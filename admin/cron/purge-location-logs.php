<?php
/**
 * Deletes location points past the retention window.
 *
 * cPanel -> Cron Jobs, daily at 3:15 AM:
 *   15 3 * * * /usr/local/bin/php /home/USER/public_html/cron/purge-location-logs.php
 *
 * THIS CRON IS NOT OPTIONAL. The location notice every agent acknowledges says
 * their position is kept for a fixed number of days "and then it is deleted
 * automatically". If this is not scheduled, that sentence is false and the system
 * accumulates an unbounded record of where its staff have been.
 *
 *   --days=N     override the retention window for this run
 *   --dry-run    report what would be deleted, delete nothing
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
use App\Core\Logger;
use App\Services\TrackingService;

$options = getopt('', ['days::', 'dry-run']);
$dryRun = array_key_exists('dry-run', $options);
$days = isset($options['days']) && $options['days'] !== false
    ? (int) $options['days']
    : TrackingService::retentionDays();

if ($days < 1) {
    fwrite(STDERR, "!! --days must be 1 or more. A retention of zero would mean 'keep forever'.\n");
    exit(1);
}

$db = Database::instance();
$cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

printf("Location retention purge%s\n", $dryRun ? '  (dry run)' : '');
echo str_repeat('=', 66), "\n";
printf("  retention window : %d days\n", $days);
printf("  deleting before  : %s\n", $cutoff);

$total = (int) ($db->scalar('SELECT COUNT(*) FROM bc_location_logs') ?? 0);
$stale = (int) ($db->scalar('SELECT COUNT(*) FROM bc_location_logs WHERE logged_at < ?', [$cutoff]) ?? 0);
$oldest = $db->scalar('SELECT MIN(logged_at) FROM bc_location_logs');

printf("  points held      : %d\n", $total);
printf("  oldest point     : %s\n", $oldest === null ? '(none)' : (string) $oldest);
printf("  past retention   : %d\n", $stale);

if ($dryRun) {
    echo "\n  Dry run - nothing was deleted.\n";
    exit(0);
}

if ($stale === 0) {
    echo "\n  Nothing to purge.\n";
    exit(0);
}

$removed = TrackingService::purge($days);
printf("\n  deleted          : %d\n", $removed);
printf("  remaining        : %d\n", (int) ($db->scalar('SELECT COUNT(*) FROM bc_location_logs') ?? 0));

// The purge itself is audited: "why is there no trail for last March?" needs an
// answer, and "it expired on this date, as the notice said" is that answer.
Logger::audit(
    'purge',
    'bc_location_logs',
    null,
    null,
    ['retention_days' => $days, 'cutoff' => $cutoff, 'deleted' => $removed],
    sprintf('Purged %d location point(s) older than %d days', $removed, $days)
);

echo "\nPURGE OK\n";
exit(0);
