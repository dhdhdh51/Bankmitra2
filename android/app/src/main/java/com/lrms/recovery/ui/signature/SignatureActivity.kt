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
import com.lrms.recovery.util.FileStore

/**
 * Full-screen, landscape-only signature capture.
 *
 * Landscape is deliberate: a signature box the width of a portrait phone is too
 * narrow for anyone to sign naturally, and the result looks nothing like the
 * signature on the bank's records. Rotation is locked in the manifest so an
 * accidental turn cannot discard strokes mid-signature.
 *
 * Returns the saved PNG path through [EXTRA_RESULT_PATH].
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

        fun intent(context: Context, type: String, signerName: String? = null): Intent =
            Intent(context, SignatureActivity::class.java).apply {
                putExtra(EXTRA_TYPE, type)
                putExtra(EXTRA_SIGNER_NAME, signerName)
            }
    }
}
