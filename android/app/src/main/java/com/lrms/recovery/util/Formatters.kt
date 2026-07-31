package com.lrms.recovery.util

import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

/**
 * Display formatting shared across screens.
 *
 * Currency uses Indian digit grouping (1,25,000.50) rather than the Western
 * thousands grouping, because every figure in this product is a rupee amount
 * read by branch staff.
 *
 * Pure functions with no Android dependencies, so they are unit tested directly.
 */
object Formatters {

    private val isoDate = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val isoDateTime = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)
    private val displayDate = SimpleDateFormat("dd MMM yyyy", Locale.US)
    private val displayDateShort = SimpleDateFormat("dd MMM yy", Locale.US)
    private val displayDateTime = SimpleDateFormat("dd MMM yyyy, hh:mm a", Locale.US)
    private val displayTime = SimpleDateFormat("hh:mm a", Locale.US)
    private val isoTime = SimpleDateFormat("HH:mm:ss", Locale.US)
    private val isoTimeShort = SimpleDateFormat("HH:mm", Locale.US)

    // -----------------------------------------------------------------------
    // Money
    // -----------------------------------------------------------------------

    /** Indian-grouped amount without a symbol, e.g. 12,34,567.00 */
    fun money(amount: Double?, decimals: Boolean = true): String {
        val value = amount ?: 0.0
        val negative = value < 0
        val absolute = kotlin.math.abs(value)

        val whole = absolute.toLong()
        val fraction = if (decimals) {
            val cents = Math.round((absolute - whole) * 100).toInt()
            String.format(Locale.US, ".%02d", cents)
        } else {
            ""
        }

        val grouped = groupIndian(whole.toString())
        return (if (negative) "-" else "") + grouped + fraction
    }

    /** Amount prefixed with the rupee sign. */
    fun rupees(amount: Double?, decimals: Boolean = true): String = "\u20B9" + money(amount, decimals)

    /**
     * Compact form for tight spaces: 1.2L, 45.5K, 2.3Cr.
     * Uses lakh and crore because that is how the figures are discussed.
     */
    fun moneyCompact(amount: Double?): String {
        val value = amount ?: 0.0
        val absolute = kotlin.math.abs(value)
        val sign = if (value < 0) "-" else ""

        return when {
            absolute >= 10_000_000 -> sign + trimZero(absolute / 10_000_000) + "Cr"
            absolute >= 100_000 -> sign + trimZero(absolute / 100_000) + "L"
            absolute >= 1_000 -> sign + trimZero(absolute / 1_000) + "K"
            else -> sign + money(absolute, false)
        }
    }

    private fun trimZero(value: Double): String {
        val rounded = String.format(Locale.US, "%.1f", value)
        return if (rounded.endsWith(".0")) rounded.dropLast(2) else rounded
    }

    /**
     * Indian grouping: last three digits, then pairs.
     * 1234567 -> 12,34,567
     */
    private fun groupIndian(digits: String): String {
        if (digits.length <= 3) return digits

        val lastThree = digits.takeLast(3)
        val rest = digits.dropLast(3)

        val builder = StringBuilder()
        var count = 0
        for (index in rest.indices.reversed()) {
            builder.append(rest[index])
            count++
            if (count % 2 == 0 && index != 0) {
                builder.append(',')
            }
        }

        return builder.reverse().toString() + "," + lastThree
    }

    // -----------------------------------------------------------------------
    // Dates
    // -----------------------------------------------------------------------

    /** "2024-03-31" -> "31 Mar 2024". Returns a dash for null/blank/invalid. */
    fun date(iso: String?, short: Boolean = false): String {
        if (iso.isNullOrBlank() || iso.startsWith("0000")) return DASH
        val parsed = parseIso(iso) ?: return DASH
        return if (short) displayDateShort.format(parsed) else displayDate.format(parsed)
    }

    /** "2024-03-31 14:05:00" -> "31 Mar 2024, 02:05 PM" */
    fun dateTime(iso: String?): String {
        if (iso.isNullOrBlank()) return DASH
        val parsed = parseIso(iso) ?: return DASH
        return displayDateTime.format(parsed)
    }

    /** "14:30:00" -> "02:30 PM" */
    fun time(value: String?): String {
        if (value.isNullOrBlank()) return DASH
        val parsed = runCatching { isoTime.parse(value) }.getOrNull()
            ?: runCatching { isoTimeShort.parse(value) }.getOrNull()
            ?: return value
        return displayTime.format(parsed)
    }

    /** Relative age: "just now", "5 mins ago", "3 days ago", else a date. */
    fun timeAgo(iso: String?, nowMillis: Long = System.currentTimeMillis()): String {
        if (iso.isNullOrBlank()) return DASH
        val parsed = parseIso(iso) ?: return DASH

        val diffSeconds = (nowMillis - parsed.time) / 1000
        if (diffSeconds < 0) return date(iso)

        return when {
            diffSeconds < 60 -> "just now"
            diffSeconds < 3_600 -> {
                val minutes = diffSeconds / 60
                "$minutes min${plural(minutes)} ago"
            }
            diffSeconds < 86_400 -> {
                val hours = diffSeconds / 3_600
                "$hours hour${plural(hours)} ago"
            }
            diffSeconds < 2_592_000 -> {
                val days = diffSeconds / 86_400
                "$days day${plural(days)} ago"
            }
            else -> date(iso)
        }
    }

    private fun plural(count: Long): String = if (count == 1L) "" else "s"

    private fun parseIso(value: String): Date? {
        val trimmed = value.trim()
        return runCatching { isoDateTime.parse(trimmed) }.getOrNull()
            ?: runCatching { isoDate.parse(trimmed) }.getOrNull()
    }

    fun todayIso(): String = isoDate.format(Date())

    fun nowTimeIso(): String = isoTimeShort.format(Date())

    fun isoFrom(year: Int, month: Int, dayOfMonth: Int): String {
        val calendar = Calendar.getInstance().apply {
            set(Calendar.YEAR, year)
            set(Calendar.MONTH, month)
            set(Calendar.DAY_OF_MONTH, dayOfMonth)
        }
        return isoDate.format(calendar.time)
    }

    fun isoTimeFrom(hour: Int, minute: Int): String =
        String.format(Locale.US, "%02d:%02d", hour, minute)

    // -----------------------------------------------------------------------
    // Text
    // -----------------------------------------------------------------------

    /** Human label for a lead status. */
    fun statusLabel(status: String?): String = when (status) {
        "pending" -> "Pending"
        "visited" -> "Visited"
        "promise" -> "Promise"
        "followup" -> "Follow-up"
        "legal" -> "Legal"
        "closed" -> "Closed"
        else -> status?.replaceFirstChar { it.uppercase() } ?: DASH
    }

    fun promiseStatusLabel(status: String?): String = when (status) {
        "pending" -> "Pending"
        "kept" -> "Kept"
        "broken" -> "Broken"
        "cancelled" -> "Cancelled"
        else -> status?.replaceFirstChar { it.uppercase() } ?: DASH
    }

    fun occupationLabel(value: String?): String =
        com.lrms.recovery.domain.VisitFormData.OCCUPATIONS
            .firstOrNull { it.first == value }?.second ?: DASH

    /** Groups an Aadhaar number for readability: 1234 5678 9012 */
    fun aadhaar(value: String?): String {
        if (value.isNullOrBlank()) return DASH
        val digits = value.filter { it.isDigit() }
        if (digits.length != 12) return value
        return "${digits.take(4)} ${digits.drop(4).take(4)} ${digits.takeLast(4)}"
    }

    fun orDash(value: String?): String = if (value.isNullOrBlank()) DASH else value

    /** Initial for an avatar bubble. */
    fun initial(name: String?): String =
        name?.trim()?.firstOrNull()?.uppercase() ?: "?"

    const val DASH = "\u2014"
}
