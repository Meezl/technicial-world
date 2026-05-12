<template>
    <main class="main-content reports-content">
        <section class="reports-hero">
            <div class="hero-copy">
                <span class="hero-kicker">{{ contextLabel }}</span>
                <div class="hero-headline">
                    <h1>Reports &amp; Analytics</h1>
                    <p>Track revenue, collections, delivery progress, and top clients in one clean view.</p>
                </div>
                <div class="hero-pills">
                    <span class="hero-pill">
                        <i class="far fa-calendar-alt"></i>
                        {{ dateRangeLabel }}
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-briefcase"></i>
                        {{ totalJobs }} jobs
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-users"></i>
                        {{ clientCount }} clients
                    </span>
                </div>
            </div>

            <div class="filter-panel">
                <div class="filter-copy">
                    <span class="filter-label">Reporting Window</span>
                    <strong>{{ rangeSummary }}</strong>
                    <p>{{ windowSummary }}</p>
                </div>

                <div class="filter-fields">
                    <label class="filter-field">
                        <span>From</span>
                        <input v-model="fromDate" type="date" class="date-input" />
                    </label>
                    <label class="filter-field">
                        <span>To</span>
                        <input v-model="toDate" type="date" class="date-input" />
                    </label>
                </div>

                <div class="filter-actions">
                    <button @click="applyFilters" class="btn btn-primary btn-sm filter-button">
                        <i class="fas fa-filter"></i>
                        Apply filters
                    </button>
                    <button
                        v-if="hasDateSelection"
                        @click="clearFilters"
                        class="btn btn-secondary btn-sm filter-button filter-button-secondary"
                    >
                        Clear
                    </button>
                </div>
            </div>
        </section>

        <section class="kpi-grid">
            <article class="kpi-card tone-blue">
                <div class="kpi-topline">
                    <span class="kpi-tag">Revenue</span>
                    <div class="kpi-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <span class="kpi-label">Total Quoted</span>
                <span class="kpi-value">{{ formatCurrency(reportData.total_quoted_revenue) }}</span>
                <p class="kpi-footnote">{{ jobCount }} tracked job{{ jobCount === 1 ? '' : 's' }} in the selected period.</p>
            </article>

            <article class="kpi-card tone-green">
                <div class="kpi-topline">
                    <span class="kpi-tag">Cash In</span>
                    <div class="kpi-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
                <span class="kpi-label">Collected</span>
                <span class="kpi-value">{{ formatCurrency(reportData.total_collected) }}</span>
                <p class="kpi-footnote">{{ collectionRate }}% of quoted revenue has been collected.</p>
            </article>

            <article class="kpi-card tone-amber">
                <div class="kpi-topline">
                    <span class="kpi-tag">Open Balance</span>
                    <div class="kpi-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
                <span class="kpi-label">Outstanding</span>
                <span class="kpi-value">{{ formatCurrency(reportData.outstanding) }}</span>
                <p class="kpi-footnote">{{ outstandingShare }}% of quoted revenue is still unpaid.</p>
            </article>

            <article class="kpi-card" :class="`tone-${collectionRateTone}`">
                <div class="kpi-topline">
                    <span class="kpi-tag">Recovery</span>
                    <div class="kpi-icon">
                        <i class="fas fa-percentage"></i>
                    </div>
                </div>
                <span class="kpi-label">Collection Rate</span>
                <span class="kpi-value">{{ collectionRate }}%</span>
                <p class="kpi-footnote">{{ collectionRateMessage }}</p>
            </article>
        </section>

        <section class="insights-grid">
            <article class="panel-card spotlight-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Collections</p>
                        <h3>Revenue Collection</h3>
                    </div>
                    <span class="section-badge">{{ collectionRate }}% recovered</span>
                </div>

                <div class="collection-progress">
                    <div class="collection-progress-track">
                        <div
                            class="collection-progress-fill"
                            :style="{ width: Math.min(collectionRate, 100) + '%' }"
                        ></div>
                    </div>
                    <div class="collection-stats">
                        <div class="stat-pill success">
                            <span class="stat-pill-label">Collected</span>
                            <strong>{{ formatCurrency(reportData.total_collected) }}</strong>
                        </div>
                        <div class="stat-pill warning">
                            <span class="stat-pill-label">Outstanding</span>
                            <strong>{{ formatCurrency(reportData.outstanding) }}</strong>
                        </div>
                    </div>
                </div>
            </article>

            <article class="panel-card overview-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Delivery</p>
                        <h3>Job Summary</h3>
                    </div>
                    <span class="section-badge muted">{{ completedJobRate }}% completed</span>
                </div>

                <div class="summary-grid">
                    <div class="summary-tile">
                        <span class="summary-label">Total Jobs</span>
                        <strong>{{ totalJobs }}</strong>
                    </div>
                    <div class="summary-tile">
                        <span class="summary-label">Completed</span>
                        <strong class="green-text">{{ completedJobs }}</strong>
                    </div>
                    <div class="summary-tile">
                        <span class="summary-label">In Progress</span>
                        <strong class="blue-text">{{ activeJobs }}</strong>
                    </div>
                </div>
            </article>
        </section>

        <section class="report-two-col">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Operations</p>
                        <h3>Job Overview</h3>
                    </div>
                    <span class="section-badge muted">{{ totalJobs }} total</span>
                </div>

                <div v-if="reportData.job_status_breakdown?.length" class="status-bars">
                    <div v-for="item in reportData.job_status_breakdown" :key="item.status" class="status-bar-row">
                        <span class="status-bar-label">{{ formatStatus(item.status) }}</span>
                        <div class="status-bar-track">
                            <div
                                class="status-bar-fill"
                                :style="{ width: getBarWidth(item.count, totalJobs) + '%' }"
                                :class="getBarColor(item.status)"
                            ></div>
                        </div>
                        <span class="status-bar-count">{{ item.count }}</span>
                    </div>
                </div>
                <div v-else class="no-data-small">No jobs in this period.</div>
            </article>

            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Payments</p>
                        <h3>Payment Methods</h3>
                    </div>
                    <span class="section-badge muted">{{ methodCount }} active methods</span>
                </div>

                <div v-if="reportData.by_method?.length" class="method-list">
                    <div v-for="method in reportData.by_method" :key="method.method" class="method-row">
                        <div class="method-icon-wrap" :class="getMethodColor(method.method)">
                            <i :class="getMethodIcon(method.method)"></i>
                        </div>
                        <div class="method-info">
                            <span class="method-name">{{ formatMethod(method.method) }}</span>
                            <span class="method-count">{{ method.count }} payment{{ method.count === 1 ? '' : 's' }}</span>
                        </div>
                        <div class="method-meta">
                            <strong>{{ formatCurrency(method.total) }}</strong>
                            <span>{{ getPercent(method.total, reportData.total_collected) }}% of collections</span>
                        </div>
                    </div>
                </div>
                <div v-else class="no-data-small">No payments in this period.</div>
            </article>
        </section>

        <section class="panel-section">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Breakdown</p>
                        <h3>Revenue by Job</h3>
                    </div>
                    <span class="section-badge muted">{{ jobCount }} jobs</span>
                </div>

                <div v-if="reportData.by_job?.length" class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Job Reference</th>
                                <th>Client</th>
                                <th class="text-right">Quoted</th>
                                <th class="text-right">Collected</th>
                                <th class="text-right">Outstanding</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(job, index) in reportData.by_job" :key="index">
                                <td>
                                    <div class="job-reference">
                                        <strong>{{ job.job_reference }}</strong>
                                        <span>{{ getCollectionPct(job) }}% recovered</span>
                                    </div>
                                </td>
                                <td>{{ job.client }}</td>
                                <td class="text-right">{{ formatCurrency(job.quoted_amount) }}</td>
                                <td class="text-right green-text">{{ formatCurrency(job.collected) }}</td>
                                <td class="text-right" :class="job.outstanding > 0 ? 'amber-text' : 'green-text'">
                                    {{ formatCurrency(job.outstanding) }}
                                </td>
                                <td>
                                    <div class="mini-progress">
                                        <div class="mini-progress-track">
                                            <div
                                                class="mini-progress-fill"
                                                :style="{ width: getCollectionPct(job) + '%' }"
                                                :class="{ complete: getCollectionPct(job) >= 100 }"
                                            ></div>
                                        </div>
                                        <span class="mini-pct">{{ getCollectionPct(job) }}%</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="no-data">
                    <i class="fas fa-chart-bar"></i>
                    <p>No revenue data for this period.</p>
                </div>
            </article>
        </section>

        <section class="panel-section">
            <article class="panel-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Relationships</p>
                        <h3>Top Clients</h3>
                    </div>
                    <span class="section-badge muted">{{ clientCount }} clients</span>
                </div>

                <div v-if="reportData.by_client?.length" class="client-cards-grid">
                    <div v-for="client in reportData.by_client" :key="client.user_id" class="client-card">
                        <div class="client-avatar">
                            {{ getInitials(client.user?.name) }}
                        </div>
                        <div class="client-details">
                            <span class="client-name">{{ client.user?.name || 'N/A' }}</span>
                            <span class="client-email">{{ client.user?.email || 'No email available' }}</span>
                        </div>
                        <div class="client-payment-info">
                            <strong class="client-total">{{ formatCurrency(client.total_paid) }}</strong>
                            <span class="client-count">
                                {{ client.payment_count }} payment{{ client.payment_count === 1 ? '' : 's' }}
                            </span>
                        </div>
                    </div>
                </div>
                <div v-else class="no-data">
                    <i class="fas fa-users"></i>
                    <p>No client payment data for this period.</p>
                </div>
            </article>
        </section>
    </main>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    routePath: { type: String, required: true },
    contextLabel: { type: String, default: 'Revenue Snapshot' },
})

