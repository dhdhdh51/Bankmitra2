package com.lrms.recovery.ui.signature

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.snackbar.Snackbar
import com.lrms.recovery.R
import com.lrms.recovery.databinding.ActivitySignatureBinding
import com.lrms.recovery.location.GeoStamp
import com.lrms.recovery.util.FileStore

/**
 * Full-screen, landscape-only signature capture.
 *
 * Landscape is deliberate: a signature box the width of a portrait phone is too
 * narrow for anyone to sign naturally, and the result looks nothing like the
 * signature on the bank's records. Rotation is locked in the manifest so an
 * accidental turn cannot discard strokes mid-signature.
 *
 * Returns the saved PNG path through [EXTRA_RESULT_PATH], and where it was signed
 * through [EXTRA_RESULT_GPS] / [EXTRA_RESULT_GPS_SOURCE]. The position is read at the
 * moment Save is pressed rather than taken from the visit's own fix: a borrower signs
 * in their courtyard and the agent may well submit the report from the road, and a
 * document that put the submission point under the signature would be asserting
 * something nobody checked.
 */
class SignatureActivity : AppCompatActivity() {

    private lateinit var binding: ActivitySignatureBinding

    private val signatureType: String by lazy {
        intent.getStringExtra(EXTRA_TYPE) ?: TYPE_CUSTOMER
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySignatureBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val heading = if (signatureType == TYPE_AGENT) {
            getString(R.string.visit_agent_signature)
        } else {
            getString(R.string.visit_customer_signature)
        }
        binding.textTitle.text = heading

        intent.getStringExtra(EXTRA_SIGNER_NAME)?.let { name ->
            if (name.isNotBlank()) {
                binding.textSigner.text = name
                binding.textSigner.visibility = View.VISIBLE
            }
        }

        binding.signaturePad.onStateChanged = { syncButtons() }
        syncButtons()

        binding.buttonUndo.setOnClickListener { binding.signaturePad.undo() }
        binding.buttonRedo.setOnClickListener { binding.signaturePad.redo() }

        binding.buttonClear.setOnClickListener {
            if (binding.signaturePad.isEmpty) return@setOnClickListener
            binding.signaturePad.clear()
            Snackbar.make(binding.root, "Signature cleared", Snackbar.LENGTH_SHORT).show()
        }

        binding.buttonCancel.setOnClickListener { confirmDiscard() }
        binding.buttonSave.setOnClickListener { save() }

        // Discarding a signature by accident means asking the borrower to sign
        // again, so a back press confirms first.
        onBackPressedDispatcher.addCallback(
            this,
            object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() = confirmDiscard()
            },
        )
    }

    private fun syncButtons() {
        binding.buttonUndo.isEnabled = binding.signaturePad.canUndo
        binding.buttonRedo.isEnabled = binding.signaturePad.canRedo
        binding.buttonClear.isEnabled = !binding.signaturePad.isEmpty
        binding.buttonSave.isEnabled = !binding.signaturePad.isEmpty

        binding.textHint.visibility =
            if (binding.signaturePad.isEmpty) View.VISIBLE else View.GONE
    }

    private fun save() {
        val bitmap = binding.signaturePad.exportBitmap()

        if (bitmap == null) {
            Snackbar.make(binding.root, R.string.signature_empty, Snackbar.LENGTH_SHORT).show()
            return
        }

        // Read the fix before writing the PNG, for the same reason the camera path
        // reads it before compressing: the position of interest is where the pad was
        // signed, not where the phone was a second and a half later.
        val located = GeoStamp.current(this)

        val file = FileStore.writeSignaturePng(this, signatureType, bitmap)
        bitmap.recycle()

        if (file == null) {
            Snackbar.make(binding.root, R.string.error_unknown, Snackbar.LENGTH_LONG).show()
            return
        }

        setResult(
            Activity.RESULT_OK,
            Intent().apply {
                putExtra(EXTRA_RESULT_PATH, file.absolutePath)
                putExtra(EXTRA_TYPE, signatureType)
                // Always sent, including "denied" and "unavailable", so the server can
                // tell a refusal from a courtyard with no signal - and both of those
                // from an older app that never collected a position at all.
                putExtra(EXTRA_RESULT_GPS_SOURCE, located.wire)
                located.fix?.let { fix ->
                    putExtra(
                        EXTRA_RESULT_GPS,
                        listOf(
                            fix.latitude.toString(),
                            fix.longitude.toString(),
                            fix.accuracyMetres?.toString() ?: "",
                            fix.capturedAt,
                        ).joinToString(","),
                    )
                }
            },
        )
        finish()
    }

    private fun confirmDiscard() {
        if (binding.signaturePad.isEmpty) {
            setResult(Activity.RESULT_CANCELED)
            finish()
            return
        }

        AlertDialog.Builder(this)
            .setTitle(R.string.signature_cancel)
            .setMessage("Discard this signature?")
            .setNegativeButton(R.string.action_cancel, null)
            .setPositiveButton(R.string.visit_discard_confirm) { _, _ ->
                setResult(Activity.RESULT_CANCELED)
                finish()
            }
            .show()
    }

    companion object {
        const val TYPE_CUSTOMER = "customer"
        const val TYPE_AGENT = "agent"

        const val EXTRA_TYPE = "signature_type"
        const val EXTRA_SIGNER_NAME = "signer_name"
        const val EXTRA_RESULT_PATH = "result_path"

        /** "lat,lng,accuracyOrBlank,capturedAt" - absent when there was no fix. */
        const val EXTRA_RESULT_GPS = "result_gps"

        /** device / denied / unavailable, always present. */
        const val EXTRA_RESULT_GPS_SOURCE = "result_gps_source"

        fun intent(context: Context, type: String, signerName: String? = null): Intent =
            Intent(context, SignatureActivity::class.java).apply {
                putExtra(EXTRA_TYPE, type)
                putExtra(EXTRA_SIGNER_NAME, signerName)
            }
    }
}
