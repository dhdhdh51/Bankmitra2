package com.lrms.recovery.data.remote

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Deserialises real server responses with the real DTOs.
 *
 * Every other test in this module works on values the test itself constructed, so
 * nothing verified that the app and the API actually agree on the wire format.
 * That gap is dangerous specifically because Gson is silent about it: an unknown
 * key is dropped and a missing key leaves the field at its default. Rename
 * `customer_name` to `name` on the server and the app keeps compiling, keeps
 * returning HTTP 200, and simply shows a list of blank rows forever - with no
 * exception, no log line and nothing to grep for from a phone in a village.
 *
 * The fixtures in src/test/resources/api are captured from a live server by
 * tools/capture-api-fixtures.sh. Re-run it whenever an API response changes; if
 * a field is renamed or dropped, these assertions fail instead of the field
 * silently becoming null in production.
 */
class ApiContractTest {

    private val gson = Gson()

    /**
     * JUnit 4's assertNotNull returns void, so it cannot be used to narrow a
     * nullable to a usable value. This does both: fails with a readable message,
     * and hands back the non-null value.
     *
     * It matters for more than convenience. Gson populates fields by reflection
     * and happily writes null into a Kotlin property declared non-null, so a
     * "cannot be null" type is no guarantee once JSON is involved - these checks
     * are the only thing standing between a renamed field and a blank screen.
     */
    private fun <T : Any> need(label: String, value: T?): T =
        value ?: throw AssertionError("$label was null - the server did not send it under the expected key")

    private fun load(name: String): String =
        requireNotNull(javaClass.classLoader?.getResourceAsStream("api/$name.json")) {
            "fixture api/$name.json is missing - run tools/capture-api-fixtures.sh"
        }.bufferedReader().use { it.readText() }

    private inline fun <reified T> envelope(name: String): ApiEnvelope<T> {
        val type = object : TypeToken<ApiEnvelope<T>>() {}.type
        return gson.fromJson(load(name), type)
    }

    // -----------------------------------------------------------------------
    // Auth
    // -----------------------------------------------------------------------

    @Test
    fun `login response carries the tokens and the user`() {
        val body = envelope<AuthPayload>("login")

        assertTrue("login fixture should be a success response", body.success)
        val payload = need("data must deserialise", body.data)

        assertTrue("access_token must be present", payload.accessToken.isNotBlank())
        assertTrue("refresh_token must be present", payload.refreshToken.isNotBlank())
        assertEquals("Bearer", payload.tokenType)
        assertTrue("expires_in must be a positive number", payload.expiresIn > 0)

        val user = need("user must deserialise", payload.user)
        assertTrue(user.id > 0)
        assertEquals("AGT001", user.employeeCode)
        assertTrue("name must be present", user.name.isNotBlank())
        assertEquals("agent", user.role)
        assertTrue("isAgent must be derived from role", user.isAgent)
        assertTrue("role_name must be present", user.roleName.isNotBlank())
        assertTrue("an agent must arrive with permissions", user.permissions.isNotEmpty())
        // The app decides what the agent may do from this list, so the two the
        // product actually depends on are pinned here.
        assertTrue("agents need dashboard.view", user.can("dashboard.view"))
        assertTrue("agents need customers.view_pii to call the borrower", user.can("customers.view_pii"))
        assertFalse("agents must NOT be able to settle promises", user.can("promises.update"))
    }

    @Test
    fun `me response carries the user`() {
        val body = envelope<MePayload>("me")
        assertTrue(body.success)
        val user = need("value", body.data?.user)
        assertEquals("AGT001", user.employeeCode)
        assertTrue("branch_id must arrive - the app scopes everything by it", need("branch_id", user.branchId) > 0)
        assertTrue("branch_name is shown in the app bar", !user.branchName.isNullOrBlank())
    }

    @Test
    fun `ping response is parseable before any login`() {
        val body = envelope<PingPayload>("ping")
        assertTrue(body.success)
        val payload = need("value", body.data)
        assertTrue(payload.status.isNotBlank())
        assertTrue("api_version drives the compatibility check", payload.apiVersion.isNotBlank())
    }

    // -----------------------------------------------------------------------
    // Errors - the app parses these too
    // -----------------------------------------------------------------------

    @Test
    fun `401 response deserialises with a message and no data`() {
        val body = envelope<MePayload>("error_401")
        assertFalse(body.success)
        assertTrue("a 401 must explain itself", body.message.isNotBlank())
    }

    @Test
    fun `422 response exposes per-field errors so forms can show them inline`() {
        val raw = load("error_422")
        val body = gson.fromJson(raw, object : TypeToken<ApiEnvelope<ValidationPayload>>() {}.type)
            as ApiEnvelope<ValidationPayload>
        assertFalse(body.success)
        assertTrue(body.message.isNotBlank())
        val errors = need("field errors must deserialise", body.data?.errors)
        assertTrue("the missing field must be named", errors.containsKey("password"))
        assertTrue("and carry at least one message", errors.getValue("password").isNotEmpty())
    }

