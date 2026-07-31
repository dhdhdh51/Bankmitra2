package com.lrms.recovery.ui.search

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.EditorInfo
import android.view.inputmethod.InputMethodManager
import androidx.core.content.getSystemService
import androidx.core.widget.doAfterTextChanged
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.remote.LeadDto
import com.lrms.recovery.databinding.FragmentSearchBinding
import com.lrms.recovery.ui.BaseFragment
import com.lrms.recovery.ui.customer.CustomerProfileActivity
import com.lrms.recovery.ui.leads.LeadAdapter
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Customer search by loan account number, name, mobile, Aadhaar or village.
 *
 * Mobile and Aadhaar are encrypted at rest on the server, so those two match
 * exactly through their HMAC columns - a partial mobile number will not match, and
 * the empty-state copy says so rather than leaving the agent guessing.
 */
class SearchFragment : BaseFragment() {

    private var _binding: FragmentSearchBinding? = null
    private val binding get() = _binding!!

    private lateinit var adapter: LeadAdapter

    private var searchJob: Job? = null
    private var lastQuery = ""

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View {
        _binding = FragmentSearchBinding.inflate(inflater, container, false)
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

        binding.recyclerResults.apply {
            layoutManager = LinearLayoutManager(requireContext())
            adapter = this@SearchFragment.adapter
        }

        // Debounced so typing an account number does not fire a request per key.
        binding.inputSearch.doAfterTextChanged { text ->
            val query = text?.toString()?.trim().orEmpty()
            searchJob?.cancel()

            if (query.length < MIN_QUERY_LENGTH) {
                showIdleState()
                return@doAfterTextChanged
            }

            searchJob = viewLifecycleOwner.lifecycleScope.launch {
                delay(DEBOUNCE_MS)
                performSearch(query)
            }
        }

        binding.inputSearch.setOnEditorActionListener { _, actionId, _ ->
            if (actionId == EditorInfo.IME_ACTION_SEARCH) {
                val query = binding.inputSearch.text?.toString()?.trim().orEmpty()
                if (query.length >= MIN_QUERY_LENGTH) {
                    hideKeyboard()
                    searchJob?.cancel()
                    viewLifecycleOwner.lifecycleScope.launch { performSearch(query) }
                } else {
                    showMessage(getString(R.string.search_too_short), binding.root)
                }
                true
            } else {
                false
            }
        }

        // Searching the whole branch is opt-in: by default an agent looks through
        // their own book, which is what they want most of the time.
        binding.switchWholeBranch.setOnCheckedChangeListener { _, _ ->
            if (lastQuery.length >= MIN_QUERY_LENGTH) {
                viewLifecycleOwner.lifecycleScope.launch { performSearch(lastQuery) }
            }
        }

        showIdleState()
    }

    private suspend fun performSearch(query: String) {
        lastQuery = query

        binding.progress.visibility = View.VISIBLE
        binding.groupIdle.visibility = View.GONE
        binding.groupNoResults.visibility = View.GONE

        val result = repository.searchLeads(
            query = query,
            wholeBranch = binding.switchWholeBranch.isChecked,
        )

        binding.progress.visibility = View.GONE

        when (result) {
            is ApiResult.Success -> {
                val items: List<LeadDto> = result.data.items
                adapter.submitList(items)

                val total = result.data.meta?.total ?: items.size
                binding.textResultCount.text =
                    resources.getQuantityString(R.plurals.lead_count, total, total)
                binding.textResultCount.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE

                binding.groupNoResults.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
                binding.recyclerResults.visibility = if (items.isEmpty()) View.GONE else View.VISIBLE
            }

            else -> {
                if (handleFailure(result, binding.root)) return
                adapter.submitList(emptyList())
                binding.recyclerResults.visibility = View.GONE
                binding.groupNoResults.visibility = View.VISIBLE
                binding.textNoResultsMessage.text =
                    result.errorMessage(getString(R.string.error_unknown))
            }
        }
    }

    private fun showIdleState() {
        adapter.submitList(emptyList())
        binding.groupIdle.visibility = View.VISIBLE
        binding.groupNoResults.visibility = View.GONE
        binding.recyclerResults.visibility = View.GONE
        binding.textResultCount.visibility = View.GONE
        binding.progress.visibility = View.GONE
    }

    private fun hideKeyboard() {
        val manager = requireContext().getSystemService<InputMethodManager>()
        manager?.hideSoftInputFromWindow(binding.inputSearch.windowToken, 0)
    }

    override fun onDestroyView() {
        super.onDestroyView()
        searchJob?.cancel()
        _binding = null
    }

    private companion object {
        const val MIN_QUERY_LENGTH = 2
        const val DEBOUNCE_MS = 450L
    }
}
