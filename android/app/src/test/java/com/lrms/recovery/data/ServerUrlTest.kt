package com.lrms.recovery.data

import com.lrms.recovery.BuildConfig
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Pins the API host that ships in the APK.
 *
 * The address is compiled in so an agent never types a URL - and so nobody can
 * point the app at another host. A borrower-data app that accepts an arbitrary
 * API server is a phishing target: anyone who can talk an agent into pasting a
 * link collects their password and their assigned leads.
 *
 * These run against the DEBUG variant, so ALLOW_CUSTOM_SERVER is true here. The
 * checks below are about the URL itself and the shape of the flag, not the debug
 * value - the release value is asserted by its absence of a UI path in
 * LoginActivity and by SessionStore ignoring stored overrides.
 */
class ServerUrlTest {

    @Test
    fun `the built-in API base url points at the production server`() {
        assertEquals("https://my.controversy.blog/api/v1/", BuildConfig.DEFAULT_API_BASE_URL)
    }

    @Test
    fun `the base url is https`() {
        // The app disables cleartext outside localhost, so an http host would fail
        // every request at runtime rather than at build time.
        assertTrue(
            "the API host must be https",
            BuildConfig.DEFAULT_API_BASE_URL.startsWith("https://"),
        )
    }

    @Test
    fun `the base url ends in a slash`() {
        // Retrofit silently drops the last path segment of a base url without a
        // trailing slash: ".../api/v1" would resolve calls against ".../api/".
        assertTrue(
            "Retrofit requires a trailing slash",
            BuildConfig.DEFAULT_API_BASE_URL.endsWith("/"),
        )
    }

    @Test
    fun `the base url carries the api version prefix`() {
        assertTrue(
            "every endpoint is mounted under /api/v1",
            BuildConfig.DEFAULT_API_BASE_URL.endsWith("/api/v1/"),
        )
    }

    @Test
    fun `no placeholder domain is left in the build`() {
        val url = BuildConfig.DEFAULT_API_BASE_URL
        for (placeholder in listOf("example.com", "example.org", "localhost", "10.0.2.2", "changeme")) {
            assertFalse(
                "the shipped API host still contains the placeholder '$placeholder'",
                url.contains(placeholder),
            )
        }
    }

    @Test
    fun `the custom server flag exists and is a debug-only affordance`() {
        // Reading it here is what guarantees the field is still generated; if the
        // buildConfigField were dropped this stops compiling.
        assertTrue(
            "a debug build must stay re-pointable at a laptop",
            BuildConfig.ALLOW_CUSTOM_SERVER,
        )
    }
}
