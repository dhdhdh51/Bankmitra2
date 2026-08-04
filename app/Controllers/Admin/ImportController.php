<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\ColumnDetector;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Xlsx;
use App\Models\Branch;
use App\Models\User;
use App\Services\AssignmentService;
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
            'title'         => 'Excel Import',
            'branches'      => Branch::options($scoped),
            'agents'        => User::agents($scoped),
            'recent'        => $this->recentImports(3),
            'preview'       => Session::get('_import_preview'),
            'columns'       => $this->expectedColumns(),
            'canUpload'     => Auth::can('import.upload'),
            'fields'        => ColumnDetector::fields(),
            // Every heading a past import has taught ColumnDetector to resolve
            // automatically - shown so an operator can see what "learned" means
            // on the mapping screen, and undo a wrong lesson without a database
            // console.
            'taughtAliases' => ColumnDetector::learnedAliases(),
        ]);
    }

    /** Undoes a taught mapping - a mis-click, or a heading that means something
     *  different on a later export from the same template. */
    public function deleteAlias(Request $request): void
    {
        $this->guard($request, 'import.upload');

        ColumnDetector::forgetAlias($request->paramInt('id'));

        $this->back('/import', 'success', 'Forgotten. The next matching file will be detected or asked about again.');
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
            $result = ImportService::preview(
                $_FILES['lead_file'],
                $branchId,
                $this->columnOverrides($request),
            );
        } catch (\Throwable $e) {
            $this->back('/import', 'danger', 'Validation failed: ' . e($e->getMessage()));
        }

        // Held in the session so the result survives the redirect. stored_path
        // stays server-side: the confirm step posts only a flag, never a path.
        Session::set('_import_preview', [
            'filename'           => (string) ($_FILES['lead_file']['name'] ?? 'upload'),
            'stored_path'        => (string) ($result['stored_path'] ?? ''),
            'result'             => $result,
            // Carried forward so the confirm form can pass them back in hidden fields:
            // without these the "Import with this mapping" POST has no branch/agent
            // context, and every row gets skipped because no branch resolves.
            'default_branch_id'  => $request->nullableStr('default_branch_id'),
            'default_agent_id'   => $request->nullableStr('default_agent_id'),
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

        // How much of each batch is still unassigned, in one query rather than one per
        // row. Shown because "assign this import again" is only a sensible thing to offer
        // next to the number it would change.
        $pending = [];
        if ($imports->items !== []) {
            $ids = array_map(static fn (array $i): int => (int) $i['id'], $imports->items);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params2 = $ids;

            $scopeSql = '';
            if ($scoped !== null) {
                $scopeSql = ' AND branch_id = ?';
                $params2[] = $scoped;
            }

            foreach (Database::instance()->all(
                "SELECT import_id,
                        COUNT(*) AS total,
                        SUM(CASE WHEN assigned_agent_id IS NULL THEN 1 ELSE 0 END) AS unassigned
                   FROM loan_accounts
                  WHERE import_id IN ({$placeholders}){$scopeSql}
                  GROUP BY import_id",
                $params2
            ) as $row) {
                $pending[(int) $row['import_id']] = [
                    'total'      => (int) $row['total'],
                    'unassigned' => (int) $row['unassigned'],
                ];
            }
        }

        $this->view($request, 'import/history', [
            'title'     => 'Import history',
            'imports'   => $imports,
            'leadState' => $pending,
            'agents'    => Auth::can('leads.assign') ? User::agents($scoped) : [],
            'canAssign' => Auth::can('leads.assign'),
        ]);
    }

    /**
     * Assign the leads of a past import, again, whenever.
     *
     * Assignment used to happen only in the same breath as the upload, which quietly made
     * it a one-shot decision: whoever imported a file either picked the right agent at
     * that moment or the leads sat unassigned until somebody selected them by hand off
     * the borrower list. A file is not a one-time event - it is a batch that a branch
     * works through, gets a second BC for, or has to rebalance when somebody goes on
     * leave. So the batch stays addressable.
     *
     * Never steals work in progress: a lead somebody has already visited keeps its agent
     * unless the caller explicitly asks to reassign.
     */
    public function assignBatch(Request $request): void
    {
        // Assigning is assigning, whichever screen it is started from.
        $this->guard($request, 'leads.assign');

        $id = $request->paramInt('id');
        $import = Database::instance()->first('SELECT * FROM lead_imports WHERE id = ? LIMIT 1', [$id]);

        if ($import === null) {
            $this->back('/import/history', 'danger', 'That import could not be found.');
        }

        Auth::assertBranchAccess($import['branch_id'] === null ? null : (int) $import['branch_id']);

        $mode = $request->str('assign_mode');
        $onlyUnassigned = $request->bool('only_unassigned');

        // Scoped in the query, not filtered afterwards: a branch manager acting on an
        // all-branches import must only touch the rows that are theirs.
        $scoped = Auth::scopedBranchId();
        $sql = 'SELECT id FROM loan_accounts WHERE import_id = ?';
        $params = [$id];

        if ($scoped !== null) {
            $sql .= ' AND branch_id = ?';
            $params[] = $scoped;
        }
        if ($onlyUnassigned) {
            $sql .= ' AND assigned_agent_id IS NULL';
        }

        $leadIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            Database::instance()->all($sql . ' ORDER BY id', $params)
        );

        if ($leadIds === []) {
            $this->back(
                '/import/history',
                'warning',
                $onlyUnassigned
                    ? 'Every lead from that import is already assigned.'
                    : 'That import has no leads you can assign.'
            );
        }

        if ($mode === 'distribute') {
            $result = AssignmentService::distribute($leadIds);
        } else {
            $agentId = $request->nullableInt('agent_id');
            if ($agentId === null || $agentId <= 0) {
                $this->back('/import/history', 'danger', 'Choose an agent, or choose to distribute evenly.');
            }

            $agent = User::find($agentId);
            if ($agent === null || !Auth::canAccessBranch($agent['branch_id'] === null ? null : (int) $agent['branch_id'])) {
                $this->back('/import/history', 'danger', 'That agent is not available to you.');
            }

            // Reassign semantics, because these leads may well already have an owner -
            // which is the whole reason somebody is on this screen a second time.
            $result = AssignmentService::assign($leadIds, $agentId, true);
        }

        $message = sprintf(
            '%d lead(s) from %s assigned, %d skipped.',
            $result['updated'],
            e((string) $import['original_name']),
            $result['skipped']
        );

        if ($result['messages'] !== []) {
            $message .= ' ' . e(implode(' ', array_slice($result['messages'], 0, 4)));
        }

        $this->back('/import/history', $result['updated'] > 0 ? 'success' : 'warning', $message);
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

        // Headings and the sample row both come from the field definition, so a
        // new column can never leave the template with a sample row of the wrong
        // width - which is exactly what happened when the sample was a literal.
        $headings = [];
        $sampleRow = [];
        foreach (ColumnDetector::fields() as $meta) {
            $headings[] = $meta['label'];
            $sampleRow[] = $meta['example'];
        }
        $sample = [$sampleRow];

        if (Xlsx::available()) {
            Response::download(
                Xlsx::build(
                    'Lead Template',
                    $headings,
                    $sample,
                    'D2 Recovery Solutions & Services Lead Import Template',
                    'Only Loan Account Number and Customer Name are required. '
                    . 'You do not have to use this layout: upload your bank\'s own export and '
                    . 'the columns are detected. Branch is read from the file.'
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
        // Importing the file that was just validated: no new upload is needed, and
        // the confirmed column mapping applies to that exact file.
        $previewed = Session::get('_import_preview');
        $reuseStoredPath = null;
        $reuseName = null;
        if ($request->input('use_previewed_file') === '1' && is_array($previewed)) {
            $candidate = (string) ($previewed['stored_path'] ?? '');
            if ($candidate !== '' && is_file($candidate)) {
                $reuseStoredPath = $candidate;
                $reuseName = (string) ($previewed['filename'] ?? 'upload');
            }
        }

        if ($reuseStoredPath === null && !isset($_FILES['lead_file'])) {
            $this->back('/import', 'danger', 'Choose a file to upload.');
        }

        $branchId = $this->resolveDefaultBranch($request);

        // "distribute" is a mode, not an agent id, so it is read before the id is.
        $assignMode = $request->str('default_agent_id') === 'distribute' ? 'distribute' : 'agent';
        $agentId = $assignMode === 'distribute' ? null : $request->nullableInt('default_agent_id');

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
                $reuseStoredPath !== null
                    ? ['name' => $reuseName]
                    : $_FILES['lead_file'],
                $branchId,
                $agentId,
                (int) $user['id'],
                (string) $user['name'],
                $this->columnOverrides($request),
                // Branches are created from the sheet only for an uploader who is
                // not tied to one branch. A branch manager must not be able to
                // conjure branches outside their own scope through a spreadsheet.
                Auth::scopedBranchId() === null,
                $reuseStoredPath,
                $assignMode === 'distribute',
            );
        } catch (\Throwable $e) {
            $this->back('/import', 'danger', 'Import failed: ' . e($e->getMessage()));
        }

        // Every mapping THIS run actually used (detection plus whatever the confirm
        // screen overrode), not just what the operator's dropdowns changed - so a
        // heading detection already got right is remembered too, and the next file
        // from the same source maps the same way even if nobody ever touches a
        // dropdown for it again.
        $this->rememberChosenAliases((array) ($result['headings'] ?? []), (array) ($result['map'] ?? []), (int) $user['id']);

        Session::forget('_import_preview');

        $parts = [sprintf(
            'Import complete: <strong>%d</strong> inserted, <strong>%d</strong> updated, <strong>%d</strong> skipped of %d row(s).',
            $result['inserted'],
            $result['updated'],
            $result['skipped'],
            $result['total']
        )];

        // Branches created from the sheet are named explicitly. Auto-creating them
        // silently would let one misspelling become a permanent branch nobody
        // notices, so the operator is told and can rename or merge.
        if (($result['created_branches'] ?? []) !== []) {
            $labels = array_map(
                static fn (array $branch): string => $branch['name'] . ' (' . $branch['code'] . ')',
                array_slice($result['created_branches'], 0, 8)
            );
            $parts[] = sprintf(
                '<strong>%d branch(es) created from the file:</strong> <code>%s</code>%s '
                . '<a href="%s">Review them</a> if any look like a typo.',
                count($result['created_branches']),
                e(implode(', ', $labels)),
                count($result['created_branches']) > 8 ? ' and more.' : '',
                e(url('/branches'))
            );
        }

        if ($result['unmatched_branches'] !== []) {
            $parts[] = 'Unknown branch codes: <code>'
                . e(implode(', ', array_slice($result['unmatched_branches'], 0, 8)))
                . '</code>.';
        }

        // Columns the fixed vocabulary did not recognise, so nothing this file carried
        // was thrown away - each became its own field on the loan account instead. Said
        // out loud here for the same reason a created branch is: an operator watching an
        // unfamiliar bank's export go in for the first time should see where those
        // columns landed, not discover them later on the Custom Fields screen.
        if (($result['unmapped_fields'] ?? []) !== []) {
            $parts[] = sprintf(
                '<strong>%d column(s) not in the fixed list became custom fields</strong> on the loan '
                . 'account, so nothing in the file was dropped: <code>%s</code>%s '
                . '<a href="%s">Review them</a> if you want to relabel one, mark it required, or show it '
                . 'on the printed report.',
                count($result['unmapped_fields']),
                e(implode(', ', array_slice($result['unmapped_fields'], 0, 8))),
                count($result['unmapped_fields']) > 8 ? ' and more.' : '',
                e(url('/custom-fields'))
            );
        }

        // Figures a human corrected in the panel are left alone by the import. That is
        // the point of allowing the correction, but it has to be said out loud: an
        // override nobody remembers setting freezes a figure forever, and the operator
        // watching the file go in is the only person positioned to notice.
        if (($result['skipped_overrides'] ?? []) !== []) {
            $accounts = array_slice(array_keys($result['skipped_overrides']), 0, 6);
            $labels = [];
            foreach ($accounts as $account) {
                $columns = array_map(
                    static fn (string $column): string =>
                        \App\Models\LoanAccount::MANUALLY_EDITABLE[$column] ?? $column,
                    array_unique($result['skipped_overrides'][$account])
                );
                $labels[] = $account . ' (' . implode(', ', $columns) . ')';
            }

            $parts[] = sprintf(
                '<strong>%d account(s) kept hand-corrected figures</strong> instead of the'
                . ' values in the file: <code>%s</code>%s',
                count($result['skipped_overrides']),
                e(implode('; ', $labels)),
                count($result['skipped_overrides']) > 6 ? ' and more.' : ''
            );
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
        // Derived, not duplicated. This list used to be typed out here as well as
        // in the service, so adding a column meant editing two places and the
        // generated template could disagree with what the importer accepted.
        $columns = [];
        foreach (ColumnDetector::fields() as $meta) {
            $columns[$meta['label']] = $meta['required'];
        }
        return $columns;
    }

    /**
     * The mapping the operator confirmed on the dry-run screen.
     *
     * Posted as column_map[field] = column index, with -1 meaning "ignore this
     * field". Anything not posted is left to detection.
     *
     * @return array<string,int>
     */
    private function columnOverrides(Request $request): array
    {
        $raw = $_POST['column_map'] ?? null;
        
        // Debug logging
        Logger::info('Column mapping received', [
            'raw_post' => $_POST,
            'column_map' => $raw,
        ]);
        
        if (!is_array($raw)) {
            Logger::warning('column_map is not an array', ['type' => gettype($raw)]);
            return [];
        }

        $fields = ColumnDetector::fields();
        $overrides = [];
        foreach ($raw as $field => $index) {
            if (!is_string($field) || !isset($fields[$field]) || !is_scalar($index)) {
                Logger::warning('Invalid column mapping entry', [
                    'field' => $field,
                    'index' => $index,
                    'field_is_string' => is_string($field),
                    'field_exists' => isset($fields[$field]),
                    'index_is_scalar' => is_scalar($index),
                ]);
                continue;
            }
            $value = (string) $index;
            if ($value === '') {
                continue;   // "detect automatically"
            }
            if (!is_numeric($value)) {
                Logger::warning('Column index is not numeric', ['field' => $field, 'value' => $value]);
                continue;
            }
            $overrides[$field] = (int) $value;
        }
        
        Logger::info('Column overrides processed', ['overrides' => $overrides]);

        return $overrides;
    }

    /**
     * Teaches ColumnDetector every mapping this run actually used, so the next
     * file carrying the same heading - a different month's export from the
     * same bank, typically - maps automatically instead of asking the same
     * question a second time.
     *
     * $map is ImportService::run()'s resolved mapping (field => column index),
     * not just what the confirm screen's dropdowns changed - detection getting
     * a heading right on its own is remembered exactly like a correction is.
     * Harmless either way: learnAlias() upserts on the heading's normalised
     * text, so re-teaching a mapping that was already right just writes the
     * same row again. What actually matters - "ADRESS now means Village on
     * this file" - gets remembered the same way a mapping that needed no
     * correction does.
     *
     * A field absent from $map (the operator marked it "not in this file", or
     * detection found nothing) is not taught: forgetting a field is not the
     * same claim as a heading meaning something, and would need its own kind
     * of memory ("this heading is never anything") that nothing here needs yet.
     */
    private function rememberChosenAliases(array $headings, array $map, int $actorId): void
    {
        foreach ($map as $field => $index) {
            if (!is_int($index) || $index < 0 || !isset($headings[$index])) {
                continue;
            }
            $heading = trim((string) $headings[$index]);
            if ($heading === '') {
                continue;
            }
            ColumnDetector::learnAlias($heading, (string) $field, $actorId);
        }
    }
}
