package com.lrms.recovery.reminder

import java.io.File
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Guards the declarative half of the reminder.
 *
 * [ReportReminderPlanTest] proves the arithmetic. This file guards the things that
 * compile perfectly and fail on a device: a receiver missing from the manifest, a
 * boot receiver that was never registered, an alarm chain that stops rescheduling
 * itself. Every one of them fails silently and looks identical to "working" from
 * inside the app - the reminder simply stops arriving, and the agent gets a warning
 * for a late report instead of a bug report.
 */
class ReportReminderWiringTest {

    private val manifest = File("src/main/AndroidManifest.xml").readText()
    private val receiver = File(
        "src/main/java/com/lrms/recovery/reminder/ReportReminderReceiver.kt",
    ).readText()
    private val boot = File(
        "src/main/java/com/lrms/recovery/reminder/BootReceiver.kt",
    ).readText()
    private val scheduler = File(
        "src/main/java/com/lrms/recovery/reminder/ReportReminderScheduler.kt",
    ).readText()
    private val app = File("src/main/java/com/lrms/recovery/LrmsApp.kt").readText()
    private val account = File(
        "src/main/java/com/lrms/recovery/ui/account/AccountFragment.kt",
    ).readText()
    private val session = File(
        "src/main/java/com/lrms/recovery/data/local/SessionStore.kt",
    ).readText()

    private fun code(source: String): String = source
        .replace(Regex("""<!--[\s\S]*?-->"""), "")
        .replace(Regex("""/\*[\s\S]*?\*/"""), "")
        .replace(Regex("""//[^\n]*"""), "")

    // -----------------------------------------------------------------------
    // Declared, and unexported
    // -----------------------------------------------------------------------

