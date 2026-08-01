package com.lrms.recovery.data

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import com.google.gson.Gson
import com.lrms.recovery.data.local.SessionStore
import com.lrms.recovery.data.remote.ApiClient
import com.lrms.recovery.data.remote.ApiEnvelope
import com.lrms.recovery.data.remote.ApiService
import com.lrms.recovery.data.remote.AgentDashboardPayload
import com.lrms.recovery.data.remote.AuthPayload
import com.lrms.recovery.data.remote.ChangePasswordRequest
import com.lrms.recovery.data.remote.CustomerProfilePayload
import com.lrms.recovery.data.remote.DeviceTokenRequest
import com.lrms.recovery.data.remote.ForgotPasswordRequest
import com.lrms.recovery.data.remote.FormOptionsPayload
import com.lrms.recovery.data.remote.HistoryPayload
import com.lrms.recovery.data.remote.LeadDto
import com.lrms.recovery.data.remote.LoginRequest
import com.lrms.recovery.data.remote.LogoutRequest
import com.lrms.recovery.data.remote.MetaPayload
import com.lrms.recovery.data.remote.NotificationDto
import com.lrms.recovery.data.remote.OtpPayload
import com.lrms.recovery.data.remote.PageMeta
import com.lrms.recovery.data.remote.PingPayload
import com.lrms.recovery.data.remote.PromiseDto
import com.lrms.recovery.data.remote.ResetPasswordRequest
import com.lrms.recovery.data.remote.UnreadPayload
import com.lrms.recovery.data.remote.UserDto
import com.lrms.recovery.data.remote.ValidationPayload
import com.lrms.recovery.data.remote.VisitDetailPayload
import com.lrms.recovery.data.remote.VisitSubmitPayload
import com.lrms.recovery.data.remote.VisitSummaryDto
import com.lrms.recovery.domain.VisitFormData
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import retrofit2.Response
import java.io.File
import java.io.IOException
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.URI
import java.net.UnknownHostException
import javax.net.ssl.SSLException

/**
 * Single gateway between the UI and the API.
 *
 * All calls run on the IO dispatcher and come back as [ApiResult], so no screen
 * ever touches Retrofit, HTTP codes or exceptions directly.
 */
class LrmsRepository(context: Context) {

    private val appContext = context.applicationContext
    val session = SessionStore(appContext)
    private val gson = Gson()

    private val api: ApiService get() = ApiClient.service(session)

    /** Paginated list plus its meta block. */
    data class Page<T>(val items: List<T>, val meta: PageMeta?)

    // =======================================================================
    // Auth
    // =======================================================================

    suspend fun ping(): ApiResult<PingPayload> = call { api.ping() }

    suspend fun login(
        employeeCode: String,
        password: String,
        rememberMe: Boolean,
    ): ApiResult<AuthPayload> {
        val result = call {
            api.login(
                LoginRequest(
                    employeeCode = employeeCode.trim(),
                    password = password,
                    deviceToken = session.deviceToken,
                    appVersion = com.lrms.recovery.BuildConfig.VERSION_NAME,
                ),
            )
        }

        if (result is ApiResult.Success) {
            val payload = result.data
            session.saveTokens(payload.accessToken, payload.refreshToken, payload.expiresIn)
            session.user = payload.user
            session.rememberMe = rememberMe
            session.lastEmployeeCode = if (rememberMe) employeeCode.trim() else null
        }

        return result
    }

    suspend fun logout(): ApiResult<Unit> {
        val refresh = session.refreshToken
        // The local session is cleared regardless of the server's answer: the
        // user asked to sign out, so the device must forget the tokens either way.
        val result = call { api.logout(LogoutRequest(refresh, session.deviceToken)) }
        session.clearSession()
        return if (result is ApiResult.Success) result else ApiResult.Success(Unit)
    }

    suspend fun refreshProfile(): ApiResult<UserDto> {
        val result = call { api.me() }
        return when (result) {
            is ApiResult.Success -> {
                val user = result.data.user
                if (user != null) {
                    session.user = user
                    ApiResult.Success(user)
                } else {
                    ApiResult.Failure("The profile could not be loaded.")
                }
            }
            is ApiResult.Failure -> result
            is ApiResult.NetworkError -> result
            ApiResult.Unauthorised -> ApiResult.Unauthorised
        }
    }