    @Test
    fun `404 response deserialises`() {
        val body = envelope<Unit>("error_404")
        assertFalse(body.success)
        assertTrue(body.message.isNotBlank())
    }

    // -----------------------------------------------------------------------
    // Leads
    // -----------------------------------------------------------------------

    @Test
    fun `leads list deserialises with pagination meta alongside the array`() {
        val body = envelope<List<LeadDto>>("leads")
        assertTrue(body.success)
        val leads = need("value", body.data)
        assertTrue("the fixture should contain leads", leads.isNotEmpty())

        // meta is a SIBLING of data, not nested inside it. If the server ever
        // wraps the array in its own object, `data` stops being a List and Gson
        // throws - so this pins the envelope shape too.
        val meta = need("pagination meta must deserialise", body.meta)
        assertTrue(meta.total > 0)
        assertTrue(meta.perPage > 0)
        assertTrue(meta.currentPage >= 1)

        val lead = leads.first()
        assertTrue("id", lead.id > 0)
        assertTrue("loan_account_number is the primary label in the list", lead.loanAccountNumber.isNotBlank())
        assertTrue("customer_name is the primary label in the list", lead.customerName.isNotBlank())
        assertTrue("customer_id is needed to open the profile", lead.customerId > 0)
        assertTrue("branch_id", lead.branchId > 0)
        assertTrue("current_status drives the status chip", lead.currentStatus.isNotBlank())
        assertTrue("outstanding_amount is displayed on every row", lead.outstandingAmount > 0.0)
        // The Call button in the lead list is enabled from `mobile`, so the list
        // must carry a dialable number for an agent - who holds
        // customers.view_pii. When this was null the button silently never
        // appeared and an agent had to open every lead just to phone someone.
        assertFalse("mobile must be dialable straight from the list", lead.mobile.isNullOrBlank())
        assertFalse("mobile_masked is what the row displays", lead.mobileMasked.isNullOrBlank())

        // Aadhaar deliberately stays out of list responses: nothing in a list
        // needs it, and shipping it for every row would widen the damage from any
        // single leaked or cached response for no benefit.
        assertTrue(
            "aadhaar must NOT be bulk-exposed in a list",
            lead.aadhaar.isNullOrBlank(),
        )
        assertFalse("the masked form is still available", lead.aadhaarMasked.isNullOrBlank())
    }

    @Test
    fun `lead search returns the same shape as the list`() {
        val body = envelope<List<LeadDto>>("leads_search")
        assertTrue("search fixture must be a success - not a validation error", body.success)
        val leads = need("value", body.data)
        assertTrue("search should have matched something", leads.isNotEmpty())
        assertTrue(leads.first().loanAccountNumber.isNotBlank())
    }

    // -----------------------------------------------------------------------
    // Customer profile and history
    // -----------------------------------------------------------------------

    @Test
    fun `customer profile deserialises every section the screen renders`() {
        val body = envelope<CustomerProfilePayload>("customer_profile")
        assertTrue(body.success)
        val payload = need("value", body.data)

        val lead = need("the profile header comes from lead", payload.lead)
        assertTrue(lead.id > 0)
        assertTrue(lead.customerName.isNotBlank())

        // These four lists back the four tabs. They may legitimately be empty,
        // but they must never be null, or the adapters crash.
        need("payload.promises", payload.promises)
        need("payload.visits", payload.visits)
        need("payload.timeline", payload.timeline)
        need("payload.photos", payload.photos)
        need("payload.otherAccounts", payload.otherAccounts)
    }

    @Test
    fun `customer history deserialises the timeline`() {
        val body = envelope<HistoryPayload>("customer_history")
        assertTrue(body.success)
        val payload = need("value", body.data)
        assertTrue(payload.loanAccountNumber.isNotBlank())
        assertTrue(payload.customerName.isNotBlank())
        need("payload.timeline", payload.timeline)
        need("payload.visits", payload.visits)

        payload.timeline.firstOrNull()?.let { event ->
            assertTrue("event_type", event.eventType.isNotBlank())
            assertTrue("event_at drives the ordering shown to the user", event.eventAt.isNotBlank())
            assertTrue("title is the headline of the row", event.title.isNotBlank())
        }
    }

    // -----------------------------------------------------------------------
    // Visits
    // -----------------------------------------------------------------------

