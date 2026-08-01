<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\ImportService;

final class ImportController extends Controller
{
    /** Uploads and imports an Excel/CSV lead file. */
    public function upload(Request $request): void
    {
        $user = $this->auth($request, 'import.upload');

        if (!isset($_FILES['lead_file'])) {
            Response::error('Attach the lead file as multipart field "lead_file".', 422);
        }

        $scoped = Auth::scopedBranchId();
        $branchId = $scoped ?? ($request->nullableInt('default_branch_id') ?: null);

        $agentId = $request->nullableInt('default_agent_id');
        if ($agentId !== null && $agentId > 0) {
            $agent = User::find($agentId);
            if ($agent === null || !Auth::canAccessBranch($agent['branch_id'] === null ? null : (int) $agent['branch_id'])) {
                Response::forbidden('The selected agent is not available to you.');
            }
        } else {
            $agentId = null;
        }

        try {
            $result = ImportService::run(
                $_FILES['lead_file'],
                $branchId,
                $agentId,
                (int) $user['id'],
                (string) $user['name'],
                [],
                Auth::scopedBranchId() === null,
            );
        } catch (\Throwable $e) {
            Response::error('Import failed: ' . $e->getMessage(), 422);
        }

        Response::json(true, [
            'import_id'          => $result['import_id'],
            'created_branches'   => $result['created_branches'] ?? [],
            'sheet'              => $result['sheet'] ?? '',
            'mapping'            => $result['mapping'] ?? [],
            'total_rows'         => $result['total'],
            'inserted'           => $result['inserted'],
            'updated'            => $result['updated'],
            'skipped'            => $result['skipped'],
            'error_count'        => count($result['errors']),
            'unmatched_branches' => $result['unmatched_branches'],
            // Only the first slice is returned; the full list is in the CSV log.
            'errors'             => array_slice($result['errors'], 0, 50),
            'error_log_url'      => $result['error_log'] === null
                ? null
                : '/api/v1/import/' . $result['import_id'] . '/errors',
        ], sprintf(
            'Import complete: %d inserted, %d updated, %d skipped.',
            $result['inserted'],
            $result['updated'],
            $result['skipped']
        ), 201);
    }

    /** Dry run: reports what would happen without writing anything. */
    public function preview(Request $request): void
    {
        $this->auth($request, 'import.upload');

        if (!isset($_FILES['lead_file'])) {
            Response::error('Attach the lead file as multipart field "lead_file".', 422);
        }

        $scoped = Auth::scopedBranchId();
        $branchId = $scoped ?? ($request->nullableInt('default_branch_id') ?: null);

        try {
            $result = ImportService::preview($_FILES['lead_file'], $branchId);
        } catch (\Throwable $e) {
            Response::error('Validation failed: ' . $e->getMessage(), 422);
        }

        Response::success($result, 'Validation complete. Nothing has been written.');
    }

    /** Import history, newest first. */
    public function index(Request $request): void
    {
        $this->auth($request, 'import.view');

        $scoped = Auth::scopedBranchId();
        $where = '1 = 1';
        $params = [];

        if ($scoped !== null) {
            $where = '(li.branch_id = ? OR li.branch_id IS NULL)';
            $params[] = $scoped;
        }

        $page = \App\Core\Paginator::fromQuery(
            "SELECT COUNT(*) FROM lead_imports li WHERE {$where}",
            "SELECT li.*, u.name AS uploaded_by_name
               FROM lead_imports li JOIN users u ON u.id = li.uploaded_by
              WHERE {$where}
              ORDER BY li.created_at DESC, li.id DESC",
            $params,
            $request->page(),
            $this->perPage($request)
        );

        Response::success(
            array_map(static fn (array $row): array => [
                'id'             => (int) $row['id'],
                'original_name'  => (string) $row['original_name'],
                'total_rows'     => (int) $row['total_rows'],
                'inserted_count' => (int) $row['inserted_count'],
                'updated_count'  => (int) $row['updated_count'],
                'skipped_count'  => (int) $row['skipped_count'],
                'error_count'    => (int) $row['error_count'],
                'status'         => (string) $row['status'],
                'uploaded_by'    => (string) $row['uploaded_by_name'],
                'created_at'     => (string) $row['created_at'],
            ], $page->items),
            '',
            ['meta' => $page->meta()]
        );
    }

    /** Downloads the rejected-rows CSV. */
    public function errors(Request $request): void
    {
        $this->auth($request, 'import.view');

        $id = $request->paramInt('id');
        $import = Database::instance()->first('SELECT * FROM lead_imports WHERE id = ? LIMIT 1', [$id]);

        if ($import === null) {
            Response::notFound('That import could not be found.');
        }

        $scoped = Auth::scopedBranchId();
        if ($scoped !== null && $import['branch_id'] !== null && (int) $import['branch_id'] !== $scoped) {
            Response::forbidden('That import belongs to another branch.');
        }

        $path = $import['error_log_path'] === null ? null : (string) $import['error_log_path'];
        if ($path === null || !is_file($path)) {
            Response::notFound('No error log is available for that import.');
        }

        Response::download(
            (string) file_get_contents($path),
            sprintf('lrms_import_%d_errors.csv', $id),
            'text/csv; charset=utf-8'
        );
    }
}
