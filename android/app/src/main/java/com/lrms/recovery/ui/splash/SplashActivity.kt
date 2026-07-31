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
 * The branded splash covers that round trip. It is held on screen deliberately
 * (see [MIN_SPLASH_MS]) because the alternative is worse in both directions: let
 * it go immediately and a cached session produces a one-frame flicker of the
 * brand; leave the routing until after it and a slow connection shows a bare
 * window. The layout behind the splash carries the same navy and the same mark,
 * so whichever finishes first the screen never changes appearance.
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

        // Hold the splash until the destination is known, but never past
        // MAX_SPLASH_MS: an unreachable server must not freeze the launch. When
        // it is dismissed on the timeout the activity's own layout is already
        // behind it, showing the same brand plus a spinner.
        splash.setKeepOnScreenCondition {
            val elapsed = SystemClock.elapsedRealtime() - shownAt
            elapsed < MIN_SPLASH_MS || (routing && elapsed < MAX_SPLASH_MS)
        }

        setContentView(R.layout.activity_splash)

        if (!session.isLoggedIn) {
            // No session to verify, so nothing to wait for. Still let the splash
            // serve its minimum so the app does not appear to blink.
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

    /** Keeps a fast path from finishing before the brand has been seen. */
    private suspend fun holdForMinimum() {
        val remaining = MIN_SPLASH_MS - (SystemClock.elapsedRealtime() - shownAt)
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
        /** Long enough to register as a launch screen, short enough not to annoy. */
        const val MIN_SPLASH_MS = 600L

        /** Beyond this the splash gives way to the in-app loading screen. */
        const val MAX_SPLASH_MS = 2_500L

        /** When to admit that the network is being slow. */
        const val STATUS_HINT_MS = 2_000L
    }
}
