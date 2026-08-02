package com.lrms.recovery.util

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
}
