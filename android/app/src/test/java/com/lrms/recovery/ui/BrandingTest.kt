package com.lrms.recovery.ui

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Pins the product name and the brand palette.
 *
 * Two things went wrong here that only a phone could reveal. The app was called
 * LRMS in a dozen strings after the product had been named D2 Recovery. And the
 * UI used a brighter blue borrowed from the web panel, so tapping a navy-and-gold
 * launcher icon opened a plainly blue app - the icon and the app did not look
 * like the same product.
 *
 * Both are declarative facts in resource files, so they can be checked here
 * rather than discovered by a user.
 */
class BrandingTest {

    private val res = File("src/main/res")

    private fun text(path: String): String {
        val file = File(res, path)
        assertTrue("missing resource: $path", file.isFile)
        return file.readText()
    }

    /** Value of a <color> entry, as an #AARRGGBB or #RRGGBB literal. */
    private fun colour(name: String): String {
        val xml = text("values/colors.xml")
        val match = Regex("""<color name="${Regex.escape(name)}">\s*(#[0-9A-Fa-f]{6,8})\s*</color>""")
            .find(xml)
        requireNotNull(match) { "colour '$name' is not declared" }
        return match.groupValues[1].lowercase()
    }

    /** Resolves a theme item to the colour it ends up as. */
    private fun themeColour(style: String, item: String): String {
        val themes = text("values/themes.xml")
        val body = Regex(
            """<style name="${Regex.escape(style)}".*?</style>""",
            RegexOption.DOT_MATCHES_ALL,
        ).find(themes)?.value
        requireNotNull(body) { "style '$style' is not declared" }

        val value = Regex("""<item name="${Regex.escape(item)}">([^<]+)</item>""")
            .find(body)?.groupValues?.get(1)?.trim()
        requireNotNull(value) { "'$item' is not set on '$style'" }

        return when {
            value.startsWith("@color/") -> colour(value.removePrefix("@color/"))
            value.startsWith("#") -> value.lowercase()
            else -> error("'$item' on '$style' is not a colour: $value")
        }
    }

    // -----------------------------------------------------------------------
    // Contrast
    // -----------------------------------------------------------------------

    /** WCAG relative luminance. */
    private fun luminance(hex: String): Double {
        val rgb = hex.removePrefix("#").let { if (it.length == 8) it.substring(2) else it }
        val channels = listOf(0, 2, 4).map { i ->
            val v = rgb.substring(i, i + 2).toInt(16) / 255.0
            if (v <= 0.03928) v / 12.92 else Math.pow((v + 0.055) / 1.055, 2.4)
        }
        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
    }

    private fun contrast(a: String, b: String): Double {
        val la = luminance(a)
        val lb = luminance(b)
        return (maxOf(la, lb) + 0.05) / (minOf(la, lb) + 0.05)
    }

    // -----------------------------------------------------------------------
    // Name
    // -----------------------------------------------------------------------

    /** Every shipped strings file. Add a locale here when one is added to res/. */
    private val LOCALES = listOf("values/strings.xml", "values-hi/strings.xml")

    @Test
    fun `the app is called D2 Recovery`() {
        val strings = text("values/strings.xml")
        // The attribute list is matched loosely on purpose: app_name carries
        // translatable="false" (a product name is not translated), and a regex pinned to
        // `name="app_name">` stopped matching the moment that was added - which made this
        // test pass by finding nothing rather than by finding the right thing.
        val appName = Regex("""<string name="app_name"[^>]*>([^<]+)</string>""")
            .find(strings)?.groupValues?.get(1)
        assertEquals("D2 Recovery", appName)
    }

    @Test
    fun `no string shown to a user still says LRMS`() {
        // Every locale, not only the default. A stale product name left in a translation
        // is shown to exactly the people least able to report it.
        val strings = LOCALES.joinToString("\n") { text(it) }
        val offenders = Regex("""<string name="([^"]+)"[^>]*>([^<]*)</string>""")
            .findAll(strings)
            .filter { it.groupValues[2].contains("LRMS", ignoreCase = false) }
            .map { it.groupValues[1] }
            .toList()

        assertTrue(
            "these strings still carry the old product name: $offenders",
            offenders.isEmpty(),
        )
    }

    @Test
    fun `the launch screen carries the product name, not a hardcoded one`() {
        val layout = text("layout/activity_splash.xml")
        assertTrue(
            "the splash must use @string/app_name so a rename cannot miss it",
            layout.contains("@string/app_name"),
        )
    }

