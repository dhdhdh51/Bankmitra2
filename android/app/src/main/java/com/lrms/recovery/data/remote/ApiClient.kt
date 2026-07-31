package com.lrms.recovery.data.remote

import com.lrms.recovery.BuildConfig
import com.lrms.recovery.data.local.SessionStore
import okhttp3.Authenticator
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.Route
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Builds the Retrofit stack.
 *
 * Two pieces of behaviour matter here:
 *
 *  1. [AuthInterceptor] attaches the Bearer token to every request except the
 *     public auth endpoints.
 *  2. [TokenAuthenticator] handles a 401 by exchanging the refresh token for a
 *     new access token and replaying the request once. This is what lets an agent
 *     stay signed in for weeks without retyping a password.
 *
 * The client is rebuilt whenever the configured base URL changes, so switching
 * servers does not need an app restart.
 */
object ApiClient {

    private const val TIMEOUT_SECONDS = 45L
    private const val UPLOAD_TIMEOUT_SECONDS = 120L

    @Volatile
    private var retrofit: Retrofit? = null

    @Volatile
    private var cachedBaseUrl: String? = null

    fun service(session: SessionStore): ApiService = instance(session).create(ApiService::class.java)

    /** Rebuilds on the next call, e.g. after the server address is changed. */
    fun reset() {
        synchronized(this) {
            retrofit = null
            cachedBaseUrl = null
        }
    }

    private fun instance(session: SessionStore): Retrofit {
        val baseUrl = session.baseUrl

        val existing = retrofit
        if (existing != null && cachedBaseUrl == baseUrl) {
            return existing
        }

        return synchronized(this) {
            val current = retrofit
            if (current != null && cachedBaseUrl == baseUrl) {
                current
            } else {
                val built = build(session, baseUrl)
                retrofit = built
                cachedBaseUrl = baseUrl
                built
            }
        }
    }

    private fun build(session: SessionStore, baseUrl: String): Retrofit {
        val clientBuilder = OkHttpClient.Builder()
            .connectTimeout(TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .readTimeout(UPLOAD_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .writeTimeout(UPLOAD_TIMEOUT_SECONDS, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .addInterceptor(AuthInterceptor(session))
            .authenticator(TokenAuthenticator(session, baseUrl))

        if (BuildConfig.DEBUG) {
            // Headers only: bodies would print borrower PII into logcat.
            clientBuilder.addInterceptor(
                HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.HEADERS },
            )
        }

        return Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(clientBuilder.build())
            .addConverterFactory(GsonConverterFactory.create())
            .build()
    }

    /** Endpoints that must not carry (or need) an Authorization header. */
    private fun isPublicPath(path: String): Boolean = listOf(
        "auth/login",
        "auth/refresh",
        "auth/forgot-password",
        "auth/reset-password",
        "ping",
    ).any { path.endsWith(it) }

    // -----------------------------------------------------------------------

    private class AuthInterceptor(private val session: SessionStore) : Interceptor {
        override fun intercept(chain: Interceptor.Chain): Response {
            val original = chain.request()

            if (isPublicPath(original.url.encodedPath)) {
                return chain.proceed(original)
            }

            val token = session.accessToken
            val request = if (token.isNullOrBlank()) {
                original
            } else {
                original.newBuilder()
                    .header("Authorization", "Bearer $token")
                    .header("Accept", "application/json")
                    .build()
            }

            return chain.proceed(request)
        }
    }

    /**
     * Refreshes the access token on a 401 and replays the request once.
     *
     * OkHttp calls this off the main thread and serialises retries per
     * connection, but two parallel requests can still both see a 401, so the
     * refresh itself is synchronised and re-checks whether another thread has
     * already replaced the token.
     */
    private class TokenAuthenticator(
        private val session: SessionStore,
        private val baseUrl: String,
    ) : Authenticator {

        override fun authenticate(route: Route?, response: Response): Request? {
            // Give up rather than loop if a retry also came back 401.
            if (responseCount(response) >= 2) {
                session.clearSession()
                return null
            }

            val failedToken = response.request.header("Authorization")
                ?.removePrefix("Bearer ")
                ?.trim()

            synchronized(this) {
                val currentToken = session.accessToken

                // Another thread already refreshed: replay with the new token.
                if (!currentToken.isNullOrBlank() && currentToken != failedToken) {
                    return response.request.newBuilder()
                        .header("Authorization", "Bearer $currentToken")
                        .build()
                }

                val refreshToken = session.refreshToken
                if (refreshToken.isNullOrBlank()) {
                    session.clearSession()
                    return null
                }

                val refreshed = performRefresh(refreshToken)
                if (refreshed == null) {
                    // The refresh token is dead: the user must sign in again.
                    session.clearSession()
                    return null
                }

                session.saveTokens(refreshed.accessToken, refreshed.refreshToken, refreshed.expiresIn)
                refreshed.user?.let { session.user = it }

                return response.request.newBuilder()
                    .header("Authorization", "Bearer ${refreshed.accessToken}")
                    .build()
            }
        }

        /**
         * Performs the refresh with a bare client. A separate client is used so
         * this call cannot recurse back into this authenticator.
         */
        private fun performRefresh(refreshToken: String): AuthPayload? = try {
            val bareClient = OkHttpClient.Builder()
                .connectTimeout(TIMEOUT_SECONDS, TimeUnit.SECONDS)
                .readTimeout(TIMEOUT_SECONDS, TimeUnit.SECONDS)
                .build()

            val service = Retrofit.Builder()
                .baseUrl(baseUrl)
                .client(bareClient)
                .addConverterFactory(GsonConverterFactory.create())
                .build()
                .create(ApiService::class.java)

            val result = runCatching {
                kotlinx.coroutines.runBlocking { service.refresh(RefreshRequest(refreshToken)) }
            }.getOrNull()

            val body = result?.body()
            if (result?.isSuccessful == true && body?.success == true) body.data else null
        } catch (error: Exception) {
            null
        }

        private fun responseCount(response: Response): Int {
            var count = 1
            var prior = response.priorResponse
            while (prior != null) {
                count++
                prior = prior.priorResponse
            }
            return count
        }
    }
}
