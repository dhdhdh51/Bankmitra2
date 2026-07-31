package com.lrms.recovery.ui

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.view.View
import androidx.fragment.app.Fragment
import com.google.android.material.snackbar.Snackbar
import com.lrms.recovery.LrmsApp
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.LrmsRepository

/**
 * Shared behaviour for the tab fragments.
 */
abstract class BaseFragment : Fragment() {

    protected val repository: LrmsRepository
        get() = (requireActivity().application as LrmsApp).repository

    protected val session get() = repository.session

    /**
     * @return true when the session had expired and the user was signed out, so
     *         the caller should stop.
     */
    protected fun handleFailure(result: ApiResult<*>, anchor: View? = null): Boolean {
        if (result is ApiResult.Unauthorised) {
            (activity as? BaseActivity)?.forceSignOut()
            return true
        }

        val message = result.errorMessage(getString(R.string.error_unknown))
        if (message.isNotBlank()) {
            showMessage(message, anchor)
        }

        return false
    }

    protected fun showMessage(message: String, anchor: View? = null) {
        val target = anchor ?: view ?: return
        Snackbar.make(target, message, Snackbar.LENGTH_LONG).show()
    }

    /**
     * Opens the dialler pre-filled with the number.
     *
     * ACTION_DIAL rather than ACTION_CALL: the agent confirms the call, so the app
     * does not need to hold a dangerous permission to place calls silently.
     */
    protected fun dialNumber(number: String?) {
        if (number.isNullOrBlank()) {
            showMessage(getString(R.string.not_available))
            return
        }

        try {
            startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number")))
        } catch (error: ActivityNotFoundException) {
            showMessage("No dialler app is available on this device.")
        }
    }
}
