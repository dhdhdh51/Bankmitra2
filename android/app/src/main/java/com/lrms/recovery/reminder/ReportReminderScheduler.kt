package com.lrms.recovery.reminder

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.util.Log
import com.lrms.recovery.data.local.SessionStore

/**
 * Registers the daily report reminder with the system alarm clock.
 *
 * DELIBERATELY INEXACT. `setWindow` rather than `setExactAndAllowWhileIdle`, and no
 * `SCHEDULE_EXACT_ALARM` / `USE_EXACT_ALARM` permission. Exact alarms on Android 12+
 * are gated behind a permission Google restricts to alarm clocks and calendar events;
 * a "submit your report" nudge is explicitly not one of those, and shipping the
 * permission risks the listing. A ten-minute window is entirely adequate for a
 * reminder about a deadline - and an inexact alarm survives Doze, which an agent's
 * idle phone in a village will be in.
 *
 * NOT A REPEATING ALARM. `setInexactRepeating` would be less code, but it cannot skip
 * Sundays and cannot re-read a deadline the bank changed. Each firing schedules the
 * next one instead, so a change to the deadline or the agent's lead time takes effect
 * the same day rather than whenever the repeat happens to lapse.
 */
object ReportReminderScheduler {

    private const val TAG = "ReportReminder"
    private const val REQUEST_CODE = 7301

    /** How late the system may fire it. Ten minutes is invisible for a reminder. */
    private const val WINDOW_MS = 10L * 60L * 1000L

    /**
     * Cancels and re-registers the reminder from whatever is currently stored.
     *
     * Safe to call repeatedly - on launch, after login, after a settings change, after
     * a reboot. Cancelling first is what makes it idempotent: without it, changing the
     * lead time would leave the old alarm registered as well and the agent would be
     * nudged twice.
     */
    fun reschedule(context: Context, session: SessionStore) {
        val manager = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager
        if (manager == null) {
            Log.w(TAG, "no AlarmManager; the daily reminder cannot be scheduled")
            return
        }

        val intent = pendingIntent(context)

        // The bank's switch wins over the agent's. An agent can silence their own
        // reminder; only the bank can decide nobody gets one.
        if (!session.reportReminderAllowed || !session.reportReminderEnabled) {
            manager.cancel(intent)
            Log.i(TAG, "daily reminder is off; alarm cancelled")
            return
        }

        val triggerAt = ReportReminderPlan.nextTriggerAt(
            dueTime = session.reportDueTime,
            leadMinutes = session.reportReminderLeadMinutes,
            nowMillis = System.currentTimeMillis(),
        )

        manager.cancel(intent)
        manager.setWindow(AlarmManager.RTC_WAKEUP, triggerAt, WINDOW_MS, intent)

        Log.i(TAG, "daily reminder scheduled for $triggerAt")
    }

    fun cancel(context: Context) {
        val manager = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return
        manager.cancel(pendingIntent(context))
    }

    /**
     * FLAG_IMMUTABLE because nothing outside this app has any business filling in the
     * extras, and it has been required since API 31 anyway.
     */
    private fun pendingIntent(context: Context): PendingIntent = PendingIntent.getBroadcast(
        context,
        REQUEST_CODE,
        Intent(context, ReportReminderReceiver::class.java)
            .setAction(ReportReminderReceiver.ACTION_REMIND),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
    )
}
