package com.lrms.recovery.util

import androidx.appcompat.app.AppCompatDelegate
import androidx.core.os.LocaleListCompat

/**
 * The app's language, as the agent chooses it.
 *
 * WHY A PER-APP LOCALE AND NOT A CONFIGURATION OVERRIDE
 *
 * The obvious implementation is to wrap every base context, swap the `Locale` in a
 * `Configuration` and recreate the activity. It is also the one that crashes: the wrapped
 * context and the one Android hands to a dialog, a `WebView` or a notification builder
 * disagree, the resource cache holds strings from the previous locale, and anything that
 * outlives the activity - a foreground service, an alarm receiver, a notification channel
 * name - reads whichever locale it happened to be created under.
 *
 * `AppCompatDelegate.setApplicationLocales()` is the platform's own answer (framework on
 * API 33+, backported below it). Android performs the switch, recreates what needs
 * recreating, and everything that asks for a string afterwards - including code with no
 * `Activity` anywhere near it - gets the chosen language.
 *
 * WHY THE CHOICE IS ALSO STORED HERE
 *
 * appcompat can persist it for us, but only with `autoStoreLocales` metadata and a
 * synchronous read on the main thread at startup. We already have a preferences file, so
 * the tag is written there and reapplied in `LrmsApp.onCreate()`. That also means the
 * stored value is inspectable and testable without an Android framework at all, which is
 * what the unit tests exercise.
 *
 * The class is deliberately free of Android context: [fromTag], [tagFor] and [labelFor]
 * are pure, so the mapping between what is stored, what is applied and what the agent
 * sees is covered by ordinary JVM tests rather than by hoping.
 */
enum class AppLanguage(
    /** What goes in preferences and into a BCP-47 locale list. Empty = follow the phone. */
    val tag: String,
) {
    /**
     * Whatever the phone is set to.
     *
     * The default, and it stays the default: an agent handed a phone already in Hindi
     * should not have to find a setting to be spoken to in Hindi.
     */
    SYSTEM(""),
    ENGLISH("en"),
    HINDI("hi"),
    ;

    companion object {

        /**
         * The stored tag read back, tolerantly.
         *
         * Anything unrecognised becomes [SYSTEM] rather than throwing. A preferences file
         * can carry a value written by an older or newer build, and a language setting is
         * not worth crashing an app over - falling back to the phone's language is always
         * a defensible answer.
         */
        fun fromTag(tag: String?): AppLanguage {
            val wanted = tag?.trim()?.lowercase().orEmpty()
            if (wanted.isEmpty()) return SYSTEM

            // Matched on the language subtag so a stored "hi-IN" or "en-GB" still resolves.
            val language = wanted.substringBefore('-')
            return entries.firstOrNull { it.tag.isNotEmpty() && it.tag == language } ?: SYSTEM
        }

        /** The tag to store for a choice. */
        fun tagFor(language: AppLanguage): String = language.tag

        /**
         * The locale list to hand to appcompat.
         *
         * An EMPTY list is the documented way to say "go back to the system default", and
         * it is not the same as a list containing the system language: the empty list
         * keeps following the phone if the agent later changes it there.
         */
        fun localesFor(language: AppLanguage): LocaleListCompat =
            if (language == SYSTEM) {
                LocaleListCompat.getEmptyLocaleList()
            } else {
                LocaleListCompat.forLanguageTags(language.tag)
            }

        /** The order the choices are offered in, English first because the code is. */
        val CHOICES: List<AppLanguage> = listOf(ENGLISH, HINDI, SYSTEM)

        /** Applies a choice. Safe to call with the value already in force - it is a no-op. */
        fun apply(language: AppLanguage) {
            AppCompatDelegate.setApplicationLocales(localesFor(language))
        }
    }
}
