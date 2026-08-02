package com.lrms.recovery.data.remote

import com.google.gson.annotations.SerializedName

/**
 * Wire models for the D2 Recovery Solutions & Services REST API.
 *
 * Every endpoint answers with the same envelope, so [ApiEnvelope] is the single
 * type the networking layer unwraps. Nullable fields mirror the server contract
 * exactly - the API returns null for absent values rather than omitting keys, so
 * a missing value can never be confused with a parse failure.
 */
data class ApiEnvelope<T>(
    @SerializedName("success") val success: Boolean = false,
    @SerializedName("data") val data: T? = null,
    @SerializedName("message") val message: String = "",
    @SerializedName("meta") val meta: PageMeta? = null,
    @SerializedName("unread_count") val unreadCount: Int? = null,
    @SerializedName("status_counts") val statusCounts: Map<String, Int>? = null,
)

data class PageMeta(
    @SerializedName("current_page") val currentPage: Int = 1,
    @SerializedName("per_page") val perPage: Int = 25,
    @SerializedName("total") val total: Int = 0,
    @SerializedName("last_page") val lastPage: Int = 1,
    @SerializedName("from") val from: Int = 0,
    @SerializedName("to") val to: Int = 0,
    @SerializedName("has_more") val hasMore: Boolean = false,
)

/** Field-level validation errors from a 422 response. */
data class ValidationPayload(
    @SerializedName("errors") val errors: Map<String, List<String>>? = null,
)

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

data class LoginRequest(
    @SerializedName("employee_code") val employeeCode: String,
    @SerializedName("password") val password: String,
    @SerializedName("device_token") val deviceToken: String? = null,
    @SerializedName("app_version") val appVersion: String? = null,
)

data class RefreshRequest(
    @SerializedName("refresh_token") val refreshToken: String,
)

data class LogoutRequest(
    @SerializedName("refresh_token") val refreshToken: String?,
    @SerializedName("device_token") val deviceToken: String? = null,
)

data class ForgotPasswordRequest(
    @SerializedName("employee_code") val employeeCode: String,
)

data class ResetPasswordRequest(
    @SerializedName("employee_code") val employeeCode: String,
    @SerializedName("otp") val otp: String,
    @SerializedName("password") val password: String,
)

data class ChangePasswordRequest(
    @SerializedName("current_password") val currentPassword: String,
    @SerializedName("password") val password: String,
)

data class AuthPayload(
    @SerializedName("access_token") val accessToken: String = "",
    @SerializedName("refresh_token") val refreshToken: String = "",
    @SerializedName("token_type") val tokenType: String = "Bearer",
    @SerializedName("expires_in") val expiresIn: Long = 0,
    @SerializedName("user") val user: UserDto? = null,
    @SerializedName("app_version") val appVersion: String? = null,
    @SerializedName("min_version") val minVersion: String? = null,
)

data class MePayload(
    @SerializedName("user") val user: UserDto? = null,
    @SerializedName("app_version") val appVersion: String? = null,
    @SerializedName("min_version") val minVersion: String? = null,
)

data class OtpPayload(
    @SerializedName("otp_sent") val otpSent: Boolean = false,
    @SerializedName("contact_admin") val contactAdmin: Boolean = false,
    @SerializedName("mobile_masked") val mobileMasked: String? = null,
    @SerializedName("expires_in") val expiresIn: Long = 0,
)

data class UserDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("employee_code") val employeeCode: String = "",
    @SerializedName("name") val name: String = "",
    @SerializedName("email") val email: String? = null,
    @SerializedName("mobile_masked") val mobileMasked: String? = null,
    @SerializedName("role") val role: String = "",
    @SerializedName("role_name") val roleName: String = "",
    @SerializedName("branch_id") val branchId: Int? = null,
    @SerializedName("branch_name") val branchName: String? = null,
    @SerializedName("branch_code") val branchCode: String? = null,
    @SerializedName("bc_code") val bcCode: String? = null,
    @SerializedName("designation") val designation: String? = null,
    @SerializedName("must_change_password") val mustChangePassword: Boolean = false,
    @SerializedName("permissions") val permissions: List<String> = emptyList(),
) {
    val isAgent: Boolean get() = role == "agent"

    fun can(permission: String): Boolean =
        permissions.contains("*") || permissions.contains(permission)
}

