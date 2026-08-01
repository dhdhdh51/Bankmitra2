package com.lrms.recovery.reminder

import java.util.Calendar
import java.util.Locale
import java.util.TimeZone

/**
 * Works out when the next "submit your daily report" reminder should fire.
 *
 * Deliberately pure - no Context, no AlarmManager, no clock of its own. Every input
 * is a parameter, which is the only way this is testable: an alarm bug shows up once
 * a day at a specific hour, and discovering it by waiting until 5 pm is not a
 * development loop. [ReportReminderScheduler] is the thin part that talks to Android.
 *
 * The rules encoded here, and why:
 *
 *   NEVER IN THE PAST. If today's reminder time has already gone, the next one is
 *   tomorrow. An alarm set for a past instant fires immediately on some OEM builds,
 *   which means an agent who opens the app at 8 pm gets told to submit a report that
 *   was due three hours ago - every time they open it.
 *
 *   NEVER ON A SUNDAY. Sundays are not assessed anywhere else in this system: the
 *   warning cron skips them, targets are pro-rated over working days only. A reminder
 *   on a day nobody is measured on is pure nuisance, and nuisance is how a reminder
 *   gets switched off entirely.
 *
 *   THE LEAD TIME CANNOT PUSH PAST THE DEADLINE. An agent may ask to be nudged
 *   earlier - half an hour before, an hour before - but not later. A reminder after
 *   the deadline is not a reminder, and letting it move later would quietly let
 *   somebody opt out of the deadline they are still assessed against.
 */
object ReportReminderPlan {

    /** Used when the server's value is missing or malformed. */
    const val DEFAULT_DUE_TIME = "17:00"

    /** The most an agent may bring their own reminder forward. */
    const val MAX_LEAD_MINUTES = 240

    /**
     * A parsed deadline. [minuteOfDay] is what the scheduler actually uses.
     */
    data class DueTime(val hour: Int, val minute: Int) {
        val minuteOfDay: Int get() = (hour * 60) + minute

        fun format(): String = String.format(Locale.US, "%02d:%02d", hour, minute)
    }

    /**
     * Parses `HH:mm` from the server, falling back to 17:00.
     *
     * Falls back to a real time rather than returning null on purpose: the server
     * setting is free-form enough that a blank could reach here, and treating that as
     * "no deadline" would mean agents silently stop being reminded - the one
     * interpretation nobody wants for a deadline they are measured against.
     */
    fun parseDueTime(raw: String?): DueTime {
        val fallback = DueTime(17, 0)
        val text = raw?.trim().orEmpty()

        val match = Regex("""^(\d{1,2}):(\d{2})$""").find(text) ?: return fallback

        val hour = match.groupValues[1].toIntOrNull() ?: return fallback
        val minute = match.groupValues[2].toIntOrNull() ?: return fallback

        if (hour > 23 || minute > 59) {
            return fallback
        }

        return DueTime(hour, minute)
    }

    /** Clamped so a stored preference from an older build cannot move it later. */
    fun sanitiseLead(minutes: Int): Int = minutes.coerceIn(0, MAX_LEAD_MINUTES)

    /**
     * The instant the next reminder should fire, in epoch milliseconds.
     *
     * @param dueTime     the bank's deadline, as sent by the server
     * @param leadMinutes how far ahead of it this agent wants to be nudged
     * @param nowMillis   the current instant
     * @param timeZone    the device's zone; passed in so tests are not at the mercy
     *                    of wherever the build machine thinks it is
     */
    fun nextTriggerAt(
        dueTime: String?,
        leadMinutes: Int,
        nowMillis: Long,
        timeZone: TimeZone = TimeZone.getDefault(),
    ): Long {
        val due = parseDueTime(dueTime)
        val lead = sanitiseLead(leadMinutes)

        val calendar = Calendar.getInstance(timeZone).apply {
            timeInMillis = nowMillis
            set(Calendar.HOUR_OF_DAY, due.hour)
            set(Calendar.MINUTE, due.minute)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
            add(Calendar.MINUTE, -lead)
        }

        // Strictly after now. Equal-to-now would fire an alarm the same instant the
        // app scheduled it, which reads as a spurious notification on launch.
        if (calendar.timeInMillis <= nowMillis) {
            calendar.add(Calendar.DAY_OF_MONTH, 1)
        }

        while (calendar.get(Calendar.DAY_OF_WEEK) == Calendar.SUNDAY) {
            calendar.add(Calendar.DAY_OF_MONTH, 1)
        }

        return calendar.timeInMillis
    }

    /**
     * Whether a reminder is worth showing at all when the alarm goes off.
     *
     * An agent who has already filed today does not need telling. Checked at fire
     * time rather than at schedule time, because the whole point of the gap between
     * the two is that they might do the work in it.
     *
     * @param lastSubmittedIso the last date the agent filed anything, `yyyy-MM-dd`
     * @param todayIso         today, same format
     */
    fun shouldNotify(
        enabledOnServer: Boolean,
        enabledByAgent: Boolean,
        lastSubmittedIso: String?,
        todayIso: String,
        dayOfWeek: Int,
    ): Boolean {
        if (!enabledOnServer || !enabledByAgent) {
            return false
        }

        if (dayOfWeek == Calendar.SUNDAY) {
            return false
        }

        // Nagging somebody who has already done the thing is how a reminder becomes
        // noise, and noise gets silenced - taking the useful reminders with it.
        return lastSubmittedIso != todayIso
    }
}
