package com.lrms.recovery.ui.visit

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.remote.VisitReportDto
import com.lrms.recovery.databinding.ActivityVisitDetailBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.customer.MediaAdapter
import com.lrms.recovery.util.Formatters
import kotlinx.coroutines.launch

/**
 * A single submitted visit report, read-only.
 *
 * Renders the snapshot stored with the report rather than the customer's current
 * details, so a historical report always reads exactly as it was signed.
 */
class VisitDetailActivity : BaseActivity() {

    private lateinit var binding: ActivityVisitDetailBinding

    private val visitId: Int by lazy { intent.getIntExtra(EXTRA_VISIT_ID, 0) }

    private lateinit var mediaAdapter: MediaAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityVisitDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { finish() }

        if (visitId <= 0) {
            finish()
            return
        }

        mediaAdapter = MediaAdapter(session)
        binding.recyclerMedia.apply {
            layoutManager = LinearLayoutManager(
                this@VisitDetailActivity,
                LinearLayoutManager.HORIZONTAL,
                false,
            )
            adapter = mediaAdapter
        }

        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            val result = repository.visitDetail(visitId)
            binding.progress.visibility = View.GONE

            when (result) {
                is ApiResult.Success -> {
                    val report = result.data.report
                    if (report == null) {
                        showMessage(R.string.error_unknown, binding.root)
                        return@launch
                    }

                    render(report)

                    val media = result.data.photos + result.data.signatures
                    mediaAdapter.submitList(media)
                    binding.groupMedia.visibility = if (media.isEmpty()) View.GONE else View.VISIBLE

                    binding.content.visibility = View.VISIBLE
                }

                else -> handleFailure(result, binding.root)
            }
        }
    }

    private fun render(report: VisitReportDto) {
        // ---- General ----
        report.general?.let { general ->
            binding.toolbar.subtitle = Formatters.date(general.visitDate)
            binding.textVisitDate.text = Formatters.date(general.visitDate)
            binding.textVisitTime.text = Formatters.time(general.visitTime)
            binding.textBcCode.text = Formatters.orDash(general.bcCode)
            binding.textBranch.text = general.branch
            binding.textAgent.text = general.agentName
            binding.textVillage.text = Formatters.orDash(general.village)
        }

        // ---- Borrower (snapshot at the time of the visit) ----
        report.borrower?.let { borrower ->
            binding.textCustomerName.text = borrower.customerName
            binding.textFatherName.text = Formatters.orDash(borrower.fatherHusbandName)
            binding.textMobile.text = Formatters.orDash(borrower.mobile ?: borrower.mobileMasked)
            binding.textAadhaar.text = if (borrower.aadhaar != null) {
                Formatters.aadhaar(borrower.aadhaar)
            } else {
                Formatters.orDash(borrower.aadhaarMasked)
            }
            binding.textAddress.text = Formatters.orDash(borrower.address)
        }

        // ---- Loan (snapshot) ----
        report.loan?.let { loan ->
            binding.textAccountNumber.text = loan.loanAccountNumber
            binding.textLoanType.text = Formatters.orDash(loan.loanType)
            binding.textOutstanding.text = Formatters.rupees(loan.outstandingAmount)
            binding.textOverdue.text = Formatters.rupees(loan.overdueAmount)
            binding.textNpaDate.text = if (loan.npaDate != null) {
                Formatters.date(loan.npaDate)
            } else {
                getString(R.string.not_available)
            }
        }

        // ---- Contact ----
        report.contact?.let { contact ->
            binding.textContact.text = buildList {
                if (contact.customerMet) add(getString(R.string.visit_customer_met))
                if (contact.familyMemberMet) add(getString(R.string.visit_family_met))
                if (contact.houseLocked) add(getString(R.string.visit_house_locked))
                if (contact.phoneContact) add(getString(R.string.visit_phone_contact))
                if (contact.phoneSwitchedOff) add(getString(R.string.visit_phone_off))
            }.joinToString(", ").ifBlank { getString(R.string.not_available) }

            val family = listOfNotNull(
                contact.familyMemberName?.takeIf { it.isNotBlank() },
                contact.familyMemberRelationship?.takeIf { it.isNotBlank() },
            ).joinToString(" · ")

            binding.textFamilyMember.text = family
            binding.textFamilyMember.visibility = if (family.isBlank()) View.GONE else View.VISIBLE
        }

        // ---- Verification ----
        report.verification?.let { verification ->
            binding.textBorrowerAlive.text = yesNo(verification.borrowerAlive)
            binding.textSameAddress.text = yesNo(verification.sameAddress)
            binding.textShifted.text = yesNo(verification.shifted)
            binding.textOccupation.text = Formatters.occupationLabel(verification.occupation)
        }

        // ---- Recovery ----
        report.recovery?.let { recovery ->
            binding.textRecovery.text = buildList {
                if (recovery.readyToPay) add(getString(R.string.visit_ready_to_pay))
                if (recovery.notReady) add(getString(R.string.visit_not_ready))
                if (recovery.interestPayment) add(getString(R.string.visit_interest_payment))
                if (recovery.ots) add(getString(R.string.visit_ots))
            }.joinToString(", ").ifBlank { getString(R.string.not_available) }

            val hasPromise = recovery.promiseAmount != null && recovery.promiseAmount > 0.0
            binding.groupPromise.visibility = if (hasPromise) View.VISIBLE else View.GONE

            if (hasPromise) {
                binding.textPromiseAmount.text = Formatters.rupees(recovery.promiseAmount)
                binding.textPromiseDate.text = Formatters.date(recovery.promiseDate)
            }
        }

        // ---- Non-payment reasons ----
        report.nonPaymentReason?.let { reason ->
            val reasons = buildList {
                if (reason.financialProblem) add(getString(R.string.visit_reason_financial))
                if (reason.cropLoss) add(getString(R.string.visit_reason_crop))
                if (reason.animalLoss) add(getString(R.string.visit_reason_animal))
                if (reason.illness) add(getString(R.string.visit_reason_illness))
                if (reason.unemployment) add(getString(R.string.visit_reason_unemployment))
                if (reason.dispute) add(getString(R.string.visit_reason_dispute))
                if (reason.otherLoan) add(getString(R.string.visit_reason_other_loan))
                reason.otherText?.takeIf { it.isNotBlank() }?.let { add(it) }
            }

            binding.textReasons.text =
                reasons.joinToString(", ").ifBlank { getString(R.string.not_available) }
        }

        // ---- Recommendation ----
        report.recommendation?.let { recommendation ->
            val items = buildList {
                if (recommendation.recoveryPossible) add(getString(R.string.visit_rec_recovery_possible))
                if (recommendation.regularFollowup) add(getString(R.string.visit_rec_followup))
                if (recommendation.legalAction) add(getString(R.string.visit_rec_legal))
                if (recommendation.rc) add(getString(R.string.visit_rec_rc))
                if (recommendation.ots) add(getString(R.string.visit_rec_ots))
                recommendation.otherText?.takeIf { it.isNotBlank() }?.let { add(it) }
            }

            binding.textRecommendations.text =
                items.joinToString(", ").ifBlank { getString(R.string.not_available) }
        }

        // ---- Remarks ----
        binding.textRemarks.text = report.remarks?.takeIf { it.isNotBlank() }
            ?: getString(R.string.not_available)

        // ---- Provenance ----
        binding.textSubmitted.text = getString(
            R.string.visit_submitted_from,
            report.source,
            report.appVersion ?: "-",
            Formatters.dateTime(report.createdAt),
        )
    }

    private fun yesNo(value: Boolean): String =
        getString(if (value) R.string.yes else R.string.no)

    companion object {
        private const val EXTRA_VISIT_ID = "visit_id"

        fun intent(context: Context, visitId: Int): Intent =
            Intent(context, VisitDetailActivity::class.java).apply {
                putExtra(EXTRA_VISIT_ID, visitId)
            }
    }
}
