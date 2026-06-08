<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="dashboard" />

        <main class="main-content client-dashboard">
            <section v-if="flash.success" class="submission-banner">
                <div class="submission-banner-copy">
                    <span class="submission-banner-tag">Request Received</span>
                    <h2>{{ flash.success }}</h2>
                    <p v-if="submittedRequest">
                        {{ submittedRequest.request_id }} is now in your active requests and has been routed for review.
                    </p>
                    <p v-else>
                        Your request is now in the queue and visible in your active requests.
                    </p>
                </div>

                <div class="submission-banner-actions">
                    <Link
                        v-if="submittedRequest"
                        :href="`/client/request-status/${submittedRequest.id}`"
                        class="btn btn-primary banner-button"
                    >
                        View Request
                    </Link>
                    <Link href="/client/new-request" class="btn btn-secondary banner-button">
                        Submit Another Request
                    </Link>
                </div>
            </section>

            <section class="dashboard-hero">
                <article class="hero-card hero-card-primary">
                    <span class="hero-kicker">Client Workspace</span>
                    <h1>Welcome back, {{ firstName }}.</h1>
                    <p>{{ heroMessage }}</p>

                    <div class="hero-pills">
                        <span class="hero-pill">
                            <i class="fas fa-briefcase"></i>
                            {{ formatNumber(stats.activeRequests) }} active request{{ Number(stats.activeRequests || 0) === 1 ? '' : 's' }}
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-wallet"></i>
                            {{ formatCurrency(stats.totalSpent) }} paid to date
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-check-circle"></i>
                            {{ formatNumber(stats.completedJobs) }} completed job{{ Number(stats.completedJobs || 0) === 1 ? '' : 's' }}
                        </span>
                    </div>
                </article>

                <article class="hero-card hero-card-action">
                    <div class="hero-action-copy">
                        <span class="hero-kicker">Start Something New</span>
                        <h2>Need another service request?</h2>
                        <p>Submit a fresh request and keep all quotations, approvals, payments, and delivery updates in one place.</p>
                    </div>

                    <div class="hero-actions">
                        <Link href="/client/new-request" class="btn btn-primary hero-button">
                            <i class="fas fa-plus"></i>
                            Submit New Request
                        </Link>
                        <Link href="/client/payments" class="btn btn-secondary hero-button secondary-button">
                            <i class="fas fa-credit-card"></i>
                            View Payments
                        </Link>
                    </div>
                </article>
            </section>

            <section class="summary-grid">
                <article class="summary-card tone-blue">
                    <div class="summary-topline">
                        <span class="summary-tag">Live Work</span>
                        <i class="fas fa-tools"></i>
                    </div>
                    <span class="summary-label">Active Requests</span>
                    <strong class="summary-value">{{ formatNumber(stats.activeRequests) }}</strong>
                    <p class="summary-note">Requests still moving through review, payment, assignment, or execution.</p>
                </article>

                <article class="summary-card tone-green">
                    <div class="summary-topline">
                        <span class="summary-tag">History</span>
                        <i class="fas fa-flag-checkered"></i>
                    </div>
                    <span class="summary-label">Completed Jobs</span>
                    <strong class="summary-value">{{ formatNumber(stats.completedJobs) }}</strong>
                    <p class="summary-note">Work that has been delivered and closed out successfully.</p>
                </article>

                <article class="summary-card tone-amber">
                    <div class="summary-topline">
                        <span class="summary-tag">Finance</span>
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <span class="summary-label">Pending Payments</span>
                    <strong class="summary-value">{{ formatNumber(stats.pendingPayments) }}</strong>
                    <p class="summary-note">Requests waiting on payment confirmation before the next step can proceed.</p>
                </article>

                <article class="summary-card tone-slate">
                    <div class="summary-topline">
                        <span class="summary-tag">Spend</span>
                        <i class="fas fa-sack-dollar"></i>
                    </div>
                    <span class="summary-label">Total Paid</span>
                    <strong class="summary-value">{{ formatCurrency(stats.totalSpent) }}</strong>
                    <p class="summary-note">Completed payments across all of your service requests to date.</p>
                </article>
            </section>

            <section class="panel-row">
                <article class="panel-card spotlight-card">
                    <div class="section-heading">
                        <div>
                            <p class="section-kicker">Current Projects</p>
                            <h3>Active Project Status</h3>
                        </div>
                        <span class="section-badge">{{ activeRequests.length }} active</span>
                    </div>

                    <div v-if="activeRequests.length" class="active-grid">
                        <article v-for="request in activeRequests.slice(0, 4)" :key="request.id" class="active-card">
                            <div class="active-card-head">
                                <div>
                                    <strong>{{ request.service_category?.name || 'Service Request' }}</strong>
                                    <span>{{ request.job_reference || request.request_id }}</span>
                                </div>
                                <span :class="['status-badge', statusTone(request.status)]">
                                    {{ formatStatus(request.status, request) }}
                                </span>
                            </div>

                            <div class="active-meta">
                                <span><i class="fas fa-map-marker-alt"></i> {{ request.location || 'Location not provided' }}</span>
                                <span><i class="fas fa-hard-hat"></i> {{ request.technician?.user?.name || 'Technician not assigned yet' }}</span>
                            </div>

                            <p class="active-note">{{ nextStepMessage(request) }}</p>
                        </article>
                    </div>

                    <div v-else class="empty-state compact-empty">
                        <i class="fas fa-inbox"></i>
                        <h3>No active projects yet</h3>
                        <p>Once you submit a request, its approval and delivery progress will appear here.</p>
                    </div>
                </article>

                <article class="panel-card spotlight-card">
                    <div class="section-heading">
                        <div>
                            <p class="section-kicker">At A Glance</p>
                            <h3>What To Expect Next</h3>
                        </div>
                    </div>

                    <div class="timeline-list">
                        <div class="timeline-item">
                            <strong>Request review</strong>
                            <span>Your request is reviewed and prepared for quotation.</span>
                        </div>
                        <div class="timeline-item">
                            <strong>Quotation & approval</strong>
                            <span>You receive pricing and can approve the quotation when ready.</span>
                        </div>
                        <div class="timeline-item">
                            <strong>Payment & assignment</strong>
                            <span>Once payment is confirmed, the job is scheduled and assigned.</span>
                        </div>
                        <div class="timeline-item">
                            <strong>Execution & updates</strong>
                            <span>We keep you updated as work progresses until completion.</span>
                        </div>
                    </div>
                </article>
            </section>

            <section class="panel-card request-table-panel">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Recent Requests</p>
                        <h3>Quotation Requests (RFQ)</h3>
                    </div>
                    <Link href="/client/new-request" class="section-link">Submit new request</Link>
                </div>

                <div v-if="serviceRequests.length" class="requests-table-wrap desktop-requests">
                    <table class="requests-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Quotation</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="request in serviceRequests" :key="request.id">
                                <td>
                                    <div class="entity-block">
                                        <strong>{{ request.service_category?.name || 'Service Request' }}</strong>
                                        <span>{{ request.job_reference || request.request_id }}</span>
                                    </div>
                                </td>
                                <td>{{ formatDate(request.created_at) }}</td>
                                <td>
                                    <span :class="['status-badge', statusTone(request.status)]">
                                        {{ formatStatus(request.status, request) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="entity-block compact">
                                        <strong>{{ quotationHeadline(request) }}</strong>
                                        <span>{{ quotationSubline(request) }}</span>
                                    </div>
                                </td>
                                <td>
                                    <Link :href="`/client/request-status/${request.id}`" class="btn btn-secondary btn-sm">
                                        View Details
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="serviceRequests.length" class="mobile-request-list">
                    <article v-for="request in serviceRequests" :key="`mobile-${request.id}`" class="request-card">
                        <div class="request-card-head">
                            <div class="entity-block">
                                <strong>{{ request.service_category?.name || 'Service Request' }}</strong>
                                <span>{{ request.job_reference || request.request_id }}</span>
                            </div>
                            <span :class="['status-badge', statusTone(request.status)]">
                                {{ formatStatus(request.status, request) }}
                            </span>
                        </div>

                        <div class="request-meta-grid">
                            <div class="meta-chip">
                                <span>Submitted</span>
                                <strong>{{ formatDate(request.created_at) }}</strong>
                            </div>
                            <div class="meta-chip">
                                <span>Quotation</span>
                                <strong>{{ quotationHeadline(request) }}</strong>
                            </div>
                        </div>

                        <Link :href="`/client/request-status/${request.id}`" class="btn btn-secondary btn-sm request-button">
                            View Details
                        </Link>
                    </article>
                </div>

                <div v-if="!serviceRequests.length" class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No service requests yet</h3>
                    <p>Submit your first request to receive a quotation and begin tracking progress here.</p>
                </div>

                <div class="card-footer">
                    <p>After a job is completed, you’ll be able to review the outcome and provide feedback to help us maintain quality standards.</p>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import ClientSidebar from '../../Components/ClientSidebar.vue'

const page = usePage()

const props = defineProps({
    serviceRequests: {
        type: Array,
        default: () => [],
    },
    stats: {
        type: Object,
        default: () => ({}),
    },
})

const firstName = computed(() => {
    const name = page.props.auth?.user?.name || 'Client'
    return name.split(' ')[0] || 'Client'
})

const flash = computed(() => page.props.flash || {})

const submittedRequest = computed(() => flash.value.submittedRequest || null)

const activeRequests = computed(() => {
    return props.serviceRequests.filter((request) =>
        !['completed', 'cancelled', 'closed', 'archived'].includes(request.status)
    )
})

const heroMessage = computed(() => {
    if (activeRequests.value.length > 0) {
        return 'Here’s a simple view of your active requests, quotation progress, and the next steps across delivery and payments.'
    }

    return 'You can use this dashboard to track quotations, payments, and project updates once your service requests are in motion.'
})

/**
 * Display the request status. For "awaiting_payment" we split into two
 * cases (#13): if a payment request has been issued the label reads
 * "Awaiting Payment"; if not, "Awaiting Payment Request from TW".
 */
function formatStatus(status, request) {
    if (status === 'awaiting_payment') {
        const hasPending = Number(request?.pending_payment_requests_count) > 0
        return hasPending ? 'Awaiting Payment' : 'Awaiting Payment Request from TW'
    }
    return (status || 'pending').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function formatQuoteStatus(status) {
    return (status || 'pending').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

/**
 * Quotation status displayed on the Recent Requests cards (#4 / #13).
 *
 * Real quotation data lives inline on the service_request row
 * (rfq_status, quote_amount, quote_revision_count, etc.) — there's no
 * separate Quotation row most of the time, so `latest_quotation` is
 * usually null. Derive the headline/subline from those fields directly.
 */
function quotationHeadline(request) {
    if (request.latest_quotation?.version) {
        return `Quote v${request.latest_quotation.version}`
    }
    const ref = request.job_reference || request.request_id
    const rev = Number(request.quote_revision_count) || 0
    switch (request.rfq_status) {
        case 'quoted':
            return rev > 0 ? `Revised Quote · ${ref}` : `Quoted · ${ref}`
        case 'approved':
            return `Approved · ${ref}`
        case 'rejected':
            return 'Declined'
        case 'pending':
        default:
            return 'Not issued yet'
    }
}

function quotationSubline(request) {
    if (request.latest_quotation?.status) {
        return formatQuoteStatus(request.latest_quotation.status)
    }
    // Once an RFQ is approved, the visible quotation status should reflect
    // what is happening downstream — payment, technician work, completion —
    // rather than stay on "approved" forever (#35).
    if (request.rfq_status === 'approved') {
        switch (request.status) {
            case 'awaiting_payment':
            case 'payment_pending_approval':
                return 'Approved — awaiting payment'
            case 'ready_for_assignment':
                return 'Paid — awaiting technician assignment'
            case 'assigned':
                return 'Approved — technician assigned'
            case 'queued':
            case 'awaiting_tech_availability':
                return 'Approved — queued for execution'
            case 'in_progress':
                return 'Approved — work in progress'
            case 'completed':
            case 'completed_pending_confirmation':
            case 'closed':
            case 'archived':
                return 'Approved — job completed'
            default:
                return 'Approved'
        }
    }
    switch (request.rfq_status) {
        case 'quoted':
            return 'Awaiting your approval'
        case 'rejected':
            return request.rejection_reason ? 'Declined' : 'Declined by client'
        case 'pending':
        default:
            return 'Awaiting quotation'
    }
}

function statusTone(status) {
    const map = {
        pending: 'tone-amber',
        awaiting_quote_generation: 'tone-amber',
        awaiting_quote_approval: 'tone-blue',
        awaiting_payment: 'tone-orange',
        payment_pending_approval: 'tone-orange',
        ready_for_assignment: 'tone-blue',
        assigned: 'tone-green',
        in_progress: 'tone-green',
        completed: 'tone-slate',
        completed_pending_confirmation: 'tone-slate',
        closed: 'tone-slate',
    }

    return map[status] || 'tone-slate'
}

function nextStepMessage(request) {
    if (request.status === 'awaiting_quote_approval') return 'Review your quotation and approve it when you are ready.'
    if (request.status === 'awaiting_payment' || request.status === 'payment_pending_approval') return 'Payment confirmation is the next step before scheduling can continue.'
    if (request.status === 'ready_for_assignment') return 'Your request is approved and being prepared for technician assignment.'
    if (request.status === 'assigned') return 'A technician has been assigned and scheduling is underway.'
    if (request.status === 'in_progress') return 'Work is actively underway and progress updates will continue to appear.'
    return 'Your request is moving through the review process.'
}

function formatDate(date) {
    if (!date) return ''
    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(date))
}

function formatCurrency(value) {
    return new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency: 'KES',
        maximumFractionDigits: 0,
    }).format(Number(value || 0))
}

function formatNumber(value) {
    return new Intl.NumberFormat('en-KE').format(Number(value || 0))
}

defineOptions({
    layout: null,
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

.client-dashboard {
    display: grid;
    gap: 1.4rem;
}

.submission-banner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 1.35rem 1.5rem;
    border-radius: 24px;
    background: linear-gradient(135deg, rgba(220, 252, 231, 0.94), rgba(240, 253, 244, 0.98));
    border: 1px solid rgba(34, 197, 94, 0.16);
    box-shadow: 0 18px 42px rgba(34, 197, 94, 0.08);
}

.submission-banner-copy {
    display: grid;
    gap: 0.35rem;
}

.submission-banner-tag {
    display: inline-flex;
    width: fit-content;
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    background: rgba(21, 128, 61, 0.12);
    color: #166534;
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.submission-banner h2,
.submission-banner p {
    margin: 0;
}

.submission-banner h2 {
    color: #14532d;
    font-size: 1.2rem;
}

.submission-banner p {
    color: #166534;
    max-width: 50rem;
}

.submission-banner-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.banner-button {
    white-space: nowrap;
}

.dashboard-hero,
.summary-grid,
.panel-row {
    display: grid;
    gap: 1rem;
}

.dashboard-hero {
    grid-template-columns: minmax(0, 1.45fr) minmax(0, 0.95fr);
}

.client-dashboard h1,
.client-dashboard h2,
.client-dashboard h3,
.client-dashboard p {
    overflow-wrap: anywhere;
    word-break: break-word;
}

.hero-card,
.summary-card,
.panel-card,
.active-card,
.request-card {
    border-radius: 28px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
}

.hero-card {
    padding: 1.55rem 1.7rem;
}

.hero-card-primary {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 35%),
        linear-gradient(135deg, #ffffff, #eff6ff);
}

.hero-card-action {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    display: grid;
    gap: 1rem;
}

.hero-kicker,
.summary-tag,
.section-kicker {
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #0284c7;
}

.hero-card h1,
.hero-card h2,
.section-heading h3 {
    margin: 0.55rem 0 0;
    color: #0f172a;
}

.hero-card p,
.summary-note,
.active-note,
.timeline-item span,
.entity-block span,
.entity-block small,
.card-footer p,
.empty-state p {
    color: #64748b;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.25rem;
}

.hero-pill,
.status-badge,
.section-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.72rem 0.95rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
    background: rgba(226, 232, 240, 0.8);
    color: #0f172a;
}

.hero-pill.muted,
.section-badge {
    background: #f8fafc;
    color: #475569;
}

.hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.hero-button {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 999px;
}

.secondary-button {
    background: #e2e8f0;
    color: #0f172a;
}

.summary-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    padding: 1.25rem;
    background: #ffffff;
}

.summary-topline,
.section-heading,
.active-card-head,
.request-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.summary-topline i {
    color: #0f172a;
}

.summary-label {
    display: block;
    margin-top: 0.95rem;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.summary-value {
    display: block;
    margin-top: 0.3rem;
    color: #0f172a;
    font-size: 1.55rem;
}

.summary-note {
    margin: 0.7rem 0 0;
    line-height: 1.55;
}

.tone-blue {
    background: linear-gradient(180deg, #eff6ff, #dbeafe);
}

.tone-green {
    background: linear-gradient(180deg, #ecfdf5, #dcfce7);
}

.tone-amber {
    background: linear-gradient(180deg, #fffbeb, #fef3c7);
}

.tone-orange {
    background: rgba(249, 115, 22, 0.14);
    color: #c2410c;
}

.tone-slate {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.panel-row {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.panel-card {
    padding: 1.35rem;
    background: #ffffff;
}

.active-grid,
.timeline-list {
    display: grid;
    gap: 0.9rem;
    margin-top: 1rem;
}

.active-card {
    padding: 1rem;
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.active-card-head strong,
.timeline-item strong,
.entity-block strong,
.meta-chip strong {
    color: #0f172a;
}

.active-meta {
    display: grid;
    gap: 0.4rem;
    margin-top: 0.85rem;
    color: #475569;
    font-size: 0.88rem;
}

.active-meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.active-note {
    margin: 0.85rem 0 0;
    line-height: 1.55;
}

.timeline-item {
    display: grid;
    gap: 0.28rem;
    padding: 0.95rem 1rem;
    border-radius: 18px;
    background: #f8fafc;
}

.request-table-panel {
    overflow: hidden;
}

.section-link {
    color: #0f4c81;
    font-weight: 700;
    text-decoration: none;
}

.requests-table-wrap {
    margin-top: 1rem;
    overflow-x: auto;
}

.requests-table {
    width: 100%;
    min-width: 860px;
    border-collapse: collapse;
}

.requests-table th {
    padding: 0.9rem 1rem;
    text-align: left;
    color: #64748b;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 1px solid rgba(226, 232, 240, 0.9);
}

.requests-table td {
    padding: 1rem;
    vertical-align: top;
    border-bottom: 1px solid rgba(226, 232, 240, 0.84);
}

.entity-block {
    display: grid;
    gap: 0.22rem;
}

.entity-block.compact strong {
    font-size: 0.98rem;
}

.status-badge.tone-blue {
    background: rgba(59, 130, 246, 0.14);
    color: #1d4ed8;
}

.status-badge.tone-green {
    background: rgba(34, 197, 94, 0.14);
    color: #15803d;
}

.status-badge.tone-amber {
    background: rgba(245, 158, 11, 0.15);
    color: #b45309;
}

.status-badge.tone-orange {
    background: rgba(249, 115, 22, 0.14);
    color: #c2410c;
}

.status-badge.tone-slate {
    background: rgba(148, 163, 184, 0.18);
    color: #475569;
}

.mobile-request-list {
    display: none;
    gap: 1rem;
    margin-top: 1rem;
}

.request-card {
    padding: 1rem;
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.request-meta-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin: 1rem 0;
}

.meta-chip {
    display: grid;
    gap: 0.2rem;
    padding: 0.8rem;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.88);
}

.meta-chip span {
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
}

.request-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.card-footer {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(226, 232, 240, 0.9);
}

.empty-state {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    padding: 3rem 1rem 1rem;
    text-align: center;
}

.compact-empty {
    padding: 2rem 1rem 1rem;
}

.empty-state i {
    font-size: 2rem;
    color: #0284c7;
}

.empty-state h3 {
    margin: 0;
    color: #0f172a;
}

@media (max-width: 1100px) {
    .submission-banner {
        flex-direction: column;
        align-items: flex-start;
    }

    .dashboard-hero,
    .summary-grid,
    .panel-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 820px) {
    .desktop-requests {
        display: none;
    }

    .mobile-request-list {
        display: grid;
    }
}

@media (max-width: 640px) {
    .request-meta-grid,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .hero-card,
    .summary-card,
    .panel-card {
        padding: 1rem;
    }
}
</style>