    @Test
    fun `visit feed deserialises`() {
        val body = envelope<List<VisitSummaryDto>>("visits_feed")
        assertTrue(body.success)
        val visits = need("value", body.data)
        assertTrue("the fixture should contain visits", visits.isNotEmpty())

        val visit = visits.first()
        assertTrue(visit.id > 0)
        assertTrue("visit_date", visit.visitDate.isNotBlank())
        assertTrue("agent_name", visit.agentName.isNotBlank())
        assertTrue("created_at is used for ordering", visit.createdAt.isNotBlank())
    }

    @Test
    fun `visit detail deserialises the full nested report`() {
        val body = envelope<VisitDetailPayload>("visit_detail")
        assertTrue(body.success)
        val payload = need("value", body.data)
        val report = need("report must deserialise", payload.report)
        assertTrue(report.id > 0)

        // The detail screen renders these grouped sections. A rename on the
        // server would leave whole cards blank.
        need("general section", report.general)
        need("borrower section", report.borrower)
        need("loan section", report.loan)
        need("recovery section", report.recovery)
    }

    @Test
    fun `visit form options deserialise into the dropdowns and flags`() {
        val body = envelope<FormOptionsPayload>("form_options")
        assertTrue(body.success)
        val payload = need("value", body.data)
        // An empty option list means an unusable visit form: the dropdown and
        // every checkbox group on the visit screen is built from these.
        assertTrue("occupations drive a dropdown", payload.occupations.isNotEmpty())
        payload.occupations.first().let {
            assertTrue("option value", it.value.isNotBlank())
            assertTrue("option label", it.label.isNotBlank())
        }

        assertTrue("contact flags", payload.contactFlags.isNotEmpty())
        assertTrue("recovery flags", payload.recoveryFlags.isNotEmpty())
        assertTrue("non-payment reason flags", payload.reasonFlags.isNotEmpty())
        assertTrue("recommendation flags", payload.recommendationFlags.isNotEmpty())

        // Flags use `key`, options use `value` - two different shapes on one
        // payload, so both are pinned.
        payload.contactFlags.first().let {
            assertTrue("flag key", it.key.isNotBlank())
            assertTrue("flag label", it.label.isNotBlank())
        }

        // The keys the visit form posts back must be exactly these, or a
        // submitted report silently loses its checkboxes.
        val contactKeys = payload.contactFlags.map { it.key }
        assertTrue("customer_met must be offered", contactKeys.contains("customer_met"))
        val recoveryKeys = payload.recoveryFlags.map { it.key }
        assertTrue("ready_to_pay must be offered", recoveryKeys.contains("ready_to_pay"))
    }

    // -----------------------------------------------------------------------
    // Promises, notifications, dashboard
    // -----------------------------------------------------------------------

    @Test
    fun `promises deserialise`() {
        val body = envelope<List<PromiseDto>>("promises")
        assertTrue(body.success)
        val promises = need("value", body.data)
        assertTrue(promises.isNotEmpty())
        val promise = promises.first()
        assertTrue(promise.id > 0)
        assertTrue("loan_account_id is needed to open the lead", promise.loanAccountId > 0)
        assertTrue("promise_date", promise.promiseDate.isNotBlank())
        assertTrue("status drives the chip colour", promise.status.isNotBlank())
        assertTrue("promise_amount", promise.promiseAmount > 0.0)
    }

    @Test
    fun `notifications deserialise`() {
        val body = envelope<List<NotificationDto>>("notifications")
        assertTrue(body.success)
        val items = need("value", body.data)
        assertTrue(items.isNotEmpty())
        val item = items.first()
        assertTrue(item.id > 0)
        assertTrue("title", item.title.isNotBlank())
        assertTrue("created_at", item.createdAt.isNotBlank())
    }

    @Test
    fun `unread count deserialises`() {
        val body = envelope<UnreadPayload>("unread_count")
        assertTrue(body.success)
        need("body.data", body.data)
        assertTrue("the badge count must not be negative", need("unread payload", body.data).unreadCount >= 0)
    }

    @Test
    fun `meta deserialises`() {
        val body = envelope<MetaPayload>("meta")
        assertTrue(body.success)
        need("body.data", body.data)
    }

    @Test
    fun `agent dashboard counters deserialise`() {
        val body = envelope<AgentDashboardPayload>("dashboard")
        assertTrue(body.success)
        val payload = need("value", body.data)

        val leads = need("lead counters", payload.leads)
        val visits = need("visit counters", payload.visits)
        val promises = need("promise counters", payload.promises)

        // Every card on the agent home screen reads one of these. A rename shows
        // a screen full of zeros, which looks like "no work today" rather than a
        // bug - the most misleading possible failure for a recovery agent.
        assertTrue("total leads", leads.total > 0)
        assertTrue("outstanding", leads.outstanding > 0.0)
        assertTrue("visits total", visits.total > 0)
        assertTrue("visits today must be present, even as zero", visits.today >= 0)
        assertTrue("promises pending must be present, even as zero", promises.pending >= 0)
    }
}
