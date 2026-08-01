package com.lrms.recovery.ui.photo

import android.Manifest
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.ImageView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.core.content.FileProvider
import com.bumptech.glide.Glide
import com.lrms.recovery.R
import com.lrms.recovery.databinding.ActivityPhotoUploadBinding
import com.lrms.recovery.location.GeoStamp
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.util.FileStore
import java.io.File

/**
 * Photo and document capture for a visit report.
 *
 * Three typed slots (customer, house, Aadhaar copy) plus a list of other
 * documents. Each slot takes a camera capture or a gallery pick.
 *
 * Captures are downscaled and EXIF-rotated before returning: a raw 12 MP JPEG is
 * what makes a report submission time out on a rural connection, and an
 * unrotated one arrives sideways in the admin panel.
 *
 * A CAMERA CAPTURE IS STAMPED WITH A LOCATION; A GALLERY PICK IS NOT. That asymmetry
 * is the whole point of keeping the two paths separate. A photo taken at the doorstep
 * can honestly carry coordinates. An image chosen from the gallery was taken at an
 * unknown time in an unknown place, and giving it today's position would manufacture
 * evidence that an agent stood somewhere they may never have been. So the gallery
 * path clears the stamp rather than leaving whatever the previous capture set.
 */
class PhotoUploadActivity : BaseActivity() {

    private lateinit var binding: ActivityPhotoUploadBinding

    private var customerPhoto: File? = null
    private var housePhoto: File? = null
    private var aadhaarPhoto: File? = null
    private val otherDocuments = mutableListOf<File>()

    /** Which slot the in-flight camera or gallery request belongs to. */
    private var pendingSlot: Slot = Slot.CUSTOMER
    private var pendingCaptureFile: File? = null

    /**
     * Coordinates for the slots filled by the camera. A slot absent from this map
     * either has no photo or has one that came from the gallery - in both cases the
     * report must make no claim about where it was taken.
     */
    private val stamps = mutableMapOf<Slot, GeoStamp.Fix>()

    private enum class Slot { CUSTOMER, HOUSE, AADHAAR, OTHER }

    // ---- Launchers ---------------------------------------------------------

    private val cameraLauncher = registerForActivityResult(
        ActivityResultContracts.TakePicture(),
    ) { success ->
        val file = pendingCaptureFile
        pendingCaptureFile = null

        if (!success || file == null || !file.exists() || file.length() == 0L) {
            file?.delete()
            return@registerForActivityResult
        }

        // Read the fix before compressing: compression takes a moment, and the
        // point of interest is where the shutter was pressed.
        val stamp = GeoStamp.current(this).fix
        val slot = pendingSlot

        if (stamp == null) {
            stamps.remove(slot)
        } else {
            stamps[slot] = stamp
        }

        assign(slot, FileStore.compressInPlace(file))
    }

    private val galleryLauncher = registerForActivityResult(
        ActivityResultContracts.GetContent(),
    ) { uri: Uri? ->
        if (uri == null) return@registerForActivityResult

        val imported = FileStore.importFromUri(this, uri, pendingSlot.name.lowercase())
        if (imported == null) {
            showMessage(R.string.error_unknown, binding.root)
            return@registerForActivityResult
        }

        // Cleared, not left alone. Replacing a camera photo with a gallery one must
        // drop the coordinates the camera photo had, or the report would attribute a
        // position to an image that never had one.
        stamps.remove(pendingSlot)

        assign(pendingSlot, imported)
    }

