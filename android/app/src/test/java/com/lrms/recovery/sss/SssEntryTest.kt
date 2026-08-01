package com.lrms.recovery.sss

import java.io.File
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Guards the asymmetry between figures an agent types and figures the system counts.
 *
 * The four scheme counts are typed. Visits, contacts and PTP are counted from filed
 * reports. If a visit-count field ever appears in this app, there are immediately two
 * answers to "how many visits did this agent make today" - the typed one and the one
 * the supervisor's report computes - and the agent is scored on one while defending
 * the other.
 */
class SssEntryTest {

    private val activity = File(
        "src/main/java/com/lrms/recovery/ui/sss/SssEntryActivity.kt",
    ).readText()

    private val layout = File("src/main/res/layout/activity_sss_entry.xml").readText()

    private val accountLayout = File("src/main/res/layout/fragment_account.xml").readText()

    private val accountFragment = File(
        "src/main/java/com/lrms/recovery/ui/account/AccountFragment.kt",
    ).readText()

    private val manifest = File("src/main/AndroidManifest.xml").readText()

    private val apiService = File(
        "src/main/java/com/lrms/recovery/data/remote/ApiService.kt",
    ).readText()

    private fun code(source: String): String = source
        .replace(Regex("""<!--.*?-->""", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("""/\*.*?\*/""", RegexOption.DOT_MATCHES_ALL), "")
        .replace(Regex("""//[^\n]*"""), "")

    // -----------------------------------------------------------------------
    // The agent can reach it
    // -----------------------------------------------------------------------

    @Test
    fun `the screen is reachable from the account tab`() {
        // The whole reason this screen exists is that the panel was the only place
        // these figures could be entered, and agents cannot open the panel. A screen
        // nothing links to would reproduce exactly that problem.
        assertTrue(
            "the account layout must carry an SSS row",
            accountLayout.contains("@+id/rowSss"),
        )
        assertTrue(
            "the row must open SssEntryActivity",
            Regex("""rowSss\.setOnClickListener(.|\n)*?SssEntryActivity""")
                .containsMatchIn(code(accountFragment)),
        )
    }

    @Test
    fun `the activity is declared and not exported`() {
        val clean = code(manifest)
        assertTrue("the activity must be declared", clean.contains(".ui.sss.SssEntryActivity"))
        assertTrue(
            "it must not be exported",
            Regex("""SssEntryActivity(.|\n)*?android:exported="false"""").containsMatchIn(clean),
        )
    }

    // -----------------------------------------------------------------------
    // Four typed fields, and no typed visit count
    // -----------------------------------------------------------------------

    @Test
    fun `all four schemes can be entered`() {
        for (field in listOf("inputApy", "inputPmjjby", "inputPmsby", "inputPmjdy")) {
            assertTrue("$field is missing from the layout", layout.contains("@+id/$field"))
            assertTrue("$field is never read", activity.contains("binding.$field"))
        }
    }

    @Test
    fun `the scheme abbreviations are spelled out for the agent`() {
        // "PMJJBY" and "PMSBY" differ by two letters and cover different things.
        // An agent entering one figure against the other is a silent data error.
        val strings = File("src/main/res/values/strings.xml").readText()
        for (full in listOf(
            "Atal Pension Yojana",
            "Pradhan Mantri Jeevan Jyoti Bima Yojana",
            "Pradhan Mantri Suraksha Bima Yojana",
            "Pradhan Mantri Jan Dhan Yojana",
        )) {
            assertTrue("the full name \"$full\" must be shown", strings.contains(full))
        }
    }

    @Test
    fun `there is no field anywhere for typing a visit count`() {
        // The counted figures are displayed in TextViews, never inputs. An EditText
        // for visits would create a self-reported number that the server also
        // computes independently - two answers, and the agent scored on one.
        assertTrue("visits must be displayed", layout.contains("@+id/textVisits"))
        assertFalse(
            "visits must never be an input field",
            layout.contains("@+id/inputVisits") || layout.contains("@+id/inputVisitCount"),
        )
        assertFalse(
            "the app must not send a visit count",
            code(apiService).contains("visits_done") || code(apiService).contains("visit_count"),
        )
    }

    @Test
    fun `the counted figures are labelled as counted, not asked for`() {
        assertTrue(
            "the counted block must explain where those numbers come from",
            layout.contains("@string/sss_counted_note"),
        )
        val strings = File("src/main/res/values/strings.xml").readText()
        val note = Regex("""<string name="sss_counted_note">([^<]+)</string>""")
            .find(strings)?.groupValues?.get(1)
        requireNotNull(note) { "sss_counted_note is missing" }
        assertTrue("it must say the agent does not type them", note.contains("never type"))
    }

    // -----------------------------------------------------------------------
    // Saving is safe to retry, and an old day is read-only
    // -----------------------------------------------------------------------

    @Test
    fun `a resend cannot double a figure`() {
        // The endpoint is an upsert on (agent, date). This test pins the comment that
        // says so, because the next person to see a POST creating rows may well
        // "fix" it into a create-only endpoint.
        assertTrue(
            "saveSss must be documented as an upsert",
            Regex("""upsert""").containsMatchIn(apiService),
        )
        assertTrue(
            "the activity must send the date it loaded, not a fresh 'today'",
            activity.contains("date = payload?.date"),
        )
    }

    @Test
    fun `a day the server marks read-only cannot be edited`() {
        assertTrue(
            "editability must come from the server, not be assumed",
            Regex("""setEditable\(data\.editable\)""").containsMatchIn(code(activity)),
        )
        assertTrue(
            "the save button must be hidden on a read-only day",
            Regex("""buttonSave\.visibility(.|\n)*?editable""").containsMatchIn(code(activity)),
        )
        assertTrue(
            "and the reason must be shown",
            layout.contains("@string/sss_read_only"),
        )
    }

    @Test
    fun `zero is shown as empty rather than as a filled-in zero`() {
        // "None today" and "not filled in yet" are different, and the difference is a
        // target met versus a target missed.
        assertTrue(
            "a zero must render as an empty field",
            Regex("""takeIf \{ it > 0 \}""").containsMatchIn(code(activity)),
        )
    }

    @Test
    fun `a nonsense entry does not crash the screen`() {
        assertTrue(
            "input must be parsed defensively",
            Regex("""toIntOrNull\(\)""").containsMatchIn(code(activity)),
        )
        assertTrue(
            "and clamped to the range the server accepts",
            Regex("""coerceIn\(0, 999\)""").containsMatchIn(code(activity)),
        )
    }

    @Test
    fun `the screen reloads after saving rather than trusting what was typed`() {
        // Matched inside save() rather than across the file: an alternation like
        // (.|\n)*? over a few hundred lines backtracks itself into a StackOverflow,
        // which is a test failing for a reason that has nothing to do with the code.
        val saveBody = code(activity).substringAfter("private fun save()", "")
        assertTrue("save() not found", saveBody.isNotEmpty())
        assertTrue(
            "counted figures may have moved while the form was open, so it must re-read",
            saveBody.contains("load()"),
        )
    }
}
