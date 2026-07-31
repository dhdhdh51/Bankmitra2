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
import com.lrms.recovery.ui.photo.PhotoUploadActivity
import com.lrms.recovery.ui.signature.SignatureActivity
import com.lrms.recovery.util.FileStore
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

    private val signatureLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        if (result.resultCode != RESULT_OK) return@registerForActivityResult

        val path = result.data?.getStringExtra(SignatureActivity.EXTRA_RESULT_PATH)
        val type = result.data?.getStringExtra(SignatureActivity.EXTRA_TYPE)

        if (path.isNullOrBlank()) return@registerForActivityResult
        val file = File(path)

        when (type) {
            SignatureActivity.TYPE_AGENT -> form.agentSignature = file
            else -> form.customerSignature = file
        }

        renderSignatureState()
    }

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
        data.getStringArrayListExtra(PhotoUploadActivity.RESULT_OTHER_DOCS)?.let { paths ->
            form.otherDocuments = paths.map { File(it) }.toMutableList()
        }

        renderPhotoState()
    }

    // -----------------------------------------------------------------------

    private var leadLoanType: String = ""

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

        setUpReportType()
        setUpGeneral()
        setUpContact()
        setUpVerification()
        setUpRecovery()
        setUpReasons()
        setUpRecommendations()
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
        }

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
        bindText(c.inputCkccDocOtherText) { form.ckccDocOtherText = it }
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

        c.checkCkccDocAadhaar.setOnCheckedChangeListener { _, v -> form.ckccDocAadhaar = v }
        c.checkCkccDocPan.setOnCheckedChangeListener { _, v -> form.ckccDocPan = v }
        c.checkCkccDocPassbook.setOnCheckedChangeListener { _, v -> form.ckccDocPassbook = v }
        c.checkCkccDocLandRecord.setOnCheckedChangeListener { _, v -> form.ckccDocLandRecord = v }
        c.checkCkccDocKhasra.setOnCheckedChangeListener { _, v -> form.ckccDocKhasraKhatauni = v }
        c.checkCkccDocPhotograph.setOnCheckedChangeListener { _, v -> form.ckccDocPhotograph = v }
        c.checkCkccDocMobile.setOnCheckedChangeListener { _, v -> form.ckccDocMobileAvailable = v }
        c.checkCkccDocOther.setOnCheckedChangeListener { _, v ->
            form.ckccDocOthers = v
            c.fieldCkccDocOtherText.visibility = if (v) View.VISIBLE else View.GONE
        }

        c.checkCkccWilling.setOnCheckedChangeListener { _, v -> form.ckccWillingToRenew = v }
        c.checkCkccDocsHanded.setOnCheckedChangeListener { _, v -> form.ckccDocumentsHandedOver = v }
        c.checkCkccFormSigned.setOnCheckedChangeListener { _, v -> form.ckccRenewalFormSigned = v }
        c.checkCkccEkyc.setOnCheckedChangeListener { _, v -> form.ckccEkycCompleted = v }
        c.checkCkccBiometrics.setOnCheckedChangeListener { _, v -> form.ckccBiometricsCompleted = v }

        c.checkCkccRecRenewNow.setOnCheckedChangeListener { _, v -> form.ckccRecRenewImmediately = v }
        c.checkCkccRecDocsSubmitted.setOnCheckedChangeListener { _, v -> form.ckccRecDocumentsSubmitted = v }
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

        binding.inputRemarks.doAfterTextChanged { form.remarks = it?.toString().orEmpty() }
    }

    // =======================================================================
    // Documents & signatures
    // =======================================================================

    private fun setUpAttachments() {
        binding.buttonPhotos.setOnClickListener {
            photoLauncher.launch(
                PhotoUploadActivity.intent(
                    context = this,
                    customerPhoto = form.customerPhoto?.absolutePath,
                    housePhoto = form.housePhoto?.absolutePath,
                    aadhaarPhoto = form.aadhaarPhoto?.absolutePath,
                    otherDocs = form.otherDocuments.map { it.absolutePath },
                ),
            )
        }

        binding.buttonCustomerSignature.setOnClickListener {
            signatureLauncher.launch(
                SignatureActivity.intent(
                    this,
                    SignatureActivity.TYPE_CUSTOMER,
                    intent.getStringExtra(EXTRA_CUSTOMER_NAME),
                ),
            )
        }

        binding.buttonAgentSignature.setOnClickListener {
            signatureLauncher.launch(
                SignatureActivity.intent(
                    this,
                    SignatureActivity.TYPE_AGENT,
                    session.user?.name,
                ),
            )
        }

        renderPhotoState()
        renderSignatureState()
    }

    private fun renderPhotoState() {
        val count = form.photoFiles().size + form.otherDocuments.size

        binding.textPhotoState.text = if (count == 0) {
            getString(R.string.visit_signature_not_captured)
        } else {
            resources.getQuantityString(R.plurals.photo_count, count, count)
        }
    }

    private fun renderSignatureState() {
        binding.textCustomerSignatureState.text = getString(
            if (form.customerSignature != null) {
                R.string.visit_signature_captured
            } else {
                R.string.visit_signature_not_captured
            },
        )
        binding.textAgentSignatureState.text = getString(
            if (form.agentSignature != null) {
                R.string.visit_signature_captured
            } else {
                R.string.visit_signature_not_captured
            },
        )

        binding.buttonCustomerSignature.text = getString(
            if (form.customerSignature != null) R.string.visit_recapture else R.string.visit_capture_signature,
        )
        binding.buttonAgentSignature.text = getString(
            if (form.agentSignature != null) R.string.visit_recapture else R.string.visit_capture_signature,
        )
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

        submitting = true
        setSubmitting(true)

        lifecycleScope.launch {
            val result = repository.submitVisit(form)

            submitting = false
            setSubmitting(false)

            when (result) {
                is ApiResult.Success -> {
                    val payload = result.data

                    // Cached photos and signatures are no longer needed once the
                    // report is on the server.
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

        errors["contact"]?.let { message ->
            binding.textContactError.text = message
            binding.textContactError.visibility = View.VISIBLE
        }

        // Scroll to the first problem so the agent is not left hunting for it.
        binding.scrollView.post {
            val target: View = when {
                errors.containsKey("visit_date") || errors.containsKey("visit_time") -> binding.cardGeneral
                errors.containsKey("contact") || errors.containsKey("family_member_name") -> binding.cardContact
                errors.containsKey("occupation_other_text") -> binding.cardVerification
                errors.containsKey("promise_amount") || errors.containsKey("promise_date") -> binding.cardRecovery
                errors.containsKey("reason_other_text") -> binding.cardReasons
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

        private const val DAY_MS = 86_400_000L

        fun intent(
            context: Context,
            leadId: Int,
            customerName: String?,
            village: String?,
            // Used only to suggest the CKCC renewal form for a CKCC account. The
            // agent always makes the final choice.
            loanType: String? = null,
        ): Intent = Intent(context, VisitReportActivity::class.java).apply {
            putExtra(EXTRA_LEAD_ID, leadId)
            putExtra(EXTRA_CUSTOMER_NAME, customerName)
            putExtra(EXTRA_VILLAGE, village)
            putExtra(EXTRA_LOAN_TYPE, loanType)
        }
    }
}
