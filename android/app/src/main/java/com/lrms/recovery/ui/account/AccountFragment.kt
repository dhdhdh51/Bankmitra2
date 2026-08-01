package com.lrms.recovery.ui.account

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatDelegate
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.BuildConfig
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.FragmentAccountBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.BaseFragment
import com.lrms.recovery.location.DutyLocationService
import com.lrms.recovery.ui.location.LocationConsentActivity
import com.lrms.recovery.ui.login.ChangePasswordActivity
import com.lrms.recovery.util.Formatters
import kotlinx.coroutines.launch

/**
 * The agent's own account: identity, their performance counters, theme and
 * sign-out.
 *
 * The counters come from the agent dashboard endpoint and are scoped server-side
 * to this agent, so no branch or all-branch figures are ever exposed here.
 */
class AccountFragment : BaseFragment() {

    private var _binding: FragmentAccountBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?,
    ): View {
        _binding = FragmentAccountBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        bindUser()

        binding.rowChangePassword.setOnClickListener {
            startActivity(Intent(requireContext(), ChangePasswordActivity::class.java))
        }

        binding.switchDarkMode.isChecked =
            session.themeMode == AppCompatDelegate.MODE_NIGHT_YES

        binding.switchDarkMode.setOnCheckedChangeListener { _, checked ->
            val mode = if (checked) {
                AppCompatDelegate.MODE_NIGHT_YES
            } else {
                AppCompatDelegate.MODE_NIGHT_NO
            }
            session.themeMode = mode
            AppCompatDelegate.setDefaultNightMode(mode)
        }

        // The only route to the location notice. It sits in Settings permanently
        // rather than appearing as a one-time prompt, because "you can withdraw at
        // any time" is only true if there is somewhere to go and do it.
        binding.rowLocation.setOnClickListener {
            startActivity(LocationConsentActivity.intent(requireContext()))
        }

        binding.buttonSignOut.setOnClickListener { confirmSignOut() }

        binding.textVersion.text = getString(
            R.string.account_version_format,
            BuildConfig.VERSION_NAME,
            BuildConfig.VERSION_CODE,
        )

        binding.swipeRefresh.setOnRefreshListener { loadStats() }

        loadStats()
    }

    override fun onResume() {
        super.onResume()
        // Re-read on every return: a session can be stopped from the notification or
        // from the consent screen, and a stale "recording" line here would be the one
        // place in the app that lies about it.
        _binding?.textLocationState?.setText(
            if (DutyLocationService.isRunning) {
                R.string.location_on_duty
            } else {
                R.string.location_off_duty
            },
        )
    }

    private fun bindUser() {
        val user = session.user ?: return

        binding.textName.text = user.name
        binding.textAvatar.text = Formatters.initial(user.name)
        binding.textEmployeeCode.text = user.employeeCode
        binding.textRole.text = user.roleName.ifBlank { user.role }

        binding.textBranch.text = listOfNotNull(
            user.branchName?.takeIf { it.isNotBlank() },
            user.bcCode?.takeIf { it.isNotBlank() },
        ).joinToString(" · ").ifBlank { Formatters.DASH }

        binding.textMobile.text = Formatters.orDash(user.mobileMasked)
    }

    private fun loadStats() {
        viewLifecycleOwner.lifecycleScope.launch {
            // Refresh the profile too: a role or branch change should show up.
            repository.refreshProfile().let { if (it is ApiResult.Success) bindUser() }

            val result = repository.agentDashboard()
            binding.swipeRefresh.isRefreshing = false

            when (result) {
                is ApiResult.Success -> {
                    val leads = result.data.leads
                    val visits = result.data.visits
                    val promises = result.data.promises

                    binding.groupStats.visibility = View.VISIBLE

                    binding.textStatAssigned.text = (leads?.total ?: 0).toString()
                    binding.textStatPending.text = (leads?.pending ?: 0).toString()
                    binding.textStatVisitsToday.text = (visits?.today ?: 0).toString()
                    binding.textStatVisitsMonth.text = (visits?.month ?: 0).toString()
                    binding.textStatPromisesKept.text = (promises?.kept ?: 0).toString()
                    binding.textStatPromisesPending.text = (promises?.pending ?: 0).toString()

                    binding.textOutstanding.text =
                        Formatters.rupees(leads?.outstanding ?: 0.0, decimals = false)
                    binding.textOverdue.text =
                        Formatters.rupees(leads?.overdue ?: 0.0, decimals = false)
                }

                else -> {
                    if (handleFailure(result, binding.root)) return@launch
                    binding.groupStats.visibility = View.GONE
                }
            }
        }
    }

    private fun confirmSignOut() {
        AlertDialog.Builder(requireContext())
            .setTitle(R.string.account_sign_out_confirm)
            .setNegativeButton(R.string.action_cancel, null)
            .setPositiveButton(R.string.account_sign_out) { _, _ -> signOut() }
            .show()
    }

    private fun signOut() {
        binding.buttonSignOut.isEnabled = false

        viewLifecycleOwner.lifecycleScope.launch {
            // The local session is cleared regardless of the server's answer, so
            // an offline sign-out still works.
            repository.logout()
            (activity as? BaseActivity)?.forceSignOut("You have been signed out.")
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
