package com.lrms.recovery.ui

import android.content.Intent
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.snackbar.Snackbar
import com.lrms.recovery.LrmsApp
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.LrmsRepository
import com.lrms.recovery.location.DutyLocationService
import com.lrms.recovery.ui.login.LoginActivity

/**
 * Shared behaviour for every screen: access to the repository, one place that
 * handles an expired session, and consistent error messaging.
 */
abstract class BaseActivity : AppCompatActivity() {

    protected val repository: LrmsRepository
        get() = (application as LrmsApp).repository

    protected val session get() = repository.session

    /**
     * Handles a failed call in the one way that is right for its kind.
     *
     * @return true when the failure was an expired session and the user has been
     *         sent back to the login screen, so the caller should stop.
     */
    protected fun handleFailure(result: ApiResult<*>, anchor: View? = null): Boolean {
        if (result is ApiResult.Unauthorised) {
            forceSignOut()
            return true
        }

        val message = result.errorMessage(getString(R.string.error_unknown))
        if (message.isNotBlank()) {
            showMessage(message, anchor)
        }

        return false
    }

    /**
     * Clears the session and returns to login, closing the back stack.
     * Public so a hosted fragment can trigger it too.
     */
    fun forceSignOut(message: String? = null) {
        // Recording follows the session out. Left running it would keep collecting
        // against a token that has just been cleared - every point refused, the ongoing
        // notification still claiming a duty session, and nobody signed in to explain it.
        DutyLocationService.stop(this)
        session.clearSession()

        startActivity(
            Intent(this, LoginActivity::class.java).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                putExtra(
                    LoginActivity.EXTRA_MESSAGE,
                    message ?: getString(R.string.error_session_expired),
                )
            },
        )
        finish()
    }

    protected fun showMessage(message: String, anchor: View? = null) {
        val target = anchor ?: findViewById(android.R.id.content)
        Snackbar.make(target, message, Snackbar.LENGTH_LONG).show()
    }

    protected fun showMessage(resId: Int, anchor: View? = null) =
        showMessage(getString(resId), anchor)
}
