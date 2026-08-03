<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Models\LoanAccount;
use App\Models\Promise;
use App\Models\Timeline;
use App\Models\VisitReport;
use App\Services\AssignmentService;
use App\Services\CustomerSheetService;

final class LeadController extends Controller
{
    /**
     * Assigned leads for the signed-in agent, or the branch/all leads for a
     * manager or admin. Paginated, searchable, filterable and sortable.
     */
    public function index(Request $request): void
    {
        $user = $this->auth($request, 'customers.view');

        $filters = $this->filters($request, $user);
        [$sortBy, $sortDir] = $request->sort(LoanAccount::SORTABLE, 'created_at', 'DESC');

        $page = LoanAccount::paginate($filters, $sortBy, $sortDir, $request->page(), $this->perPage($request));

        // The app's Call button in the lead list is enabled from `mobile`, so the
        // list has to carry a dialable number for anyone allowed to see PII.
        // Aadhaar is not attached: no list needs it.
        $withPii = Auth::can('customers.view_pii') || Auth::isAgent();
        $items = $withPii ? LoanAccount::attachMobiles($page->items) : $page->items;

        Response::success(
            array_map(fn (array $lead): array => $this->presentLead($lead, $withPii), $items),
            '',
            [
                'meta'          => $page->meta(),
                'status_counts' => LoanAccount::statusCounts($filters),
            ]
        );
    }

    /**
     * Customer search by loan account number, name, mobile, Aadhaar or village.
     * Mobile and Aadhaar match exactly through their HMAC columns.
     */
    public function search(Request $request): void
    {
        $user = $this->auth($request, 'customers.view');

        $term = $request->str('q', $request->str('search'));
        if (mb_strlen($term) < 2) {
            Response::error('Enter at least 2 characters to search.', 422);
        }

        $filters = $this->filters($request, $user);
        $filters['search'] = $term;

        // An agent searching is looking across their own book unless they ask for
        // the whole branch, which the "scope=branch" flag allows.
        if (Auth::isAgent() && $request->str('scope') === 'branch') {
            unset($filters['agent_id']);
            $filters['branch_id'] = $user['branch_id'] === null ? null : (int) $user['branch_id'];
        }

        $page = LoanAccount::paginate($filters, 'created_at', 'DESC', $request->page(), $this->perPage($request));

        $this->logActivity('search', 'API', sprintf('Searched leads for "%s"', mb_substr($term, 0, 60)));

        $withPii = Auth::can('customers.view_pii') || Auth::isAgent();
        $items = $withPii ? LoanAccount::attachMobiles($page->items) : $page->items;

        Response::success(
            array_map(fn (array $lead): array => $this->presentLead($lead, $withPii), $items),
            '',
            ['meta' => $page->meta()]
        );
    }

    /**
     * Full customer profile: loan details, promise history, visit timeline and
     * media references.
     */
    public function show(Request $request): void
    {
        $user = $this->auth($request, 'customers.view');

        $id = $request->paramInt('id');
        $withPii = Auth::can('customers.view_pii') || Auth::isAgent();

        // Agents need the real mobile number to call the borrower from the app.
        $lead = $withPii ? LoanAccount::findWithPii($id) : LoanAccount::find($id);

        if ($lead === null) {
            Response::notFound('That loan account could not be found.');
        }

        $this->assertLeadAccess($lead, $user);

        $timeline = Timeline::forLoanAccount($id, 100);

        Response::success([
            'lead'       => $this->presentLead($lead, $withPii),
            'promises'   => array_map(fn (array $p): array => $this->presentPromise($p), Promise::forLoanAccount($id)),
            'visits'     => array_map(fn (array $v): array => $this->presentVisitSummary($v), VisitReport::forLoanAccount($id, 50)),
            'timeline'   => array_map(fn (array $e): array => $this->presentTimelineEvent($e), $timeline),
            'photos'     => array_map(fn (array $m): array => $this->presentMedia($m, 'photo'), VisitReport::photosForLoanAccount($id)),
            'documents'  => array_map(fn (array $m): array => $this->presentMedia($m, 'document'), VisitReport::documentsForLoanAccount($id)),
            'other_accounts' => array_map(
                static fn (array $row): array => [
                    'id'                  => (int) $row['id'],
                    'loan_account_number' => (string) $row['loan_account_number'],
                    'loan_type'           => $row['loan_type'] === null ? null : (string) $row['loan_type'],
                    'outstanding_amount'  => round((float) $row['outstanding_amount'], 2),
                    'current_status'      => (string) $row['current_status'],
                ],
                Customer::loanAccounts((int) $lead['customer_id'])
            ),
        ]);
    }