data class PingPayload(
    @SerializedName("status") val status: String = "",
    @SerializedName("app_name") val appName: String = "D2 Recovery Solutions & Services",
    @SerializedName("bank_name") val bankName: String? = null,
    @SerializedName("app_version") val appVersion: String = "",
    @SerializedName("min_version") val minVersion: String = "",
    @SerializedName("api_version") val apiVersion: String = "",
)

data class DeviceTokenRequest(
    @SerializedName("device_token") val deviceToken: String,
    @SerializedName("app_version") val appVersion: String,
)

// ---------------------------------------------------------------------------
// Leads & customers
// ---------------------------------------------------------------------------

data class LeadDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("loan_account_number") val loanAccountNumber: String = "",
    @SerializedName("customer_id") val customerId: Int = 0,
    @SerializedName("customer_name") val customerName: String = "",
    @SerializedName("father_husband_name") val fatherHusbandName: String? = null,
    @SerializedName("village") val village: String? = null,
    @SerializedName("address") val address: String? = null,
    @SerializedName("mobile") val mobile: String? = null,
    @SerializedName("mobile_masked") val mobileMasked: String? = null,
    @SerializedName("aadhaar") val aadhaar: String? = null,
    @SerializedName("aadhaar_masked") val aadhaarMasked: String? = null,
    @SerializedName("bc_code") val bcCode: String? = null,
    @SerializedName("loan_type") val loanType: String? = null,
    @SerializedName("outstanding_amount") val outstandingAmount: Double = 0.0,
    @SerializedName("overdue_amount") val overdueAmount: Double = 0.0,
    @SerializedName("npa_date") val npaDate: String? = null,
    @SerializedName("is_npa") val isNpa: Boolean = false,
    @SerializedName("current_status") val currentStatus: String = "pending",
    @SerializedName("branch_id") val branchId: Int = 0,
    @SerializedName("branch_name") val branchName: String = "",
    @SerializedName("branch_code") val branchCode: String? = null,
    @SerializedName("assigned_agent_id") val assignedAgentId: Int? = null,
    @SerializedName("agent_name") val agentName: String? = null,
    @SerializedName("visit_count") val visitCount: Int = 0,
    @SerializedName("last_visit_at") val lastVisitAt: String? = null,
    @SerializedName("next_followup_date") val nextFollowupDate: String? = null,
    @SerializedName("remarks") val remarks: String? = null,
    @SerializedName("created_at") val createdAt: String = "",
)

data class CustomerProfilePayload(
    @SerializedName("lead") val lead: LeadDto? = null,
    @SerializedName("promises") val promises: List<PromiseDto> = emptyList(),
    @SerializedName("visits") val visits: List<VisitSummaryDto> = emptyList(),
    @SerializedName("timeline") val timeline: List<TimelineEventDto> = emptyList(),
    @SerializedName("photos") val photos: List<MediaDto> = emptyList(),
    @SerializedName("documents") val documents: List<MediaDto> = emptyList(),
    @SerializedName("other_accounts") val otherAccounts: List<OtherAccountDto> = emptyList(),
)

data class OtherAccountDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("loan_account_number") val loanAccountNumber: String = "",
    @SerializedName("loan_type") val loanType: String? = null,
    @SerializedName("outstanding_amount") val outstandingAmount: Double = 0.0,
    @SerializedName("current_status") val currentStatus: String = "",
)

data class HistoryPayload(
    @SerializedName("loan_account_number") val loanAccountNumber: String = "",
    @SerializedName("customer_name") val customerName: String = "",
    @SerializedName("visit_count") val visitCount: Int = 0,
    @SerializedName("timeline") val timeline: List<TimelineEventDto> = emptyList(),
    @SerializedName("visits") val visits: List<VisitSummaryDto> = emptyList(),
)

