package com.lrms.recovery.ui.splash

import android.content.Intent
import android.os.Bundle
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.login.ChangePasswordActivity
import com.lrms.recovery.ui.login.LoginActivity
import com.lrms.recovery.ui.main.MainActivity
import kotlinx.coroutines.launch

/**
 * Decides where to send the user on launch.
 *
 * A stored session is confirmed against the server rather than trusted blindly:
 * the account may have been suspended or its password reset since the last use,
 * and an agent should discover that at launch rather than when a submission fails.
 */
class SplashActivity : BaseActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        if (!session.isLoggedIn) {
            goTo(LoginActivity::class.java)
            return
        }

        lifecycleScope.launch {
            when (val result = repository.refreshProfile()) {
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

    private fun goTo(target: Class<*>) {
        startActivity(
            Intent(this, target).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
            },
        )
        finish()
    }
}
