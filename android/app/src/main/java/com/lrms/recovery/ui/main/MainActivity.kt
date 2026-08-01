package com.lrms.recovery.ui.main

import android.os.Bundle
import androidx.fragment.app.Fragment
import androidx.fragment.app.commit
import androidx.lifecycle.lifecycleScope
import com.google.android.material.badge.BadgeDrawable
import com.lrms.recovery.reminder.ReportReminderScheduler
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.ActivityMainBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.account.AccountFragment
import com.lrms.recovery.ui.leads.LeadsFragment
import com.lrms.recovery.ui.notifications.NotificationsFragment
import com.lrms.recovery.ui.search.SearchFragment
import kotlinx.coroutines.launch

/**
 * Shell for the four main tabs: My Leads, Search, Alerts and Account.
 *
 * Fragments are kept alive with show/hide instead of being replaced, so a filter
 * or a half-typed search survives tab switching - which matters when an agent is
 * moving between screens with one hand in the field.
 */
class MainActivity : BaseActivity() {

    private lateinit var binding: ActivityMainBinding

    private var activeItemId = R.id.nav_leads

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        if (!session.isLoggedIn) {
            forceSignOut()
            return
        }

        activeItemId = savedInstanceState?.getInt(STATE_ACTIVE_TAB) ?: R.id.nav_leads

        binding.bottomNav.setOnItemSelectedListener { item ->
            showTab(item.itemId)
            true
        }

        showTab(activeItemId)
        binding.bottomNav.selectedItemId = activeItemId

        refreshReportDeadline()
    }

    /**
     * Pulls the bank's report deadline and caches it, then re-arms the alarm.
     *
     * Fire-and-forget on purpose. A failure here is silent because the cached value
     * still works: the alarm has to fire with no network - which in these villages is
     * the normal case - so a stale deadline is far better than a blocking call or a
     * missed reminder. When the deadline does change, every agent picks it up the next
     * time they open the app.
     */
    private fun refreshReportDeadline() {
        lifecycleScope.launch {
            val result = repository.meta()
            if (result !is ApiResult.Success) {
                return@launch
            }

            val due = result.data.reportDueTime
            if (!due.isNullOrBlank()) {
                session.reportDueTime = due
            }
            session.reportReminderAllowed = result.data.reportReminder

            ReportReminderScheduler.reschedule(this@MainActivity, session)
        }
    }

    override fun onResume() {
        super.onResume()
        refreshUnreadBadge()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(STATE_ACTIVE_TAB, activeItemId)
    }

    /**
     * Switches tabs, keeping each one alive so its scroll position and half-typed
     * input survive.
     *
     * THE FRAGMENT MANAGER IS THE ONLY SOURCE OF TRUTH HERE. This used to keep its own
     * `Map<Int, Fragment>` and that was the bug: the map lives in this activity
     * instance, so anything that recreates the activity - a rotation, a font-size
     * change, the dark-mode switch on the Account tab, coming back after the system
     * killed the process - emptied it, while the FragmentManager happily restored the
     * fragments it had been given tags for. The map then reported "nothing is showing",
     * so nothing was hidden and a second copy was added on top of the restored one.
     * Both were visible, one bleeding through the other, and every further tab tap made
     * it worse because hide() was operating on an instance that was no longer on screen.
     *
     * Looking every fragment up by tag makes that impossible: there is one place the
     * state lives, and it is the one that survives recreation.
     */
    private fun showTab(itemId: Int) {
        val manager = supportFragmentManager
        val transaction = manager.beginTransaction()

        val target = manager.findFragmentByTag(tagFor(itemId))

        // Hide everything that is not the target rather than just the tab we think was
        // last active. Costs nothing, and it self-heals: if anything else has been left
        // visible, this is the transaction that puts it away.
        for (fragment in manager.fragments) {
            if (fragment !== target) {
                transaction.hide(fragment)
            }
        }

        if (target == null) {
            transaction.add(R.id.fragmentContainer, createFragment(itemId), tagFor(itemId))
        } else {
            transaction.show(target)
        }

        // Allowing state loss on purpose. A tab tap can land after onSaveInstanceState
        // - the system pausing the activity mid-gesture is enough - and commit() throws
        // there. Which tab was showing is saved separately in activeItemId and restored
        // in onCreate, so the worst case is reopening on the previous tab. Crashing an
        // agent out of the app mid-visit is not a trade worth making for that.
        transaction.commitAllowingStateLoss()
        activeItemId = itemId
    }

    private fun createFragment(itemId: Int): Fragment = when (itemId) {
        R.id.nav_search -> SearchFragment()
        R.id.nav_notifications -> NotificationsFragment()
        R.id.nav_account -> AccountFragment()
        else -> LeadsFragment()
    }

    private fun tagFor(itemId: Int): String = "tab_$itemId"

    /** Shows the unread count on the Alerts tab. */
    fun refreshUnreadBadge() {
        lifecycleScope.launch {
            when (val result = repository.unreadCount()) {
                is ApiResult.Success -> applyBadge(result.data)
                ApiResult.Unauthorised -> forceSignOut()
                // A badge is not worth interrupting the user for.
                else -> Unit
            }
        }
    }

    private fun applyBadge(count: Int) {
        if (count <= 0) {
            binding.bottomNav.removeBadge(R.id.nav_notifications)
            return
        }

        val badge: BadgeDrawable = binding.bottomNav.getOrCreateBadge(R.id.nav_notifications)
        badge.isVisible = true
        badge.number = count
        badge.maxCharacterCount = MAX_BADGE_DIGITS
    }

    /** Lets a fragment jump to another tab, e.g. Alerts to a lead. */
    fun selectTab(itemId: Int) {
        binding.bottomNav.selectedItemId = itemId
    }

    companion object {
        private const val STATE_ACTIVE_TAB = "active_tab"
        private const val MAX_BADGE_DIGITS = 3
    }
}
