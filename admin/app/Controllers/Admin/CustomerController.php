<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoanAccount;
use App\Models\Promise;
use App\Models\Timeline;
use App\Models\User;
use App\Models\VisitReport;
use App\Services\AssignmentService;

final class CustomerController extends Controller
{
    /**
     * Leads list: searchable by loan account number, name, mobile, Aadhaar and
     * village, filterable by branch/agent/status, with bulk actions.
     */
    public function index(Request $request): void
    {
        $this->guard($request, 'customers.view');

        $filters = $this->filters($request);
        [$sortBy, $sortDir] = $request->sort(LoanAccount::SORTABLE, 'created_at', 'DESC');

        $leads = LoanAccount::paginate($filters, $sortBy, $sortDir, $request->page(), $this->perPage($request));
        $counts = LoanAccount::statusCounts($filters);

        $scoped = Auth::scopedBranchId();

        $this->view($request, 'customers/index', [
            'title'      => 'Customers & Leads',
            'leads'      => $leads,
            'counts'     => $counts,
            'filters'    => $filters,
            'sortBy'     => $sortBy,
            'sortDir'    => $sortDir,
            'branches'   => Branch::options($scoped),
            'agents'     => User::agents($scoped ?? ($filters['branch_id'] ?? null)),
            'villages'   => LoanAccount::villages($scoped),
            'loanTypes'  => LoanAccount::loanTypes($scoped),
            'canAssign'  => Auth::can('leads.assign'),
            'canTransfer' => Auth::can('leads.transfer'),
            'canClose'   => Auth::can('leads.close'),
        ]);
    }

    /**
     * Full customer profile: loan info, promise history, visit history, photos,
     * documents, signatures and the append-only timeline.
     */
    public function show(Request $request): void
    {
        $this->guard($request, 'customers.view');

        $id = $request->paramInt('id');

        // PII is only decrypted for users who hold customers.view_pii.
        $showPii = Auth::can('customers.view_pii');
        $lead = $showPii ? LoanAccount::findWithPii($id) : LoanAccount::find($id);

        if ($lead === null) {
            $this->back('/customers', 'danger', 'That loan account could not be found.');
        }

        Auth::assertBranchAccess((int) $lead['branch_id']);

        $this->logView('Customers', sprintf('Viewed loan account %s', (string) $lead['loan_account_number']));

        $scoped = Auth::scopedBranchId();

        $this->view($request, 'customers/show', [
            'title'        => (string) $lead['customer_name'],
            'lead'         => $lead,
            'showPii'      => $showPii,
            'timeline'     => Timeline::forLoanAccount($id),
            'visits'       => VisitReport::forLoanAccount($id),
            'promises'     => Promise::forLoanAccount($id),
            'photos'       => VisitReport::photosForLoanAccount($id),
            'documents'    => VisitReport::documentsForLoanAccount($id),
            'signatures'   => VisitReport::signaturesForLoanAccount($id),
            'otherLoans'   => Customer::loanAccounts((int) $lead['customer_id']),
            'agents'       => Auth::can('leads.assign') ? User::agents($scoped ?? (int) $lead['branch_id']) : [],
            'branches'     => Auth::can('leads.transfer') ? Branch::options($scoped) : [],
        ]);
    }

