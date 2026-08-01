<?php
/**
 * Nightly BC target check and escalating warnings.
 *
 * cPanel -> Cron Jobs, daily at 11:55 PM:
 *   55 23 * * * /usr/local/bin/php /home/USER/public_html/cron/bc-warning-check.php
 *
 * For every active agent:
 *   1. Recompute today's achievement from visit_reports, promises and
 *      sss_enrollment (never from a counter - see BcPerformanceService).
 *   2. Compare against that agent's targets for the month, monthly targets
 *      pro-rated across the working days elapsed.
 *   3. For each missed target, work out the consecutive miss streak and issue
 *      Level 1 (1 day), Level 2 (3 days) or Level 3 (7 days).
 *   4. Mail the agent, their supervisor, the service provider and the regional
 *      office, in Hindi.
 *   5. Update the agent's dashboard badge, and raise the escalation flag when a
 *      final warning has gone unimproved for a week.
 *
 * Sundays are skipped entirely: nobody is expected to enrol anyone on a Sunday,
 * and a warning issued for one would be indefensible.
 *
 * Idempotent. A unique key on (agent, target, date) means a re-run after a
 * failure cannot issue the same warning twice or send a second email.
 *
 *   --date=YYYY-MM-DD   assess a specific day (backfill)
 *   --dry-run           compute and print, write nothing, send nothing
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

$options = getopt('', ['date::', 'dry-run']);
$date = isset($options['date']) && $options['date'] !== false
    ? (string) $options['date']
    : date('Y-m-d');
$dryRun = array_key_exists('dry-run', $options);

if (strtotime($date) === false) {
    fwrite(STDERR, "!! --date must be YYYY-MM-DD\n");
    exit(1);
}

$db = Database::instance();
$started = microtime(true);

printf("BC warning check for %s%s\n", $date, $dryRun ? '  (dry run)' : '');
echo str_repeat('=', 66), "\n";

if (!BcPerformanceService::isWorkingDay($date)) {
    echo "  Sunday - no targets are assessed. Nothing to do.\n";
    exit(0);
}

/** Human labels for the email, in Hindi. */
$metricLabels = [
    'apy'          => 'अटल पेंशन योजना (APY)',
    'pmjjby'       => 'प्रधानमंत्री जीवन ज्योति बीमा (PMJJBY)',
    'pmsby'        => 'प्रधानमंत्री सुरक्षा बीमा (PMSBY)',
    'pmjdy'        => 'जन धन खाता (PMJDY)',
    'npa_recovery' => 'एनपीए वसूली',
    'od2_renewal'  => 'ओडी-2 / सीकेसीसी नवीनीकरण',
    'visit'        => 'दैनिक फील्ड विज़िट',
    'report'       => 'दैनिक रिपोर्ट प्रस्तुत करना',
];

$levelLabels = [
    'L1' => 'चेतावनी-1',
    'L2' => 'चेतावनी-2',
    'L3' => 'अंतिम चेतावनी',
];

$agents = $db->all(
    "SELECT u.id, u.employee_code, u.name, u.email, u.branch_id, b.name AS branch_name
       FROM users u
       JOIN roles r ON r.id = u.role_id
  LEFT JOIN branches b ON b.id = u.branch_id
      WHERE r.slug = 'agent' AND u.status = 'active'
   ORDER BY u.name"
);

printf("  %d active agent(s)\n\n", count($agents));

$assessed = 0;
$warned = 0;
$mailed = 0;
$skippedNoTarget = 0;
$escalated = 0;

