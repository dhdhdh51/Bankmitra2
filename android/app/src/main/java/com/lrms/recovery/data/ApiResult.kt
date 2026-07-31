package com.lrms.recovery.data

/**
 * Outcome of an API call.
 *
 * Failures are modelled explicitly so the UI can react to the kind of problem
 * rather than showing one generic message for everything: a dropped connection,
 * an expired session and a field validation error all need different handling.
 */
sealed class ApiResult<out T> {

    data class Success<T>(val data: T, val message: String = "") : ApiResult<T>()

    /** The server answered with success = false, or a 4xx/5xx. */
    data class Failure(
        val message: String,
        val httpCode: Int = 0,
        val fieldErrors: Map<String, List<String>> = emptyMap(),
    ) : ApiResult<Nothing>()

    /** No usable connection, DNS failure or timeout. */
    data class NetworkError(val message: String) : ApiResult<Nothing>()

    /** The session is gone and a refresh could not save it. */
    data object Unauthorised : ApiResult<Nothing>()

    val isSuccess: Boolean get() = this is Success

    fun dataOrNull(): T? = (this as? Success)?.data

    /** First error for a field, for inline form messages. */
    fun fieldError(field: String): String? =
        (this as? Failure)?.fieldErrors?.get(field)?.firstOrNull()

    /** A message suitable for a snackbar. */
    fun errorMessage(fallback: String): String = when (this) {
        is Failure -> message.ifBlank { fallback }
        is NetworkError -> message
        Unauthorised -> "Your session has expired. Please sign in again."
        is Success -> ""
    }

    inline fun <R> map(transform: (T) -> R): ApiResult<R> = when (this) {
        is Success -> Success(transform(data), message)
        is Failure -> this
        is NetworkError -> this
        Unauthorised -> Unauthorised
    }
}
