package com.lrms.recovery.ui.notifications

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.lrms.recovery.R
import com.lrms.recovery.data.remote.NotificationDto
import com.lrms.recovery.databinding.ItemNotificationBinding
import com.lrms.recovery.util.Formatters

class NotificationAdapter(
    private val onClick: (NotificationDto) -> Unit,
) : ListAdapter<NotificationDto, NotificationAdapter.ViewHolder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemNotificationBinding.inflate(
            LayoutInflater.from(parent.context), parent, false,
        )
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    inner class ViewHolder(
        private val binding: ItemNotificationBinding,
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(notification: NotificationDto) {
            val context = binding.root.context

            binding.textTitle.text = notification.title
            binding.textBody.text = notification.body.orEmpty()
            binding.textBody.visibility =
                if (notification.body.isNullOrBlank()) View.GONE else View.VISIBLE

            binding.textTime.text = Formatters.timeAgo(notification.createdAt)

            binding.viewUnreadDot.visibility =
                if (notification.isRead) View.INVISIBLE else View.VISIBLE

            // Unread rows get a tinted background, as in the admin panel.
            binding.root.setCardBackgroundColor(
                ContextCompat.getColor(
                    context,
                    if (notification.isRead) R.color.transparent_surface else R.color.lrms_primary_light,
                ),
            )

            binding.imageIcon.setImageResource(iconFor(notification.type))

            binding.textAccount.text = notification.loanAccountNumber.orEmpty()
            binding.textAccount.visibility =
                if (notification.loanAccountNumber.isNullOrBlank()) View.GONE else View.VISIBLE

            binding.root.setOnClickListener { onClick(notification) }
        }

        private fun iconFor(type: String): Int = when (type) {
            "new_lead_assigned" -> R.drawable.ic_person
            "followup_reminder" -> R.drawable.ic_clock
            "promise_reminder" -> R.drawable.ic_handshake
            else -> R.drawable.ic_bell
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<NotificationDto>() {
            override fun areItemsTheSame(oldItem: NotificationDto, newItem: NotificationDto) =
                oldItem.id == newItem.id

            override fun areContentsTheSame(oldItem: NotificationDto, newItem: NotificationDto) =
                oldItem == newItem
        }
    }
}
