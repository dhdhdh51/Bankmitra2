package com.lrms.recovery.util

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.net.Uri
import android.util.Log
import androidx.exifinterface.media.ExifInterface
import java.io.File
import java.io.FileOutputStream
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Local file handling for visit photos and signatures.
 *
 * Everything lives in the app cache: these files exist only long enough to be
 * uploaded with the report. Keeping borrower photos in permanent storage on a
 * shared field device would be a needless data-protection risk.
 */
object FileStore {

    private const val TAG = "FileStore"

    private const val CAPTURES_DIR = "captures"
    private const val SIGNATURES_DIR = "signatures"

    /** Photos are downscaled before upload: a 12 MP original is pointless here. */
    private const val MAX_PHOTO_DIMENSION = 1600
    private const val PHOTO_QUALITY = 82

    // -----------------------------------------------------------------------
    // Signatures
    // -----------------------------------------------------------------------

    /** Writes a signature as a lossless PNG, as the spec requires. */
    fun writeSignaturePng(context: Context, type: String, bitmap: Bitmap): File? = try {
        val directory = File(context.cacheDir, SIGNATURES_DIR).apply { mkdirs() }
        val file = File(directory, "signature_${type}_${timestamp()}.png")

        FileOutputStream(file).use { stream ->
            bitmap.compress(Bitmap.CompressFormat.PNG, 100, stream)
        }

        file
    } catch (error: Exception) {
        Log.e(TAG, "Could not write the signature", error)
        null
    }

    // -----------------------------------------------------------------------
    // Photos
    // -----------------------------------------------------------------------

    /** Destination handed to the camera app via FileProvider. */
    fun createCaptureFile(context: Context, label: String): File {
        val directory = File(context.cacheDir, CAPTURES_DIR).apply { mkdirs() }
        return File(directory, "photo_${label}_${timestamp()}.jpg")
    }

    /**
     * Copies a gallery pick into the cache, downscaling and correcting rotation.
     * @return the cached file, or null when the image could not be read.
     */
    fun importFromUri(context: Context, uri: Uri, label: String): File? = try {
        val bitmap = context.contentResolver.openInputStream(uri)?.use { input ->
            BitmapFactory.decodeStream(input)
        } ?: return null

        val rotated = context.contentResolver.openInputStream(uri)?.use { input ->
            applyExifRotation(bitmap, ExifInterface(input))
        } ?: bitmap

        val scaled = downscale(rotated)
        val file = createCaptureFile(context, label)

        FileOutputStream(file).use { stream ->
            scaled.compress(Bitmap.CompressFormat.JPEG, PHOTO_QUALITY, stream)
        }

        if (scaled != rotated) scaled.recycle()
        if (rotated != bitmap) rotated.recycle()
        bitmap.recycle()

        file
    } catch (error: Exception) {
        Log.e(TAG, "Could not import the selected image", error)
        null
    }

    /**
     * Compresses a camera capture in place.
     *
     * Phone cameras write multi-megabyte JPEGs; uploading three of those over a
     * rural connection is what makes a report submission fail. This keeps the
     * payload small enough to send reliably.
     */
    fun compressInPlace(file: File): File {
        if (!file.exists() || file.length() == 0L) return file

        return try {
            val original = BitmapFactory.decodeFile(file.absolutePath) ?: return file
            val rotated = file.inputStream().use { applyExifRotation(original, ExifInterface(it)) }
            val scaled = downscale(rotated)

            FileOutputStream(file).use { stream ->
                scaled.compress(Bitmap.CompressFormat.JPEG, PHOTO_QUALITY, stream)
            }

            if (scaled != rotated) scaled.recycle()
            if (rotated != original) rotated.recycle()
            original.recycle()

            file
        } catch (error: Exception) {
            // A photo that cannot be compressed is still worth uploading.
            Log.w(TAG, "Could not compress the photo; uploading it as captured", error)
            file
        }
    }

    private fun downscale(source: Bitmap): Bitmap {
        val longest = maxOf(source.width, source.height)
        if (longest <= MAX_PHOTO_DIMENSION) return source

        val scale = MAX_PHOTO_DIMENSION.toFloat() / longest
        val width = (source.width * scale).toInt().coerceAtLeast(1)
        val height = (source.height * scale).toInt().coerceAtLeast(1)

        return Bitmap.createScaledBitmap(source, width, height, true)
    }

    /**
     * Applies the EXIF orientation.
     *
     * Many phones store the sensor image unrotated and record the orientation in
     * EXIF. Without this, house photos arrive sideways in the admin panel.
     */
    private fun applyExifRotation(source: Bitmap, exif: ExifInterface): Bitmap {
        val orientation = exif.getAttributeInt(
            ExifInterface.TAG_ORIENTATION,
            ExifInterface.ORIENTATION_NORMAL,
        )

        val matrix = Matrix()
        when (orientation) {
            ExifInterface.ORIENTATION_ROTATE_90 -> matrix.postRotate(90f)
            ExifInterface.ORIENTATION_ROTATE_180 -> matrix.postRotate(180f)
            ExifInterface.ORIENTATION_ROTATE_270 -> matrix.postRotate(270f)
            ExifInterface.ORIENTATION_FLIP_HORIZONTAL -> matrix.postScale(-1f, 1f)
            ExifInterface.ORIENTATION_FLIP_VERTICAL -> matrix.postScale(1f, -1f)
            else -> return source
        }

        return try {
            Bitmap.createBitmap(source, 0, 0, source.width, source.height, matrix, true)
        } catch (error: OutOfMemoryError) {
            Log.w(TAG, "Not enough memory to rotate the photo", error)
            source
        }
    }

    // -----------------------------------------------------------------------

    /** Removes cached captures and signatures after a successful submission. */
    fun clearWorkingFiles(context: Context) {
        listOf(CAPTURES_DIR, SIGNATURES_DIR).forEach { name ->
            runCatching {
                File(context.cacheDir, name).listFiles()?.forEach { it.delete() }
            }
        }
    }

    fun humanSize(bytes: Long): String = when {
        bytes >= 1_048_576 -> String.format(Locale.US, "%.1f MB", bytes / 1_048_576.0)
        bytes >= 1_024 -> String.format(Locale.US, "%d KB", bytes / 1_024)
        else -> "$bytes B"
    }

    private fun timestamp(): String =
        SimpleDateFormat("yyyyMMdd_HHmmss_SSS", Locale.US).format(Date())
}
