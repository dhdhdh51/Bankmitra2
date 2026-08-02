package com.lrms.recovery.location

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.Bundle
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import android.app.Service
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import com.lrms.recovery.LrmsApp
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.ui.main.MainActivity
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.util.concurrent.CopyOnWriteArrayList

/**
 * Records the agent's position for the duration of a duty session.
 *
 * A session begins when a signed-in agent opens the app and the location permission is
 * held. There is no start button, deliberately: what the bank measures is not the
 * measured person's setting, in the same way the daily reminder time is not.
 *
 * A FOREGROUND service, not a background one, and that is the whole design rather
 * than a technical detail: Android forces a permanent notification for as long as
 * this runs, so an agent can always see that recording is on.
 * `ACCESS_BACKGROUND_LOCATION` is deliberately never requested - it would allow
 * collection with nothing on screen to say so, which is a different product. The
 * consequence is honest and intended: close the app and recording stops.
 *
 * Uses the platform [LocationManager] rather than Play Services. The agents this is
 * for carry cheap phones, some without Google Play Services at all, and one more
 * dependency for a fix every few minutes is not a trade worth making.
 *
 * Points are queued and posted in batches, because a village with no signal is the
 * normal case and one request per fix would lose most of them. The queue survives
 * a failed upload and is only cleared once the server has acknowledged the points -
 * except for points the server tells us it deliberately dropped, which are cleared
 * too, or the app would retry them forever.
 */
class DutyLocationService : Service() {

    /**
     * Own scope rather than androidx lifecycle-service, which would be another
     * dependency for one property. Cancelled in onDestroy so an upload in flight
     * cannot outlive the session that started it.
     */
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    private val queue = CopyOnWriteArrayList<QueuedPoint>()
    private val uploadLock = Mutex()
    private var locationManager: LocationManager? = null
    private var listener: LocationListener? = null

    data class QueuedPoint(
        val latitude: Double,
        val longitude: Double,
        val accuracyMetres: Int?,
        val loggedAt: String,
    )

    override fun onCreate() {
        super.onCreate()
        createChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }

        // The permission is the gate on this side. The other gate is the server's: it
        // answers 412 until consent is on file, and that is handled where the points are
        // uploaded rather than guessed at here.
        if (!hasLocationPermission()) {
            stopSelf()
            return START_NOT_STICKY
        }

        // A start for a session that is already recording is a no-op, not a second
        // session. The app asks for one on every entry - it has to, because the ongoing
        // notification's Stop action can end a session while the app is in the background
        // - and registering a second set of listeners on the same providers would double
        // every fix and leave one set behind when the first is removed.
        if (active) {
            return START_NOT_STICKY
        }

        startAsForeground()
        beginUpdates()
        active = true

