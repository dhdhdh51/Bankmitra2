package com.lrms.recovery.ui.sss

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.ApiResult
import com.lrms.recovery.data.remote.SssDayPayload
import com.lrms.recovery.databinding.ActivitySssEntryBinding
import com.lrms.recovery.ui.BaseActivity
import com.lrms.recovery.util.Formatters
import kotlinx.coroutines.launch

/**
 * The agent's own SSS enrolment figures for the day.
 *
 * This screen exists because of an asymmetry that had become indefensible: the
 * nightly warning check measures APY, PMJJBY, PMSBY and PMJDY against each agent's
 * monthly target and escalates a sustained shortfall to the supervisor, then the
 * service provider, then the regional office - while the only place those four
 * numbers could be entered was the admin panel, which an agent cannot open. So an
 * agent could receive a written warning for failing to report figures the software
 * gave them no way to report.
 *
 * Two deliberate choices in the layout:
 *
 *   VISITS ARE SHOWN, NOT ASKED FOR. Visits, contacts and PTP are counted from the
 *   reports already filed and are read-only here. There is no field anywhere in this
 *   app for typing a visit count - a self-reported number that a supervisor's report
 *   also computes independently is two answers waiting to disagree.
 *
 *   TODAY AND YESTERDAY ONLY. Enforced by the server, reflected here by disabling the
 *   form. Backdating a month of enrolments the evening before an assessment is
 *   exactly the pressure a scored metric creates.
 */
class SssEntryActivity : BaseActivity() {

    private lateinit var binding: ActivitySssEntryBinding
    private var payload: SssDayPayload? = null
    private var saving = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySssEntryBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setSupportActionBar(binding.toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        binding.toolbar.setNavigationOnClickListener { finish() }

        binding.buttonSave.setOnClickListener { save() }

        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            when (val result = repository.sssDay()) {
                is ApiResult.Success -> {
                    payload = result.data
                    render(result.data)
                }
                else -> handleFailure(result, binding.root)
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun render(data: SssDayPayload) {
        binding.textDate.text = getString(R.string.sss_for_date, Formatters.date(data.date))

        // Zero is shown as an empty field, not as "0". A pre-filled zero reads as a
        // figure somebody entered, and the difference between "none today" and "not
        // filled in yet" is the difference between a target met and a target missed.
        binding.inputApy.setText(data.apy.takeIf { it > 0 }?.toString() ?: "")
        binding.inputPmjjby.setText(data.pmjjby.takeIf { it > 0 }?.toString() ?: "")
        binding.inputPmsby.setText(data.pmsby.takeIf { it > 0 }?.toString() ?: "")
        binding.inputPmjdy.setText(data.pmjdy.takeIf { it > 0 }?.toString() ?: "")
        binding.inputRemarks.setText(data.remarks ?: "")

        val counted = data.today
        binding.textVisits.text = (counted?.visits ?: 0).toString()
        binding.textContacts.text = (counted?.contacts ?: 0).toString()
        binding.textPtp.text = (counted?.ptp ?: 0).toString()

        val month = data.month
        binding.textMonthTotal.text = getString(
            R.string.sss_month_total,
            month?.total ?: 0,
            month?.days ?: 0,
        )

        setEditable(data.editable)
    }

    /** A read-only day is shown, not hidden: seeing an older day is useful. */
    private fun setEditable(editable: Boolean) {
        for (field in listOf(
            binding.inputApy, binding.inputPmjjby, binding.inputPmsby,
            binding.inputPmjdy, binding.inputRemarks,
        )) {
            field.isEnabled = editable
        }

        binding.buttonSave.visibility = if (editable) View.VISIBLE else View.GONE
        binding.textReadOnlyNote.visibility = if (editable) View.GONE else View.VISIBLE
    }

    private fun save() {
        if (saving) {
            return
        }

        val apy = number(binding.inputApy.text?.toString())
        val pmjjby = number(binding.inputPmjjby.text?.toString())
        val pmsby = number(binding.inputPmsby.text?.toString())
        val pmjdy = number(binding.inputPmjdy.text?.toString())

        saving = true
        binding.buttonSave.isEnabled = false
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            // Safe to retry: the server upserts on (agent, date), so a resend after a
            // timeout on a rural connection leaves the same figures rather than
            // doubling numbers that feed a ranking.
            val result = repository.saveSss(
                date = payload?.date,
                apy = apy,
                pmjjby = pmjjby,
                pmsby = pmsby,
                pmjdy = pmjdy,
                remarks = binding.inputRemarks.text?.toString()?.trim()?.takeIf { it.isNotEmpty() },
            )

            saving = false
            binding.buttonSave.isEnabled = true
            binding.progress.visibility = View.GONE

            when (result) {
                is ApiResult.Success -> {
                    showMessage(getString(R.string.sss_saved, result.data.total), binding.root)
                    // Re-read rather than trusting what was typed: the counted
                    // figures may have moved since the screen opened.
                    load()
                }
                else -> handleFailure(result, binding.root)
            }
        }
    }

    /** Blank means none. An unparseable value means none, not a crash. */
    private fun number(raw: String?): Int =
        raw?.trim()?.takeIf { it.isNotEmpty() }?.toIntOrNull()?.coerceIn(0, 999) ?: 0

    companion object {
        fun intent(context: Context): Intent = Intent(context, SssEntryActivity::class.java)
    }
}
