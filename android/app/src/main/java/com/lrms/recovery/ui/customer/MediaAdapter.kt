package com.lrms.recovery.ui.customer

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.bumptech.glide.Glide
import com.bumptech.glide.load.model.GlideUrl
import com.bumptech.glide.load.model.LazyHeaders
import com.lrms.recovery.R
import com.lrms.recovery.data.local.SessionStore
import com.lrms.recovery.data.remote.MediaDto
import com.lrms.recovery.databinding.ItemMediaBinding

/**
 * Photo thumbnails.
 *
 * Media is served by an authenticated endpoint - the files are not publicly
 * readable - so each Glide request carries the Bearer token. Without that header
 * every thumbnail would come back 401.
 */
class MediaAdapter(
    private val session: SessionStore,
) : ListAdapter<MediaDto, MediaAdapter.ViewHolder>(DIFF) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val binding = ItemMediaBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ViewHolder(binding)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) = holder.bind(getItem(position))

    inner class ViewHolder(
        private val binding: ItemMediaBinding,
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(media: MediaDto) {
            binding.textLabel.text = media.type.replace('_', ' ')

            val url = absoluteUrl(media.url)
            val token = session.accessToken

            if (token.isNullOrBlank()) {
                binding.imageThumb.setImageResource(R.drawable.ic_image)
                return
            }

            val glideUrl = GlideUrl(
                url,
                LazyHeaders.Builder()
                    .addHeader("Authorization", "Bearer $token")
                    .build(),
            )

            Glide.with(binding.imageThumb)
                .load(glideUrl)
                .placeholder(R.drawable.ic_image)
                .error(R.drawable.ic_image)
                .centerCrop()
                .into(binding.imageThumb)
        }

        /**
         * The API returns media paths relative to the API root, so they are
         * resolved against the configured base URL rather than assumed absolute.
         */
        private fun absoluteUrl(path: String): String {
            if (path.startsWith("http://") || path.startsWith("https://")) {
                return path
            }

            val base = session.baseUrl.trimEnd('/')
            // baseUrl already ends in /api/v1, and the path repeats that prefix.
            val cleaned = path.removePrefix("/api/v1").removePrefix("/")
            return "$base/$cleaned"
        }
    }

    private companion object {
        val DIFF = object : DiffUtil.ItemCallback<MediaDto>() {
            override fun areItemsTheSame(oldItem: MediaDto, newItem: MediaDto) =
                oldItem.id == newItem.id && oldItem.kind == newItem.kind

            override fun areContentsTheSame(oldItem: MediaDto, newItem: MediaDto) =
                oldItem == newItem
        }
    }
}