const fromDate = ref(props.filters.from || '')
const toDate = ref(props.filters.to || '')

const reportData = computed(() => props.report || {})
const totalJobs = computed(() => Number(reportData.value.total_jobs || 0))
const completedJobs = computed(() => Number(reportData.value.completed_jobs || 0))
const activeJobs = computed(() => Math.max(totalJobs.value - completedJobs.value, 0))
const jobCount = computed(() => reportData.value.by_job?.length || 0)
const clientCount = computed(() => reportData.value.by_client?.length || 0)
const methodCount = computed(() => reportData.value.by_method?.length || 0)
const collectionRate = computed(() => normalizeNumber(reportData.value.collection_rate))
const completedJobRate = computed(() => getPercent(completedJobs.value, totalJobs.value))
const outstandingShare = computed(() => getPercent(reportData.value.outstanding, reportData.value.total_quoted_revenue))
const hasDateSelection = computed(() => Boolean(fromDate.value || toDate.value))

const collectionRateTone = computed(() => {
    if (collectionRate.value >= 75) return 'green'
    if (collectionRate.value >= 40) return 'amber'
    return 'red'
})

const collectionRateMessage = computed(() => {
    if (collectionRate.value >= 75) return 'Collections are tracking well against quotes.'
    if (collectionRate.value >= 40) return 'There is solid movement, with room to tighten follow-up.'
    return 'Collections are lagging and may need closer attention.'
})

