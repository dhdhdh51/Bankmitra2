package com.lrms.recovery.ui.splash

import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.os.SystemClock
import android.view.View
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.login.ChangePasswordActivity
import com.lrms.recovery.ui.login.LoginActivity
import com.lrms.recovery.ui.main.MainActivity
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The launch screen, and the decision about where to send the user.
 *
 * A stored session is confirmed against the server rather than trusted blindly:
 * the account may have been suspended or its password reset since the last use,
 * and an agent should discover that at launch rather than when a submission fails.
 *
 * The brand lockup covers that round trip, and is guaranteed a minimum time on
 * screen (see [MIN_BRAND_MS]) because a cached session can resolve in under a
 * frame and would otherwise skip the launch screen entirely. The system splash,
 * this layout and the window background are all the same navy, so nothing about
 * the screen changes while the call is in flight - a slow connection looks like
 * loading rather than a hang.
 */
class SplashActivity : BaseActivity() {

    /** Cleared once we know where the user is going. */
    private var routing = true
    private var shownAt = 0L

    override fun onCreate(savedInstanceState: Bundle?) {
        // Must run before super.onCreate() - it swaps the launch theme for
        // postSplashScreenTheme and installs the splash on the window.
        val splash = installSplashScreen()
        super.onCreate(savedInstanceState)

        shownAt = SystemClock.elapsedRealtime()

        // Let the system splash go as soon as this layout is drawn. It can only
        // show a small centred icon; the layout behind it carries the full brand
        // lockup on the same navy, so releasing early shows MORE brand, not less.
        // Holding it instead would keep the monogram on screen and could hide the
        // lockup entirely when a cached session resolves quickly.
        splash.setKeepOnScreenCondition { false }

        setContentView(R.layout.activity_splash)

        if (!session.isLoggedIn) {
            // No session to verify, so nothing to wait for. Still show the brand
            // for its minimum so the app does not appear to blink.
            lifecycleScope.launch {
                holdForMinimum()
                routing = false
                goTo(LoginActivity::class.java)
            }
            return
        }

        // Tell the user we are waiting on the network, but only once the wait is
        // long enough to be noticeable - otherwise the message itself flickers.
        lifecycleScope.launch {
            delay(STATUS_HINT_MS)
            if (routing) {
                findViewById<View>(R.id.splash_status)?.visibility = View.VISIBLE
            }
        }

        lifecycleScope.launch {
            val result = repository.refreshProfile()
            holdForMinimum()
            routing = false

            when (result) {
                is ApiResult.Success -> {
                    if (result.data.mustChangePassword) {
                        startActivity(
                            Intent(this@SplashActivity, ChangePasswordActivity::class.java)
                                .putExtra(ChangePasswordActivity.EXTRA_FORCED, true),
                        )
                        finish()
                    } else {
                        goTo(MainActivity::class.java)
                    }
                }

                ApiResult.Unauthorised -> goTo(LoginActivity::class.java)

                // Offline: the cached session is good enough to open the app. The
                // first API call will surface the problem with a real message.
                is ApiResult.NetworkError -> goTo(MainActivity::class.java)

                is ApiResult.Failure -> goTo(MainActivity::class.java)
            }
        }
    }

    /**
     * Keeps a fast path from finishing before the brand has been seen.
     *
     * On a warm start with a cached session the profile call can return in well
     * under 100 ms, which would flash the lockup for a single frame or skip it
     * altogether. This is the only thing making the launch screen a launch screen
     * rather than a stutter.
     */
    private suspend fun holdForMinimum() {
        val remaining = MIN_BRAND_MS - (SystemClock.elapsedRealtime() - shownAt)
        if (remaining > 0) {
            delay(remaining)
        }
    }

    private fun goTo(target: Class<*>) {
        startActivity(
            Intent(this, target).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
            },
        )
        finish()

        // The branded splash already covered the launch; a second system
        // transition on top of it just looks like a stutter. The call that does
        // this was replaced in API 34, so both forms are needed to stay
        // warning-free across minSdk 24 to targetSdk 36.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            overrideActivityTransition(OVERRIDE_TRANSITION_CLOSE, 0, 0)
        } else {
            @Suppress("DEPRECATION")
            overridePendingTransition(0, 0)
        }
    }

    private companion object {
        /**
         * How long the brand lockup is guaranteed to stay on screen. Long enough
         * to read the wordmark, short enough that an agent opening the app for the
         * twentieth time that morning is not waiting on decoration.
         */
        const val MIN_BRAND_MS = 1_300L

        /** When to admit that the network is being slow. */
        const val STATUS_HINT_MS = 2_000L
    }
}
