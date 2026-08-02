package com.lrms.recovery.reminder

import android.app.AlarmManager
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.core.content.ContextCompat
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

    /** Tighter, so a 15-minute repeat is not a 25-minute one. */
    private const val RETRY_WINDOW_MS = 2L * 60L * 1000L

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

        // The bank's switch is the only switch. There used to be an agent-side one beside
        // it; a reminder the person being measured can silence is not a reminder.
        if (!session.reportReminderAllowed) {
            manager.cancel(intent)
            Log.i(TAG, "the bank has daily reminders off; alarm cancelled")
            return
        }

        val triggerAt = ReportReminderPlan.nextTriggerAt(
            dueTime = session.reportDueTime,
            nowMillis = System.currentTimeMillis(),
        )

        manager.cancel(intent)
        manager.setWindow(AlarmManager.RTC_WAKEUP, triggerAt, WINDOW_MS, intent)

        Log.i(TAG, "daily reminder scheduled for $triggerAt")
    }

    /**
     * Books the next nudge for a report that still has not been filed.
     *
     * Returns true when a repeat was booked, false when today's repeats are spent - and
     * the caller then books tomorrow's deadline instead. That distinction is the whole
     * mechanism: the alarm keeps coming back through the evening and goes quiet overnight,
     * rather than either giving up after one notification or ringing at 3 am.
     *
     * A tighter window than the daily alarm, because a fifteen-minute repeat with a
     * ten-minute slop is not really a fifteen-minute repeat.
     */
    fun scheduleRetry(context: Context, session: SessionStore): Boolean {
        val manager = context.getSystemService(Context.ALARM_SERVICE) as? AlarmManager ?: return false

        if (!session.reportReminderAllowed) {
            return false
        }

        val retryAt = ReportReminderPlan.nextRetryAt(
            nowMillis = System.currentTimeMillis(),
            repeatMinutes = session.reportReminderRepeatMinutes,
            untilHour = session.reportReminderUntilHour,
        ) ?: return false

        val intent = pendingIntent(context)
        manager.cancel(intent)
        manager.setWindow(AlarmManager.RTC_WAKEUP, retryAt, RETRY_WINDOW_MS, intent)

        Log.i(TAG, "report still not filed; nudging again at $retryAt")
        return true
    }

    /**
     * Takes the reminder notification off the screen.
     *
     * Explicit rather than automatic, because the notification is deliberately not
     * auto-cancelling: a nudge that vanishes on the first accidental swipe is a nudge that
     * did not happen. Called the moment a report is filed, so the way to make it go away is
     * to do the thing it asks for.
     */
    fun clearNotification(context: Context) {
        try {
            ContextCompat.getSystemService(context, NotificationManager::class.java)
                ?.cancel(ReportReminderReceiver.NOTIFICATION_ID)
        } catch (_: SecurityException) {
            // The agent has declined notifications; there is nothing on screen to clear.
        }
    }

    /**
     * Called when the agent files anything that counts as their daily report.
     *
     * Records the date, clears the nudge and drops back to the daily schedule in one place,
     * so no caller can do two of those three and leave the alarm repeating at somebody who
     * has already done the work.
     */
    fun markReportSubmitted(context: Context, session: SessionStore, todayIso: String) {
        session.lastReportSubmittedDate = todayIso
        clearNotification(context)
        reschedule(context, session)
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