    /**
     * Edits the borrower's contact details. Loan figures are not editable here:
     * they come from the core banking Excel import, so hand-editing them would
     * be silently overwritten by the next upload.
     */
    public function edit(Request $request): void
    {
        $this->guard($request, 'customers.update');

        $id = $request->paramInt('id');
        $lead = LoanAccount::findWithPii($id);

        if ($lead === null) {
            $this->back('/customers', 'danger', 'That loan account could not be found.');
        }
        Auth::assertBranchAccess((int) $lead['branch_id']);

        if (!$request->isPost()) {
            $this->view($request, 'customers/edit', [
                'title' => 'Edit borrower',
                'lead'  => $lead,
            ]);
        }

        $validator = Validator::make($request->all(), [
            'name'                => 'required|max:150',
            'father_husband_name' => 'nullable|max:150',
            'mobile'              => 'nullable|mobile',
            'aadhaar'             => 'nullable|aadhaar',
            'village'             => 'nullable|max:150',
            'address'             => 'nullable|max:500',
        ], ['father_husband_name' => 'Father / husband name']);

        if ($validator->fails()) {
            $this->backWithErrors('/customers/' . $id . '/edit', $validator->errors(), $request->all());
        }

        $before = [
            'name'                => $lead['customer_name'],
            'father_husband_name' => $lead['father_husband_name'],
            'village'             => $lead['village'],
            'address'             => $lead['address'],
        ];

        $mobile = $request->nullableStr('mobile');
        $aadhaar = $request->nullableStr('aadhaar');

        $after = [
            'name'                => $request->str('name'),
            'father_husband_name' => $request->nullableStr('father_husband_name'),
            'village'             => $request->nullableStr('village'),
            'address'             => $request->nullableStr('address'),
        ];

        Customer::update((int) $lead['customer_id'], $after, $mobile, $aadhaar, true);

        Logger::auditDiff(
            'customer',
            (int) $lead['customer_id'],
            $before,
            $after,
            sprintf('Updated borrower for %s', (string) $lead['loan_account_number'])
        );

        $this->back('/customers/' . $id, 'success', 'Borrower details updated.');
    }

    /**
     * Bulk assign / reassign / transfer / close.
     */
    public function bulk(Request $request): void
    {
        $this->guard($request);

        $action = $request->str('bulk_action');
        $ids = $request->intArr('lead_ids');
        $redirect = '/customers' . ($request->str('return_query') !== '' ? '?' . $request->str('return_query') : '');

        if ($ids === []) {
            $this->back($redirect, 'warning', 'No leads were selected.');
        }

        // Guard against a runaway selection creating an unbounded transaction.
        if (count($ids) > 2000) {
            $this->back($redirect, 'danger', 'Select at most 2,000 leads at a time.');
        }

        $result = match ($action) {
            'assign', 'reassign' => $this->bulkAssign($request, $ids, $action === 'reassign'),
            'transfer'           => $this->bulkTransfer($request, $ids),
            'unassign'           => $this->bulkUnassign($ids),
            'close'              => $this->bulkStatus($ids, 'closed'),
            'reopen'             => $this->bulkStatus($ids, 'pending'),
            'followup'           => $this->bulkStatus($ids, 'followup'),
            default              => null,
        };

        if ($result === null) {
            $this->back($redirect, 'danger', 'Choose a valid bulk action.');
        }

        $messages = [];
        if ($result['updated'] > 0) {
            $messages[] = sprintf('<strong>%d</strong> lead(s) updated.', $result['updated']);
        }
        if ($result['skipped'] > 0) {
            $messages[] = sprintf('%d skipped.', $result['skipped']);
        }
        foreach (array_slice($result['messages'], 0, 5) as $message) {
            $messages[] = e($message);
        }

        $this->back(
            $redirect,
            $result['updated'] > 0 ? 'success' : 'warning',
            $messages === [] ? 'Nothing changed.' : implode(' ', $messages)
        );
    }

