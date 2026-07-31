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
    var otherDocuments: MutableList<File> = mutableListOf(),

    // ---- Signatures --------------------------------------------------------
    var customerSignature: File? = null,
    var agentSignature: File? = null,
    var customerSignatureName: String = "",
    var agentSignatureName: String = "",

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

        return errors
    }

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

        putIfNotBlank(fields, "remarks", remarks)
        putIfNotBlank(fields, "customer_signature_name", customerSignatureName)
        putIfNotBlank(fields, "agent_signature_name", agentSignatureName)
        putIfNotBlank(fields, "app_version", appVersion)
        putIfNotBlank(fields, "device_info", deviceInfo)

        return fields
    }

    /** Typed photo files keyed by their API multipart field name. */
    fun photoFiles(): Map<String, File> = buildMap {
        customerPhoto?.let { put("customer_photo", it) }
        housePhoto?.let { put("house_photo", it) }
        aadhaarPhoto?.let { put("aadhaar_photo", it) }
    }

    fun signatureFiles(): Map<String, File> = buildMap {
        customerSignature?.let { put("customer_signature", it) }
        agentSignature?.let { put("agent_signature", it) }
    }

    fun attachmentCount(): Int =
        photoFiles().size + signatureFiles().size + otherDocuments.size

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
            attachmentCount() > 0

    private fun bool(value: Boolean): String = if (value) "1" else "0"

    private fun putIfNotBlank(target: MutableMap<String, String>, key: String, value: String) {
        val trimmed = value.trim()
        if (trimmed.isNotEmpty()) {
            target[key] = trimmed
        }
    }

    companion object {
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
