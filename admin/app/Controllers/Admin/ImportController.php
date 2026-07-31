<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Xlsx;
use App\Models\Branch;
use App\Models\User;
use App\Services\ImportService;

final class ImportController extends Controller
{
    /** Upload form + POST handler for the actual import. */
    public function index(Request $request): void
    {
        $this->guard($request, 'import.view');

        if ($request->isPost()) {
            Auth::requirePermissionPanel('import.upload', '/import');
            $this->handleUpload($request);
        }

        $scoped = Auth::scopedBranchId();

        $this->view($request, 'import/index', [
            'title'     => 'Excel Import',
            'branches'  => Branch::options($scoped),
            'agents'    => User::agents($scoped),
            'recent'    => $this->recentImports(3),
            'preview'   => Session::get('_import_preview'),
            'columns'   => $this->expectedColumns(),
            'canUpload' => Auth::can('import.upload'),
        ]);
    }

    /**
     * Dry run: parses the file and reports what would happen without writing.
     */
    public function preview(Request $request): void
    {
        $this->guard($request, 'import.upload');

        if (!isset($_FILES['lead_file'])) {
            $this->back('/import', 'danger', 'Choose a file to validate.');
        }

        $branchId = $this->resolveDefaultBranch($request);

        try {
            $result = ImportService::preview($_FILES['lead_file'], $branchId);
        } catch (\Throwable $e) {
            $this->back('/import', 'danger', 'Validation failed: ' . e($e->getMessage()));
        }

        // Held in the session so the result survives the redirect.
        Session::set('_import_preview', [
            'filename' => (string) ($_FILES['lead_file']['name'] ?? 'upload'),
            'result'   => $result,
        ]);

        $this->back(
            '/import',
            $result['missing_required'] === [] ? 'info' : 'warning',
            $result['missing_required'] === []
                ? sprintf(
                    'Validation complete: <strong>%d</strong> new, <strong>%d</strong> updates, <strong>%d</strong> issue(s).',
                    $result['new_count'],
                    $result['update_count'],
                    count($result['issues'])
                )
                : 'The file is missing required columns. See the details below.'
        );
    }

    public function history(Request $request): void
    {
        $this->guard($request, 'import.view');

        $scoped = Auth::scopedBranchId();

        $where = '1 = 1';
        $params = [];
        if ($scoped !== null) {
            $where = '(li.branch_id = ? OR li.branch_id IS NULL)';
            $params[] = $scoped;
        }

        $imports = \App\Core\Paginator::fromQuery(
            "SELECT COUNT(*) FROM lead_imports li WHERE {$where}",
            "SELECT li.*, u.name AS uploaded_by_name, b.name AS branch_name
               FROM lead_imports li
               JOIN users u ON u.id = li.uploaded_by
               LEFT JOIN branches b ON b.id = li.branch_id
              WHERE {$where}
              ORDER BY li.created_at DESC, li.id DESC",
            $params,
            $request->page(),
            $this->perPage($request)
        );

        $this->view($request, 'import/history', [
            'title'   => 'Import history',
            'imports' => $imports,
        ]);
    }

    /** Downloads the rejected-rows CSV for one import. */
    public function errors(Request $request): void
    {
        $this->guard($request, 'import.view');

        $id = $request->paramInt('id');
        $import = Database::instance()->first('SELECT * FROM lead_imports WHERE id = ? LIMIT 1', [$id]);

        if ($import === null) {
            $this->back('/import/history', 'danger', 'That import could not be found.');
        }

        $scoped = Auth::scopedBranchId();
        if ($scoped !== null && $import['branch_id'] !== null && (int) $import['branch_id'] !== $scoped) {
            $this->back('/import/history', 'danger', 'That import belongs to another branch.');
        }

        $path = $import['error_log_path'] === null ? null : (string) $import['error_log_path'];
        if ($path === null || !is_file($path)) {
            $this->back('/import/history', 'warning', 'No error log is available for that import.');
        }

        $this->logExport('Import', sprintf('Downloaded error log for import #%d', $id));

        Response::download(
            (string) file_get_contents($path),
            sprintf('lrms_import_%d_errors.csv', $id),
            'text/csv; charset=utf-8'
        );
    }

