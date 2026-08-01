package com.lrms.recovery.ui

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Guards the launch screen and the launcher icon.
 *
 * These are resource-level facts that no other test can reach: a wrong theme
 * parent or a missing icon configuration compiles perfectly and only shows up on
 * a real phone, which is exactly how the app shipped with a splash screen that
 * never appeared and no launcher icon on Android 7.
 *
 * The assertions read the resource XML directly rather than going through
 * Robolectric, because what matters here is what was declared - Robolectric would
 * happily resolve a theme that the platform then ignores.
 */
class SplashBrandingTest {

    private val res = File("src/main/res")

    private fun text(path: String): String {
        val file = File(res, path)
        assertTrue("missing resource: $path", file.isFile)
        return file.readText()
    }

    /** Drops `//` and block comments so prose cannot be mistaken for code. */
    private fun stripComments(source: String): String = source
        .replace(Regex("""/\*.*?\*/""", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("""//[^\n]*"""), "")

    /** Every android:pathData value, in document order. */
    private fun pathData(xml: String): List<String> =
        Regex("""android:pathData="([^"]+)"""")
            .findAll(xml)
            .map { it.groupValues[1].replace(Regex("\\s+"), " ").trim() }
            .toList()

    // -----------------------------------------------------------------------
    // The launch screen
    // -----------------------------------------------------------------------

    @Test
    fun `splash theme is built on the splashscreen library`() {
        val themes = text("values/themes.xml")
        val splash = Regex(
            """<style name="Theme\.LRMS\.Splash"[^>]*parent="([^"]+)"[^>]*>(.*?)</style>""",
            RegexOption.DOT_MATCHES_ALL,
        ).find(themes)

        requireNotNull(splash) { "Theme.LRMS.Splash is not declared" }

        // Parenting on Theme.LRMS instead is what made the splash invisible: it
        // reduced the whole launch screen to a window background colour.
        assertEquals(
            "Theme.LRMS.Splash must inherit Theme.SplashScreen",
            "Theme.SplashScreen",
            splash.groupValues[1],
        )

        val body = splash.groupValues[2]
        assertTrue(
            "the splash needs a background colour",
            body.contains("windowSplashScreenBackground"),
        )
        assertTrue(
            "the splash needs the brand mark, otherwise the platform draws its default icon",
            body.contains("""name="windowSplashScreenAnimatedIcon">@drawable/ic_splash_logo"""),
        )
        assertTrue(
            "postSplashScreenTheme must hand over to a real theme",
            body.contains("postSplashScreenTheme"),
        )
    }

    @Test
    fun `the splash hands over to a theme with the same background`() {
        val themes = text("values/themes.xml")
        val loading = Regex(
            """<style name="Theme\.LRMS\.Loading".*?</style>""",
            RegexOption.DOT_MATCHES_ALL,
        ).find(themes)?.value

        requireNotNull(loading) { "Theme.LRMS.Loading is not declared" }
        // A different windowBackground here means the brand visibly flashes into
        // a white rectangle while the session call is still in flight.
        assertTrue(
            "the post-splash theme must stay on brand navy",
            loading.contains("""name="android:windowBackground">@color/lrms_brand_navy"""),
        )
    }

    @Test
    fun `the manifest launches through the splash theme`() {
        val manifest = text("../AndroidManifest.xml")
        val activity = Regex(
            """<activity[^>]*SplashActivity.*?</activity>""",
            RegexOption.DOT_MATCHES_ALL,
        ).find(manifest)?.value

        requireNotNull(activity) { "SplashActivity is not in the manifest" }
        assertTrue(
            "SplashActivity must use Theme.LRMS.Splash",
            activity.contains("@style/Theme.LRMS.Splash"),
        )
        assertTrue(
            "SplashActivity must be the launcher entry point",
            activity.contains("android.intent.category.LAUNCHER"),
        )
    }

    @Test
    fun `the splash activity installs and holds the splash`() {
        // Comments are stripped first. The prose above installSplashScreen()
        // mentions super.onCreate(), and matching that instead of the real call
        // made this assertion fail against correct code - the same way a panel
        // smoke check once passed against its own HTML comment.
        val source = stripComments(
            File("src/main/java/com/lrms/recovery/ui/splash/SplashActivity.kt").readText(),
        )

        assertTrue(
            "installSplashScreen() is what applies postSplashScreenTheme",
            source.contains("installSplashScreen()"),
        )
        // Called after super.onCreate() the theme swap has already been missed.
        assertTrue(
            "installSplashScreen() must run before super.onCreate()",
            source.indexOf("installSplashScreen()") < source.indexOf("super.onCreate"),
        )
        assertTrue(
            "the system splash must be released so the lockup behind it is visible",
            source.contains("setKeepOnScreenCondition"),
        )
        // Without a floor the launch screen is skipped entirely on a warm start
        // with a cached session, which is how "the image never appears" happens.
        assertTrue(
            "the lockup needs a guaranteed minimum time on screen",
            source.contains("MIN_BRAND_MS"),
        )
        assertTrue(
            "routing must wait for that minimum",
            source.contains("holdForMinimum()"),
        )
    }

    @Test
    fun `the loading layout exists behind the splash`() {
        val layout = text("layout/activity_splash.xml")
        // The full lockup, not the monogram: the system splash slot can only show
        // a small centred icon, so this layout is the only place the supplied
        // artwork can appear at launch.
        assertTrue("must show the brand lockup", layout.contains("@drawable/brand_lockup"))
        assertTrue("must stay on brand navy", layout.contains("@color/lrms_brand_navy"))
        assertTrue("must show progress", layout.contains("CircularProgressIndicator"))
        assertTrue(
            "the slow-network hint must start hidden",
            Regex("""splash_status(.|\n)*?android:visibility="invisible"""").containsMatchIn(layout),
        )
    }

    // -----------------------------------------------------------------------
    // Artwork
    // -----------------------------------------------------------------------

    @Test
    fun `the splash mark and the launcher mark are the same artwork`() {
        val splash = pathData(text("drawable/ic_splash_logo.xml"))
        val launcher = pathData(text("drawable/ic_launcher_foreground.xml"))

        assertTrue("the splash logo has no paths", splash.isNotEmpty())
        // The splash copy differs only in viewport and a translate group. If the
        // paths ever diverge the app shows two different logos.
        assertEquals(
            "ic_splash_logo.xml and ic_launcher_foreground.xml have drifted apart",
            launcher,
            splash,
        )
    }

    @Test
    fun `the splash mark fills its viewport instead of the masking safe zone`() {
        val splash = text("drawable/ic_splash_logo.xml")
        // 108 is the adaptive-icon canvas, where the art occupies the middle 66dp.
        // Reusing it here is what renders the mark two thirds size on the splash.
        assertFalse(
            "the splash logo must not reuse the 108dp masking canvas",
            splash.contains("""android:viewportWidth="108""""),
        )
        assertTrue(
            "the artwork must be shifted onto its own bounds",
            splash.contains("android:translateX"),
        )
    }

    // -----------------------------------------------------------------------
    // Launcher icon coverage
    // -----------------------------------------------------------------------

    @Test
    fun `every supported api level has a launcher icon`() {
        val minSdk = Regex("""minSdk\s*=\s*(\d+)""")
            .find(File("build.gradle.kts").readText())
            ?.groupValues?.get(1)?.toInt()
        requireNotNull(minSdk) { "could not read minSdk" }

        assertTrue(
            "adaptive icon missing",
            File(res, "mipmap-anydpi-v26/ic_launcher.xml").isFile,
        )

        // The -v26 qualifier excludes anything older, so a minSdk below 26 needs
        // an unqualified fallback or those devices get no icon at all.
        if (minSdk < 26) {
            for (name in listOf("ic_launcher", "ic_launcher_round")) {
                assertTrue(
                    "minSdk is $minSdk, so mipmap-anydpi/$name.xml is required " +
                        "as the pre-adaptive fallback",
                    File(res, "mipmap-anydpi/$name.xml").isFile,
                )
            }

            // The fallback must reuse the adaptive layers rather than carry a
            // second copy of the mark.
            val fallback = text("mipmap-anydpi/ic_launcher.xml")
            assertTrue(
                "the fallback must reuse the shared foreground",
                fallback.contains("@drawable/ic_launcher_foreground"),
            )
            assertTrue(
                "the fallback must reuse the shared background colour",
                fallback.contains("@color/ic_launcher_background"),
            )
        }
    }
}
