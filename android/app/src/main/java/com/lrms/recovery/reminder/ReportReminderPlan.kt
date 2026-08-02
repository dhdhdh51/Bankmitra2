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
 *   THE AGENT HAS NO SAY IN IT. There is no lead time to bring it forward and no switch
 *   to turn it off. Both existed and both are gone: the deadline is the bank's, the agent
 *   is measured against it, and a reminder that the person being measured can move or
 *   silence is not a reminder. The only switch is the bank's, and it arrives from /meta.
 *
 *   IT KEEPS COMING UNTIL THE REPORT IS IN. One notification at the deadline is one swipe
 *   away from being nobody's problem until tomorrow. It re-fires on the bank's interval
 *   until the agent has filed - and stops at the bank's cutoff hour, because an alarm at
 *   2 am does not get a report submitted. It gets the app's notifications switched off
 *   entirely, and takes the reminders that were working with it.
 */
object ReportReminderPlan {

    /** Used when the server's value is missing or malformed. */
    const val DEFAULT_DUE_TIME = "17:00"

    /** Used when the server sends no repeat interval. */
    const val DEFAULT_REPEAT_MINUTES = 15

    /** Repeats never run past this hour unless the server says otherwise. */
    const val DEFAULT_UNTIL_HOUR = 22

    /** Below this, a repeat is a phone nobody can put down rather than a firmer nudge. */
    const val MIN_REPEAT_MINUTES = 5

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

    /** Clamped so a server value of 1 does not turn the phone into an alarm clock. */
    fun sanitiseRepeat(minutes: Int): Int = when {
        minutes <= 0 -> 0
        else -> minutes.coerceIn(MIN_REPEAT_MINUTES, 240)
    }

    /** Clamped to a real hour of the day. */
    fun sanitiseUntilHour(hour: Int): Int = hour.coerceIn(0, 23)

    /**
     * The instant the next reminder should fire, in epoch milliseconds.
     *
     * @param dueTime   the bank's deadline, as sent by the server
     * @param nowMillis the current instant
     * @param timeZone  the device's zone; passed in so tests are not at the mercy of
     *                  wherever the build machine thinks it is
     */
    fun nextTriggerAt(
        dueTime: String?,
        nowMillis: Long,
        timeZone: TimeZone = TimeZone.getDefault(),
    ): Long {
        val due = parseDueTime(dueTime)

        val calendar = Calendar.getInstance(timeZone).apply {
            timeInMillis = nowMillis
            set(Calendar.HOUR_OF_DAY, due.hour)
            set(Calendar.MINUTE, due.minute)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
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
        lastSubmittedIso: String?,
        todayIso: String,
        dayOfWeek: Int,
    ): Boolean {
        if (!enabledOnServer) {
            return false
        }

        if (dayOfWeek == Calendar.SUNDAY) {
            return false
        }

        // Nagging somebody who has already done the thing is how a reminder becomes
        // noise, and noise gets silenced - taking the useful reminders with it. This is
        // also what makes the repeating alarm stop: it is checked every time it fires, so
        // the moment the report is in, the next firing does nothing and the chain ends.
        return lastSubmittedIso != todayIso
    }

    /**
     * When to nudge again after a reminder that did not get a report filed, or null when
     * the day is over.
     *
     * This is the "keeps ringing until submitted" half. Null is the important return: it
     * means the repeats have run out for today, so the caller books the deadline on the
     * next working day instead and the agent's phone is quiet overnight. An unfiled report
     * is not forgotten - it is picked up again tomorrow.
     *
     * @param repeatMinutes the bank's interval; 0 means "one reminder, no repeats"
     * @param untilHour     the hour repeats stop at, exclusive of anything past it
     */
    fun nextRetryAt(
        nowMillis: Long,
        repeatMinutes: Int,
        untilHour: Int,
        timeZone: TimeZone = TimeZone.getDefault(),
    ): Long? {
        val interval = sanitiseRepeat(repeatMinutes)
        if (interval == 0) {
            return null
        }

        val calendar = Calendar.getInstance(timeZone).apply {
            timeInMillis = nowMillis
            add(Calendar.MINUTE, interval)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }

        // Past the cutoff, or rolled into tomorrow. Either way today's repeats are done.
        val cutoff = Calendar.getInstance(timeZone).apply {
            timeInMillis = nowMillis
            set(Calendar.HOUR_OF_DAY, sanitiseUntilHour(untilHour))
            set(Calendar.MINUTE, 0)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }

        if (calendar.timeInMillis > cutoff.timeInMillis) {
            return null
        }

        return calendar.timeInMillis
    }
}