        // START_STICKY would have Android silently restart this after the process died,
        // with no activity on screen and nobody to notice it had come back wrong. The app
        // starts a session every time it is opened, which covers the same ground while
        // recording stays tied to something visible.
        return START_NOT_STICKY
    }

    override fun onBind(intent: Intent): IBinder? = null

    override fun onDestroy() {
        // Cleared first, and unconditionally: every path out of this service ends
        // here, so nothing can leave the flag reading "recording" after it stopped.
        active = false
        listener?.let { locationManager?.removeUpdates(it) }
        listener = null
        // Flush whatever is queued; the session is ending and these points are
        // otherwise lost.
        if (queue.isNotEmpty()) {
            scope.launch { upload() }
        }
        scope.cancel()
        super.onDestroy()
    }

    // -----------------------------------------------------------------------
    // Location
    // -----------------------------------------------------------------------

    private fun beginUpdates() {
        val manager = ContextCompat.getSystemService(this, LocationManager::class.java) ?: run {
            stopSelf()
            return
        }
        locationManager = manager

        val callback = object : LocationListener {
            override fun onLocationChanged(location: Location) = enqueue(location)

            // Required on API 24-28 or the listener is never registered on those
            // versions; harmless no-ops above that.
            @Deprecated("Required for API < 29")
            override fun onStatusChanged(provider: String?, status: Int, extras: Bundle?) = Unit

            override fun onProviderEnabled(provider: String) = Unit
            override fun onProviderDisabled(provider: String) = Unit
        }
        listener = callback

        // GPS where available, network as a fallback: indoors and in a bank branch
        // a GPS fix often never arrives, and a coarse fix is better than none.
        val providers = listOfNotNull(
            LocationManager.GPS_PROVIDER.takeIf { manager.isProviderEnabled(it) },
            LocationManager.NETWORK_PROVIDER.takeIf { manager.isProviderEnabled(it) },
        )

        if (providers.isEmpty()) {
            stopSelf()
            return
        }

        try {
            providers.forEach { provider ->
                manager.requestLocationUpdates(
                    provider,
                    INTERVAL_MS,
                    MIN_DISTANCE_METRES,
                    callback,
                )
            }
        } catch (_: SecurityException) {
            // Permission revoked between the check and the call.
            stopSelf()
        }
    }

    private fun enqueue(location: Location) {
        // The server drops fixes closer than a minute apart, so there is no point
        // sending them. Filtering here also saves the radio.
        val last = queue.lastOrNull()
        if (last != null && (System.currentTimeMillis() - parseMillis(last.loggedAt)) < INTERVAL_MS) {
            return
        }

        queue.add(
            QueuedPoint(
                latitude = location.latitude,
                longitude = location.longitude,
                accuracyMetres = if (location.hasAccuracy()) location.accuracy.toInt() else null,
                loggedAt = TIMESTAMP_FORMAT.format(java.util.Date()),
            ),
        )

        if (queue.size >= BATCH_SIZE) {
            scope.launch { upload() }
        }
    }

    private suspend fun upload() = uploadLock.withLock {
        val batch = queue.take(MAX_BATCH).toList()
        if (batch.isEmpty()) {
            return@withLock
        }

        val repository = (application as LrmsApp).repository
        when (val result = repository.uploadLocations(batch)) {
            is ApiResult.Success -> {
                // Stored AND dropped both count as delivered. A dropped point is one
                // the server accepted and deliberately discarded as too close to the
                // previous fix; retrying it forever would be a loop.
                queue.removeAll(batch.toSet())
            }

            // Consent withdrawn or never given: stop, do not keep collecting into a
            // queue that will never be accepted.
            is ApiResult.Failure -> if (result.httpCode == 412) {
                queue.clear()
                stopSelf()
            }

            // Offline: keep the queue and try again on the next fix. Trim from the
            // front if the day has been entirely without signal, so memory is bounded.
            else -> if (queue.size > MAX_QUEUE) {
                repeat(queue.size - MAX_QUEUE) { queue.removeAt(0) }
            }
        }
    }

    private fun parseMillis(stamp: String): Long =
        runCatching { TIMESTAMP_FORMAT.parse(stamp)?.time ?: 0L }.getOrDefault(0L)

    // -----------------------------------------------------------------------
    // Foreground notification
    // -----------------------------------------------------------------------

    private fun startAsForeground() {
        val open = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )

        val stop = PendingIntent.getService(
            this,
            1,
            Intent(this, DutyLocationService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )

        val notification: Notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.location_on_duty))
            .setContentText(getString(R.string.location_notice_short))
            .setSmallIcon(R.drawable.ic_launcher_monochrome)
            .setContentIntent(open)
            // Stop ends the session that is running. It is not a setting: the next time
            // the app is opened, recording resumes. It stays because a foreground service
            // an agent cannot end at all is the kind of thing that gets an app's
            // notifications switched off wholesale, which would take the reminder with it.
            .addAction(0, getString(R.string.location_stop_duty), stop)
            .setOngoing(true)
            .setSilent(true)
            .build()

        ServiceCompat.startForeground(
            this,
            NOTIFICATION_ID,
            notification,
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            } else {
                0
            },
        )
    }

    private fun createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return
        }
        val channel = NotificationChannel(
            CHANNEL_ID,
            getString(R.string.location_channel_name),
            NotificationManager.IMPORTANCE_LOW,
        ).apply {
            description = getString(R.string.location_channel_description)
            setShowBadge(false)
        }
        ContextCompat.getSystemService(this, NotificationManager::class.java)
            ?.createNotificationChannel(channel)
    }

    private fun hasLocationPermission(): Boolean =
        ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED

    companion object {
        const val ACTION_STOP = "com.lrms.recovery.location.STOP"

        /**
         * Whether a duty session is recording right now.
         *
         * A plain flag is enough and is not a shortcut: the service lives in this
         * process, so if the process is gone the service is gone and a fresh flag
         * reads false - which is the truth. It exists so no screen can offer "Start
         * duty session" while the ongoing notification says recording is already on.
         * An agent who sees the app and the notification disagree has no reason to
         * believe either.
         */
        @Volatile
        private var active = false

        val isRunning: Boolean get() = active

        private const val CHANNEL_ID = "duty_location"
        private const val NOTIFICATION_ID = 4201

        /** Four minutes: enough to show a route, cheap enough on a small battery. */
        private const val INTERVAL_MS = 4L * 60L * 1000L
        private const val MIN_DISTANCE_METRES = 50f

        private const val BATCH_SIZE = 5
        private const val MAX_BATCH = 200
        private const val MAX_QUEUE = 500

        private val TIMESTAMP_FORMAT =
            java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US)

        fun start(context: Context) {
            ContextCompat.startForegroundService(
                context,
                Intent(context, DutyLocationService::class.java),
            )
        }

        fun stop(context: Context) {
            context.startService(
                Intent(context, DutyLocationService::class.java).setAction(ACTION_STOP),
            )
        }
    }
}
