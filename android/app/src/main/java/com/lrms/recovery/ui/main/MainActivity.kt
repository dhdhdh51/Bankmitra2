package com.lrms.recovery.ui.main

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.fragment.app.commit
import androidx.lifecycle.lifecycleScope
import com.google.android.material.badge.BadgeDrawable
import com.lrms.recovery.reminder.ReportReminderScheduler
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.ActivityMainBinding
import com.lrms.recovery.location.DutyLocationService
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

    /**
     * Asked for once, here, rather than when the agent switches a reminder on.
     *
     * There is no switch any more, so there is no moment where the agent opts in - and on
     * Android 13+ a notification nobody allowed is dropped silently, which would leave the
     * daily reminder appearing to work and simply never arriving. The result is ignored:
     * refusing is allowed, and nagging about it on every launch is the fastest way to have
     * the whole app muted.
     */
    private val notificationPermission = registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { /* declined is a valid answer */ }

    /**
     * The location permission, asked for by the operating system and nowhere else.
     *
     * There used to be an in-app notice screen that asked first and only then requested
     * the permission. It is gone, so this dialog is the whole of the question - which
     * means somebody has to raise it, and the shell every signed-in agent lands on is the
     * only place that sees every session.
     *
     * Granting it starts recording immediately; there is no separate duty switch for the
     * agent to leave off. Refusing is not re-asked in the app: Android stops showing the
     * dialog once it has been refused twice, and a launch that opens on a permission
     * prompt is a launch an agent learns to dismiss without reading.
     */
    private val locationPermission = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { granted ->
        if (granted.values.any { it }) {
            startRecordingLocation()
        }
    }

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
        requestNotificationsIfNeeded()
        requestLocationIfNeeded()
    }

    /** No-op below Android 13, where notifications need no permission. */
    private fun requestNotificationsIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            return
        }

        val granted = ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED

        if (!granted) {
            notificationPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    /**
     * Either asks the operating system for location, or - if it is already held - gets
     * recording going.
     *
     * The permission dialog IS the consent. There used to be a second in-app notice with
     * its own acknowledgement and its own duty switch, and it could disagree with the OS
     * setting: an agent could grant the permission and still have every coordinate refused
     * by the server, with no indication anywhere of why their visits carried no location.
     */
    private fun hasLocationPermission(): Boolean =
        ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

    private fun requestLocationIfNeeded() {
        // Only asks. Starting is onResume's job, which runs on every entry to the app
        // including the one straight after this dialog is answered.
        if (hasLocationPermission()) {
            return
        }

        locationPermission.launch(
            arrayOf(
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION,
            ),
        )
    }

    /**
     * Records the consent server-side, then starts the duty session.
     *
     * IN THAT ORDER, and that is the whole reason this is one method. The server refuses
     * uploaded coordinates with a 412 until consent is on file, and the service treats a
     * 412 as withdrawn consent and stops itself - so starting the two in parallel on a
     * fresh install is a race that ends with recording switched off until the next launch.
     *
     * Consent is recorded rather than assumed because the audit trail is the point: which
     * notice version was in force, when, from which device. The version comes from the
     * server, not a constant baked into whichever build happened to be installed. The
     * whole thing is latched, so it costs one pair of calls per install; a failure is
     * simply retried on the next launch.
     */
    private fun startRecordingLocation() {
        if (session.locationConsentRecorded) {
            DutyLocationService.start(this)
            return
        }

        lifecycleScope.launch {
            val notice = repository.locationNotice()
            if (notice !is ApiResult.Success) {
                return@launch
            }

            val accepted = repository.acceptLocationNotice(
                version = notice.data.version,
                deviceInfo = Build.MANUFACTURER + " " + Build.MODEL,
            )

            if (accepted is ApiResult.Success) {
                session.locationConsentRecorded = true
                DutyLocationService.start(this@MainActivity)
            }
        }
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

            // How the alarm behaves once it fires, also the bank's to decide. Cached for
            // the same reason as the deadline: the alarm has to work with no network.
            session.reportReminderRepeatMinutes = result.data.reportReminderRepeatMinutes
            session.reportReminderUntilHour = result.data.reportReminderUntilHour

            ReportReminderScheduler.reschedule(this@MainActivity, session)
        }
    }

    override fun onResume() {
        super.onResume()
        refreshUnreadBadge()

        // Picks recording back up on the way into the app. Two cases need it: the
        // permission granted from the system settings screen rather than the dialog, and
        // the Stop action in the ongoing notification - which Android requires us to
        // offer. Stop ends the session that is running; it is not a setting, so the next
        // time the agent opens the app recording resumes. What is captured is the bank's
        // decision, in the same way the reminder time is.
        if (hasLocationPermission() && !DutyLocationService.isRunning) {
            startRecordingLocation()
        }
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
