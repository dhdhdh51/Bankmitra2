package com.lrms.recovery.reminder

import java.util.Calendar
import java.util.TimeZone
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Real behavioural tests, not file assertions.
 *
 * An alarm bug surfaces once a day at one specific hour. Finding it by waiting until
 * 5 pm is not a development loop, and "it worked on my phone yesterday" is not
 * evidence. Every input here is a parameter, so the awkward cases - the deadline
 * already gone, a Sunday, a garbage setting, a lead time someone tried to make
 * negative - are all reachable in milliseconds.
 */
class ReportReminderPlanTest {

    private val ist: TimeZone = TimeZone.getTimeZone("Asia/Kolkata")

    /** Epoch millis for a wall-clock instant in IST. */
    private fun at(year: Int, month: Int, day: Int, hour: Int, minute: Int): Long =
        Calendar.getInstance(ist).apply {
            clear()
            set(year, month - 1, day, hour, minute, 0)
        }.timeInMillis

    private fun fields(millis: Long): Triple<Int, Int, Int> {
        val calendar = Calendar.getInstance(ist).apply { timeInMillis = millis }
        return Triple(
            calendar.get(Calendar.DAY_OF_MONTH),
            calendar.get(Calendar.HOUR_OF_DAY),
            calendar.get(Calendar.MINUTE),
        )
    }

    // -----------------------------------------------------------------------
    // Parsing the deadline
    // -----------------------------------------------------------------------

    @Test
    fun `a normal time parses`() {
        val due = ReportReminderPlan.parseDueTime("17:00")
        assertEquals(17, due.hour)
        assertEquals(0, due.minute)
        assertEquals(1020, due.minuteOfDay)
    }

    @Test
    fun `a single digit hour parses`() {
        assertEquals(9, ReportReminderPlan.parseDueTime("9:30").hour)
        assertEquals(30, ReportReminderPlan.parseDueTime("9:30").minute)
    }

    @Test
    fun `garbage falls back to the deadline rather than to no deadline`() {
        // Returning null here and treating it as "no reminder" would turn one bad
        // settings value into agents silently never being reminded - and nobody would
        // notice until a month of late reports.
        for (bad in listOf(null, "", "   ", "5pm", "17", "17:0", "abc", "25:00", "17:60", "-1:00")) {
            val due = ReportReminderPlan.parseDueTime(bad)
            assertEquals("input <$bad> should fall back", 17, due.hour)
            assertEquals("input <$bad> should fall back", 0, due.minute)
        }
    }

    @Test
    fun `the fallback matches the server's own default`() {
        assertEquals(
            ReportReminderPlan.DEFAULT_DUE_TIME,
            ReportReminderPlan.parseDueTime("nonsense").format(),
        )
    }

    // -----------------------------------------------------------------------
    // The lead time cannot move the reminder later
    // -----------------------------------------------------------------------

    @Test
    fun `the agent has no lead time and no switch any more`() {
        // Both existed. They are gone on purpose: the deadline is the bank's and the agent
        // is measured against it, so a reminder the measured person can move earlier - or
        // switch off - is not a reminder. This test exists so the feature cannot quietly
        // come back through a merge.
        val plan = ReportReminderPlan::class.java
        val names = plan.declaredMethods.map { it.name }

        assertFalse("sanitiseLead should not exist", names.any { it.contains("sanitiseLead") })
        assertFalse("no MAX_LEAD_MINUTES", plan.declaredFields.any { it.name.contains("LEAD") })
    }

    @Test
    fun `the repeat interval is clamped to something a person can live with`() {
        // A server value of 1 would be a phone buzzing every minute, which is not a firmer
        // reminder - it is an uninstalled app.
        assertEquals(ReportReminderPlan.MIN_REPEAT_MINUTES, ReportReminderPlan.sanitiseRepeat(1))
        assertEquals(240, ReportReminderPlan.sanitiseRepeat(99_999))
        assertEquals(15, ReportReminderPlan.sanitiseRepeat(15))
    }

