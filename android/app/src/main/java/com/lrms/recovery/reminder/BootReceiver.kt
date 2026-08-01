package com.lrms.recovery.reminder

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.lrms.recovery.LrmsApp

/**
 * Re-registers the daily reminder after the device restarts.
 *
 * This exists because Android drops every registered alarm on reboot, silently. Without
 * it the reminder works perfectly until the agent's phone runs out of battery once, and
 * then never fires again - while the app still shows the reminder as switched on. That
 * failure is invisible from inside the app, which is exactly the kind that survives to
 * production: nobody reports "my alarm stopped three weeks ago", they just stop
 * submitting reports on time and get a warning for it.
 *
 * MY_PACKAGE_REPLACED is handled too, for the same reason: alarms do not survive an
 * app update either, and these APKs are updated by hand from a CI artifact.
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action !in HANDLED) {
            return
        }

        val session = (context.applicationContext as? LrmsApp)?.repository?.session ?: return

        ReportReminderScheduler.reschedule(context, session)
    }

    private companion object {
        val HANDLED = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED,
            // Some OEM builds send this instead of BOOT_COMPLETED on a quick restart.
            "android.intent.action.QUICKBOOT_POWERON",
        )
    }
}
