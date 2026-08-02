<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uploader;

/**
 * Streams borrower photos and documents to the app.
 *
 * Authenticated with the Bearer JWT, then scoped: an agent only receives media
 * belonging to leads in their own branch. Files on disk are never web-readable
 * (see uploads/.htaccess), so this is the only way to fetch them.
 */
final class MediaController extends Controller
{
    private const TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    public function show(Request $request): void
    {
        $user = $this->auth($request, 'customers.view');

        $relative = $request->str('f');
        if ($relative === '') {
            Response::error('No file requested.', 422);
        }

        if (str_contains($relative, "\0") || str_contains($relative, '..')) {
            Response::error('Invalid path.', 400);
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            Response::error('Unsupported file type.', 415);
        }

        $real = realpath(Uploader::absolutePath($relative));
        $root = realpath(Uploader::uploadRoot());

        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            Response::notFound('File not found.');
        }

        if (!$this->isAuthorised($relative, $user)) {
            Response::forbidden('You do not have access to this file.');
        }

        $this->stream($real, self::TYPES[$extension]);
    }

    /**
     * The file must belong to a lead the caller can see. A super admin sees all;
     * everyone else is limited to their branch.
     *
     * @param array<string,mixed> $user
     */
    private function isAuthorised(string $relative, array $user): bool
    {
        if (Auth::isSuperAdmin()) {
            return true;
        }

        $branchId = Database::instance()->scalar(
            'SELECT la.branch_id
               FROM photos p JOIN loan_accounts la ON la.id = p.loan_account_id
              WHERE p.file_path = ?
              UNION
             SELECT la.branch_id
               FROM documents d JOIN loan_accounts la ON la.id = d.loan_account_id
              WHERE d.file_path = ?
              UNION
             SELECT vr.branch_id
               FROM visit_reports vr
              WHERE vr.approval_photo_path = ?
              LIMIT 1',
            [$relative, $relative, $relative]
        );

        if ($branchId === null) {
            return false; // orphaned file: never served
        }

        $callerBranch = $user['branch_id'] === null ? null : (int) $user['branch_id'];
        return $callerBranch !== null && (int) $branchId === $callerBranch;
    }

    private function stream(string $path, string $mime): never
    {
        $size = (int) filesize($path);
        $modified = (int) filemtime($path);
        $etag = '"' . md5($path . $size . $modified) . '"';

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . $size);
            header('ETag: ' . $etag);
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
        }

        readfile($path);
        exit;
    }
}