    @Test
    fun `zero means one reminder and no repeating`() {
        // A real choice, not a mistake to be clamped away: some branches want a single
        // nudge at the deadline.
        assertEquals(0, ReportReminderPlan.sanitiseRepeat(0))
        assertEquals(0, ReportReminderPlan.sanitiseRepeat(-5))
        assertNull(
            ReportReminderPlan.nextRetryAt(at(2026, 4, 1, 17, 0), 0, 22, ist),
        )
    }

    @Test
    fun `an unfiled report is nudged again on the bank's interval`() {
        val now = at(2026, 4, 1, 17, 0)
        val retry = ReportReminderPlan.nextRetryAt(now, 15, 22, ist)

        assertNotNull("a retry should be booked well before the cutoff", retry)
        val (_, hour, minute) = fields(retry!!)
        assertEquals(17, hour)
        assertEquals(15, minute)
    }

    @Test
    fun `repeats stop at the cutoff rather than running through the night`() {
        // "Until it is submitted" cannot literally mean all night. An alarm at 2 am does
        // not get a report filed; it gets the app's notifications switched off, which
        // loses the reminders that were working.
        assertNull(
            "a repeat that would land after the cutoff is refused",
            ReportReminderPlan.nextRetryAt(at(2026, 4, 1, 21, 55), 15, 22, ist),
        )
        assertNotNull(
            "one that lands before it is fine",
            ReportReminderPlan.nextRetryAt(at(2026, 4, 1, 21, 40), 15, 22, ist),
        )
    }

    @Test
    fun `the cutoff hour is clamped to a real hour`() {
        assertEquals(23, ReportReminderPlan.sanitiseUntilHour(99))
        assertEquals(0, ReportReminderPlan.sanitiseUntilHour(-4))
        assertEquals(22, ReportReminderPlan.sanitiseUntilHour(22))
    }

    @Test
    fun `before the deadline the reminder is today`() {
        val now = at(2026, 4, 1, 9, 0)          // Wednesday
        val (day, hour, minute) = fields(ReportReminderPlan.nextTriggerAt("17:00", now, ist))

        assertEquals(1, day)
        // ON the deadline, not before it. There used to be an agent-chosen lead time that
        // moved this earlier; it is gone, so the first nudge is the deadline itself and the
        // repeats carry it from there.
        assertEquals(17, hour)
        assertEquals(0, minute)
    }

    @Test
    fun `after the deadline the reminder is tomorrow, not immediately`() {
        // An alarm set for a past instant fires on the spot on some OEM builds, so an
        // agent opening the app at 8 pm would be nagged every single time.
        val now = at(2026, 4, 1, 20, 0)         // Wednesday evening
        val (day, hour, minute) = fields(ReportReminderPlan.nextTriggerAt("17:00", now, ist))

        assertEquals(2, day)
        assertEquals(17, hour)
        assertEquals(0, minute)
    }

    @Test
    fun `exactly at the deadline the reminder moves to the next day`() {
        // Equal-to-now would fire the same instant it was scheduled, which reads as a
        // spurious notification on launch.
        val now = at(2026, 4, 1, 17, 0)
        val (day, _, _) = fields(ReportReminderPlan.nextTriggerAt("17:00", now, ist))
        assertEquals(2, day)
    }

    @Test
    fun `one minute before the deadline still fires today`() {
        val now = at(2026, 4, 1, 16, 59)
        val (day, hour, minute) = fields(ReportReminderPlan.nextTriggerAt("17:00", now, ist))

        assertEquals(1, day)
        assertEquals(17, hour)
        assertEquals(0, minute)
    }

    @Test
    fun `the trigger is always in the future`() {
        val now = at(2026, 4, 1, 23, 59)
        assertTrue(ReportReminderPlan.nextTriggerAt("17:00", now, ist) > now)
    }

    // -----------------------------------------------------------------------
    // Never on a Sunday
    // -----------------------------------------------------------------------

