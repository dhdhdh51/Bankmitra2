package com.lrms.recovery.ui.leads

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.lrms.recovery.R
import com.lrms.recovery.data.remote.LeadDto
import com.lrms.recovery.databinding.ItemLeadBinding
import com.lrms.recovery.util.Formatters

/**
 * Lead list row.
 *
 * Uses ListAdapter so refreshing after a submitted visit animates the changed row
 * rather than flashing the whole list.
 */
class LeadAdapter(
    private val onClick: (LeadDto) -> Unit,
    private val onCall: (LeadDto) -> Unit,
) : ListAdapter<LeadDto, LeadAdapter.LeadViewHolder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): LeadViewHolder {
        val binding = ItemLeadBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return LeadViewHolder(binding)
    }

    override fun onBindViewHolder(holder: LeadViewHolder, position: Int) {
        holder.bind(getItem(position))
    }

    inner class LeadViewHolder(
        private val binding: ItemLeadBinding,
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(lead: LeadDto) {
            val context = binding.root.context

            binding.textAccountNumber.text = lead.loanAccountNumber
            binding.textCustomerName.text = lead.customerName

            binding.textVillage.text = listOfNotNull(
                lead.village?.takeIf { it.isNotBlank() },
                lead.loanType?.takeIf { it.isNotBlank() },
            ).joinToString(" · ").ifBlank { Formatters.DASH }

            binding.textOutstanding.text = Formatters.rupees(lead.outstandingAmount, decimals = false)
            binding.textOverdue.text = Formatters.rupees(lead.overdueAmount, decimals = false)

            // Status pill, coloured the same way as the admin panel badges.
            binding.chipStatus.text = Formatters.statusLabel(lead.currentStatus)
            applyStatusColours(lead.currentStatus)

            binding.textNpa.visibility = if (lead.isNpa) View.VISIBLE else View.GONE

            binding.textVisitInfo.text = when {
                lead.visitCount == 0 -> context.getString(R.string.leads_filter_pending)
                lead.lastVisitAt != null ->
                    "${lead.visitCount} visit${if (lead.visitCount == 1) "" else "s"} · " +
                        Formatters.timeAgo(lead.lastVisitAt)
                else -> "${lead.visitCount} visit${if (lead.visitCount == 1) "" else "s"}"
            }

            // Calling is only offered when a real number came back from the API.
            val callable = !lead.mobile.isNullOrBlank()
            binding.buttonCall.visibility = if (callable) View.VISIBLE else View.GONE
            binding.buttonCall.setOnClickListener { onCall(lead) }

            binding.root.setOnClickListener { onClick(lead) }
        }

        private fun applyStatusColours(status: String) {
            val context = binding.root.context

            val (background, foreground) = when (status) {
                "pending" -> R.color.lrms_warning_light to R.color.lrms_warning
                "visited" -> R.color.lrms_success_light to R.color.lrms_success
                "promise" -> R.color.lrms_primary_light to R.color.lrms_primary_dark
                "legal" -> R.color.lrms_danger_light to R.color.lrms_danger
                else -> R.color.lrms_surface_variant to R.color.lrms_slate
            }

            binding.chipStatus.chipBackgroundColor =
                ContextCompat.getColorStateList(context, background)
            binding.chipStatus.setTextColor(ContextCompat.getColor(context, foreground))
            binding.chipStatus.chipStrokeColor =
                ContextCompat.getColorStateList(context, foreground)
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<LeadDto>() {
            override fun areItemsTheSame(oldItem: LeadDto, newItem: LeadDto): Boolean =
                oldItem.id == newItem.id

            override fun areContentsTheSame(oldItem: LeadDto, newItem: LeadDto): Boolean =
                oldItem == newItem
        }
    }
}
