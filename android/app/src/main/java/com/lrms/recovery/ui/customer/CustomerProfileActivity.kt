package com.lrms.recovery.ui.customer

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.remote.CustomerProfilePayload
import com.lrms.recovery.databinding.ActivityCustomerProfileBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.history.VisitHistoryActivity
import com.lrms.recovery.ui.visit.VisitDetailActivity
import com.lrms.recovery.ui.visit.VisitReportActivity
import com.lrms.recovery.util.Formatters
import kotlinx.coroutines.launch

/**
 * Customer profile: loan details, promise history, visit history, photos,
 * signatures and the append-only timeline.
 *
 * This is where an agent starts a visit report from, so the primary action is
 * kept visible at the bottom rather than buried in a menu.
 */
class CustomerProfileActivity : BaseActivity() {

    private lateinit var binding: ActivityCustomerProfileBinding

    private val leadId: Int by lazy { intent.getIntExtra(EXTRA_LEAD_ID, 0) }

    private var payload: CustomerProfilePayload? = null

    private lateinit var timelineAdapter: TimelineAdapter
    private lateinit var promiseAdapter: PromiseAdapter
    private lateinit var mediaAdapter: MediaAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityCustomerProfileBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { finish() }

        if (leadId <= 0) {
            showMessage(R.string.error_unknown, binding.root)
            finish()
            return
        }

        timelineAdapter = TimelineAdapter { event ->
            event.visitReportId?.let {
                startActivity(VisitDetailActivity.intent(this, it))
            }
        }
        binding.recyclerTimeline.apply {
            layoutManager = LinearLayoutManager(this@CustomerProfileActivity)
            adapter = timelineAdapter
            isNestedScrollingEnabled = false
        }

        promiseAdapter = PromiseAdapter()
        binding.recyclerPromises.apply {
            layoutManager = LinearLayoutManager(this@CustomerProfileActivity)
            adapter = promiseAdapter
            isNestedScrollingEnabled = false
        }

        mediaAdapter = MediaAdapter(session)
        binding.recyclerPhotos.apply {
            layoutManager = LinearLayoutManager(
                this@CustomerProfileActivity,
                LinearLayoutManager.HORIZONTAL,
                false,
            )
            adapter = mediaAdapter
        }

        binding.swipeRefresh.setOnRefreshListener { load() }

        binding.buttonNewVisit.setOnClickListener { startVisitReport() }
        binding.buttonCall.setOnClickListener { dial() }
        binding.buttonFullHistory.setOnClickListener {
            startActivity(VisitHistoryActivity.intent(this, leadId))
        }

