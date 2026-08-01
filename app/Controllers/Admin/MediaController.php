<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Uploader;

/**
 * Streams uploaded photos, documents and signatures.
 *
 * These files are borrower personal data (including Aadhaar copies), so they are
 * NOT served straight off disk - uploads/.htaccess denies direct access. Every
 * request is checked here for:
 *   1. an authenticated panel session
 *   2. the customers.view permission
 *   3. branch scope: a branch manager cannot fetch another branch's images
 *   4. path containment inside the uploads root
 */
final class MediaController extends Controller
{
    /** Extensions we are willing to serve, mapped to their content type. */
    private const TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
    ];

    public function show(Request $request): void
    {
        $this->guard($request, 'customers.view');

        $relative = $request->str('f');
        if ($relative === '') {
            $this->deny(400, 'No file requested.');
        }

        // Reject traversal before touching the filesystem.
        if (str_contains($relative, "\0") || str_contains($relative, '..')) {
            $this->deny(400, 'Invalid path.');
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            $this->deny(415, 'Unsupported file type.');
        }

        $absolute = Uploader::absolutePath($relative);
        $real = realpath($absolute);
        $root = realpath(Uploader::uploadRoot());

        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            $this->deny(404, 'File not found.');
        }

        if (!$this->isAuthorised($relative)) {
            $this->deny(403, 'You do not have access to this file.');
        }

        $this->stream($real, self::TYPES[$extension]);
    }

    /**
     * The file must belong to a record inside the caller's branch scope.
     * A super admin sees everything; anyone else is limited to their branch.
     */
    private function isAuthorised(string $relative): bool
    {
        $scoped = Auth::scopedBranchId();
        if ($scoped === null) {
            return true;
        }

        // Five places a servable file can be owned from. The last two were added with
        // staff photographs and report approval: a file whose owner is not in one of
        // these tables is an orphan, and an orphan is never served - so forgetting to
        // add a new media kind here fails closed, which is the right direction.
        $branchId = Database::instance()->scalar(
            'SELECT la.branch_id
               FROM photos p JOIN loan_accounts la ON la.id = p.loan_account_id
              WHERE p.file_path = ?
              UNION
             SELECT la.branch_id
               FROM documents d JOIN loan_accounts la ON la.id = d.loan_account_id
              WHERE d.file_path = ?
              UNION
             SELECT la.branch_id
               FROM signatures s JOIN loan_accounts la ON la.id = s.loan_account_id
              WHERE s.file_path = ?
              UNION
             SELECT u.branch_id
               FROM users u
              WHERE u.photo_path = ? OR u.signature_path = ?
              UNION
             SELECT vr.branch_id
               FROM visit_reports vr
              WHERE vr.approval_photo_path = ? OR vr.approval_signature_path = ?
              LIMIT 1',
            [$relative, $relative, $relative, $relative, $relative, $relative, $relative]
        );

        // An orphaned file with no owning record is never served.
        return $branchId !== null && (int) $branchId === $scoped;
    }

    private function stream(string $path, string $mime): never
    {
        $size = (int) filesize($path);
        $modified = (int) filemtime($path);
        $etag = '"' . md5($path . $size . $modified) . '"';

        // Conditional request support keeps the profile gallery light.
        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch !== '' && trim($ifNoneMatch) === $etag) {
            http_response_code(304);
            exit;
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . $size);
            header('ETag: ' . $etag);
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
            // private: proxies must not cache borrower images.
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            // Force a download-or-render decision rather than inline scripting.
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'none'");
        }

        readfile($path);
        exit;
    }

    private function deny(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }
}