    /**
     * Exports the current filtered lead list to Excel.
     */
    public function export(Request $request): void
    {
        $this->guard($request, 'reports.export');

        $filters = $this->filters($request);
        [$sortBy, $sortDir] = $request->sort(LoanAccount::SORTABLE, 'created_at', 'DESC');

        // Cap the export so a shared host cannot be pushed out of memory.
        $page = LoanAccount::paginate($filters, $sortBy, $sortDir, 1, 20000);

        $headings = [
            'Loan Account Number', 'Customer Name', 'Father/Husband Name', 'Mobile', 'Aadhaar',
            'Village', 'Branch', 'BC Code', 'Loan Type', 'Outstanding', 'Overdue',
            'NPA Date', 'Status', 'Assigned Agent', 'Visits', 'Last Visit',
        ];

        $rows = [];
        foreach ($page->items as $lead) {
            $rows[] = [
                (string) $lead['loan_account_number'],
                (string) $lead['customer_name'],
                (string) ($lead['father_husband_name'] ?? ''),
                (string) ($lead['mobile_masked'] ?? ''),
                (string) ($lead['aadhaar_masked'] ?? ''),
                (string) ($lead['village'] ?? ''),
                (string) $lead['branch_name'],
                (string) ($lead['bc_code'] ?? ''),
                (string) ($lead['loan_type'] ?? ''),
                (float) $lead['outstanding_amount'],
                (float) $lead['overdue_amount'],
                $lead['npa_date'] === null ? '' : fmt_date((string) $lead['npa_date']),
                ucfirst((string) $lead['current_status']),
                (string) ($lead['agent_name'] ?? 'Unassigned'),
                (int) $lead['visit_count'],
                $lead['last_visit_at'] === null ? '' : fmt_date((string) $lead['last_visit_at']),
            ];
        }

        $this->logExport('Customers', sprintf('Exported %d lead(s) to Excel', count($rows)));

        $filename = 'lrms_leads_' . date('Ymd_His');

        if (Xlsx::available()) {
            Response::download(
                Xlsx::build('Leads', $headings, $rows, 'Customers & Leads', 'Exported ' . date('d M Y, h:i A')),
                $filename . '.xlsx',
                Xlsx::MIME
            );
        }

        Response::download(Xlsx::csv($headings, $rows), $filename . '.csv', 'text/csv; charset=utf-8');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'search'     => $request->str('search'),
            'branch_id'  => $this->branchFilter($request),
            'agent_id'   => $this->agentFilter($request),
            'status'     => $request->str('status'),
            'village'    => $request->str('village'),
            'loan_type'  => $request->str('loan_type'),
            'npa_only'   => $request->bool('npa_only'),
            'unassigned' => $request->bool('unassigned'),
            'date_from'  => $request->str('date_from'),
            'date_to'    => $request->str('date_to'),
        ];
    }

    /**
     * @param list<int> $ids
     * @return array{updated:int,skipped:int,messages:list<string>}
     */
    private function bulkAssign(Request $request, array $ids, bool $isReassign): array
    {
        Auth::requirePermissionPanel($isReassign ? 'leads.reassign' : 'leads.assign', '/customers');

        $agentId = $request->nullableInt('agent_id_action');
        if ($agentId === null || $agentId <= 0) {
            return ['updated' => 0, 'skipped' => count($ids), 'messages' => ['Choose an agent to assign to.']];
        }

        return AssignmentService::assign($ids, $agentId, $isReassign);
    }

    /**
     * @param list<int> $ids
     * @return array{updated:int,skipped:int,messages:list<string>}
     */
    private function bulkTransfer(Request $request, array $ids): array
    {
        Auth::requirePermissionPanel('leads.transfer', '/customers');

        $branchId = $request->nullableInt('branch_id_action');
        if ($branchId === null || $branchId <= 0) {
            return ['updated' => 0, 'skipped' => count($ids), 'messages' => ['Choose a destination branch.']];
        }

        return AssignmentService::transfer($ids, $branchId, true);
    }

    /**
     * @param list<int> $ids
     * @return array{updated:int,skipped:int,messages:list<string>}
     */
    private function bulkUnassign(array $ids): array
    {
        Auth::requirePermissionPanel('leads.reassign', '/customers');
        return AssignmentService::unassign($ids);
    }

    /**
     * @param list<int> $ids
     * @return array{updated:int,skipped:int,messages:list<string>}
     */
    private function bulkStatus(array $ids, string $status): array
    {
        Auth::requirePermissionPanel('leads.close', '/customers');
        return AssignmentService::setStatus($ids, $status);
    }
}