    @Test
    fun `no screen draws an initial in place of the logo`() {
        // The login screen had an "LR" tile - the initial of the old product name,
        // typed into a TextView - sitting next to the app name. An initial is not a
        // logo, and a rename cannot reach one that is drawn as text.
        val offenders = (File(res, "layout").listFiles() ?: emptyArray())
            .filter { it.extension == "xml" }
            .filter { file ->
                Regex("""android:text="LR[SM]?"""").containsMatchIn(file.readText())
            }
            .map { it.name }

        assertTrue("these layouts draw an initial instead of the logo: $offenders", offenders.isEmpty())
    }

    @Test
    fun `the supplied artwork ships and is used where it is meant to be`() {
        // Generated from docs/brand by tools/prepare-brand-assets.py. The monogram
        // crop is a panel asset only - the app has room for the full lockup on both
        // screens that show branding, so a mark drawable here would be unreferenced.
        assertTrue(
            "missing drawable-nodpi/brand_lockup.webp - run tools/prepare-brand-assets.py",
            File(res, "drawable-nodpi/brand_lockup.webp").isFile,
        )

        val unused = (File(res, "drawable-nodpi").listFiles() ?: emptyArray())
            .map { it.name }
            .filter { it.startsWith("brand_") && it != "brand_lockup.webp" }
        assertTrue("these brand drawables are shipped but never used: $unused", unused.isEmpty())

        assertTrue(
            "the launch screen must show the full lockup",
            text("layout/activity_splash.xml").contains("@drawable/brand_lockup"),
        )
        assertTrue(
            "the login screen must show the full lockup",
            text("layout/activity_login.xml").contains("@drawable/brand_lockup"),
        )
    }

    // -----------------------------------------------------------------------
    // Palette
    // -----------------------------------------------------------------------

    @Test
    fun `the UI primary is the same navy as the launcher icon`() {
        // The icon background and the app's primary have to be one colour, or the
        // app that opens does not look like the icon that was tapped.
        assertEquals(
            "lrms_primary must be the brand navy",
            colour("lrms_brand_navy"),
            colour("lrms_primary"),
        )

        val launcherBg = Regex("""<color name="ic_launcher_background">\s*(#[0-9A-Fa-f]{6,8})""")
            .find(text("values/ic_launcher_background.xml"))
            ?.groupValues?.get(1)?.lowercase()
        assertEquals(
            "the launcher icon background must be the brand navy too",
            colour("lrms_brand_navy"),
            launcherBg,
        )
    }

    @Test
    fun `the splash background is the primary, so the hand-over is invisible`() {
        assertEquals(
            colour("lrms_primary"),
            themeColour("Theme.LRMS.Splash", "windowSplashScreenBackground"),
        )
        assertEquals(
            colour("lrms_primary"),
            themeColour("Theme.LRMS.Loading", "android:windowBackground"),
        )
    }

    @Test
    fun `gold is the accent`() {
        assertEquals(
            colour("lrms_brand_gold"),
            themeColour("Theme.LRMS", "colorSecondary"),
        )
    }

    @Test
    fun `text on the brand colours is readable`() {
        val onPrimary = contrast(
            themeColour("Theme.LRMS", "colorPrimary"),
            themeColour("Theme.LRMS", "colorOnPrimary"),
        )
        assertTrue(
            "colorOnPrimary over colorPrimary is only %.1f:1".format(onPrimary),
            onPrimary >= 4.5,
        )

        val onSecondary = contrast(
            themeColour("Theme.LRMS", "colorSecondary"),
            themeColour("Theme.LRMS", "colorOnSecondary"),
        )
        assertTrue(
            "colorOnSecondary over gold is only %.1f:1".format(onSecondary),
            onSecondary >= 4.5,
        )

        // The reason onSecondary is ink and not white, asserted so nobody
        // "tidies" it back to white: white on this gold is about 2:1.
        val whiteOnGold = contrast(colour("lrms_brand_gold"), "#ffffff")
        assertTrue(
            "white on gold measures %.1f:1, so it must not be used".format(whiteOnGold),
            whiteOnGold < 3.0,
        )
    }

    @Test
    fun `dark mode does not use the navy it cannot show`() {
        val nightThemes = File(res, "values-night/themes.xml").readText()
        val nightPrimary = Regex("""<item name="colorPrimary">([^<]+)</item>""")
            .find(nightThemes)?.groupValues?.get(1)?.trim()
        requireNotNull(nightPrimary) { "night colorPrimary is not set" }
        assertTrue(
            "dark mode must not reuse the light-mode navy: it is invisible on a dark background",
            nightPrimary != "@color/lrms_primary" && nightPrimary != "@color/lrms_brand_navy",
        )

        val nightBg = colour("lrms_background_night")
        val nightOnBg = colour(nightPrimary.removePrefix("@color/"))
        val ratio = contrast(nightBg, nightOnBg)
        assertTrue(
            "the night primary only reaches %.1f:1 against the night background".format(ratio),
            ratio >= 4.5,
        )
    }
}
