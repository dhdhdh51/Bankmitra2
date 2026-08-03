package com.lrms.recovery.ui.customer

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.lrms.recovery.databinding.ItemEditableFieldBinding
import com.lrms.recovery.util.Formatters

/**
 * Renders the profile's "Additional details" list: read-only rows normally, an
 * input widget per row while editing.
 *
 * One adapter for both fixed and custom fields - see [EditableField] for why a
 * single flat shape is what lets that be true - and one input dispatch per
 * field_type, mirroring the same text/number/money/date/select/toggle switch
 * `CustomField::TYPES` defines server-side, so a type this app does not know
 * how to render cannot exist without a matching server-side type existing first.
 *
 * A saved edit is committed one field at a time (onSave is called the moment an
 * input loses focus or a date/toggle choice is made), not batched behind a single
 * screen-wide Save button - the same shape [VisitReportActivity] would use for a
 * single field is not applicable here since this list can be one field or forty,
 * and losing forty unsaved edits to a screen rotation is a worse failure mode
 * than one extra request per correction.
 */
class EditableFieldAdapter(
    private val onSave: (EditableField, String?) -> Unit,
) : ListAdapter<EditableField, EditableFieldAdapter.ViewHolder>(DIFF) {

    /** Toggled for the whole list at once from the section's Edit/Done action. */
    var editing: Boolean = false
        set(value) {
            field = value
            notifyItemRangeChanged(0, itemCount)
        }

    /** Fields currently mid-save, so their row can show that rather than looking idle. */
    private val saving = mutableSetOf<String>()

    fun markSaving(key: String, isSaving: Boolean) {
        if (isSaving) saving.add(key) else saving.remove(key)
        val index = currentList.indexOfFirst { it.key == key }
        if (index >= 0) notifyItemChanged(index)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder =
        ViewHolder(
            ItemEditableFieldBinding.inflate(LayoutInflater.from(parent.context), parent, false),
        )

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        holder.bind(getItem(position), editing, saving)
    }

    inner class ViewHolder(private val binding: ItemEditableFieldBinding) :
        RecyclerView.ViewHolder(binding.root) {

        // Cleared and reattached on every bind - a RecyclerView reuses this holder
        // for a different field entirely, and a stale listener would post the
        // PREVIOUS field's key with the new field's text.
        fun bind(field: EditableField, editing: Boolean, saving: Set<String>) {
            binding.textLabel.text = field.label + if (field.isRequired) " *" else ""
            binding.textOverrideBadge.visibility = if (field.isOverridden) android.view.View.VISIBLE else android.view.View.GONE

            binding.textHint.text = field.hint
            binding.textHint.visibility = if (!field.hint.isNullOrBlank()) android.view.View.VISIBLE else android.view.View.GONE

            binding.editValue.setOnFocusChangeListener(null)
            binding.dropdownValue.onItemClickListener = null
            for (button in listOf(binding.buttonYes, binding.buttonNo, binding.buttonNotStated)) {
                button.setOnClickListener(null)
            }

            if (!editing) {
                showReadMode(field)
                return
            }

            when (field.fieldType) {
                "select" -> showDropdown(field)
                "toggle" -> showToggle(field, threeState = false)
                "flag" -> showToggle(field, threeState = true)
                else -> showTextInput(field)
            }

            binding.root.alpha = if (field.key in saving) 0.6f else 1f
        }

        private fun showReadMode(field: EditableField) {
            binding.textValue.visibility = android.view.View.VISIBLE
            binding.inputLayout.visibility = android.view.View.GONE
            binding.dropdownLayout.visibility = android.view.View.GONE
            binding.toggleGroup.visibility = android.view.View.GONE
            binding.textValue.text = displayValue(field)
            binding.root.alpha = 1f
        }

        private fun displayValue(field: EditableField): String {
            val value = field.value
            if (value.isNullOrBlank()) return binding.root.context.getString(com.lrms.recovery.R.string.not_available)
            return when (field.fieldType) {
                "money" -> Formatters.rupees(value.toDoubleOrNull() ?: 0.0)
                "date" -> Formatters.date(value)
                "toggle" -> if (value == "1") binding.root.context.getString(com.lrms.recovery.R.string.yes) else binding.root.context.getString(com.lrms.recovery.R.string.no)
                "flag" -> when (value) {
                    "1" -> binding.root.context.getString(com.lrms.recovery.R.string.yes)
                    "0" -> binding.root.context.getString(com.lrms.recovery.R.string.no)
                    else -> binding.root.context.getString(com.lrms.recovery.R.string.not_stated)
                }
                else -> value
            }
        }

        private fun showTextInput(field: EditableField) {
            binding.textValue.visibility = android.view.View.GONE
            binding.inputLayout.visibility = android.view.View.VISIBLE
            binding.dropdownLayout.visibility = android.view.View.GONE
            binding.toggleGroup.visibility = android.view.View.GONE

            binding.editValue.inputType = when (field.fieldType) {
                "number" -> android.text.InputType.TYPE_CLASS_NUMBER
                "money" -> android.text.InputType.TYPE_CLASS_NUMBER or android.text.InputType.TYPE_NUMBER_FLAG_DECIMAL or android.text.InputType.TYPE_NUMBER_FLAG_SIGNED
                "textarea" -> android.text.InputType.TYPE_CLASS_TEXT or android.text.InputType.TYPE_TEXT_FLAG_MULTI_LINE
                "date" -> android.text.InputType.TYPE_NULL
                else -> android.text.InputType.TYPE_CLASS_TEXT
            }
            binding.editValue.setText(field.value.orEmpty())
            binding.editValue.isFocusable = field.fieldType != "date"
            binding.editValue.isFocusableInTouchMode = field.fieldType != "date"

            if (field.fieldType == "date") {
                binding.editValue.setOnClickListener { showDatePicker(field) }
            } else {
                binding.editValue.setOnClickListener(null)
                binding.editValue.setOnFocusChangeListener { _, hasFocus ->
                    if (!hasFocus) {
                        val text = binding.editValue.text?.toString().orEmpty()
                        if (text != (field.value ?: "")) onSave(field, text.ifBlank { null })
                    }
                }
            }
        }

        private fun showDatePicker(field: EditableField) {
            val context = binding.root.context
            val calendar = java.util.Calendar.getInstance()
            field.value?.let { iso ->
                runCatching { java.text.SimpleDateFormat("yyyy-MM-dd", java.util.Locale.US).parse(iso) }
                    .getOrNull()?.let { calendar.time = it }
            }

            android.app.DatePickerDialog(
                context,
                { _, year, month, day ->
                    val iso = String.format(java.util.Locale.US, "%04d-%02d-%02d", year, month + 1, day)
                    binding.editValue.setText(Formatters.date(iso))
                    onSave(field, iso)
                },
                calendar.get(java.util.Calendar.YEAR),
                calendar.get(java.util.Calendar.MONTH),
                calendar.get(java.util.Calendar.DAY_OF_MONTH),
            ).show()
        }

        private fun showDropdown(field: EditableField) {
            binding.textValue.visibility = android.view.View.GONE
            binding.inputLayout.visibility = android.view.View.GONE
            binding.dropdownLayout.visibility = android.view.View.VISIBLE
            binding.toggleGroup.visibility = android.view.View.GONE

            binding.dropdownValue.setSimpleItems(field.options.toTypedArray())
            binding.dropdownValue.setText(field.value ?: "", false)
            binding.dropdownValue.setOnItemClickListener { _, _, position, _ ->
                val picked = field.options.getOrNull(position)
                if (picked != field.value) onSave(field, picked)
            }
        }

        private fun showToggle(field: EditableField, threeState: Boolean) {
            binding.textValue.visibility = android.view.View.GONE
            binding.inputLayout.visibility = android.view.View.GONE
            binding.dropdownLayout.visibility = android.view.View.GONE
            binding.toggleGroup.visibility = android.view.View.VISIBLE
            binding.buttonNotStated.visibility = if (threeState) android.view.View.VISIBLE else android.view.View.GONE

            binding.toggleGroup.clearChecked()
            when (field.value) {
                "1" -> binding.toggleGroup.check(binding.buttonYes.id)
                "0" -> binding.toggleGroup.check(binding.buttonNo.id)
                else -> if (threeState) binding.toggleGroup.check(binding.buttonNotStated.id)
            }

            binding.buttonYes.setOnClickListener { if (field.value != "1") onSave(field, "1") }
            binding.buttonNo.setOnClickListener { if (field.value != "0") onSave(field, "0") }
            binding.buttonNotStated.setOnClickListener { if (field.value != null) onSave(field, null) }
        }
    }

    companion object {
        private val DIFF = object : DiffUtil.ItemCallback<EditableField>() {
            override fun areItemsTheSame(oldItem: EditableField, newItem: EditableField): Boolean =
                oldItem.key == newItem.key && oldItem.isCustom == newItem.isCustom

            override fun areContentsTheSame(oldItem: EditableField, newItem: EditableField): Boolean =
                oldItem == newItem
        }
    }
}
