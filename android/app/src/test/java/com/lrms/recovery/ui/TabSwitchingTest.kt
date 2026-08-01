package com.lrms.recovery.ui

import java.io.File
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Guards the tab switcher against the overlap bug.
 *
 * THE BUG THIS EXISTS FOR. `MainActivity` kept its own `Map<Int, Fragment>` of the
 * tabs it had created and used that to decide what to hide. The map lives in the
 * activity instance; the fragments do not. So anything that recreated the activity -
 * a rotation, a system font-size change, the dark-mode switch on the Account tab,
 * returning after the process was killed - left the map empty while the
 * FragmentManager restored the tagged fragments it already had. The map then said
 * "nothing is showing", so nothing was hidden, and a second copy of the tab was added
 * on top of the restored one. Two fragments visible at once, one bleeding through the
 * other, and every further tab tap compounded it because `hide()` was being called on
 * an instance that was no longer on screen.
 *
 * These are source-level assertions rather than a Robolectric run. Driving a real
 * recreation would mean standing up a signed-in session and stubbing the two network
 * calls `MainActivity` makes on create, and the thing that actually went wrong is
 * structural: a second source of truth for which fragment is on screen. So that is
 * what is pinned here - if the map comes back, or the code goes back to hiding only
 * the tab it believes was last active, this fails.
 */
class TabSwitchingTest {

    private val source = File(
        "src/main/java/com/lrms/recovery/ui/main/MainActivity.kt",
    ).readText()

    private val code: String = source
        .replace(Regex("""/\*[\s\S]*?\*/"""), "")
        .replace(Regex("""//[^\n]*"""), "")

    private val showTab: String = code.substringAfter("private fun showTab", "")
        .substringBefore("private fun createFragment")

    @Test
    fun `showTab exists and was found`() {
        assertTrue("showTab() could not be located", showTab.isNotBlank())
    }

    // -----------------------------------------------------------------------
    // One source of truth
    // -----------------------------------------------------------------------

    @Test
    fun `the activity does not cache fragments in a field`() {
        // This is the bug, exactly. A collection of fragments held by the activity is
        // empty after recreation while the FragmentManager's is not, and the two
        // disagreeing is what put a second copy on screen.
        assertFalse(
            "MainActivity must not keep its own map of fragments - it does not survive recreation",
            Regex("""mutableMapOf<[^>]*Fragment>""").containsMatchIn(code),
        )
        assertFalse(
            "nor a list of them",
            Regex("""mutableListOf<[^>]*Fragment>""").containsMatchIn(code),
        )
        assertFalse(
            "nor an array",
            Regex("""(?:val|var)\s+fragments\s*[:=]""").containsMatchIn(code),
        )
    }

    @Test
    fun `the target tab is looked up from the fragment manager`() {
        assertTrue(
            "showTab() must resolve the tab through findFragmentByTag, which survives recreation",
            showTab.contains("findFragmentByTag"),
        )
        assertTrue(
            "and the lookup must be keyed by the tab's tag",
            Regex("""findFragmentByTag\(tagFor\(""").containsMatchIn(showTab),
        )
    }

    @Test
    fun `a tab is only added when the manager does not already have it`() {
        // Adding without checking is what produced the duplicate. The add must be
        // guarded by the result of the lookup.
        assertTrue(
            "the add must be conditional on the lookup returning nothing",
            Regex("""(?:target|existing)\s*==\s*null[\s\S]{0,200}\.add\(""").containsMatchIn(showTab),
        )
        assertTrue(
            "and an existing tab must be shown rather than re-added",
            showTab.contains(".show("),
        )
    }

    // -----------------------------------------------------------------------
    // Nothing is left on screen
    // -----------------------------------------------------------------------

    @Test
    fun `every non-target fragment is hidden, not just the last active one`() {
        // Hiding only the tab the activity believes was last active is what fails after
        // recreation: that belief is wrong precisely when the bug bites. Hiding
        // everything that is not the target is self-healing and costs nothing.
        assertTrue(
            "showTab() must iterate the manager's fragments",
            Regex("""for\s*\([\s\S]{0,60}manager\.fragments""").containsMatchIn(showTab),
        )
        assertTrue(
            "and hide each one that is not the target",
            Regex("""!==\s*target[\s\S]{0,120}\.hide\(""").containsMatchIn(showTab),
        )
    }

    @Test
    fun `hiding is not driven by the remembered active tab`() {
        // `hide(fragments[activeItemId])` was the original line. activeItemId is a
        // perfectly good record of which tab to restore, and a useless basis for
        // deciding which view is currently on screen.
        assertFalse(
            "activeItemId must not be used to decide what to hide",
            Regex("""hide\([^)]*activeItemId""").containsMatchIn(showTab),
        )
    }

    // -----------------------------------------------------------------------
    // Surviving the trip
    // -----------------------------------------------------------------------

    @Test
    fun `the selected tab is saved and restored`() {
        assertTrue(
            "the active tab must be written to the instance state",
            Regex("""onSaveInstanceState[\s\S]{0,240}putInt\(STATE_ACTIVE_TAB""")
                .containsMatchIn(code),
        )
        assertTrue(
            "and read back on create",
            Regex("""savedInstanceState\?\.getInt\(STATE_ACTIVE_TAB\)""").containsMatchIn(code),
        )
    }

    @Test
    fun `a tab switch cannot crash after onSaveInstanceState`() {
        // A tap can land after the activity has saved its state - the system pausing it
        // mid-gesture is enough - and commit() throws there. Losing which tab was
        // showing is recoverable; crashing an agent out of the app mid-visit is not.
        assertTrue(
            "the transaction must tolerate state loss",
            showTab.contains("commitAllowingStateLoss()"),
        )
    }

    @Test
    fun `tab tags are derived from the tab id`() {
        // Stable tags are what makes the FragmentManager lookup work across recreation.
        // A tag derived from anything instance-specific would defeat the whole fix.
        assertTrue(
            "tagFor() must build the tag from the item id",
            Regex("""fun tagFor\(itemId: Int\)[\s\S]{0,80}itemId""").containsMatchIn(code),
        )
    }
}
