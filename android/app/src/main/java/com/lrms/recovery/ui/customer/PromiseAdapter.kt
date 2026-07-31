package com.lrms.recovery.ui.customer

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.lrms.recovery.R
import com.lrms.recovery.data.remote.PromiseDto
import com.lrms.recovery.databinding.ItemPromiseBinding
import com.lrms.recovery.util.Formatters

/**
 * Promise history.
 *
 * Read-only in the app: an agent records a promise during a visit, but deciding
 * whether it was kept or broken is a branch decision made in the admin panel.
 */
class PromiseAdapter : ListAdapter<PromiseDto, PromiseAdapter.ViewHolder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemPromiseBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    inner class ViewHolder(
        private val binding: ItemPromiseBinding,
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(promise: PromiseDto) {
            val context = binding.root.context

            binding.textAmount.text = Formatters.rupees(promise.promiseAmount)
            binding.textDate.text = Formatters.date(promise.promiseDate)
            binding.textAgent.text = promise.agentName

            binding.chipStatus.text = Formatters.promiseStatusLabel(promise.status)

            val (background, foreground) = when (promise.status) {
                "kept" -> R.color.lrms_success_light to R.color.lrms_success
                "broken" -> R.color.lrms_danger_light to R.color.lrms_danger
                "cancelled" -> R.color.lrms_surface_variant to R.color.lrms_muted
                else -> R.color.lrms_warning_light to R.color.lrms_warning
            }
            binding.chipStatus.chipBackgroundColor =
                ContextCompat.getColorStateList(context, background)
            binding.chipStatus.setTextColor(ContextCompat.getColor(context, foreground))

            // Only a still-pending promise can be overdue.
            val overdue = promise.status == "pending" && promise.daysOverdue > 0
            binding.textOverdue.visibility = if (overdue) View.VISIBLE else View.GONE
            if (overdue) {
                binding.textOverdue.text = context.resources.getQuantityString(
                    R.plurals.day_count, promise.daysOverdue, promise.daysOverdue,
                ) + " late"
            }

            binding.textNotes.text = promise.notes.orEmpty()
            binding.textNotes.visibility =
                if (promise.notes.isNullOrBlank()) View.GONE else View.VISIBLE
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<PromiseDto>() {
            override fun areItemsTheSame(oldItem: PromiseDto, newItem: PromiseDto) =
                oldItem.id == newItem.id

            override fun areContentsTheSame(oldItem: PromiseDto, newItem: PromiseDto) =
                oldItem == newItem
        }
    }
}