    suspend fun forgotPassword(employeeCode: String): ApiResult<OtpPayload> =
        call { api.forgotPassword(ForgotPasswordRequest(employeeCode.trim())) }

    suspend fun resetPassword(employeeCode: String, otp: String, password: String): ApiResult<Unit> =
        callUnit { api.resetPassword(ResetPasswordRequest(employeeCode.trim(), otp.trim(), password)) }

    suspend fun changePassword(currentPassword: String, newPassword: String): ApiResult<UserDto?> {
        val result = call { api.changePassword(ChangePasswordRequest(currentPassword, newPassword)) }

        if (result is ApiResult.Success) {
            result.data.user?.let { session.user = it }
            return ApiResult.Success(result.data.user, result.message)
        }

        return when (result) {
            is ApiResult.Failure -> result
            is ApiResult.NetworkError -> result
            ApiResult.Unauthorised -> ApiResult.Unauthorised
            else -> ApiResult.Failure("The password could not be changed.")
        }
    }

    suspend fun registerDeviceToken(token: String): ApiResult<Unit> {
        session.deviceToken = token
        return callUnit {
            api.registerDeviceToken(
                DeviceTokenRequest(token, com.lrms.recovery.BuildConfig.VERSION_NAME),
            )
        }
    }

    // =======================================================================
    // Meta & dashboard
    // =======================================================================

    suspend fun meta(): ApiResult<MetaPayload> = call { api.meta() }

    suspend fun agentDashboard(): ApiResult<AgentDashboardPayload> = call { api.agentDashboard() }

    // =======================================================================
    // Leads
    // =======================================================================

    suspend fun leads(
        status: String? = null,
        page: Int = 1,
        perPage: Int = 25,
    ): ApiResult<Page<LeadDto>> {
        val filters = buildMap {
            put("page", page.toString())
            put("per_page", perPage.toString())
            if (!status.isNullOrBlank()) put("status", status)
        }

        return paged { api.leads(filters) }
    }

    suspend fun searchLeads(
        query: String,
        wholeBranch: Boolean,
        page: Int = 1,
        perPage: Int = 25,
    ): ApiResult<Page<LeadDto>> {
        val filters = buildMap {
            put("q", query.trim())
            put("page", page.toString())
            put("per_page", perPage.toString())
            if (wholeBranch) put("scope", "branch")
        }

        return paged { api.searchLeads(filters) }
    }

    suspend fun customerProfile(id: Int): ApiResult<CustomerProfilePayload> =
        call { api.customerProfile(id) }

    suspend fun customerHistory(id: Int): ApiResult<HistoryPayload> =
        call { api.customerHistory(id) }

    // =======================================================================
    // Visits
    // =======================================================================

    suspend fun visitsForLead(loanAccountId: Int): ApiResult<List<VisitSummaryDto>> =
        call { api.visitsForLead(loanAccountId) }

    suspend fun visitDetail(id: Int): ApiResult<VisitDetailPayload> = call { api.visitDetail(id) }

    suspend fun visitFormOptions(): ApiResult<FormOptionsPayload> = call { api.visitFormOptions() }

    /**
     * Submits a visit report with its photos and signatures as one multipart
     * request, so a report and its evidence cannot be separated by a dropped
     * connection. The form's client UUID makes a retry idempotent server-side.
     */
    suspend fun submitVisit(form: VisitFormData): ApiResult<VisitSubmitPayload> {
        val fields = form.toFieldMap().map { (key, value) ->
            MultipartBody.Part.createFormData(key, value)
        }

        val files = mutableListOf<MultipartBody.Part>()

        form.photoFiles().forEach { (field, file) ->
            if (file.exists()) {
                files += filePart(field, file, "image/jpeg")
            }
        }

        form.signatureFiles().forEach { (field, file) ->
            if (file.exists()) {
                files += filePart(field, file, "image/png")
            }
        }

        form.otherDocuments.forEach { file ->
            if (file.exists()) {
                files += filePart("other_documents[]", file, guessMime(file))
            }
        }

        return call { api.submitVisit(fields, files) }
    }