const dateRangeLabel = computed(() => {
    if (!fromDate.value && !toDate.value) return 'All time'
    if (fromDate.value && toDate.value) return `${formatDateShort(fromDate.value)} to ${formatDateShort(toDate.value)}`
    return fromDate.value ? `From ${formatDateShort(fromDate.value)}` : `Until ${formatDateShort(toDate.value)}`
})

const rangeSummary = computed(() => {
    if (fromDate.value && toDate.value) return `${formatDateLong(fromDate.value)} - ${formatDateLong(toDate.value)}`
    if (fromDate.value) return `Starting ${formatDateLong(fromDate.value)}`
    if (toDate.value) return `Up to ${formatDateLong(toDate.value)}`
    return 'Current reporting range'
})

const windowSummary = computed(() => {
    if (!fromDate.value || !toDate.value) return 'Pick a date range to focus the report.'

    const days = getDayCount(fromDate.value, toDate.value)

    if (days <= 1) return 'A one-day snapshot of performance and collections.'
    return `${days} days of activity are included in this report.`
})

const applyFilters = () => {
    router.get(props.routePath, {
        from: fromDate.value || undefined,
        to: toDate.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

const clearFilters = () => {
    fromDate.value = ''
    toDate.value = ''
    router.get(props.routePath, {}, {
        preserveState: true,
        preserveScroll: true,
    })
}

function normalizeNumber(value) {
    return Number(Number(value || 0).toFixed(1))
}

function formatCurrency(amount) {
    if (!amount && amount !== 0) return 'KES 0.00'

    return `KES ${Number(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`
}

function formatDateShort(value) {
    if (!value) return ''

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`))
}

function formatDateLong(value) {
    if (!value) return ''

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${value}T00:00:00`))
}

function getDayCount(start, end) {
    const startDate = new Date(`${start}T00:00:00`)
    const endDate = new Date(`${end}T00:00:00`)
    const milliseconds = endDate.getTime() - startDate.getTime()

    return Math.max(Math.floor(milliseconds / 86400000) + 1, 1)
}

function getPercent(value, total) {
    if (!total) return 0
    return Math.min(Math.round((Number(value || 0) / Number(total || 0)) * 100), 100)
}

function formatStatus(status) {
    if (!status) return ''
    return status.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function formatMethod(method) {
    const labels = {
        mpesa: 'M-Pesa',
        cheque: 'Cheque',
        cash: 'Cash',
        bank_deposit: 'Bank Deposit',
    }

    return labels[method] || method?.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) || 'Other'
}

function getMethodIcon(method) {
    const icons = {
        mpesa: 'fas fa-mobile-alt',
        cheque: 'fas fa-money-check',
        cash: 'fas fa-coins',
        bank_deposit: 'fas fa-university',
    }

    return icons[method] || 'fas fa-credit-card'
}

function getMethodColor(method) {
    const colors = {
        mpesa: 'green',
        cheque: 'blue',
        cash: 'amber',
        bank_deposit: 'slate',
    }

    return colors[method] || 'blue'
}

function getBarWidth(count, total) {
    if (!total) return 0
    return Math.max(Math.round((count / total) * 100), 2)
}

function getBarColor(status) {
    if (['completed', 'closed'].includes(status)) return 'bar-green'
    if (['in_progress', 'assigned', 'queued'].includes(status)) return 'bar-blue'
    if (['cancelled', 'suspended'].includes(status)) return 'bar-red'
    return 'bar-amber'
}

function getCollectionPct(job) {
    if (!job?.quoted_amount || Number(job.quoted_amount) <= 0) return 0
    return Math.min(Math.round((Number(job.collected || 0) / Number(job.quoted_amount)) * 100), 100)
}

function getInitials(name) {
    if (!name) return '?'
    return name.split(' ').map((part) => part[0]).join('').toUpperCase().slice(0, 2)
}
</script>

<style scoped>
.reports-content {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.12), transparent 24rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.reports-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(300px, 0.95fr);
    gap: 1.5rem;
    margin-bottom: 1.75rem;
}