data class TimelineEventDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("event_type") val eventType: String = "",
    @SerializedName("event_label") val eventLabel: String = "",
    @SerializedName("tone") val tone: String = "slate",
    @SerializedName("event_at") val eventAt: String = "",
    @SerializedName("actor_name") val actorName: String? = null,
    @SerializedName("title") val title: String = "",
    @SerializedName("description") val description: String? = null,
    @SerializedName("visit_report_id") val visitReportId: Int? = null,
    @SerializedName("promise_id") val promiseId: Int? = null,
    @SerializedName("photo_count") val photoCount: Int = 0,
    @SerializedName("promise_amount") val promiseAmount: Double? = null,
    @SerializedName("promise_date") val promiseDate: String? = null,
)

data class PromiseDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("loan_account_id") val loanAccountId: Int = 0,
    @SerializedName("loan_account_number") val loanAccountNumber: String? = null,
    @SerializedName("customer_name") val customerName: String? = null,
    @SerializedName("village") val village: String? = null,
    @SerializedName("promise_amount") val promiseAmount: Double = 0.0,
    @SerializedName("promise_date") val promiseDate: String = "",
    @SerializedName("status") val status: String = "pending",
    @SerializedName("agent_name") val agentName: String = "",
    @SerializedName("notes") val notes: String? = null,
    @SerializedName("settled_at") val settledAt: String? = null,
    @SerializedName("days_overdue") val daysOverdue: Int = 0,
    @SerializedName("created_at") val createdAt: String = "",
)

data class MediaDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("kind") val kind: String = "",
    @SerializedName("type") val type: String = "",
    @SerializedName("url") val url: String = "",
    @SerializedName("title") val title: String? = null,
    @SerializedName("signed_name") val signedName: String? = null,
    @SerializedName("created_at") val createdAt: String = "",
)

// ---------------------------------------------------------------------------
// Visits
// ---------------------------------------------------------------------------

data class VisitSummaryDto(
    @SerializedName("id") val id: Int = 0,
    /** recovery | ots | ckcc_renewal - lets a list label the kind of report. */
    @SerializedName("report_type") val reportType: String = "recovery",
    @SerializedName("visit_date") val visitDate: String = "",
    @SerializedName("visit_time") val visitTime: String = "",
    @SerializedName("agent_name") val agentName: String = "",
    @SerializedName("village") val village: String? = null,
    @SerializedName("customer_met") val customerMet: Boolean = false,
    @SerializedName("family_member_met") val familyMemberMet: Boolean = false,
    @SerializedName("house_locked") val houseLocked: Boolean = false,
    @SerializedName("phone_contact") val phoneContact: Boolean = false,
    @SerializedName("phone_switched_off") val phoneSwitchedOff: Boolean = false,
    @SerializedName("ready_to_pay") val readyToPay: Boolean = false,
    @SerializedName("not_ready") val notReady: Boolean = false,
    @SerializedName("promise_amount") val promiseAmount: Double? = null,
    @SerializedName("promise_date") val promiseDate: String? = null,
    @SerializedName("outstanding_amount") val outstandingAmount: Double = 0.0,
    @SerializedName("overdue_amount") val overdueAmount: Double = 0.0,
    @SerializedName("remarks") val remarks: String? = null,
    @SerializedName("photo_count") val photoCount: Int = 0,
    @SerializedName("document_count") val documentCount: Int = 0,
    @SerializedName("created_at") val createdAt: String = "",
)

data class VisitSubmitPayload(
    @SerializedName("visit_id") val visitId: Int = 0,
    @SerializedName("promise_id") val promiseId: Int? = null,
    @SerializedName("duplicate") val duplicate: Boolean = false,
    @SerializedName("media") val media: Map<String, Int>? = null,
    @SerializedName("warnings") val warnings: List<String> = emptyList(),
    @SerializedName("lead") val lead: LeadDto? = null,
)

data class VisitDetailPayload(
    @SerializedName("report") val report: VisitReportDto? = null,
    @SerializedName("photos") val photos: List<MediaDto> = emptyList(),
    @SerializedName("documents") val documents: List<MediaDto> = emptyList(),
)