    /** A ready-to-fill template with the exact expected headers. */
    public function template(Request $request): void
    {
        $this->guard($request, 'import.view');

        $headings = array_keys($this->expectedColumns());

        $sample = [[
            'BR001', 'BC-001', 'LN0000000001', 'Ramesh Kumar', 'Shyam Lal',
            '9876543210', '123456789012', 'Kotri', 'House 12, Kotri', 'Crop Loan',
            125000.00, 24500.00, '2024-03-31', 'First default',
        ]];

        if (Xlsx::available()) {
            Response::download(
                Xlsx::build(
                    'Lead Template',
                    $headings,
                    $sample,
                    'LRMS Lead Import Template',
                    'Replace the sample row with your data. Branch and Loan Account Number are required.'
                ),
                'lrms_lead_import_template.xlsx',
                Xlsx::MIME
            );
        }

        Response::download(
            Xlsx::csv($headings, $sample),
            'lrms_lead_import_template.csv',
            'text/csv; charset=utf-8'
        );
    }

    // -----------------------------------------------------------------------

    private function handleUpload(Request $request): never
    {
        if (!isset($_FILES['lead_file'])) {
            $this->back('/import', 'danger', 'Choose a file to upload.');
        }

        $branchId = $this->resolveDefaultBranch($request);
        $agentId = $request->nullableInt('default_agent_id');

        // The bulk-assignment target must be inside the caller's scope.
        if ($agentId !== null && $agentId > 0) {
            $agent = User::find($agentId);
            if ($agent === null || !Auth::canAccessBranch($agent['branch_id'] === null ? null : (int) $agent['branch_id'])) {
                $this->back('/import', 'danger', 'The selected agent is not available to you.');
            }
        } else {
            $agentId = null;
        }

        $user = Auth::user();

        try {
            $result = ImportService::run(
                $_FILES['lead_file'],
                $branchId,
                $agentId,
                (int) $user['id'],
                (string) $user['name']
            );
        } catch (\Throwable $e) {
            $this->back('/import', 'danger', 'Import failed: ' . e($e->getMessage()));
        }

        Session::forget('_import_preview');

        $parts = [sprintf(
            'Import complete: <strong>%d</strong> inserted, <strong>%d</strong> updated, <strong>%d</strong> skipped of %d row(s).',
            $result['inserted'],
            $result['updated'],
            $result['skipped'],
            $result['total']
        )];

        if ($result['unmatched_branches'] !== []) {
            $parts[] = 'Unknown branch codes: <code>'
                . e(implode(', ', array_slice($result['unmatched_branches'], 0, 8)))
                . '</code>.';
        }

        if ($result['error_log'] !== null) {
            $parts[] = sprintf(
                '<a href="%s">Download the error log</a> to see why rows were skipped.',
                e(url('/import/' . $result['import_id'] . '/errors'))
            );
        }

        $this->back(
            '/import',
            $result['inserted'] + $result['updated'] > 0 ? 'success' : 'warning',
            implode(' ', $parts)
        );
    }

    /**
     * A branch manager always imports into their own branch; a super admin may
     * pick a fallback branch for rows whose branch column does not match.
     */
    private function resolveDefaultBranch(Request $request): ?int
    {
        $scoped = Auth::scopedBranchId();
        if ($scoped !== null) {
            return $scoped;
        }

        $branchId = $request->nullableInt('default_branch_id');
        return ($branchId !== null && $branchId > 0) ? $branchId : null;
    }

    /** @return list<array<string,mixed>> */
    private function recentImports(int $limit): array
    {
        return Database::instance()->all(
            'SELECT li.*, u.name AS uploaded_by_name
               FROM lead_imports li
               JOIN users u ON u.id = li.uploaded_by
              ORDER BY li.created_at DESC
              LIMIT ' . max(1, min(20, $limit))
        );
    }

    /**
     * Expected column => whether it is required. Drives the on-screen guide and
     * the downloadable template, so both stay in step with the parser.
     *
     * @return array<string,bool>
     */
    private function expectedColumns(): array
    {
        return [
            'Branch'              => false,
            'BC Code'             => false,
            'Loan Account Number' => true,
            'Customer Name'       => true,
            'Father/Husband Name' => false,
            'Mobile'              => false,
            'Aadhaar'             => false,
            'Village'             => false,
            'Address'             => false,
            'Loan Type'           => false,
            'Outstanding Amount'  => false,
            'Overdue Amount'      => false,
            'NPA Date'            => false,
            'Remarks'             => false,
        ];
    }
}
