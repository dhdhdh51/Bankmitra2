package com.lrms.recovery.ui.login

import android.os.Bundle
import android.view.View
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.databinding.ActivityForgotPasswordBinding
import com.lrms.recovery.ui.BaseActivity
import kotlinx.coroutines.launch

/**
 * Password reset by OTP.
 *
 * Two steps in one screen: request an OTP, then enter it with a new password.
 * When the SMS gateway is not configured the server says so, and the screen tells
 * the agent to ask an administrator instead of leaving them stuck.
 */
class ForgotPasswordActivity : BaseActivity() {

    private lateinit var binding: ActivityForgotPasswordBinding

    private var otpRequested = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityForgotPasswordBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { finish() }

        session.lastEmployeeCode?.let { binding.inputEmployeeCode.setText(it) }

        binding.buttonSendOtp.setOnClickListener { requestOtp() }
        binding.buttonReset.setOnClickListener { resetPassword() }
        binding.buttonResend.setOnClickListener { requestOtp() }

        showStep(step2 = false)
    }

    private fun showStep(step2: Boolean) {
        otpRequested = step2
        binding.groupStep1.visibility = if (step2) View.GONE else View.VISIBLE
        binding.groupStep2.visibility = if (step2) View.VISIBLE else View.GONE
    }

    private fun requestOtp() {
        val employeeCode = binding.inputEmployeeCode.text?.toString()?.trim().orEmpty()

        binding.fieldEmployeeCode.error = null
        if (employeeCode.isEmpty()) {
            binding.fieldEmployeeCode.error = getString(R.string.login_error_code)
            return
        }

        setLoading(true)

        lifecycleScope.launch {
            val result = repository.forgotPassword(employeeCode)
            setLoading(false)

            when (result) {
                is ApiResult.Success -> {
                    val payload = result.data

                    if (payload.otpSent) {
                        val masked = payload.mobileMasked
                        binding.textOtpSent.text = if (masked.isNullOrBlank()) {
                            result.message
                        } else {
                            "An OTP has been sent to $masked."
                        }
                        showStep(step2 = true)
                    } else {
                        // Either the account does not exist or SMS is unavailable.
                        // The server intentionally does not distinguish the two.
                        showMessage(result.message, binding.root)
                    }
                }

                else -> handleFailure(result, binding.root)
            }
        }
    }

    private fun resetPassword() {
        val employeeCode = binding.inputEmployeeCode.text?.toString()?.trim().orEmpty()
        val otp = binding.inputOtp.text?.toString()?.trim().orEmpty()
        val password = binding.inputNewPassword.text?.toString().orEmpty()
        val confirm = binding.inputConfirmPassword.text?.toString().orEmpty()

        binding.fieldOtp.error = null
        binding.fieldNewPassword.error = null
        binding.fieldConfirmPassword.error = null

        var valid = true

        if (otp.length < 4) {
            binding.fieldOtp.error = "Enter the OTP you received"
            valid = false
        }
        if (password.length < MIN_PASSWORD_LENGTH) {
            binding.fieldNewPassword.error = getString(R.string.password_too_short)
            valid = false
        }
        if (password != confirm) {
            binding.fieldConfirmPassword.error = getString(R.string.password_mismatch)
            valid = false
        }

        if (!valid) return

        setLoading(true)

        lifecycleScope.launch {
            val result = repository.resetPassword(employeeCode, otp, password)
            setLoading(false)

            when (result) {
                is ApiResult.Success -> {
                    setResult(RESULT_OK)
                    showMessage(
                        result.message.ifBlank { "Your password has been reset. Please sign in." },
                        binding.root,
                    )
                    binding.root.postDelayed({ finish() }, FINISH_DELAY_MS)
                }

                is ApiResult.Failure -> {
                    result.fieldError("otp")?.let { binding.fieldOtp.error = it }
                    result.fieldError("password")?.let { binding.fieldNewPassword.error = it }
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
        binding.buttonSendOtp.isEnabled = !loading
        binding.buttonReset.isEnabled = !loading
        binding.buttonResend.isEnabled = !loading
    }

    companion object {
        private const val MIN_PASSWORD_LENGTH = 8
        private const val FINISH_DELAY_MS = 1200L
    }
}