data class VisitReportDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("loan_account_id") val loanAccountId: Int = 0,
    @SerializedName("general") val general: VisitGeneralDto? = null,
    @SerializedName("borrower") val borrower: VisitBorrowerDto? = null,
    @SerializedName("loan") val loan: VisitLoanDto? = null,
    @SerializedName("contact") val contact: VisitContactDto? = null,
    @SerializedName("verification") val verification: VisitVerificationDto? = null,
    @SerializedName("recovery") val recovery: VisitRecoveryDto? = null,
    @SerializedName("non_payment_reason") val nonPaymentReason: VisitReasonDto? = null,
    @SerializedName("recommendation") val recommendation: VisitRecommendationDto? = null,
    /** Section 7 of the printed form, keyed on the short document name. */
    @SerializedName("documents_verified") val documentsVerified: Map<String, Any>? = null,
    /** Section 10, and deliberately separate from [photos]: this is what was CLAIMED. */
    @SerializedName("evidence_attached") val evidenceAttached: Map<String, Any>? = null,
    @SerializedName("certification") val certification: VisitCertificationDto? = null,
    @SerializedName("declaration") val declaration: VisitDeclarationDto? = null,
    @SerializedName("remarks") val remarks: String? = null,
    @SerializedName("source") val source: String = "",
    @SerializedName("app_version") val appVersion: String? = null,
    @SerializedName("created_at") val createdAt: String = "",
)

data class VisitGeneralDto(
    @SerializedName("visit_date") val visitDate: String = "",
    @SerializedName("visit_time") val visitTime: String = "",
    @SerializedName("report_type") val reportType: String = "",
    @SerializedName("report_type_label") val reportTypeLabel: String? = null,
    @SerializedName("report_type_other_text") val reportTypeOtherText: String? = null,
    @SerializedName("bc_code") val bcCode: String? = null,
    @SerializedName("branch") val branch: String = "",
    // Stamped from the branch master rather than asked of the agent, so the printed
    // header does not carry four spellings of the same regional office.
    @SerializedName("branch_code") val branchCode: String? = null,
    @SerializedName("regional_office") val regionalOffice: String? = null,
    @SerializedName("zone") val zone: String? = null,
    @SerializedName("linked_branch") val linkedBranch: String? = null,
    @SerializedName("district") val district: String? = null,
    @SerializedName("sp_cbc_name") val spCbcName: String? = null,
    @SerializedName("agent_name") val agentName: String = "",
    @SerializedName("village") val village: String? = null,
)

data class VisitBorrowerDto(
    @SerializedName("customer_name") val customerName: String = "",
    @SerializedName("father_husband_name") val fatherHusbandName: String? = null,
    @SerializedName("gender") val gender: String? = null,
    @SerializedName("date_of_birth") val dateOfBirth: String? = null,
    @SerializedName("address") val address: String? = null,
    @SerializedName("mobile") val mobile: String? = null,
    @SerializedName("mobile_masked") val mobileMasked: String? = null,
    @SerializedName("alt_mobile") val altMobile: String? = null,
    @SerializedName("alt_mobile_masked") val altMobileMasked: String? = null,
    @SerializedName("aadhaar") val aadhaar: String? = null,
    @SerializedName("aadhaar_masked") val aadhaarMasked: String? = null,
    // Under the same PII gate as the other two identifiers: a PAN is as good a key for
    // joining a person's records together as an Aadhaar number.
    @SerializedName("pan") val pan: String? = null,
    @SerializedName("pan_masked") val panMasked: String? = null,
    @SerializedName("addr_village") val addrVillage: String? = null,
    @SerializedName("gram_panchayat") val gramPanchayat: String? = null,
    @SerializedName("tehsil") val tehsil: String? = null,
    @SerializedName("addr_district") val addrDistrict: String? = null,
    @SerializedName("state") val state: String? = null,
    @SerializedName("pin_code") val pinCode: String? = null,
)