    /** Timeline only, for the app's "Visit History" screen. */
    public function history(Request $request): void
    {
        $user = $this->auth($request, 'visits.view');

        $id = $request->paramInt('id');
        $lead = LoanAccount::find($id);

        if ($lead === null) {
            Response::notFound('That loan account could not be found.');
        }
        $this->assertLeadAccess($lead, $user);

        Response::success([
            'loan_account_number' => (string) $lead['loan_account_number'],
            'customer_name'       => (string) $lead['customer_name'],
            'visit_count'         => (int) $lead['visit_count'],
            'timeline'            => array_map(
                fn (array $e): array => $this->presentTimelineEvent($e),
                Timeline::forLoanAccount($id, 200)
            ),
            'visits'              => array_map(
                fn (array $v): array => $this->presentVisitSummary($v),
                VisitReport::forLoanAccount($id, 100)
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // Admin actions (used by tooling and future admin clients)
    // -----------------------------------------------------------------------

    public function assign(Request $request): void
    {
        $this->auth($request, 'leads.assign');

        $ids = $request->intArr('lead_ids');
        $agentId = $request->int('agent_id');

        if ($ids === [] || $agentId <= 0) {
            Response::error('lead_ids and agent_id are required.', 422);
        }

        $result = AssignmentService::assign($ids, $agentId, $request->bool('reassign'));
        Response::success($result, sprintf('%d lead(s) updated.', $result['updated']));
    }

    public function transfer(Request $request): void
    {
        $this->auth($request, 'leads.transfer');

        $ids = $request->intArr('lead_ids');
        $branchId = $request->int('branch_id');

        if ($ids === [] || $branchId <= 0) {
            Response::error('lead_ids and branch_id are required.', 422);
        }

        $result = AssignmentService::transfer($ids, $branchId, $request->bool('clear_agent', true));
        Response::success($result, sprintf('%d lead(s) transferred.', $result['updated']));
    }

    public function updateStatus(Request $request): void
    {
        $this->auth($request, 'leads.close');

        $ids = $request->intArr('lead_ids');
        $status = $request->str('status');

        if ($ids === [] || $status === '') {
            Response::error('lead_ids and status are required.', 422);
        }

        $result = AssignmentService::setStatus($ids, $status, $request->nullableStr('note'));
        Response::success($result, sprintf('%d lead(s) updated.', $result['updated']));
    }

    // -----------------------------------------------------------------------

    /**
     * Scopes the filter set to what the caller is allowed to see.
     * An agent is always pinned to their own assigned leads.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    private function filters(Request $request, array $user): array
    {
        $filters = [
            'search'        => $request->str('search', $request->str('q')),
            'status'        => $request->str('status'),
            'village'       => $request->str('village'),
            'loan_type'     => $request->str('loan_type'),
            // KCC and OD-2 are worked as two separate renewal queues on the reports
            // side (see ReportService::renewalWorklist()); the same enum lets the app's
            // lead list split them the same way, rather than the free-text loan_type
            // string a bank's own export happens to use.
            'facility_type' => $request->str('facility_type'),
            'npa_only'      => $request->bool('npa_only'),
            'date_from'     => $request->str('date_from'),
            'date_to'       => $request->str('date_to'),
        ];

        if (Auth::isAgent()) {
            // Hard scope: an agent only ever sees leads assigned to them.
            $filters['agent_id'] = (int) $user['id'];
            $filters['branch_id'] = $user['branch_id'] === null ? null : (int) $user['branch_id'];
            return $filters;
        }

        $scoped = Auth::scopedBranchId();
        $filters['branch_id'] = $scoped ?? ($request->nullableInt('branch_id') ?: null);

        $agentId = $request->nullableInt('agent_id');
        if ($agentId !== null && $agentId > 0) {
            $filters['agent_id'] = $agentId;
        }
        if ($request->bool('unassigned')) {
            $filters['unassigned'] = true;
        }

        return $filters;
    }

    /**
     * Streams the customer data sheet as a PDF.
     *
     * Scoped harder than the rest of the lead API on purpose. Everything else an
     * agent reads stays inside the app; this leaves the device as a file that can
     * be printed, mailed or forwarded, so an agent may only take the sheet for a
     * lead actually assigned to them - not for any lead that merely happens to sit
     * in their branch. It is also audited, because the sheet carries a borrower's
     * contact details and the branch's settlement figures.
     *
     * `?lang=hi` prints the sheet's labels in Hindi - the same choice the app's
     * own account screen offers for the UI, so an agent who has switched the app
     * to Hindi can hand a borrower a sheet in the language they read it in,
     * rather than the sheet always following the phone's underlying locale. A
     * borrower's own name, address and figures print exactly as recorded either
     * way; only the field labels translate.
     */
    public function sheet(Request $request): void
    {
        $user = $this->auth($request, 'customers.view');

        $id = $request->paramInt('id');
        $lead = LoanAccount::findWithPii($id);

        if ($lead === null) {
            Response::notFound('That loan account could not be found.');
        }

        if (Auth::isAgent()) {
            $assigned = $lead['assigned_agent_id'] === null ? null : (int) $lead['assigned_agent_id'];
            if ($assigned !== (int) $user['id']) {
                Response::forbidden('You can only download the data sheet for a lead assigned to you.');
            }
        } else {
            Auth::assertBranchAccess((int) $lead['branch_id']);
        }

        $language = $request->str('lang', 'en');
        if (!in_array($language, CustomerSheetService::languages(), true)) {
            $language = 'en';
        }

        try {
            $sheet = CustomerSheetService::render($id, $language);
        } catch (\Throwable $e) {
            Response::error('The data sheet could not be produced: ' . $e->getMessage(), 500);
        }

        Logger::audit(
            'export',
            'loan_account',
            $id,
            null,
            ['document' => 'customer_sheet', 'account' => $sheet['account']],
            sprintf('Downloaded the customer data sheet for %s', $sheet['account'])
        );

        Response::download($sheet['bytes'], $sheet['filename'], 'application/pdf');
    }

    /**
     * @param array<string,mixed> $lead
     * @param array<string,mixed> $user
     */
    private function assertLeadAccess(array $lead, array $user): void
    {
        // Agents may only open a lead assigned to them, or one in their branch
        // when they reached it through a branch-scoped search.
        if (Auth::isAgent()) {
            $assigned = $lead['assigned_agent_id'] === null ? null : (int) $lead['assigned_agent_id'];
            $sameBranch = (int) $lead['branch_id'] === (int) ($user['branch_id'] ?? 0);

            if ($assigned !== (int) $user['id'] && !$sameBranch) {
                Response::forbidden('This lead is not in your branch.');
            }
            return;
        }

        Auth::assertBranchAccess((int) $lead['branch_id']);
    }
}
