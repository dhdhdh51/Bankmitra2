package com.lrms.recovery.ui.location

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.remote.LocationNoticePayload
import com.lrms.recovery.databinding.ActivityLocationConsentBinding
import com.lrms.recovery.location.DutyLocationService
import com.lrms.recovery.ui.BaseActivity
import kotlinx.coroutines.launch

/**
 * The location notice, the agent's acknowledgement, and the duty toggle.
 *
 * One screen on purpose. The notice, the consent and the off switch belong together:
 * an agent who wants to know what is recorded and an agent who wants to stop it are
 * the same person having the same thought, and separating those into different
 * corners of the app is how "you can withdraw at any time" becomes technically true
 * and practically false.
 *
 * The notice text comes from the server rather than being hardcoded here, so a
 * change to what is collected reaches the screen without shipping an APK - and the
 * version is checked, so old text can never be acknowledged for new collection.
 */
class LocationConsentActivity : BaseActivity() {

    private lateinit var binding: ActivityLocationConsentBinding
    private var notice: LocationNoticePayload? = null

    /**
     * Permission is requested only AFTER the notice has been acknowledged. Asking
     * the OS first would put the system dialog in front of somebody who has not yet
     * been told what the app intends to do with the answer.
     */
    private val requestPermission = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { granted ->
        if (granted.values.any { it }) {
            startDuty()
        } else {
            showMessage(R.string.location_permission_needed, binding.root)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLocationConsentBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { finish() }

        binding.buttonAccept.setOnClickListener { accept() }
        binding.buttonWithdraw.setOnClickListener { confirmWithdraw() }
        binding.buttonStartDuty.setOnClickListener { requestPermissionThenStart() }
        binding.buttonStopDuty.setOnClickListener {
            DutyLocationService.stop(this)
            render(notice, onDuty = false)
            showMessage(R.string.location_off_duty, binding.root)
        }

        load()
    }

    override fun onResume() {
        super.onResume()
        // The Stop action in the notification can end a session while this screen is
        // in the background. Re-read the state on the way back rather than trusting
        // whatever was drawn before, or the screen offers to stop something that
        // already stopped.
        notice?.let { render(it, onDuty = DutyLocationService.isRunning) }
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            when (val result = repository.locationNotice()) {
                is ApiResult.Success -> {
                    notice = result.data
                    // Not `false`. Reopening this screen mid-session must not show
                    // "Off duty" and a Start button while the notification says
                    // recording is on.
                    render(result.data, onDuty = DutyLocationService.isRunning)
                }
                else -> handleFailure(result, binding.root)
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun render(payload: LocationNoticePayload?, onDuty: Boolean) {
        if (payload == null) {
            return
        }

        // Hindi first: it is the language most of these agents read most easily,
        // and a notice nobody reads is not a notice.
        binding.textNoticeHindi.text = payload.hindi
        binding.textNoticeEnglish.text = payload.english
        binding.textRetention.text = getString(R.string.location_retention_note, payload.retentionDays)

        val acknowledged = payload.acknowledged
        binding.buttonAccept.visibility = if (acknowledged) View.GONE else View.VISIBLE
        binding.buttonWithdraw.visibility = if (acknowledged) View.VISIBLE else View.GONE
        binding.groupDuty.visibility = if (acknowledged) View.VISIBLE else View.GONE
        binding.buttonStartDuty.visibility = if (onDuty) View.GONE else View.VISIBLE
        binding.buttonStopDuty.visibility = if (onDuty) View.VISIBLE else View.GONE
        binding.textStatus.setText(
            when {
                !acknowledged -> R.string.location_notice_required
                onDuty -> R.string.location_on_duty
                else -> R.string.location_off_duty
            },
        )
    }

    private fun accept() {
        val version = notice?.version ?: return
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            val device = "${Build.MANUFACTURER} ${Build.MODEL}, Android ${Build.VERSION.RELEASE}"
            when (val result = repository.acceptLocationNotice(version, device)) {
                is ApiResult.Success -> {
                    notice = notice?.copy(acknowledged = true, trackingAllowed = true)
                    render(notice, onDuty = false)
                }
                // A 409 means the notice changed while this screen was open. Reload
                // rather than retry: the agent must see the current text.
                is ApiResult.Failure -> if (result.httpCode == 409) {
                    load()
                    showMessage(getString(R.string.location_notice_updated), binding.root)
                } else {
                    handleFailure(result, binding.root)
                }
                else -> handleFailure(result, binding.root)
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun confirmWithdraw() {
        MaterialAlertDialogBuilder(this)
            .setTitle(R.string.location_withdraw)
            .setMessage(R.string.location_withdraw_confirm)
            .setNegativeButton(android.R.string.cancel, null)
            .setPositiveButton(R.string.location_withdraw) { _, _ -> withdraw() }
            .show()
    }

    private fun withdraw() {
        binding.progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            when (val result = repository.withdrawLocationConsent()) {
                is ApiResult.Success -> {
                    // Stop first, then update the screen: recording must not outlive
                    // the decision by even a moment.
                    DutyLocationService.stop(this@LocationConsentActivity)
                    notice = notice?.copy(acknowledged = false, trackingAllowed = false)
                    render(notice, onDuty = false)
                    showMessage(R.string.location_withdrawn, binding.root)
                }
                else -> handleFailure(result, binding.root)
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun requestPermissionThenStart() {
        if (notice?.acknowledged != true) {
            showMessage(R.string.location_notice_required, binding.root)
            return
        }

        val fine = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
        val coarse = ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION)

        if (fine == PackageManager.PERMISSION_GRANTED || coarse == PackageManager.PERMISSION_GRANTED) {
            startDuty()
            return
        }

        requestPermission.launch(
            arrayOf(
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION,
            ),
        )
    }

    private fun startDuty() {
        DutyLocationService.start(this)
        render(notice, onDuty = true)
        showMessage(R.string.location_on_duty, binding.root)
    }

    companion object {
        fun intent(context: Context): Intent = Intent(context, LocationConsentActivity::class.java)
    }
}
