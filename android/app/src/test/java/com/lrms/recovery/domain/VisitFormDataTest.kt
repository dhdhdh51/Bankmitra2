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
    fun `attachment count reflects photos and documents`() {
        val form = validForm()
        assertEquals(0, form.attachmentCount())
    }

    // =======================================================================
    // Report types: KRM/OTS settlement and CKCC OD-2 renewal
    // =======================================================================

    private fun otsForm(): VisitFormData = VisitFormData(
        loanAccountId = 7,
        reportType = VisitFormData.REPORT_OTS,
        visitDate = "2026-07-31",
        visitTime = "10:30:00",
        customerMet = true,
    )

    private fun ckccForm(): VisitFormData = VisitFormData(
        loanAccountId = 7,
        reportType = VisitFormData.REPORT_CKCC,
        visitDate = "2026-07-31",
        visitTime = "10:30:00",
        customerMet = true,
    )

    @Test
    fun `a recovery report sends no settlement or renewal fields`() {
        val fields = VisitFormData(
            loanAccountId = 1,
            visitDate = "2026-07-31",
            visitTime = "09:00:00",
            customerMet = true,
        ).toFieldMap()

        assertEquals("recovery", fields["report_type"])
        // A stray section would make the server write an empty detail row that a
        // settlement report later has to be filtered against.
        assertTrue(fields.keys.none { it.startsWith("ots_details") })
        assertTrue(fields.keys.none { it.startsWith("ckcc_details") })
    }

    @Test
    fun `settlement fields are sent under the ots_details prefix`() {
        val form = otsForm().apply {
            otsEligible = true
            otsScheme = "krm_ots"
            otsRlbAmount = "2,00,000"
            otsPayableAmount = "45000"
            otsDepositReceived = true
            otsDepositAmount = "4500"
            otsDepositDate = "2026-07-30"
            otsDepositReference = "RCPT/1"
            otsBorrowerAccepted = true
        }
        val fields = form.toFieldMap()

        assertEquals("ots", fields["report_type"])
        assertEquals("1", fields["ots_details[eligible_for_ots]"])
        assertEquals("krm_ots", fields["ots_details[scheme]"])
        // Grouped input must reach the API as a plain number.
        assertEquals("200000.0", fields["ots_details[rlb_amount]"])
        assertEquals("RCPT/1", fields["ots_details[deposit_reference]"])
        assertTrue(fields.keys.none { it.startsWith("ckcc_details") })
    }

    @Test
    fun `an unparseable amount is not sent at all`() {
        val fields = otsForm().apply {
            otsBorrowerAccepted = true
            otsRlbAmount = "about two lakh"
        }.toFieldMap()

        // Better to omit it than to post something the server has to guess at.
        assertFalse(fields.containsKey("ots_details[rlb_amount]"))
    }

    @Test
    fun `payable is suggested from RLB and the scheme percentage`() {
        val form = otsForm().apply {
            otsRlbAmount = "200000"
            otsPayablePercent = "22.5"
        }
        assertEquals(45000.0, form.suggestedPayable()!!, 0.01)
    }

    @Test
    fun `the required deposit is suggested from the payable amount`() {
        val form = otsForm().apply {
            otsPayableAmount = "45000"
            otsDepositPercent = "10"
        }
        assertEquals(4500.0, form.suggestedRequiredDeposit()!!, 0.01)
    }

    @Test
    fun `the balance owed subtracts what was already deposited`() {
        val form = otsForm().apply {
            otsTotalSettlement = "45000"
            otsDepositAmount = "4500"
        }
        assertEquals(40500.0, form.suggestedBalancePayable()!!, 0.01)
    }

    @Test
    fun `the balance never goes negative when the deposit exceeds the total`() {
        val form = otsForm().apply {
            otsTotalSettlement = "4000"
            otsDepositAmount = "4500"
        }
        // A negative "balance payable" on a settlement letter would be nonsense.
        assertEquals(0.0, form.suggestedBalancePayable()!!, 0.01)
    }

    @Test
    fun `no suggestion is offered until its inputs exist`() {
        assertNull(otsForm().suggestedPayable())
        assertNull(otsForm().apply { otsRlbAmount = "200000" }.let {
            it.otsPayablePercent = ""
            it.suggestedPayable()
        })
    }

    @Test
    fun `a recorded deposit must carry an amount date and bank reference`() {
        val errors = otsForm().apply {
            otsBorrowerAccepted = true
            otsDepositReceived = true
        }.validate()

        // Without these three, the record is not evidence of anything - and the
        // agent must never be the one holding the money.
        assertTrue(errors.containsKey("ots_deposit_amount"))
        assertTrue(errors.containsKey("ots_deposit_date"))
        assertTrue(errors.containsKey("ots_deposit_reference"))
    }

    @Test
    fun `a refusal must record its reason`() {
        val errors = otsForm().apply { otsBorrowerAccepted = false }.validate()
        assertTrue(errors.containsKey("ots_rejection_reason"))

        val ok = otsForm().apply {
            otsBorrowerAccepted = false
            otsRejectionReason = "Wants more time to sell produce."
        }.validate()
        assertFalse(ok.containsKey("ots_rejection_reason"))
    }

    @Test
    fun `an out of range percentage is rejected`() {
        val errors = otsForm().apply {
            otsBorrowerAccepted = true
            otsReliefPercent = "150"
        }.validate()
        assertTrue(errors.containsKey("ots_relief_percent"))
    }

    @Test
    fun `a validity window cannot end before it starts`() {
        val errors = otsForm().apply {
            otsBorrowerAccepted = true
            otsValidityFrom = "2026-08-01"
            otsValidityTo = "2026-07-01"
        }.validate()
        assertTrue(errors.containsKey("ots_validity_to"))
    }

    @Test
    fun `eligibility without a scheme is rejected`() {
        val errors = otsForm().apply {
            otsBorrowerAccepted = true
            otsEligible = true
        }.validate()
        assertTrue(errors.containsKey("ots_scheme"))
    }

    @Test
    fun `a renewal report requires the renewal due date`() {
        // The NPA date is derived from it, which is the whole point of the report.
        assertTrue(ckccForm().validate().containsKey("ckcc_renewal_due_date"))
        assertFalse(
            ckccForm().apply { ckccRenewalDueDate = "2026-08-20" }
                .validate().containsKey("ckcc_renewal_due_date"),
        )
    }

    @Test
    fun `days to renewal counts down and goes negative once overdue`() {
        val today = java.time.LocalDate.parse("2026-07-31")

        assertEquals(10L, ckccForm().apply { ckccRenewalDueDate = "2026-08-10" }.daysToRenewal(today))
        assertEquals(0L, ckccForm().apply { ckccRenewalDueDate = "2026-07-31" }.daysToRenewal(today))
        assertEquals(-4L, ckccForm().apply { ckccRenewalDueDate = "2026-07-27" }.daysToRenewal(today))
        assertNull(ckccForm().daysToRenewal(today))
        // A malformed date must not crash the countdown.
        assertNull(ckccForm().apply { ckccRenewalDueDate = "not-a-date" }.daysToRenewal(today))
    }

    @Test
    fun `the renewal bucket matches the server thresholds`() {
        val today = java.time.LocalDate.parse("2026-07-31")
        fun bucketFor(due: String) = ckccForm().apply { ckccRenewalDueDate = due }.renewalBucket(today)

        assertEquals("overdue", bucketFor("2026-07-30"))
        assertEquals("within_7", bucketFor("2026-07-31"))
        assertEquals("within_7", bucketFor("2026-08-07"))
        assertEquals("within_15", bucketFor("2026-08-08"))
        assertEquals("within_15", bucketFor("2026-08-15"))
        assertEquals("within_30", bucketFor("2026-08-16"))
    }

    @Test
    fun `the expected NPA date is the day after the deadline`() {
        assertEquals(
            "2026-08-11",
            ckccForm().apply { ckccRenewalDueDate = "2026-08-10" }.expectedNpaDate(),
        )
        assertNull(ckccForm().expectedNpaDate())
    }

    @Test
    fun `a signed renewal form without consent is contradictory`() {
        val errors = ckccForm().apply {
            ckccRenewalDueDate = "2026-08-20"
            ckccRenewalFormSigned = true
            ckccWillingToRenew = false
        }.validate()
        assertTrue(errors.containsKey("ckcc_willing_to_renew"))
    }

    @Test
    fun `KYC is sent as the enum the server stores`() {
        val complete = ckccForm().apply {
            ckccRenewalDueDate = "2026-08-20"
            ckccKycComplete = true
        }.toFieldMap()
        assertEquals("complete", complete["ckcc_details[kyc_status]"])

        val pending = ckccForm().apply { ckccRenewalDueDate = "2026-08-20" }.toFieldMap()
        assertEquals("pending", pending["ckcc_details[kyc_status]"])
    }

    @Test
    fun `renewal evidence photos are sent under their own field names`() {
        val form = ckccForm().apply {
            ckccRenewalDueDate = "2026-08-20"
            landPhoto = java.io.File("land.jpg")
            passbookPhoto = java.io.File("passbook.jpg")
            renewalFormPhoto = java.io.File("form.jpg")
        }
        val photos = form.photoFiles()
        assertTrue(photos.containsKey("land_photo"))
        assertTrue(photos.containsKey("passbook_photo"))
        assertTrue(photos.containsKey("renewal_form_photo"))
    }

    @Test
    fun `switching to a special report type counts as unsaved input`() {
        val blank = VisitFormData(loanAccountId = 1)
        assertFalse(blank.hasUnsavedInput())
        assertTrue(blank.copy(reportType = VisitFormData.REPORT_CKCC).hasUnsavedInput())
    }

    @Test
    fun `relief is suggested as whatever the borrower is not paying`() {
        // The worked example: 22.50% payable, so 77.50% waived.
        val form = otsForm().apply { otsPayablePercent = "22.5" }
        assertEquals(77.5, form.suggestedReliefPercent()!!, 0.01)

        assertEquals(0.0, otsForm().apply { otsPayablePercent = "100" }.suggestedReliefPercent()!!, 0.01)
        // Blank explicitly: the field defaults to the scheme's 22.5%, so a fresh
        // form already has enough to suggest from.
        assertNull(otsForm().apply { otsPayablePercent = "" }.suggestedReliefPercent())
        // Nonsense in, nothing out - better than suggesting a negative waiver.
        assertNull(otsForm().apply { otsPayablePercent = "140" }.suggestedReliefPercent())
    }

    @Test
    fun `the worked settlement example reproduces end to end`() {
        // Outstanding 2,50,000 at 22.50% payable, 10% initial deposit:
        // payable 56,250 -> deposit 5,625 -> balance 50,625.
        val form = otsForm().apply {
            otsRlbAmount = "250000"
            otsPayablePercent = "22.5"
            otsDepositPercent = "10"
        }
        assertEquals(56250.0, form.suggestedPayable()!!, 0.01)

        form.otsPayableAmount = "56250"
        assertEquals(5625.0, form.suggestedRequiredDeposit()!!, 0.01)
        assertEquals(77.5, form.suggestedReliefPercent()!!, 0.01)

        form.otsTotalSettlement = "56250"
        form.otsDepositAmount = "5625"
        assertEquals(50625.0, form.suggestedBalancePayable()!!, 0.01)
    }
}
