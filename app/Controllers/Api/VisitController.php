<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\LoanAccount;
use App\Models\VisitReport;
use App\Services\VisitService;

/**
 * Field visit report submission and retrieval.
 *
 * Submission is append-only and idempotent: the app sends a client_uuid, so a
 * retry over a flaky rural connection returns the original report instead of
 * filing a duplicate visit.
 */
final class VisitController extends Controller
{
    public function store(Request $request): void
    {
        $user = $this->auth($request, 'visits.create');

        $this->validate($request, [
            'loan_account_id' => 'required|integer',
            'visit_date'      => 'required|date',
            'visit_time'      => 'required|time',
            'promise_amount'  => 'nullable|numeric|min_value:0',
            'promise_date'    => 'nullable|date',
            'occupation'      => 'nullable|in:agriculture,dairy,business,job,labour,others',
            'remarks'         => 'nullable|max:20000',
        ], [
            'loan_account_id' => 'Loan account',
            'visit_date'      => 'Visit date',
            'visit_time'      => 'Visit time',
        ]);

        $leadId = $request->int('loan_account_id');
        $lead = LoanAccount::find($leadId);

        if ($lead === null) {
            Response::notFound('That loan account could not be found.');
        }

        // An agent may only file a report against a lead in their own branch.
        if (Auth::isAgent()) {
            if ((int) $lead['branch_id'] !== (int) ($user['branch_id'] ?? 0)) {
                Response::forbidden('This lead is not in your branch.');
            }
        } else {
            Auth::assertBranchAccess((int) $lead['branch_id']);
        }

        // A promise needs both halves to become a promise case.
        $promiseAmount = $request->nullableFloat('promise_amount');
        $promiseDate = $request->nullableStr('promise_date');
        if ($promiseAmount !== null && $promiseAmount > 0 && $promiseDate === null) {
            Response::validationError(
                ['promise_date' => ['A promise date is required when a promise amount is entered.']],
                'A promise date is required when a promise amount is entered.'
            );
        }

        $agentContext = [
            'id'        => (int) $user['id'],
            'name'      => (string) $user['name'],
            'bc_code'   => $user['bc_code'] === null ? null : (string) $user['bc_code'],
            'branch_id' => $user['branch_id'] === null ? null : (int) $user['branch_id'],
        ];

        try {
            $result = VisitService::submit($request->all(), $agentContext, 'android');
        } catch (\Throwable $e) {
            Response::error('The visit report could not be saved: ' . $e->getMessage(), 422);
        }

        if ($result['duplicate']) {
            Response::success([
                'visit_id'  => $result['visit_id'],
                'duplicate' => true,
            ], 'This visit was already submitted.');
        }

        $fresh = LoanAccount::find($leadId);

        Response::json(true, [
            'visit_id'   => $result['visit_id'],
            'promise_id' => $result['promise_id'],
            'media'      => $result['media'],
            'warnings'   => $result['warnings'],
            'lead'       => $fresh === null ? null : $this->presentLead($fresh),
        ], $result['warnings'] === []
            ? 'Visit report submitted.'
            : 'Visit report submitted, with warnings.', 201);
    }

    /** Visit reports for one loan account, newest first. */
    public function index(Request $request): void
    {
        $user = $this->auth($request, 'visits.view');

        $leadId = $request->int('loan_account_id');
        if ($leadId > 0) {
            $lead = LoanAccount::find($leadId);
            if ($lead === null) {
                Response::notFound('That loan account could not be found.');
            }
            if (!Auth::isAgent()) {
                Auth::assertBranchAccess((int) $lead['branch_id']);
            } elseif ((int) $lead['branch_id'] !== (int) ($user['branch_id'] ?? 0)) {
                Response::forbidden('This lead is not in your branch.');
            }

            Response::success(
                array_map(
                    fn (array $v): array => $this->presentVisitSummary($v),
                    VisitReport::forLoanAccount($leadId, 100)
                )
            );
        }

        // Otherwise: a paginated feed, scoped to the caller.
        $filters = [
            'date_from' => $request->str('date_from'),
            'date_to'   => $request->str('date_to'),
            'village'   => $request->str('village'),
            'search'    => $request->str('search'),
        ];

        if (Auth::isAgent()) {
            $filters['agent_id'] = (int) $user['id'];
        } else {
            $scoped = Auth::scopedBranchId();
            $filters['branch_id'] = $scoped ?? ($request->nullableInt('branch_id') ?: null);
            $agentId = $request->nullableInt('agent_id');
            if ($agentId !== null && $agentId > 0) {
                $filters['agent_id'] = $agentId;
            }
        }

        $page = VisitReport::paginate($filters, $request->page(), $this->perPage($request));

        Response::success(
            array_map(fn (array $v): array => $this->presentVisitSummary($v), $page->items),
            '',
            ['meta' => $page->meta()]
        );
    }

