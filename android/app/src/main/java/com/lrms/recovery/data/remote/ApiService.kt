package com.lrms.recovery.data.remote

import okhttp3.MultipartBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query
import retrofit2.http.QueryMap
import retrofit2.http.Streaming

/**
 * The D2 Recovery Solutions & Services REST API as the app consumes it.
 *
 * Responses are wrapped in [Response] so the repository can read the HTTP status
 * (401 to refresh, 422 for field errors) instead of only the body.
 */
interface ApiService {

    // ---------------- Auth ----------------

    @GET("ping")
    suspend fun ping(): Response<ApiEnvelope<PingPayload>>

    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): Response<ApiEnvelope<AuthPayload>>

    @POST("auth/refresh")
    suspend fun refresh(@Body body: RefreshRequest): Response<ApiEnvelope<AuthPayload>>

    @POST("auth/logout")
    suspend fun logout(@Body body: LogoutRequest): Response<ApiEnvelope<Unit>>

    @GET("auth/me")
    suspend fun me(): Response<ApiEnvelope<MePayload>>

    @POST("auth/forgot-password")
    suspend fun forgotPassword(@Body body: ForgotPasswordRequest): Response<ApiEnvelope<OtpPayload>>

    @POST("auth/reset-password")
    suspend fun resetPassword(@Body body: ResetPasswordRequest): Response<ApiEnvelope<Unit>>

    @POST("auth/change-password")
    suspend fun changePassword(@Body body: ChangePasswordRequest): Response<ApiEnvelope<MePayload>>

    @POST("auth/device-token")
    suspend fun registerDeviceToken(@Body body: DeviceTokenRequest): Response<ApiEnvelope<Unit>>

    // ---------------- Meta & dashboard ----------------

    @GET("meta")
    suspend fun meta(): Response<ApiEnvelope<MetaPayload>>

    @GET("dashboard")
    suspend fun agentDashboard(): Response<ApiEnvelope<AgentDashboardPayload>>

    // ---------------- Leads ----------------

    @GET("leads")
    suspend fun leads(@QueryMap filters: Map<String, String>): Response<ApiEnvelope<List<LeadDto>>>

    @GET("leads/search")
    suspend fun searchLeads(@QueryMap filters: Map<String, String>): Response<ApiEnvelope<List<LeadDto>>>

    // ---------------- Customers ----------------

    @GET("customers/{id}")
    suspend fun customerProfile(@Path("id") id: Int): Response<ApiEnvelope<CustomerProfilePayload>>

    @GET("customers/{id}/history")
    suspend fun customerHistory(@Path("id") id: Int): Response<ApiEnvelope<HistoryPayload>>

    // ---------------- Visits ----------------

    @GET("visits")
    suspend fun visitsForLead(
        @Query("loan_account_id") loanAccountId: Int,
    ): Response<ApiEnvelope<List<VisitSummaryDto>>>

    @GET("visits/form-options")
    suspend fun visitFormOptions(): Response<ApiEnvelope<FormOptionsPayload>>

    @GET("visits/{id}")
    suspend fun visitDetail(@Path("id") id: Int): Response<ApiEnvelope<VisitDetailPayload>>

    /**
     * Submits a field visit report.
     *
     * Multipart, because a report carries several photos and documents alongside its
     * fields. Sent as a single request so a report and its evidence can never be
     * separated by a dropped connection.
     */
    @Multipart
    @POST("visits")
    suspend fun submitVisit(
        @Part fields: List<MultipartBody.Part>,
        @Part files: List<MultipartBody.Part>,
    ): Response<ApiEnvelope<VisitSubmitPayload>>

    // ---------------- Promises ----------------

    @GET("promises")
    suspend fun promises(@QueryMap filters: Map<String, String>): Response<ApiEnvelope<List<PromiseDto>>>

    // ---------------- Notifications ----------------

    @GET("notifications")
    suspend fun notifications(
        @QueryMap filters: Map<String, String>,
    ): Response<ApiEnvelope<List<NotificationDto>>>

    @GET("notifications/unread-count")
    suspend fun unreadCount(): Response<ApiEnvelope<UnreadPayload>>

    @POST("notifications/{id}/read")
    suspend fun markNotificationRead(@Path("id") id: Int): Response<ApiEnvelope<UnreadPayload>>

    @POST("notifications/read-all")
    suspend fun markAllNotificationsRead(): Response<ApiEnvelope<UnreadPayload>>

    // ---------------- Media ----------------

    // ---------------- SSS enrolment, filed by the agent -------------------

    @GET("sss")
    suspend fun sssDay(@Query("date") date: String? = null): Response<ApiEnvelope<SssDayPayload>>

    /**
     * An upsert. A retry on a dropped connection must not double a figure that feeds
     * a ranking, so the server replaces the day rather than adding a second row.
     */
    @POST("sss")
    suspend fun saveSss(@Body body: SssEntryRequest): Response<ApiEnvelope<SssSavePayload>>

    // ---------------- Location notice, consent and points ----------------

    @GET("tracking/notice")
    suspend fun locationNotice(): Response<ApiEnvelope<LocationNoticePayload>>

    @POST("tracking/consent")
    suspend fun acceptLocationNotice(@Body body: LocationConsentRequest): Response<ApiEnvelope<Unit>>

    @POST("tracking/consent/withdraw")
    suspend fun withdrawLocationConsent(): Response<ApiEnvelope<Unit>>

    /** Batched: a village with no signal is the normal case, not the exception. */
    @POST("tracking/location")
    suspend fun uploadLocations(@Body body: LocationBatchRequest): Response<ApiEnvelope<LocationUploadPayload>>

    /** Streamed so a large document is not buffered entirely in memory. */
    @Streaming
    @GET("media")
    suspend fun media(@Query("f") path: String): Response<ResponseBody>

    /**
     * The printable customer data sheet, as a PDF.
     *
     * The server only issues this for a lead assigned to the calling agent, which
     * is stricter than the rest of the lead API: the sheet leaves the device as a
     * file and can be forwarded on from there.
     *
     * @param lang "en" or "hi" - the field labels print in this language; a
     * borrower's own name, address and figures print as recorded either way.
     */
    @Streaming
    @GET("customers/{id}/sheet")
    suspend fun customerSheet(
        @Path("id") id: Long,
        @Query("lang") lang: String,
    ): Response<ResponseBody>
}
