package com.lrms.recovery.domain

import java.io.File
import java.util.UUID

/**
 * The Digital BC Field Visit Report as the app collects it.
 *
 * Field names map one-to-one onto the API contract, so [toFieldMap] is the single
 * place the wire format is defined. The class is a plain data holder and is
 * covered by unit tests - it deliberately has no Android dependencies.
 *
 * [clientUuid] is generated once when the form is opened. The server treats it as
 * an idempotency key, so a retry after a dropped connection returns the original
 * report instead of filing a duplicate visit.
 */
data class VisitFormData(
    val loanAccountId: Int,

    /**
     * Which of the three field reports this is. Chosen from a dropdown at the top
     * of the form; the extra sections appear only for the type selected, so a
     * plain recovery call is not buried under sixty settlement fields.
     */
    var reportType: String = REPORT_RECOVERY,

    // ---- General -----------------------------------------------------------
    var visitDate: String = "",
    var visitTime: String = "",
    var village: String = "",

    // ---- Customer contact --------------------------------------------------
    var customerMet: Boolean = false,
    var familyMemberMet: Boolean = false,
    var houseLocked: Boolean = false,
    var phoneContact: Boolean = false,
    var phoneSwitchedOff: Boolean = false,
    var familyMemberName: String = "",
    var familyMemberRelationship: String = "",

    // ---- Physical verification ---------------------------------------------
    var borrowerAlive: Boolean = true,
    var sameAddress: Boolean = true,
    var shifted: Boolean = false,
    var occupation: String = "",
    var occupationOtherText: String = "",

    // ---- Recovery possibility ----------------------------------------------
    var readyToPay: Boolean = false,
    var notReady: Boolean = false,
    var interestPayment: Boolean = false,
    var ots: Boolean = false,
    var promiseAmount: String = "",
    var promiseDate: String = "",

    // ---- Non-payment reason ------------------------------------------------
    var reasonFinancialProblem: Boolean = false,
    var reasonCropLoss: Boolean = false,
    var reasonAnimalLoss: Boolean = false,
    var reasonIllness: Boolean = false,
    var reasonUnemployment: Boolean = false,
    var reasonDispute: Boolean = false,
    var reasonOtherLoan: Boolean = false,
    var reasonOthers: Boolean = false,
    var reasonOtherText: String = "",

    // ---- Agent recommendation ----------------------------------------------
    var recRecoveryPossible: Boolean = false,
    var recRegularFollowup: Boolean = false,
    var recLegalAction: Boolean = false,
    var recRc: Boolean = false,
    var recOts: Boolean = false,
    var recOthers: Boolean = false,
    var recOtherText: String = "",

    // ---- Remarks -----------------------------------------------------------
    var remarks: String = "",

    // ---- Documents ---------------------------------------------------------
    var customerPhoto: File? = null,
    var housePhoto: File? = null,
    var aadhaarPhoto: File? = null,
    /**
     * The agent's own photograph, taken at the door.
     *
     * Not the portrait on their user record: that one was uploaded once in a branch
     * office and proves only that they have a face. This one carries the fix that says
     * where they were standing, which is what a disputed visit actually turns on.
     */
    var agentPhoto: File? = null,
    var otherDocuments: MutableList<File> = mutableListOf(),

    // ---- KRM / OTS settlement (report_type = ots) ---------------------------
    var otsEligible: Boolean = false,
    var otsScheme: String = "",
    var otsReliefPercent: String = "",
    var otsRlbAmount: String = "",
    var otsPayablePercent: String = DEFAULT_PAYABLE_PERCENT,
    var otsPayableAmount: String = "",
    var otsTotalSettlement: String = "",
    var otsDepositPercent: String = DEFAULT_DEPOSIT_PERCENT,
    var otsRequiredDeposit: String = "",
    var otsDepositReceived: Boolean = false,
    var otsDepositAmount: String = "",
    var otsDepositDate: String = "",
    var otsDepositReference: String = "",
    var otsBalancePayable: String = "",
    var otsFinalPaymentDate: String = "",
    var otsApprovalStatus: String = OTS_STATUS_PENDING,
    var otsValidityFrom: String = "",
    var otsValidityTo: String = "",
    var otsExpectedClosureDate: String = "",
    var otsBorrowerAccepted: Boolean = false,
    var otsRejectionReason: String = "",

    // ---- CKCC OD-2 renewal (report_type = ckcc_renewal) ---------------------
    var ckccCifNumber: String = "",
    var ckccSanctionDate: String = "",
    var ckccSanctionLimit: String = "",
    var ckccDrawingPower: String = "",
    var ckccOutstanding: String = "",
    var ckccInterestOverdue: String = "",
    var ckccRenewalDueDate: String = "",
    var ckccEligibleForRenewal: Boolean = false,
    var ckccKycComplete: Boolean = false,
    var ckccAadhaarSeeded: Boolean = false,
    var ckccMobileLinked: Boolean = false,
    var ckccAadhaarAuthCompleted: Boolean = false,
    var ckccDocAadhaar: Boolean = false,
    var ckccDocPan: Boolean = false,
    var ckccDocPassbook: Boolean = false,
    var ckccDocLandRecord: Boolean = false,
    var ckccDocKhasraKhatauni: Boolean = false,
    var ckccDocPhotograph: Boolean = false,
    var ckccDocMobileAvailable: Boolean = false,
    var ckccDocOthers: Boolean = false,
    var ckccDocOtherText: String = "",
    var ckccWillingToRenew: Boolean = false,
    var ckccDocumentsHandedOver: Boolean = false,
    var ckccRenewalFormSigned: Boolean = false,
    var ckccEkycCompleted: Boolean = false,
    var ckccBiometricsCompleted: Boolean = false,
    var ckccObservation: String = "",
    var ckccRecRenewImmediately: Boolean = false,
    var ckccRecDocumentsSubmitted: Boolean = false,
    var ckccRecFollowupRequired: Boolean = false,
    var ckccRecNotInterested: Boolean = false,
    var ckccRecBranchContactUrgent: Boolean = false,
    var ckccRecOthers: Boolean = false,
    var ckccRecOtherText: String = "",
    var ckccStCustomerContacted: Boolean = false,
    var ckccStCustomerVerified: Boolean = false,
    var ckccStDocumentsCollected: Boolean = false,
    var ckccStApplicationSubmitted: Boolean = false,
    var ckccStCkccRenewed: Boolean = false,
    var ckccStPendingAtBranch: Boolean = false,
    var ckccStFollowupRequired: Boolean = false,
    var ckccStBecameNpa: Boolean = false,

    // ---- Declaration -------------------------------------------------------
    var spCbcName: String = "",

    // ---- Extra evidence (CKCC) ---------------------------------------------
    var landPhoto: File? = null,
    var passbookPhoto: File? = null,
    var renewalFormPhoto: File? = null,

    // ---- Where the report was filed ----------------------------------------
    /**
     * The agent's position when the report was completed, and per-photo positions
     * for the slots the camera filled.
     *
     * [gpsSource] carries `device`, `denied` or `unavailable` and is always sent,
     * because "we asked and were refused" and "there was no signal" are different
     * facts and the server records them differently. Sending nothing at all would
     * leave the report unable to distinguish either from an older app that never
     * collected a position.
     *
     * The photo stamps are separate from the report's own position on purpose: a
     * gallery-picked image has none, and inheriting the report's would assert that
     * an unknown photo was taken at the doorstep.
     */
    var gpsLatitude: Double? = null,
    var gpsLongitude: Double? = null,
    var gpsAccuracyMetres: Int? = null,
    var gpsCapturedAt: String = "",
    var gpsSource: String = "unavailable",

    /** Slot name (`customer`, `house`, `aadhaar`) to "lat,lng,accuracyOrBlank". */
    var photoStamps: MutableMap<String, String> = mutableMapOf(),

    /**
     * Slot name to "camera" or "gallery".
     *
     * Sent for every attached photograph, independent of whether it has coordinates.
     * The server stores it as photos.capture_source, which is what lets a printed
     * report distinguish a doorstep photograph from a gallery pick instead of
     * labelling every image "unknown".
     */
    var photoSources: MutableMap<String, String> = mutableMapOf(),

    // ---- Meta --------------------------------------------------------------
    val clientUuid: String = UUID.randomUUID().toString(),
    var appVersion: String = "",
    var deviceInfo: String = "",
) {

    /**
     * Validates the report before submission.
     *
     * @return field name to message. Empty when the form is ready to send.
     */
    fun validate(): Map<String, String> {
        val errors = mutableMapOf<String, String>()

        if (visitDate.isBlank()) {
            errors["visit_date"] = "Select the visit date"
        }
        if (visitTime.isBlank()) {
            errors["visit_time"] = "Select the visit time"
        }

        // At least one contact outcome must be recorded, otherwise the report
        // says nothing about what happened at the door.
        val anyContact = customerMet || familyMemberMet || houseLocked ||
            phoneContact || phoneSwitchedOff
        if (!anyContact) {
            errors["contact"] = "Select how the visit went"
        }

        if (familyMemberMet && familyMemberName.isBlank()) {
            errors["family_member_name"] = "Enter the family member's name"
        }

        // A promise is only a promise with both an amount and a date. Half a
        // promise would silently fail to create a promise case on the server.
        val amount = promiseAmountValue()
        val hasAmount = amount != null && amount > 0.0
        val hasDate = promiseDate.isNotBlank()

        if (hasAmount && !hasDate) {
            errors["promise_date"] = "Select the promised payment date"
        }
        if (hasDate && !hasAmount) {
            errors["promise_amount"] = "Enter the promised amount"
        }
        if (promiseAmount.isNotBlank() && amount == null) {
            errors["promise_amount"] = "Enter a valid amount"
        }

        if (reasonOthers && reasonOtherText.isBlank()) {
            errors["reason_other_text"] = "Describe the reason"
        }
        if (recOthers && recOtherText.isBlank()) {
            errors["rec_other_text"] = "Describe the recommendation"
        }
        if (occupation == OCCUPATION_OTHERS && occupationOtherText.isBlank()) {
            errors["occupation_other_text"] = "Specify the occupation"
        }

        errors += validateOts()
        errors += validateCkcc()

        return errors
    }

    /**
     * Settlement rules.
     *
     * Deliberately narrow: the branch's sanction letter is the authority, so this
     * refuses only combinations that are self-contradictory or that would record a
     * deposit nobody can trace. It never rejects a figure for failing to match our
     * own arithmetic.
     */
    private fun validateOts(): Map<String, String> {
        if (reportType != REPORT_OTS) return emptyMap()
        val errors = mutableMapOf<String, String>()

        if (otsEligible && otsScheme.isBlank()) {
            errors["ots_scheme"] = "Select the applicable scheme"
        }
        for ((key, raw) in listOf(
            "ots_relief_percent" to otsReliefPercent,
            "ots_payable_percent" to otsPayablePercent,
            "ots_deposit_percent" to otsDepositPercent,
        )) {
            val value = number(raw) ?: continue
            if (value < 0.0 || value > 100.0) {
                errors[key] = "Enter a percentage between 0 and 100"
            }
        }
        for ((key, raw) in listOf(
            "ots_rlb_amount" to otsRlbAmount,
            "ots_payable_amount" to otsPayableAmount,
            "ots_total_settlement" to otsTotalSettlement,
            "ots_deposit_amount" to otsDepositAmount,
        )) {
            if (raw.isNotBlank() && number(raw) == null) {
                errors[key] = "Enter a valid amount"
            }
        }

        // A deposit that cannot be traced back to a bank receipt is not evidence
        // of anything - and this system never collects money itself.
        if (otsDepositReceived) {
            if (number(otsDepositAmount).let { it == null || it <= 0.0 }) {
                errors["ots_deposit_amount"] = "Enter the amount the borrower deposited"
            }
            if (otsDepositDate.isBlank()) {
                errors["ots_deposit_date"] = "Enter the deposit date"
            }
            if (otsDepositReference.isBlank()) {
                errors["ots_deposit_reference"] = "Enter the bank receipt or transaction number"
            }
        }

        if (!otsBorrowerAccepted && otsRejectionReason.isBlank()) {
            errors["ots_rejection_reason"] = "Record why the borrower did not accept"
        }
        if (otsValidityFrom.isNotBlank() && otsValidityTo.isNotBlank() &&
            otsValidityTo < otsValidityFrom
        ) {
            errors["ots_validity_to"] = "The validity end cannot be before the start"
        }

        return errors
    }

    private fun validateCkcc(): Map<String, String> {
        if (reportType != REPORT_CKCC) return emptyMap()
        val errors = mutableMapOf<String, String>()

        // Without this date the report cannot say when the account turns NPA,
        // which is the entire reason a renewal report exists.
        if (ckccRenewalDueDate.isBlank()) {
            errors["ckcc_renewal_due_date"] = "Enter the CKCC renewal due date"
        }
        if (ckccDocOthers && ckccDocOtherText.isBlank()) {
            errors["ckcc_doc_other_text"] = "Describe the other document"
        }
        if (ckccRecOthers && ckccRecOtherText.isBlank()) {
            errors["ckcc_rec_other_text"] = "Describe the recommendation"
        }
        if (ckccRenewalFormSigned && !ckccWillingToRenew) {
            errors["ckcc_willing_to_renew"] =
                "A signed renewal form means the borrower is willing - tick that too"
        }
        for ((key, raw) in listOf(
            "ckcc_sanction_limit" to ckccSanctionLimit,
            "ckcc_drawing_power" to ckccDrawingPower,
            "ckcc_interest_overdue" to ckccInterestOverdue,
        )) {
            if (raw.isNotBlank() && number(raw) == null) {
                errors[key] = "Enter a valid amount"
            }
        }

        return errors
    }

    // -----------------------------------------------------------------------
    // Live suggestions
    //
    // These only ever fill a blank field or update a field the agent has not
    // edited. Nothing here overwrites a typed figure: the sanction letter wins,
    // and a form that silently rewrites a settlement amount under the agent's
    // hands would be worse than one that suggests nothing.
    // -----------------------------------------------------------------------

    /**
     * Relief and the borrower's share are the two halves of the same 100%, which is
     * how the worked example runs: 77.50% waived, 22.50% payable. Offered as a hint
     * only - a scheme could define them independently.
     */
    fun suggestedReliefPercent(): Double? {
        val payable = number(otsPayablePercent) ?: return null
        if (payable < 0.0 || payable > 100.0) return null
        return round2(100.0 - payable)
    }

    /** payable = RLB x payable_percent. Null when either input is missing. */
    fun suggestedPayable(): Double? {
        val rlb = number(otsRlbAmount) ?: return null
        val percent = number(otsPayablePercent) ?: return null
        return round2(rlb * percent / 100.0)
    }

    /** The initial deposit the scheme requires, as a share of the payable amount. */
    fun suggestedRequiredDeposit(): Double? {
        val payable = number(otsPayableAmount) ?: suggestedPayable() ?: return null
        val percent = number(otsDepositPercent) ?: return null
        return round2(payable * percent / 100.0)
    }

    /** What is still owed after the deposit already made. */
    fun suggestedBalancePayable(): Double? {
        val total = number(otsTotalSettlement) ?: number(otsPayableAmount) ?: return null
        val paid = number(otsDepositAmount) ?: 0.0
        return round2((total - paid).coerceAtLeast(0.0))
    }

    /**
     * Days between today and the renewal deadline, negative once overdue.
     *
     * Shown as a hint only. The stored value is computed on the server, because a
     * device with a wrong clock must not be able to write a misleading deadline
     * into a report a branch acts on.
     */
    fun daysToRenewal(today: java.time.LocalDate = java.time.LocalDate.now()): Long? {
        val due = runCatching { java.time.LocalDate.parse(ckccRenewalDueDate) }.getOrNull()
            ?: return null
        return java.time.temporal.ChronoUnit.DAYS.between(today, due)
    }

    /** The bucket the renewal falls in, matching the server's enum. */
    fun renewalBucket(today: java.time.LocalDate = java.time.LocalDate.now()): String? =
        when (val days = daysToRenewal(today)) {
            null -> null
            else -> when {
                days < 0 -> "overdue"
                days <= 7 -> "within_7"
                days <= 15 -> "within_15"
                else -> "within_30"
            }
        }

    /** Expected NPA date if the renewal is not completed: the day after the deadline. */
    fun expectedNpaDate(): String? =
        runCatching { java.time.LocalDate.parse(ckccRenewalDueDate).plusDays(1).toString() }
            .getOrNull()

    /** Parsed promise amount, tolerating grouped input like "12,500". */
    fun promiseAmountValue(): Double? {
        val cleaned = promiseAmount.replace(",", "").replace("\u20B9", "").trim()
        if (cleaned.isEmpty()) return null
        return cleaned.toDoubleOrNull()
    }

    /** True when this report will create a promise case. */
    fun createsPromise(): Boolean {
        val amount = promiseAmountValue()
        return amount != null && amount > 0.0 && promiseDate.isNotBlank()
    }

    /**
     * Serialises the form to the multipart field map the API expects.
     * Booleans go as "1"/"0"; blank optional fields are omitted entirely.
     */
    fun toFieldMap(): Map<String, String> {
        val fields = linkedMapOf<String, String>()

        fields["loan_account_id"] = loanAccountId.toString()
        fields["visit_date"] = visitDate
        fields["visit_time"] = visitTime
        fields["client_uuid"] = clientUuid

        putIfNotBlank(fields, "village", village)

        fields["customer_met"] = bool(customerMet)
        fields["family_member_met"] = bool(familyMemberMet)
        fields["house_locked"] = bool(houseLocked)
        fields["phone_contact"] = bool(phoneContact)
        fields["phone_switched_off"] = bool(phoneSwitchedOff)
        putIfNotBlank(fields, "family_member_name", familyMemberName)
        putIfNotBlank(fields, "family_member_relationship", familyMemberRelationship)

        fields["borrower_alive"] = bool(borrowerAlive)
        fields["same_address"] = bool(sameAddress)
        fields["shifted"] = bool(shifted)
        putIfNotBlank(fields, "occupation", occupation)
        putIfNotBlank(fields, "occupation_other_text", occupationOtherText)

        fields["ready_to_pay"] = bool(readyToPay)
        fields["not_ready"] = bool(notReady)
        fields["interest_payment"] = bool(interestPayment)
        fields["ots"] = bool(ots)

        // Only send a promise when it is complete, so the server never has to
        // guess at half a promise.
        if (createsPromise()) {
            fields["promise_amount"] = promiseAmountValue()!!.toString()
            fields["promise_date"] = promiseDate
        }

        fields["reason_financial_problem"] = bool(reasonFinancialProblem)
        fields["reason_crop_loss"] = bool(reasonCropLoss)
        fields["reason_animal_loss"] = bool(reasonAnimalLoss)
        fields["reason_illness"] = bool(reasonIllness)
        fields["reason_unemployment"] = bool(reasonUnemployment)
        fields["reason_dispute"] = bool(reasonDispute)
        fields["reason_other_loan"] = bool(reasonOtherLoan)
        fields["reason_others"] = bool(reasonOthers)
        putIfNotBlank(fields, "reason_other_text", reasonOtherText)

        fields["rec_recovery_possible"] = bool(recRecoveryPossible)
        fields["rec_regular_followup"] = bool(recRegularFollowup)
        fields["rec_legal_action"] = bool(recLegalAction)
        fields["rec_rc"] = bool(recRc)
        fields["rec_ots"] = bool(recOts)
        fields["rec_others"] = bool(recOthers)
        putIfNotBlank(fields, "rec_other_text", recOtherText)

        fields["report_type"] = reportType
        putIfNotBlank(fields, "sp_cbc_name", spCbcName)

        // The detail sections are sent as flat `ots_details[field]` keys. The visit
        // goes up as multipart because it carries photos, and multipart has no
        // nesting - the server accepts either shape.
        if (reportType == REPORT_OTS) {
            fields["ots_details[eligible_for_ots]"] = bool(otsEligible)
            putIfNotBlank(fields, "ots_details[scheme]", otsScheme)
            putAmount(fields, "ots_details[relief_waiver_percent]", otsReliefPercent)
            putAmount(fields, "ots_details[rlb_amount]", otsRlbAmount)
            putAmount(fields, "ots_details[payable_percent]", otsPayablePercent)
            putAmount(fields, "ots_details[borrower_payable_amount]", otsPayableAmount)
            putAmount(fields, "ots_details[total_settlement_amount]", otsTotalSettlement)
            putAmount(fields, "ots_details[initial_deposit_percent]", otsDepositPercent)
            putAmount(fields, "ots_details[required_deposit_amount]", otsRequiredDeposit)
            fields["ots_details[deposit_received]"] = bool(otsDepositReceived)
            putAmount(fields, "ots_details[deposit_amount]", otsDepositAmount)
            putIfNotBlank(fields, "ots_details[deposit_date]", otsDepositDate)
            putIfNotBlank(fields, "ots_details[deposit_reference]", otsDepositReference)
            putAmount(fields, "ots_details[balance_payable]", otsBalancePayable)
            putIfNotBlank(fields, "ots_details[proposed_final_payment_date]", otsFinalPaymentDate)
            putIfNotBlank(fields, "ots_details[approval_status]", otsApprovalStatus)
            putIfNotBlank(fields, "ots_details[validity_from]", otsValidityFrom)
            putIfNotBlank(fields, "ots_details[validity_to]", otsValidityTo)
            putIfNotBlank(fields, "ots_details[expected_closure_date]", otsExpectedClosureDate)
            fields["ots_details[borrower_accepted]"] = bool(otsBorrowerAccepted)
            putIfNotBlank(fields, "ots_details[rejection_reason]", otsRejectionReason)
        }

        if (reportType == REPORT_CKCC) {
            putIfNotBlank(fields, "ckcc_details[cif_number]", ckccCifNumber)
            putIfNotBlank(fields, "ckcc_details[sanction_date]", ckccSanctionDate)
            putAmount(fields, "ckcc_details[sanction_limit]", ckccSanctionLimit)
            putAmount(fields, "ckcc_details[drawing_power]", ckccDrawingPower)
            putAmount(fields, "ckcc_details[outstanding_amount]", ckccOutstanding)
            putAmount(fields, "ckcc_details[interest_overdue]", ckccInterestOverdue)
            putIfNotBlank(fields, "ckcc_details[renewal_due_date]", ckccRenewalDueDate)

            fields["ckcc_details[eligible_for_renewal]"] = bool(ckccEligibleForRenewal)
            // The server stores an enum; the form asks it as a single checkbox
            // because "pending" is simply the absence of "complete".
            fields["ckcc_details[kyc_status]"] = if (ckccKycComplete) "complete" else "pending"
            fields["ckcc_details[aadhaar_seeded]"] = bool(ckccAadhaarSeeded)
            fields["ckcc_details[mobile_linked]"] = bool(ckccMobileLinked)
            fields["ckcc_details[aadhaar_auth_completed]"] = bool(ckccAadhaarAuthCompleted)

            fields["ckcc_details[doc_aadhaar]"] = bool(ckccDocAadhaar)
            fields["ckcc_details[doc_pan]"] = bool(ckccDocPan)
            fields["ckcc_details[doc_passbook]"] = bool(ckccDocPassbook)
            fields["ckcc_details[doc_land_record]"] = bool(ckccDocLandRecord)
            fields["ckcc_details[doc_khasra_khatauni]"] = bool(ckccDocKhasraKhatauni)
            fields["ckcc_details[doc_photograph]"] = bool(ckccDocPhotograph)
            fields["ckcc_details[doc_mobile_available]"] = bool(ckccDocMobileAvailable)
            fields["ckcc_details[doc_others]"] = bool(ckccDocOthers)
            putIfNotBlank(fields, "ckcc_details[doc_other_text]", ckccDocOtherText)

            fields["ckcc_details[willing_to_renew]"] = bool(ckccWillingToRenew)
            fields["ckcc_details[documents_handed_over]"] = bool(ckccDocumentsHandedOver)
            fields["ckcc_details[renewal_form_signed]"] = bool(ckccRenewalFormSigned)
            fields["ckcc_details[ekyc_completed]"] = bool(ckccEkycCompleted)
            fields["ckcc_details[biometrics_completed]"] = bool(ckccBiometricsCompleted)

            putIfNotBlank(fields, "ckcc_details[agent_observation]", ckccObservation)
            fields["ckcc_details[rec_renew_immediately]"] = bool(ckccRecRenewImmediately)
            fields["ckcc_details[rec_documents_submitted]"] = bool(ckccRecDocumentsSubmitted)
            fields["ckcc_details[rec_followup_required]"] = bool(ckccRecFollowupRequired)
            fields["ckcc_details[rec_not_interested]"] = bool(ckccRecNotInterested)
            fields["ckcc_details[rec_branch_contact_urgent]"] = bool(ckccRecBranchContactUrgent)
            fields["ckcc_details[rec_others]"] = bool(ckccRecOthers)
            putIfNotBlank(fields, "ckcc_details[rec_other_text]", ckccRecOtherText)

            fields["ckcc_details[st_customer_contacted]"] = bool(ckccStCustomerContacted)
            fields["ckcc_details[st_customer_verified]"] = bool(ckccStCustomerVerified)
            fields["ckcc_details[st_documents_collected]"] = bool(ckccStDocumentsCollected)
            fields["ckcc_details[st_application_submitted]"] = bool(ckccStApplicationSubmitted)
            fields["ckcc_details[st_ckcc_renewed]"] = bool(ckccStCkccRenewed)
            fields["ckcc_details[st_pending_at_branch]"] = bool(ckccStPendingAtBranch)
            fields["ckcc_details[st_followup_required]"] = bool(ckccStFollowupRequired)
            fields["ckcc_details[st_became_npa]"] = bool(ckccStBecameNpa)
        }

        putIfNotBlank(fields, "remarks", remarks)
        putIfNotBlank(fields, "app_version", appVersion)
        putIfNotBlank(fields, "device_info", deviceInfo)

        // Always sent, even when there is nothing to report, so the server can tell
        // "no fix" apart from "an app that does not collect one".
        fields["gps_source"] = gpsSource

        if (gpsSource == "device" && gpsLatitude != null && gpsLongitude != null) {
            fields["gps_latitude"] = gpsLatitude.toString()
            fields["gps_longitude"] = gpsLongitude.toString()
            gpsAccuracyMetres?.let { fields["gps_accuracy_m"] = it.toString() }
            putIfNotBlank(fields, "gps_captured_at", gpsCapturedAt)
        }

        photoSources.forEach { (slot, source) ->
            if (source.isNotBlank()) {
                fields["${slot}_photo_source"] = source
            }
        }

        photoStamps.forEach { (slot, packed) ->
            val fix = unpackFix(packed) ?: return@forEach

            fields["${slot}_photo_gps_source"] = "camera"
            fields["${slot}_photo_latitude"] = fix.latitude
            fields["${slot}_photo_longitude"] = fix.longitude
            fix.accuracyMetres?.let { fields["${slot}_photo_accuracy_m"] = it }
            // The server records this in photos.captured_at, which held NULL for every
            // photograph ever filed until it was sent. A printed report said where a
            // photograph was taken but never when, so two photographs of the same door
            // an hour apart were indistinguishable.
            fix.capturedAt?.let { fields["${slot}_photo_captured_at"] = it }
        }

        return fields
    }

    /** A fix unpacked from its wire string. */
    private data class UnpackedFix(
        val latitude: String,
        val longitude: String,
        val accuracyMetres: String?,
        val capturedAt: String?,
    )

    /**
     * Reads "lat,lng,accuracyOrBlank,capturedAt" back apart.
     *
     * Returns null unless both coordinates are present, so a half-filled stamp is
     * dropped rather than sent as a position with a missing half. Tolerates a
     * three-part string because that is what earlier builds packed, and a stamp
     * without a capture time is still a usable position.
     */
    private fun unpackFix(packed: String): UnpackedFix? {
        if (packed.isBlank()) return null

        val parts = packed.split(',')
        if (parts.size < 2 || parts[0].isBlank() || parts[1].isBlank()) return null

        return UnpackedFix(
            latitude = parts[0],
            longitude = parts[1],
            accuracyMetres = parts.getOrNull(2)?.takeIf { it.isNotBlank() },
            capturedAt = parts.drop(3).joinToString(",").takeIf { it.isNotBlank() },
        )
    }

    /** Typed photo files keyed by their API multipart field name. */
    fun photoFiles(): Map<String, File> = buildMap {
        customerPhoto?.let { put("customer_photo", it) }
        housePhoto?.let { put("house_photo", it) }
        aadhaarPhoto?.let { put("aadhaar_photo", it) }
        agentPhoto?.let { put("agent_photo", it) }
        landPhoto?.let { put("land_photo", it) }
        passbookPhoto?.let { put("passbook_photo", it) }
        renewalFormPhoto?.let { put("renewal_form_photo", it) }
    }

    fun attachmentCount(): Int = photoFiles().size + otherDocuments.size

    /** True when the user has entered anything worth warning about on exit. */
    fun hasUnsavedInput(): Boolean =
        customerMet || familyMemberMet || houseLocked || phoneContact || phoneSwitchedOff ||
            shifted || !borrowerAlive || !sameAddress ||
            readyToPay || notReady || interestPayment || ots ||
            promiseAmount.isNotBlank() || promiseDate.isNotBlank() ||
            remarks.isNotBlank() || occupation.isNotBlank() ||
            reasonFinancialProblem || reasonCropLoss || reasonAnimalLoss || reasonIllness ||
            reasonUnemployment || reasonDispute || reasonOtherLoan || reasonOthers ||
            recRecoveryPossible || recRegularFollowup || recLegalAction || recRc ||
            recOts || recOthers ||
            reportType != REPORT_RECOVERY ||
            otsRlbAmount.isNotBlank() || otsPayableAmount.isNotBlank() ||
            otsDepositAmount.isNotBlank() || otsScheme.isNotBlank() ||
            ckccRenewalDueDate.isNotBlank() || ckccObservation.isNotBlank() ||
            attachmentCount() > 0

    private fun bool(value: Boolean): String = if (value) "1" else "0"

    /** Parses a money or percentage field, tolerating grouping and a rupee sign. */
    fun number(raw: String): Double? {
        val cleaned = raw.replace(",", "").replace("\u20B9", "").trim()
        if (cleaned.isEmpty()) return null
        return cleaned.toDoubleOrNull()
    }

    private fun round2(value: Double): Double = Math.round(value * 100.0) / 100.0

    /** Sends a numeric field only when it parses, so "abc" never reaches the API. */
    private fun putAmount(target: MutableMap<String, String>, key: String, raw: String) {
        number(raw)?.let { target[key] = it.toString() }
    }

    private fun putIfNotBlank(target: MutableMap<String, String>, key: String, value: String) {
        val trimmed = value.trim()
        if (trimmed.isNotEmpty()) {
            target[key] = trimmed
        }
    }

    companion object {
        const val REPORT_RECOVERY = "recovery"
        const val REPORT_OTS = "ots"
        const val REPORT_CKCC = "ckcc_renewal"

        const val OTS_STATUS_PENDING = "pending"

        /** Scheme defaults; both stay editable per case. */
        const val DEFAULT_PAYABLE_PERCENT = "22.5"
        const val DEFAULT_DEPOSIT_PERCENT = "10"

        /** The report types, matching the server enum. */
        val REPORT_TYPES = listOf(
            REPORT_RECOVERY to "Recovery Visit",
            REPORT_OTS to "KRM / OTS Settlement",
            REPORT_CKCC to "CKCC OD-2 Renewal",
        )

        val OTS_SCHEMES = listOf(
            "krm_ots" to "KRM OTS",
            "general_ots" to "General OTS",
        )

        val OTS_APPROVAL_STATUSES = listOf(
            "pending" to "Pending",
            "approved" to "Approved",
            "rejected" to "Rejected",
        )

        const val OCCUPATION_OTHERS = "others"

        /** The occupation enum, matching the server. */
        val OCCUPATIONS = listOf(
            "agriculture" to "Agriculture",
            "dairy" to "Dairy",
            "business" to "Business",
            "job" to "Job",
            "labour" to "Labour",
            OCCUPATION_OTHERS to "Others",
        )
    }
}
