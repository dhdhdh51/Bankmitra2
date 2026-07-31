package com.lrms.recovery.domain

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Unit tests for the visit report form model.
 *
 * This class defines the wire contract for the most important write in the whole
 * product, so its validation rules and serialisation are covered directly.
 */
class VisitFormDataTest {

    private fun validForm() = VisitFormData(
        loanAccountId = 42,
        visitDate = "2026-07-30",
        visitTime = "14:30",
        customerMet = true,
    )

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    @Test
    fun `a complete form passes validation`() {
        assertTrue(validForm().validate().isEmpty())
    }

    @Test
    fun `visit date is required`() {
        val errors = validForm().copy(visitDate = "").validate()
        assertTrue(errors.containsKey("visit_date"))
    }

    @Test
    fun `visit time is required`() {
        val errors = validForm().copy(visitTime = "").validate()
        assertTrue(errors.containsKey("visit_time"))
    }

    @Test
    fun `at least one contact outcome is required`() {
        // A report with no contact outcome says nothing about what happened.
        val errors = validForm().copy(customerMet = false).validate()
        assertTrue(errors.containsKey("contact"))
    }

    @Test
    fun `any single contact outcome satisfies the contact rule`() {
        listOf<(VisitFormData) -> VisitFormData>(
            { it.copy(customerMet = true) },
            { it.copy(familyMemberMet = true, familyMemberName = "Sita Devi") },
            { it.copy(houseLocked = true) },
            { it.copy(phoneContact = true) },
            { it.copy(phoneSwitchedOff = true) },
        ).forEach { mutate ->
            val form = mutate(validForm().copy(customerMet = false))
            assertFalse(
                "Expected no contact error for $form",
                mutate(form).validate().containsKey("contact"),
            )
        }
    }

    @Test
    fun `family member name is required when a family member was met`() {
        val errors = validForm()
            .copy(customerMet = false, familyMemberMet = true, familyMemberName = "")
            .validate()

        assertTrue(errors.containsKey("family_member_name"))
    }

    @Test
    fun `a promise amount without a date is rejected`() {
        // Half a promise would not create a promise case on the server, so it is
        // refused here rather than silently dropped.
        val errors = validForm().copy(promiseAmount = "5000").validate()
        assertTrue(errors.containsKey("promise_date"))
    }

    @Test
    fun `a promise date without an amount is rejected`() {
        val errors = validForm().copy(promiseDate = "2026-08-15").validate()
        assertTrue(errors.containsKey("promise_amount"))
    }

    @Test
    fun `a complete promise passes validation`() {
        val errors = validForm()
            .copy(promiseAmount = "12500", promiseDate = "2026-08-15")
            .validate()

        assertTrue(errors.isEmpty())
    }

    @Test
    fun `a non-numeric promise amount is rejected`() {
        val errors = validForm()
            .copy(promiseAmount = "about five thousand", promiseDate = "2026-08-15")
            .validate()

        assertTrue(errors.containsKey("promise_amount"))
    }

    @Test
    fun `other reason requires free text`() {
        val errors = validForm().copy(reasonOthers = true, reasonOtherText = "").validate()
        assertTrue(errors.containsKey("reason_other_text"))
    }

    @Test
    fun `other recommendation requires free text`() {
        val errors = validForm().copy(recOthers = true, recOtherText = "").validate()
        assertTrue(errors.containsKey("rec_other_text"))
    }

    @Test
    fun `other occupation requires free text`() {
        val errors = validForm()
            .copy(occupation = VisitFormData.OCCUPATION_OTHERS, occupationOtherText = "")
            .validate()

        assertTrue(errors.containsKey("occupation_other_text"))
    }

    // -----------------------------------------------------------------------
    // Promise amount parsing
    // -----------------------------------------------------------------------

    @Test
    fun `promise amount tolerates grouped and prefixed input`() {
        assertEquals(12500.0, validForm().copy(promiseAmount = "12,500").promiseAmountValue()!!, 0.001)
        assertEquals(12500.5, validForm().copy(promiseAmount = "12,500.50").promiseAmountValue()!!, 0.001)
        assertEquals(1200.0, validForm().copy(promiseAmount = "\u20B91200").promiseAmountValue()!!, 0.001)
        assertEquals(500.0, validForm().copy(promiseAmount = " 500 ").promiseAmountValue()!!, 0.001)
    }

    @Test
    fun `promise amount returns null for blank or invalid input`() {
        assertNull(validForm().copy(promiseAmount = "").promiseAmountValue())
        assertNull(validForm().copy(promiseAmount = "abc").promiseAmountValue())
    }

    @Test
    fun `createsPromise requires both halves`() {
        assertFalse(validForm().createsPromise())
        assertFalse(validForm().copy(promiseAmount = "5000").createsPromise())
        assertFalse(validForm().copy(promiseDate = "2026-08-15").createsPromise())
        assertFalse(validForm().copy(promiseAmount = "0", promiseDate = "2026-08-15").createsPromise())
        assertTrue(validForm().copy(promiseAmount = "5000", promiseDate = "2026-08-15").createsPromise())
    }

