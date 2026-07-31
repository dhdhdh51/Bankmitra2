package com.lrms.recovery.ui.customer

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.lrms.recovery.R
import com.lrms.recovery.data.remote.TimelineEventDto
import com.lrms.recovery.databinding.ItemTimelineBinding
import com.lrms.recovery.util.Formatters

/**
 * The append-only lead timeline.
 *
 * Rows are read-only by design: an event is a historical fact, so there is no
 * edit affordance anywhere. Tapping an event that came from a visit opens that
 * report.
 */
class TimelineAdapter(
    private val onClick: (TimelineEventDto) -> Unit,
) : ListAdapter<TimelineEventDto, TimelineAdapter.ViewHolder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemTimelineBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(getItem(position), isLast = position == itemCount - 1)
    }

    inner class ViewHolder(
        private val binding: ItemTimelineBinding,
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(event: TimelineEventDto, isLast: Boolean) {
            val context = binding.root.context

            binding.textTitle.text = event.title
            binding.textTime.text = Formatters.dateTime(event.eventAt)

            binding.textDescription.text = event.description.orEmpty()
            binding.textDescription.visibility =
                if (event.description.isNullOrBlank()) View.GONE else View.VISIBLE

            binding.textActor.text = event.actorName.orEmpty()
            binding.textActor.visibility =
                if (event.actorName.isNullOrBlank()) View.GONE else View.VISIBLE

            // Attachment counts, so the agent knows evidence exists without opening.
            val meta = buildList {
                if (event.photoCount > 0) add("${event.photoCount} photo")
                if (event.signatureCount > 0) add("${event.signatureCount} signature")
                if (event.promiseAmount != null && event.promiseDate != null) {
                    add(
                        Formatters.rupees(event.promiseAmount, decimals = false) +
                            " by " + Formatters.date(event.promiseDate, short = true),
                    )
                }
            }
            binding.textMeta.text = meta.joinToString(" · ")
            binding.textMeta.visibility = if (meta.isEmpty()) View.GONE else View.VISIBLE

            // Tone matches the admin panel timeline colours.
            val tone = when (event.tone) {
                "success" -> R.color.lrms_success
                "warning" -> R.color.lrms_warning
                "danger" -> R.color.lrms_danger
                "blue" -> R.color.lrms_primary
                else -> R.color.lrms_slate
            }
            binding.viewDot.setBackgroundResource(R.drawable.bg_timeline_dot)
            binding.viewDot.backgroundTintList = ContextCompat.getColorStateList(context, tone)

            // The connecting line stops at the last row.
            binding.viewLine.visibility = if (isLast) View.INVISIBLE else View.VISIBLE

            if (event.visitReportId != null) {
                binding.root.setOnClickListener { onClick(event) }
                binding.imageChevron.visibility = View.VISIBLE
            } else {
                binding.root.setOnClickListener(null)
                binding.root.isClickable = false
                binding.imageChevron.visibility = View.GONE
            }
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<TimelineEventDto>() {
            override fun areItemsTheSame(oldItem: TimelineEventDto, newItem: TimelineEventDto) =
                oldItem.id == newItem.id

            override fun areContentsTheSame(oldItem: TimelineEventDto, newItem: TimelineEventDto) =
                oldItem == newItem
        }
    }
}
