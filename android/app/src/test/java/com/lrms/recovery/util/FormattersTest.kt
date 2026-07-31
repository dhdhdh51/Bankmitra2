package com.lrms.recovery.util

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Unit tests for display formatting.
 *
 * Currency is the important part: every figure in this product is a rupee amount
 * read by branch staff, so it must use Indian digit grouping (1,25,000) and not
 * the Western thousands grouping (125,000).
 */
class FormattersTest {

    // -----------------------------------------------------------------------
    // Money - Indian grouping
    // -----------------------------------------------------------------------

    @Test
    fun `money groups the last three digits then pairs`() {
        assertEquals("1,25,000.00", Formatters.money(125_000.0))
        assertEquals("12,34,567.00", Formatters.money(1_234_567.0))
        assertEquals("1,00,00,000.00", Formatters.money(10_000_000.0))
    }

    @Test
    fun `money handles small values without grouping`() {
        assertEquals("0.00", Formatters.money(0.0))
        assertEquals("1.00", Formatters.money(1.0))
        assertEquals("999.00", Formatters.money(999.0))
        assertEquals("1,000.00", Formatters.money(1_000.0))
    }

    @Test
    fun `money renders paise`() {
        assertEquals("1,25,000.50", Formatters.money(125_000.50))
        assertEquals("0.99", Formatters.money(0.99))
    }

    @Test
    fun `money can omit decimals`() {
        assertEquals("1,25,000", Formatters.money(125_000.0, decimals = false))
        assertEquals("24,500", Formatters.money(24_500.75, decimals = false))
    }

    @Test
    fun `money handles negatives and nulls`() {
        assertEquals("-5,000.00", Formatters.money(-5_000.0))
        assertEquals("0.00", Formatters.money(null))
    }

    @Test
    fun `rupees prefixes the currency sign`() {
        assertEquals("\u20B91,25,000.00", Formatters.rupees(125_000.0))
    }

    @Test
    fun `compact money uses lakh and crore`() {
        assertEquals("1.2L", Formatters.moneyCompact(120_000.0))
        assertEquals("45.5K", Formatters.moneyCompact(45_500.0))
        assertEquals("2.3Cr", Formatters.moneyCompact(23_000_000.0))
        assertEquals("500", Formatters.moneyCompact(500.0))
    }

    @Test
    fun `compact money rounds to one decimal place`() {
        // 1,25,000 is 1.25L, which rounds half-up to 1.3L.
        assertEquals("1.3L", Formatters.moneyCompact(125_000.0))
        assertEquals("1.5Cr", Formatters.moneyCompact(15_000_000.0))
    }

    @Test
    fun `compact money trims a trailing zero decimal`() {
        assertEquals("1L", Formatters.moneyCompact(100_000.0))
        assertEquals("2Cr", Formatters.moneyCompact(20_000_000.0))
    }

    // -----------------------------------------------------------------------
    // Dates
    // -----------------------------------------------------------------------

    @Test
    fun `date formats an ISO date for display`() {
        assertEquals("31 Mar 2024", Formatters.date("2024-03-31"))
        assertEquals("31 Mar 24", Formatters.date("2024-03-31", short = true))
    }

    @Test
    fun `date parses a datetime string`() {
        assertEquals("30 Jul 2026", Formatters.date("2026-07-30 14:30:00"))
    }

    @Test
    fun `date returns a dash for empty or invalid input`() {
        assertEquals(Formatters.DASH, Formatters.date(null))
        assertEquals(Formatters.DASH, Formatters.date(""))
        assertEquals(Formatters.DASH, Formatters.date("0000-00-00"))
        assertEquals(Formatters.DASH, Formatters.date("not a date"))
    }

    @Test
    fun `dateTime includes the time`() {
        val formatted = Formatters.dateTime("2026-07-30 14:30:00")
        assertTrue("Expected a date and time, got: $formatted", formatted.contains("30 Jul 2026"))
        assertTrue(formatted.contains("02:30"))
    }

    @Test
    fun `time converts to a 12 hour clock`() {
        assertEquals("02:30 PM", Formatters.time("14:30:00"))
        assertEquals("09:05 AM", Formatters.time("09:05:00"))
        assertEquals("02:30 PM", Formatters.time("14:30"))
    }

    @Test
    fun `time returns a dash for blank input`() {
        assertEquals(Formatters.DASH, Formatters.time(null))
        assertEquals(Formatters.DASH, Formatters.time(""))
    }

    // -----------------------------------------------------------------------
    // Relative time
    // -----------------------------------------------------------------------

