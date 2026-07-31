package com.lrms.recovery.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Unit tests for the API result wrapper.
 *
 * The UI branches on these variants to decide between an inline field error, a
 * snackbar and a forced sign-out, so the distinctions are worth pinning down.
 */
class ApiResultTest {

    @Test
    fun `success exposes its payload`() {
        val result = ApiResult.Success("payload", "Saved")

        assertTrue(result.isSuccess)
        assertEquals("payload", result.dataOrNull())
        assertEquals("Saved", result.message)
    }

    @Test
    fun `failure is not a success and has no payload`() {
        val result = ApiResult.Failure("Bad request", httpCode = 400)

        assertFalse(result.isSuccess)
        assertNull(result.dataOrNull())
        assertEquals(400, result.httpCode)
    }

    @Test
    fun `field errors are readable by field name`() {
        val result = ApiResult.Failure(
            message = "Validation failed",
            httpCode = 422,
            fieldErrors = mapOf(
                "promise_date" to listOf("Select the promised payment date"),
                "visit_time" to listOf("Enter a valid time", "Second message"),
            ),
        )

        assertEquals("Select the promised payment date", result.fieldError("promise_date"))
        assertEquals("Enter a valid time", result.fieldError("visit_time"))
        assertNull(result.fieldError("unknown_field"))
    }

    @Test
    fun `errorMessage prefers the server message`() {
        val result = ApiResult.Failure("That account is suspended")
        assertEquals("That account is suspended", result.errorMessage("fallback"))
    }

    @Test
    fun `errorMessage uses the fallback when the server said nothing`() {
        val result = ApiResult.Failure("")
        assertEquals("fallback", result.errorMessage("fallback"))
    }

    @Test
    fun `network error carries its own message`() {
        val result = ApiResult.NetworkError("Check your internet connection")
        assertEquals("Check your internet connection", result.errorMessage("fallback"))
        assertFalse(result.isSuccess)
    }

    @Test
    fun `unauthorised has a session expiry message`() {
        assertTrue(ApiResult.Unauthorised.errorMessage("fallback").contains("session"))
    }

    @Test
    fun `success has no error message`() {
        assertEquals("", ApiResult.Success(1).errorMessage("fallback"))
    }

    @Test
    fun `map transforms a success payload`() {
        val mapped = ApiResult.Success(5).map { it * 2 }

        assertTrue(mapped.isSuccess)
        assertEquals(10, mapped.dataOrNull())
    }

    @Test
    fun `map preserves failure variants unchanged`() {
        val failure: ApiResult<Int> = ApiResult.Failure("nope", 500)
        val mappedFailure = failure.map { it * 2 }
        assertTrue(mappedFailure is ApiResult.Failure)
        assertEquals(500, (mappedFailure as ApiResult.Failure).httpCode)

        val network: ApiResult<Int> = ApiResult.NetworkError("offline")
        assertTrue(network.map { it * 2 } is ApiResult.NetworkError)

        val unauthorised: ApiResult<Int> = ApiResult.Unauthorised
        assertTrue(unauthorised.map { it * 2 } is ApiResult.Unauthorised)
    }

    @Test
    fun `map keeps the success message`() {
        val mapped = ApiResult.Success(1, "Created").map { it.toString() }
        assertEquals("Created", (mapped as ApiResult.Success).message)
    }
}
