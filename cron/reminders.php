<?php
/**
 * Scheduled reminders and promise housekeeping.
 *
 * cPanel -> Cron Jobs, daily at 7:00 AM:
 *   0 7 * * * /usr/local/bin/php /home/USER/public_html/cron/reminders.php
 *
 * Does three things:
 *   1. Promise reminders  - notifies the agent shortly before a promise falls due
 *   2. Overdue promises   - flags pending promises whose date has passed
 *   3. Follow-up reminders - nudges agents about leads with no recent visit
 *
 * Idempotent: it will not send the same reminder twice on the same day, so a
 * duplicated cron entry or a manual re-run cannot spam agents.
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
use App\Core\Settings;
use App\Models\Notification;

$db = Database::instance();
$sent = ['promise' => 0, 'overdue' => 0, 'followup' => 0];

/**
 * True when this exact reminder already went out to this user today.
 * Keeps the job safe to re-run.
 */
$alreadySentToday = static function (int $userId, string $type, int $loanAccountId) use ($db): bool {
    return $db->scalar(
        'SELECT 1 FROM notifications
          WHERE user_id = ? AND type = ? AND loan_account_id = ? AND DATE(created_at) = CURDATE()
          LIMIT 1',
        [$userId, $type, $loanAccountId]
    ) !== null;
};

// ---------------------------------------------------------------------------
// 1. Promises falling due within the reminder window
// ---------------------------------------------------------------------------
$leadDays = max(0, Settings::int('promise_reminder_days', 1));

$upcoming = $db->all(
    'SELECT p.id, p.loan_account_id, p.agent_id, p.promise_amount, p.promise_date,
            la.loan_account_number, c.name AS customer_name
       FROM promises p
       JOIN loan_accounts la ON la.id = p.loan_account_id
       JOIN customers c ON c.id = p.customer_id
       JOIN users u ON u.id = p.agent_id
      WHERE p.status = ?
        AND u.status = ?
        AND p.promise_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)',
    ['pending', 'active', $leadDays]
);

foreach ($upcoming as $promise) {
    $agentId = (int) $promise['agent_id'];
    $loanId = (int) $promise['loan_account_id'];

    if ($alreadySentToday($agentId, 'promise_reminder', $loanId)) {
        continue;
    }

    $isToday = (string) $promise['promise_date'] === date('Y-m-d');

    Notification::send(
        $agentId,
        'promise_reminder',
        $isToday ? 'Promise due today' : 'Promise due soon',
        sprintf(
            '%s promised %s on %s for account %s.',
            (string) $promise['customer_name'],
            number_format((float) $promise['promise_amount'], 2),
            date('d M Y', (int) strtotime((string) $promise['promise_date'])),
            (string) $promise['loan_account_number']
        ),
        $loanId,
        ['promise_id' => (int) $promise['id']]
    );

    $sent['promise']++;
}

// ---------------------------------------------------------------------------
// 2. Promises that have gone past their date while still pending
// ---------------------------------------------------------------------------
$overdue = $db->all(
    'SELECT p.id, p.loan_account_id, p.agent_id, p.promise_amount, p.promise_date,
            DATEDIFF(CURDATE(), p.promise_date) AS days_overdue,
            la.loan_account_number, c.name AS customer_name
       FROM promises p
       JOIN loan_accounts la ON la.id = p.loan_account_id
       JOIN customers c ON c.id = p.customer_id
       JOIN users u ON u.id = p.agent_id
      WHERE p.status = ? AND u.status = ? AND p.promise_date < CURDATE()
      ORDER BY p.promise_date ASC
      LIMIT 500',
    ['pending', 'active']
);

foreach ($overdue as $promise) {
    $agentId = (int) $promise['agent_id'];
    $loanId = (int) $promise['loan_account_id'];
    $days = (int) $promise['days_overdue'];

    // Nudge on day 1, then weekly, rather than every single morning.
    if ($days !== 1 && $days % 7 !== 0) {
        continue;
    }
    if ($alreadySentToday($agentId, 'promise_reminder', $loanId)) {
        continue;
    }

    Notification::send(
        $agentId,
        'promise_reminder',
        sprintf('Promise overdue by %d day%s', $days, $days === 1 ? '' : 's'),
        sprintf(
            '%s has not paid the %s promised on %s. Account %s needs a follow-up visit.',
            (string) $promise['customer_name'],
            number_format((float) $promise['promise_amount'], 2),
            date('d M Y', (int) strtotime((string) $promise['promise_date'])),
            (string) $promise['loan_account_number']
        ),
        $loanId,
        ['promise_id' => (int) $promise['id'], 'days_overdue' => $days]
    );

    $sent['overdue']++;
}

// ---------------------------------------------------------------------------
// 3. Leads with no visit for N days
// ---------------------------------------------------------------------------
$followupDays = max(1, Settings::int('followup_reminder_days', 7));

$stale = $db->all(
    'SELECT la.id, la.assigned_agent_id, la.loan_account_number, la.last_visit_at,
            c.name AS customer_name, c.village
       FROM loan_accounts la
       JOIN customers c ON c.id = la.customer_id
       JOIN users u ON u.id = la.assigned_agent_id
      WHERE la.assigned_agent_id IS NOT NULL
        AND u.status = ?
        AND la.current_status NOT IN (?, ?)
        AND (
              (la.last_visit_at IS NULL AND la.assigned_at < DATE_SUB(NOW(), INTERVAL ? DAY))
              OR (la.last_visit_at IS NOT NULL AND la.last_visit_at < DATE_SUB(NOW(), INTERVAL ? DAY))
            )
      ORDER BY la.assigned_agent_id, la.last_visit_at ASC
      LIMIT 1000',
    ['active', 'closed', 'legal', $followupDays, $followupDays]
);

// One summary per agent instead of one notification per lead.
$byAgent = [];
foreach ($stale as $lead) {
    $byAgent[(int) $lead['assigned_agent_id']][] = $lead;
}

foreach ($byAgent as $agentId => $leads) {
    $count = count($leads);

    // Reuse the per-lead guard against the first lead as the day marker.
    if ($alreadySentToday((int) $agentId, 'followup_reminder', (int) $leads[0]['id'])) {
        continue;
    }

    Notification::send(
        (int) $agentId,
        'followup_reminder',
        $count === 1 ? 'A lead needs a follow-up visit' : sprintf('%d leads need follow-up visits', $count),
        $count === 1
            ? sprintf(
                '%s (%s) has had no visit for over %d days.',
                (string) $leads[0]['customer_name'],
                (string) $leads[0]['loan_account_number'],
                $followupDays
            )
            : sprintf('%d assigned leads have had no visit for over %d days.', $count, $followupDays),
        (int) $leads[0]['id'],
        ['count' => $count]
    );

    $sent['followup']++;
}

printf(
    "[%s] reminders sent - promise: %d, overdue: %d, follow-up: %d\n",
    date('Y-m-d H:i:s'),
    $sent['promise'],
    $sent['overdue'],
    $sent['followup']
);

exit(0);