    @Test
    fun `both receivers are declared and not exported`() {
        val clean = code(manifest)

        for (name in listOf(".reminder.ReportReminderReceiver", ".reminder.BootReceiver")) {
            assertTrue("$name is not declared", clean.contains(name))
            assertTrue(
                "$name must not be exported - nothing outside this app should trigger an agent's reminder",
                Regex(Regex.escape(name) + """[\s\S]*?android:exported="false"""").containsMatchIn(clean),
            )
        }
    }

    @Test
    fun `the reminder survives a reboot`() {
        // Android drops every registered alarm on reboot. Without this the reminder
        // works until the agent's phone runs flat once, then never fires again while
        // the app still shows it switched on.
        assertTrue(
            "RECEIVE_BOOT_COMPLETED must be declared",
            code(manifest).contains("android.permission.RECEIVE_BOOT_COMPLETED"),
        )
        assertTrue(
            "BootReceiver must listen for BOOT_COMPLETED",
            Regex("""BootReceiver[\s\S]*?android.intent.action.BOOT_COMPLETED""")
                .containsMatchIn(code(manifest)),
        )
        assertTrue(
            "it must also handle an app update, which drops alarms too",
            code(manifest).contains("android.intent.action.MY_PACKAGE_REPLACED"),
        )
        assertTrue("the boot receiver must reschedule", code(boot).contains("reschedule"))
    }

    @Test
    fun `no exact alarm permission is requested`() {
        // Google restricts these to alarm clocks and calendar events. A deadline nudge
        // is not one, and shipping the permission risks the listing - while an inexact
        // alarm in a ten-minute window is entirely adequate and survives Doze.
        val clean = code(manifest)
        assertFalse("SCHEDULE_EXACT_ALARM must not be requested", clean.contains("SCHEDULE_EXACT_ALARM"))
        assertFalse("USE_EXACT_ALARM must not be requested", clean.contains("USE_EXACT_ALARM"))
        assertFalse(
            "and no exact alarm may be set",
            code(scheduler).contains("setExact"),
        )
        assertTrue("a windowed alarm must be used", code(scheduler).contains("setWindow"))
    }

    // -----------------------------------------------------------------------
    // The chain cannot stop
    // -----------------------------------------------------------------------

    @Test
    fun `the next alarm is booked before anything can return early`() {
        // This is the failure that matters most. If rescheduling happened after the
        // notification, or only when one was shown, then the single day an agent had
        // already submitted would be the day the chain ended - permanently, silently.
        val body = code(receiver).substringAfter("override fun onReceive", "")
        assertTrue("onReceive not found", body.isNotEmpty())

        val reschedulePos = body.indexOf("reschedule")
        val returnPos = body.indexOf("return")

        assertTrue("the receiver must reschedule", reschedulePos >= 0)
        assertTrue(
            "rescheduling must come before the first early return, or the chain can end",
            returnPos < 0 || reschedulePos < returnPos,
        )
    }

    @Test
    fun `a repeating alarm is not used`() {
        // setInexactRepeating cannot skip Sundays and cannot pick up a deadline the
        // bank changed. Each firing books the next one instead.
        assertFalse(
            "setInexactRepeating must not be used",
            code(scheduler).contains("setInexactRepeating"),
        )
    }

    @Test
    fun `the alarm is re-armed when the process starts`() {
        // Covers what the boot receiver does not: a process the system killed, a
        // "clear all" from the task switcher, a phone that was off when it should
        // have fired.
        assertTrue(
            "LrmsApp must reschedule on start",
            Regex("""ReportReminderScheduler\.reschedule""").containsMatchIn(code(app)),
        )
    }

    @Test
    fun `scheduling cancels first so a changed lead time does not double up`() {
        val body = code(scheduler)
        assertTrue("the alarm must be cancelled before being set", body.contains("manager.cancel"))
        assertTrue(
            "cancel must precede setWindow",
            body.indexOf("manager.cancel") < body.lastIndexOf("setWindow"),
        )
    }

    // -----------------------------------------------------------------------
    // The two switches, and who owns which
    // -----------------------------------------------------------------------

    @Test
    fun `the bank's switch overrides the agent's`() {
        assertTrue(
            "the scheduler must honour both switches",
            Regex("""reportReminderAllowed[\s\S]{0,80}reportReminderEnabled""")
                .containsMatchIn(code(scheduler)),
        )
        assertTrue(
            "the account screen must refuse to switch it on when the bank has it off",
            Regex("""reportReminderAllowed[\s\S]{0,200}return""").containsMatchIn(code(account)),
        )
    }

    @Test
    fun `the deadline is cached so the alarm works offline`() {
        // The alarm has to fire with no network, which in these villages is the normal
        // case. A stale deadline is far better than no reminder.
        assertTrue("the due time must be cached", session.contains("var reportDueTime"))
        assertTrue(
            "and refreshed from the server",
            File("src/main/java/com/lrms/recovery/ui/main/MainActivity.kt")
                .readText().contains("session.reportDueTime"),
        )
    }

    @Test
    fun `a handed-on phone does not inherit the previous agent's submitted flag`() {
        // Otherwise the next agent's first day is the day they get no reminder.
        assertTrue(
            "clearSession must drop the submitted date",
            Regex("""fun clearSession\(\)[\s\S]*?KEY_REPORT_LAST_SUBMIT""")
                .containsMatchIn(session),
        )
    }

    @Test
    fun `notification permission is requested, not assumed`() {
        // On Android 13+ a notification the agent never allowed is dropped silently,
        // so the reminder would appear on and simply never arrive.
        assertTrue(
            "POST_NOTIFICATIONS must be requested at runtime",
            code(account).contains("POST_NOTIFICATIONS"),
        )
        assertTrue(
            "and a refusal must be explained rather than left silent",
            account.contains("report_reminder_notifications_needed"),
        )
    }

    @Test
    fun `a refused notification permission cannot crash the receiver`() {
        assertTrue(
            "posting must tolerate a SecurityException",
            Regex("""catch \(_: SecurityException\)""").containsMatchIn(receiver),
        )
    }

    @Test
    fun `the notification opens the entry screen directly`() {
        // A reminder that lands on a home screen and leaves the agent to find the form
        // is a reminder that gets dismissed.
        assertTrue(
            "it must open SssEntryActivity",
            code(receiver).contains("SssEntryActivity"),
        )
        assertTrue("and be dismissible", code(receiver).contains("setAutoCancel(true)"))
    }

    @Test
    fun `nobody signed in means no reminder`() {
        assertTrue(
            "reminding a login screen is pointless",
            code(receiver).contains("accessToken.isNullOrBlank()"),
        )
    }
}
