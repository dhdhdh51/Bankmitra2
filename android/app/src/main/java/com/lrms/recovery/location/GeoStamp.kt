package com.lrms.recovery.location

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import androidx.core.content.ContextCompat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * A single location fix, taken at the moment something is recorded.
 *
 * Separate from [DutyLocationService] on purpose. That service records a trail
 * while an agent is on duty; this reads one point when a visit is filed or a photo
 * is taken, and works whether or not a duty session is running. Bolting this onto
 * the service would mean a photo could only be stamped while tracking was active,
 * which is a surprising rule to explain to somebody holding a phone.
 *
 * Everything here degrades to null rather than failing. A visit report with no
 * coordinates is worth filing; a visit report that could not be filed because the
 * sky was cloudy is not.
 */
object GeoStamp {

    /** How stale a cached fix may be before it is not worth attaching. */
    private const val MAX_AGE_MS = 3L * 60L * 1000L

    /** Fixes worse than this are attached but flagged by their accuracy value. */
    private const val IMPLAUSIBLE_ACCURACY_M = 5_000f

    private val TIMESTAMP_FORMAT = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)

    /**
     * What the app knows about where it is.
     *
     * [source] mirrors the server's `gps_source` column, and the distinction between
     * DENIED and UNAVAILABLE is deliberate: one is a decision somebody made, the
     * other is a phone in a concrete building. Collapsing them would turn a
     * conversation about consent into a conversation about signal.
     */
    data class Fix(
        val latitude: Double,
        val longitude: Double,
        val accuracyMetres: Int?,
        val capturedAt: String,
    )

    enum class Source { DEVICE, DENIED, UNAVAILABLE }

    data class Result(val fix: Fix?, val source: Source) {
        val wire: String
            get() = when (source) {
                Source.DEVICE -> "device"
                Source.DENIED -> "denied"
                Source.UNAVAILABLE -> "unavailable"
            }
    }

    /**
     * Reads the best recent fix the system already has.
     *
     * Deliberately does not request a new one. Asking for a fresh fix means waiting
     * - up to a minute with a cold GPS - while somebody stands at a doorstep with
     * the shutter button pressed. The trade is a position that may be a couple of
     * minutes old, which for "which village was this" is indistinguishable, and the
     * age is bounded by MAX_AGE_MS so a fix from this morning is never attached to
     * an afternoon visit.
     */
    fun current(context: Context): Result {
        if (!hasPermission(context)) {
            return Result(null, Source.DENIED)
        }

        val manager = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager
            ?: return Result(null, Source.UNAVAILABLE)

        val best = bestKnown(manager) ?: return Result(null, Source.UNAVAILABLE)

        // (0,0) is what some devices report with no fix at all. It is also a real
        // place in the Gulf of Guinea, so recording it would put every agent
        // without a signal in the same fictional location.
        if (kotlin.math.abs(best.latitude) < 0.0001 && kotlin.math.abs(best.longitude) < 0.0001) {
            return Result(null, Source.UNAVAILABLE)
        }

        val accuracy = if (best.hasAccuracy() && best.accuracy > 0f && best.accuracy < IMPLAUSIBLE_ACCURACY_M) {
            best.accuracy.toInt()
        } else {
            null
        }

        return Result(
            Fix(
                latitude = best.latitude,
                longitude = best.longitude,
                accuracyMetres = accuracy,
                capturedAt = TIMESTAMP_FORMAT.format(Date(timestampOf(best))),
            ),
            Source.DEVICE,
        )
    }

    fun hasPermission(context: Context): Boolean {
        val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION)
        val coarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION)

        return fine == PackageManager.PERMISSION_GRANTED || coarse == PackageManager.PERMISSION_GRANTED
    }

    /**
     * The most accurate recent fix across the enabled providers.
     *
     * Every provider is read rather than picking one, because on the phones these
     * agents carry GPS is frequently disabled while the network provider is not,
     * and a network fix naming the right village beats no fix at all.
     */
    private fun bestKnown(manager: LocationManager): Location? {
        val now = System.currentTimeMillis()
        var best: Location? = null

        for (provider in providers(manager)) {
            val candidate = try {
                @Suppress("MissingPermission")
                manager.getLastKnownLocation(provider)
            } catch (_: SecurityException) {
                null
            } catch (_: IllegalArgumentException) {
                null
            } ?: continue

            if (now - timestampOf(candidate) > MAX_AGE_MS) {
                continue
            }

            if (best == null || isBetter(candidate, best)) {
                best = candidate
            }
        }

        return best
    }

    private fun providers(manager: LocationManager): List<String> = try {
        manager.getProviders(true)
    } catch (_: SecurityException) {
        emptyList()
    }

    /** More accurate wins; with no accuracy to compare, the newer fix wins. */
    private fun isBetter(candidate: Location, incumbent: Location): Boolean {
        if (candidate.hasAccuracy() && incumbent.hasAccuracy()) {
            return candidate.accuracy < incumbent.accuracy
        }

        if (candidate.hasAccuracy() != incumbent.hasAccuracy()) {
            return candidate.hasAccuracy()
        }

        return timestampOf(candidate) > timestampOf(incumbent)
    }

    private fun timestampOf(location: Location): Long =
        if (location.time > 0L) location.time else System.currentTimeMillis()
}
