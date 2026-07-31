package com.lrms.recovery.ui.login

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.activity.OnBackPressedCallback
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.ActivityChangePasswordBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.main.MainActivity
import kotlinx.coroutines.launch

/**
 * Change password, and the forced first-login change.
 *
 * When [EXTRA_FORCED] is set the screen cannot be dismissed: the account is
 * carrying a password an administrator handed over, so it must be replaced before
 * the agent reaches any borrower data.
 */
class ChangePasswordActivity : BaseActivity() {

    private lateinit var binding: ActivityChangePasswordBinding

    private val forced: Boolean by lazy { intent.getBooleanExtra(EXTRA_FORCED, false) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityChangePasswordBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(!forced)
        binding.toolbar.setNavigationOnClickListener { finish() }

        binding.textForcedNotice.visibility = if (forced) View.VISIBLE else View.GONE

        onBackPressedDispatcher.addCallback(
            this,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    if (forced) {
                        // Signing out is the only way past a forced change.
                        showMessage(R.string.change_password_forced, binding.root)
                    } else {
                        finish()
                    }
                }
            },
        )

        binding.buttonSubmit.setOnClickListener { submit() }
    }

    private fun submit() {
        val current = binding.inputCurrent.text?.toString().orEmpty()
        val password = binding.inputNew.text?.toString().orEmpty()
        val confirm = binding.inputConfirm.text?.toString().orEmpty()

        binding.fieldCurrent.error = null
        binding.fieldNew.error = null
        binding.fieldConfirm.error = null

        var valid = true

        if (current.isEmpty()) {
            binding.fieldCurrent.error = getString(R.string.login_error_password)
            valid = false
        }
        if (password.length < MIN_PASSWORD_LENGTH) {
            binding.fieldNew.error = getString(R.string.password_too_short)
            valid = false
        }
        if (password != confirm) {
            binding.fieldConfirm.error = getString(R.string.password_mismatch)
            valid = false
        }
        if (valid && password == current) {
            binding.fieldNew.error = "Choose a password different from the current one"
            valid = false
        }

        if (!valid) return

        setLoading(true)

        lifecycleScope.launch {
            val result = repository.changePassword(current, password)
            setLoading(false)

            when (result) {
                is ApiResult.Success -> {
                    showMessage(result.message.ifBlank { "Your password has been updated." }, binding.root)

                    // Continue into the app after a forced change, otherwise
                    // simply return to where the user came from.
                    if (forced) {
                        startActivity(
                            Intent(this@ChangePasswordActivity, MainActivity::class.java).apply {
                                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                            },
                        )
                    }
                    finish()
                }

                is ApiResult.Failure -> {
                    result.fieldError("current_password")?.let { binding.fieldCurrent.error = it }
                    result.fieldError("password")?.let { binding.fieldNew.error = it }
                    if (result.fieldErrors.isEmpty()) {
                        showMessage(result.message, binding.root)
                    }
                }

                else -> handleFailure(result, binding.root)
            }
        }
    }

    private fun setLoading(loading: Boolean) {
        binding.progress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.buttonSubmit.isEnabled = !loading
    }

    companion object {
        const val EXTRA_FORCED = "forced"
        private const val MIN_PASSWORD_LENGTH = 8
    }
}
