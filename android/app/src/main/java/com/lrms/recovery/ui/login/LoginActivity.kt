package com.lrms.recovery.ui.login

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.remote.ApiClient
import com.lrms.recovery.databinding.ActivityLoginBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.ui.main.MainActivity
import kotlinx.coroutines.launch

/**
 * Sign-in with employee code and password.
 *
 * The server address is editable here. One APK is expected to serve several
 * deployments, and a field agent cannot be asked to install a different build per
 * bank, so the base URL is part of the login flow rather than baked in.
 */
class LoginActivity : BaseActivity() {

    private lateinit var binding: ActivityLoginBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        intent.getStringExtra(EXTRA_MESSAGE)?.let { showMessage(it, binding.root) }

        // Restore the remembered employee code so a returning agent types less.
        session.lastEmployeeCode?.let { binding.inputEmployeeCode.setText(it) }
        binding.checkRemember.isChecked = session.rememberMe
        binding.inputServer.setText(session.baseUrl)

        binding.buttonToggleServer.setOnClickListener {
            val visible = binding.groupServer.visibility == View.VISIBLE
            binding.groupServer.visibility = if (visible) View.GONE else View.VISIBLE
        }

        // Show the server field automatically when it has never been set, so the
        // very first launch does not silently point at the placeholder domain.
        if (!session.hasCustomBaseUrl) {
            binding.groupServer.visibility = View.VISIBLE
        }

        binding.buttonLogin.setOnClickListener { attemptLogin() }
        binding.buttonForgot.setOnClickListener {
            startActivity(Intent(this, ForgotPasswordActivity::class.java))
        }

        binding.inputPassword.setOnEditorActionListener { _, _, _ ->
            attemptLogin()
            true
        }
    }

    private fun attemptLogin() {
        val employeeCode = binding.inputEmployeeCode.text?.toString()?.trim().orEmpty()
        val password = binding.inputPassword.text?.toString().orEmpty()
        val serverUrl = binding.inputServer.text?.toString()?.trim().orEmpty()

        binding.fieldEmployeeCode.error = null
        binding.fieldPassword.error = null
        binding.fieldServer.error = null

        var valid = true

        if (employeeCode.isEmpty()) {
            binding.fieldEmployeeCode.error = getString(R.string.login_error_code)
            valid = false
        }
        if (password.isEmpty()) {
            binding.fieldPassword.error = getString(R.string.login_error_password)
            valid = false
        }
        if (!isValidBaseUrl(serverUrl)) {
            binding.fieldServer.error = getString(R.string.login_error_server)
            binding.groupServer.visibility = View.VISIBLE
            valid = false
        }

        if (!valid) return

        // Persist the address before logging in: the request must go to the
        // server the user just typed.
        if (serverUrl != session.baseUrl) {
            session.baseUrl = serverUrl
            ApiClient.reset()
        }

        setLoading(true)

        lifecycleScope.launch {
            val result = repository.login(employeeCode, password, binding.checkRemember.isChecked)
            setLoading(false)

            when (result) {
                is ApiResult.Success -> {
                    val user = result.data.user
                    if (user == null) {
                        showMessage(R.string.error_unknown, binding.root)
                        return@launch
                    }

                    if (user.mustChangePassword) {
                        startActivity(
                            Intent(this@LoginActivity, ChangePasswordActivity::class.java)
                                .putExtra(ChangePasswordActivity.EXTRA_FORCED, true),
                        )
                        finish()
                        return@launch
                    }

                    startActivity(
                        Intent(this@LoginActivity, MainActivity::class.java).apply {
                            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
                        },
                    )
                    finish()
                }

                is ApiResult.Failure -> {
                    // Field errors go next to the field; anything else is a snackbar.
                    result.fieldError("employee_code")?.let { binding.fieldEmployeeCode.error = it }
                    result.fieldError("password")?.let { binding.fieldPassword.error = it }

                    if (result.fieldErrors.isEmpty()) {
                        showMessage(result.message, binding.root)
                    }
                }

                is ApiResult.NetworkError -> showMessage(result.message, binding.root)

                ApiResult.Unauthorised -> showMessage(R.string.error_session_expired, binding.root)
            }
        }
    }

    /** Only absolute http(s) URLs are accepted, and https is expected in production. */
    private fun isValidBaseUrl(url: String): Boolean {
        if (url.isEmpty()) return false
        return (url.startsWith("https://") || url.startsWith("http://")) && url.length > 10
    }

    private fun setLoading(loading: Boolean) {
        binding.progress.visibility = if (loading) View.VISIBLE else View.GONE
        binding.buttonLogin.isEnabled = !loading
        binding.buttonLogin.text = getString(
            if (loading) R.string.loading else R.string.login_button,
        )
        binding.inputEmployeeCode.isEnabled = !loading
        binding.inputPassword.isEnabled = !loading
    }

    companion object {
        const val EXTRA_MESSAGE = "message"
    }
}
