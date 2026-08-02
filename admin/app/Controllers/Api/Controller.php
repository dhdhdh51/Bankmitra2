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
            // The second number goes to the app under the same PII gate as the first. The
            // agent holding the phone is the one who needs a number that answers, and the
            // label with it - a call that opens "who is this?" is a call that ends there.
            'alt_mobile'          => $withPii ? ($lead['alt_mobile'] ?? null) : null,
            'alt_mobile_masked'   => ($lead['alt_mobile_masked'] ?? null) === null ? null : (string) $lead['alt_mobile_masked'],
            'alt_mobile_label'    => ($lead['alt_mobile_label'] ?? null) === null ? null : (string) $lead['alt_mobile_label'],
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

            // Section 1 of the printed form.
            'general' => [
                'visit_date'      => (string) $visit['visit_date'],
                'visit_time'      => (string) $visit['visit_time'],
                'report_type'     => (string) ($visit['report_type'] ?? 'recovery'),
                'report_type_label' => \App\Models\VisitReport::REPORT_TYPES[(string) ($visit['report_type'] ?? '')]
                    ?? null,
                'report_type_other_text' => $this->nullableText($visit['report_type_other_text'] ?? null),
                'bc_code'         => $visit['bc_code'] === null ? null : (string) $visit['bc_code'],
                'branch'          => (string) ($visit['branch_name'] ?? ''),
                'branch_code'     => $this->nullableText($visit['branch_code'] ?? null),
                'regional_office' => $this->nullableText($visit['regional_office'] ?? null),
                'zone'            => $this->nullableText($visit['zone'] ?? null),
                'linked_branch'   => $this->nullableText($visit['linked_branch'] ?? null),
                'district'        => $this->nullableText($visit['district'] ?? null),
                'sp_cbc_name'     => $this->nullableText($visit['sp_cbc_name'] ?? null),
                'agent_name'      => (string) $visit['agent_name'],
                'village'         => $visit['village'] === null ? null : (string) $visit['village'],
            ],

            // Section 2.
            'borrower' => [
                'customer_name'       => (string) $visit['customer_name'],
                'father_husband_name' => $visit['father_husband_name'] === null ? null : (string) $visit['father_husband_name'],
                'gender'              => $this->nullableText($visit['gender'] ?? null),
                'date_of_birth'       => $this->nullableText($visit['date_of_birth'] ?? null),
                'address'             => $visit['address'] === null ? null : (string) $visit['address'],
                'mobile'              => $withPii ? ($visit['mobile'] ?? null) : null,
                'mobile_masked'       => $visit['mobile_masked'] === null ? null : (string) $visit['mobile_masked'],
                'alt_mobile'          => $withPii ? ($visit['alt_mobile'] ?? null) : null,
                'alt_mobile_masked'   => $this->nullableText($visit['alt_mobile_masked'] ?? null),
                'aadhaar'             => $withPii ? ($visit['aadhaar'] ?? null) : null,
                'aadhaar_masked'      => $visit['aadhaar_masked'] === null ? null : (string) $visit['aadhaar_masked'],
                // Under the same gate as the other two identifiers: a PAN is as good a
                // key for joining a person's records together as an Aadhaar number.
                'pan'                 => $withPii ? ($visit['pan'] ?? null) : null,
                'pan_masked'          => $this->nullableText($visit['pan_masked'] ?? null),
                'addr_village'        => $this->nullableText($visit['addr_village'] ?? null),
                'gram_panchayat'      => $this->nullableText($visit['gram_panchayat'] ?? null),
                'tehsil'              => $this->nullableText($visit['tehsil'] ?? null),
                'addr_district'       => $this->nullableText($visit['addr_district'] ?? null),
                'state'               => $this->nullableText($visit['state'] ?? null),
                'pin_code'            => $this->nullableText($visit['pin_code'] ?? null),
            ],

            // Section 3.
            'loan' => [
                'loan_account_number' => (string) $visit['loan_account_number'],
                'cif_number'          => $this->nullableText($visit['cif_number'] ?? null),
                'loan_type'           => $visit['loan_type'] === null ? null : (string) $visit['loan_type'],
                'loan_type_label'     => \App\Models\VisitReport::LOAN_TYPES[(string) ($visit['loan_type'] ?? '')]
                    ?? ($visit['loan_type'] === null ? null : (string) $visit['loan_type']),
                'loan_type_other_text' => $this->nullableText($visit['loan_type_other_text'] ?? null),
                'sanction_date'       => $this->nullableText($visit['sanction_date'] ?? null),
                'sanction_limit'      => $this->nullableAmount($visit['sanction_limit'] ?? null),
                'drawing_power'       => $this->nullableAmount($visit['drawing_power'] ?? null),
                'outstanding_amount'  => round((float) $visit['outstanding_amount'], 2),
                'interest_overdue'    => $this->nullableAmount($visit['interest_overdue'] ?? null),
                'overdue_amount'      => round((float) $visit['overdue_amount'], 2),
                'npa_date'            => $visit['npa_date'] === null ? null : (string) $visit['npa_date'],
                'asset_classification' => $this->nullableText($visit['asset_classification'] ?? null),
                'asset_classification_label' =>
                    \App\Models\VisitReport::ASSET_CLASSIFICATIONS[(string) ($visit['asset_classification'] ?? '')] ?? null,
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

            // Section 6.
            'verification' => [
                'borrower_alive'        => (int) $visit['borrower_alive'] === 1,
                'same_address'          => (int) $visit['same_address'] === 1,
                'shifted'               => (int) $visit['shifted'] === 1,
                // Null rather than false when the check was not run at all: "not
                // confirmed" is an assertion, and silence is not.
                'residence_verified'     => $this->nullableText($visit['residence_verified'] ?? null),
                'neighbour_verification' => $this->nullableText($visit['neighbour_verification'] ?? null),
                'occupation'            => $visit['occupation'] === null ? null : (string) $visit['occupation'],
                'occupation_other_text' => $visit['occupation_other_text'] === null ? null : (string) $visit['occupation_other_text'],
            ],

            // Section 7.
            'documents_verified' => $this->flagStates($visit, \App\Models\VisitReport::DOCUMENT_FLAGS)
                + ['other_text' => $this->nullableText($visit['doc_other_text'] ?? null)],

            // Section 10.
            'evidence_attached' => $this->flagStates($visit, \App\Models\VisitReport::EVIDENCE_FLAGS)
                + ['other_text' => $this->nullableText($visit['ev_other_text'] ?? null)],

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
                // Section 9's free-prose box, separate from the observations below.
                'general'            => $this->nullableText($visit['general_recommendation'] ?? null),
            ],

            // Section 12. The signature lines are not here: nothing fills them but a
            // pen on the printed page.
            'certification' => [
                'agent_name'             => (string) $visit['agent_name'],
                'bc_code'                => $visit['bc_code'] === null ? null : (string) $visit['bc_code'],
                'agent_mobile'           => $this->nullableText($visit['agent_mobile'] ?? null),
                'supervisor_name'        => $this->nullableText($visit['supervisor_name'] ?? null),
                'supervisor_designation' => $this->nullableText($visit['supervisor_designation'] ?? null),
                'supervisor_employee_id' => $this->nullableText($visit['supervisor_employee_id'] ?? null),
                'supervisor_verified_at' => $this->nullableText($visit['supervisor_verified_at'] ?? null),
            ],

            // Section 11: whether the agent accepted the declaration, and the words
            // they accepted. Sent together so the app can show them above the tick box
            // without shipping its own copy that could drift out of step.
            'declaration' => [
                'accepted' => (int) ($visit['declaration_accepted'] ?? 0) === 1,
                'text'     => \App\Models\VisitReport::DECLARATION,
            ],

            // Section 8.
            'remarks'     => $visit['remarks'] === null ? null : (string) $visit['remarks'],
            'source'      => (string) $visit['source'],
            'app_version' => $visit['app_version'] === null ? null : (string) $visit['app_version'],
            'created_at'  => (string) $visit['created_at'],
        ];
    }

    /**
     * A flag group as `column => bool`, with the column prefix stripped.
     *
     * Keyed on the short name (`aadhaar`, not `doc_aadhaar`) so the app's DTO reads as
     * the section it belongs to, and so renaming a column does not change the wire
     * contract a released APK is parsing.
     *
     * @param  array<string,mixed>  $row
     * @param  array<string,string> $map   column => label
     * @return array<string,bool>
     */
    private function flagStates(array $row, array $map): array
    {
        $out = [];
        foreach (array_keys($map) as $column) {
            $short = preg_replace('/^(doc|ev|rec|st)_/', '', $column) ?? $column;
            $out[$short] = (int) ($row[$column] ?? 0) === 1;
        }
        return $out;
    }

    /** A nullable string column, normalised to null rather than "". */
    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    /** A nullable money column, rounded, or null when the field was left blank. */
    private function nullableAmount(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
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
            'type'       => (string) ($media['photo_type'] ?? $media['doc_type'] ?? 'other'),
            'url'        => '/api/v1/media?f=' . rawurlencode((string) $media['file_path']),
            'title'      => $media['title'] ?? $media['original_name'] ?? null,
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