    private fun filePart(field: String, file: File, mime: String): MultipartBody.Part =
        MultipartBody.Part.createFormData(
            field,
            file.name,
            file.asRequestBody(mime.toMediaTypeOrNull()),
        )

    private fun guessMime(file: File): String = when (file.extension.lowercase()) {
        "png" -> "image/png"
        "webp" -> "image/webp"
        "pdf" -> "application/pdf"
        else -> "image/jpeg"
    }

    // =======================================================================
    // Promises
    // =======================================================================

    suspend fun promises(status: String? = null, page: Int = 1): ApiResult<Page<PromiseDto>> {
        val filters = buildMap {
            put("page", page.toString())
            if (!status.isNullOrBlank()) put("status", status)
        }
        return paged { api.promises(filters) }
    }

    // =======================================================================
    // Notifications
    // =======================================================================

    suspend fun notifications(unreadOnly: Boolean, page: Int = 1): ApiResult<Page<NotificationDto>> {
        val filters = buildMap {
            put("page", page.toString())
            if (unreadOnly) put("unread", "1")
        }
        return paged { api.notifications(filters) }
    }

    suspend fun unreadCount(): ApiResult<Int> =
        call { api.unreadCount() }.map { it.unreadCount }

    suspend fun markNotificationRead(id: Int): ApiResult<UnreadPayload> =
        call { api.markNotificationRead(id) }

    suspend fun markAllNotificationsRead(): ApiResult<UnreadPayload> =
        call { api.markAllNotificationsRead() }

    // =======================================================================
    // Request plumbing
    // =======================================================================

    /**
     * Runs a call and unwraps the envelope, translating transport and HTTP
     * failures into [ApiResult] variants.
     */
    private suspend fun <T> call(block: suspend () -> Response<ApiEnvelope<T>>): ApiResult<T> =
        withContext(Dispatchers.IO) {
            try {
                val response = block()
                val body = response.body()

                if (response.isSuccessful && body != null && body.success) {
                    val data = body.data
                    if (data != null) {
                        ApiResult.Success(data, body.message)
                    } else {
                        // 2xx with success=true and no payload: treat Unit-shaped
                        // endpoints as success rather than inventing an error.
                        @Suppress("UNCHECKED_CAST")
                        ApiResult.Success(Unit as T, body.message)
                    }
                } else {
                    toFailure(response, body?.message)
                }
            } catch (error: Throwable) {
                toNetworkError(error)
            }
        }

    /** For endpoints whose payload is irrelevant. */
    private suspend fun callUnit(block: suspend () -> Response<ApiEnvelope<Unit>>): ApiResult<Unit> =
        withContext(Dispatchers.IO) {
            try {
                val response = block()
                val body = response.body()

                if (response.isSuccessful && body != null && body.success) {
                    ApiResult.Success(Unit, body.message)
                } else {
                    toFailure(response, body?.message)
                }
            } catch (error: Throwable) {
                toNetworkError(error)
            }
        }

    /** Keeps the pagination meta alongside the list. */
    private suspend fun <T> paged(
        block: suspend () -> Response<ApiEnvelope<List<T>>>,
    ): ApiResult<Page<T>> = withContext(Dispatchers.IO) {
        try {
            val response = block()
            val body = response.body()

            if (response.isSuccessful && body != null && body.success) {
                ApiResult.Success(Page(body.data ?: emptyList(), body.meta), body.message)
            } else {
                toFailure(response, body?.message)
            }
        } catch (error: Throwable) {
            toNetworkError(error)
        }
    }

