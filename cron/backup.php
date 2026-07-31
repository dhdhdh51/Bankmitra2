<?php
/**
 * Scheduled database backup.
 *
 * cPanel -> Cron Jobs, nightly at 2:00 AM:
 *   0 2 * * * /usr/local/bin/php /home/USER/public_html/cron/backup.php
 *
 * Old files are pruned according to Settings -> Backup -> retention days.
 * CLI only: refuses to run over HTTP so it cannot be triggered from the web.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\BackupService;

try {
    $backup = BackupService::create();

    printf(
        "[%s] backup ok: %s (%s, via %s)\n",
        date('Y-m-d H:i:s'),
        $backup['file'],
        BackupService::humanBytes($backup['size']),
        $backup['method']
    );
    exit(0);
} catch (\Throwable $e) {
    fprintf(STDERR, "[%s] backup FAILED: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    exit(1);
}
