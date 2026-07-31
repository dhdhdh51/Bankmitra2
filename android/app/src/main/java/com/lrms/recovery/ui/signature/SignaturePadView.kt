package com.lrms.recovery.ui.signature

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.util.AttributeSet
import android.view.MotionEvent
import android.view.View
import kotlin.math.abs

/**
 * Signature capture surface.
 *
 * Strokes are kept as a list of point lists rather than drawn straight onto a
 * bitmap, which is what makes undo and redo possible and lets the signature be
 * re-rendered at any output resolution.
 *
 * Smoothing uses quadratic Bezier segments through the midpoints of consecutive
 * samples. Plain lineTo() between raw touch points produces visibly faceted
 * signatures on low-end devices, which look nothing like the person's hand.
 */
class SignaturePadView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0,
) : View(context, attrs, defStyleAttr) {

    /** One continuous pen-down to pen-up movement. */
    private class Stroke {
        val points = mutableListOf<Pair<Float, Float>>()
        val path = Path()
    }

    private val strokes = mutableListOf<Stroke>()

    /** Strokes removed by undo, available to redo until the next new stroke. */
    private val undone = mutableListOf<Stroke>()

    private var activeStroke: Stroke? = null

    private val penPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#1C2128")
        style = Paint.Style.STROKE
        strokeWidth = 6f
        strokeCap = Paint.Cap.ROUND
        strokeJoin = Paint.Join.ROUND
    }

    private val guidePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#CFD4DC")
        style = Paint.Style.STROKE
        strokeWidth = 2f
        pathEffect = android.graphics.DashPathEffect(floatArrayOf(12f, 10f), 0f)
    }

    /** Called whenever the stroke set changes, so buttons can be enabled. */
    var onStateChanged: (() -> Unit)? = null

    /** Draws a dashed baseline for the signer to sign along. */
    var showGuideLine: Boolean = true
        set(value) {
            field = value
            invalidate()
        }

    val isEmpty: Boolean get() = strokes.isEmpty()
    val canUndo: Boolean get() = strokes.isNotEmpty()
    val canRedo: Boolean get() = undone.isNotEmpty()

    init {
        setBackgroundColor(Color.WHITE)
        isSaveEnabled = false
        // Software layer: the incremental Path drawing below is faster and more
        // predictable than repeatedly re-uploading a hardware layer.
        setLayerType(LAYER_TYPE_SOFTWARE, null)
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)

        if (showGuideLine && height > 0) {
            val baseline = height * 0.72f
            val inset = width * 0.06f
            val guide = Path().apply {
                moveTo(inset, baseline)
                lineTo(width - inset, baseline)
            }
            canvas.drawPath(guide, guidePaint)
        }

        strokes.forEach { canvas.drawPath(it.path, penPaint) }
        activeStroke?.let { canvas.drawPath(it.path, penPaint) }
    }

    @SuppressLint("ClickableViewAccessibility")
    override fun onTouchEvent(event: MotionEvent): Boolean {
        val x = event.x
        val y = event.y

        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> {
                // Stop the scrolling parent from stealing the gesture mid-signature.
                parent?.requestDisallowInterceptTouchEvent(true)

                val stroke = Stroke()
                stroke.points.add(x to y)
                stroke.path.moveTo(x, y)
                activeStroke = stroke

                // A new stroke invalidates the redo stack, as in any editor.
                undone.clear()
                invalidate()
                return true
            }

            MotionEvent.ACTION_MOVE -> {
                val stroke = activeStroke ?: return false

                // Coalesced historical samples give a smoother line at high
                // touch rates than only using the current position.
                for (index in 0 until event.historySize) {
                    appendPoint(stroke, event.getHistoricalX(index), event.getHistoricalY(index))
                }
                appendPoint(stroke, x, y)

                invalidate()
                return true
            }

            MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                val stroke = activeStroke ?: return false
                appendPoint(stroke, x, y)

                // A tap with no movement is rendered as a dot, otherwise dotting
                // an "i" would silently disappear.
                if (stroke.points.size == 1) {
                    stroke.path.addCircle(x, y, penPaint.strokeWidth / 2f, Path.Direction.CW)
                }

                strokes.add(stroke)
                activeStroke = null
                parent?.requestDisallowInterceptTouchEvent(false)

                invalidate()
                onStateChanged?.invoke()
                return true
            }
        }

        return super.onTouchEvent(event)
    }

    /**
     * Extends the stroke, smoothing through the midpoint of the last two samples.
     * Sub-pixel movements are dropped to keep the point list small.
     */
    private fun appendPoint(stroke: Stroke, x: Float, y: Float) {
        val last = stroke.points.lastOrNull()

        if (last != null && abs(x - last.first) < MIN_MOVE && abs(y - last.second) < MIN_MOVE) {
            return
        }

        stroke.points.add(x to y)

        val count = stroke.points.size
        if (count < 3) {
            stroke.path.lineTo(x, y)
            return
        }

        val previous = stroke.points[count - 2]
        val beforePrevious = stroke.points[count - 3]

        val startX = (beforePrevious.first + previous.first) / 2f
        val startY = (beforePrevious.second + previous.second) / 2f
        val endX = (previous.first + x) / 2f
        val endY = (previous.second + y) / 2f

        stroke.path.moveTo(startX, startY)
        stroke.path.quadTo(previous.first, previous.second, endX, endY)
    }

    // -----------------------------------------------------------------------
    // Editing
    // -----------------------------------------------------------------------

    fun undo() {
        if (strokes.isEmpty()) return
        undone.add(strokes.removeAt(strokes.lastIndex))
        invalidate()
        onStateChanged?.invoke()
    }

    fun redo() {
        if (undone.isEmpty()) return
        strokes.add(undone.removeAt(undone.lastIndex))
        invalidate()
        onStateChanged?.invoke()
    }

    fun clear() {
        if (strokes.isEmpty() && activeStroke == null) return
        strokes.clear()
        undone.clear()
        activeStroke = null
        invalidate()
        onStateChanged?.invoke()
    }

    // -----------------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------------

    /**
     * Renders the signature to a bitmap, cropped to the ink with a small margin
     * and scaled to a sensible width.
     *
     * Cropping matters: an uncropped landscape capture is mostly empty white, and
     * would render as a tiny squiggle when placed on a report.
     *
     * @return null when nothing has been drawn.
     */
    fun exportBitmap(targetWidth: Int = EXPORT_WIDTH, transparent: Boolean = false): Bitmap? {
        if (strokes.isEmpty() || width == 0 || height == 0) return null

        var minX = Float.MAX_VALUE
        var minY = Float.MAX_VALUE
        var maxX = Float.MIN_VALUE
        var maxY = Float.MIN_VALUE

        strokes.forEach { stroke ->
            stroke.points.forEach { (x, y) ->
                if (x < minX) minX = x
                if (y < minY) minY = y
                if (x > maxX) maxX = x
                if (y > maxY) maxY = y
            }
        }

        val margin = penPaint.strokeWidth * 3f
        minX = (minX - margin).coerceAtLeast(0f)
        minY = (minY - margin).coerceAtLeast(0f)
        maxX = (maxX + margin).coerceAtMost(width.toFloat())
        maxY = (maxY + margin).coerceAtMost(height.toFloat())

        val cropWidth = (maxX - minX).coerceAtLeast(1f)
        val cropHeight = (maxY - minY).coerceAtLeast(1f)

        val scale = (targetWidth / cropWidth).coerceAtMost(MAX_EXPORT_SCALE)
        val outputWidth = (cropWidth * scale).toInt().coerceAtLeast(1)
        val outputHeight = (cropHeight * scale).toInt().coerceAtLeast(1)

        val bitmap = Bitmap.createBitmap(outputWidth, outputHeight, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap)

        if (!transparent) {
            canvas.drawColor(Color.WHITE)
        }

        canvas.scale(scale, scale)
        canvas.translate(-minX, -minY)

        val exportPaint = Paint(penPaint)
        strokes.forEach { canvas.drawPath(it.path, exportPaint) }

        return bitmap
    }

    /** Number of strokes, used by tests and the on-screen counter. */
    fun strokeCount(): Int = strokes.size

    companion object {
        /** Ignore movements below this to avoid recording jitter. */
        private const val MIN_MOVE = 1.5f

        /** Target width of the exported PNG in pixels. */
        private const val EXPORT_WIDTH = 900

        /** Never upscale a small signature into a blurry large image. */
        private const val MAX_EXPORT_SCALE = 3f
    }
}