    /** One full visit report, matching the Section 6 field list. */
    public function show(Request $request): void
    {
        $user = $this->auth($request, 'visits.view');

        $id = $request->paramInt('id');
        $withPii = Auth::can('customers.view_pii') || Auth::isAgent();

        $report = $withPii ? VisitReport::findWithPii($id) : VisitReport::find($id);

        if ($report === null) {
            Response::notFound('That visit report could not be found.');
        }

        if (Auth::isAgent()) {
            if ((int) $report['branch_id'] !== (int) ($user['branch_id'] ?? 0)) {
                Response::forbidden('This report is not in your branch.');
            }
        } else {
            Auth::assertBranchAccess((int) $report['branch_id']);
        }

        // Present as null rather than an empty object when the section was not
        // filled in: the app renders the card only when there is something in it,
        // and an empty object would produce a heading over nothing.
        $ots = VisitReport::otsDetails($id);
        $ckcc = VisitReport::ckccDetails($id);

        Response::success([
            'report'     => $this->presentVisitFull($report, $withPii),
            'ots'        => $ots === null ? null : $this->presentOts($ots),
            'ckcc'       => $ckcc === null ? null : $this->presentCkcc($ckcc),
            'photos'     => array_map(fn (array $m): array => $this->presentMedia($m, 'photo'), VisitReport::photos($id)),
            'documents'  => array_map(fn (array $m): array => $this->presentMedia($m, 'document'), VisitReport::documents($id)),
            'signatures' => array_map(fn (array $m): array => $this->presentMedia($m, 'signature'), VisitReport::signatures($id)),
        ]);
    }

    /**
     * Form metadata: the exact option lists the app should render, so a future
     * change to the enum does not require a new APK.
     */
    public function formOptions(Request $request): void
    {
        $this->auth($request, 'visits.create');

        Response::success([
            'occupations'     => array_map(
                static fn (string $value): array => ['value' => $value, 'label' => occupation_label($value)],
                VisitReport::OCCUPATIONS
            ),
            'contact_flags'        => $this->flagList(VisitReport::CONTACT_FLAGS),
            'recovery_flags'       => $this->flagList(VisitReport::RECOVERY_FLAGS),
            'reason_flags'         => $this->flagList(VisitReport::REASON_FLAGS),
            'recommendation_flags' => $this->flagList(VisitReport::RECOMMENDATION_FLAGS),

            // Which kind of report the agent is filing. The app shows the extra
            // sections only for the type selected, so a plain recovery visit is
            // not buried under forty settlement fields.
            'report_types' => $this->optionList(VisitReport::REPORT_TYPES),

            'ots' => [
                'schemes'          => $this->optionList(VisitReport::OTS_SCHEMES),
                'approval_statuses' => $this->optionList(VisitReport::OTS_APPROVAL_STATUSES),
                // Scheme defaults the app pre-fills; both stay editable per case.
                'default_payable_percent'         => 22.50,
                'default_initial_deposit_percent' => 10.00,
            ],

            'ckcc' => [
                'due_buckets'          => $this->optionList(VisitReport::CKCC_DUE_BUCKETS),
                'kyc_statuses'         => $this->optionList(VisitReport::CKCC_KYC_STATUSES),
                'eligibility_flags'    => $this->flagList(VisitReport::CKCC_ELIGIBILITY_FLAGS),
                'document_flags'       => $this->flagList(VisitReport::CKCC_DOCUMENT_FLAGS),
                'consent_flags'        => $this->flagList(VisitReport::CKCC_CONSENT_FLAGS),
                'recommendation_flags' => $this->flagList(VisitReport::CKCC_RECOMMENDATION_FLAGS),
                'status_flags'         => $this->flagList(VisitReport::CKCC_STATUS_FLAGS),
            ],
        ]);
    }