data class VisitLoanDto(
    @SerializedName("loan_account_number") val loanAccountNumber: String = "",
    @SerializedName("cif_number") val cifNumber: String? = null,
    @SerializedName("loan_type") val loanType: String? = null,
    @SerializedName("loan_type_label") val loanTypeLabel: String? = null,
    @SerializedName("loan_type_other_text") val loanTypeOtherText: String? = null,
    @SerializedName("sanction_date") val sanctionDate: String? = null,
    @SerializedName("sanction_limit") val sanctionLimit: Double? = null,
    @SerializedName("drawing_power") val drawingPower: Double? = null,
    @SerializedName("outstanding_amount") val outstandingAmount: Double = 0.0,
    @SerializedName("interest_overdue") val interestOverdue: Double? = null,
    @SerializedName("overdue_amount") val overdueAmount: Double = 0.0,
    @SerializedName("npa_date") val npaDate: String? = null,
    @SerializedName("asset_classification") val assetClassification: String? = null,
    @SerializedName("asset_classification_label") val assetClassificationLabel: String? = null,
    @SerializedName("current_status") val currentStatus: String? = null,
)

data class VisitContactDto(
    @SerializedName("customer_met") val customerMet: Boolean = false,
    @SerializedName("family_member_met") val familyMemberMet: Boolean = false,
    @SerializedName("house_locked") val houseLocked: Boolean = false,
    @SerializedName("phone_contact") val phoneContact: Boolean = false,
    @SerializedName("phone_switched_off") val phoneSwitchedOff: Boolean = false,
    @SerializedName("family_member_name") val familyMemberName: String? = null,
    @SerializedName("family_member_relationship") val familyMemberRelationship: String? = null,
)

data class VisitVerificationDto(
    @SerializedName("borrower_alive") val borrowerAlive: Boolean = true,
    @SerializedName("same_address") val sameAddress: Boolean = true,
    @SerializedName("shifted") val shifted: Boolean = false,
    /**
     * Null when the check was never run, which is NOT the same as failing it.
     *
     * Typed as a nullable String rather than a Boolean for exactly that reason: a
     * Boolean has no third state, and Gson would deserialise a missing key as false -
     * turning "nobody asked the neighbours" into "the neighbours were not asked and
     * that is recorded as a negative finding".
     */
    @SerializedName("residence_verified") val residenceVerified: String? = null,
    @SerializedName("neighbour_verification") val neighbourVerification: String? = null,
    @SerializedName("occupation") val occupation: String? = null,
    @SerializedName("occupation_other_text") val occupationOtherText: String? = null,
)

data class VisitRecoveryDto(
    @SerializedName("ready_to_pay") val readyToPay: Boolean = false,
    @SerializedName("not_ready") val notReady: Boolean = false,
    @SerializedName("interest_payment") val interestPayment: Boolean = false,
    @SerializedName("ots") val ots: Boolean = false,
    @SerializedName("promise_amount") val promiseAmount: Double? = null,
    @SerializedName("promise_date") val promiseDate: String? = null,
)

data class VisitReasonDto(
    @SerializedName("financial_problem") val financialProblem: Boolean = false,
    @SerializedName("crop_loss") val cropLoss: Boolean = false,
    @SerializedName("animal_loss") val animalLoss: Boolean = false,
    @SerializedName("illness") val illness: Boolean = false,
    @SerializedName("unemployment") val unemployment: Boolean = false,
    @SerializedName("dispute") val dispute: Boolean = false,
    @SerializedName("other_loan") val otherLoan: Boolean = false,
    @SerializedName("others") val others: Boolean = false,
    @SerializedName("other_text") val otherText: String? = null,
)

data class VisitRecommendationDto(
    @SerializedName("recovery_possible") val recoveryPossible: Boolean = false,
    @SerializedName("regular_followup") val regularFollowup: Boolean = false,
    @SerializedName("legal_action") val legalAction: Boolean = false,
    @SerializedName("rc") val rc: Boolean = false,
    @SerializedName("ots") val ots: Boolean = false,
    @SerializedName("others") val others: Boolean = false,
    @SerializedName("other_text") val otherText: String? = null,
    /** Section 9's free-prose box, separate from the observations. */
    @SerializedName("general") val general: String? = null,
)

