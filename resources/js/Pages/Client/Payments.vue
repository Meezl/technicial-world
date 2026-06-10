<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="payments" />

        <main class="main-content payments-content">
            <section class="payments-hero">
                <div class="hero-copy">
                    <span class="hero-kicker">Client Finance</span>
                    <h1>Payments &amp; Statements</h1>
                    <p>See what has been quoted, what is already paid, and what still needs your attention in one simple view.</p>
                    <div class="hero-pills">
                        <span class="hero-pill">
                            <i class="far fa-calendar-alt"></i>
                            {{ dateRangeLabel }}
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-briefcase"></i>
                            {{ jobCount }} jobs
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-bell"></i>
                            {{ pendingRequestCount }} pending request{{ pendingRequestCount === 1 ? '' : 's' }}
                        </span>
                    </div>
                </div>

                <div class="filter-panel">
                    <div class="filter-copy">
                        <span class="section-kicker">Filters</span>
                        <h3>Adjust your payment view</h3>
                        <p>{{ rangeSummary }}</p>
                    </div>

                    <div class="filter-grid">
                        <label class="filter-field">
                            <span>From</span>
                            <input type="date" v-model="fromDate" class="date-input" />
                        </label>
                        <label class="filter-field">
                            <span>To</span>
                            <input type="date" v-model="toDate" class="date-input" />
                        </label>
                        <label class="filter-field filter-field-full">
                            <span>Service Request</span>
                            <select v-model="selectedSR" class="status-filter">
                                <option value="">All Service Requests</option>
                                <option v-for="sr in allServiceRequests" :key="sr.id" :value="sr.id">
                                    {{ sr.job_reference || sr.request_id }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div v-if="selectedServiceRequestLabel" class="filter-chip-row">
                        <span class="filter-chip">{{ selectedServiceRequestLabel }}</span>
                    </div>

                    <div class="filter-actions">
                        <button @click="applyFilters" class="btn btn-primary btn-sm filter-button">
                            <i class="fas fa-filter"></i>
                            Apply filters
                        </button>
                        <button v-if="hasActiveFilters" @click="clearFilters" class="btn btn-secondary btn-sm filter-button filter-button-secondary">
                            Clear
                        </button>
                    </div>
                </div>
            </section>

            <section class="payment-kpi-grid">
                <article class="payment-kpi-card tone-blue">
                    <div class="payment-kpi-topline">
                        <span class="payment-kpi-tag">Quoted</span>
                        <span class="payment-kpi-icon"><i class="fas fa-file-invoice"></i></span>
                    </div>
                    <h4>Total Quoted</h4>
                    <p class="payment-kpi-value">{{ formatCurrency(summary.total_quoted) }}</p>
                    <span class="payment-kpi-footnote">Across {{ jobCount }} tracked job{{ jobCount === 1 ? '' : 's' }}.</span>
                </article>

                <article class="payment-kpi-card tone-green">
                    <div class="payment-kpi-topline">
                        <span class="payment-kpi-tag">Paid</span>
                        <span class="payment-kpi-icon"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <h4>Total Paid</h4>
                    <p class="payment-kpi-value">{{ formatCurrency(summary.total_paid) }}</p>
                    <span class="payment-kpi-footnote">{{ portfolioPaidPct }}% of the quoted value has been paid.</span>
                </article>

                <article class="payment-kpi-card tone-amber">
                    <div class="payment-kpi-topline">
                        <span class="payment-kpi-tag">Pending</span>
                        <span class="payment-kpi-icon"><i class="fas fa-clock"></i></span>
                    </div>
                    <h4>Pending Payments</h4>
                    <p class="payment-kpi-value">{{ formatCurrency(summary.total_pending) }}</p>
                    <span class="payment-kpi-footnote">{{ pendingRequestCount }} request{{ pendingRequestCount === 1 ? '' : 's' }} are still awaiting action.</span>
                </article>

                <article class="payment-kpi-card tone-slate">
                    <div class="payment-kpi-topline">
                        <span class="payment-kpi-tag">Balance</span>
                        <span class="payment-kpi-icon"><i class="fas fa-balance-scale"></i></span>
                    </div>
                    <h4>Outstanding Balance</h4>
                    <p class="payment-kpi-value">{{ formatCurrency(summary.total_balance) }}</p>
                    <span class="payment-kpi-footnote">Remaining balance across the selected jobs.</span>
                </article>
            </section>

            <section v-if="paymentsByJob.length > 0" class="jobs-list">
                <article v-for="job in paymentsByJob" :key="job.id" class="panel-card payment-job-card">
                    <div class="job-card-header">
                        <div class="job-header-main">
                            <span class="section-kicker">Job Overview</span>
                            <h3>{{ job.job_reference }}</h3>
                            <div class="job-meta-row">
                                <span class="service-label">{{ job.service_name }}</span>
                                <span :class="['status-badge', getJobStatusClass(job.status)]">
                                    {{ formatJobStatus(job.status, job) }}
                                </span>
                            </div>
                        </div>

                        <div class="job-header-side">
                            <span class="job-header-label">Quoted Value</span>
                            <strong>{{ formatCurrency(job.quote_amount) }}</strong>
                        </div>
                    </div>

                    <div class="job-summary-grid">
                        <div class="job-summary-tile">
                            <span class="job-summary-label">Paid</span>
                            <strong class="green-text">{{ formatCurrency(job.total_paid) }}</strong>
                        </div>
                        <div class="job-summary-tile">
                            <span class="job-summary-label">Pending</span>
                            <strong class="amber-text">{{ formatCurrency(job.total_pending) }}</strong>
                        </div>
                        <div class="job-summary-tile">
                            <span class="job-summary-label">Balance</span>
                            <strong>{{ formatCurrency(job.balance) }}</strong>
                        </div>
                        <div class="job-summary-tile">
                            <span class="job-summary-label">Completion</span>
                            <strong class="blue-text">{{ job.percentage_paid }}%</strong>
                        </div>
                    </div>

                    <div class="payment-progress" v-if="job.quote_amount > 0">
                        <div class="progress-info">
                            <span>Payment Progress</span>
                            <span class="progress-percent">{{ job.percentage_paid }}%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div
                                class="progress-bar-fill"
                                :style="{ width: Math.min(job.percentage_paid, 100) + '%' }"
                                :class="{ complete: job.percentage_paid >= 100 }"
                            ></div>
                        </div>
                        <div class="progress-amounts">
                            <span>Paid: {{ formatCurrency(job.total_paid) }}</span>
                            <span v-if="job.balance > 0">Balance: {{ formatCurrency(job.balance) }}</span>
                            <span v-else class="fully-paid">Fully Paid</span>
                        </div>
                    </div>

                    <div v-if="job.milestones && job.milestones.length > 0" class="milestones-section">
                        <div class="section-header">
                            <h4><i class="fas fa-flag-checkered"></i> Payment Milestones</h4>
                            <span class="section-count">{{ job.milestones.length }} milestones</span>
                        </div>
                        <div class="milestones-list">
                            <div v-for="milestone in job.milestones" :key="milestone.id" class="milestone-row">
                                <div class="milestone-info">
                                    <span class="milestone-step">Step {{ milestone.progress_step }}%</span>
                                    <span class="milestone-notes" v-if="milestone.notes">{{ milestone.notes }}</span>
                                </div>
                                <div class="milestone-meta">
                                    <strong class="milestone-amount">{{ formatCurrency(milestone.amount) }}</strong>
                                    <span :class="['status-badge', getMilestoneStatusClass(milestone.status)]">
                                        {{ formatMilestoneStatus(milestone.status) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="getPendingRequests(job).length > 0" class="pending-requests-section">
                        <div class="section-header">
                            <h4><i class="fas fa-exclamation-circle"></i> Action Required</h4>
                            <span class="section-count">{{ getPendingRequests(job).length }} request{{ getPendingRequests(job).length === 1 ? '' : 's' }}</span>
                        </div>
                        <div v-for="pr in getPendingRequests(job)" :key="pr.id" class="pending-request-card">
                            <div class="pr-info">
                                <strong>{{ formatCurrency(pr.amount) }}</strong>
                                <span v-if="pr.percentage > 0" class="pr-percent">{{ pr.percentage }}% milestone</span>
                                <span class="pr-date">Requested {{ formatDate(pr.created_at) }}</span>
                            </div>
                            <span class="status-badge review">Awaiting Payment</span>
                        </div>
                    </div>

                    <div class="transactions-section">
                        <div class="section-header">
                            <h4><i class="fas fa-history"></i> Transaction History</h4>
                            <span class="section-count">{{ job.payment_requests.length }} record{{ job.payment_requests.length === 1 ? '' : 's' }}</span>
                        </div>

                        <div class="table-shell" v-if="job.payment_requests.length > 0">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Amount (KES)</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="pr in job.payment_requests" :key="pr.id">
                                        <td>{{ formatDate(pr.paid_at || pr.created_at) }}</td>
                                        <td>
                                            {{ pr.payment_request_id }}
                                            <span v-if="pr.mpesa_receipt_number" class="receipt-ref">
                                                M-Pesa: {{ pr.mpesa_receipt_number }}
                                            </span>
                                            <span v-if="pr.cheque_number" class="receipt-ref">
                                                Cheque: {{ pr.cheque_number }}
                                            </span>
                                            <span v-if="pr.bank_reference" class="receipt-ref">
                                                Ref: {{ pr.bank_reference }}
                                            </span>
                                        </td>
                                        <td><strong>{{ formatCurrency(pr.amount) }}</strong></td>
                                        <td>
                                            <span class="method-badge" v-if="inferredMethod(pr)">
                                                {{ formatMethod(inferredMethod(pr)) }}
                                            </span>
                                            <span v-else class="method-badge pending-method">Not recorded</span>
                                        </td>
                                        <td>
                                            <span :class="['status-badge', getPaymentStatusClass(pr.status)]">
                                                {{ formatPaymentStatus(pr.status) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="no-transactions">No transactions recorded yet.</p>
                    </div>
                </article>
            </section>

            <section v-else class="panel-section">
                <div class="panel-card no-data-card">
                    <div class="no-data">
                        <i class="fas fa-receipt"></i>
                        <p>No payment records found.</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import ClientSidebar from '../../Components/ClientSidebar.vue'

const props = defineProps({
    paymentsByJob: { type: Array, default: () => [] },
    summary: {
        type: Object,
        default: () => ({
            total_quoted: 0,
            total_paid: 0,
            total_pending: 0,
            total_balance: 0,
            job_count: 0,
        }),
    },
    allServiceRequests: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
})

const fromDate = ref(props.filters.from || '')
const toDate = ref(props.filters.to || '')
const selectedSR = ref(props.filters.service_request_id || '')

const jobCount = computed(() => props.summary.job_count || props.paymentsByJob.length || 0)
const pendingRequestCount = computed(() =>
    props.paymentsByJob.reduce((sum, job) => sum + getPendingRequests(job).length, 0)
)
const portfolioPaidPct = computed(() => {
    if (!props.summary.total_quoted) return 0
    return Math.min(Math.round((props.summary.total_paid / props.summary.total_quoted) * 100), 100)
})
const hasActiveFilters = computed(() => Boolean(fromDate.value || toDate.value || selectedSR.value))
const selectedServiceRequestLabel = computed(() => {
    if (!selectedSR.value) return ''

    const match = props.allServiceRequests.find(sr => String(sr.id) === String(selectedSR.value))
    return match ? `Service Request: ${match.job_reference || match.request_id}` : 'Selected service request'
})
const dateRangeLabel = computed(() => {
    if (!fromDate.value && !toDate.value) return 'All dates'
    if (fromDate.value && toDate.value) return `${formatDate(fromDate.value)} to ${formatDate(toDate.value)}`
    return fromDate.value ? `From ${formatDate(fromDate.value)}` : `Until ${formatDate(toDate.value)}`
})
const rangeSummary = computed(() => {
    if (selectedServiceRequestLabel.value && (fromDate.value || toDate.value)) {
        return `${selectedServiceRequestLabel.value} within ${dateRangeLabel.value}.`
    }
    if (selectedServiceRequestLabel.value) return `${selectedServiceRequestLabel.value} across all recorded dates.`
    if (fromDate.value || toDate.value) return `${dateRangeLabel.value}.`
    return 'Choose a date range or a service request to narrow the records below.'
})

const applyFilters = () => {
    router.get('/client/payments', {
        from: fromDate.value || undefined,
        to: toDate.value || undefined,
        service_request_id: selectedSR.value || undefined,
    }, { preserveState: true, preserveScroll: true })
}

const clearFilters = () => {
    fromDate.value = ''
    toDate.value = ''
    selectedSR.value = ''
    router.get('/client/payments', {}, { preserveState: true, preserveScroll: true })
}

const getPendingRequests = (job) => {
    return job.payment_requests.filter(pr => pr.status === 'pending' && !pr.payment_method)
}

const formatCurrency = (amount) => {
    if (!amount && amount !== 0) return 'KES 0.00'
    return 'KES ' + Number(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('en-KE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}

const formatMethod = (method) => {
    if (!method) return 'N/A'
    const methods = {
        mpesa: 'M-Pesa',
        cheque: 'Cheque',
        cash: 'Cash',
        bank_deposit: 'Bank Deposit',
    }
    return methods[method] || method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

// Some legacy paid payment requests have `payment_method` null but still carry the
// underlying receipt fields. Infer the method so the client always sees how a
// validated payment was received.
const inferredMethod = (pr) => {
    if (!pr) return null
    if (pr.payment_method) return pr.payment_method
    if (pr.mpesa_receipt_number) return 'mpesa'
    if (pr.cheque_number) return 'cheque'
    if (pr.bank_reference) return 'bank_deposit'
    return null
}

const formatPaymentStatus = (status) => {
    // "completed" and "paid" represent the same end state in the payments flow;
    // unify them so the client sees a single Pending → Paid transition.
    const map = {
        pending: 'Pending',
        paid: 'Paid',
        completed: 'Paid',
        cancelled: 'Cancelled',
        failed: 'Failed',
    }
    return map[status] || status?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || ''
}

const getPaymentStatusClass = (status) => {
    if (status === 'paid' || status === 'completed') return 'approved'
    if (status === 'pending') return 'review'
    if (status === 'cancelled' || status === 'failed') return 'rejected'
    return 'pending'
}

const formatJobStatus = (status, job) => {
    if (!status) return ''
    // #13 — split awaiting_payment by whether a pending payment request exists.
    if (status === 'awaiting_payment' && job) {
        const hasPending = (job.payment_requests || []).some(pr => pr.status === 'pending')
        return hasPending ? 'Awaiting Payment' : 'Awaiting Payment Request from TW'
    }
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getJobStatusClass = (status) => {
    if (['completed', 'closed'].includes(status)) return 'approved'
    if (['in_progress', 'assigned'].includes(status)) return 'in-progress'
    if (['cancelled', 'suspended'].includes(status)) return 'rejected'
    return 'review'
}

const formatMilestoneStatus = (status) => {
    const map = {
        pending: 'Pending',
        invoiced: 'Invoiced',
        paid: 'Paid',
        completed: 'Paid',
    }
    return map[status] || status?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || 'Pending'
}

const getMilestoneStatusClass = (status) => {
    if (status === 'paid' || status === 'completed') return 'approved'
    if (status === 'invoiced') return 'review'
    return 'pending'
}

defineOptions({ layout: null })
</script>

<style>

.payments-content {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.1), transparent 24rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.payments-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.75rem;
}

.hero-copy,
.filter-panel,
.payment-kpi-card,
.payment-job-card,
.no-data-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.hero-copy {
    padding: 2rem;
    border-radius: 26px;
    background:
        linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92)),
        #00334b;
    color: #f8fafc;
}

.hero-kicker,
.section-kicker {
    display: inline-flex;
    align-items: center;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.hero-kicker {
    color: rgba(226, 232, 240, 0.82);
}

.hero-copy h1 {
    margin: 0.9rem 0 0;
    color: #ffffff;
    font-size: clamp(2rem, 3vw, 2.5rem);
}

.hero-copy p {
    margin: 0.9rem 0 0;
    color: rgba(226, 232, 240, 0.88);
    max-width: 38rem;
    line-height: 1.6;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.hero-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.75rem 1rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
    font-size: 0.88rem;
    font-weight: 600;
    color: #f8fafc;
}

.hero-pill.muted {
    background: rgba(15, 23, 42, 0.22);
}

.filter-panel {
    padding: 1.4rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.92);
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.section-kicker {
    color: #0f6c8f;
}

.filter-copy h3 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.filter-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.55;
    font-size: 0.92rem;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    font-size: 0.88rem;
    font-weight: 600;
    color: #334155;
}

.filter-field-full {
    grid-column: 1 / -1;
}

.date-input,
.status-filter {
    width: 100%;
    padding: 0.82rem 0.9rem;
    border-radius: 14px;
    border: 1px solid #d7dee7;
    background: #f8fafc;
    color: #0f172a;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.date-input:focus,
.status-filter:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.45);
    box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12);
    background: #ffffff;
}

.filter-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
}

.filter-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 0.8rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0f6c8f;
    font-size: 0.82rem;
    font-weight: 700;
}

.filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.filter-button {
    min-width: 8rem;
    justify-content: center;
    border-radius: 999px;
}

.filter-button-secondary {
    margin: 0;
    background: #e2e8f0;
}

.filter-button-secondary:hover {
    background: #cbd5e1;
}

.payment-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.payment-kpi-card {
    padding: 1.35rem;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.92);
}

.tone-blue {
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.95), #ffffff);
}

.tone-green {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.96), #ffffff);
}

