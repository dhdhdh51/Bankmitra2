<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Services\BackupService;

final class BackupController extends Controller
{
    public function index(Request $request): void
    {
        $this->guard($request, 'backup.run');

        $this->view($request, 'backup/index', [
            'title'     => 'Database Backup',
            'backups'   => BackupService::list(),
            'database'  => (string) Config::get('db.name', ''),
            'retention' => Settings::int('backup_retention_days', 14),
        ]);
    }

    public function run(Request $request): void
    {
        $this->guard($request, 'backup.run');

        try {
            $backup = BackupService::create();
        } catch (\Throwable $e) {
            $this->back('/backup', 'danger', 'Backup failed: ' . e($e->getMessage()));
        }

        $this->back('/backup', 'success', sprintf(
            'Backup created: <code>%s</code> (%s, via %s). <a href="%s">Download now</a>.',
            e($backup['file']),
            e(BackupService::humanBytes($backup['size'])),
            e($backup['method']),
            e(url('/backup/download?file=' . urlencode($backup['file'])))
        ));
    }

    public function download(Request $request): void
    {
        $this->guard($request, 'backup.run');

        $file = $request->str('file');
        $path = BackupService::resolve($file);

        if ($path === null) {
            $this->back('/backup', 'danger', 'That backup file could not be found.');
        }

        $this->logExport('Backup', sprintf('Downloaded backup %s', basename($path)));

        // Streamed rather than read into memory: dumps can be large.
        if (!headers_sent()) {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . (string) filesize($path));
            header('Cache-Control: no-store');
        }
        readfile($path);
        exit;
    }

    public function delete(Request $request): void
    {
        $this->guard($request, 'backup.run');

        $file = $request->str('file');

        if (!BackupService::delete($file)) {
            $this->back('/backup', 'danger', 'That backup could not be deleted.');
        }

        $this->back('/backup', 'success', sprintf('Backup <code>%s</code> deleted.', e(basename($file))));
    }
}