.hero-copy,
.filter-panel {
    border-radius: 24px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    box-shadow: 0 22px 50px rgba(15, 23, 42, 0.06);
}

.hero-copy {
    padding: 2rem;
    background:
        linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.9)),
        #00334b;
    color: #f8fafc;
    position: relative;
    overflow: hidden;
}

.hero-copy::after {
    content: '';
    position: absolute;
    inset: auto -3rem -4.5rem auto;
    width: 12rem;
    height: 12rem;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
}

.hero-kicker,
.section-kicker,
.filter-label {
    display: inline-flex;
    align-items: center;
    font-size: 0.76rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    font-weight: 700;
}

.hero-kicker {
    color: rgba(226, 232, 240, 0.82);
    margin-bottom: 1rem;
}

.hero-headline h1 {
    margin: 0;
    font-size: clamp(2rem, 3vw, 2.6rem);
    color: #ffffff;
}

.hero-headline p {
    margin: 0.75rem 0 0;
    max-width: 38rem;
    color: rgba(226, 232, 240, 0.88);
    line-height: 1.65;
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
    color: #f8fafc;
    font-size: 0.88rem;
    font-weight: 600;
}

.hero-pill.muted {
    background: rgba(15, 23, 42, 0.22);
}

.filter-panel {
    padding: 1.4rem;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(10px);
    display: flex;
    flex-direction: column;
    gap: 1rem;
    align-self: stretch;
}

.filter-copy strong {
    display: block;
    margin-top: 0.35rem;
    color: #0f172a;
    font-size: 1.1rem;
}

.filter-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.5;
    font-size: 0.9rem;
}

