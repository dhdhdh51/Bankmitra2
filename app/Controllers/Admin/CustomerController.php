<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\Xlsx;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomField;
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
        // Agents see this list, filtered to their own leads by filters() below.
        $this->guard($request, 'customers.view', allowAgent: true);

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
     * documents and the append-only timeline.
     */
    public function show(Request $request): void
    {
        $this->guard($request, 'customers.view', allowAgent: true);

        $id = $request->paramInt('id');

        // PII is only decrypted for users who hold customers.view_pii.
        $showPii = Auth::can('customers.view_pii');
        $lead = $showPii ? LoanAccount::findWithPii($id) : LoanAccount::find($id);

        if ($lead === null) {
            $this->back('/customers', 'danger', 'That loan account could not be found.');
        }

        Auth::assertBranchAccess((int) $lead['branch_id']);
        $this->assertOwnLead($lead);

        $this->logView('Customers', sprintf('Viewed loan account %s', (string) $lead['loan_account_number']));

        $scoped = Auth::scopedBranchId();

        $this->view($request, 'customers/show', [
            'customerFields' => CustomField::withValues('customer', (int) $lead['customer_id']),
            'loanFields'     => CustomField::withValues('loan_account', $id),
            'title'        => (string) $lead['customer_name'],
            'lead'         => $lead,
            'showPii'      => $showPii,
            'timeline'     => Timeline::forLoanAccount($id),
            'visits'       => VisitReport::forLoanAccount($id),
            'promises'     => Promise::forLoanAccount($id),
            'photos'       => VisitReport::photosForLoanAccount($id),
            'documents'    => VisitReport::documentsForLoanAccount($id),
            'otherLoans'   => Customer::loanAccounts((int) $lead['customer_id']),
            'agents'       => Auth::can('leads.assign') ? User::agents($scoped ?? (int) $lead['branch_id']) : [],
            'branches'     => Auth::can('leads.transfer') ? Branch::options($scoped) : [],
        ]);
    }

    /**
     * Edits the borrower's contact details. Loan figures are not editable here:
     * they come from the core banking Excel import, so hand-editing them would
     * be silently overwritten by the next upload.
     *
     * THAT IS NO LONGER THE CASE. Loan figures are editable here, and every column a
     * human changes is recorded in loan_accounts.manual_overrides so the importer skips
     * it and reports that it did. Read-only was the safe-looking choice and the wrong
     * one: it did not stop corrections happening, it just moved them into a spreadsheet
     * nobody else could see.
     */
    /**
     * Adds a borrower and a loan account by hand.
     *
     * Until now the only way a lead could enter the system was an Excel import, which
     * assumes head office has the account before the field does. That is often the wrong
     * way round: a branch hands an agent a new NPA, a takeover, or an account opened
     * elsewhere, on paper, and the agent is standing in front of the borrower weeks
     * before the account appears in anybody's export.
     *
     * Three things this deliberately does NOT do:
     *
     *  - It does not mark the typed figures as manual overrides. A hand-created lead is a
     *    placeholder for an account the bank's export has not reached yet; when the export
     *    does reach it, the core banking figures are the ones that matter and they must
     *    win. Correcting a figure afterwards through the edit form still stamps an
     *    override, which is the case where a human genuinely knows better.
     *  - It does not let a scoped user choose the branch. Both `customers.branch_id` and
     *    `loan_accounts.branch_id` are set from the caller's own branch, from one value:
     *    the two are independent columns and the panel reads them in different places, so
     *    a lead with two different branches is filterable in one and visible in the other.
     *  - It does not leave an agent's own lead unassigned. `assertOwnLead()` gates an
     *    agent to leads assigned to them, so an unassigned new lead would vanish the
     *    moment they pressed Save.
     */
    public function create(Request $request): void
    {
        // Its own permission, not customers.update: adding an account is not correcting
        // one, and an auditor who may read every borrower must not be able to invent one.
        $this->guard($request, 'customers.create', allowAgent: true);

        $scopedBranch = Auth::scopedBranchId();
        $ownAgentId = Auth::scopedAgentId();

        // A borrower can hold more than one account - a KCC and an OD-2 are two accounts
        // and one person - so the form can be opened against an existing borrower to add
        // another account to them rather than duplicating the person.
        $existing = null;
        $existingId = $request->int('customer_id');
        if ($existingId > 0) {
            $existing = Customer::findWithPii($existingId);
            if ($existing === null) {
                $this->back('/customers', 'danger', 'That borrower could not be found.');
            }
            Auth::assertBranchAccess((int) $existing['branch_id']);
        }

        if (!$request->isPost()) {
            $this->view($request, 'customers/create', [
                'title'      => $existing === null ? 'Add borrower' : 'Add another loan account',
                'existing'   => $existing,
                'branches'   => Branch::options($scopedBranch),
                'agents'     => $ownAgentId === null ? User::agents($scopedBranch) : [],
                'ownAgentId' => $ownAgentId,
                'facilities' => LoanAccount::FACILITIES,
            ]);
        }

        $rules = [
            'loan_account_number' => 'required|max:60',
            'loan_type'           => 'nullable|max:80',
            'facility_type'       => 'nullable|in:kcc,od2,other',
            'cif_number'          => 'nullable|max:40',
            'outstanding_amount'  => 'nullable|numeric|min_value:0',
            'overdue_amount'      => 'nullable|numeric|min_value:0',
            'npa_date'            => 'nullable|date',
            'ckcc_renewal_due_date' => 'nullable|date',
            'asset_classification' => 'nullable|max:40',
            'interest_rate'       => 'nullable|numeric|min_value:0',
            'days_past_due'       => 'nullable|numeric|min_value:0',
            'guarantor_name'      => 'nullable|max:150',
            'purpose'             => 'nullable|max:150',
            'remarks'             => 'nullable|max:1000',
            'sanction_date'       => 'nullable|date',
            'sanction_limit'      => 'nullable|numeric|min_value:0',
            'drawing_power'       => 'nullable|numeric|min_value:0',
        ];

        // The borrower's own fields are only asked for when there is no borrower yet.
        if ($existing === null) {
            $rules += [
                'name'                => 'required|max:150',
                'father_husband_name' => 'nullable|max:150',
                'mobile'              => 'nullable|mobile',
                'alt_mobile'          => 'nullable|mobile',
                'alt_mobile_label'    => 'nullable|max:60',
                'aadhaar'             => 'nullable|aadhaar',
                'village'             => 'nullable|max:150',
                'address'             => 'nullable|max:500',
            ];
        }

        $validator = Validator::make($request->all(), $rules, [
            'loan_account_number' => 'Loan account number',
            'father_husband_name' => 'Father / husband name',
            'ckcc_renewal_due_date' => 'CKCC renewal due date',
            'alt_mobile'          => 'Second mobile',
            'alt_mobile_label'    => 'Whose number it is',
        ]);

        $errors = $validator->fails() ? $validator->errors() : [];

        // Checked by hand rather than with `unique:`, so the message can say WHERE the
        // number already is. "This loan account number is already in use" leaves somebody
        // who has just typed a whole statement with nothing to do next.
        $account = trim($request->str('loan_account_number'));
        if ($account !== '' && !isset($errors['loan_account_number'])) {
            $clash = LoanAccount::findByNumber($account);
            if ($clash !== null) {
                $errors['loan_account_number'] = [sprintf(
                    'Account %s already exists - %s, %s branch. Open it from the borrower list instead of adding it again.',
                    $account,
                    (string) ($clash['customer_name'] ?? 'unknown borrower'),
                    (string) ($clash['branch_name'] ?? 'another')
                )];
            }
        }

        if ($errors !== []) {
            $this->backWithErrors($this->createUrl($existingId), $errors, $request->all());
        }

        // Never read from the request for a scoped user: a posted branch_id is a request
        // to write into somebody else's branch. And an account added to an existing
        // borrower belongs to that borrower's branch - it is not a question worth asking,
        // and asking it invites the answer that splits a person across two branches.
        $branchId = $scopedBranch
            ?? ($existing !== null ? (int) $existing['branch_id'] : $request->int('branch_id'));
        if ($branchId <= 0) {
            $this->backWithErrors(
                $this->createUrl($existingId),
                ['branch_id' => ['Choose the branch this account belongs to.']],
                $request->all()
            );
        }
        Auth::assertBranchAccess($branchId);

        // An agent gets their own lead. A manager or admin may hand it to one of their
        // agents now, or leave it unassigned and distribute it later.
        $agentId = $ownAgentId;
        if ($agentId === null && $request->int('assigned_agent_id') > 0) {
            $candidate = User::find($request->int('assigned_agent_id'));
            if ($candidate !== null
                && (string) ($candidate['role_slug'] ?? '') === 'agent'
                && (int) $candidate['branch_id'] === $branchId) {
                $agentId = (int) $candidate['id'];
            }
        }

        $npaDate = $request->nullableStr('npa_date');
        $now = date('Y-m-d H:i:s');

        // One transaction: a loan account that fails to insert must not leave a borrower
        // behind, and a borrower nobody owes anything is a row nothing in the panel lists.
        try {
            [$customerId, $loanId] = Database::instance()->transaction(
                function () use ($request, $existing, $existingId, $branchId, $account, $agentId, $npaDate, $now): array {
                    $customerId = $existingId;

                    if ($existing === null) {
                        $customerId = Customer::create([
                            'branch_id'           => $branchId,
                            'name'                => mb_substr($request->str('name'), 0, 150),
                            'father_husband_name' => $request->nullableStr('father_husband_name'),
                            'village'             => $request->nullableStr('village'),
                            'address'             => $request->nullableStr('address'),
                        ] + Customer::altMobileColumns(
                            $request->nullableStr('alt_mobile'),
                            $request->nullableStr('alt_mobile_label')
                        ), $request->nullableStr('mobile'), $request->nullableStr('aadhaar'));
                    }

                    $loanId = LoanAccount::create([
                        'loan_account_number' => mb_substr($account, 0, 60),
                        'customer_id'         => $customerId,
                        'branch_id'           => $branchId,
                        'current_status'      => 'pending',
                        'is_npa'              => $npaDate === null ? 0 : 1,
                        'npa_date'            => $npaDate,
                        'outstanding_amount'  => round($request->float('outstanding_amount'), 2),
                        'overdue_amount'      => round($request->float('overdue_amount'), 2),
                        'assigned_agent_id'   => $agentId,
                        'assigned_at'         => $agentId === null ? null : $now,
                        'assigned_by'         => $agentId === null ? null : Auth::id(),
                        // NULL, and it has to be: import_id is a foreign key into
                        // lead_imports and there is no import behind this row.
                        'import_id'           => null,
                    ] + self::optionalLoanColumns($request));

                    return [$customerId, $loanId];
                }
            );
        } catch (\Throwable $e) {
            // The unique key on loan_account_number is the last word. Two people typing
            // the same account at once get a message rather than a stack trace.
            $this->backWithErrors(
                $this->createUrl($existingId),
                ['loan_account_number' => ['That account could not be saved: ' . $e->getMessage()]],
                $request->all()
            );
        }

        $actorName = (string) (Auth::user()['name'] ?? '');

        Timeline::record(
            $loanId,
            'lead_created',
            'Lead created by hand',
            sprintf(
                'Typed into the panel by %s, not imported from a bank export. Outstanding %s.',
                $actorName !== '' ? $actorName : 'a panel user',
                money($request->float('outstanding_amount'))
            ),
            Auth::id(),
            $actorName
        );

        if ($agentId !== null) {
            Timeline::record(
                $loanId,
                'assigned',
                'Assigned to agent',
                $ownAgentId !== null
                    ? 'Assigned to the agent who created it.'
                    : 'Assigned when the account was created.',
                Auth::id(),
                $actorName,
                null,
                null,
                ['agent_id' => $agentId]
            );
        }

        Logger::audit(
            'create',
            'loan_account',
            $loanId,
            null,
            ['loan_account_number' => $account, 'customer_id' => $customerId, 'branch_id' => $branchId],
            sprintf('Created loan account %s by hand', $account)
        );

        $saved = CustomField::saveValues('customer', $customerId, $request->all(), Auth::id())
            + CustomField::saveValues('loan_account', $loanId, $request->all(), Auth::id());

        $this->back(
            '/customers/' . $loanId,
            'success',
            $existing === null
                ? 'Borrower and loan account added. Figures typed here are replaced by the next import that carries this account.'
                : 'Loan account added to this borrower.'
        );
    }

    /** Where the create form posts back to, keeping the borrower it was opened for. */

    private function createUrl(int $customerId): string
    {
        return '/customers/create' . ($customerId > 0 ? '?customer_id=' . $customerId : '');
    }

    /**
     * The loan columns a person may type on creation, cast and stripped of blanks.
     *
     * Blank-stripped rather than written as NULL so the DDL defaults apply, which is the
     * same shape ImportService uses for the columns a spreadsheet did not carry.
     *
     * @return array<string,mixed>
     */
    private static function optionalLoanColumns(Request $request): array
    {
        $columns = [
            'loan_type'             => 'str',
            'facility_type'         => 'str',
            'cif_number'            => 'str',
            'ckcc_renewal_due_date' => 'date',
            'asset_classification'  => 'str',
            'interest_rate'         => 'money',
            'days_past_due'         => 'int',
            'guarantor_name'        => 'str',
            'purpose'               => 'str',
            'remarks'               => 'str',
            'sanction_date'         => 'date',
            'sanction_limit'        => 'money',
            'drawing_power'         => 'money',
        ];

        $out = [];
        foreach ($columns as $column => $kind) {
            $value = match ($kind) {
                'money' => $request->nullableStr($column) === null ? null : round($request->float($column), 2),
                'int'   => $request->nullableStr($column) === null ? null : max(0, (int) $request->float($column)),
                default => $request->nullableStr($column),
            };

            if ($value !== null) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    public function edit(Request $request): void
    {
        $this->guard($request, 'customers.update', allowAgent: true);

        $id = $request->paramInt('id');
        $lead = LoanAccount::findWithPii($id);

        if ($lead === null) {
            $this->back('/customers', 'danger', 'That loan account could not be found.');
        }
        Auth::assertBranchAccess((int) $lead['branch_id']);
        $this->assertOwnLead($lead);

        if (!$request->isPost()) {
            $this->view($request, 'customers/edit', [
                'title'            => 'Edit borrower',
                'lead'             => $lead,
                'customerFields'   => CustomField::withValues('customer', (int) $lead['customer_id']),
                'loanFields'       => CustomField::withValues('loan_account', $id),
                'loanEditable'     => LoanAccount::MANUALLY_EDITABLE,
                'overridden'       => LoanAccount::overriddenColumns($lead['manual_overrides'] ?? null),
            ]);
        }

        $validator = Validator::make($request->all(), [
            'name'                => 'required|max:150',
            'father_husband_name' => 'nullable|max:150',
            'mobile'              => 'nullable|mobile',
            // The number that actually reaches them, and whose it is.
            'alt_mobile'          => 'nullable|mobile',
            'alt_mobile_label'    => 'nullable|max:60',
            'aadhaar'             => 'nullable|aadhaar',
            'village'             => 'nullable|max:150',
            'address'             => 'nullable|max:500',
            // Loan figures. Editable now, with the override recorded so the next
            // import leaves them alone - see LoanAccount::applyManualEdit().
            'loan_type'           => 'nullable|max:80',
            'outstanding_amount'  => 'nullable|numeric|min_value:0',
            'overdue_amount'      => 'nullable|numeric|min_value:0',
            'closure_amount'      => 'nullable|numeric|min_value:0',
            'ots_amount'          => 'nullable|numeric|min_value:0',
            'deposit_amount'      => 'nullable|numeric|min_value:0',
            'npa_date'            => 'nullable|date',
            'ckcc_renewal_due_date' => 'nullable|date',
            'cif_number'          => 'nullable|max:40',
            // The rest of the core banking statement.
            'asset_classification' => 'nullable|max:40',
            'interest_rate'       => 'nullable|numeric|min_value:0',
            'installment_amount'  => 'nullable|numeric|min_value:0',
            'last_payment_date'   => 'nullable|date',
            'last_payment_amount' => 'nullable|numeric|min_value:0',
            'days_past_due'       => 'nullable|numeric|min_value:0',
            'security_value'      => 'nullable|numeric|min_value:0',
            'guarantor_name'      => 'nullable|max:150',
            'maturity_date'       => 'nullable|date',
            'purpose'             => 'nullable|max:150',
            'facility_type'       => 'nullable|in:kcc,od2,other',
            'sanction_date'       => 'nullable|date',
            'sanction_limit'      => 'nullable|numeric|min_value:0',
            'drawing_power'       => 'nullable|numeric|min_value:0',
            'interest_overdue'    => 'nullable|numeric|min_value:0',
            'remarks'             => 'nullable|max:1000',
            'bc_code'             => 'nullable|max:40',
            'next_followup_date'  => 'nullable|date',
            'ots_eligible'        => 'nullable|in:0,1',
            'krm_eligible'        => 'nullable|in:0,1',
            // The identity of the account, and the one field an import matches on. Editable
            // because a number typed wrong at creation has to be fixable, and refused when
            // it would collide with another account.
            //
            // NOT `required`. Every other field on this form is written only when the
            // request carries it, so a caller that posts three fields changes three fields -
            // and making this one mandatory turned every partial post into a validation
            // failure that saved nothing. Eighteen existing assertions caught that
            // immediately, which is the only reason it is not in the hosting package.
            'loan_account_number' => 'nullable|max:60',
            'current_status'      => 'nullable|in:' . implode(',', LoanAccount::STATUSES),
        ], [
            'father_husband_name' => 'Father / husband name',
            'ckcc_renewal_due_date' => 'CKCC renewal due date',
            'alt_mobile'          => 'Second mobile',
            'alt_mobile_label'    => 'Whose number it is',
            'remarks'             => 'Notes on this account',
        ]);

        if ($validator->fails()) {
            $this->backWithErrors('/customers/' . $id . '/edit', $validator->errors(), $request->all());
        }

        $before = [
            'name'                => $lead['customer_name'],
            'father_husband_name' => $lead['father_husband_name'],
            'village'             => $lead['village'],
            'address'             => $lead['address'],
            // The masked form, not the number: an audit row is read by people who may not
            // hold customers.view_pii, and a diff that spells out a phone number hands it
            // to all of them.
            'alt_mobile'          => $lead['alt_mobile_masked'],
            'alt_mobile_label'    => $lead['alt_mobile_label'],
        ];

        $mobile = $request->nullableStr('mobile');
        $aadhaar = $request->nullableStr('aadhaar');

        $altMobile = $request->nullableStr('alt_mobile');
        $altColumns = Customer::altMobileColumns($altMobile, $request->nullableStr('alt_mobile_label'));

        $after = [
            'name'                => $request->str('name'),
            'father_husband_name' => $request->nullableStr('father_husband_name'),
            'village'             => $request->nullableStr('village'),
            'address'             => $request->nullableStr('address'),
            'alt_mobile'          => $altColumns['alt_mobile_masked'],
            'alt_mobile_label'    => $altColumns['alt_mobile_label'],
        ];

        // $after is the shape the AUDIT reads; the write needs real columns. `alt_mobile`
        // is not one - the number lives in three (enc, hash, masked) plus its label - so
        // passing $after straight through would fail on "unknown column alt_mobile".
        Customer::update(
            (int) $lead['customer_id'],
            [
                'name'                => $after['name'],
                'father_husband_name' => $after['father_husband_name'],
                'village'             => $after['village'],
                'address'             => $after['address'],
            ] + $altColumns,
            $mobile,
            $aadhaar,
            true
        );

        Logger::auditDiff(
            'customer',
            (int) $lead['customer_id'],
            $before,
            $after,
            sprintf('Updated borrower for %s', (string) $lead['loan_account_number'])
        );

        // ---- Loan figures ---------------------------------------------------
        // These come from the core banking export, which is why they used to be
        // read-only: the next upload would silently undo a correction. They are
        // editable now, and every hand-edited column is recorded in
        // loan_accounts.manual_overrides so the importer can leave it alone and say
        // that it did. Editable-and-tracked beats read-only, which just moved the
        // correction into a spreadsheet nobody else can see.
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
            // Three-state, not a checkbox. An unticked box and "the file never said" are
            // different facts about an account, and the customer sheet prints them
            // differently - so the control is a select with an explicit "not stated".
            'ots_eligible'          => 'flag',
            'krm_eligible'          => 'flag',
        ] as $column => $kind) {
            if (!$request->has($column)) {
                continue;
            }

            $loanEdits[$column] = match ($kind) {
                'flag' => $request->nullableStr($column) === null
                    ? null
                    : ($request->str($column) === '1' ? 1 : 0),
                'money' => $request->nullableStr($column) === null
                    ? null
                    : round((float) $request->float($column), 2),
                'int'   => $request->nullableStr($column) === null
                    ? null
                    : max(0, (int) $request->float($column)),
                'date'  => $request->nullableStr($column),
                default => $request->nullableStr($column),
            };
        }

        // ---- The account number ---------------------------------------------
        //
        // Editable, because a number typed wrong when the account was created has to be
        // fixable and there is no other way to fix it. Handled here rather than through
        // applyManualEdit(), for two reasons: it is the key an import matches on, so a
        // collision has to be refused rather than stamped as an override; and a rename is
        // worth its own line in the timeline, since every visit, promise and photo already
        // attached to the row keeps pointing at it under a new name.
        $newNumber = trim($request->str('loan_account_number'));
        $oldNumber = (string) $lead['loan_account_number'];

        if ($request->has('loan_account_number') && $newNumber !== '' && $newNumber !== $oldNumber) {
            $clash = LoanAccount::findByNumber($newNumber);
            if ($clash !== null) {
                $this->backWithErrors(
                    '/customers/' . $id . '/edit',
                    ['loan_account_number' => [sprintf(
                        'Account %s already belongs to %s in %s branch. Two accounts cannot share a number.',
                        $newNumber,
                        (string) ($clash['customer_name'] ?? 'another borrower'),
                        (string) ($clash['branch_name'] ?? 'another')
                    )]],
                    $request->all()
                );
            }

            LoanAccount::update($id, ['loan_account_number' => mb_substr($newNumber, 0, 60)]);

            Timeline::record(
                $id,
                'lead_updated',
                'Account number corrected',
                sprintf('%s was corrected to %s.', $oldNumber, $newNumber),
                Auth::id(),
                (string) (Auth::user()['name'] ?? ''),
                null,
                null,
                ['from' => $oldNumber, 'to' => $newNumber]
            );

            Logger::audit(
                'update',
                'loan_account',
                $id,
                ['loan_account_number' => $oldNumber],
                ['loan_account_number' => $newNumber],
                sprintf('Renamed loan account %s to %s', $oldNumber, $newNumber)
            );
        }

        // ---- The status ------------------------------------------------------
        //
        // Through AssignmentService, not written here: it stamps closed_at, and it writes
        // closed / reopened / status_changed to the timeline as three different events. A
        // direct UPDATE would move the badge and leave the history saying nothing happened.
        $newStatus = $request->nullableStr('current_status');
        if ($newStatus !== null && $newStatus !== (string) $lead['current_status']) {
            AssignmentService::setStatus([$id], $newStatus, 'Changed on the borrower\'s edit form.');
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
                    'Hand-edited %s on %s; the import will not overwrite these again',
                    implode(', ', array_keys($loanChanged)),
                    (string) $lead['loan_account_number']
                )
            );
        }

        // ---- Custom fields --------------------------------------------------
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
                sprintf('Updated custom field(s): %s', implode(', ', $customChanged))
            );
        }

        $this->back('/customers/' . $id, 'success', 'Borrower and loan details updated.');
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
            'distribute'         => $this->bulkDistribute($ids),
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
     * Spread the selection evenly across the branch's agents.
     *
     * Behind leads.assign rather than a permission of its own: it is the same act as
     * assigning, minus the part where somebody has to decide who gets what.
     *
     * @param  list<int> $ids
     * @return array{updated:int,skipped:int,messages:list<string>}
     */
    private function bulkDistribute(array $ids): array
    {
        Auth::requirePermissionPanel('leads.assign', '/customers');

        return AssignmentService::distribute($ids);
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
