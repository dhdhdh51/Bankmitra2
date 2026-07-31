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

        Response::success([
            'report'     => $this->presentVisitFull($report, $withPii),
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
}
