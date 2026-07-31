package com.lrms.recovery.ui.notifications

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.FragmentNotificationsBinding
import com.lrms.recovery.ui.BaseFragment
import com.lrms.recovery.ui.customer.CustomerProfileActivity
import com.lrms.recovery.ui.main.MainActivity
import kotlinx.coroutines.launch

/**
 * In-app notification list: new lead assignments, follow-up reminders,
 * promise reminders and broadcasts.
 *
 * The in-app list is the source of truth. Firebase push, when configured, only
 * arrives sooner - so an agent on a device with no push still sees everything.
 */
class NotificationsFragment : BaseFragment() {

    private var _binding: FragmentNotificationsBinding? = null
    private val binding get() = _binding!!

    private lateinit var adapter: NotificationAdapter

    private var unreadOnly = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View {
        _binding = FragmentNotificationsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        adapter = NotificationAdapter { notification ->
            // Opening a notification marks it read and follows it to the lead.
            viewLifecycleOwner.lifecycleScope.launch {
                if (!notification.isRead) {
                    repository.markNotificationRead(notification.id)
                    (activity as? MainActivity)?.refreshUnreadBadge()
                }

                notification.loanAccountId?.let { leadId ->
                    startActivity(CustomerProfileActivity.intent(requireContext(), leadId))
                }

                load()
            }
        }

        binding.recyclerNotifications.apply {
            layoutManager = LinearLayoutManager(requireContext())
            adapter = this@NotificationsFragment.adapter
        }

        binding.swipeRefresh.setOnRefreshListener { load() }

        binding.chipUnread.setOnCheckedChangeListener { _, checked ->
            unreadOnly = checked
            load()
        }

        binding.buttonMarkAll.setOnClickListener { markAllRead() }

        load()
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        binding.progress.visibility =
            if (adapter.itemCount == 0 && !binding.swipeRefresh.isRefreshing) View.VISIBLE else View.GONE

        viewLifecycleOwner.lifecycleScope.launch {
            val result = repository.notifications(unreadOnly)

            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false

            when (result) {
                is ApiResult.Success -> {
                    val items = result.data.items
                    adapter.submitList(items)

                    binding.groupEmpty.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
                    binding.recyclerNotifications.visibility =
                        if (items.isEmpty()) View.GONE else View.VISIBLE

                    binding.textEmptyTitle.text = getString(
                        if (unreadOnly) R.string.notifications_empty_title else R.string.notifications_empty_title,
                    )
                }

                else -> {
                    if (handleFailure(result, binding.root)) return@launch
                    if (adapter.itemCount == 0) {
                        binding.groupEmpty.visibility = View.VISIBLE
                        binding.textEmptyMessage.text =
                            result.errorMessage(getString(R.string.error_unknown))
                    }
                }
            }
        }
    }

    private fun markAllRead() {
        binding.buttonMarkAll.isEnabled = false

        viewLifecycleOwner.lifecycleScope.launch {
            val result = repository.markAllNotificationsRead()
            binding.buttonMarkAll.isEnabled = true

            when (result) {
                is ApiResult.Success -> {
                    (activity as? MainActivity)?.refreshUnreadBadge()
                    showMessage(result.message.ifBlank { "All notifications marked read." }, binding.root)
                    load()
                }

                else -> handleFailure(result, binding.root)
            }
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
