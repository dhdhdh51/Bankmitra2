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
        assertTrue("the notice must say when", notice.contains("signed in"))
        // It used to end "Tap to read the full notice." The screen that notice lived on
        // is gone, so the sentence would be inviting a tap that does nothing.
        assertFalse("the notice must not point at a screen that no longer exists", notice.contains("Tap to"))
    }

    @Test
    fun `what the agent is told appears where they will see it`() {
        // The notice screen is gone, so this one sentence is the entire disclosure. If it
        // were only defined and never placed, recording would be undisclosed in practice.
        val layouts = (File(res, "layout").listFiles() ?: emptyArray())
            .filter { it.extension == "xml" && it.readText().contains("@string/location_notice_short") }
            .map { it.name }
        assertTrue("the login screen must carry the notice", layouts.contains("activity_login.xml"))
        assertTrue("the account screen must carry the notice", layouts.contains("fragment_account.xml"))
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
            "the ongoing notification must carry a stop action - a foreground service an " +
                "agent cannot end at all is how an app's notifications get switched off " +
                "wholesale, which would take the daily reminder with it",
            service.contains("R.string.location_stop_duty") && service.contains("addAction"),
        )
        assertTrue("the notification must be ongoing", service.contains("setOngoing(true)"))
    }

    @Test
    fun `a dead process does not silently resume recording`() {
        // START_STICKY would have Android restart the service after the process died,
        // with no activity on screen and nobody to notice it had come back wrong. Opening
        // the app is what starts a session.
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
    fun `the permission is asked for, since nothing else asks any more`() {
        // Deleting the notice screen deleted the only place that ever requested the
        // location permission. Recording would then never start on any device, which is a
        // worse outcome than the screen was a problem: the app would appear to work and
        // quietly capture nothing. The shell every signed-in agent lands on asks instead.
        val main = code(File("src/main/java/com/lrms/recovery/ui/main/MainActivity.kt").readText())
        assertTrue(
            "MainActivity must request the location permission",
            Regex("""locationPermission\.launch[\s\S]{0,200}ACCESS_FINE_LOCATION""")
                .containsMatchIn(main),
        )
        assertTrue(
            "and start recording once it is granted",
            Regex("""ACCESS_FINE_LOCATION[\s\S]*?DutyLocationService\.start""").containsMatchIn(main),
        )
    }

    @Test
    fun `nothing is recorded before the server will accept it`() {
        // The server answers 412 until consent is on file, and the service reads a 412 as
        // withdrawn consent and stops. Posting the consent and starting the session in
        // parallel is therefore a race whose loser is a fresh install that records
        // nothing until the next launch. Consent first, then start, in one method.
        val main = code(File("src/main/java/com/lrms/recovery/ui/main/MainActivity.kt").readText())
        assertTrue(
            "consent must be posted before the service is started",
            Regex("""acceptLocationNotice[\s\S]*?DutyLocationService\.start""").containsMatchIn(main),
        )
        // There is one earlier start, and it is the latched path: consent already on file
        // from a previous launch, so there is nothing to wait for. It must be guarded by
        // that latch and not by anything looser.
        assertTrue(
            "starting without posting consent is only allowed once it has been recorded",
            Regex("""locationConsentRecorded\)[\s\S]{0,200}DutyLocationService\.start""")
                .containsMatchIn(main),
        )
        assertTrue(
            "and the latch must only be set on a successful accept",
            Regex("""accepted is ApiResult\.Success[\s\S]{0,200}locationConsentRecorded = true""")
                .containsMatchIn(main),
        )
        assertTrue(
            "with the version the server is serving, not a baked-in constant",
            Regex("""locationNotice\(\)[\s\S]{0,400}notice\.data\.version""").containsMatchIn(main),
        )
    }

    @Test
    fun `recording ends with the session it belongs to`() {
        // Signing out has to stop it. Left running, it would keep collecting against a
        // token that has just been cleared: every point refused, and an ongoing
        // notification still claiming a duty session with nobody signed in.
        val base = code(File("src/main/java/com/lrms/recovery/ui/BaseActivity.kt").readText())
        assertTrue(
            "forceSignOut must stop the duty service",
            Regex("""fun forceSignOut[\s\S]{0,400}DutyLocationService\.stop""").containsMatchIn(base),
        )

        // Losing the permission is the withdrawal now that there is no in-app one. It is
        // checked on every start, and again where the OS could revoke it mid-session.
        assertTrue(
            "a start without permission must stop the service",
            Regex("""hasLocationPermission\(\)[\s\S]{0,200}stopSelf\(\)""").containsMatchIn(code(service)),
        )
        assertTrue(
            "a permission revoked mid-session must stop the service",
            Regex("""SecurityException[\s\S]{0,200}stopSelf\(\)""").containsMatchIn(code(service)),
        )
    }

    // -----------------------------------------------------------------------
    // The agent can find it, and what they are shown is true
    // -----------------------------------------------------------------------

    @Test
    fun `granting the OS permission is the whole of the consent`() {
        // The in-app consent notice is gone, deliberately. It could disagree with the OS
        // setting: an agent could grant the permission and still have every coordinate
        // refused by the server, with nothing anywhere explaining why their visits carried
        // no location. The permission dialog is now the only question asked.
        val reachable = File("src/main/java/com/lrms/recovery")
            .walkTopDown()
            .filter { it.extension == "kt" && !it.path.contains("/ui/location/") }
            .any { it.readText().contains("LocationConsentActivity") }
        assertFalse("no screen should send an agent to a second consent question", reachable)

        // It is still RECORDED server-side, because the audit trail is the point: which
        // notice version applied, when, from which device.
        val main = code(File("src/main/java/com/lrms/recovery/ui/main/MainActivity.kt").readText())
        assertTrue(
            "consent must be posted once the permission is held",
            Regex("""ACCESS_FINE_LOCATION[\s\S]{0,600}acceptLocationNotice""").containsMatchIn(main),
        )
        assertTrue(
            "and latched so it is not re-posted on every launch",
            main.contains("locationConsentRecorded"),
        )
        assertTrue(
            "with the version the server is currently serving, not a baked-in constant",
            Regex("""locationNotice\(\)[\s\S]{0,400}notice\.data\.version""").containsMatchIn(main),
        )
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

        // No screen reads this to draw a Start/Stop toggle any more - there is no toggle.
        // It is read to decide whether entering the app needs to start a session, and it
        // is what keeps that from starting a second one.
        val main = code(File("src/main/java/com/lrms/recovery/ui/main/MainActivity.kt").readText())
        assertTrue(
            "entering the app must resume recording, and only when it is not running",
            Regex("""onResume\(\)[\s\S]*?!DutyLocationService\.isRunning[\s\S]{0,200}startRecordingLocation""")
                .containsMatchIn(main),
        )
        assertTrue(
            "a start for a session already running must be a no-op, not a second listener",
            Regex("""if \(active\)[\s\S]{0,120}return START_NOT_STICKY""").containsMatchIn(code(service)),
        )
    }

    @Test
    fun `the second consent question is gone, screen and strings together`() {
        assertFalse(
            "the consent activity must be deleted, not merely unreachable",
            File("src/main/java/com/lrms/recovery/ui/location/LocationConsentActivity.kt").exists(),
        )
        assertFalse(
            "its layout must go with it",
            File(res, "layout/activity_location_consent.xml").exists(),
        )
        assertFalse(
            "and its manifest entry",
            manifest.contains("LocationConsentActivity"),
        )
        // Strings are the part that gets left behind, and a string left defined is one
        // somebody drops into a layout without noticing it asks a question the app no
        // longer honours.
        for (dead in listOf(
            "location_notice_accept",
            "location_notice_required",
            "location_withdraw",
            "location_withdraw_confirm",
            "location_withdrawn",
            "location_off_duty",
            "location_start_duty",
            "location_permission_needed",
            "location_notice_updated",
        )) {
            assertFalse(
                "the string $dead belongs to the deleted screen and must go too",
                userVisibleStrings().contains("name=\"$dead\""),
            )
        }
    }
}