    // -----------------------------------------------------------------------
    // Serialisation
    // -----------------------------------------------------------------------

    @Test
    fun `field map carries the identifiers the server needs`() {
        val fields = validForm().toFieldMap()

        assertEquals("42", fields["loan_account_id"])
        assertEquals("2026-07-30", fields["visit_date"])
        assertEquals("14:30", fields["visit_time"])
        assertTrue("client_uuid must be present for idempotency", fields.containsKey("client_uuid"))
        assertTrue(fields["client_uuid"]!!.isNotBlank())
    }

    @Test
    fun `booleans serialise as 1 and 0`() {
        val fields = validForm().copy(customerMet = true, houseLocked = false).toFieldMap()

        assertEquals("1", fields["customer_met"])
        assertEquals("0", fields["house_locked"])
    }

    @Test
    fun `blank optional fields are omitted entirely`() {
        val fields = validForm().copy(village = "", remarks = "", occupation = "").toFieldMap()

        assertFalse(fields.containsKey("village"))
        assertFalse(fields.containsKey("remarks"))
        assertFalse(fields.containsKey("occupation"))
    }

    @Test
    fun `populated optional fields are trimmed and included`() {
        val fields = validForm().copy(village = "  Kotri  ", remarks = " Crop failed. ").toFieldMap()

        assertEquals("Kotri", fields["village"])
        assertEquals("Crop failed.", fields["remarks"])
    }

    @Test
    fun `an incomplete promise is not sent at all`() {
        // The server would otherwise have to guess at half a promise.
        val fields = validForm().copy(promiseAmount = "5000", promiseDate = "").toFieldMap()

        assertFalse(fields.containsKey("promise_amount"))
        assertFalse(fields.containsKey("promise_date"))
    }

    @Test
    fun `a complete promise is sent as a parsed number`() {
        val fields = validForm()
            .copy(promiseAmount = "12,500.50", promiseDate = "2026-08-15")
            .toFieldMap()

        assertEquals(12500.5, fields["promise_amount"]!!.toDouble(), 0.001)
        assertEquals("2026-08-15", fields["promise_date"])
    }

    @Test
    fun `every section 6 flag appears in the field map`() {
        // Guards against a field being added to the UI but never transmitted.
        val fields = validForm().toFieldMap()

        listOf(
            "customer_met", "family_member_met", "house_locked", "phone_contact",
            "phone_switched_off",
            "borrower_alive", "same_address", "shifted",
            "ready_to_pay", "not_ready", "interest_payment", "ots",
            "reason_financial_problem", "reason_crop_loss", "reason_animal_loss",
            "reason_illness", "reason_unemployment", "reason_dispute",
            "reason_other_loan", "reason_others",
            "rec_recovery_possible", "rec_regular_followup", "rec_legal_action",
            "rec_rc", "rec_ots", "rec_others",
        ).forEach { key ->
            assertTrue("Missing field: $key", fields.containsKey(key))
        }
    }

    @Test
    fun `client uuid is stable for one form instance`() {
        // A retry must reuse the same key, otherwise the server would treat it as
        // a second visit.
        val form = validForm()
        assertEquals(form.toFieldMap()["client_uuid"], form.toFieldMap()["client_uuid"])
    }

    @Test
    fun `separate forms get different client uuids`() {
        assertTrue(validForm().clientUuid != validForm().clientUuid)
    }

    // -----------------------------------------------------------------------
    // Misc
    // -----------------------------------------------------------------------

    @Test
    fun `hasUnsavedInput is false for an untouched form`() {
        val untouched = VisitFormData(loanAccountId = 1, visitDate = "2026-07-30", visitTime = "10:00")
        assertFalse(untouched.hasUnsavedInput())
    }

    @Test
    fun `hasUnsavedInput becomes true once anything is entered`() {
        val base = VisitFormData(loanAccountId = 1, visitDate = "2026-07-30", visitTime = "10:00")

        assertTrue(base.copy(customerMet = true).hasUnsavedInput())
        assertTrue(base.copy(remarks = "note").hasUnsavedInput())
        assertTrue(base.copy(promiseAmount = "100").hasUnsavedInput())
        assertTrue(base.copy(reasonCropLoss = true).hasUnsavedInput())
    }

    @Test
    fun `occupation list matches the server enum`() {
        assertEquals(6, VisitFormData.OCCUPATIONS.size)
        assertEquals(
            listOf("agriculture", "dairy", "business", "job", "labour", "others"),
            VisitFormData.OCCUPATIONS.map { it.first },
        )
    }

    @Test
    fun `attachment count reflects photos signatures and documents`() {
        val form = validForm()
        assertEquals(0, form.attachmentCount())
    }
}
