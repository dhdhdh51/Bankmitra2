package com.lrms.recovery.location

import java.io.File
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Guards the promises this app makes about tracking its own users.
 *
 * These are declarative facts - a manifest entry, a permission, a string - and every
 * one of them compiles perfectly while being wrong on a device. They are also the
 * things that, if they quietly changed, would turn a disclosed duty-session recorder
 * into covert surveillance. So they are asserted rather than trusted.
 */
class LocationTrackingTest {

    private val res = File("src/main/res")
    private val manifest = File("src/main/AndroidManifest.xml").readText()
    private val service = File("src/main/java/com/lrms/recovery/location/DutyLocationService.kt").readText()

    private fun strings(): String = File(res, "values/strings.xml").readText()

    /**
     * The strings an agent can actually read, with XML comments removed.
     *
     * A comment explaining why a promise was withdrawn necessarily quotes the promise.
     * Asserting against the raw file would make that explanation indistinguishable from
     * a relapse, and the cure would be to delete the explanation - which is backwards.
     */
    private fun userVisibleStrings(): String = strings()
        .replace(Regex("""<!--.*?-->""", RegexOption.DOT_MATCHES_ALL), "")

    /** Drops comments so prose about a permission is not mistaken for the permission. */
    private fun code(source: String): String = source
        .replace(Regex("""<!--.*?-->""", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("""/\*.*?\*/""", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("""//[^\n]*"""), "")

    // -----------------------------------------------------------------------
    // The old promise must be gone, not merely contradicted
    // -----------------------------------------------------------------------

    @Test
    fun `the app no longer claims that it does not track location`() {
        // This string was shown on the login and account screens. Leaving it in place
        // next to a location recorder would be a lie to the person reading it.
        assertFalse(
            "the app still tells agents it does not track their location",
            userVisibleStrings().contains("does not track your location"),
        )
        // The name has to go too. A resource left defined is a resource somebody can
        // reintroduce into a layout without noticing what it promises.
        assertFalse(
            "the string resource no_gps_note must be removed, not just reworded",
            userVisibleStrings().contains("name=\"no_gps_note\""),
        )
    }

    @Test
    fun `every layout that referenced the old string was updated`() {
        val stale = (File(res, "layout").listFiles() ?: emptyArray())
            .filter { it.extension == "xml" && it.readText().contains("@string/no_gps_note") }
            .map { it.name }
        assertTrue("these layouts reference a string that no longer exists: $stale", stale.isEmpty())
    }

    @Test
    fun `the replacement tells the agent plainly what is recorded`() {
        val notice = Regex("""<string name="location_notice_short">([^<]+)</string>""")
            .find(strings())?.groupValues?.get(1)
        requireNotNull(notice) { "location_notice_short is missing" }
        assertTrue("the notice must say location is recorded", notice.contains("records your location"))
        assertTrue("the notice must say when", notice.contains("on duty"))
    }

    // -----------------------------------------------------------------------
    // Collection must be visible and stoppable
    // -----------------------------------------------------------------------

    @Test
    fun `location is only collected by a foreground service`() {
        val clean = code(manifest)
        assertTrue(
            "the duty service must be declared",
            clean.contains(".location.DutyLocationService"),
        )
        // This attribute is what makes Android show the ongoing notification. Without
        // it, recording could run with nothing on screen to say so.
        assertTrue(
            "the service must declare foregroundServiceType=location",
            Regex("""DutyLocationService[\s\S]*?foregroundServiceType="location"""").containsMatchIn(clean),
        )
        assertTrue(
            "the service must not be exported",
            Regex("""DutyLocationService[\s\S]*?android:exported="false"""").containsMatchIn(clean),
        )
    }

    @Test
    fun `background location is never requested`() {
        // A foreground service with a visible notification covers a duty session.
        // ACCESS_BACKGROUND_LOCATION would allow collection with nothing on screen,
        // which is a different product from the one described in the notice.
        assertFalse(
            "ACCESS_BACKGROUND_LOCATION must not be requested",
            code(manifest).contains("ACCESS_BACKGROUND_LOCATION"),
        )
    }

    @Test
    fun `the permissions actually needed are declared`() {
        val clean = code(manifest)
        for (permission in listOf(
            "android.permission.ACCESS_FINE_LOCATION",
            "android.permission.FOREGROUND_SERVICE",
            "android.permission.FOREGROUND_SERVICE_LOCATION",
        )) {
            assertTrue("missing $permission", clean.contains(permission))
        }
    }

    @Test
    fun `the notification offers a way to stop`() {
        assertTrue(
            "the ongoing notification must carry a stop action - making somebody hunt " +
                "through settings to turn off tracking is not consent",
            service.contains("R.string.location_stop_duty") && service.contains("addAction"),
        )
        assertTrue("the notification must be ongoing", service.contains("setOngoing(true)"))
    }

    @Test
    fun `a duty session is never resumed without the agent`() {
        // START_STICKY would have Android restart the service after the process dies,
        // resuming recording that nobody asked for.
        assertFalse("the service must not be sticky", code(service).contains("START_STICKY"))
        assertTrue("the service must return START_NOT_STICKY", code(service).contains("START_NOT_STICKY"))
    }

    // -----------------------------------------------------------------------
    // Consent gates collection
    // -----------------------------------------------------------------------

    @Test
    fun `withdrawn consent stops the service rather than queueing forever`() {
        // 412 is the server saying "the notice has not been acknowledged". Continuing
        // to queue points against that would collect data the server will never
        // accept, and would survive a withdrawal.
        assertTrue(
            "a 412 from the server must stop the session",
            Regex("""412[\s\S]*?stopSelf\(\)""").containsMatchIn(service),
        )
    }

    @Test
    fun `the consent screen asks before the operating system does`() {
        val activity = File(
            "src/main/java/com/lrms/recovery/ui/location/LocationConsentActivity.kt",
        ).readText()

        // Requesting the OS permission first would put a system dialog in front of
        // somebody who has not yet been told what the answer will be used for.
        assertTrue(
            "the duty button must check acknowledgement before requesting permission",
            Regex("""acknowledged != true[\s\S]*?return""").containsMatchIn(activity),
        )
        assertTrue(
            "withdrawing must stop the service immediately",
            Regex("""withdrawLocationConsent[\s\S]*?DutyLocationService\.stop""").containsMatchIn(activity),
        )
        assertTrue(
            "the notice text must come from the server, not be hardcoded in the app",
            activity.contains("repository.locationNotice()"),
        )
        assertTrue(
            "a changed notice version must force a re-read",
            Regex("""409[\s\S]*?load\(\)""").containsMatchIn(activity),
        )
    }

    @Test
    fun `withdrawal is offered in the app and explains the consequence`() {
        val text = strings()
        assertTrue("a withdraw action must exist", text.contains("name=\"location_withdraw\""))
        val confirm = Regex("""<string name="location_withdraw_confirm">([^<]+)</string>""")
            .find(text)?.groupValues?.get(1)
        requireNotNull(confirm) { "location_withdraw_confirm is missing" }
        assertTrue("withdrawal must say recording stops", confirm.contains("stop immediately"))
        assertTrue("withdrawal must mention the effect on reports", confirm.contains("Visit reports"))
    }

    // -----------------------------------------------------------------------
    // The agent can find it, and what they are shown is true
    // -----------------------------------------------------------------------

    @Test
    fun `the consent screen is reachable from the app`() {
        // A consent screen nothing opens is not a consent mechanism: recording could
        // never be switched on, and more to the point could never be switched off.
        val reachable = File("src/main/java/com/lrms/recovery")
            .walkTopDown()
            .filter { it.extension == "kt" && !it.path.contains("/ui/location/") }
            .any { it.readText().contains("LocationConsentActivity") }
        assertTrue("no screen in the app opens LocationConsentActivity", reachable)
    }

    @Test
    fun `duty state is read from the service rather than assumed`() {
        // Both screens used to draw "off duty" from a hardcoded false. Reopening
        // either one mid-session then offered "Start duty session" while the ongoing
        // notification said recording was already on.
        assertTrue(
            "the service must expose whether it is recording",
            code(service).contains("val isRunning"),
        )
        assertTrue(
            "the flag must be cleared in onDestroy, which every exit path reaches",
            Regex("""onDestroy\(\)[\s\S]*?active = false""").containsMatchIn(code(service)),
        )

        for (screen in listOf(
            "src/main/java/com/lrms/recovery/ui/location/LocationConsentActivity.kt",
            "src/main/java/com/lrms/recovery/ui/account/AccountFragment.kt",
        )) {
            val text = code(File(screen).readText())
            assertTrue(
                "$screen must read DutyLocationService.isRunning",
                text.contains("DutyLocationService.isRunning"),
            )
            // Without this, stopping from the notification leaves the screen behind
            // it still showing a Stop button for a session that already ended.
            assertTrue(
                "$screen must refresh duty state in onResume",
                Regex("""onResume\(\)[\s\S]*?isRunning""").containsMatchIn(text),
            )
        }
    }

    @Test
    fun `the notice is available in Hindi as well as English`() {
        val activity = File(
            "src/main/java/com/lrms/recovery/ui/location/LocationConsentActivity.kt",
        ).readText()
        assertTrue("the Hindi notice must be shown", activity.contains("payload.hindi"))
        assertTrue("the English notice must be shown", activity.contains("payload.english"))
        assertTrue(
            "the retention period must be shown to the agent",
            activity.contains("location_retention_note"),
        )
    }
}
