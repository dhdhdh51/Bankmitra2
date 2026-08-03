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
import com.lrms.recovery.ui.login.ChangePasswordActivity
import com.lrms.recovery.ui.sss.SssEntryActivity
import com.lrms.recovery.util.AppLanguage
import com.lrms.recovery.util.Formatters
import kotlinx.coroutines.launch

/**
 * The agent's own account: identity, their performance counters, theme and sign-out.
 *
 * The counters come from the agent dashboard endpoint and are scoped server-side to this
 * agent, so no branch or all-branch figures are ever exposed here.
 *
 * TWO THINGS DELIBERATELY ARE NOT HERE ANY MORE.
 *
 * The daily report reminder had a switch and a "nudge me this early" picker. Both are
 * gone: the deadline is the bank's, the agent is measured against it, and a reminder the
 * measured person can move or silence is not a reminder. The time now arrives from /meta
 * and there is nothing on this screen about it.
 *
 * Location had a consent notice with an on/off control. Also gone. Granting the operating
 * system's location permission is the whole of the consent, recorded once for the audit
 * trail without asking a second question - so there is no in-app toggle that can disagree
 * with the OS setting, and nothing here for an agent to switch off and then wonder why
 * their visits carry no coordinates.
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

        bindLanguage()
        binding.rowLanguage.setOnClickListener { chooseLanguage() }

        binding.rowSss.setOnClickListener {
            startActivity(SssEntryActivity.intent(requireContext()))
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

    // -----------------------------------------------------------------------
    // Language
    // -----------------------------------------------------------------------

    /**
     * Shows the current choice under the row.
     *
     * Each option is labelled in its OWN script - English as "English", Hindi as
     * "हिन्दी" - so the row is legible whichever language the app happens to be in.
     * Labelling them in the current language would leave an agent stuck in a language
     * they cannot read looking at a list they also cannot read.
     */
    private fun bindLanguage() {
        binding.textLanguageValue.text = languageLabel(currentLanguage())
    }

    private fun currentLanguage(): AppLanguage {
        // Read from the system first: an agent may have used Android 13's own per-app
        // language picker, and this row must not contradict it. Falls back to what we
        // stored, which is the only source on older phones.
        val applied = AppCompatDelegate.getApplicationLocales()
        return if (applied.isEmpty) {
            AppLanguage.fromTag(session.languageTag)
        } else {
            AppLanguage.fromTag(applied[0]?.language)
        }
    }

    private fun languageLabel(language: AppLanguage): String = getString(
        when (language) {
            AppLanguage.ENGLISH -> R.string.account_language_english
            AppLanguage.HINDI -> R.string.account_language_hindi
            AppLanguage.SYSTEM -> R.string.account_language_system
        },
    )

    private fun chooseLanguage() {
        val choices = AppLanguage.CHOICES
        val labels = choices.map { languageLabel(it) }.toTypedArray()
        val current = choices.indexOf(currentLanguage()).coerceAtLeast(0)

        // setMessage() is deliberately NOT used here. AlertDialog.Builder only has one
        // content-view slot below the title: setSingleChoiceItems() fills it with the
        // radio list, and calling setMessage() afterwards - or before, order does not
        // matter - REPLACES that content view with a plain TextView showing only the
        // message. The dialog still builds and shows without error, so this shipped
        // once as "Language" + the hint text + a Cancel button and no way to actually
        // choose a language - broken, but nothing about it threw.
        //
        // The hint now runs as the dialog's title, alongside the row's own label, so an
        // agent still knows what the choice affects without costing the list.
        AlertDialog.Builder(requireContext())
            .setTitle(
                getString(R.string.account_language) + "\n\n" +
                    getString(R.string.account_language_hint),
            )
            .setSingleChoiceItems(labels, current) { dialog, which ->
                dialog.dismiss()

                val picked = choices[which]
                session.languageTag = AppLanguage.tagFor(picked)

                // Android performs the switch and recreates what needs recreating. We do
                // NOT recreate the activity ourselves, and we do not wrap any context: a
                // hand-rolled Configuration override is what makes dialogs, notifications
                // and services disagree about the language, and it is the version of this
                // feature that crashes.
                AppLanguage.apply(picked)
            }
            .setNegativeButton(R.string.action_cancel, null)
            .show()
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
