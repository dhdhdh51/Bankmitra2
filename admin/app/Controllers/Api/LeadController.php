<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Customer;
use App\Models\LoanAccount;
use App\Models\Promise;
use App\Models\Timeline;
use App\Models\VisitReport;
use App\Services\AssignmentService;

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

        Response::success(
            array_map(fn (array $lead): array => $this->presentLead($lead), $page->items),
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

        Response::success(
            array_map(fn (array $lead): array => $this->presentLead($lead), $page->items),
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
            'signatures' => array_map(fn (array $m): array => $this->presentMedia($m, 'signature'), VisitReport::signaturesForLoanAccount($id)),
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
            'search'    => $request->str('search', $request->str('q')),
            'status'    => $request->str('status'),
            'village'   => $request->str('village'),
            'loan_type' => $request->str('loan_type'),
            'npa_only'  => $request->bool('npa_only'),
            'date_from' => $request->str('date_from'),
            'date_to'   => $request->str('date_to'),
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
