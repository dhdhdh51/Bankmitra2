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

        private const val EXPIRY_MARGIN_MS = 30_000L
    }
}