        load()
    }

    override fun onResume() {
        super.onResume()
        // Returning from a submitted visit must show the new state immediately.
        if (payload != null) {
            load(silent = true)
        }
    }

    private fun load(silent: Boolean = false) {
        if (!silent && payload == null) {
            binding.progress.visibility = View.VISIBLE
        }

        lifecycleScope.launch {
            val result = repository.customerProfile(leadId)

            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false

            when (result) {
                is ApiResult.Success -> {
                    payload = result.data
                    render(result.data)
                    binding.content.visibility = View.VISIBLE
                }

                else -> {
                    if (handleFailure(result, binding.root)) return@launch
                    if (payload == null) {
                        binding.groupError.visibility = View.VISIBLE
                        binding.textError.text = result.errorMessage(getString(R.string.error_unknown))
                    }
                }
            }
        }
    }

    private fun render(data: CustomerProfilePayload) {
        val lead = data.lead ?: return

        binding.toolbar.title = lead.customerName
        binding.toolbar.subtitle = lead.loanAccountNumber

        // ---- Borrower ----
        binding.textCustomerName.text = lead.customerName
        binding.textFatherName.text = Formatters.orDash(lead.fatherHusbandName)
        binding.textMobile.text = Formatters.orDash(lead.mobile ?: lead.mobileMasked)
        binding.textAadhaar.text = if (lead.aadhaar != null) {
            Formatters.aadhaar(lead.aadhaar)
        } else {
            Formatters.orDash(lead.aadhaarMasked)
        }
        binding.textVillage.text = Formatters.orDash(lead.village)
        binding.textAddress.text = Formatters.orDash(lead.address)

        // ---- Loan ----
        binding.textAccountNumber.text = lead.loanAccountNumber
        binding.textLoanType.text = Formatters.orDash(lead.loanType)
        binding.textOutstanding.text = Formatters.rupees(lead.outstandingAmount)
        binding.textOverdue.text = Formatters.rupees(lead.overdueAmount)
        binding.textNpaDate.text = if (lead.npaDate != null) {
            Formatters.date(lead.npaDate)
        } else {
            getString(R.string.not_available)
        }
        binding.textBranch.text = lead.branchName
        binding.textBcCode.text = Formatters.orDash(lead.bcCode)
        binding.textVisitCount.text = resources.getQuantityString(
            R.plurals.visit_count, lead.visitCount, lead.visitCount,
        )

        binding.chipStatus.text = Formatters.statusLabel(lead.currentStatus)
        binding.textNpaBadge.visibility = if (lead.isNpa) View.VISIBLE else View.GONE

        binding.buttonCall.visibility = if (lead.mobile.isNullOrBlank()) View.GONE else View.VISIBLE

        // A closed lead should not invite another visit report.
        val closed = lead.currentStatus == "closed"
        binding.buttonNewVisit.isEnabled = !closed
        binding.buttonNewVisit.text = getString(
            if (closed) R.string.leads_filter_closed else R.string.profile_new_visit,
        )

        // ---- Promises ----
        promiseAdapter.submitList(data.promises)
        binding.textNoPromises.visibility = if (data.promises.isEmpty()) View.VISIBLE else View.GONE
        binding.recyclerPromises.visibility = if (data.promises.isEmpty()) View.GONE else View.VISIBLE

        // ---- Timeline (capped here; the full list is on the history screen) ----
        val timeline = data.timeline.take(TIMELINE_PREVIEW)
        timelineAdapter.submitList(timeline)
        binding.textNoTimeline.visibility = if (timeline.isEmpty()) View.VISIBLE else View.GONE
        binding.buttonFullHistory.visibility =
            if (data.timeline.size > TIMELINE_PREVIEW) View.VISIBLE else View.GONE

        // ---- Media ----
        val media = data.photos + data.signatures
        mediaAdapter.submitList(media)
        binding.groupPhotos.visibility = if (media.isEmpty()) View.GONE else View.VISIBLE
        binding.textPhotoCount.text = resources.getQuantityString(
            R.plurals.photo_count, data.photos.size, data.photos.size,
        )

        // ---- Other accounts ----
        val others = data.otherAccounts.filter { it.id != lead.id }
        binding.groupOtherAccounts.visibility = if (others.isEmpty()) View.GONE else View.VISIBLE
        binding.textOtherAccounts.text = others.joinToString("\n") { other ->
            "${other.loanAccountNumber} · ${Formatters.orDash(other.loanType)} · " +
                Formatters.rupees(other.outstandingAmount, decimals = false)
        }
    }

    private fun dial() {
        val number = payload?.lead?.mobile
        if (number.isNullOrBlank()) {
            showMessage(R.string.not_available, binding.root)
            return
        }

        try {
            startActivity(
                Intent(Intent.ACTION_DIAL, android.net.Uri.parse("tel:$number")),
            )
        } catch (error: Exception) {
            showMessage("No dialler app is available on this device.", binding.root)
        }
    }

    private fun startVisitReport() {
        val lead = payload?.lead ?: return
        startActivity(VisitReportActivity.intent(this, lead.id, lead.customerName, lead.village))
    }

    companion object {
        private const val EXTRA_LEAD_ID = "lead_id"
        private const val TIMELINE_PREVIEW = 8

        fun intent(context: Context, leadId: Int): Intent =
            Intent(context, CustomerProfileActivity::class.java).apply {
                putExtra(EXTRA_LEAD_ID, leadId)
            }
    }
}
