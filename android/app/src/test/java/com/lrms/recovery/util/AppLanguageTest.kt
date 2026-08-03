package com.lrms.recovery.util

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The language choice, as stored and read back.
 *
 * Worth testing on the JVM rather than only on a device, because the failure modes are all
 * in the mapping and none of them are visible: a tag that does not round-trip leaves an
 * agent's choice silently ignored on the next launch, and an unrecognised tag that throws
 * would crash the app in `Application.onCreate()` - before any screen exists to show an
 * error, which on a phone is indistinguishable from the app being broken.
 */
class AppLanguageTest {

    @Test
    fun `the default follows the phone`() {
        // An agent handed a phone already in Hindi should be spoken to in Hindi without
        // finding a setting first.
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag(null))
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag(""))
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag("   "))
    }

    @Test
    fun `every choice round-trips through the stored tag`() {
        // This is what makes the choice survive a restart. If it did not round-trip the
        // switch would appear to work and then forget.
        for (language in AppLanguage.entries) {
            assertEquals(language, AppLanguage.fromTag(AppLanguage.tagFor(language)))
        }
    }

    @Test
    fun `a region-qualified tag resolves to its language`() {
        // Android 13's own per-app picker can hand back "hi-IN", and appcompat reports
        // whatever the phone resolved to. Matching the whole tag would treat that as
        // unknown and quietly drop the agent back to English.
        assertEquals(AppLanguage.HINDI, AppLanguage.fromTag("hi-IN"))
        assertEquals(AppLanguage.ENGLISH, AppLanguage.fromTag("en-GB"))
        assertEquals(AppLanguage.ENGLISH, AppLanguage.fromTag("EN"))
    }

    @Test
    fun `an unknown tag falls back rather than throwing`() {
        // Read in Application.onCreate(), where an exception is a launch crash with no
        // screen to report it on. A preferences file can hold a value written by another
        // build, and a language setting is never worth crashing over.
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag("kl"))
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag("nonsense"))
        assertEquals(AppLanguage.SYSTEM, AppLanguage.fromTag("--"))
    }

    @Test
    fun `following the phone is an empty locale list, not a named one`() {
        // The distinction matters: an empty list keeps tracking the phone if the agent
        // changes it there later. A list naming today's system language pins it forever,
        // so the "phone's language" option would stop following the phone.
        assertTrue(AppLanguage.localesFor(AppLanguage.SYSTEM).isEmpty)
        assertFalse(AppLanguage.localesFor(AppLanguage.HINDI).isEmpty)
        assertEquals("hi", AppLanguage.localesFor(AppLanguage.HINDI).toLanguageTags())
        assertEquals("en", AppLanguage.localesFor(AppLanguage.ENGLISH).toLanguageTags())
    }

    @Test
    fun `the offered choices are English, Hindi and the phone`() {
        assertEquals(
            listOf(AppLanguage.ENGLISH, AppLanguage.HINDI, AppLanguage.SYSTEM),
            AppLanguage.CHOICES,
        )
        // Every enum value is offered. A language shipped as a translation but missing
        // from the picker is a translation nobody can reach.
        assertEquals(AppLanguage.entries.size, AppLanguage.CHOICES.size)
        assertEquals(AppLanguage.entries.toSet(), AppLanguage.CHOICES.toSet())
    }

    @Test
    fun `the stored tag for following the phone is empty, not a word`() {
        // "system" or "default" as a stored value would be read back by fromTag() as a
        // language subtag and would have to be special-cased in two places. Empty means
        // empty everywhere - in preferences, in the locale list, and in the check above.
        assertEquals("", AppLanguage.tagFor(AppLanguage.SYSTEM))
    }

    @Test
    fun `the app's own default is English, not the phone's language`() {
        // What SessionStore hands back before an agent has ever opened Account ->
        // Language. Deliberately NOT SYSTEM: the app's language is meant to be
        // independent of whatever the device happens to be set to, and "follow the
        // phone" as the untouched default would make that untrue for every install
        // nobody has configured yet.
        assertEquals(AppLanguage.ENGLISH, AppLanguage.DEFAULT)
        assertTrue(AppLanguage.DEFAULT in AppLanguage.CHOICES)
    }

    // -------------------------------------------------------------------------
    // SessionStore.languageTag - guarded here as source text, the same way the
    // rest of this codebase pins wiring that a unit test cannot reach without a
    // framework Context (see ReportReminderWiringTest for the precedent). What
    // matters is not just that a fresh phone starts on English: this app is
    // installed as an UPDATE over the previous build, never fresh, so a phone
    // that had "Phone's language" tapped even once before this existed already
    // has an explicit "" sitting in preferences - and raising a bare
    // getString(KEY, default) default cannot reach a key that already has a
    // value. An explicit "has this agent ever chosen?" flag can, and has to be
    // the thing actually gating the fallback.
    // -------------------------------------------------------------------------

    private val sessionSource = File(
        "src/main/java/com/lrms/recovery/data/local/SessionStore.kt",
    ).readText()

    // Everything from "var languageTag" up to the next "var "/"fun " declaration - the
    // whole property, getter and setter both, and nothing from a neighbouring one. A
    // bare substringAfter("set(value)") on the whole file matches accessToken's setter
    // first, since it appears earlier in the file - this scopes to languageTag only.
    private val languageTagProperty = sessionSource
        .substringAfter("var languageTag: String")
        .let { after -> after.substring(0, Regex("""\n\s+(var|fun) """).find(after)?.range?.first ?: after.length) }

    @Test
    fun `languageTag is gated on an explicit chosen flag, not just a changed default`() {
        // A bare "getString(KEY_LANGUAGE, AppLanguage.DEFAULT.tag)" looks right and is
        // not: it only helps a phone where the key was never written at all. Every
        // phone this app is actually installed on already has a build's worth of
        // history in the same preferences file.
        val getter = languageTagProperty.substringBefore("set(value)")
        assertTrue(
            "the getter must consult a chosen/explicit flag, not just fall back on a default",
            getter.contains("KEY_LANGUAGE_CHOSEN"),
        )
        assertTrue(
            "the flag must gate the untouched case: DEFAULT.tag when nothing has been chosen",
            Regex("""KEY_LANGUAGE_CHOSEN[\s\S]*AppLanguage\.DEFAULT\.tag""").containsMatchIn(getter)
                || Regex("""AppLanguage\.DEFAULT\.tag[\s\S]*KEY_LANGUAGE_CHOSEN""").containsMatchIn(getter),
        )
    }

    @Test
    fun `setting the language always records that a choice was made`() {
        val setter = languageTagProperty.substringAfter("set(value)")
        assertTrue(
            "the setter must record KEY_LANGUAGE_CHOSEN = true whenever it runs",
            Regex("""putBoolean\(\s*KEY_LANGUAGE_CHOSEN,\s*true\s*\)""").containsMatchIn(setter),
        )
    }

    @Test
    fun `nothing outside AccountFragment's dialog writes languageTag`() {
        // The one and only place a real choice happens. Anything else assigning to
        // languageTag would mark an agent as having chosen a language they never
        // picked, and lock them out of the English default they should still be on.
        // Excludes SessionStore.kt itself, which legitimately assigns to the backing
        // `value` parameter inside the setter, not to `languageTag`.
        val writers = File("src/main/java/com/lrms/recovery")
            .walkTopDown()
            .filter { it.isFile && it.extension == "kt" }
            .filter { it.name != "SessionStore.kt" }
            .filter { it.readText().contains("languageTag =") }
            .map { it.path }
            .toList()
        assertEquals(
            "only AccountFragment.kt may assign to session.languageTag, found: $writers",
            listOf("src/main/java/com/lrms/recovery/ui/account/AccountFragment.kt"),
            writers,
        )
    }
}