/** Section 12. The signature lines are not here: nothing fills them but a pen. */
data class VisitCertificationDto(
    @SerializedName("agent_name") val agentName: String = "",
    @SerializedName("bc_code") val bcCode: String? = null,
    @SerializedName("agent_mobile") val agentMobile: String? = null,
    @SerializedName("supervisor_name") val supervisorName: String? = null,
    @SerializedName("supervisor_designation") val supervisorDesignation: String? = null,
    @SerializedName("supervisor_employee_id") val supervisorEmployeeId: String? = null,
    @SerializedName("supervisor_verified_at") val supervisorVerifiedAt: String? = null,
)

/**
 * Section 11: whether the declaration was accepted, and the words that were accepted.
 *
 * The text travels with the flag rather than being compiled into the APK, so the
 * wording an agent agrees to and the wording printed on the page cannot drift apart.
 */
data class VisitDeclarationDto(
    @SerializedName("accepted") val accepted: Boolean = false,
    @SerializedName("text") val text: List<String> = emptyList(),
)

// ---------------------------------------------------------------------------
// Notifications, meta, dashboard
// ---------------------------------------------------------------------------

data class NotificationDto(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("type") val type: String = "",
    @SerializedName("title") val title: String = "",
    @SerializedName("body") val body: String? = null,
    @SerializedName("is_read") val isRead: Boolean = false,
    @SerializedName("loan_account_id") val loanAccountId: Int? = null,
    @SerializedName("loan_account_number") val loanAccountNumber: String? = null,
    @SerializedName("created_at") val createdAt: String = "",
)

data class UnreadPayload(
    @SerializedName("unread_count") val unreadCount: Int = 0,
    @SerializedName("marked") val marked: Int = 0,
)

data class MetaPayload(
    @SerializedName("villages") val villages: List<String> = emptyList(),
    @SerializedName("loan_types") val loanTypes: List<String> = emptyList(),
    @SerializedName("statuses") val statuses: List<String> = emptyList(),
    @SerializedName("app_version") val appVersion: String? = null,
    @SerializedName("min_version") val minVersion: String? = null,
    /** The bank's daily report deadline, `HH:mm`. Drives the on-device alarm. */
    @SerializedName("report_due_time") val reportDueTime: String? = null,
    /** The bank's master switch for the reminder. There is no agent-side one. */
    @SerializedName("report_reminder") val reportReminder: Boolean = true,
    /** How often the alarm re-fires until the report is in. 0 = one reminder only. */
    @SerializedName("report_reminder_repeat_minutes") val reportReminderRepeatMinutes: Int = 15,
    /** The hour repeats stop at, so the phone is quiet overnight. */
    @SerializedName("report_reminder_until_hour") val reportReminderUntilHour: Int = 22,
)

data class AgentDashboardPayload(
    @SerializedName("leads") val leads: AgentLeadCounters? = null,
    @SerializedName("visits") val visits: AgentVisitCounters? = null,
    @SerializedName("promises") val promises: AgentPromiseCounters? = null,
)

data class AgentLeadCounters(
    @SerializedName("total") val total: Int = 0,
    @SerializedName("pending") val pending: Int = 0,
    @SerializedName("visited") val visited: Int = 0,
    @SerializedName("promise_cases") val promiseCases: Int = 0,
    @SerializedName("followup") val followup: Int = 0,
    @SerializedName("closed") val closed: Int = 0,
    @SerializedName("npa_cases") val npaCases: Int = 0,
    @SerializedName("outstanding") val outstanding: Double = 0.0,
    @SerializedName("overdue") val overdue: Double = 0.0,
)

data class AgentVisitCounters(
    @SerializedName("total") val total: Int = 0,
    @SerializedName("today") val today: Int = 0,
    @SerializedName("week") val week: Int = 0,
    @SerializedName("month") val month: Int = 0,
)

data class AgentPromiseCounters(
    @SerializedName("pending") val pending: Int = 0,
    @SerializedName("kept") val kept: Int = 0,
    @SerializedName("broken") val broken: Int = 0,
    @SerializedName("overdue") val overdue: Int = 0,
    @SerializedName("due_today") val dueToday: Int = 0,
)

