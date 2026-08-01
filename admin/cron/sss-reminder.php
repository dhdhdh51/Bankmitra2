<?php
/**
 * SSS enrolment reminders for agents who have not recorded anything today.
 *
 * cPanel -> Cron Jobs, four times on working days:
 *   0 11,14,16 * * * /usr/local/bin/php /home/USER/public_html/cron/sss-reminder.php
 *   0 17       * * * /usr/local/bin/php /home/USER/public_html/cron/sss-reminder.php --final
 *
 * Sent by EMAIL and in-app, not SMS: this deployment has SMTP configured and no
 * SMS gateway, so a reminder written for SMS would simply never arrive. The
 * wording is the same either way.
 *
 * At the --final slot the agent's supervisor is copied and the agent's dashboard
 * is flagged for the day. Earlier slots are between the agent and the app only -
 * copying a supervisor at 11am about a day that still has six hours left in it is
 * how these messages stop being read.
 *
 *   --date=YYYY-MM-DD   assess a specific day
 *   --final             final slot: copy the supervisor and flag the dashboard
 *   --dry-run           report, send nothing
 *
 * Idempotent per slot: an agent is reminded at most once per slot per day, so a
 * duplicated cron entry cannot spam them.
 *
 * CLI only.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Notifier;
use App\Core\Settings;
use App\Models\Notification;
use App\Services\BcPerformanceService;

$options = getopt('', ['date::', 'final', 'dry-run']);
$date = isset($options['date']) && $options['date'] !== false ? (string) $options['date'] : date('Y-m-d');
$isFinal = array_key_exists('final', $options);
$dryRun = array_key_exists('dry-run', $options);

if (strtotime($date) === false) {
    fwrite(STDERR, "!! --date must be YYYY-MM-DD\n");
    exit(1);
}

// The slot label makes the reminder idempotent per slot rather than per day.
$slot = $isFinal ? 'final' : ('h' . date('H'));

printf("SSS reminder for %s  slot=%s%s\n", $date, $slot, $dryRun ? '  (dry run)' : '');

// The app's alarm is scheduled from the `daily_report_due_time` setting, while this
// job fires at whatever hour the crontab says. Those are two places holding the same
// policy, and nothing stops them drifting - so the drift is reported rather than left
// to be discovered by agents being reminded at one time and assessed at another.
if ($isFinal) {
    $configuredDue = \App\Controllers\Api\MetaController::reportDueTime();
    $configuredHour = (int) substr($configuredDue, 0, 2);
    $runningHour = (int) date('H');

    if (!isset($options['date']) && $configuredHour !== $runningHour) {
        fwrite(STDERR, sprintf(
            "!! The deadline in Settings is %s but this --final run started at %02d:00.\n"
            . "   Agents are reminded by the app at %s. Move the crontab entry to that hour,\n"
            . "   or change 'Daily report due by' in Settings, so the two agree.\n",
            $configuredDue,
            $runningHour,
            $configuredDue
        ));
    } else {
        printf("  deadline         : %s (matches this run)\n", $configuredDue);
    }
}
echo str_repeat('=', 66), "\n";

if (!BcPerformanceService::isWorkingDay($date)) {
    echo "  Sunday - no SSS target is expected. Nothing to do.\n";
    exit(0);
}

$db = Database::instance();

// Agents with an SSS target for the month who have recorded nothing today.
$pending = $db->all(
    "SELECT u.id, u.employee_code, u.name, u.email, u.branch_id, b.name AS branch_name,
            t.apy_target, t.pmjjby_target, t.pmsby_target
       FROM users u
       JOIN roles r ON r.id = u.role_id
       JOIN bc_targets t ON t.agent_id = u.id AND t.target_month = ?
  LEFT JOIN branches b ON b.id = u.branch_id
  LEFT JOIN sss_enrollment s ON s.agent_id = u.id AND s.enrollment_date = ?
      WHERE r.slug = 'agent' AND u.status = 'active'
        AND (t.apy_target + t.pmjjby_target + t.pmsby_target) > 0
        AND COALESCE(s.apy_count + s.pmjjby_count + s.pmsby_count, 0) = 0
   ORDER BY u.name",
    [date('Y-m-01', strtotime($date)), $date]
);

printf("  %d agent(s) with no SSS entry yet\n\n", count($pending));

$message = 'प्रिय BC, आज अभी तक आपके द्वारा कोई SSS (APY/PMJJBY/PMSBY) नामांकन दर्ज नहीं किया गया है। '
    . 'कृपया आज का लक्ष्य पूरा करें और ऐप में रिपोर्ट अपडेट करें। – D2 Recovery Solutions';

$reminded = 0;
$mailed = 0;
$skipped = 0;

foreach ($pending as $agent) {
    $agentId = (int) $agent['id'];

    // One reminder per slot per assessed day.
    //
    // Matched on the date carried in the payload, not on created_at. The row is
    // written when the cron runs, which is not necessarily the day being assessed:
    // a --date backfill would never match, and a 23:59 slot retried at 00:01 would
    // look like a different day and remind the agent twice.
    $already = $db->scalar(
        "SELECT 1 FROM notifications
          WHERE user_id = ? AND type = 'sss_pending'
            AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.date')) = ?
            AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.slot')) = ?
          LIMIT 1",
        [$agentId, $date, $slot]
    );
    if ($already !== null) {
        $skipped++;
        continue;
    }

    printf("  %-10s %s\n", (string) $agent['employee_code'], (string) $agent['name']);
    if ($dryRun) {
        $reminded++;
        continue;
    }

    Notification::send(
        $agentId,
        'sss_pending',
        $isFinal ? 'SSS लक्ष्य आज बाकी है (अंतिम सूचना)' : 'SSS नामांकन आज दर्ज नहीं हुआ',
        $message,
        null,
        ['slot' => $slot, 'date' => $date, 'final' => $isFinal],
        null,
        $agent['branch_id'] === null ? null : (int) $agent['branch_id']
    );
    $reminded++;

    if (!Notifier::smtpConfigured()) {
        continue;
    }

    $recipients = [];
    if (($agent['email'] ?? '') !== '') {
        $recipients[] = (string) $agent['email'];
    }

    // Only the final slot escalates.
    if ($isFinal && $agent['branch_id'] !== null) {
        $escalation = $db->first(
            'SELECT supervisor_email FROM branch_escalation_emails WHERE branch_id = ? LIMIT 1',
            [(int) $agent['branch_id']]
        );
        if (($escalation['supervisor_email'] ?? '') !== '') {
            $recipients[] = (string) $escalation['supervisor_email'];
        }
    }

    if ($recipients === []) {
        continue;
    }

    $bank = trim((string) Settings::get('bank_name', ''));
    $subject = $isFinal
        ? sprintf('SSS लक्ष्य अपूर्ण (अंतिम सूचना) - %s / %s', (string) $agent['name'], (string) $agent['employee_code'])
        : sprintf('SSS नामांकन आज दर्ज नहीं - %s', (string) $agent['name']);

    $body = '<pre style="font:14px/1.7 system-ui,sans-serif;white-space:pre-wrap">'
        . htmlspecialchars(implode("\n", [
            $message,
            '',
            'BC : ' . (string) $agent['name'] . ' (' . (string) $agent['employee_code'] . ')',
            'शाखा : ' . (string) ($agent['branch_name'] ?? '-'),
            'दिनांक : ' . date('d/m/Y', strtotime($date)),
            '',
            $bank !== '' ? $bank . ' · D2 Recovery' : 'D2 Recovery',
        ]), ENT_QUOTES, 'UTF-8')
        . '</pre>';

    foreach (array_unique($recipients) as $recipient) {
        try {
            if (Notifier::sendMail($recipient, $subject, $body)) {
                $mailed++;
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf("  !! mail to %s failed: %s\n", $recipient, mb_substr($e->getMessage(), 0, 120)));
        }
    }
}

echo "\n", str_repeat('=', 66), "\n";
printf("  reminded        : %d\n", $reminded);
printf("  already sent    : %d\n", $skipped);
printf("  emails sent     : %d\n", $mailed);
if (!Notifier::smtpConfigured()) {
    echo "  !! SMTP is not configured, so only in-app notifications were raised.\n";
}

exit(0);
