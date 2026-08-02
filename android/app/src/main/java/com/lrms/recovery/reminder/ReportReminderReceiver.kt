package com.lrms.recovery.reminder

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.lrms.recovery.LrmsApp
import com.lrms.recovery.R
import com.lrms.recovery.ui.sss.SssEntryActivity
import com.lrms.recovery.util.Formatters
import java.util.Calendar

/**
 * Fires the daily "submit your report" reminder, then books the next one.
 *
 * Two things happen here in a fixed order, and the order is the point:
 *
 *   1. The next alarm is scheduled. FIRST, and outside any conditional. If it were
 *      scheduled after the notification - or only when a notification was actually
 *      shown - then the one day an agent had already submitted would be the day the
 *      chain silently ended, and the reminder would never fire again. An alarm that
 *      stops without telling anybody is worse than no alarm, because everyone
 *      believes it is still on.
 *
 *   2. The notification is shown, but only if it is still warranted: the bank has
 *      reminders on, this agent has not switched theirs off, it is not a Sunday, and
 *      they have not already filed today. Somebody who has done the work does not need
 *      telling, and a reminder that nags regardless is one that gets silenced -
 *      taking the useful ones with it.
 */
class ReportReminderReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val session = (context.applicationContext as? LrmsApp)?.repository?.session

        if (intent.action != ACTION_REMIND || session == null) {
            // Still book the next day if we can: an alarm chain that ends silently is
            // worse than no alarm, because everybody believes it is still on.
            if (session != null) {
                ReportReminderScheduler.reschedule(context, session)
            }
            return
        }

        // Nobody signed in: this phone has been handed on, or the agent signed out.
        // Reminding a login screen is pointless.
        if (session.accessToken.isNullOrBlank()) {
            ReportReminderScheduler.reschedule(context, session)
            return
        }

        val warranted = ReportReminderPlan.shouldNotify(
            enabledOnServer = session.reportReminderAllowed,
            lastSubmittedIso = session.lastReportSubmittedDate,
            todayIso = Formatters.todayIso(),
            dayOfWeek = Calendar.getInstance().get(Calendar.DAY_OF_WEEK),
        )

        if (!warranted) {
            // The report is in (or it is Sunday, or the bank has reminders off). Clear any
            // notification left on screen and go back to the daily schedule - this is the
            // branch that ends the repeating chain.
            ReportReminderScheduler.clearNotification(context)
            ReportReminderScheduler.reschedule(context, session)
            return
        }

        notify(context, session.reportDueTime)

        // Still not filed, so come back on the bank's interval. When today's repeats are
        // spent, fall back to booking tomorrow's deadline so the phone is quiet overnight
        // and the unfiled report is still picked up in the morning.
        if (!ReportReminderScheduler.scheduleRetry(context, session)) {
            ReportReminderScheduler.reschedule(context, session)
        }
    }



    private fun notify(context: Context, dueTime: String?) {
        createChannel(context)

        val due = ReportReminderPlan.parseDueTime(dueTime).format()

        // Straight to the entry screen. A reminder that lands you on a home screen and
        // leaves you to find the form is a reminder that gets dismissed.
        val open = PendingIntent.getActivity(
            context,
            0,
            SssEntryActivity.intent(context).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setContentTitle(context.getString(R.string.report_reminder_title))
            .setContentText(context.getString(R.string.report_reminder_body, Formatters.time(due)))
            .setStyle(
                NotificationCompat.BigTextStyle().bigText(
                    context.getString(R.string.report_reminder_body_long, Formatters.time(due)),
                ),
            )
            .setSmallIcon(R.drawable.ic_launcher_monochrome)
            .setContentIntent(open)
            // Not auto-cancelling and ongoing: this has to still be there until the report
            // is in. Auto-cancel would clear it the moment the agent opened the form and
            // backed out again, which is exactly the case the repeat exists for.
            .setAutoCancel(false)
            .setOngoing(true)
            .setOnlyAlertOnce(false)
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            // HIGH now, not DEFAULT. It was default because a heads-up every evening
            // teaches an agent to swipe the app away - but a reminder that must be acted
            // on before the day closes is worth surfacing, and it stops the moment the
            // report is filed rather than arriving regardless.
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()

        // Wrapped: on Android 13+ POST_NOTIFICATIONS may have been refused, and the
        // platform throws rather than no-opping. A refused notification permission
        // must not crash a broadcast receiver.
        try {
            ContextCompat.getSystemService(context, NotificationManager::class.java)
                ?.notify(NOTIFICATION_ID, notification)
        } catch (_: SecurityException) {
            // Nothing to do: the agent has declined notifications.
        }
    }

    private fun createChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }

        // HIGH, so it is seen. The earlier reasoning for DEFAULT was that a heads-up
        // every evening teaches an agent to swipe the app's notifications away - which is
        // true of a reminder that arrives whether or not the work is done. This one stops
        // as soon as the report is filed, so the way to make it go away is to do the thing
        // it is asking for.
        //
        // A channel's importance is fixed once created, so this only takes effect for
        // installs that have not created it yet. That is why the id changed with it.
        val channel = NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.report_reminder_channel_name),
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = context.getString(R.string.report_reminder_channel_description)
        }

        ContextCompat.getSystemService(context, NotificationManager::class.java)
            ?.createNotificationChannel(channel)
    }

    companion object {
        const val ACTION_REMIND = "com.lrms.recovery.reminder.REMIND"

        private const val CHANNEL_ID = "daily_report_reminder_v2"
        /** Shared with the scheduler, which clears it when the report lands. */
        const val NOTIFICATION_ID = 7301
    }
}
