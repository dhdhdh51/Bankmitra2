<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Auth;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validator;

/**
 * Base class for the REST API.
 *
 * Every response uses the envelope { success, data, message }. CSRF is not
 * applied here: the API authenticates with a Bearer JWT rather than a cookie, so
 * there is no ambient credential for a cross-site request to ride on.
 */
abstract class Controller
{
    /**
     * Authenticates the caller and enforces a permission.
     *
     * @return array<string,mixed> the current user
     */
    protected function auth(Request $request, ?string $permission = null): array
    {
        $user = Auth::requireApi($request);

        // A user forced to change their password can only reach the auth endpoints.
        if ((int) ($user['must_change_password'] ?? 0) === 1 && !$this->allowsPasswordChangeOnly($request)) {
            Response::json(false, ['must_change_password' => true], 'Please change your password before continuing.', 403);
        }

        if ($permission !== null) {
            Auth::requirePermission($permission);
        }

        return $user;
    }

    /** The only endpoints reachable while a password change is pending. */
    private function allowsPasswordChangeOnly(Request $request): bool
    {
        return in_array($request->path(), [
            '/api/v1/auth/change-password',
            '/api/v1/auth/logout',
            '/api/v1/auth/me',
        ], true);
    }

    /**
     * Validates input, returning a 422 with per-field errors on failure.
     *
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @return array<string,mixed> the raw input
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        $input = $request->all();
        $validator = Validator::make($input, $rules, $labels);

        if ($validator->fails()) {
            Response::validationError($validator->errors(), $validator->firstError());
        }

        return $input;
    }

    protected function perPage(Request $request): int
    {
        return $request->perPage((int) Settings::get('records_per_page', '25'), 100);
    }

    protected function logActivity(string $activity, string $module, string $description): void
    {
        Logger::activity($activity, $module, $description);
    }

    // -----------------------------------------------------------------------
    // Presenters
    //
    // The app never receives raw database rows: these shape a stable contract so
    // internal column changes cannot break a released APK. PII is masked unless
    // the caller holds customers.view_pii.
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $lead
     * @return array<string,mixed>
     */
    protected function presentLead(array $lead, bool $withPii = false): array
    {
        return [
            'id'                  => (int) $lead['id'],
            'loan_account_number' => (string) $lead['loan_account_number'],
            'customer_id'         => (int) $lead['customer_id'],
            'customer_name'       => (string) $lead['customer_name'],
            'father_husband_name' => $lead['father_husband_name'] === null ? null : (string) $lead['father_husband_name'],
            'village'             => $lead['village'] === null ? null : (string) $lead['village'],
            'address'             => $lead['address'] === null ? null : (string) $lead['address'],
            'mobile'              => $withPii ? ($lead['mobile'] ?? null) : null,
            'mobile_masked'       => $lead['mobile_masked'] === null ? null : (string) $lead['mobile_masked'],
            'aadhaar'             => $withPii ? ($lead['aadhaar'] ?? null) : null,
            'aadhaar_masked'      => $lead['aadhaar_masked'] === null ? null : (string) $lead['aadhaar_masked'],
            'bc_code'             => $lead['bc_code'] === null ? null : (string) $lead['bc_code'],
            'loan_type'           => $lead['loan_type'] === null ? null : (string) $lead['loan_type'],
            'outstanding_amount'  => round((float) $lead['outstanding_amount'], 2),
            'overdue_amount'      => round((float) $lead['overdue_amount'], 2),
            'npa_date'            => $lead['npa_date'] === null ? null : (string) $lead['npa_date'],
            'is_npa'              => (int) $lead['is_npa'] === 1,
            'current_status'      => (string) $lead['current_status'],
            'branch_id'           => (int) $lead['branch_id'],
            'branch_name'         => (string) $lead['branch_name'],
            'branch_code'         => (string) ($lead['branch_code'] ?? ''),
            'assigned_agent_id'   => $lead['assigned_agent_id'] === null ? null : (int) $lead['assigned_agent_id'],
            'agent_name'          => $lead['agent_name'] === null ? null : (string) $lead['agent_name'],
            'visit_count'         => (int) $lead['visit_count'],
            'last_visit_at'       => $lead['last_visit_at'] === null ? null : (string) $lead['last_visit_at'],
            'next_followup_date'  => $lead['next_followup_date'] === null ? null : (string) $lead['next_followup_date'],
            'remarks'             => $lead['remarks'] === null ? null : (string) $lead['remarks'],
            'created_at'          => (string) $lead['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $visit
     * @return array<string,mixed>
     */
    protected function presentVisitSummary(array $visit): array
    {
        return [
            'id'                 => (int) $visit['id'],
            // Which kind of report this is, so a list can label a settlement or a
            // renewal instead of showing every row as a plain recovery visit.
            'report_type'        => (string) ($visit['report_type'] ?? 'recovery'),
            'visit_date'         => (string) $visit['visit_date'],
            'visit_time'         => (string) $visit['visit_time'],
            'agent_name'         => (string) $visit['agent_name'],
            'village'            => $visit['village'] === null ? null : (string) $visit['village'],
            'customer_met'       => (int) $visit['customer_met'] === 1,
            'family_member_met'  => (int) ($visit['family_member_met'] ?? 0) === 1,
            'house_locked'       => (int) $visit['house_locked'] === 1,
            'phone_contact'      => (int) ($visit['phone_contact'] ?? 0) === 1,
            'phone_switched_off' => (int) ($visit['phone_switched_off'] ?? 0) === 1,
            'ready_to_pay'       => (int) ($visit['ready_to_pay'] ?? 0) === 1,
            'not_ready'          => (int) ($visit['not_ready'] ?? 0) === 1,
            'promise_amount'     => $visit['promise_amount'] === null ? null : round((float) $visit['promise_amount'], 2),
            'promise_date'       => $visit['promise_date'] === null ? null : (string) $visit['promise_date'],
            'outstanding_amount' => round((float) ($visit['outstanding_amount'] ?? 0), 2),
            'overdue_amount'     => round((float) ($visit['overdue_amount'] ?? 0), 2),
            'remarks'            => $visit['remarks'] === null ? null : (string) $visit['remarks'],
            'photo_count'        => (int) ($visit['photo_count'] ?? 0),
            'document_count'     => (int) ($visit['document_count'] ?? 0),
            'signature_count'    => (int) ($visit['signature_count'] ?? 0),
            'created_at'         => (string) ($visit['created_at'] ?? ''),
        ];
    }

    /**
     * Full visit report, matching the Section 6 field list exactly.
     *
     * @param array<string,mixed> $visit
     * @return array<string,mixed>
     */
    protected function presentVisitFull(array $visit, bool $withPii = false): array
    {
        return [
            'id'              => (int) $visit['id'],
            'loan_account_id' => (int) $visit['loan_account_id'],

            'general' => [
                'visit_date' => (string) $visit['visit_date'],
                'visit_time' => (string) $visit['visit_time'],
                'bc_code'    => $visit['bc_code'] === null ? null : (string) $visit['bc_code'],
                'branch'     => (string) ($visit['branch_name'] ?? ''),
                'agent_name' => (string) $visit['agent_name'],
                'village'    => $visit['village'] === null ? null : (string) $visit['village'],
            ],

            'borrower' => [
                'customer_name'       => (string) $visit['customer_name'],
                'father_husband_name' => $visit['father_husband_name'] === null ? null : (string) $visit['father_husband_name'],
                'address'             => $visit['address'] === null ? null : (string) $visit['address'],
                'mobile'              => $withPii ? ($visit['mobile'] ?? null) : null,
                'mobile_masked'       => $visit['mobile_masked'] === null ? null : (string) $visit['mobile_masked'],
                'aadhaar'             => $withPii ? ($visit['aadhaar'] ?? null) : null,
                'aadhaar_masked'      => $visit['aadhaar_masked'] === null ? null : (string) $visit['aadhaar_masked'],
            ],

            'loan' => [
                'loan_account_number' => (string) $visit['loan_account_number'],
                'loan_type'           => $visit['loan_type'] === null ? null : (string) $visit['loan_type'],
                'outstanding_amount'  => round((float) $visit['outstanding_amount'], 2),
                'overdue_amount'      => round((float) $visit['overdue_amount'], 2),
                'npa_date'            => $visit['npa_date'] === null ? null : (string) $visit['npa_date'],
                'current_status'      => $visit['current_status'] === null ? null : (string) $visit['current_status'],
            ],

            'contact' => [
                'customer_met'               => (int) $visit['customer_met'] === 1,
                'family_member_met'          => (int) $visit['family_member_met'] === 1,
                'house_locked'               => (int) $visit['house_locked'] === 1,
                'phone_contact'              => (int) $visit['phone_contact'] === 1,
                'phone_switched_off'         => (int) $visit['phone_switched_off'] === 1,
                'family_member_name'         => $visit['family_member_name'] === null ? null : (string) $visit['family_member_name'],
                'family_member_relationship' => $visit['family_member_relationship'] === null ? null : (string) $visit['family_member_relationship'],
            ],

            'verification' => [
                'borrower_alive'        => (int) $visit['borrower_alive'] === 1,
                'same_address'          => (int) $visit['same_address'] === 1,
                'shifted'               => (int) $visit['shifted'] === 1,
                'occupation'            => $visit['occupation'] === null ? null : (string) $visit['occupation'],
                'occupation_other_text' => $visit['occupation_other_text'] === null ? null : (string) $visit['occupation_other_text'],
            ],

            'recovery' => [
                'ready_to_pay'     => (int) $visit['ready_to_pay'] === 1,
                'not_ready'        => (int) $visit['not_ready'] === 1,
                'interest_payment' => (int) $visit['interest_payment'] === 1,
                'ots'              => (int) $visit['ots'] === 1,
                'promise_amount'   => $visit['promise_amount'] === null ? null : round((float) $visit['promise_amount'], 2),
                'promise_date'     => $visit['promise_date'] === null ? null : (string) $visit['promise_date'],
            ],

            'non_payment_reason' => [
                'financial_problem' => (int) $visit['reason_financial_problem'] === 1,
                'crop_loss'         => (int) $visit['reason_crop_loss'] === 1,
                'animal_loss'       => (int) $visit['reason_animal_loss'] === 1,
                'illness'           => (int) $visit['reason_illness'] === 1,
                'unemployment'      => (int) $visit['reason_unemployment'] === 1,
                'dispute'           => (int) $visit['reason_dispute'] === 1,
                'other_loan'        => (int) $visit['reason_other_loan'] === 1,
                'others'            => (int) $visit['reason_others'] === 1,
                'other_text'        => $visit['reason_other_text'] === null ? null : (string) $visit['reason_other_text'],
            ],

            'recommendation' => [
                'recovery_possible' => (int) $visit['rec_recovery_possible'] === 1,
                'regular_followup'  => (int) $visit['rec_regular_followup'] === 1,
                'legal_action'       => (int) $visit['rec_legal_action'] === 1,
                'rc'                 => (int) $visit['rec_rc'] === 1,
                'ots'                => (int) $visit['rec_ots'] === 1,
                'others'             => (int) $visit['rec_others'] === 1,
                'other_text'         => $visit['rec_other_text'] === null ? null : (string) $visit['rec_other_text'],
            ],

            'remarks'     => $visit['remarks'] === null ? null : (string) $visit['remarks'],
            'source'      => (string) $visit['source'],
            'app_version' => $visit['app_version'] === null ? null : (string) $visit['app_version'],
            'created_at'  => (string) $visit['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $promise
     * @return array<string,mixed>
     */
    protected function presentPromise(array $promise): array
    {
        return [
            'id'              => (int) $promise['id'],
            'loan_account_id' => (int) $promise['loan_account_id'],
            'promise_amount'  => round((float) $promise['promise_amount'], 2),
            'promise_date'    => (string) $promise['promise_date'],
            'status'          => (string) $promise['status'],
            'agent_name'      => (string) ($promise['agent_name'] ?? ''),
            'notes'           => $promise['notes'] === null ? null : (string) $promise['notes'],
            'settled_at'      => $promise['settled_at'] === null ? null : (string) $promise['settled_at'],
            'created_at'      => (string) $promise['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    protected function presentTimelineEvent(array $event): array
    {
        return [
            'id'              => (int) $event['id'],
            'event_type'      => (string) $event['event_type'],
            'event_label'     => (string) ($event['event_meta']['label'] ?? ''),
            'tone'            => (string) ($event['event_meta']['tone'] ?? 'slate'),
            'event_at'        => (string) $event['event_at'],
            'actor_name'      => $event['actor_name'] === null ? null : (string) $event['actor_name'],
            'title'           => (string) $event['title'],
            'description'     => $event['description'] === null ? null : (string) $event['description'],
            'visit_report_id' => $event['visit_report_id'] === null ? null : (int) $event['visit_report_id'],
            'promise_id'      => $event['promise_id'] === null ? null : (int) $event['promise_id'],
            'photo_count'     => (int) ($event['photo_count'] ?? 0),
            'signature_count' => (int) ($event['signature_count'] ?? 0),
            'promise_amount'  => $event['promise_amount'] === null ? null : round((float) $event['promise_amount'], 2),
            'promise_date'    => $event['promise_date'] === null ? null : (string) $event['promise_date'],
        ];
    }

    /**
     * Media rows are returned with an API URL the app can fetch with its Bearer
     * token, never a direct filesystem path.
     *
     * @param array<string,mixed> $media
     * @return array<string,mixed>
     */
    protected function presentMedia(array $media, string $kind): array
    {
        return [
            'id'         => (int) $media['id'],
            'kind'       => $kind,
            'type'       => (string) ($media['photo_type'] ?? $media['signature_type'] ?? $media['doc_type'] ?? 'other'),
            'url'        => '/api/v1/media?f=' . rawurlencode((string) $media['file_path']),
            'title'      => $media['title'] ?? $media['original_name'] ?? null,
            'signed_name' => $media['signed_name'] ?? null,
            'created_at' => (string) $media['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    protected function presentUser(array $user): array
    {
        return [
            'id'                   => (int) $user['id'],
            'employee_code'        => (string) $user['employee_code'],
            'name'                 => (string) $user['name'],
            'email'                => $user['email'] === null ? null : (string) $user['email'],
            'mobile_masked'        => $user['mobile_masked'] === null ? null : (string) $user['mobile_masked'],
            'role'                 => (string) $user['role_slug'],
            'role_name'            => (string) ($user['role_name'] ?? ''),
            'branch_id'            => $user['branch_id'] === null ? null : (int) $user['branch_id'],
            'branch_name'          => $user['branch_name'] === null ? null : (string) $user['branch_name'],
            'branch_code'          => $user['branch_code'] === null ? null : (string) $user['branch_code'],
            'bc_code'              => $user['bc_code'] === null ? null : (string) $user['bc_code'],
            'designation'          => $user['designation'] === null ? null : (string) $user['designation'],
            'must_change_password' => (int) $user['must_change_password'] === 1,
            'permissions'          => Auth::isSuperAdmin() ? ['*'] : Auth::permissions(),
        ];
    }
}
