package com.lrms.recovery

import android.app.Application
import androidx.appcompat.app.AppCompatDelegate
import com.lrms.recovery.data.LrmsRepository

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
    }
}