.filter-label,
.section-kicker {
    color: #0f6c8f;
}

.filter-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    color: #334155;
    font-size: 0.88rem;
    font-weight: 600;
}

.date-input {
    width: 100%;
    padding: 0.82rem 0.9rem;
    border-radius: 14px;
    border: 1px solid #d7dee7;
    background: #f8fafc;
    color: #0f172a;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.date-input:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.45);
    box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12);
    background: #ffffff;
}

.filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.filter-button {
    justify-content: center;
    min-width: 8rem;
    border-radius: 999px;
    padding-inline: 1rem;
}

.filter-button-secondary {
    margin: 0;
    background: #e2e8f0;
}

.filter-button-secondary:hover {
    background: #cbd5e1;
}

.kpi-grid {
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.kpi-card {
    border-radius: 22px;
    padding: 1.35rem;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.05);
    background: rgba(255, 255, 255, 0.92);
}

.tone-blue {
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.95), #ffffff);
}

.tone-green {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.95), #ffffff);
}

.tone-amber {
    background: linear-gradient(180deg, rgba(255, 251, 235, 0.98), #ffffff);
}

.tone-red {
    background: linear-gradient(180deg, rgba(254, 242, 242, 0.95), #ffffff);
}

.kpi-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.kpi-tag {
    display: inline-flex;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
    color: #33506c;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.kpi-icon {
    width: 2.9rem;
    height: 2.9rem;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: linear-gradient(135deg, #0f6c8f, #0ea5e9);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28);
}

.tone-green .kpi-icon {
    background: linear-gradient(135deg, #0f766e, #16a34a);
}

.tone-amber .kpi-icon {
    background: linear-gradient(135deg, #c2410c, #f59e0b);
}

.tone-red .kpi-icon {
    background: linear-gradient(135deg, #b91c1c, #ef4444);
}

.kpi-label {
    display: block;
    margin-bottom: 0.4rem;
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.kpi-value {
    font-size: clamp(1.4rem, 2vw, 1.85rem);
    line-height: 1.2;
    color: #0f172a;
}

.kpi-footnote {
    margin: 0.75rem 0 0;
    color: #64748b;
    line-height: 1.55;
    font-size: 0.9rem;
}

.insights-grid,
.report-two-col {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.5rem;
}

.insights-grid {
    margin-bottom: 1.5rem;
}

.panel-card {
    border-radius: 24px;
    padding: 1.4rem;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.spotlight-card,
.overview-card {
    min-height: 100%;
}

.section-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.section-heading h3 {
    margin: 0.28rem 0 0;
    color: #0f172a;
    font-size: 1.15rem;
}

.section-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.45rem 0.75rem;
    border-radius: 999px;
    background: #ecfeff;
    color: #0f6c8f;
    font-size: 0.8rem;
    font-weight: 700;
    white-space: nowrap;
}

.section-badge.muted {
    background: #f1f5f9;
    color: #475569;
}

.collection-progress-track,
.status-bar-track,
.mini-progress-track {
    overflow: hidden;
    background: #e2e8f0;
}

.collection-progress-track {
    height: 0.9rem;
    border-radius: 999px;
}

.collection-progress-fill {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #0f766e 0%, #22c55e 100%);
    transition: width 0.45s ease;
}

.collection-stats,
.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
    margin-top: 1rem;
}

.stat-pill,
.summary-tile {
    padding: 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.stat-pill {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.stat-pill.success {
    background: linear-gradient(180deg, #f0fdf4, #ffffff);
}

.stat-pill.warning {
    background: linear-gradient(180deg, #fffbeb, #ffffff);
}

.stat-pill-label,
.summary-label {
    color: #64748b;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.summary-tile strong,
.stat-pill strong {
    color: #0f172a;
    font-size: 1.2rem;
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

.status-bars {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.status-bar-row {
    display: grid;
    grid-template-columns: minmax(110px, 130px) minmax(0, 1fr) 2.2rem;
    align-items: center;
    gap: 0.85rem;
}

.status-bar-label {
    color: #475569;
    font-size: 0.84rem;
    font-weight: 600;
}

.status-bar-track {
    height: 0.6rem;
    border-radius: 999px;
}

.status-bar-fill,
.mini-progress-fill {
    height: 100%;
    border-radius: 999px;
}

.bar-green {
    background: linear-gradient(90deg, #16a34a, #4ade80);
}

.bar-blue {
    background: linear-gradient(90deg, #0f6c8f, #38bdf8);
}

.bar-amber {
    background: linear-gradient(90deg, #d97706, #fbbf24);
}

.bar-red {
    background: linear-gradient(90deg, #dc2626, #f87171);
}

.status-bar-count {
    color: #334155;
    font-size: 0.84rem;
    font-weight: 700;
    text-align: right;
}

.method-list,
.client-cards-grid {
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
}

.method-row,
.client-card {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 1rem;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}

.method-icon-wrap,
.client-avatar {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.method-icon-wrap {
    width: 2.9rem;
    height: 2.9rem;
    border-radius: 16px;
    font-size: 1rem;
}

.method-icon-wrap.green {
    background: #dcfce7;
    color: #15803d;
}

.method-icon-wrap.blue {
    background: #e0f2fe;
    color: #0369a1;
}

.method-icon-wrap.amber {
    background: #fef3c7;
    color: #b45309;
}

.method-icon-wrap.slate {
    background: #e2e8f0;
    color: #475569;
}

.method-info,
.client-details,
.client-payment-info,
.method-meta,
.job-reference {
    display: flex;
    flex-direction: column;
}

.method-info,
.client-details {
    flex: 1;
}

.method-name,
.client-name {
    color: #0f172a;
    font-size: 0.95rem;
    font-weight: 700;
}

.method-count,
.client-email,
.client-count,
.method-meta span,
.job-reference span,
.mini-pct {
    color: #64748b;
    font-size: 0.8rem;
}

.method-meta {
    align-items: flex-end;
    text-align: right;
}

.report-table-wrap {
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.report-table th,
.report-table td {
    padding: 0.95rem 1rem;
    vertical-align: middle;
}

.report-table th {
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    border-bottom: 1px solid #dbe4ee;
}

.report-table td {
    color: #334155;
    font-size: 0.9rem;
    border-bottom: 1px solid #edf2f7;
}

.report-table tbody tr:hover {
    background: rgba(241, 245, 249, 0.72);
}

.text-right {
    text-align: right;
}

.job-reference strong {
    color: #0f172a;
}

.mini-progress {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.mini-progress-track {
    width: 5.75rem;
    height: 0.45rem;
    border-radius: 999px;
}

.mini-progress-fill {
    background: linear-gradient(90deg, #0f6c8f, #38bdf8);
}

.mini-progress-fill.complete {
    background: linear-gradient(90deg, #15803d, #4ade80);
}

.client-avatar {
    width: 3rem;
    height: 3rem;
    border-radius: 50%;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
    color: #ffffff;
    font-size: 0.9rem;
    font-weight: 800;
}

.client-payment-info {
    align-items: flex-end;
    text-align: right;
}

.client-total {
    color: #0f172a;
    font-size: 1rem;
}

.no-data,
.no-data-small {
    text-align: center;
    color: #64748b;
}

.no-data {
    padding: 2.6rem 1.5rem;
}

.no-data i {
    display: block;
    margin-bottom: 0.8rem;
    font-size: 2rem;
    color: #94a3b8;
}

.no-data-small {
    padding: 1.75rem 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px dashed #d7dee7;
}

@media (max-width: 1100px) {
    .reports-hero,
    .insights-grid,
    .report-two-col {
        grid-template-columns: 1fr;
    }

    .filter-panel {
        max-width: 32rem;
    }
}

@media (max-width: 720px) {
    .reports-content {
        padding: 1.25rem;
    }

    .hero-copy,
    .filter-panel,
    .panel-card,
    .kpi-card {
        border-radius: 20px;
    }

    .hero-copy {
        padding: 1.5rem;
    }

    .filter-fields,
    .collection-stats,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .report-table th,
    .report-table td {
        padding-inline: 0.75rem;
    }

    .status-bar-row {
        grid-template-columns: 1fr;
    }

    .status-bar-count {
        text-align: left;
    }

    .method-row,
    .client-card {
        align-items: flex-start;
    }

    .method-meta,
    .client-payment-info {
        align-items: flex-start;
        text-align: left;
    }
}
</style>