    @Test
    fun `timeAgo describes recent instants`() {
        // A fixed "now" keeps these deterministic.
        val now = 1_800_000_000_000L

        fun ago(millis: Long): String {
            val stamp = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
                .format(java.util.Date(now - millis))
            return Formatters.timeAgo(stamp, now)
        }

        assertEquals("just now", ago(10_000))
        assertEquals("5 mins ago", ago(5 * 60_000))
        assertEquals("1 min ago", ago(60_000))
        assertEquals("3 hours ago", ago(3 * 3_600_000))
        assertEquals("1 hour ago", ago(3_600_000))
        assertEquals("2 days ago", ago(2 * 86_400_000L))
        assertEquals("1 day ago", ago(86_400_000L))
    }

    @Test
    fun `timeAgo falls back to a date for old instants`() {
        val now = 1_800_000_000_000L
        val old = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)
            .format(java.util.Date(now - (200L * 86_400_000L)))

        // Older than a month: a date is more useful than "200 days ago".
        assertTrue(Formatters.timeAgo(old, now).contains(" "))
    }

    @Test
    fun `timeAgo returns a dash for blank input`() {
        assertEquals(Formatters.DASH, Formatters.timeAgo(null))
    }

    // -----------------------------------------------------------------------
    // Labels and PII
    // -----------------------------------------------------------------------

    @Test
    fun `status labels are human readable`() {
        assertEquals("Pending", Formatters.statusLabel("pending"))
        assertEquals("Follow-up", Formatters.statusLabel("followup"))
        assertEquals("Promise", Formatters.statusLabel("promise"))
        assertEquals("Closed", Formatters.statusLabel("closed"))
    }

    @Test
    fun `promise status labels are human readable`() {
        assertEquals("Kept", Formatters.promiseStatusLabel("kept"))
        assertEquals("Broken", Formatters.promiseStatusLabel("broken"))
        assertEquals("Cancelled", Formatters.promiseStatusLabel("cancelled"))
    }

    @Test
    fun `occupation labels come from the shared enum`() {
        assertEquals("Agriculture", Formatters.occupationLabel("agriculture"))
        assertEquals("Dairy", Formatters.occupationLabel("dairy"))
        assertEquals(Formatters.DASH, Formatters.occupationLabel("nonsense"))
        assertEquals(Formatters.DASH, Formatters.occupationLabel(null))
    }

    @Test
    fun `aadhaar is grouped in fours for readability`() {
        assertEquals("1234 5678 9012", Formatters.aadhaar("123456789012"))
        assertEquals("1234 5678 9012", Formatters.aadhaar("1234 5678 9012"))
    }

    @Test
    fun `aadhaar passes through a masked value unchanged`() {
        // The server sends "XXXX XXXX 9012" when the caller cannot see full PII.
        assertEquals("XXXX XXXX 9012", Formatters.aadhaar("XXXX XXXX 9012"))
        assertEquals(Formatters.DASH, Formatters.aadhaar(null))
    }

    @Test
    fun `orDash replaces blank values`() {
        assertEquals(Formatters.DASH, Formatters.orDash(null))
        assertEquals(Formatters.DASH, Formatters.orDash(""))
        assertEquals("Kotri", Formatters.orDash("Kotri"))
    }

    @Test
    fun `initial returns a single uppercase character`() {
        assertEquals("R", Formatters.initial("Ramesh Kumar"))
        assertEquals("S", Formatters.initial("sita devi"))
        assertEquals("?", Formatters.initial(null))
        assertEquals("?", Formatters.initial(""))
    }

    @Test
    fun `isoFrom builds a zero padded ISO date`() {
        // Calendar months are zero based, so January is month 0.
        assertEquals("2026-01-05", Formatters.isoFrom(2026, 0, 5))
        assertEquals("2026-12-31", Formatters.isoFrom(2026, 11, 31))
    }

    @Test
    fun `isoTimeFrom zero pads the clock`() {
        assertEquals("09:05", Formatters.isoTimeFrom(9, 5))
        assertEquals("14:30", Formatters.isoTimeFrom(14, 30))
        assertEquals("00:00", Formatters.isoTimeFrom(0, 0))
    }

    @Test
    fun `todayIso is a valid ISO date`() {
        assertTrue(Formatters.todayIso().matches(Regex("""\d{4}-\d{2}-\d{2}""")))
    }

    @Test
    fun `nowTimeIso is a valid 24 hour time`() {
        assertTrue(Formatters.nowTimeIso().matches(Regex("""\d{2}:\d{2}""")))
    }
}