data class FormOptionsPayload(
    @SerializedName("occupations") val occupations: List<OptionDto> = emptyList(),
    /**
     * The three field report types. The app also hard-codes them so the visit
     * form works with no network round trip, but they are read here too so a
     * server-side rename shows up as a failing contract test rather than as a
     * dropdown that silently posts an unknown value.
     */
    @SerializedName("report_types") val reportTypes: List<OptionDto> = emptyList(),
    @SerializedName("ots") val ots: OtsOptions? = null,
    @SerializedName("ckcc") val ckcc: CkccOptions? = null,
    @SerializedName("contact_flags") val contactFlags: List<FlagDto> = emptyList(),
    @SerializedName("recovery_flags") val recoveryFlags: List<FlagDto> = emptyList(),
    @SerializedName("reason_flags") val reasonFlags: List<FlagDto> = emptyList(),
    @SerializedName("recommendation_flags") val recommendationFlags: List<FlagDto> = emptyList(),
    @SerializedName("genders") val genders: List<OptionDto> = emptyList(),
    @SerializedName("loan_types") val loanTypes: List<OptionDto> = emptyList(),
    @SerializedName("asset_classifications") val assetClassifications: List<OptionDto> = emptyList(),
    @SerializedName("residence_verification") val residenceVerification: List<OptionDto> = emptyList(),
    @SerializedName("neighbour_verification") val neighbourVerification: List<OptionDto> = emptyList(),
    /**
     * Sections 7 and 10, asked on every case type.
     *
     * `document_flags` used to sit inside the `ckcc` block, which meant a recovery visit
     * was never offered the checklist at all.
     */
    @SerializedName("document_flags") val documentFlags: List<FlagDto> = emptyList(),
    @SerializedName("evidence_flags") val evidenceFlags: List<FlagDto> = emptyList(),
    /** Section 11, sent rather than compiled in so the two copies cannot drift. */
    @SerializedName("declaration") val declaration: List<String> = emptyList(),
    @SerializedName("important_note") val importantNote: String? = null,
)

data class OtsOptions(
    @SerializedName("schemes") val schemes: List<OptionDto> = emptyList(),
    @SerializedName("approval_statuses") val approvalStatuses: List<OptionDto> = emptyList(),
    /** Section 4's Customer Response row: why, not just whether. */
    @SerializedName("customer_responses") val customerResponses: List<OptionDto> = emptyList(),
    @SerializedName("recommendation_flags") val recommendationFlags: List<FlagDto> = emptyList(),
    @SerializedName("status_flags") val statusFlags: List<FlagDto> = emptyList(),
    /** Scheme defaults the form pre-fills; the agent can override both. */
    @SerializedName("default_payable_percent") val defaultPayablePercent: Double = 22.50,
    @SerializedName("default_initial_deposit_percent") val defaultDepositPercent: Double = 10.00,
)

data class CkccOptions(
    @SerializedName("due_buckets") val dueBuckets: List<OptionDto> = emptyList(),
    @SerializedName("kyc_statuses") val kycStatuses: List<OptionDto> = emptyList(),
    @SerializedName("eligibility_flags") val eligibilityFlags: List<FlagDto> = emptyList(),
    // No document_flags here any more: the checklist is on FormOptionsPayload, asked
    // once for every case type. Two copies let one report answer it twice.
    @SerializedName("consent_flags") val consentFlags: List<FlagDto> = emptyList(),
    @SerializedName("recommendation_flags") val recommendationFlags: List<FlagDto> = emptyList(),
    @SerializedName("status_flags") val statusFlags: List<FlagDto> = emptyList(),
)

data class OptionDto(
    @SerializedName("value") val value: String = "",
    @SerializedName("label") val label: String = "",
)

data class FlagDto(
    @SerializedName("key") val key: String = "",
    @SerializedName("label") val label: String = "",
)

// ---------------------------------------------------------------------------
// Location notice and consent
// ---------------------------------------------------------------------------

