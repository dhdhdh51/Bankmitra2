<?php

/**
 * Alerts agents as a CKCC / OD-2 account approaches its renewal deadline.
 *
 * cPanel -> Cron Jobs, daily at 7:00 AM:
 *   0 7 * * * /usr/local/bin/php /home/USER/public_html/cron/ckcc-renewal-check.php
 *
 *   --date=YYYY-MM-DD   treat this as today (for backfills and testing)
 *   --dry-run           report what would be sent, send nothing
 *
 * CLI only.
 *
 * WHY THIS DOES NOT STORE AN "URGENCY" COLUMN
 * -------------------------------------------
 * The obvious build is a nightly job that writes a bucket - overdue / within_7 /
 * within_15 / within_30 - onto every CKCC account. That was rejected. The bucket
 * is a pure function of `ckcc_renewal_due_date` and today's date, so storing it
 * creates a second answer to the same question, and the stored answer is wrong
 * every day between midnight and whenever the cron runs. Worse, it is wrong
 * silently: a missed cron leaves a screen quietly showing yesterday's urgency,
 * and nothing about the screen says so. Queries derive the bucket in SQL instead,
 * where it cannot go stale.
 *
 * What genuinely needs a cron is the part that is not derivable: telling somebody.
 * An account crossing the 30-day line is news exactly once, and there is no way to
 * compute "have we mentioned this yet" from the due date alone. So that is all
 * this job does - it notifies on threshold crossings and remembers which
 * thresholds it has already announced, per account, so a re-run at noon or a
 * catch-up after a failed night does not spam the same agent again.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Logger;
use App\Core\Settings;
use App\Models\Notification;

$options = getopt('', ['date::', 'dry-run']);
$dryRun = array_key_exists('dry-run', $options);

$today = isset($options['date']) && $options['date'] !== false
    ? (string) $options['date']
    : date('Y-m-d');

if (\DateTimeImmutable::createFromFormat('Y-m-d', $today) === false) {
    fwrite(STDERR, "!! --date must be YYYY-MM-DD\n");
    exit(1);
}

/**
 * The thresholds, widest first. An account is announced at the narrowest band it
 * has newly entered, so a lead imported three days before its deadline gets one
 * "7 days" alert rather than three alerts in a row for 30, 15 and 7.
 */
$bands = [
    ['key' => 'overdue', 'label' => 'overdue', 'max' => -1],
    ['key' => 'within_7', 'label' => 'due within 7 days', 'max' => 7],
    ['key' => 'within_15', 'label' => 'due within 15 days', 'max' => 15],
    ['key' => 'within_30', 'label' => 'due within 30 days', 'max' => Settings::int('ckcc_renewal_alert_days', 30)],
];

$widest = (int) $bands[3]['max'];
if ($widest < 1) {
    $widest = 30;
    $bands[3]['max'] = 30;
}

$db = Database::instance();

printf("CKCC / OD-2 renewal check for %s%s\n", $today, $dryRun ? '  (dry run)' : '');
echo str_repeat('=', 66), "\n";
printf("  alert window     : %d days\n", $widest);

// Only accounts that are actually somebody's work: an unassigned account has
// nobody to notify, and a closed one has nothing to renew.
$accounts = $db->all(
    'SELECT la.id, la.account_number, la.ckcc_renewal_due_date, la.assigned_agent_id,'
    . '       la.branch_id, la.outstanding_amount,'
    . '       c.name AS customer_name,'
    . '       DATEDIFF(la.ckcc_renewal_due_date, ?) AS days_left'
    . '  FROM loan_accounts la'
    . '  JOIN customers c ON c.id = la.customer_id'
    . ' WHERE la.ckcc_renewal_due_date IS NOT NULL'
    . '   AND la.assigned_agent_id IS NOT NULL'
    . "   AND la.current_status <> 'closed'"
    . '   AND DATEDIFF(la.ckcc_renewal_due_date, ?) <= ?'
    . ' ORDER BY la.ckcc_renewal_due_date ASC',
    [$today, $today, $widest],
);

printf("  candidates       : %d\n\n", count($accounts));

$sent = 0;
$skipped = 0;
$perBand = [];

foreach ($accounts as $account) {
    $daysLeft = (int) $account['days_left'];

    $band = null;
    foreach ($bands as $candidate) {
        if ($daysLeft <= (int) $candidate['max']) {
            $band = $candidate;
        }
    }

    if ($band === null) {
        continue;
    }

    $accountId = (int) $account['id'];
    $agentId = (int) $account['assigned_agent_id'];

    // Idempotency on the payload, not on created_at: a job that ran late or is
    // being re-run must recognise its own earlier notification. Matching on the
    // date it was sent would double-send after midnight and suppress a backfill.
    $already = (int) ($db->scalar(
        'SELECT COUNT(*) FROM notifications'
        . " WHERE type = 'ckcc_renewal_due'"
        . '   AND loan_account_id = ?'
        . '   AND user_id = ?'
        . "   AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.band')) = ?"
        . "   AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.due_date')) = ?",
        [$accountId, $agentId, $band['key'], (string) $account['ckcc_renewal_due_date']],
    ) ?? 0);

    if ($already > 0) {
        $skipped++;

        continue;
    }

    $perBand[$band['key']] = ($perBand[$band['key']] ?? 0) + 1;

    $title = $daysLeft < 0
        ? 'CKCC renewal overdue'
        : sprintf('CKCC renewal %s', $band['label']);

    $body = $daysLeft < 0
        ? sprintf(
            '%s (A/c %s) was due for renewal on %s - %d day(s) ago. NPA follows if it is not completed.',
            (string) $account['customer_name'],
            (string) $account['account_number'],
            date('d-m-Y', (int) strtotime((string) $account['ckcc_renewal_due_date'])),
            abs($daysLeft),
        )
        : sprintf(
            '%s (A/c %s) is due for renewal on %s - %d day(s) left.',
            (string) $account['customer_name'],
            (string) $account['account_number'],
            date('d-m-Y', (int) strtotime((string) $account['ckcc_renewal_due_date'])),
            $daysLeft,
        );

    printf(
        "  %-18s %-16s %s (%d day%s)\n",
        $band['key'],
        (string) $account['account_number'],
        (string) $account['customer_name'],
        $daysLeft,
        abs($daysLeft) === 1 ? '' : 's',
    );

    if ($dryRun) {
        continue;
    }

    Notification::send(
        $agentId,
        'ckcc_renewal_due',
        $title,
        $body,
        $accountId,
        [
            'band' => $band['key'],
            'due_date' => (string) $account['ckcc_renewal_due_date'],
            'days_left' => $daysLeft,
        ],
        null,
        $account['branch_id'] === null ? null : (int) $account['branch_id'],
    );

    $sent++;
}

echo "\n", str_repeat('-', 66), "\n";

if ($dryRun) {
    echo "  Dry run - nothing was sent.\n";
    exit(0);
}

printf("  sent             : %d\n", $sent);
printf("  already notified : %d\n", $skipped);

foreach ($perBand as $key => $count) {
    printf("    %-16s %d\n", $key, $count);
}

if ($sent > 0) {
    Logger::audit(
        'update',
        'loan_account',
        null,
        null,
        ['date' => $today, 'sent' => $sent, 'bands' => $perBand],
        sprintf('CKCC renewal check sent %d alert(s)', $sent),
    );
}

echo "\nCKCC RENEWAL CHECK OK\n";
exit(0);
