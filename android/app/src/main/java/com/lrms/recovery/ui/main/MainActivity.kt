package com.lrms.recovery.ui.main

import android.os.Bundle
import androidx.fragment.app.Fragment
import androidx.fragment.app.commit
import androidx.lifecycle.lifecycleScope
import com.google.android.material.badge.BadgeDrawable
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

    private val fragments = mutableMapOf<Int, Fragment>()
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
    }

    override fun onResume() {
        super.onResume()
        refreshUnreadBadge()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(STATE_ACTIVE_TAB, activeItemId)
    }

    private fun showTab(itemId: Int) {
        val transaction = supportFragmentManager.beginTransaction()

        // Hide whatever is currently showing.
        fragments[activeItemId]?.let { transaction.hide(it) }

        val existing = fragments[itemId]
        if (existing == null) {
            val fragment = createFragment(itemId)
            fragments[itemId] = fragment
            transaction.add(R.id.fragmentContainer, fragment, tagFor(itemId))
        } else {
            transaction.show(existing)
        }

        transaction.commit()
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
