package com.lrms.recovery

import android.app.Application
import androidx.appcompat.app.AppCompatDelegate
import com.lrms.recovery.data.LrmsRepository
import com.lrms.recovery.reminder.ReportReminderScheduler

/**
 * Application entry point.
 *
 * The repository is created once and shared, so every screen reads the same
 * session state and the same Retrofit stack.
 */
class LrmsApp : Application() {

    val repository: LrmsRepository by lazy { LrmsRepository(this) }

    override fun onCreate() {
        super.onCreate()

        // Apply the saved light/dark preference before the first screen inflates.
        AppCompatDelegate.setDefaultNightMode(
            when (repository.session.themeMode) {
                AppCompatDelegate.MODE_NIGHT_NO -> AppCompatDelegate.MODE_NIGHT_NO
                AppCompatDelegate.MODE_NIGHT_YES -> AppCompatDelegate.MODE_NIGHT_YES
                else -> AppCompatDelegate.MODE_NIGHT_FOLLOW_SYSTEM
            },
        )

        // Re-register the daily reminder from whatever is cached. Cheap, idempotent,
        // and it covers the cases the boot receiver does not: a process killed by the
        // system, a "clear all" from the task switcher, a phone that was off at the
        // time the alarm should have fired.
        ReportReminderScheduler.reschedule(this, repository.session)
    }
}