    /**
     * Builds a failure, pulling out field errors from a 422 so forms can show
     * messages inline instead of one generic toast.
     */
    private fun <T> toFailure(response: Response<*>, envelopeMessage: String?): ApiResult<T> {
        if (response.code() == 401) {
            session.clearSession()
            return ApiResult.Unauthorised
        }

        var message = envelopeMessage?.takeIf { it.isNotBlank() }
        var fieldErrors: Map<String, List<String>> = emptyMap()

        val errorBody = runCatching { response.errorBody()?.string() }.getOrNull()
        if (!errorBody.isNullOrBlank()) {
            runCatching {
                val envelope = gson.fromJson(errorBody, ApiEnvelope::class.java)
                if (envelope?.message?.isNotBlank() == true) {
                    message = envelope.message
                }

                val validation = gson.fromJson(errorBody, ValidationEnvelope::class.java)
                validation?.data?.errors?.let { fieldErrors = it }
            }

            // A server that answers the API with a web page is misconfigured, not
            // merely failing. That happened for real: a setup screen was served
            // to the app, which could only report "something went wrong" - so the
            // phone took the blame for a server problem. Say what it was.
            if (message == null && looksLikeHtml(errorBody)) {
                message = "${serverHost()} returned a web page instead of data " +
                    "(HTTP ${response.code()}). The server is not set up correctly - " +
                    "please tell your administrator."
            }
        }

        return ApiResult.Failure(
            message = message ?: defaultMessageFor(response.code()),
            httpCode = response.code(),
            fieldErrors = fieldErrors,
        )
    }

    private fun looksLikeHtml(body: String): Boolean {
        val head = body.trimStart().take(200).lowercase()
        return head.startsWith("<!doctype html") || head.startsWith("<html") ||
            head.startsWith("<br") || head.contains("<title")
    }

    private fun defaultMessageFor(code: Int): String = when (code) {
        403 -> "You do not have permission to do that."
        404 -> "That record could not be found."
        409 -> "That action conflicts with the current state."
        419 -> "Your session expired. Please sign in again."
        422 -> "Please check the highlighted fields."
        429 -> "Too many attempts. Please wait and try again."
        in 500..599 -> "The server reported an error. Please try again."
        else -> "Something went wrong. Please try again."
    }

    /**
     * Turns a transport failure into something an agent can act on.
     *
     * "Check your internet connection" was shown for every one of these, which
     * was actively misleading: the most common cause in the field was a server
     * that could not be resolved or was misconfigured, on a phone whose network
     * was perfectly fine. An agent cannot fix a server, but they can stop
     * hunting for signal and call whoever can - so the message has to say which
     * of the two it is, and name the host it actually tried.
     */
    private fun <T> toNetworkError(error: Throwable): ApiResult<T> = when (error) {
        is UnknownHostException -> ApiResult.NetworkError(
            if (hasNetwork()) {
                "Could not find the server ${serverHost()}. The address may be wrong or the " +
                    "server may be offline. This is not a problem with your phone - please " +
                    "tell your administrator."
            } else {
                "No internet connection. Check your mobile data or Wi-Fi and try again."
            },
        )

        is SSLException -> ApiResult.NetworkError(
            "The secure connection to ${serverHost()} could not be established. The server's " +
                "certificate may have expired. Please tell your administrator.",
        )

        is ConnectException -> ApiResult.NetworkError(
            "${serverHost()} refused the connection. The server may be down or restarting. " +
                "Please try again shortly.",
        )

        is SocketTimeoutException -> ApiResult.NetworkError(
            if (hasNetwork()) {
                "${serverHost()} took too long to respond. Please try again."
            } else {
                "The connection is too slow to reach the server. Move to a better signal and " +
                    "try again."
            },
        )

        is IOException -> ApiResult.NetworkError(
            "Connection lost. Check your network and try again.",
        )

        else -> ApiResult.Failure(error.message ?: "Something went wrong. Please try again.")
    }

    /** Host of the configured server, for messages. Falls back to "the server". */
    private fun serverHost(): String =
        runCatching { URI(session.baseUrl).host }.getOrNull()?.takeIf { it.isNotBlank() }
            ?: "the server"

    /**
     * Whether the device believes it has usable connectivity.
     *
     * Only used to choose the wording of a failure, never to skip a request:
     * captive portals and metered-but-blocked networks report themselves as
     * connected, so treating this as authoritative would strand the agent.
     */
    private fun hasNetwork(): Boolean = runCatching {
        val manager = appContext.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val capabilities = manager.getNetworkCapabilities(manager.activeNetwork)
            capabilities?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) == true
        } else {
            @Suppress("DEPRECATION")
            manager.activeNetworkInfo?.isConnected == true
        }
    }.getOrDefault(true)

    /** Shape used only to parse the field errors out of a 422 body. */
    private data class ValidationEnvelope(val data: ValidationPayload?)
}