foreach ($agents as $agent) {
    $agentId = (int) $agent['id'];

    if (!$dryRun) {
        BcPerformanceService::rollUpDay($agentId, $date);
    }

    $targets = BcPerformanceService::targetsFor($agentId, $date);
    if ($targets === null) {
        // No targets set for the month. Silence is correct: warning somebody for
        // missing a target nobody gave them is worse than not warning at all.
        $skippedNoTarget++;
        continue;
    }

    $assessed++;
    $gaps = BcPerformanceService::gapsFor($agentId, $date);

    if ($gaps === []) {
        if (!$dryRun) {
            BcPerformanceService::refreshStanding($agentId, $date);
        }
        continue;
    }

    $issued = [];
    foreach ($gaps as $metric => $gap) {
        if ($dryRun) {
            $streak = BcPerformanceService::missStreak($agentId, $metric, $date);
            $issued[] = [
                'level'  => BcPerformanceService::levelForStreak($streak),
                'streak' => $streak,
                'metric' => $metric,
                'gap'    => $gap,
                'date'   => $date,
            ];
            continue;
        }

        $warning = BcPerformanceService::recordWarning($agentId, $metric, $gap, $date);
        if ($warning !== null) {
            $issued[] = $warning;
        }
    }

    if ($issued === []) {
        continue;
    }

    $warned++;
    $worst = 'L1';
    foreach ($issued as $warning) {
        if ($warning['level'] === 'L3' || ($warning['level'] === 'L2' && $worst === 'L1')) {
            $worst = $warning['level'];
        }
    }

    printf(
        "  %-10s %-24s %s  (%s)\n",
        (string) $agent['employee_code'],
        mb_strimwidth((string) $agent['name'], 0, 24, ''),
        $levelLabels[$worst],
        implode(', ', array_map(static fn (array $w): string => $w['metric'] . ' x' . $w['streak'], $issued))
    );

    if ($dryRun) {
        continue;
    }

    $standing = BcPerformanceService::refreshStanding($agentId, $date);
    if ($standing['escalation_flag'] === 1) {
        $escalated++;
    }

    // ---- Notify -----------------------------------------------------------
    $recipients = [];
    if (($agent['email'] ?? '') !== '') {
        $recipients[] = (string) $agent['email'];
    }

    $escalation = $agent['branch_id'] === null ? null : $db->first(
        'SELECT supervisor_email, service_provider_email, regional_office_email
           FROM branch_escalation_emails WHERE branch_id = ? LIMIT 1',
        [(int) $agent['branch_id']]
    );

    // A Level 1 is between the agent and their supervisor. The service provider and
    // the regional office are copied only once it has become a pattern - escalating
    // a single missed day to a regional office is how these systems get ignored.
    if ($escalation !== null) {
        foreach (['supervisor_email'] as $key) {
            if (($escalation[$key] ?? '') !== '') {
                $recipients[] = (string) $escalation[$key];
            }
        }
        if ($worst === 'L2' || $worst === 'L3') {
            foreach (['service_provider_email'] as $key) {
                if (($escalation[$key] ?? '') !== '') {
                    $recipients[] = (string) $escalation[$key];
                }
            }
        }
        if ($worst === 'L3' && ($escalation['regional_office_email'] ?? '') !== '') {
            $recipients[] = (string) $escalation['regional_office_email'];
        }
    }

    $recipients = array_values(array_unique(array_filter($recipients)));

    $lines = [];
    foreach ($issued as $warning) {
        $lines[] = sprintf(
            "  %s\n    निर्धारित लक्ष्य : %s\n    प्राप्त उपलब्धि : %s\n    कमी (Gap) : %s\n    लगातार दिन : %d",
            $metricLabels[$warning['metric']] ?? $warning['metric'],
            $warning['gap']['money'] ? '₹ ' . number_format($warning['gap']['target'], 2) : (string) (int) $warning['gap']['target'],
            $warning['gap']['money'] ? '₹ ' . number_format($warning['gap']['achieved'], 2) : (string) (int) $warning['gap']['achieved'],
            $warning['gap']['money'] ? '₹ ' . number_format($warning['gap']['gap'], 2) : (string) (int) $warning['gap']['gap'],
            $warning['streak']
        );
    }

    $bank = trim((string) Settings::get('bank_name', ''));
    $subject = sprintf(
        'चेतावनी सूचना - लक्ष्य अपूर्ण [%s / %s]',
        (string) $agent['name'],
        (string) $agent['employee_code']
    );

    $body = sprintf(
        "%s\n\n"
        . "BC का नाम : %s\n"
        . "BC Code : %s\n"
        . "शाखा : %s\n"
        . "रिपोर्ट दिनांक : %s\n"
        . "चेतावनी स्तर : %s\n\n"
        . "अपूर्ण लक्ष्य :\n%s\n\n"
        . "सुधार हेतु निर्देश: कृपया निर्धारित समयसीमा में लक्ष्य पूर्ण करें अन्यथा आगे की कार्यवाही की जाएगी।\n\n"
        . "%s\n"
        . "यह सूचना स्वतः उत्पन्न हुई है।\n",
        $levelLabels[$worst],
        (string) $agent['name'],
        (string) $agent['employee_code'],
        (string) ($agent['branch_name'] ?? '-'),
        date('d/m/Y', strtotime($date)),
        $levelLabels[$worst],
        implode("\n\n", $lines),
        $bank !== '' ? $bank . ' · D2 Recovery' : 'D2 Recovery'
    );

    $sent = false;
    $note = null;
    if ($recipients === []) {
        $note = 'no email address for the agent or the branch escalation chain';
    } elseif (!Notifier::smtpConfigured()) {
        $note = 'SMTP is not configured in Settings';
    } else {
        // One message per recipient: the mailer takes a single address, and sending
        // separately means one bad address in the chain cannot stop the rest.
        $html = '<pre style="font:14px/1.7 system-ui,sans-serif;white-space:pre-wrap">'
            . htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
            . '</pre>';
        $delivered = 0;
        $failures = [];

        foreach ($recipients as $recipient) {
            try {
                if (Notifier::sendMail($recipient, $subject, $html)) {
                    $delivered++;
                } else {
                    $failures[] = $recipient;
                }
            } catch (\Throwable $e) {
                $failures[] = $recipient . ' (' . mb_substr($e->getMessage(), 0, 60) . ')';
            }
        }

        $sent = $delivered > 0;
        if ($failures !== []) {
            $note = 'failed: ' . mb_substr(implode(', ', $failures), 0, 200);
        }
    }

    if ($sent) {
        $mailed++;
    }

    foreach ($issued as $warning) {
        $db->update('bc_warnings', [
            'email_sent'    => $sent ? 1 : 0,
            'notified_at'   => $sent ? date('Y-m-d H:i:s') : null,
            'delivery_note' => $note,
        ], ['id' => (int) $warning['id']]);
    }

    // The agent also gets it in the app, which is the only channel that reaches
    // them reliably - a BC's email is often an address they never open.
    // One agent's notification failing must not abandon everyone after them in the
    // list. The warning row is already committed, which is the part that matters;
    // a missed in-app nudge is recorded and the run carries on.
    try {
        Notification::send(
            $agentId,
            'target_warning',
            $levelLabels[$worst] . ': लक्ष्य अपूर्ण',
            sprintf(
                '%s. कृपया आज का लक्ष्य पूरा करें।',
                implode(', ', array_map(
                    static fn (array $w): string => $metricLabels[$w['metric']] ?? $w['metric'],
                    $issued
                ))
            ),
            null,
            ['warning_level' => $worst, 'date' => $date],
            null,
            $agent['branch_id'] === null ? null : (int) $agent['branch_id']
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf(
            "  !! in-app notification failed for %s: %s\n",
            (string) $agent['employee_code'],
            mb_substr($e->getMessage(), 0, 140)
        ));
    }
}

echo "\n", str_repeat('=', 66), "\n";
printf("  assessed        : %d\n", $assessed);
printf("  no targets set  : %d\n", $skippedNoTarget);
printf("  warned          : %d\n", $warned);
printf("  emails sent     : %d\n", $mailed);
printf("  escalated       : %d\n", $escalated);
printf("  took            : %.2fs\n", microtime(true) - $started);

if ($skippedNoTarget > 0 && $assessed === 0) {
    echo "\n  !! No agent has targets for this month, so nothing was assessed.\n";
    echo "     Set them under Admin -> BC Targets before relying on this cron.\n";
}

exit(0);