/**
 * The notice text plus whether THIS agent has acknowledged THIS version.
 *
 * version matters: the server refuses an acknowledgement quoting an older version,
 * so changing the notice forces the app to show it again rather than carrying the
 * old consent forward over new collection.
 */
/**
 * The agent's own SSS figures for a day, plus what the system counted for them.
 *
 * [today] is deliberately part of the same payload: visits, contacts and PTP are
 * counted from the reports already filed and are never sent by the app, so showing
 * them next to the four fields the agent does type is what stops "how many visits am
 * I credited with today" being a guess.
 */
data class SssDayPayload(
    @SerializedName("date") val date: String = "",
    @SerializedName("editable") val editable: Boolean = false,
    @SerializedName("recorded") val recorded: Boolean = false,
    @SerializedName("apy") val apy: Int = 0,
    @SerializedName("pmjjby") val pmjjby: Int = 0,
    @SerializedName("pmsby") val pmsby: Int = 0,
    @SerializedName("pmjdy") val pmjdy: Int = 0,
    @SerializedName("remarks") val remarks: String? = null,
    @SerializedName("month") val month: SssMonthTotals? = null,
    @SerializedName("today") val today: SssCountedFigures? = null,
)

data class SssMonthTotals(
    @SerializedName("apy") val apy: Int = 0,
    @SerializedName("pmjjby") val pmjjby: Int = 0,
    @SerializedName("pmsby") val pmsby: Int = 0,
    @SerializedName("pmjdy") val pmjdy: Int = 0,
    @SerializedName("total") val total: Int = 0,
    @SerializedName("days") val days: Int = 0,
)

/** Counted by the server from filed reports. Never sent up by the app. */
data class SssCountedFigures(
    @SerializedName("visits") val visits: Int = 0,
    @SerializedName("contacts") val contacts: Int = 0,
    @SerializedName("ptp") val ptp: Int = 0,
    @SerializedName("od2_renewal") val od2Renewal: Int = 0,
    @SerializedName("npa_recovery") val npaRecovery: Double = 0.0,
    @SerializedName("sss_total") val sssTotal: Int = 0,
)

data class SssEntryRequest(
    @SerializedName("date") val date: String? = null,
    @SerializedName("apy_count") val apyCount: Int,
    @SerializedName("pmjjby_count") val pmjjbyCount: Int,
    @SerializedName("pmsby_count") val pmsbyCount: Int,
    @SerializedName("pmjdy_count") val pmjdyCount: Int,
    @SerializedName("remarks") val remarks: String? = null,
)

data class SssSavePayload(
    @SerializedName("id") val id: Int = 0,
    @SerializedName("date") val date: String = "",
    @SerializedName("total") val total: Int = 0,
    @SerializedName("today") val today: SssCountedFigures? = null,
)

data class LocationNoticePayload(
    @SerializedName("version") val version: String = "",
    @SerializedName("english") val english: String = "",
    @SerializedName("hindi") val hindi: String = "",
    @SerializedName("retention_days") val retentionDays: Int = 0,
    @SerializedName("acknowledged") val acknowledged: Boolean = false,
    @SerializedName("tracking_allowed") val trackingAllowed: Boolean = false,
)

data class LocationConsentRequest(
    @SerializedName("notice_version") val noticeVersion: String,
    @SerializedName("device_info") val deviceInfo: String? = null,
)

data class LocationPointDto(
    @SerializedName("latitude") val latitude: Double,
    @SerializedName("longitude") val longitude: Double,
    @SerializedName("accuracy_m") val accuracyMetres: Int? = null,
    @SerializedName("logged_at") val loggedAt: String? = null,
    @SerializedName("on_duty") val onDuty: Boolean = true,
)

data class LocationBatchRequest(
    @SerializedName("points") val points: List<LocationPointDto>,
)

data class LocationUploadPayload(
    @SerializedName("stored") val stored: Int = 0,
    /** Accepted then discarded as too close to the previous fix - treat as delivered. */
    @SerializedName("dropped") val dropped: Int = 0,
)
