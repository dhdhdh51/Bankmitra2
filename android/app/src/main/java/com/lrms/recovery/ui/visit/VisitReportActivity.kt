package com.lrms.recovery.ui.visit

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.View
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.core.widget.doAfterTextChanged
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.BuildConfig
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.ActivityVisitReportBinding
import com.lrms.recovery.domain.VisitFormData
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.location.GeoStamp
import com.lrms.recovery.ui.photo.PhotoUploadActivity
import com.lrms.recovery.util.FileStore
import com.lrms.recovery.reminder.ReportReminderScheduler
import com.lrms.recovery.util.Formatters
import kotlinx.coroutines.launch
import java.io.File
import java.util.Calendar

/**
 * The Digital BC Field Visit Report form.
 *
 * Every field in Section 6 of the specification is present, in the same order and
 * grouping, so an agent filling the paper form finds the app familiar.
 *
 * Notable behaviour:
 *  - Contact outcomes are mutually exclusive: only one of "customer met",
 *    "family member met", "house locked" or "phone switched off" can be true.
 *  - A promise needs both an amount and a date; half a promise is refused rather
 *    than silently dropped, because the server would not create a promise case.
 *  - Submission is idempotent through the form's client UUID, so a retry after a
 *    dropped connection cannot file the same visit twice.
 */
class VisitReportActivity : BaseActivity() {

    private lateinit var binding: ActivityVisitReportBinding

    private lateinit var form: VisitFormData

    private var submitting = false

    // ---- Child screen results ---------------------------------------------

    private val photoLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        if (result.resultCode != RESULT_OK) return@registerForActivityResult

        val data = result.data ?: return@registerForActivityResult

        data.getStringExtra(PhotoUploadActivity.RESULT_CUSTOMER_PHOTO)?.let {
            form.customerPhoto = File(it)
        }
        data.getStringExtra(PhotoUploadActivity.RESULT_HOUSE_PHOTO)?.let {
            form.housePhoto = File(it)
        }
        data.getStringExtra(PhotoUploadActivity.RESULT_AADHAAR_PHOTO)?.let {
            form.aadhaarPhoto = File(it)
        }
        data.getStringExtra(PhotoUploadActivity.RESULT_AGENT_PHOTO)?.let {
            form.agentPhoto = File(it)
        }
        data.getStringArrayListExtra(PhotoUploadActivity.RESULT_OTHER_DOCS)?.let { paths ->
            form.otherDocuments = paths.map { File(it) }.toMutableList()
        }

        // Per-photo coordinates, present only for the slots the camera filled. The
        // map is rebuilt rather than merged: a photo swapped for a gallery pick
        // arrives with its key absent, and a stale entry would leave the old
        // position attached to the new image.
        form.photoStamps.clear()
        mapOf(
            "customer" to PhotoUploadActivity.RESULT_CUSTOMER_GPS,
            "house" to PhotoUploadActivity.RESULT_HOUSE_GPS,
            "aadhaar" to PhotoUploadActivity.RESULT_AADHAAR_GPS,
            "agent" to PhotoUploadActivity.RESULT_AGENT_GPS,
        ).forEach { (slot, key) ->
            data.getStringExtra(key)?.let { form.photoStamps[slot] = it }
        }

        // Rebuilt for the same reason as the stamps: a slot refilled from the gallery
        // arrives without a camera entry, and a stale one would keep calling it a
        // doorstep photograph.
        form.photoSources.clear()
        mapOf(
            "customer" to PhotoUploadActivity.RESULT_CUSTOMER_SOURCE,
            "house" to PhotoUploadActivity.RESULT_HOUSE_SOURCE,
            "aadhaar" to PhotoUploadActivity.RESULT_AADHAAR_SOURCE,
            "agent" to PhotoUploadActivity.RESULT_AGENT_SOURCE,
        ).forEach { (slot, key) ->
            data.getStringExtra(key)?.let { form.photoSources[slot] = it }
        }

