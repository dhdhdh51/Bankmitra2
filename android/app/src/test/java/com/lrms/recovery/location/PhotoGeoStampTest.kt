package com.lrms.recovery.location

import java.io.File
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Guards the one property of photo geo-stamping that matters.
 *
 * A camera capture may carry coordinates. A gallery pick may not. If that ever
 * inverts - or if the gallery path simply forgets to clear a previous capture's
 * stamp - the app starts attaching a doorstep position to an image chosen from
 * somebody's photo roll, and the report asserts a place the agent may never have
 * stood. That is manufactured evidence in a recovery file, and it would be
 * invisible: the photo looks the same either way.
 */
class PhotoGeoStampTest {

    private val photoActivity = File(
        "src/main/java/com/lrms/recovery/ui/photo/PhotoUploadActivity.kt",
    ).readText()

    private val visitActivity = File(
        "src/main/java/com/lrms/recovery/ui/visit/VisitReportActivity.kt",
    ).readText()

    private val formData = File(
        "src/main/java/com/lrms/recovery/domain/VisitFormData.kt",
    ).readText()

    private val geoStamp = File(
        "src/main/java/com/lrms/recovery/location/GeoStamp.kt",
    ).readText()

    private fun code(source: String): String = source
        .replace(Regex("""/\*.*?\*/""", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("""//[^\n]*"""), "")

    @Test
    fun `a camera capture records a fix`() {
        val clean = code(photoActivity)
        assertTrue(
            "the camera result must read a location",
            Regex("""cameraLauncher(.|\n)*?GeoStamp\.current""").containsMatchIn(clean),
        )
        assertTrue(
            "the fix must be stored against the slot being filled",
            clean.contains("stamps[slot] = stamp"),
        )
    }

    @Test
    fun `a gallery pick clears the stamp instead of inheriting one`() {
        val clean = code(photoActivity)

        // The gallery branch must not read a location...
        val galleryBlock = Regex("""galleryLauncher = registerForActivityResult((.|\n)*?)\n    }""")
            .find(clean)?.groupValues?.get(1) ?: error("gallery launcher not found")

        assertFalse(
            "a gallery pick must not be given coordinates",
            galleryBlock.contains("GeoStamp.current"),
        )
        // ...and must actively remove any stamp left by a previous capture in that slot.
        assertTrue(
            "the gallery path must clear the slot's stamp",
            galleryBlock.contains("stamps.remove(pendingSlot)"),
        )
    }

    @Test
    fun `removing a photo removes its coordinates`() {
        assertTrue(
            "remove() must drop the stamp, or a deleted photo's position survives it",
            Regex("""fun remove\(slot: Slot\)(.|\n)*?stamps\.remove\(slot\)""")
                .containsMatchIn(code(photoActivity)),
        )
    }

    @Test
    fun `the visit form rebuilds photo stamps rather than merging them`() {
        // Merging would leave a stale entry when a camera photo is replaced by a
        // gallery pick, which is precisely the case the asymmetry exists to prevent.
        assertTrue(
            "photo stamps must be cleared before being repopulated",
            Regex("""form\.photoStamps\.clear\(\)""").containsMatchIn(code(visitActivity)),
        )
    }

    @Test
    fun `the report position is read at submit time`() {
        // Reading it when the form opened would file the previous doorstep for an
        // agent who walked to the next house with the form still on screen.
        assertTrue(
            "the fix must be taken in the submit path",
            Regex("""GeoStamp\.current\((.|\n)*?form\.gpsSource""").containsMatchIn(code(visitActivity)),
        )
    }

    @Test
    fun `refused and unavailable are reported as different things`() {
        val clean = code(geoStamp)

        for (source in listOf("DENIED", "UNAVAILABLE", "DEVICE")) {
            assertTrue("GeoStamp must distinguish $source", clean.contains(source))
        }

        // No permission is a decision somebody made; no provider is a building with
        // a concrete roof. Collapsing them turns a consent question into a signal
        // question, and a supervisor cannot tell which conversation to have.
        assertTrue(
            "missing permission must report DENIED",
            Regex("""hasPermission\(context\)(.|\n)*?Source\.DENIED""").containsMatchIn(clean),
        )
        assertTrue(
            "a missing provider must report UNAVAILABLE",
            Regex("""LOCATION_SERVICE(.|\n)*?Source\.UNAVAILABLE""").containsMatchIn(clean),
        )
    }

    @Test
    fun `a zero zero fix is refused`() {
        // (0,0) is what some devices report with no fix, and it is a real place in
        // the Gulf of Guinea. Accepting it would put every agent without a signal in
        // the same fictional location - and it would look like real data.
        assertTrue(
            "GeoStamp must reject a null-island fix",
            Regex("""abs\(best\.latitude\)(.|\n)*?Source\.UNAVAILABLE""").containsMatchIn(code(geoStamp)),
        )
    }

    @Test
    fun `a stale fix is not attached to a fresh report`() {
        assertTrue("a maximum fix age must be enforced", code(geoStamp).contains("MAX_AGE_MS"))
        assertTrue(
            "the age must actually be compared",
            Regex("""timestampOf\(candidate\) > MAX_AGE_MS""").containsMatchIn(code(geoStamp)),
        )
    }

    @Test
    fun `no new location request is made while somebody waits`() {
        // requestLocationUpdates on a cold GPS can block for a minute with the agent
        // standing at a doorstep. The last known fix, bounded by age, is the trade.
        assertFalse(
            "GeoStamp must not request fresh updates",
            code(geoStamp).contains("requestLocationUpdates"),
        )
        assertTrue("it must read the last known fix", code(geoStamp).contains("getLastKnownLocation"))
    }

    @Test
    fun `the source is always sent so an old app is distinguishable`() {
        assertTrue(
            "gps_source must be sent unconditionally",
            Regex("""fields\["gps_source"\] = gpsSource""").containsMatchIn(code(formData)),
        )
        // Coordinates only when there are coordinates.
        assertTrue(
            "coordinates must be gated on a device fix",
            Regex("""gpsSource == "device"(.|\n)*?gps_latitude""").containsMatchIn(code(formData)),
        )
    }

    @Test
    fun `photo stamps are sent per slot and marked as camera`() {
        val clean = code(formData)
        assertTrue(
            "each stamped slot must be marked as a camera capture",
            clean.contains("_photo_gps_source\"] = \"camera\""),
        )
        assertTrue(
            "a malformed stamp must be skipped rather than sent half-complete",
            Regex("""parts\.size < 2(.|\n)*?return@forEach""").containsMatchIn(clean),
        )
    }
}