    /**
     * @param array<string,string> $map
     * @return list<array{key:string,label:string}>
     */
    private function flagList(array $map): array
    {
        $out = [];
        foreach ($map as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentOts(array $row): array
    {
        $amount = static fn (string $k): ?float => $row[$k] === null ? null : round((float) $row[$k], 2);
        $percent = static fn (string $k): ?float => $row[$k] === null ? null : round((float) $row[$k], 2);

        return [
            'eligible_for_ots' => (int) $row['eligible_for_ots'] === 1,
            'scheme'           => $row['scheme'] === null ? null : (string) $row['scheme'],
            'scheme_label'     => $row['scheme'] === null
                ? null
                : (VisitReport::OTS_SCHEMES[(string) $row['scheme']] ?? (string) $row['scheme']),

            'outstanding_amount'      => $amount('outstanding_amount'),
            'relief_waiver_percent'   => $percent('relief_waiver_percent'),
            'rlb_amount'              => $amount('rlb_amount'),
            'payable_percent'         => $percent('payable_percent'),
            'borrower_payable_amount' => $amount('borrower_payable_amount'),
            'total_settlement_amount' => $amount('total_settlement_amount'),

            'initial_deposit_percent' => $percent('initial_deposit_percent'),
            'required_deposit_amount' => $amount('required_deposit_amount'),
            'deposit_received'        => (int) $row['deposit_received'] === 1,
            'deposit_amount'          => $amount('deposit_amount'),
            'deposit_date'            => $row['deposit_date'] === null ? null : (string) $row['deposit_date'],
            'deposit_reference'       => $row['deposit_reference'] === null ? null : (string) $row['deposit_reference'],
            'balance_payable'         => $amount('balance_payable'),
            'proposed_final_payment_date' => $row['proposed_final_payment_date'] === null ? null : (string) $row['proposed_final_payment_date'],

            'approval_status'       => (string) $row['approval_status'],
            'approval_status_label' => VisitReport::OTS_APPROVAL_STATUSES[(string) $row['approval_status']] ?? (string) $row['approval_status'],
            'validity_from'         => $row['validity_from'] === null ? null : (string) $row['validity_from'],
            'validity_to'           => $row['validity_to'] === null ? null : (string) $row['validity_to'],
            'expected_closure_date' => $row['expected_closure_date'] === null ? null : (string) $row['expected_closure_date'],

            'borrower_accepted' => (int) $row['borrower_accepted'] === 1,
            'rejection_reason'  => $row['rejection_reason'] === null ? null : (string) $row['rejection_reason'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentCkcc(array $row): array
    {
        $flags = static function (array $map) use ($row): array {
            $out = [];
            foreach ($map as $key => $label) {
                $out[] = [
                    'key'     => $key,
                    'label'   => $label,
                    'checked' => (int) ($row[$key] ?? 0) === 1,
                ];
            }
            return $out;
        };

        $amount = static fn (string $k): ?float => $row[$k] === null ? null : round((float) $row[$k], 2);
        $date = static fn (string $k): ?string => $row[$k] === null ? null : (string) $row[$k];

        return [
            'cif_number'         => $row['cif_number'] === null ? null : (string) $row['cif_number'],
            'sanction_date'      => $date('sanction_date'),
            'sanction_limit'     => $amount('sanction_limit'),
            'drawing_power'      => $amount('drawing_power'),
            'outstanding_amount' => $amount('outstanding_amount'),
            'interest_overdue'   => $amount('interest_overdue'),
            'renewal_due_date'   => $date('renewal_due_date'),
            'expected_npa_date'  => $date('expected_npa_date'),
            'days_remaining'     => $row['days_remaining'] === null ? null : (int) $row['days_remaining'],

            'eligible_for_renewal' => (int) $row['eligible_for_renewal'] === 1,
            'renewal_due_bucket'   => $row['renewal_due_bucket'] === null ? null : (string) $row['renewal_due_bucket'],
            'renewal_due_label'    => $row['renewal_due_bucket'] === null
                ? null
                : (VisitReport::CKCC_DUE_BUCKETS[(string) $row['renewal_due_bucket']] ?? null),
            'kyc_status'           => $row['kyc_status'] === null ? null : (string) $row['kyc_status'],
            'aadhaar_seeded'         => (int) $row['aadhaar_seeded'] === 1,
            'mobile_linked'          => (int) $row['mobile_linked'] === 1,
            'aadhaar_auth_completed' => (int) $row['aadhaar_auth_completed'] === 1,

            'documents'      => $flags(VisitReport::CKCC_DOCUMENT_FLAGS),
            'doc_other_text' => $row['doc_other_text'] === null ? null : (string) $row['doc_other_text'],
            'consent'        => $flags(VisitReport::CKCC_CONSENT_FLAGS),
            'recommendations' => $flags(VisitReport::CKCC_RECOMMENDATION_FLAGS),
            'rec_other_text' => $row['rec_other_text'] === null ? null : (string) $row['rec_other_text'],
            'report_status'  => $flags(VisitReport::CKCC_STATUS_FLAGS),

            'agent_observation' => $row['agent_observation'] === null ? null : (string) $row['agent_observation'],
        ];
    }

    /**
     * Same data, but keyed `value`/`label` rather than `key`/`label`.
     *
     * The two shapes are not interchangeable on the wire: a flag is a checkbox
     * the app posts back by name, an option is a dropdown choice it posts as a
     * value. The app's DTOs are typed accordingly, so mixing them up produces
     * empty dropdowns.
     *
     * @param array<string,string> $map
     * @return list<array{value:string,label:string}>
     */
    private function optionList(array $map): array
    {
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }
        return $out;
    }
}
