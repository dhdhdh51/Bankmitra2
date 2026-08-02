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

    private val signaturePad = File(
        "src/main/java/com/lrms/recovery/ui/signature/SignatureActivity.kt",
    ).readText()

    private fun code(source: String): String = source
        .replace(Regex("""/\*.*?\*/""", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("""//[^\n]*"""), "")

    @Test
    fun `a camera capture records a fix`() {
        val clean = code(photoActivity)
        assertTrue(
            "the camera result must read a location",
            Regex("""cameraLauncher[\s\S]*?GeoStamp\.current""").containsMatchIn(clean),
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
        val galleryBlock = Regex("""galleryLauncher = registerForActivityResult([\s\S]*?)\n    }""")
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
            Regex("""fun remove\(slot: Slot\)[\s\S]*?stamps\.remove\(slot\)""")
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
            Regex("""GeoStamp\.current\([\s\S]*?form\.gpsSource""").containsMatchIn(code(visitActivity)),
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
            Regex("""hasPermission\(context\)[\s\S]*?Source\.DENIED""").containsMatchIn(clean),
        )
        assertTrue(
            "a missing provider must report UNAVAILABLE",
            Regex("""LOCATION_SERVICE[\s\S]*?Source\.UNAVAILABLE""").containsMatchIn(clean),
        )
    }

    @Test
    fun `a zero zero fix is refused`() {
        // (0,0) is what some devices report with no fix, and it is a real place in
        // the Gulf of Guinea. Accepting it would put every agent without a signal in
        // the same fictional location - and it would look like real data.
        assertTrue(
            "GeoStamp must reject a null-island fix",
            Regex("""abs\(best\.latitude\)[\s\S]*?Source\.UNAVAILABLE""").containsMatchIn(code(geoStamp)),
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
            Regex("""gpsSource == "device"[\s\S]*?gps_latitude""").containsMatchIn(code(formData)),
        )
    }

    @Test
    fun `the source of every photograph is reported, with or without coordinates`() {
        val clean = code(photoActivity)

        // Reported separately from the coordinates and never inferred from them. A
        // camera photograph taken indoors has no fix, and calling that a gallery pick
        // is an accusation on a recovery file.
        assertTrue(
            "a camera capture must be recorded as such",
            clean.contains("sources[slot] = SOURCE_CAMERA"),
        )
        assertTrue(
            "a gallery pick must be recorded as such",
            clean.contains("sources[pendingSlot] = SOURCE_GALLERY"),
        )
        assertTrue(
            "removing a photo must drop its source too",
            Regex("""fun remove\(slot: Slot\)[\s\S]{0,200}sources\.remove\(slot\)""")
                .containsMatchIn(clean),
        )

        val formData = code(File("src/main/java/com/lrms/recovery/domain/VisitFormData.kt").readText())
        assertTrue(
            "the source must be sent for every attached photograph",
            formData.contains("_photo_source"),
        )

        // Rebuilt, not merged: a slot refilled from the gallery arrives with no camera
        // entry, and a stale one would keep calling it a doorstep photograph.
        assertTrue(
            "photo sources must be cleared before being repopulated",
            code(visitActivity).contains("form.photoSources.clear()"),
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
            Regex("""parts\.size < 2[\s\S]*?return null""").containsMatchIn(clean),
        )
        assertTrue(
            "and the caller must drop the slot when the stamp cannot be read",
            clean.contains("unpackFix(packed) ?: return@forEach"),
        )
    }

    @Test
    fun `a photograph sends when it was taken, not just where`() {
        val clean = code(formData)

        // photos.captured_at held NULL for every photograph ever filed because nothing
        // sent it, so a printed report said where a photograph was taken and never
        // when - and two photographs of the same door an hour apart were identical.
        assertTrue(
            "the capture time must be sent alongside the coordinates",
            clean.contains("_photo_captured_at\"] = it"),
        )
        assertTrue(
            "the stamp packs the capture time as its fourth part",
            code(photoActivity).contains("fix.capturedAt"),
        )
    }

    @Test
    fun `a signature records where it was signed`() {
        val clean = code(formData)

        for (field in listOf(
            "_signature_latitude", "_signature_longitude",
            "_signature_accuracy_m", "_signature_captured_at",
        )) {
            assertTrue("a signature must send $field", clean.contains(field))
        }

        // Sent even with no fix: "declined" and "no signal in the courtyard" are
        // different answers to a supervisor asking why a signed report has no position.
        assertTrue(
            "the signature source must be sent unconditionally",
            clean.contains("_signature_gps_source\"] = source"),
        )

        val pad = code(signaturePad)
        assertTrue(
            "the pad must read a fix when Save is pressed",
            Regex("""GeoStamp\.current\(this\)[\s\S]*?writeSignaturePng""").containsMatchIn(pad),
        )
        assertTrue(
            "and return the source even when there is no fix",
            pad.contains("EXTRA_RESULT_GPS_SOURCE, located.wire"),
        )
    }

    @Test
    fun `the agent's own photograph cannot come from the gallery`() {
        val clean = code(photoActivity)

        // Its only job is to record that this agent stood at this door. A gallery image
        // was taken at an unknown time in an unknown place, so offering the picker
        // would invite something that looks like proof of presence and is not.
        assertTrue(
            "the agent slot must skip the source dialog and go straight to the camera",
            Regex("""slot == Slot\.AGENT\)[\s\S]*?requestCamera\(\)""").containsMatchIn(clean),
        )
        assertTrue(
            "and the gallery result must refuse the agent slot outright",
            Regex("""pendingSlot == Slot\.AGENT\)[\s\S]*?imported\.delete\(\)""")
                .containsMatchIn(clean),
        )
        assertTrue(
            "the agent photograph is sent as its own typed slot",
            code(formData).contains("""put("agent_photo", it)"""),
        )
    }
}