.tone-amber {
    background: linear-gradient(180deg, rgba(255, 251, 235, 0.98), #ffffff);
}

.tone-slate {
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), #ffffff);
}

.payment-kpi-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.95rem;
}

.payment-kpi-tag {
    display: inline-flex;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
    color: #475569;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.payment-kpi-icon {
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
}

.tone-green .payment-kpi-icon {
    background: linear-gradient(135deg, #0f766e, #22c55e);
}

.tone-amber .payment-kpi-icon {
    background: linear-gradient(135deg, #c2410c, #f59e0b);
}

.tone-slate .payment-kpi-icon {
    background: linear-gradient(135deg, #475569, #94a3b8);
}

.payment-kpi-card h4 {
    margin: 0;
    color: #475569;
    font-size: 0.92rem;
}

.payment-kpi-value {
    margin: 0.45rem 0 0;
    font-size: 1.65rem;
    font-weight: 800;
    color: #0f172a;
}

.payment-kpi-footnote {
    display: block;
    margin-top: 0.6rem;
    color: #64748b;
    font-size: 0.84rem;
    line-height: 1.45;
}

.jobs-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.payment-job-card,
.no-data-card {
    border-radius: 24px;
    padding: 1.4rem;
    background: rgba(255, 255, 255, 0.92);
}

.job-card-header,
.section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.job-card-header {
    margin-bottom: 1.25rem;
}

.job-header-main h3 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.job-meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-top: 0.8rem;
}

.service-label {
    background: #eef2ff;
    color: #334155;
    padding: 0.35rem 0.75rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
}

.job-header-side {
    text-align: right;
}

.job-header-label,
.job-summary-label,
.section-count {
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.job-header-side strong {
    display: block;
    margin-top: 0.35rem;
    font-size: 1.2rem;
    color: #0f172a;
}

.job-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.85rem;
    margin-bottom: 1.2rem;
}

.job-summary-tile {
    padding: 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.job-summary-tile strong {
    display: block;
    margin-top: 0.35rem;
    font-size: 1.12rem;
    color: #0f172a;
}

.green-text {
    color: #15803d;
}

.blue-text {
    color: #0f6c8f;
}

.amber-text {
    color: #c2410c;
}

.payment-progress {
    margin: 1.25rem 0;
    padding: 1rem;
    background: #f8fafc;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
}

.progress-info,
.progress-amounts {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
}

.progress-info {
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: #334155;
}

.progress-percent {
    color: #0f6c8f;
}

.progress-bar-container {
    width: 100%;
    height: 0.85rem;
    background: #e2e8f0;
    border-radius: 999px;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #0f6c8f, #38bdf8);
    border-radius: 999px;
    transition: width 0.5s ease;
}

.progress-bar-fill.complete {
    background: linear-gradient(90deg, #15803d, #4ade80);
}

.progress-amounts {
    margin-top: 0.65rem;
    font-size: 0.84rem;
    color: #64748b;
}

.fully-paid {
    color: #15803d;
    font-weight: 700;
}

.milestones-section,
.pending-requests-section,
.transactions-section {
    margin-top: 1.35rem;
    padding-top: 1.1rem;
    border-top: 1px solid #e2e8f0;
}

.section-header h4 {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
}

.milestones-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    margin-top: 0.9rem;
}

.milestone-row,
.pending-request-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1rem;
    border-radius: 18px;
}

.milestone-row {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.milestone-info,
.pr-info,
.milestone-meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.milestone-meta {
    justify-content: flex-end;
}

.milestone-step {
    font-weight: 700;
    color: #0f172a;
}

.milestone-notes,
.pr-percent,
.pr-date,
.receipt-ref {
    color: #64748b;
    font-size: 0.82rem;
}

.milestone-amount {
    color: #0f172a;
}

.pending-request-card {
    margin-top: 0.9rem;
    background: #fff7ed;
    border: 1px solid #fed7aa;
}

.table-shell {
    margin-top: 0.9rem;
    overflow: hidden;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 0.95rem 1rem;
    border-bottom: 1px solid #edf2f7;
    vertical-align: middle;
}

.data-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.data-table tbody tr:hover {
    background: rgba(248, 250, 252, 0.9);
}

.receipt-ref {
    display: block;
    margin-top: 0.25rem;
}

.no-transactions {
    text-align: center;
    color: #64748b;
    font-style: italic;
    padding: 1rem 0;
}

.method-badge,
.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.38rem 0.7rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.method-badge {
    background: #eef2ff;
    color: #334155;
}

.pending-method {
    color: #94a3b8;
    font-style: italic;
}

.status-badge.approved {
    background: #dcfce7;
    color: #166534;
}

.status-badge.review,
.status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.in-progress {
    background: #dbeafe;
    color: #1e40af;
}

.no-data {
    text-align: center;
    padding: 3rem 1rem;
    color: #64748b;
}

.no-data i {
    display: block;
    margin-bottom: 1rem;
    font-size: 2.5rem;
    color: #94a3b8;
}

@media (max-width: 1024px) {
    .payments-hero,
    .payment-kpi-grid,
    .job-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .payments-hero,
    .payment-kpi-grid,
    .filter-grid,
    .job-summary-grid {
        grid-template-columns: 1fr;
    }

    .job-card-header,
    .section-header,
    .milestone-row,
    .pending-request-card,
    .progress-info,
    .progress-amounts {
        flex-direction: column;
        align-items: flex-start;
    }

    .job-header-side {
        text-align: left;
    }
}
</style>