    @Test
    fun `a Saturday evening reminder skips Sunday and lands on Monday`() {
        // 4 Apr 2026 is a Saturday. Nothing is assessed on a Sunday anywhere in this
        // system, so a reminder on one is pure nuisance - and nuisance is how a
        // reminder gets switched off altogether.
        val now = at(2026, 4, 4, 20, 0)
        val trigger = ReportReminderPlan.nextTriggerAt("17:00", now, ist)
        val calendar = Calendar.getInstance(ist).apply { timeInMillis = trigger }

        assertEquals(Calendar.MONDAY, calendar.get(Calendar.DAY_OF_WEEK))
        assertEquals(6, calendar.get(Calendar.DAY_OF_MONTH))
    }

    @Test
    fun `a Sunday morning reminder moves to Monday`() {
        val now = at(2026, 4, 5, 8, 0)          // Sunday
        val calendar = Calendar.getInstance(ist).apply {
            timeInMillis = ReportReminderPlan.nextTriggerAt("17:00", now, ist)
        }

        assertEquals(Calendar.MONDAY, calendar.get(Calendar.DAY_OF_WEEK))
    }

    @Test
    fun `no computed trigger ever falls on a Sunday`() {
        // Walked across a fortnight at hourly steps: a rule that holds for one
        // hand-picked date and fails for another is not a rule.
        var now = at(2026, 3, 30, 0, 0)
        repeat(14 * 24) {
            val calendar = Calendar.getInstance(ist).apply {
                timeInMillis = ReportReminderPlan.nextTriggerAt("17:30", now, ist)
            }
            assertFalse(
                "a Sunday trigger was produced from $now",
                calendar.get(Calendar.DAY_OF_WEEK) == Calendar.SUNDAY,
            )
            now += 60L * 60L * 1000L
        }
    }

    // -----------------------------------------------------------------------
    // Whether it is worth showing at all
    // -----------------------------------------------------------------------

    @Test
    fun `a reminder is shown to somebody who has not filed`() {
        assertTrue(
            ReportReminderPlan.shouldNotify(
                enabledOnServer = true,
                lastSubmittedIso = "2026-03-31",
                todayIso = "2026-04-01",
                dayOfWeek = Calendar.WEDNESDAY,
            ),
        )
    }

    @Test
    fun `somebody who has already filed today is left alone`() {
        // Nagging a person who has done the thing is how a reminder becomes noise, and
        // noise gets silenced - taking the useful reminders with it.
        assertFalse(
            ReportReminderPlan.shouldNotify(
                enabledOnServer = true,
                lastSubmittedIso = "2026-04-01",
                todayIso = "2026-04-01",
                dayOfWeek = Calendar.WEDNESDAY,
            ),
        )
    }

    @Test
    fun `the bank's switch is the only switch`() {
        assertFalse(
            "nothing is shown when the bank has turned reminders off",
            ReportReminderPlan.shouldNotify(
                enabledOnServer = false,
                lastSubmittedIso = null,
                todayIso = "2026-04-01",
                dayOfWeek = Calendar.WEDNESDAY,
            ),
        )
    }

    @Test
    fun `an agent cannot switch it off, so it still fires`() {
        // The inverse of the test that used to be here. There is no agent-side switch, so
        // an agent who has not filed is reminded whatever they would have preferred.
        assertTrue(
            ReportReminderPlan.shouldNotify(
                enabledOnServer = true,
                lastSubmittedIso = null,
                todayIso = "2026-04-01",
                dayOfWeek = Calendar.WEDNESDAY,
            ),
        )
    }

    @Test
    fun `nothing is shown on a Sunday even if the alarm somehow fires`() {
        // Belt and braces: the scheduler should never book a Sunday, but a stale alarm
        // registered before a deadline change could still land on one.
        assertFalse(
            ReportReminderPlan.shouldNotify(
                enabledOnServer = true,
                lastSubmittedIso = null,
                todayIso = "2026-04-05",
                dayOfWeek = Calendar.SUNDAY,
            ),
        )
    }

    @Test
    fun `a never-submitted agent is reminded`() {
        assertTrue(
            ReportReminderPlan.shouldNotify(
                enabledOnServer = true,
                lastSubmittedIso = null,
                todayIso = "2026-04-01",
                dayOfWeek = Calendar.WEDNESDAY,
            ),
        )
    }
}