        renderPhotoState()
    }

    // -----------------------------------------------------------------------

    private var leadLoanType: String = ""
    private var leadOutstanding: Double = 0.0

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityVisitReportBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { confirmDiscard() }

        val leadId = intent.getIntExtra(EXTRA_LEAD_ID, 0)
        if (leadId <= 0) {
            showMessage(R.string.error_unknown, binding.root)
            finish()
            return
        }

        form = VisitFormData(
            loanAccountId = leadId,
            visitDate = Formatters.todayIso(),
            visitTime = Formatters.nowTimeIso(),
            village = intent.getStringExtra(EXTRA_VILLAGE).orEmpty(),
            appVersion = BuildConfig.VERSION_NAME,
            deviceInfo = "${Build.MANUFACTURER} ${Build.MODEL} (Android ${Build.VERSION.RELEASE})",
        )

        binding.toolbar.subtitle = intent.getStringExtra(EXTRA_CUSTOMER_NAME)
        leadLoanType = intent.getStringExtra(EXTRA_LOAN_TYPE).orEmpty()
        leadOutstanding = intent.getDoubleExtra(EXTRA_OUTSTANDING, 0.0)

        setUpReportType()
        setUpGeneral()
        setUpBorrower()
        setUpLoanDetails()
        setUpContact()
        setUpVerification()
        setUpDocumentsVerified()
        setUpRecovery()
        setUpReasons()
        setUpRecommendations()
        setUpEvidence()
        setUpDeclaration()
        setUpAttachments()
        setUpOts()
        setUpCkcc()

        binding.buttonSubmit.setOnClickListener { submit() }

        onBackPressedDispatcher.addCallback(
            this,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() = confirmDiscard()
            },
        )
    }


    // =======================================================================
    // Report type
    // =======================================================================

    /**
     * The dropdown that decides which sections exist.
     *
     * Switching type only hides a section, it never clears it: an agent who taps
     * the wrong entry and switches back would otherwise lose everything typed so
     * far, and only the fields belonging to the selected type are sent anyway.
     */
    private fun setUpReportType() {
        val labels = VisitFormData.REPORT_TYPES.map { it.second }
        binding.inputReportType.setSimpleItems(labels.toTypedArray())
        binding.inputReportType.setText(labels.first(), false)

        // Defaults to the first entry in the list, which is what setText() above shows.
        form.reportType = VisitFormData.REPORT_TYPES.first().first

        binding.inputReportType.setOnItemClickListener { _, _, position, _ ->
            form.reportType = VisitFormData.REPORT_TYPES[position].first
            applyReportType()
        }

        // A CKCC account is almost certainly here for a renewal, but the agent may
        // still be on a recovery call - so suggest, never switch for them.
        binding.textCkccSuggestion.visibility =
            if (leadLoanType.contains("ckcc", ignoreCase = true)) View.VISIBLE else View.GONE

        applyReportType()
    }

    private fun applyReportType() {
        binding.sectionOts.root.visibility =
            if (form.reportType == VisitFormData.REPORT_OTS) View.VISIBLE else View.GONE
        binding.sectionCkcc.root.visibility =
            if (form.reportType == VisitFormData.REPORT_CKCC) View.VISIBLE else View.GONE
    }

    // =======================================================================
    // KRM / OTS settlement
    // =======================================================================

    private fun setUpOts() {
        val ots = binding.sectionOts

        ots.inputOtsScheme.setSimpleItems(VisitFormData.OTS_SCHEMES.map { it.second }.toTypedArray())
        ots.inputOtsScheme.setOnItemClickListener { _, _, position, _ ->
            form.otsScheme = VisitFormData.OTS_SCHEMES[position].first
            ots.chipOtsScheme.text = VisitFormData.OTS_SCHEMES[position].second
            ots.chipOtsScheme.visibility = View.VISIBLE
            ots.fieldOtsScheme.error = null
            ots.fieldOtsSchemeOther.visibility =
                if (form.otsScheme == VisitFormData.OTS_SCHEME_OTHER) View.VISIBLE else View.GONE
        }
        bindText(ots.inputOtsSchemeOther) {
            form.otsSchemeOtherText = it
            ots.fieldOtsSchemeOther.error = null
        }

        // WHY the borrower answered as they did. "Asked for time" and "refused
        // outright" both leave the accepted switch off and lead to different next steps.
        ots.inputOtsCustomerResponse.setSimpleItems(
            VisitFormData.OTS_CUSTOMER_RESPONSES.map { it.second }.toTypedArray(),
        )
        ots.inputOtsCustomerResponse.setOnItemClickListener { _, _, position, _ ->
            form.otsCustomerResponse = VisitFormData.OTS_CUSTOMER_RESPONSES[position].first
        }

        bindDate(ots.inputOtsExpectedDepositDate) { form.otsExpectedDepositDate = it }

        ots.checkOtsRecProposal.setOnCheckedChangeListener { _, v -> form.otsRecProposalRecommended = v }
        ots.checkOtsRecFollowup.setOnCheckedChangeListener { _, v -> form.otsRecFollowupRequired = v }
        ots.checkOtsRecRefused.setOnCheckedChangeListener { _, v -> form.otsRecCustomerRefused = v }
        ots.checkOtsRecNotEligible.setOnCheckedChangeListener { _, v -> form.otsRecNotEligible = v }

        ots.checkOtsStContacted.setOnCheckedChangeListener { _, v -> form.otsStCustomerContacted = v }
        ots.checkOtsStVerified.setOnCheckedChangeListener { _, v -> form.otsStCustomerVerified = v }
        ots.checkOtsStAccepted.setOnCheckedChangeListener { _, v -> form.otsStOtsAccepted = v }
        ots.checkOtsStRejected.setOnCheckedChangeListener { _, v -> form.otsStOtsRejected = v }
        ots.checkOtsStDeposit.setOnCheckedChangeListener { _, v -> form.otsStInitialDepositReceived = v }
        ots.checkOtsStClosed.setOnCheckedChangeListener { _, v -> form.otsStOtsClosed = v }
        ots.checkOtsStFollowup.setOnCheckedChangeListener { _, v -> form.otsStFollowupRequired = v }

        ots.inputOtsApprovalStatus.setSimpleItems(
            VisitFormData.OTS_APPROVAL_STATUSES.map { it.second }.toTypedArray(),
        )
        ots.inputOtsApprovalStatus.setText(VisitFormData.OTS_APPROVAL_STATUSES.first().second, false)
        ots.inputOtsApprovalStatus.setOnItemClickListener { _, _, position, _ ->
            form.otsApprovalStatus = VisitFormData.OTS_APPROVAL_STATUSES[position].first
        }

        ots.switchOtsEligible.setOnCheckedChangeListener { _, checked ->
            form.otsEligible = checked
        }

        // Accepted is the normal case, so it starts on and the reason box only
        // matters when it is turned off.
        ots.switchOtsAccepted.isChecked = true
        form.otsBorrowerAccepted = true
        ots.switchOtsAccepted.setOnCheckedChangeListener { _, checked ->
            form.otsBorrowerAccepted = checked
            ots.fieldOtsRejectionReason.visibility = if (checked) View.GONE else View.VISIBLE
        }
        ots.fieldOtsRejectionReason.visibility = View.GONE

        ots.switchOtsDepositReceived.setOnCheckedChangeListener { _, checked ->
            form.otsDepositReceived = checked
            ots.groupOtsDeposit.visibility = if (checked) View.VISIBLE else View.GONE
            refreshOtsSuggestions()
        }

        ots.inputOtsPayablePercent.setText(VisitFormData.DEFAULT_PAYABLE_PERCENT)
        ots.inputOtsDepositPercent.setText(VisitFormData.DEFAULT_DEPOSIT_PERCENT)

        // The residual balance starts at the account's outstanding amount, which is
        // how the settlement is normally worked out - payable is a percentage of it.
        // Still editable: the branch may hand over a different figure.
        if (leadOutstanding > 0.0) {
            ots.inputOtsRlb.setText(Formatters.plainAmount(leadOutstanding))
        }

        bindText(ots.inputOtsRelief) { form.otsReliefPercent = it }
        bindText(ots.inputOtsRlb) { form.otsRlbAmount = it; refreshOtsSuggestions() }
        bindText(ots.inputOtsPayablePercent) { form.otsPayablePercent = it; refreshOtsSuggestions() }
        bindText(ots.inputOtsPayable) { form.otsPayableAmount = it; refreshOtsSuggestions() }
        bindText(ots.inputOtsTotal) { form.otsTotalSettlement = it; refreshOtsSuggestions() }
        bindText(ots.inputOtsDepositPercent) { form.otsDepositPercent = it; refreshOtsSuggestions() }
        bindText(ots.inputOtsRequiredDeposit) { form.otsRequiredDeposit = it }
        bindText(ots.inputOtsDepositAmount) { form.otsDepositAmount = it; refreshOtsSuggestions() }
        bindText(ots.inputOtsDepositReference) { form.otsDepositReference = it }
        bindText(ots.inputOtsBalance) { form.otsBalancePayable = it }
        bindText(ots.inputOtsRejectionReason) { form.otsRejectionReason = it }

        bindDate(ots.inputOtsDepositDate) { form.otsDepositDate = it }
        bindDate(ots.inputOtsFinalPaymentDate) { form.otsFinalPaymentDate = it }
        bindDate(ots.inputOtsValidityFrom) { form.otsValidityFrom = it }
        bindDate(ots.inputOtsValidityTo) { form.otsValidityTo = it }
        bindDate(ots.inputOtsClosureDate) { form.otsExpectedClosureDate = it }

        refreshOtsSuggestions()
    }

    /**
     * Offers computed figures as tappable hints.
     *
     * Never writes into a field on its own. The branch's sanction letter is the
     * authority on a settlement, and a form that silently rewrote an amount under
     * the agent's hands would be worse than one that suggests nothing.
     */
    private fun refreshOtsSuggestions() {
        val ots = binding.sectionOts

        bindSuggestion(ots.textOtsPayableHint, form.suggestedPayable(), form.otsPayableAmount) {
            ots.inputOtsPayable.setText(Formatters.plainAmount(it))
        }
        bindSuggestion(ots.textOtsDepositHint, form.suggestedRequiredDeposit(), form.otsRequiredDeposit) {
            ots.inputOtsRequiredDeposit.setText(Formatters.plainAmount(it))
        }
        bindSuggestion(ots.textOtsBalanceHint, form.suggestedBalancePayable(), form.otsBalancePayable) {
            ots.inputOtsBalance.setText(Formatters.plainAmount(it))
        }
        // Relief is whatever the borrower is not paying.
        bindSuggestion(ots.textOtsReliefHint, form.suggestedReliefPercent(), form.otsReliefPercent) {
            ots.inputOtsRelief.setText(Formatters.plainAmount(it))
        }
    }

    private fun bindSuggestion(
        view: android.widget.TextView,
        suggestion: Double?,
        current: String,
        apply: (Double) -> Unit,
    ) {
        // Hide it once the field agrees with the suggestion, so the hint is not
        // nagging about something already done.
        val alreadyMatches = suggestion != null && form.number(current)?.let {
            kotlin.math.abs(it - suggestion) < 0.01
        } == true

        if (suggestion == null || alreadyMatches) {
            view.visibility = View.GONE
            return
        }
        view.visibility = View.VISIBLE
        view.text = getString(R.string.suggestion_tap_to_use, Formatters.money(suggestion))
        view.setOnClickListener { apply(suggestion) }
    }

    // =======================================================================
    // CKCC OD-2 renewal
    // =======================================================================

    private fun setUpCkcc() {
        val c = binding.sectionCkcc

        bindText(c.inputCkccCif) { form.ckccCifNumber = it }
        bindText(c.inputCkccSanctionLimit) { form.ckccSanctionLimit = it }
        bindText(c.inputCkccDrawingPower) { form.ckccDrawingPower = it }
        bindText(c.inputCkccOutstanding) { form.ckccOutstanding = it }
        bindText(c.inputCkccInterestOverdue) { form.ckccInterestOverdue = it }
        bindText(c.inputCkccObservation) { form.ckccObservation = it }
        bindText(c.inputCkccRecOtherText) { form.ckccRecOtherText = it }

        bindDate(c.inputCkccSanctionDate) { form.ckccSanctionDate = it }
        bindDate(c.inputCkccRenewalDue) {
            form.ckccRenewalDueDate = it
            c.fieldCkccRenewalDue.error = null
            refreshRenewalBanner()
        }

        c.switchCkccEligible.setOnCheckedChangeListener { _, v -> form.ckccEligibleForRenewal = v }
        c.checkCkccKyc.setOnCheckedChangeListener { _, v -> form.ckccKycComplete = v }
        c.checkCkccAadhaarSeeded.setOnCheckedChangeListener { _, v -> form.ckccAadhaarSeeded = v }
        c.checkCkccMobileLinked.setOnCheckedChangeListener { _, v -> form.ckccMobileLinked = v }
        c.checkCkccAadhaarAuth.setOnCheckedChangeListener { _, v -> form.ckccAadhaarAuthCompleted = v }

        // The document checklist is section 7 on the main form now, asked once for
        // every case type - see setUpDocumentsVerified().

        c.checkCkccWilling.setOnCheckedChangeListener { _, v -> form.ckccWillingToRenew = v }
        c.checkCkccDocsHanded.setOnCheckedChangeListener { _, v -> form.ckccDocumentsHandedOver = v }
        c.checkCkccFormSigned.setOnCheckedChangeListener { _, v -> form.ckccRenewalFormSigned = v }
        c.checkCkccEkyc.setOnCheckedChangeListener { _, v -> form.ckccEkycCompleted = v }
        c.checkCkccBiometrics.setOnCheckedChangeListener { _, v -> form.ckccBiometricsCompleted = v }

        c.checkCkccRecRenewNow.setOnCheckedChangeListener { _, v -> form.ckccRecRenewImmediately = v }
        c.checkCkccRecDocsSubmitted.setOnCheckedChangeListener { _, v -> form.ckccRecDocumentsSubmitted = v }
        c.checkCkccRecPendingDocs.setOnCheckedChangeListener { _, v -> form.ckccRecPendingDocuments = v }
        c.checkCkccRecFollowup.setOnCheckedChangeListener { _, v -> form.ckccRecFollowupRequired = v }
        c.checkCkccRecNotInterested.setOnCheckedChangeListener { _, v -> form.ckccRecNotInterested = v }
        c.checkCkccRecBranchUrgent.setOnCheckedChangeListener { _, v -> form.ckccRecBranchContactUrgent = v }
        c.checkCkccRecOther.setOnCheckedChangeListener { _, v ->
            form.ckccRecOthers = v
            c.fieldCkccRecOtherText.visibility = if (v) View.VISIBLE else View.GONE
        }

        c.checkCkccStContacted.setOnCheckedChangeListener { _, v -> form.ckccStCustomerContacted = v }
        c.checkCkccStVerified.setOnCheckedChangeListener { _, v -> form.ckccStCustomerVerified = v }
        c.checkCkccStDocsCollected.setOnCheckedChangeListener { _, v -> form.ckccStDocumentsCollected = v }
        c.checkCkccStSubmitted.setOnCheckedChangeListener { _, v -> form.ckccStApplicationSubmitted = v }
        c.checkCkccStRenewed.setOnCheckedChangeListener { _, v -> form.ckccStCkccRenewed = v }
        c.checkCkccStPendingBranch.setOnCheckedChangeListener { _, v -> form.ckccStPendingAtBranch = v }
        c.checkCkccStFollowup.setOnCheckedChangeListener { _, v -> form.ckccStFollowupRequired = v }
        c.checkCkccStNpa.setOnCheckedChangeListener { _, v -> form.ckccStBecameNpa = v }

        refreshRenewalBanner()
    }

    /**
     * The countdown banner.
     *
     * A renewal report exists because of one date, so the consequence of that date
     * is spelled out rather than left as arithmetic for an agent reading a phone
     * screen in the field. Amber inside a week, red once overdue.
     *
     * This is display only - the stored deadline and bucket are computed on the
     * server, so a device with a wrong clock cannot write a misleading date into a
     * report a branch acts on.
     */
    private fun refreshRenewalBanner() {
        val c = binding.sectionCkcc
        val days = form.daysToRenewal()

        if (days == null) {
            c.bannerCkccNpa.visibility = View.GONE
            c.chipCkccBucket.visibility = View.GONE
            return
        }

        c.bannerCkccNpa.visibility = View.VISIBLE
        c.textCkccCountdown.text = when {
            days < 0L -> getString(R.string.ckcc_days_overdue, -days)
            days == 0L -> getString(R.string.ckcc_due_today)
            else -> getString(R.string.ckcc_days_remaining, days)
        }
        form.expectedNpaDate()?.let {
            c.textCkccNpaDate.text = getString(R.string.ckcc_npa_warning, Formatters.date(it))
        }

        val tone = when {
            days < 0L -> R.color.lrms_danger
            days <= 7L -> R.color.lrms_warning
            else -> R.color.lrms_ink
        }
        c.textCkccCountdown.setTextColor(androidx.core.content.ContextCompat.getColor(this, tone))

        val bucketLabel = when (form.renewalBucket()) {
            "overdue" -> R.string.ckcc_bucket_overdue
            "within_7" -> R.string.ckcc_bucket_7
            "within_15" -> R.string.ckcc_bucket_15
            else -> R.string.ckcc_bucket_30
        }
        c.chipCkccBucket.visibility = View.VISIBLE
        c.chipCkccBucket.setText(bucketLabel)
    }

    // =======================================================================
    // Small binding helpers
    // =======================================================================

    private fun bindText(input: android.widget.EditText, assign: (String) -> Unit) {
        input.doAfterTextChanged { assign(it?.toString().orEmpty()) }
    }

    /**
     * A one-of-many dropdown that reports the stored value, not the label.
     *
     * Deliberately starts EMPTY rather than pre-selecting the first option. Every one of
     * these is a question the agent is meant to answer, and a control that answers it for
     * them by default records something nobody asserted - which on a verification form is
     * the difference between "not confirmed" and "never asked".
     *
     * @param options value-to-label pairs, in the order the printed form lists them.
     */
    private fun bindChoice(
        input: com.google.android.material.textfield.MaterialAutoCompleteTextView,
        options: List<Pair<String, String>>,
        assign: (String) -> Unit,
    ) {
        input.setSimpleItems(options.map { it.second }.toTypedArray())
        input.setOnItemClickListener { _, _, position, _ -> assign(options[position].first) }
    }

    /** Read-only field that opens a date picker and reports an ISO date. */
    private fun bindDate(input: android.widget.EditText, assign: (String) -> Unit) {
        input.setOnClickListener {
            val calendar = Calendar.getInstance()
            DatePickerDialog(
                this,
                { _, year, month, day ->
                    val iso = Formatters.isoFrom(year, month, day)
                    assign(iso)
                    input.setText(Formatters.date(iso))
                },
                calendar.get(Calendar.YEAR),
                calendar.get(Calendar.MONTH),
                calendar.get(Calendar.DAY_OF_MONTH),
            ).show()
        }
    }

    // =======================================================================
    // General
    // =======================================================================

    private fun setUpGeneral() {
        binding.inputVisitDate.setText(Formatters.date(form.visitDate))
        binding.inputVisitTime.setText(Formatters.time(form.visitTime))
        binding.inputVillage.setText(form.village)

        binding.inputVisitDate.setOnClickListener { pickDate() }
        binding.fieldVisitDate.setEndIconOnClickListener { pickDate() }

        binding.inputVisitTime.setOnClickListener { pickTime() }
        binding.fieldVisitTime.setEndIconOnClickListener { pickTime() }

        binding.inputVillage.doAfterTextChanged { form.village = it?.toString().orEmpty() }
    }

    private fun pickDate() {
        val calendar = Calendar.getInstance()

        DatePickerDialog(
            this,
            { _, year, month, day ->
                form.visitDate = Formatters.isoFrom(year, month, day)
                binding.inputVisitDate.setText(Formatters.date(form.visitDate))
                binding.fieldVisitDate.error = null
            },
            calendar.get(Calendar.YEAR),
            calendar.get(Calendar.MONTH),
            calendar.get(Calendar.DAY_OF_MONTH),
        ).apply {
            // A visit cannot have happened in the future.
            datePicker.maxDate = System.currentTimeMillis()
        }.show()
    }

    private fun pickTime() {
        val calendar = Calendar.getInstance()

        TimePickerDialog(
            this,
            { _, hour, minute ->
                form.visitTime = Formatters.isoTimeFrom(hour, minute)
                binding.inputVisitTime.setText(Formatters.time(form.visitTime))
                binding.fieldVisitTime.error = null
            },
            calendar.get(Calendar.HOUR_OF_DAY),
            calendar.get(Calendar.MINUTE),
            false,
        ).show()
    }

    // =======================================================================
    // 2. Borrower information
    // =======================================================================

    /**
     * The identity fields the printed form asks for that the borrower record does not
     * already hold.
     *
     * Every one is optional. An agent who cannot get a date of birth out of somebody at
     * their own front door must still be able to file the visit that happened - the
     * alternative is a form that refuses the report, and then the visit is recorded
     * nowhere at all.
     */
    private fun setUpBorrower() {
        bindChoice(binding.inputGender, VisitFormData.GENDERS) { form.gender = it }
        bindDate(binding.inputDateOfBirth) { form.dateOfBirth = it }

        bindText(binding.inputPan) {
            form.panNumber = it
            binding.fieldPan.error = null
        }
        bindText(binding.inputAddrVillage) { form.addrVillage = it }
        bindText(binding.inputGramPanchayat) { form.gramPanchayat = it }
        bindText(binding.inputTehsil) { form.tehsil = it }
        bindText(binding.inputAddrDistrict) { form.addrDistrict = it }
        bindText(binding.inputState) { form.state = it }
        bindText(binding.inputPinCode) {
            form.pinCode = it
            binding.fieldPinCode.error = null
        }
    }

    // =======================================================================
    // 3. Loan account details
    // =======================================================================

    private fun setUpLoanDetails() {
        bindText(binding.inputCifNumber) { form.cifNumber = it }

        bindChoice(binding.inputLoanType, VisitFormData.LOAN_TYPES) {
            form.loanType = it
            binding.fieldLoanTypeOther.visibility =
                if (it == VisitFormData.LOAN_TYPE_OTHER) View.VISIBLE else View.GONE
        }
        bindText(binding.inputLoanTypeOther) {
            form.loanTypeOtherText = it
            binding.fieldLoanTypeOther.error = null
        }

        bindChoice(binding.inputAssetClassification, VisitFormData.ASSET_CLASSIFICATIONS) {
            form.assetClassification = it
        }

        bindDate(binding.inputSanctionDate) { form.sanctionDate = it }
        bindText(binding.inputSanctionLimit) {
            form.sanctionLimit = it
            binding.fieldSanctionLimit.error = null
        }
        bindText(binding.inputDrawingPower) {
            form.drawingPower = it
            binding.fieldDrawingPower.error = null
        }
        bindText(binding.inputInterestOverdue) {
            form.interestOverdue = it
            binding.fieldInterestOverdue.error = null
        }
    }

    // =======================================================================
    // 7. Documents verified
    // =======================================================================

    /**
     * What the borrower physically produced, asked on every case type.
     *
     * This checklist used to live inside the CKCC renewal section, so a recovery visit
     * had nowhere to record that an Aadhaar card was shown - and a renewal report showed
     * the same eleven boxes twice, once here and once there, free to disagree.
     */
    private fun setUpDocumentsVerified() {
        binding.checkDocAadhaar.setOnCheckedChangeListener { _, v -> form.docAadhaar = v }
        binding.checkDocPan.setOnCheckedChangeListener { _, v -> form.docPan = v }
        binding.checkDocPassbook.setOnCheckedChangeListener { _, v -> form.docPassbook = v }
        binding.checkDocLandRecord.setOnCheckedChangeListener { _, v -> form.docLandRecord = v }
        binding.checkDocKhatauni.setOnCheckedChangeListener { _, v -> form.docKhatauni = v }
        binding.checkDocElectricityBill.setOnCheckedChangeListener { _, v -> form.docElectricityBill = v }
        binding.checkDocPhotograph.setOnCheckedChangeListener { _, v -> form.docPhotograph = v }
        binding.checkDocMobileVerified.setOnCheckedChangeListener { _, v -> form.docMobileVerified = v }
        binding.checkDocRenewalForm.setOnCheckedChangeListener { _, v -> form.docRenewalForm = v }
        binding.checkDocOtsConsent.setOnCheckedChangeListener { _, v -> form.docOtsConsentLetter = v }
        binding.checkDocOthers.setOnCheckedChangeListener { _, v ->
            form.docOthers = v
            binding.groupDocOther.visibility = if (v) View.VISIBLE else View.GONE
        }
        bindText(binding.inputDocOtherText) {
            form.docOtherText = it
            binding.fieldDocOtherText.error = null
        }
    }

    // =======================================================================
    // 10. Evidence attached
    // =======================================================================

    /**
     * What the agent says this report carries.
     *
     * Not derived from the photographs actually attached, and that is the point: the
     * panel prints this list next to the real counts, so a report claiming a passbook
     * copy and carrying none is visible without opening the record.
     */
    private fun setUpEvidence() {
        binding.checkEvBorrowerPhoto.setOnCheckedChangeListener { _, v -> form.evBorrowerPhoto = v }
        binding.checkEvHousePhoto.setOnCheckedChangeListener { _, v -> form.evHousePhoto = v }
        binding.checkEvLandPhoto.setOnCheckedChangeListener { _, v -> form.evLandPhoto = v }
        binding.checkEvAadhaarCopy.setOnCheckedChangeListener { _, v -> form.evAadhaarCopy = v }
        binding.checkEvPassbookCopy.setOnCheckedChangeListener { _, v -> form.evPassbookCopy = v }
        binding.checkEvGpsLocation.setOnCheckedChangeListener { _, v -> form.evGpsLocation = v }
        binding.checkEvRenewalForm.setOnCheckedChangeListener { _, v -> form.evRenewalForm = v }
        binding.checkEvOtsConsent.setOnCheckedChangeListener { _, v -> form.evOtsConsent = v }
        binding.checkEvOthers.setOnCheckedChangeListener { _, v ->
            form.evOthers = v
            binding.groupEvOther.visibility = if (v) View.VISIBLE else View.GONE
        }
        bindText(binding.inputEvOtherText) {
            form.evOtherText = it
            binding.fieldEvOtherText.error = null
        }
    }

    // =======================================================================
    // 11. Declaration
    // =======================================================================

    /**
     * The RBI / Fair Practices Code declaration, and the one hard stop on this form.
     *
     * Everything else can be left blank and the report is still worth filing. A report
     * submitted by somebody who did not certify it is a different thing: the declaration
     * is printed in full on every copy, so an unticked box would put words in the
     * agent's mouth.
     */
    private fun setUpDeclaration() {
        binding.checkDeclaration.setOnCheckedChangeListener { _, checked ->
            form.declarationAccepted = checked
            if (checked) binding.textDeclarationError.visibility = View.GONE
        }
    }

    // =======================================================================
    // Customer contact
    // =======================================================================

    private fun setUpContact() {
        // Only one outcome can describe what happened at the door.
        val exclusive = listOf(
            binding.checkCustomerMet,
            binding.checkFamilyMet,
            binding.checkHouseLocked,
            binding.checkPhoneOff,
        )

        exclusive.forEach { box ->
            box.setOnCheckedChangeListener { button, checked ->
                if (checked) {
                    exclusive.filter { it != button }.forEach { it.isChecked = false }
                }
                syncContact()
            }
        }

        binding.checkPhoneContact.setOnCheckedChangeListener { _, _ -> syncContact() }

        binding.inputFamilyName.doAfterTextChanged {
            form.familyMemberName = it?.toString().orEmpty()
            binding.fieldFamilyName.error = null
        }
        binding.inputFamilyRelationship.doAfterTextChanged {
            form.familyMemberRelationship = it?.toString().orEmpty()
        }

        syncContact()
    }

    private fun syncContact() {
        form.customerMet = binding.checkCustomerMet.isChecked
        form.familyMemberMet = binding.checkFamilyMet.isChecked
        form.houseLocked = binding.checkHouseLocked.isChecked
        form.phoneSwitchedOff = binding.checkPhoneOff.isChecked
        form.phoneContact = binding.checkPhoneContact.isChecked

        // Family member details only make sense when a family member was met.
        binding.groupFamily.visibility =
            if (form.familyMemberMet) View.VISIBLE else View.GONE

        binding.textContactError.visibility = View.GONE
    }

    // =======================================================================
    // Physical verification
    // =======================================================================

    private fun setUpVerification() {
        binding.switchBorrowerAlive.isChecked = form.borrowerAlive
        binding.switchSameAddress.isChecked = form.sameAddress
        binding.switchShifted.isChecked = form.shifted

        binding.switchBorrowerAlive.setOnCheckedChangeListener { _, checked ->
            form.borrowerAlive = checked
        }
        binding.switchSameAddress.setOnCheckedChangeListener { _, checked ->
            form.sameAddress = checked
            // Not at the same address implies the borrower has shifted.
            if (!checked && !binding.switchShifted.isChecked) {
                binding.switchShifted.isChecked = true
            }
        }
        binding.switchShifted.setOnCheckedChangeListener { _, checked ->
            form.shifted = checked
        }

        // Both start unselected and stay unselected until the agent picks one, because
        // "not confirmed" is a claim about a check somebody ran and silence is not.
        bindChoice(
            binding.inputResidenceVerified,
            VisitFormData.RESIDENCE_VERIFICATION,
        ) { form.residenceVerified = it }
        bindChoice(
            binding.inputNeighbourVerification,
            VisitFormData.NEIGHBOUR_VERIFICATION,
        ) { form.neighbourVerification = it }

        // Occupation chips, built from the shared enum so app and server agree.
        VisitFormData.OCCUPATIONS.forEach { (value, label) ->
            val chip = com.google.android.material.chip.Chip(this).apply {
                text = label
                isCheckable = true
                setOnClickListener {
                    form.occupation = if (isChecked) value else ""
                    binding.groupOccupationOther.visibility =
                        if (form.occupation == VisitFormData.OCCUPATION_OTHERS) {
                            View.VISIBLE
                        } else {
                            View.GONE
                        }
                }
            }
            binding.chipGroupOccupation.addView(chip)
        }

        binding.inputOccupationOther.doAfterTextChanged {
            form.occupationOtherText = it?.toString().orEmpty()
            binding.fieldOccupationOther.error = null
        }
    }

    // =======================================================================
    // Recovery possibility
    // =======================================================================

    private fun setUpRecovery() {
        // Ready and not-ready are opposites.
        binding.checkReadyToPay.setOnCheckedChangeListener { _, checked ->
            form.readyToPay = checked
            if (checked) binding.checkNotReady.isChecked = false
        }
        binding.checkNotReady.setOnCheckedChangeListener { _, checked ->
            form.notReady = checked
            if (checked) binding.checkReadyToPay.isChecked = false
        }

        binding.checkInterestPayment.setOnCheckedChangeListener { _, checked ->
            form.interestPayment = checked
        }
        binding.checkOts.setOnCheckedChangeListener { _, checked -> form.ots = checked }

        binding.inputPromiseAmount.doAfterTextChanged {
            form.promiseAmount = it?.toString().orEmpty()
            binding.fieldPromiseAmount.error = null
            syncPromiseHint()
        }

        binding.inputPromiseDate.setOnClickListener { pickPromiseDate() }
        binding.fieldPromiseDate.setEndIconOnClickListener { pickPromiseDate() }

        syncPromiseHint()
    }

    private fun pickPromiseDate() {
        val calendar = Calendar.getInstance()

        DatePickerDialog(
            this,
            { _, year, month, day ->
                form.promiseDate = Formatters.isoFrom(year, month, day)
                binding.inputPromiseDate.setText(Formatters.date(form.promiseDate))
                binding.fieldPromiseDate.error = null
                syncPromiseHint()
            },
            calendar.get(Calendar.YEAR),
            calendar.get(Calendar.MONTH),
            calendar.get(Calendar.DAY_OF_MONTH),
        ).apply {
            // A promise is a future commitment.
            datePicker.minDate = System.currentTimeMillis() - DAY_MS
        }.show()
    }

    /** Warns while only one half of a promise has been entered. */
    private fun syncPromiseHint() {
        val amount = form.promiseAmountValue()
        val hasAmount = amount != null && amount > 0.0
        val hasDate = form.promiseDate.isNotBlank()

        binding.textPromiseHint.visibility =
            if (hasAmount != hasDate) View.VISIBLE else View.GONE
    }

    // =======================================================================
    // Non-payment reason
    // =======================================================================

    private fun setUpReasons() {
        binding.checkReasonFinancial.setOnCheckedChangeListener { _, c -> form.reasonFinancialProblem = c }
        binding.checkReasonCrop.setOnCheckedChangeListener { _, c -> form.reasonCropLoss = c }
        binding.checkReasonAnimal.setOnCheckedChangeListener { _, c -> form.reasonAnimalLoss = c }
        binding.checkReasonIllness.setOnCheckedChangeListener { _, c -> form.reasonIllness = c }
        binding.checkReasonUnemployment.setOnCheckedChangeListener { _, c -> form.reasonUnemployment = c }
        binding.checkReasonDispute.setOnCheckedChangeListener { _, c -> form.reasonDispute = c }
        binding.checkReasonOtherLoan.setOnCheckedChangeListener { _, c -> form.reasonOtherLoan = c }

        binding.checkReasonOthers.setOnCheckedChangeListener { _, checked ->
            form.reasonOthers = checked
            binding.groupReasonOther.visibility = if (checked) View.VISIBLE else View.GONE
        }

        binding.inputReasonOther.doAfterTextChanged {
            form.reasonOtherText = it?.toString().orEmpty()
            binding.fieldReasonOther.error = null
        }
    }

    // =======================================================================
    // Agent recommendation
    // =======================================================================

    private fun setUpRecommendations() {
        binding.checkRecRecovery.setOnCheckedChangeListener { _, c -> form.recRecoveryPossible = c }
        binding.checkRecFollowup.setOnCheckedChangeListener { _, c -> form.recRegularFollowup = c }
        binding.checkRecLegal.setOnCheckedChangeListener { _, c -> form.recLegalAction = c }
        binding.checkRecRc.setOnCheckedChangeListener { _, c -> form.recRc = c }
        binding.checkRecOts.setOnCheckedChangeListener { _, c -> form.recOts = c }

        binding.checkRecOthers.setOnCheckedChangeListener { _, checked ->
            form.recOthers = checked
            binding.groupRecOther.visibility = if (checked) View.VISIBLE else View.GONE
        }

        binding.inputRecOther.doAfterTextChanged {
            form.recOtherText = it?.toString().orEmpty()
            binding.fieldRecOther.error = null
        }

        bindText(binding.inputGeneralRecommendation) { form.generalRecommendation = it }

        binding.inputRemarks.doAfterTextChanged { form.remarks = it?.toString().orEmpty() }
    }

    // =======================================================================
    // Documents
    // =======================================================================

    private fun setUpAttachments() {
        binding.buttonPhotos.setOnClickListener {
            photoLauncher.launch(
                PhotoUploadActivity.intent(
                    context = this,
                    customerPhoto = form.customerPhoto?.absolutePath,
                    housePhoto = form.housePhoto?.absolutePath,
                    aadhaarPhoto = form.aadhaarPhoto?.absolutePath,
                    agentPhoto = form.agentPhoto?.absolutePath,
                    otherDocs = form.otherDocuments.map { it.absolutePath },
                ),
            )
        }

        renderPhotoState()
    }

    private fun renderPhotoState() {
        val count = form.photoFiles().size + form.otherDocuments.size

        binding.textPhotoState.text = if (count == 0) {
            getString(R.string.visit_none_attached)
        } else {
            resources.getQuantityString(R.plurals.photo_count, count, count)
        }
    }

    // =======================================================================
    // Submission
    // =======================================================================

    private fun submit() {
        if (submitting) return

        val errors = form.validate()
        if (errors.isNotEmpty()) {
            showValidationErrors(errors)
            return
        }

        // Read at submit time, not when the form opened. A form left open for half
        // an hour while the agent walks between houses would otherwise file the
        // position of the previous doorstep.
        GeoStamp.current(this).let { stamp ->
            form.gpsSource = stamp.wire
            form.gpsLatitude = stamp.fix?.latitude
            form.gpsLongitude = stamp.fix?.longitude
            form.gpsAccuracyMetres = stamp.fix?.accuracyMetres
            form.gpsCapturedAt = stamp.fix?.capturedAt ?: ""
        }

        submitting = true
        setSubmitting(true)

        lifecycleScope.launch {
            val result = repository.submitVisit(form)

            if (result is ApiResult.Success) {
                // Filing a visit is reporting. rollUpDay() counts report_submitted the
                // same way, so the reminder must agree with it - otherwise an agent who
                // spent the day on visits still gets told they have not reported.
                //
                // Goes through the scheduler rather than setting the date directly: the
                // reminder now REPEATS until the report is in, so recording the date
                // without also clearing the notification and rebooking the daily alarm
                // would leave it nudging somebody who has already done the work.
                ReportReminderScheduler.markReportSubmitted(
                    this@VisitReportActivity,
                    session,
                    Formatters.todayIso(),
                )
            }

            submitting = false
            setSubmitting(false)

            when (result) {
                is ApiResult.Success -> {
                    val payload = result.data

                    // Cached photos are no longer needed once the report is on the
                    // server.
                    FileStore.clearWorkingFiles(this@VisitReportActivity)

                    val message = when {
                        payload.duplicate -> "This visit was already submitted."
                        payload.warnings.isNotEmpty() -> payload.warnings.joinToString(" ")
                        else -> getString(R.string.visit_submitted)
                    }

                    AlertDialog.Builder(this@VisitReportActivity)
                        .setTitle(R.string.visit_submitted)
                        .setMessage(message)
                        .setCancelable(false)
                        .setPositiveButton(R.string.action_ok) { _, _ ->
                            setResult(RESULT_OK)
                            finish()
                        }
                        .show()
                }

                is ApiResult.Failure -> {
                    // Map server field errors back onto the form.
                    result.fieldErrors.forEach { (field, messages) ->
                        applyServerFieldError(field, messages.firstOrNull())
                    }
                    if (result.fieldErrors.isEmpty()) {
                        showMessage(result.message, binding.root)
                    } else {
                        showMessage(result.message, binding.root)
                    }
                }

                else -> handleFailure(result, binding.root)
            }
        }
    }

    private fun showValidationErrors(errors: Map<String, String>) {
        errors["visit_date"]?.let { binding.fieldVisitDate.error = it }
        errors["visit_time"]?.let { binding.fieldVisitTime.error = it }
        errors["family_member_name"]?.let { binding.fieldFamilyName.error = it }
        errors["promise_amount"]?.let { binding.fieldPromiseAmount.error = it }
        errors["promise_date"]?.let { binding.fieldPromiseDate.error = it }
        errors["reason_other_text"]?.let { binding.fieldReasonOther.error = it }
        errors["rec_other_text"]?.let { binding.fieldRecOther.error = it }
        errors["occupation_other_text"]?.let { binding.fieldOccupationOther.error = it }
        errors["pan_number"]?.let { binding.fieldPan.error = it }
        errors["pin_code"]?.let { binding.fieldPinCode.error = it }
        errors["loan_type_other_text"]?.let { binding.fieldLoanTypeOther.error = it }
        errors["sanction_limit"]?.let { binding.fieldSanctionLimit.error = it }
        errors["drawing_power"]?.let { binding.fieldDrawingPower.error = it }
        errors["interest_overdue"]?.let { binding.fieldInterestOverdue.error = it }
        errors["doc_other_text"]?.let { binding.fieldDocOtherText.error = it }
        errors["ev_other_text"]?.let { binding.fieldEvOtherText.error = it }
        errors["ots_scheme_other_text"]?.let { binding.sectionOts.fieldOtsSchemeOther.error = it }

        errors["contact"]?.let { message ->
            binding.textContactError.text = message
            binding.textContactError.visibility = View.VISIBLE
        }

        errors["declaration_accepted"]?.let { binding.textDeclarationError.visibility = View.VISIBLE }

        // Scroll to the first problem so the agent is not left hunting for it. Ordered
        // the way the form is, so the agent is taken to the earliest thing that is wrong
        // rather than to whichever error the map happened to yield first.
        binding.scrollView.post {
            val target: View = when {
                errors.containsKey("visit_date") || errors.containsKey("visit_time") -> binding.cardGeneral
                errors.containsKey("pan_number") || errors.containsKey("pin_code") -> binding.cardBorrower
                errors.containsKey("loan_type_other_text") || errors.containsKey("sanction_limit")
                    || errors.containsKey("drawing_power")
                    || errors.containsKey("interest_overdue") -> binding.cardLoanDetails
                errors.containsKey("contact") || errors.containsKey("family_member_name") -> binding.cardContact
                errors.containsKey("occupation_other_text") -> binding.cardVerification
                errors.containsKey("doc_other_text") -> binding.cardDocumentsVerified
                errors.containsKey("promise_amount") || errors.containsKey("promise_date") -> binding.cardRecovery
                errors.containsKey("reason_other_text") -> binding.cardReasons
                errors.containsKey("ev_other_text") -> binding.cardEvidence
                // Last, because it is the last thing on the form and the most likely
                // single reason a completed report will not submit.
                errors.containsKey("declaration_accepted") -> binding.cardDeclaration
                else -> binding.cardRecommendations
            }
            binding.scrollView.smoothScrollTo(0, target.top)
        }

        showMessage(errors.values.first(), binding.root)
    }

    private fun applyServerFieldError(field: String, message: String?) {
        if (message == null) return

        when (field) {
            "visit_date" -> binding.fieldVisitDate.error = message
            "visit_time" -> binding.fieldVisitTime.error = message
            "promise_amount" -> binding.fieldPromiseAmount.error = message
            "promise_date" -> binding.fieldPromiseDate.error = message
            "remarks" -> binding.fieldRemarks.error = message
            "pan_number" -> binding.fieldPan.error = message
            "pin_code" -> binding.fieldPinCode.error = message
            "general_recommendation" -> binding.fieldGeneralRecommendation.error = message
        }
    }

    private fun setSubmitting(loading: Boolean) {
        binding.progress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.buttonSubmit.isEnabled = !loading
        binding.buttonSubmit.text = getString(
            if (loading) R.string.visit_submitting else R.string.visit_submit,
        )
    }

    private fun confirmDiscard() {
        if (!form.hasUnsavedInput()) {
            finish()
            return
        }

        AlertDialog.Builder(this)
            .setTitle(R.string.visit_discard_title)
            .setMessage(R.string.visit_discard_message)
            .setNegativeButton(R.string.visit_keep_editing, null)
            .setPositiveButton(R.string.visit_discard_confirm) { _, _ ->
                FileStore.clearWorkingFiles(this)
                finish()
            }
            .show()
    }

    companion object {
        private const val EXTRA_LEAD_ID = "lead_id"
        private const val EXTRA_CUSTOMER_NAME = "customer_name"
        private const val EXTRA_VILLAGE = "village"
        private const val EXTRA_LOAN_TYPE = "loan_type"
        private const val EXTRA_OUTSTANDING = "outstanding"

        private const val DAY_MS = 86_400_000L

        fun intent(
            context: Context,
            leadId: Int,
            customerName: String?,
            village: String?,
            // Used only to suggest the CKCC renewal form for a CKCC account. The
            // agent always makes the final choice.
            loanType: String? = null,
            // Seeds the residual-balance field on a settlement report.
            outstanding: Double = 0.0,
        ): Intent = Intent(context, VisitReportActivity::class.java).apply {
            putExtra(EXTRA_LEAD_ID, leadId)
            putExtra(EXTRA_CUSTOMER_NAME, customerName)
            putExtra(EXTRA_VILLAGE, village)
            putExtra(EXTRA_LOAN_TYPE, loanType)
            putExtra(EXTRA_OUTSTANDING, outstanding)
        }
    }
}