    private val cameraPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (granted) {
            launchCamera()
        } else {
            showMessage(R.string.photo_camera_denied, binding.root)
        }
    }

    private val galleryPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (granted) {
            galleryLauncher.launch("image/*")
        } else {
            showMessage(R.string.photo_gallery_denied, binding.root)
        }
    }

    // -----------------------------------------------------------------------

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPhotoUploadBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { finishWithResult() }

        // Restore anything already attached to the report.
        intent.getStringExtra(EXTRA_CUSTOMER_PHOTO)?.let { customerPhoto = File(it) }
        intent.getStringExtra(EXTRA_HOUSE_PHOTO)?.let { housePhoto = File(it) }
        intent.getStringExtra(EXTRA_AADHAAR_PHOTO)?.let { aadhaarPhoto = File(it) }
        intent.getStringArrayListExtra(EXTRA_OTHER_DOCS)?.forEach { otherDocuments.add(File(it)) }

        binding.buttonCustomerPhoto.setOnClickListener { chooseSource(Slot.CUSTOMER) }
        binding.buttonHousePhoto.setOnClickListener { chooseSource(Slot.HOUSE) }
        binding.buttonAadhaarPhoto.setOnClickListener { chooseSource(Slot.AADHAAR) }
        binding.buttonOtherDocs.setOnClickListener { chooseSource(Slot.OTHER) }

        binding.buttonRemoveCustomer.setOnClickListener { remove(Slot.CUSTOMER) }
        binding.buttonRemoveHouse.setOnClickListener { remove(Slot.HOUSE) }
        binding.buttonRemoveAadhaar.setOnClickListener { remove(Slot.AADHAAR) }
        binding.buttonClearOthers.setOnClickListener { remove(Slot.OTHER) }

        binding.buttonDone.setOnClickListener { finishWithResult() }

        render()
    }

    private fun chooseSource(slot: Slot) {
        pendingSlot = slot

        AlertDialog.Builder(this)
            .setTitle(labelFor(slot))
            .setItems(
                arrayOf(getString(R.string.photo_take), getString(R.string.photo_choose)),
            ) { _, which ->
                if (which == 0) requestCamera() else requestGallery()
            }
            .setNegativeButton(R.string.action_cancel, null)
            .show()
    }

    private fun requestCamera() {
        val granted = androidx.core.content.ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.CAMERA,
        ) == android.content.pm.PackageManager.PERMISSION_GRANTED

        if (granted) launchCamera() else cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
    }

    private fun launchCamera() {
        val file = FileStore.createCaptureFile(this, pendingSlot.name.lowercase())
        pendingCaptureFile = file

        val uri = FileProvider.getUriForFile(
            this,
            "${packageName}.fileprovider",
            file,
        )

        cameraLauncher.launch(uri)
    }

    private fun requestGallery() {
        // Scoped storage on 33+ uses a different permission, and on 29+ reading a
        // picked URI needs no permission at all.
        val permission = when {
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU ->
                Manifest.permission.READ_MEDIA_IMAGES
            Build.VERSION.SDK_INT <= Build.VERSION_CODES.P ->
                Manifest.permission.READ_EXTERNAL_STORAGE
            else -> null
        }

        if (permission == null) {
            galleryLauncher.launch("image/*")
            return
        }

        val granted = androidx.core.content.ContextCompat.checkSelfPermission(this, permission) ==
            android.content.pm.PackageManager.PERMISSION_GRANTED

        if (granted) galleryLauncher.launch("image/*") else galleryPermissionLauncher.launch(permission)
    }

    private fun assign(slot: Slot, file: File) {
        when (slot) {
            Slot.CUSTOMER -> customerPhoto = file
            Slot.HOUSE -> housePhoto = file
            Slot.AADHAAR -> aadhaarPhoto = file
            Slot.OTHER -> otherDocuments.add(file)
        }
        render()
    }

    private fun remove(slot: Slot) {
        stamps.remove(slot)

        when (slot) {
            Slot.CUSTOMER -> { customerPhoto?.delete(); customerPhoto = null }
            Slot.HOUSE -> { housePhoto?.delete(); housePhoto = null }
            Slot.AADHAAR -> { aadhaarPhoto?.delete(); aadhaarPhoto = null }
            Slot.OTHER -> {
                otherDocuments.forEach { it.delete() }
                otherDocuments.clear()
            }
        }
        render()
    }

    private fun render() {
        bindSlot(customerPhoto, binding.imageCustomer, binding.buttonRemoveCustomer, binding.textCustomerSize)
        bindSlot(housePhoto, binding.imageHouse, binding.buttonRemoveHouse, binding.textHouseSize)
        bindSlot(aadhaarPhoto, binding.imageAadhaar, binding.buttonRemoveAadhaar, binding.textAadhaarSize)

        binding.textOtherCount.text = if (otherDocuments.isEmpty()) {
            getString(R.string.visit_signature_not_captured)
        } else {
            resources.getQuantityString(
                R.plurals.photo_count, otherDocuments.size, otherDocuments.size,
            )
        }
        binding.buttonClearOthers.visibility =
            if (otherDocuments.isEmpty()) View.GONE else View.VISIBLE
    }

    private fun bindSlot(file: File?, image: ImageView, removeButton: View, sizeLabel: android.widget.TextView) {
        if (file == null || !file.exists()) {
            image.setImageResource(R.drawable.ic_image)
            removeButton.visibility = View.GONE
            sizeLabel.visibility = View.GONE
            return
        }

        Glide.with(image).load(file).centerCrop().into(image)
        removeButton.visibility = View.VISIBLE
        sizeLabel.visibility = View.VISIBLE
        sizeLabel.text = FileStore.humanSize(file.length())
    }

    private fun gpsResultKey(slot: Slot): String = when (slot) {
        Slot.CUSTOMER -> RESULT_CUSTOMER_GPS
        Slot.HOUSE -> RESULT_HOUSE_GPS
        Slot.AADHAAR -> RESULT_AADHAAR_GPS
        // Other documents are a list with no per-item slot, so they are not stamped.
        // Inventing a single position for a bundle of unrelated scans would be worse
        // than leaving them unstamped.
        Slot.OTHER -> "result_other_gps"
    }

    private fun labelFor(slot: Slot): String = getString(
        when (slot) {
            Slot.CUSTOMER -> R.string.visit_customer_photo
            Slot.HOUSE -> R.string.visit_house_photo
            Slot.AADHAAR -> R.string.visit_aadhaar_photo
            Slot.OTHER -> R.string.visit_other_documents
        },
    )

    private fun finishWithResult() {
        setResult(
            RESULT_OK,
            Intent().apply {
                customerPhoto?.let { putExtra(RESULT_CUSTOMER_PHOTO, it.absolutePath) }
                housePhoto?.let { putExtra(RESULT_HOUSE_PHOTO, it.absolutePath) }
                aadhaarPhoto?.let { putExtra(RESULT_AADHAAR_PHOTO, it.absolutePath) }
                putStringArrayListExtra(
                    RESULT_OTHER_DOCS,
                    ArrayList(otherDocuments.map { it.absolutePath }),
                )
                // "lat,lng,accuracyOrBlank" per stamped slot. Only slots the camera
                // filled appear here.
                stamps.forEach { (slot, fix) ->
                    putExtra(
                        gpsResultKey(slot),
                        listOf(
                            fix.latitude.toString(),
                            fix.longitude.toString(),
                            fix.accuracyMetres?.toString() ?: "",
                        ).joinToString(","),
                    )
                }
            },
        )
        finish()
    }

    companion object {
        private const val EXTRA_CUSTOMER_PHOTO = "extra_customer_photo"
        private const val EXTRA_HOUSE_PHOTO = "extra_house_photo"
        private const val EXTRA_AADHAAR_PHOTO = "extra_aadhaar_photo"
        private const val EXTRA_OTHER_DOCS = "extra_other_docs"

        const val RESULT_CUSTOMER_PHOTO = "result_customer_photo"
        const val RESULT_HOUSE_PHOTO = "result_house_photo"
        const val RESULT_AADHAAR_PHOTO = "result_aadhaar_photo"
        const val RESULT_OTHER_DOCS = "result_other_docs"

        const val RESULT_CUSTOMER_GPS = "result_customer_gps"
        const val RESULT_HOUSE_GPS = "result_house_gps"
        const val RESULT_AADHAAR_GPS = "result_aadhaar_gps"

        fun intent(
            context: Context,
            customerPhoto: String? = null,
            housePhoto: String? = null,
            aadhaarPhoto: String? = null,
            otherDocs: List<String> = emptyList(),
        ): Intent = Intent(context, PhotoUploadActivity::class.java).apply {
            putExtra(EXTRA_CUSTOMER_PHOTO, customerPhoto)
            putExtra(EXTRA_HOUSE_PHOTO, housePhoto)
            putExtra(EXTRA_AADHAAR_PHOTO, aadhaarPhoto)
            putStringArrayListExtra(EXTRA_OTHER_DOCS, ArrayList(otherDocs))
        }
    }
}
