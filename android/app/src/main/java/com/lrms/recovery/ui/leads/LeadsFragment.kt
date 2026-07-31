package com.lrms.recovery.ui.leads

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.google.android.material.chip.Chip
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.remote.LeadDto
import com.lrms.recovery.databinding.FragmentLeadsBinding
import com.lrms.recovery.ui.BaseFragment
import com.lrms.recovery.ui.customer.CustomerProfileActivity
import kotlinx.coroutines.launch

/**
 * Assigned leads for the signed-in agent.
 *
 * Status filter chips, pull-to-refresh and endless scrolling. The list is paged
 * rather than loaded whole: an agent can hold hundreds of accounts and a single
 * large response is exactly what fails on a weak rural connection.
 */
class LeadsFragment : BaseFragment() {

    private var _binding: FragmentLeadsBinding? = null
    private val binding get() = _binding!!

    private lateinit var adapter: LeadAdapter

    private var statusFilter: String? = null
    private var currentPage = 1
    private var hasMore = false
    private var isLoading = false

    private val loadedLeads = mutableListOf<LeadDto>()

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View {
        _binding = FragmentLeadsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        adapter = LeadAdapter(
            onClick = { lead ->
                startActivity(CustomerProfileActivity.intent(requireContext(), lead.id))
            },
            onCall = { lead -> dialNumber(lead.mobile) },
        )

        binding.recyclerLeads.apply {
            layoutManager = LinearLayoutManager(requireContext())
            adapter = this@LeadsFragment.adapter
            setHasFixedSize(false)
            addOnScrollListener(endlessScrollListener())
        }

        binding.swipeRefresh.setOnRefreshListener { load(reset = true) }

        setUpFilterChips()

        binding.buttonRetry.setOnClickListener { load(reset = true) }

        load(reset = true)
    }

    override fun onResume() {
        super.onResume()
        // A submitted visit changes a lead's status, so the list is refreshed on
        // return rather than showing a stale row.
        if (loadedLeads.isNotEmpty()) {
            load(reset = true, silent = true)
        }
    }

    private fun setUpFilterChips() {
        val filters = listOf(
            null to getString(R.string.leads_filter_all),
            "pending" to getString(R.string.leads_filter_pending),
            "visited" to getString(R.string.leads_filter_visited),
            "promise" to getString(R.string.leads_filter_promise),
            "followup" to getString(R.string.leads_filter_followup),
            "closed" to getString(R.string.leads_filter_closed),
        )

        binding.chipGroupStatus.removeAllViews()

        filters.forEach { (value, label) ->
            val chip = Chip(requireContext()).apply {
                text = label
                isCheckable = true
                isChecked = value == statusFilter
                setOnClickListener {
                    if (statusFilter != value) {
                        statusFilter = value
                        load(reset = true)
                    } else {
                        // Keep the active chip checked; tapping it again is a no-op.
                        isChecked = true
                    }
                }
            }
            binding.chipGroupStatus.addView(chip)
        }
    }

    private fun endlessScrollListener() = object : RecyclerView.OnScrollListener() {
        override fun onScrolled(recyclerView: RecyclerView, dx: Int, dy: Int) {
            if (dy <= 0 || isLoading || !hasMore) return

            val layoutManager = recyclerView.layoutManager as? LinearLayoutManager ?: return
            val lastVisible = layoutManager.findLastVisibleItemPosition()

            if (lastVisible >= adapter.itemCount - PREFETCH_DISTANCE) {
                load(reset = false)
            }
        }
    }

    private fun load(reset: Boolean, silent: Boolean = false) {
        if (isLoading) return
        isLoading = true

        if (reset) {
            currentPage = 1
        }

        val showSpinner = reset && !silent && loadedLeads.isEmpty()
        binding.progress.visibility = if (showSpinner) View.VISIBLE else View.GONE
        if (!reset) {
            binding.progressMore.visibility = View.VISIBLE
        }
        binding.groupError.visibility = View.GONE

        viewLifecycleOwner.lifecycleScope.launch {
            val result = repository.leads(status = statusFilter, page = currentPage)

            isLoading = false
            binding.progress.visibility = View.GONE
            binding.progressMore.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false

            when (result) {
                is ApiResult.Success -> {
                    val page = result.data

                    if (reset) {
                        loadedLeads.clear()
                    }
                    loadedLeads.addAll(page.items)

                    adapter.submitList(loadedLeads.toList())

                    hasMore = page.meta?.hasMore ?: false
                    if (hasMore) currentPage++

                    updateSummary(page.meta?.total ?: loadedLeads.size)
                    showEmptyState(loadedLeads.isEmpty())
                }

                else -> {
                    if (handleFailure(result, binding.root)) return@launch

                    // Only take over the screen when there is nothing to show.
                    if (loadedLeads.isEmpty()) {
                        binding.groupError.visibility = View.VISIBLE
                        binding.textError.text = result.errorMessage(getString(R.string.error_unknown))
                    }
                }
            }
        }
    }

    private fun updateSummary(total: Int) {
        binding.textSummary.text = resources.getQuantityString(R.plurals.lead_count, total, total)
        binding.textSummary.visibility = if (total > 0) View.VISIBLE else View.GONE
    }

    private fun showEmptyState(empty: Boolean) {
        binding.groupEmpty.visibility = if (empty) View.VISIBLE else View.GONE
        binding.recyclerLeads.visibility = if (empty) View.GONE else View.VISIBLE

        binding.textEmptyMessage.text = if (statusFilter == null) {
            getString(R.string.leads_empty_message)
        } else {
            "No leads with this status. Try a different filter."
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private companion object {
        /** Start loading the next page this many rows from the end. */
        const val PREFETCH_DISTANCE = 5
    }
}
