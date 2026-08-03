<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Models\Customer;
use App\Models\CustomField;
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

    /**
     * Edits the borrower's details, the loan's core-banking figures, and any
     * custom fields - from the app, mirroring what Admin\CustomerController::edit()
     * already lets the web panel do.
     *
     * Every fixed column comes from LoanAccount::MANUALLY_EDITABLE, the same list the
     * panel edit form is built from, so "what can an agent correct" can never drift
     * between the two surfaces. A field absent from the request body is left
     * untouched - this is a partial update, not a replace-the-whole-record PUT, which
     * is what lets the app save just the field somebody tapped rather than resending
     * everything else at the same time and risking a stale value winning a race.
     */
    public function update(Request $request): void
    {
        $user = $this->auth($request, 'customers.update');

        $id = $request->paramInt('id');
        $lead = LoanAccount::findWithPii($id);

        if ($lead === null) {
            Response::notFound('That loan account could not be found.');
        }

        $this->assertLeadAccess($lead, $user);

        // Everything below is optional-if-absent (see the loop further down), but
        // whatever IS present still has to be well-formed - a mobile number that is
        // not ten digits should not silently become NULL.
        $rules = [
            'name'                  => 'nullable|max:150',
            'father_husband_name'   => 'nullable|max:150',
            'mobile'                => 'nullable|mobile',
            'alt_mobile'            => 'nullable|mobile',
            'alt_mobile_label'      => 'nullable|max:60',
            'aadhaar'               => 'nullable|aadhaar',
            'village'               => 'nullable|max:150',
            'address'               => 'nullable|max:500',
            'loan_type'             => 'nullable|max:80',
            'outstanding_amount'    => 'nullable|numeric|min_value:0',
            'overdue_amount'        => 'nullable|numeric|min_value:0',
            'closure_amount'        => 'nullable|numeric|min_value:0',
            'ots_amount'            => 'nullable|numeric|min_value:0',
            'deposit_amount'        => 'nullable|numeric|min_value:0',
            'npa_date'              => 'nullable|date',
            'ckcc_renewal_due_date' => 'nullable|date',
            'cif_number'            => 'nullable|max:40',
            'asset_classification'  => 'nullable|max:40',
            'interest_rate'         => 'nullable|numeric|min_value:0',
            'installment_amount'    => 'nullable|numeric|min_value:0',
            'last_payment_date'     => 'nullable|date',
            'last_payment_amount'   => 'nullable|numeric|min_value:0',
            'days_past_due'         => 'nullable|numeric|min_value:0',
            'security_value'        => 'nullable|numeric|min_value:0',
            'guarantor_name'        => 'nullable|max:150',
            'maturity_date'         => 'nullable|date',
            'purpose'               => 'nullable|max:150',
            'facility_type'         => 'nullable|in:kcc,od2,other',
            'sanction_date'         => 'nullable|date',
            'sanction_limit'        => 'nullable|numeric|min_value:0',
            'drawing_power'         => 'nullable|numeric|min_value:0',
            'interest_overdue'      => 'nullable|numeric|min_value:0',
            'remarks'               => 'nullable|max:1000',
            'bc_code'               => 'nullable|max:40',
            'next_followup_date'    => 'nullable|date',
            'ots_eligible'          => 'nullable|in:0,1',
            'krm_eligible'          => 'nullable|in:0,1',
        ];

        $validator = Validator::make($request->all(), $rules, [
            'father_husband_name' => 'Father / husband name',
            'ckcc_renewal_due_date' => 'CKCC renewal due date',
            'alt_mobile'          => 'Second mobile',
            'alt_mobile_label'    => 'Whose number it is',
        ]);

        if ($validator->fails()) {
            Response::validationError($validator->errors(), $validator->firstError());
        }

        // ---- Borrower details -------------------------------------------------
        // Written only when the request actually carries at least one of them - an
        // agent correcting the outstanding amount must not blank the father's name
        // because it happened not to be in the same request body.
        $borrowerFields = ['name', 'father_husband_name', 'mobile', 'alt_mobile', 'alt_mobile_label', 'village', 'address', 'aadhaar'];
        $hasBorrowerField = false;
        foreach ($borrowerFields as $borrowerField) {
            if ($request->has($borrowerField)) {
                $hasBorrowerField = true;
                break;
            }
        }
        if ($hasBorrowerField) {
            $customerUpdate = [];
            if ($request->has('name')) {
                $customerUpdate['name'] = mb_substr($request->str('name'), 0, 150);
            }
            if ($request->has('father_husband_name')) {
                $customerUpdate['father_husband_name'] = $request->nullableStr('father_husband_name');
            }
            if ($request->has('village')) {
                $customerUpdate['village'] = $request->nullableStr('village');
            }
            if ($request->has('address')) {
                $customerUpdate['address'] = $request->nullableStr('address');
            }
            if ($request->has('alt_mobile') || $request->has('alt_mobile_label')) {
                $customerUpdate += Customer::altMobileColumns(
                    $request->nullableStr('alt_mobile'),
                    $request->nullableStr('alt_mobile_label')
                );
            }

            $mobile = $request->has('mobile') ? $request->nullableStr('mobile') : null;
            $aadhaar = $request->has('aadhaar') ? $request->nullableStr('aadhaar') : null;
            $touchPii = $request->has('mobile') || $request->has('aadhaar');

            Customer::update((int) $lead['customer_id'], $customerUpdate, $mobile, $aadhaar, $touchPii);

            Logger::audit(
                'update',
                'customer',
                (int) $lead['customer_id'],
                null,
                array_keys($customerUpdate),
                sprintf('Borrower details updated from the app for %s', (string) $lead['loan_account_number'])
            );
        }

        // ---- Loan figures -------------------------------------------------------
        // The exact list a person may correct by hand, shared with the panel's edit
        // form - see LoanAccount::MANUALLY_EDITABLE for why each one is on it and,
        // in a comment right below the list, exactly which columns are deliberately
        // NOT (assignment, status, timestamps: all of those go through their own
        // action so the timeline still says what happened).
        $loanEdits = [];
        foreach ([
            'loan_type'             => 'str',
            'cif_number'            => 'str',
            'outstanding_amount'    => 'money',
            'overdue_amount'        => 'money',
            'closure_amount'        => 'money',
            'ots_amount'            => 'money',
            'deposit_amount'        => 'money',
            'npa_date'              => 'date',
            'ckcc_renewal_due_date' => 'date',
            'asset_classification'  => 'str',
            'interest_rate'         => 'money',
            'installment_amount'    => 'money',
            'last_payment_date'     => 'date',
            'last_payment_amount'   => 'money',
            'days_past_due'         => 'int',
            'security_value'        => 'money',
            'guarantor_name'        => 'str',
            'maturity_date'         => 'date',
            'purpose'               => 'str',
            'facility_type'         => 'str',
            'sanction_date'         => 'date',
            'sanction_limit'        => 'money',
            'drawing_power'         => 'money',
            'interest_overdue'      => 'money',
            'remarks'               => 'str',
            'bc_code'               => 'str',
            'next_followup_date'    => 'date',
            'ots_eligible'          => 'flag',
            'krm_eligible'          => 'flag',
        ] as $column => $kind) {
            if (!$request->has($column)) {
                continue;
            }

            $loanEdits[$column] = match ($kind) {
                'flag'  => $request->nullableStr($column) === null ? null : ($request->str($column) === '1' ? 1 : 0),
                'money' => $request->nullableStr($column) === null ? null : round($request->float($column), 2),
                'int'   => $request->nullableStr($column) === null ? null : max(0, (int) $request->float($column)),
                'date'  => $request->nullableStr($column),
                default => $request->nullableStr($column),
            };
        }

        $loanChanged = LoanAccount::applyManualEdit($id, $loanEdits, Auth::id());

        if ($loanChanged !== []) {
            Logger::audit(
                'update',
                'loan_account',
                $id,
                null,
                $loanChanged,
                sprintf(
                    'Hand-edited %s from the app on %s; the import will not overwrite these again',
                    implode(', ', array_keys($loanChanged)),
                    (string) $lead['loan_account_number']
                )
            );
        }

        // ---- Custom fields --------------------------------------------------
        // Same call the panel's edit form makes - CustomField::saveValues() matches
        // submitted keys against each entity's active definitions and ignores
        // anything else in the request body, so an app that also posts loan
        // columns in the same call cannot accidentally create a stray field.
        $customChanged = array_merge(
            CustomField::saveValues('customer', (int) $lead['customer_id'], $request->all(), Auth::id()),
            CustomField::saveValues('loan_account', $id, $request->all(), Auth::id())
        );

        if ($customChanged !== []) {
            Logger::audit(
                'update',
                'loan_account',
                $id,
                null,
                ['custom_fields' => $customChanged],
                sprintf('Updated custom field(s) from the app: %s', implode(', ', $customChanged))
            );
        }

        $fresh = LoanAccount::findWithPii($id);
        if ($fresh === null) {
            Response::notFound('That loan account could not be found.');
        }

        Response::success(
            $this->presentLead($fresh, true),
            'Saved.'
        );
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
