package com.lrms.recovery.data.local

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.google.gson.Gson
import com.lrms.recovery.data.remote.UserDto

/**
 * Persistent session state: tokens, the signed-in user and the server address.
 *
 * Backed by EncryptedSharedPreferences so the JWT and refresh token are encrypted
 * at rest. If the keystore is unavailable (a handful of OEM builds have a broken
 * one), it falls back to plain preferences rather than crashing the app - a
 * degraded but working session is better than an agent locked out in the field.
 */
class SessionStore(context: Context) {

    private val prefs: SharedPreferences = createPreferences(context)
    private val gson = Gson()

    private fun createPreferences(context: Context): SharedPreferences = try {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        EncryptedSharedPreferences.create(
            context,
            ENCRYPTED_FILE,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    } catch (error: Exception) {
        Log.w(TAG, "Encrypted preferences unavailable, falling back to plain storage", error)
        context.getSharedPreferences(FALLBACK_FILE, Context.MODE_PRIVATE)
    }

    // ---------------------------------------------------------------------
    // Tokens
    // ---------------------------------------------------------------------

    var accessToken: String?
        get() = prefs.getString(KEY_ACCESS_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_ACCESS_TOKEN, value).apply()

    var refreshToken: String?
        get() = prefs.getString(KEY_REFRESH_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_REFRESH_TOKEN, value).apply()

    /** Wall-clock time the access token stops being valid. */
    var accessTokenExpiresAt: Long
        get() = prefs.getLong(KEY_EXPIRES_AT, 0L)
        set(value) = prefs.edit().putLong(KEY_EXPIRES_AT, value).apply()

    val isLoggedIn: Boolean
        get() = !accessToken.isNullOrBlank() && user != null

    /**
     * True when the access token is expired or about to be.
     * A 30 second margin avoids sending a token that dies in transit.
     */
    fun isAccessTokenExpiring(nowMillis: Long = System.currentTimeMillis()): Boolean {
        val expiry = accessTokenExpiresAt
        return expiry > 0L && nowMillis >= expiry - EXPIRY_MARGIN_MS
    }

    fun saveTokens(access: String, refresh: String, expiresInSeconds: Long) {
        prefs.edit()
            .putString(KEY_ACCESS_TOKEN, access)
            .putString(KEY_REFRESH_TOKEN, refresh)
            .putLong(KEY_EXPIRES_AT, System.currentTimeMillis() + (expiresInSeconds * 1000L))
            .apply()
    }

    // ---------------------------------------------------------------------
    // User
    // ---------------------------------------------------------------------

    var user: UserDto?
        get() {
            val json = prefs.getString(KEY_USER, null) ?: return null
            return try {
                gson.fromJson(json, UserDto::class.java)
            } catch (error: Exception) {
                // A stored payload from an older app version may not parse.
                Log.w(TAG, "Stored user could not be read; clearing it", error)
                prefs.edit().remove(KEY_USER).apply()
                null
            }
        }
        set(value) {
            prefs.edit().apply {
                if (value == null) remove(KEY_USER) else putString(KEY_USER, gson.toJson(value))
            }.apply()
        }

    // ---------------------------------------------------------------------
    // Server address
    // ---------------------------------------------------------------------

    /**
     * Base URL of the API, always with a trailing slash (Retrofit requires it).
     *
     * In a release build this is ALWAYS the address compiled into the APK. Any
     * stored value is ignored rather than deleted, which matters for an app that
     * is already installed: an earlier build let the agent type a host, so a
     * phone in the field may still hold the old placeholder domain. Reading the
     * built-in value means the update simply starts working, with no reinstall
     * and nothing for the agent to fix.
     *
     * Only a debug build honours a stored override.
     */
    var baseUrl: String
        get() {
            val builtIn = com.lrms.recovery.BuildConfig.DEFAULT_API_BASE_URL
            if (!com.lrms.recovery.BuildConfig.ALLOW_CUSTOM_SERVER) {
                return builtIn
            }
            return prefs.getString(KEY_BASE_URL, null) ?: builtIn
        }
        set(value) {
            if (!com.lrms.recovery.BuildConfig.ALLOW_CUSTOM_SERVER) {
                return
            }
            val normalised = if (value.endsWith("/")) value else "$value/"
            prefs.edit().putString(KEY_BASE_URL, normalised).apply()
        }

    /** True when a debug build is pointed somewhere other than the built-in host. */
    val hasCustomBaseUrl: Boolean
        get() = com.lrms.recovery.BuildConfig.ALLOW_CUSTOM_SERVER
            && prefs.getString(KEY_BASE_URL, null) != null

    // ---------------------------------------------------------------------
    // Preferences
    // ---------------------------------------------------------------------

    /** -1 follow system, 1 light, 2 dark (AppCompatDelegate constants). */
    var themeMode: Int
        get() = prefs.getInt(KEY_THEME, -1)
        set(value) = prefs.edit().putInt(KEY_THEME, value).apply()

    var rememberMe: Boolean
        get() = prefs.getBoolean(KEY_REMEMBER, true)
        set(value) = prefs.edit().putBoolean(KEY_REMEMBER, value).apply()

    // ---- Daily report reminder ---------------------------------------------

    /**
     * The bank's deadline, cached from /meta as `HH:mm`.
     *
     * Cached rather than fetched when the alarm fires, because the alarm has to work
     * with no network - which in these villages is the normal case, not the edge one.
     * A stale deadline is far better than no reminder.
     */
    var reportDueTime: String?
        get() = prefs.getString(KEY_REPORT_DUE, null)
        set(value) = prefs.edit().putString(KEY_REPORT_DUE, value).apply()

    /** Whether the bank has the reminder switched on for everybody. */
    var reportReminderAllowed: Boolean
        get() = prefs.getBoolean(KEY_REPORT_ALLOWED, true)
        set(value) = prefs.edit().putBoolean(KEY_REPORT_ALLOWED, value).apply()

    /**
     * How often the alarm re-fires until the report is in, in minutes, from /meta.
     *
     * The bank's number, not the agent's. There used to be an agent-side switch and an
     * agent-side lead time here; both are gone. A reminder that the person being measured
     * against the deadline can move or silence is not a reminder.
     */
    var reportReminderRepeatMinutes: Int
        get() = prefs.getInt(KEY_REPORT_REPEAT, 15)
        set(value) = prefs.edit().putInt(KEY_REPORT_REPEAT, value).apply()

    /** The hour repeats stop at, from /meta. Overnight alarms get the app silenced. */
    var reportReminderUntilHour: Int
        get() = prefs.getInt(KEY_REPORT_UNTIL, 22)
        set(value) = prefs.edit().putInt(KEY_REPORT_UNTIL, value).apply()

    /**
     * The last date this agent filed anything, `yyyy-MM-dd`.
     *
     * Written when a visit is submitted or SSS figures are saved, and read when the
     * alarm fires so somebody who has already done the work is not told to do it.
     */
    var lastReportSubmittedDate: String?
        get() = prefs.getString(KEY_REPORT_LAST_SUBMIT, null)
        set(value) = prefs.edit().putString(KEY_REPORT_LAST_SUBMIT, value).apply()

    /**
     * Whether the OS location permission has been reported to the server as consent.
     *
     * A local latch so the app does not repeat the call on every launch. Deliberately NOT
     * cleared with the session: the record belongs to the agent account on the server, and
     * re-posting it for the same install adds nothing. It is not authoritative either - the
     * server decides - so a phone that loses this flag simply posts once more.
     */
    var locationConsentRecorded: Boolean
        get() = prefs.getBoolean(KEY_LOCATION_CONSENT_SENT, false)
        set(value) = prefs.edit().putBoolean(KEY_LOCATION_CONSENT_SENT, value).apply()

    var lastEmployeeCode: String?
        get() = prefs.getString(KEY_LAST_CODE, null)
        set(value) = prefs.edit().putString(KEY_LAST_CODE, value).apply()

    var deviceToken: String?
        get() = prefs.getString(KEY_DEVICE_TOKEN, null)
        set(value) = prefs.edit().putString(KEY_DEVICE_TOKEN, value).apply()

    // ---------------------------------------------------------------------

    /**
     * Clears the session. The server address, theme and remembered employee code
     * survive so the next sign-in does not require retyping them.
     */
    fun clearSession() {
        prefs.edit()
            .remove(KEY_ACCESS_TOKEN)
            .remove(KEY_REFRESH_TOKEN)
            .remove(KEY_EXPIRES_AT)
            .remove(KEY_USER)
            // Cleared with the session, unlike the theme or the base URL. These
            // phones get shared and reassigned, and "already submitted today" left
            // behind by the previous agent would silently deny the next one their
            // reminder - on their first day, when they need it most.
            .remove(KEY_REPORT_LAST_SUBMIT)
            .apply()
    }

    companion object {
        private const val TAG = "SessionStore"
        private const val ENCRYPTED_FILE = "lrms_session_secure"
        private const val FALLBACK_FILE = "lrms_session"

        private const val KEY_ACCESS_TOKEN = "access_token"
        private const val KEY_REFRESH_TOKEN = "refresh_token"
        private const val KEY_EXPIRES_AT = "expires_at"
        private const val KEY_USER = "user"
        private const val KEY_BASE_URL = "base_url"
        private const val KEY_THEME = "theme_mode"
        private const val KEY_REMEMBER = "remember_me"
        private const val KEY_LAST_CODE = "last_employee_code"
        private const val KEY_DEVICE_TOKEN = "device_token"
        private const val KEY_REPORT_DUE = "report_due_time"
        private const val KEY_REPORT_ALLOWED = "report_reminder_allowed"
        private const val KEY_REPORT_REPEAT = "report_reminder_repeat"
        private const val KEY_REPORT_UNTIL = "report_reminder_until"
        private const val KEY_REPORT_LAST_SUBMIT = "report_last_submitted"
        private const val KEY_LOCATION_CONSENT_SENT = "location_consent_sent"

        private const val EXPIRY_MARGIN_MS = 30_000L
    }
}
