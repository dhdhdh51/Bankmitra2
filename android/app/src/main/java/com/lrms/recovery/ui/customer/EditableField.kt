package com.lrms.recovery.ui.customer

import com.lrms.recovery.data.remote.LeadDto

/**
 * One row of the profile's "Additional details" section - a field the agent can
 * see and correct, beyond the summary cards at the top of the screen.
 *
 * Covers two different sources under one shape, deliberately:
 *
 *   FIXED fields - the rest of the core banking statement (sanction limit, days
 *   past due, security value, and so on). [isCustom] is false and [key] is the
 *   literal loan_accounts column name, which is exactly what
 *   LoanAccount::MANUALLY_EDITABLE expects back on save.
 *
 *   CUSTOM fields - anything the admin panel or an Excel import added beyond the
 *   fixed schema, including a field auto-created from a spreadsheet column
 *   nobody recognised. [isCustom] is true and [key] is the field's own
 *   field_key, which CustomField::saveValues() on the server matches submitted
 *   keys against.
 *
 * A single flat list of these is what lets one adapter and one Save button
 * handle both kinds without the screen needing to know which is which - an
 * agent should not have to care whether "Crop Insurance No." came from the
 * fixed schema or from last month's import.
 */
data class EditableField(
    /** The key this field is saved back under - a column name or a custom field_key. */
    val key: String,
    val label: String,
    /** text | textarea | number | money | date | select | toggle | flag */
    val fieldType: String,
    /** Choices for a `select` field. Empty for every other type. */
    val options: List<String> = emptyList(),
    /** The current value, as a plain string - "" or null both mean "not recorded". */
    val value: String?,
    val isCustom: Boolean,
    /** Whether a person, not an import, is the reason this value is what it is. */
    val isOverridden: Boolean = false,
    val hint: String? = null,
    val isRequired: Boolean = false,
)

/**
 * Every field the fixed schema and the account's custom fields carry, beyond
 * what the summary cards at the top of the profile already show.
 *
 * `flag` is used for the two tri-state fields (OTS/KRM eligible) rather than
 * `toggle`: a plain switch cannot represent "the file never said", and collapsing
 * that into "No" would be recording a refusal that was never actually made -
 * exactly the distinction ImportService.parseBoolean() and
 * LeadController::update()'s own 'flag' handling exist to preserve server-side.
 */
fun LeadDto.toAdditionalFields(): List<EditableField> {
    val overridden = overriddenFields.toSet()

    fun fixed(
        key: String,
        label: String,
        type: String,
        value: String?,
    ) = EditableField(key, label, type, value = value, isCustom = false, isOverridden = key in overridden)

    val fixedFields = listOf(
        // Also shown read-only in the summary cards above - repeated here,
        // editable, rather than turning those cards themselves into input
        // fields, so the at-a-glance header an agent checks first stays exactly
        // that: a glance, not a form.
        fixed("name", "Customer name", "text", customerName),
        fixed("father_husband_name", "Father / husband name", "text", fatherHusbandName),
        // Only offered when the app actually holds the real number/Aadhaar (an agent
        // does), not the masked form - editing a masked value back to the server
        // would overwrite the real one with asterisks.
        fixed("mobile", "Mobile", "text", mobile),
        fixed("aadhaar", "Aadhaar", "text", aadhaar),
        fixed("village", "Village", "text", village),
        fixed("address", "Address", "textarea", address),
        fixed("loan_type", "Loan type", "text", loanType),
        fixed("outstanding_amount", "Outstanding amount", "money", formatAmount(outstandingAmount)),
        fixed("overdue_amount", "Overdue amount", "money", formatAmount(overdueAmount)),
        fixed("bc_code", "BC code on the account", "text", bcCode),
        fixed("npa_date", "NPA date", "date", npaDate),
        fixed("cif_number", "CIF number", "text", cifNumber),
        fixed("guarantor_name", "Guarantor name", "text", guarantorName),
        fixed("asset_classification", "Asset classification", "text", assetClassification),
        fixed("purpose", "Purpose / activity", "text", purpose),
        fixed("sanction_date", "Sanction date", "date", sanctionDate),
        fixed("sanction_limit", "Sanction limit", "money", sanctionLimit?.let(::formatAmount)),
        fixed("drawing_power", "Drawing power", "money", drawingPower?.let(::formatAmount)),
        fixed("interest_overdue", "Interest overdue", "money", interestOverdue?.let(::formatAmount)),
        fixed("interest_rate", "Interest rate (%)", "money", interestRate?.let(::formatAmount)),
        fixed("installment_amount", "Instalment / EMI", "money", installmentAmount?.let(::formatAmount)),
        fixed("last_payment_date", "Last payment date", "date", lastPaymentDate),
        fixed("last_payment_amount", "Last payment amount", "money", lastPaymentAmount?.let(::formatAmount)),
        fixed("days_past_due", "Days past due (DPD)", "number", daysPastDue?.toString()),
        fixed("security_value", "Security value", "money", securityValue?.let(::formatAmount)),
        fixed("maturity_date", "Maturity date", "date", maturityDate),
        fixed("ckcc_renewal_due_date", "CKCC renewal due date", "date", ckccRenewalDueDate),
        fixed("ots_eligible", "OTS eligible", "flag", otsEligible?.let { if (it) "1" else "0" }),
        fixed("ots_amount", "OTS amount", "money", otsAmount?.let(::formatAmount)),
        fixed("krm_eligible", "KRM eligible", "flag", krmEligible?.let { if (it) "1" else "0" }),
        fixed("deposit_amount", "Deposit amount", "money", depositAmount?.let(::formatAmount)),
        fixed("closure_amount", "Closure amount", "money", closureAmount?.let(::formatAmount)),
        fixed("next_followup_date", "Next follow-up date", "date", nextFollowupDate),
        fixed("remarks", "Notes on this account", "textarea", remarks),
    )

    val customFieldRows = customFields.map { field ->
        EditableField(
            key = field.key,
            label = field.label,
            fieldType = field.fieldType,
            options = field.options,
            value = field.value,
            isCustom = true,
            hint = field.hint,
            isRequired = field.isRequired,
        )
    }

    return fixedFields + customFieldRows
}

/** Trims a trailing ".00" so an editable amount field is not full of zeroes nobody typed. */
private fun formatAmount(value: Double): String {
    if (value == value.toLong().toDouble()) return value.toLong().toString()
    return value.toString()
}
