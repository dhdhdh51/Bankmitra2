package com.lrms.recovery.ui.history

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.ActivityVisitHistoryBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.customer.TimelineAdapter
import com.lrms.recovery.ui.visit.VisitDetailActivity
import kotlinx.coroutines.launch

/**
 * Read-only visit history for one loan account, newest first.
 *
 * Reports are append-only, so there is no edit or delete affordance anywhere on
 * this screen - the notice at the top says so explicitly, because an agent who
 * spots a mistake needs to know the fix is a new visit, not an edit.
 */
class VisitHistoryActivity : BaseActivity() {

    private lateinit var binding: ActivityVisitHistoryBinding

    private val leadId: Int by lazy { intent.getIntExtra(EXTRA_LEAD_ID, 0) }

    private lateinit var timelineAdapter: TimelineAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityVisitHistoryBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { finish() }

        if (leadId <= 0) {
            finish()
            return
        }

        timelineAdapter = TimelineAdapter { event ->
            event.visitReportId?.let {
                startActivity(VisitDetailActivity.intent(this, it))
            }
        }

        binding.recyclerTimeline.apply {
            layoutManager = LinearLayoutManager(this@VisitHistoryActivity)
            adapter = timelineAdapter
        }

        binding.swipeRefresh.setOnRefreshListener { load() }

        load()
    }

    private fun load() {
        binding.progress.visibility =
            if (timelineAdapter.itemCount == 0) View.VISIBLE else View.GONE

        lifecycleScope.launch {
            val result = repository.customerHistory(leadId)

            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false

            when (result) {
                is ApiResult.Success -> {
                    val data = result.data

                    binding.toolbar.subtitle = data.loanAccountNumber
                    binding.textCustomerName.text = data.customerName
                    binding.textVisitCount.text = resources.getQuantityString(
                        R.plurals.visit_count, data.visitCount, data.visitCount,
                    )

                    timelineAdapter.submitList(data.timeline)

                    val empty = data.timeline.isEmpty()
                    binding.groupEmpty.visibility = if (empty) View.VISIBLE else View.GONE
                    binding.recyclerTimeline.visibility = if (empty) View.GONE else View.VISIBLE
                }

                else -> {
                    if (handleFailure(result, binding.root)) return@launch
                    if (timelineAdapter.itemCount == 0) {
                        binding.groupEmpty.visibility = View.VISIBLE
                        binding.textEmptyMessage.text =
                            result.errorMessage(getString(R.string.error_unknown))
                    }
                }
            }
        }
    }

    companion object {
        private const val EXTRA_LEAD_ID = "lead_id"

        fun intent(context: Context, leadId: Int): Intent =
            Intent(context, VisitHistoryActivity::class.java).apply {
                putExtra(EXTRA_LEAD_ID, leadId)
            }
    }
}
