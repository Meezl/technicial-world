<template>
    <main class="main-content revenue-breakdown-page">
        <section class="revenue-hero">
            <div class="hero-copy">
                <span class="hero-kicker">{{ contextLabel }}</span>
                <div class="hero-headline">
                    <h1>{{ pageTitle }}</h1>
                    <p>{{ pageDescription }}</p>
                </div>
                <div class="hero-pills">
                    <span class="hero-pill">
                        <i class="far fa-calendar-alt"></i>
                        {{ dateRangeLabel }}
                    </span>
                    <span class="hero-pill muted">
                        <i :class="countPillIcon"></i>
                        {{ countPillText }}
                    </span>
                    <span v-if="leaderPillText" class="hero-pill muted">
                        <i class="fas fa-trophy"></i>
                        {{ leaderPillText }}
                    </span>
                </div>
            </div>

            <div class="filter-panel">
                <div class="filter-copy">
                    <span class="filter-label">Report Window</span>
                    <strong>{{ rangeSummary }}</strong>
                    <p>{{ rangeCaption }}</p>
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
                    <button @click="exportReport('pdf')" class="btn btn-secondary btn-sm filter-button filter-button-secondary">
                        <i class="fas fa-file-pdf"></i>
                        Export PDF
                    </button>
                    <button @click="exportReport('excel')" class="btn btn-secondary btn-sm filter-button filter-button-secondary">
                        <i class="fas fa-file-excel"></i>
                        Export Excel
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

        <section class="summary-grid">
            <article v-for="card in summaryCards" :key="card.label" class="summary-card" :class="card.tone">
                <div class="summary-card-top">
                    <span class="summary-tag">{{ card.tag }}</span>
                    <div class="summary-icon">
                        <i :class="card.icon"></i>
                    </div>
                </div>
                <span class="summary-label">{{ card.label }}</span>
                <strong class="summary-value">{{ card.value }}</strong>
                <p class="summary-note">{{ card.note }}</p>
            </article>
        </section>

        <section class="spotlight-grid">
            <article v-for="card in spotlightCards" :key="card.title" class="panel-card spotlight-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">{{ card.kicker }}</p>
                        <h3>{{ card.title }}</h3>
                    </div>
                    <span class="section-badge" :class="card.badgeTone">{{ card.badge }}</span>
                </div>

                <div v-if="card.item" class="spotlight-body">
                    <div class="spotlight-main">
                        <strong>{{ card.item.title }}</strong>
                        <span>{{ card.item.subtitle }}</span>
                    </div>

                    <div class="spotlight-metrics">
                        <div class="metric-chip">
                            <span>{{ card.primaryLabel }}</span>
                            <strong>{{ card.primaryValue }}</strong>
                        </div>
                        <div class="metric-chip muted">
                            <span>{{ card.secondaryLabel }}</span>
                            <strong>{{ card.secondaryValue }}</strong>
                        </div>
                    </div>
                </div>

                <div v-else class="no-data-small">No matching activity in this period.</div>
            </article>
        </section>

        <section class="panel-card">
            <div class="section-heading">
                <div>
                    <p class="section-kicker">{{ tableKicker }}</p>
                    <h3>{{ tableTitle }}</h3>
                </div>
                <span class="section-badge muted">{{ rows.length }} {{ rows.length === 1 ? tableUnit : tableUnitPlural }}</span>
            </div>

            <div v-if="rows.length" class="report-table-wrap desktop-report">
                <table class="report-table">
                    <thead>
                        <tr v-if="isRfqVariant">
                            <th>RFQ / Job</th>
                            <th>Client</th>
                            <th class="text-right">Gross Quote</th>
                            <th class="text-right">Collected in Window</th>
                            <th class="text-right">Collected to Date</th>
                            <th class="text-right">Outstanding</th>
                            <th>Recovery</th>
                        </tr>
                        <tr v-else>
                            <th>Client</th>
                            <th class="text-right">RFQs</th>
                            <th class="text-right">Gross Business</th>
                            <th class="text-right">Collected in Window</th>
                            <th class="text-right">Collected to Date</th>
                            <th class="text-right">Outstanding</th>
                            <th>Business Quality</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="rowKey(row)">
                            <template v-if="isRfqVariant">
                                <td>
                                    <div class="entity-block">
                                        <strong>{{ row.job_reference }}</strong>
                                        <span>{{ row.request_id }}</span>
                                        <small class="inline-note">{{ row.service_name || 'General service' }}</small>
                                        <small class="status-pill" :class="statusTone(row.status)">{{ formatStatus(row.status) }}</small>
                                    </div>
                                </td>
                                <td>
                                    <div class="entity-block compact">
                                        <strong>{{ row.client_name }}</strong>
                                        <span>{{ row.client_email || 'No email available' }}</span>
                                        <small class="inline-note">{{ row.submission_mode_label }} • {{ row.quote_label }}</small>
                                        <small v-if="row.created_by_admin_name" class="inline-note">Created by {{ row.created_by_admin_name }}</small>
                                        <small v-if="row.proxy_quote_approved_by_name" class="inline-note">Proxy approved by {{ row.proxy_quote_approved_by_name }}</small>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="amount-cell">
                                        <strong>{{ formatCurrency(row.gross_quoted_amount) }}</strong>
                                        <span>{{ row.approved_quote_date ? `Approved ${formatDate(row.approved_quote_date)}` : 'Awaiting approval' }}</span>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="amount-cell positive">
                                        <strong>{{ formatCurrency(row.collected_in_period) }}</strong>
                                        <span>{{ row.payment_count_in_period }} payment{{ row.payment_count_in_period === 1 ? '' : 's' }}</span>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="amount-cell">
                                        <strong>{{ formatCurrency(row.total_collected) }}</strong>
                                        <span>{{ row.latest_payment_date ? `Last ${formatDate(row.latest_payment_date)}` : 'No payments yet' }}</span>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <strong :class="row.outstanding_total > 0 ? 'amber-text' : 'green-text'">
                                        {{ formatCurrency(row.outstanding_total) }}
                                    </strong>
                                </td>
                                <td>
                                    <div class="progress-cell">
                                        <div class="mini-progress-track">
                                            <div
                                                class="mini-progress-fill"
                                                :style="{ width: `${progressWidth(row.total_collected, row.gross_quoted_amount)}%` }"
                                                :class="{ complete: progressWidth(row.total_collected, row.gross_quoted_amount) >= 100 }"
                                            ></div>
                                        </div>
                                        <span class="mini-pct">{{ progressLabel(row.total_collected, row.gross_quoted_amount) }}</span>
                                    </div>
                                </td>
                            </template>

                            <template v-else>
                                <td>
                                    <div class="entity-block">
                                        <strong>{{ row.client_name }}</strong>
                                        <span>{{ row.client_email || 'No email available' }}</span>
                                        <small class="inline-note">{{ row.latest_payment_date ? `Last payment ${formatDate(row.latest_payment_date)}` : 'No recent payment date' }}</small>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="amount-cell">
                                        <strong>{{ row.rfq_count }}</strong>
                                        <span>{{ row.payment_count_in_period }} payment{{ row.payment_count_in_period === 1 ? '' : 's' }} in range</span>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <strong>{{ formatCurrency(row.gross_quoted_amount) }}</strong>
                                </td>
                                <td class="text-right">
                                    <strong class="green-text">{{ formatCurrency(row.collected_in_period) }}</strong>
                                </td>
                                <td class="text-right">
                                    <strong>{{ formatCurrency(row.total_collected) }}</strong>
                                </td>
                                <td class="text-right">
                                    <strong :class="row.outstanding_total > 0 ? 'amber-text' : 'green-text'">
                                        {{ formatCurrency(row.outstanding_total) }}
                                    </strong>
                                </td>
                                <td>
                                    <div class="quality-stack">
                                        <span>{{ row.collection_rate }}% collected this window</span>
                                        <small>Avg RFQ {{ formatCurrency(row.average_rfq_value) }}</small>
                                        <small>{{ row.admin_assisted_rfq_count || 0 }} admin-assisted • {{ row.client_self_rfq_count || 0 }} self-submitted</small>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <Link :href="drilldownUrl(row)" class="btn btn-secondary btn-sm" title="View RFQ Revenue Drilldown">
                                        View Details
                                    </Link>
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="rows.length" class="mobile-report-list">
                <article v-for="row in rows" :key="`mobile-${rowKey(row)}`" class="mobile-report-card">
                    <div class="mobile-report-head">
                        <div class="entity-block">
                            <strong>{{ isRfqVariant ? row.job_reference : row.client_name }}</strong>
                            <span>{{ isRfqVariant ? row.client_name : row.client_email || 'No email available' }}</span>
                            <small v-if="isRfqVariant" class="inline-note">{{ row.service_name || 'General service' }}</small>
                        </div>
                        <span v-if="isRfqVariant" class="status-pill" :class="statusTone(row.status)">
                            {{ formatStatus(row.status) }}
                        </span>
                        <span v-else class="status-pill neutral">
                            {{ row.rfq_count }} RFQ{{ row.rfq_count === 1 ? '' : 's' }}
                        </span>
                    </div>

                    <div class="mobile-metrics">
                        <div class="mobile-metric">
                            <span>{{ isRfqVariant ? 'Gross Quote' : 'Gross Business' }}</span>
                            <strong>{{ formatCurrency(row.gross_quoted_amount) }}</strong>
                        </div>
                        <div class="mobile-metric">
                            <span>Collected in Window</span>
                            <strong class="green-text">{{ formatCurrency(row.collected_in_period) }}</strong>
                        </div>
                        <div class="mobile-metric">
                            <span>Collected to Date</span>
                            <strong>{{ formatCurrency(row.total_collected) }}</strong>
                        </div>
                        <div class="mobile-metric">
                            <span>Outstanding</span>
                            <strong :class="row.outstanding_total > 0 ? 'amber-text' : 'green-text'">
                                {{ formatCurrency(row.outstanding_total) }}
                            </strong>
                        </div>
                    </div>

                    <div class="mobile-footnote">
                        <span v-if="isRfqVariant">{{ row.submission_mode_label }} • {{ row.quote_label }}</span>
                        <span v-else>{{ row.collection_rate }}% collected this window</span>
                        <span v-if="isRfqVariant && row.latest_payment_date">Last {{ formatDate(row.latest_payment_date) }}</span>
                        <span v-else-if="!isRfqVariant">Avg RFQ {{ formatCurrency(row.average_rfq_value) }}</span>
                        <span v-if="isRfqVariant && row.proxy_quote_approved_by_name">Approved by {{ row.proxy_quote_approved_by_name }}</span>
                        <span v-if="!isRfqVariant">{{ row.admin_assisted_rfq_count || 0 }} assisted • {{ row.client_self_rfq_count || 0 }} self-submitted</span>
                    </div>

                    <div v-if="!isRfqVariant" class="mobile-actions" style="margin-top: 1rem;">
                        <Link :href="drilldownUrl(row)" class="btn btn-secondary btn-sm" style="width: 100%; justify-content: center;">
                            View Details
                        </Link>
                    </div>
                </article>
            </div>

            <div v-if="!rows.length" class="empty-state">
                <i class="fas fa-chart-pie"></i>
                <h3>No report data in this range</h3>
                <p>Try widening the date window to compare RFQs, collections, and client value.</p>
            </div>
        </section>
    </main>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'

const props = defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    routePath: { type: String, required: true },
    contextLabel: { type: String, default: 'Revenue Drilldown' },
    exportPdfPath: { type: String, required: true },
    exportExcelPath: { type: String, required: true },
    variant: {
        type: String,
        default: 'rfq',
        validator: (value) => ['rfq', 'client'].includes(value),
    },
})

const fromDate = ref(props.filters.from || '')
const toDate = ref(props.filters.to || '')

const reportData = computed(() => props.report || {})
const totals = computed(() => reportData.value.totals || {})
const leaders = computed(() => reportData.value.leaders || {})
const rows = computed(() => reportData.value.rows || [])
const isRfqVariant = computed(() => props.variant === 'rfq')
const hasDateSelection = computed(() => Boolean(fromDate.value || toDate.value))

const pageTitle = computed(() => (
    isRfqVariant.value ? 'RFQ Revenue Report' : 'Client Revenue Report'
))

const pageDescription = computed(() => (
    isRfqVariant.value
        ? 'See which RFQs generated the most value, how much cash came in during the selected window, and what remains open against approved quotations.'
        : 'Rank clients by value and volume for the selected period using actual collections alongside approved quotation totals.'
))

const dateRangeLabel = computed(() => {
    if (fromDate.value && toDate.value) {
        return `${formatDate(fromDate.value)} - ${formatDate(toDate.value)}`
    }

    return 'Current reporting window'
})

const rangeSummary = computed(() => (
    fromDate.value && toDate.value
        ? `${formatDate(fromDate.value)} to ${formatDate(toDate.value)}`
        : 'Current reporting window'
))

const rangeCaption = computed(() => (
    isRfqVariant.value
        ? 'Actual collections are filtered by payment date. Gross quote values are pulled from the latest approved quotation or approved amendment.'
        : 'Client rankings combine RFQ volume with approved quotation value and collected cash inside the selected window.'
))

const countPillIcon = computed(() => (
    isRfqVariant.value ? 'fas fa-file-invoice-dollar' : 'fas fa-users'
))

const countPillText = computed(() => (
    isRfqVariant.value
        ? `${totals.value.rfq_count || 0} RFQs tracked`
        : `${totals.value.client_count || 0} clients tracked`
))

const leaderPillText = computed(() => {
    if (isRfqVariant.value) {
        const leader = leaders.value.top_collected_rfq
        return leader ? `${leader.job_reference} led collections` : ''
    }

    const leader = leaders.value.top_value_client
    return leader ? `${leader.client_name} leads by value` : ''
})

const summaryCards = computed(() => {
    if (isRfqVariant.value) {
        return [
            {
                tag: 'Coverage',
                label: 'Tracked RFQs',
                value: formatNumber(totals.value.rfq_count || 0),
                note: `${totals.value.amended_rfq_count || 0} amended quotation${Number(totals.value.amended_rfq_count || 0) === 1 ? '' : 's'} included.`,
                icon: 'fas fa-layer-group',
                tone: 'tone-slate',
            },
            {
                tag: 'Quote Value',
                label: 'Gross Quoted',
                value: formatCurrency(totals.value.total_gross_quoted || 0),
                note: 'Latest approved quotation totals across matching RFQs.',
                icon: 'fas fa-file-signature',
                tone: 'tone-blue',
            },
            {
                tag: 'Collections',
                label: 'Collected in Window',
                value: formatCurrency(totals.value.total_collected_in_period || 0),
                note: 'Completed payments received during the selected dates.',
                icon: 'fas fa-hand-holding-usd',
                tone: 'tone-green',
            },
            {
                tag: 'Open Balance',
                label: 'Outstanding',
                value: formatCurrency(totals.value.total_outstanding || 0),
                note: 'Remaining balance after all completed payments to date.',
                icon: 'fas fa-hourglass-half',
                tone: 'tone-amber',
            },
        ]
    }

    return [
        {
            tag: 'Clients',
            label: 'Tracked Clients',
            value: formatNumber(totals.value.client_count || 0),
            note: `${totals.value.total_rfq_count || 0} RFQs contribute to this ranking window.`,
            icon: 'fas fa-user-friends',
            tone: 'tone-slate',
        },
        {
            tag: 'Volume',
            label: 'RFQ Volume',
            value: formatNumber(totals.value.total_rfq_count || 0),
            note: `Average RFQ value ${formatCurrency(totals.value.average_rfq_value || 0)}.`,
            icon: 'fas fa-briefcase',
            tone: 'tone-blue',
        },
        {
            tag: 'Business Value',
            label: 'Gross Business',
            value: formatCurrency(totals.value.total_gross_quoted || 0),
            note: 'Approved quotation totals across clients in the selected window.',
            icon: 'fas fa-sack-dollar',
            tone: 'tone-green',
        },
        {
            tag: 'Cash In',
            label: 'Collected in Window',
            value: formatCurrency(totals.value.total_collected_in_period || 0),
            note: `Outstanding balance ${formatCurrency(totals.value.total_outstanding || 0)}.`,
            icon: 'fas fa-coins',
            tone: 'tone-amber',
        },
    ]
})

const spotlightCards = computed(() => {
    if (isRfqVariant.value) {
        return [
            {
                kicker: 'Collections Leader',
                title: 'Top RFQ by Cash Collected',
                badge: 'Highest cash-in',
                badgeTone: 'success',
                item: buildRfqSpotlight(leaders.value.top_collected_rfq),
                primaryLabel: 'Collected in window',
                primaryValue: formatCurrency(leaders.value.top_collected_rfq?.collected_in_period || 0),
                secondaryLabel: 'Gross quote',
                secondaryValue: formatCurrency(leaders.value.top_collected_rfq?.gross_quoted_amount || 0),
            },
            {
                kicker: 'Quoted Value',
                title: 'Highest Value RFQ',
                badge: 'Largest approved quote',
                badgeTone: 'muted',
                item: buildRfqSpotlight(leaders.value.highest_quote_rfq),
                primaryLabel: 'Gross quote',
                primaryValue: formatCurrency(leaders.value.highest_quote_rfq?.gross_quoted_amount || 0),
                secondaryLabel: 'Collected to date',
                secondaryValue: formatCurrency(leaders.value.highest_quote_rfq?.total_collected || 0),
            },
        ]
    }

    return [
        {
            kicker: 'Value Leader',
            title: 'Top Client by Value',
            badge: 'Highest collections',
            badgeTone: 'success',
            item: buildClientSpotlight(leaders.value.top_value_client),
            primaryLabel: 'Collected in window',
            primaryValue: formatCurrency(leaders.value.top_value_client?.collected_in_period || 0),
            secondaryLabel: 'Gross business',
            secondaryValue: formatCurrency(leaders.value.top_value_client?.gross_quoted_amount || 0),
        },
        {
            kicker: 'Volume Leader',
            title: 'Top Client by RFQ Volume',
            badge: 'Most RFQs',
            badgeTone: 'muted',
            item: buildClientSpotlight(leaders.value.top_volume_client),
            primaryLabel: 'RFQs',
            primaryValue: formatNumber(leaders.value.top_volume_client?.rfq_count || 0),
            secondaryLabel: 'Avg RFQ value',
            secondaryValue: formatCurrency(leaders.value.top_volume_client?.average_rfq_value || 0),
        },
    ]
})

const tableKicker = computed(() => (
    isRfqVariant.value ? 'Per RFQ' : 'Per Client'
))

const tableTitle = computed(() => (
    isRfqVariant.value ? 'RFQ Revenue Ledger' : 'Client Value Ledger'
))

const tableUnit = computed(() => (
    isRfqVariant.value ? 'RFQ' : 'client'
))

const tableUnitPlural = computed(() => (
    isRfqVariant.value ? 'RFQs' : 'clients'
))

function applyFilters() {
    router.get(props.routePath, {
        from: fromDate.value || undefined,
        to: toDate.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const rfqReportPath = computed(() => {
    return props.routePath.replace('client-revenue', 'rfq-revenue')
})

function drilldownUrl(row) {
    const params = new URLSearchParams()
    if (fromDate.value) params.set('from', fromDate.value)
    if (toDate.value) params.set('to', toDate.value)
    params.set('client_id', row.client_id)

    return `${rfqReportPath.value}?${params.toString()}`
}

function clearFilters() {
    fromDate.value = ''
    toDate.value = ''
    applyFilters()
}

function exportReport(format) {
    const basePath = format === 'pdf' ? props.exportPdfPath : props.exportExcelPath
    const params = new URLSearchParams()

    if (fromDate.value) params.set('from', fromDate.value)
    if (toDate.value) params.set('to', toDate.value)

    const target = params.toString() ? `${basePath}?${params.toString()}` : basePath
    window.location.href = target
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

function formatDate(value) {
    if (!value) return 'N/A'

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value))
}

function formatStatus(value) {
    if (!value) return 'Unknown'
    return value.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

function rowKey(row) {
    return isRfqVariant.value ? row.service_request_id : row.client_id
}

function progressWidth(collected, total) {
    if (!total) return 0
    return Math.min(Math.round((Number(collected || 0) / Number(total || 0)) * 100), 100)
}

function progressLabel(collected, total) {
    return `${progressWidth(collected, total)}%`
}

function statusTone(status) {
    const tones = {
        closed: 'success',
        completed: 'success',
        ready_for_assignment: 'info',
        in_progress: 'info',
        assigned: 'info',
        awaiting_payment: 'warning',
        awaiting_quote_approval: 'warning',
        delayed: 'warning',
        suspended: 'neutral',
    }

    return tones[status] || 'neutral'
}

function buildRfqSpotlight(item) {
    if (!item) return null

    return {
        title: item.job_reference,
        subtitle: `${item.client_name} • ${item.quote_label}`,
    }
}

function buildClientSpotlight(item) {
    if (!item) return null

    return {
        title: item.client_name,
        subtitle: `${item.rfq_count} RFQ${item.rfq_count === 1 ? '' : 's'} • ${item.client_email || 'No email available'}`,
    }
}
</script>

<style scoped>
.revenue-breakdown-page {
    display: grid;
    gap: 1.5rem;
}

.revenue-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(280px, 0.95fr);
    gap: 1.25rem;
    align-items: stretch;
}

.hero-copy,
.filter-panel,
.panel-card,
.summary-card {
    border-radius: 28px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 24px 50px rgba(15, 23, 42, 0.08);
}

.hero-copy {
    padding: 1.75rem 1.9rem;
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.16), transparent 38%),
        linear-gradient(135deg, #ffffff, #f7fbff);
}

.hero-kicker,
.section-kicker,
.filter-label,
.summary-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.72rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    font-weight: 800;
}

.hero-kicker,
.summary-tag {
    color: #0369a1;
}

.hero-headline h1,
.section-heading h3 {
    margin: 0;
    color: #0f172a;
}

.hero-headline {
    margin-top: 0.75rem;
}

.hero-headline h1 {
    font-size: clamp(1.9rem, 3vw, 2.45rem);
    line-height: 1.1;
}

.hero-headline p,
.filter-copy p,
.summary-note,
.empty-state p,
.no-data-small {
    margin: 0;
    color: #475569;
    line-height: 1.6;
}

.hero-headline p {
    margin-top: 0.6rem;
    max-width: 60ch;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.7rem;
    margin-top: 1.35rem;
}

.hero-pill,
.section-badge,
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 999px;
    font-weight: 700;
}

.hero-pill {
    padding: 0.72rem 1rem;
    background: rgba(226, 232, 240, 0.72);
    color: #0f172a;
}

.hero-pill.muted,
.section-badge.muted,
.status-pill.neutral {
    background: rgba(241, 245, 249, 0.95);
    color: #475569;
}

.filter-panel,
.panel-card {
    padding: 1.5rem;
    background: #ffffff;
}

.filter-panel {
    display: grid;
    gap: 1rem;
}

.filter-label,
.section-kicker {
    color: #0284c7;
}

.filter-copy strong {
    display: block;
    margin-top: 0.35rem;
    color: #0f172a;
    font-size: 1.05rem;
}

.filter-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.filter-field {
    display: grid;
    gap: 0.4rem;
    color: #334155;
    font-size: 0.86rem;
    font-weight: 700;
}

.date-input {
    width: 100%;
    border: 1px solid rgba(148, 163, 184, 0.3);
    border-radius: 16px;
    padding: 0.82rem 0.95rem;
    background: #f8fafc;
}

.filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.filter-button {
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding-inline: 1rem;
}

.filter-button-secondary {
    background: #e2e8f0;
    color: #0f172a;
}

.summary-grid,
.spotlight-grid {
    display: grid;
    gap: 1rem;
}

.summary-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    padding: 1.35rem;
    background: #ffffff;
}

.summary-card-top,
.section-heading,
.mobile-report-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.summary-icon {
    width: 2.75rem;
    height: 2.75rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.75);
    border: 1px solid rgba(255, 255, 255, 0.55);
    color: #0f172a;
}

.summary-label {
    display: block;
    margin-top: 1rem;
    font-size: 0.82rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.summary-value {
    display: block;
    margin-top: 0.35rem;
    color: #0f172a;
    font-size: 1.55rem;
    line-height: 1.1;
}

.summary-note {
    margin-top: 0.75rem;
    font-size: 0.9rem;
}

.tone-slate {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.tone-blue {
    background: linear-gradient(180deg, #f0f9ff, #e0f2fe);
}

.tone-green {
    background: linear-gradient(180deg, #ecfdf5, #dcfce7);
}

.tone-amber {
    background: linear-gradient(180deg, #fffbeb, #fef3c7);
}

.spotlight-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.section-badge {
    padding: 0.5rem 0.8rem;
    font-size: 0.75rem;
}

.section-badge.success,
.status-pill.success {
    background: rgba(34, 197, 94, 0.14);
    color: #15803d;
}

.section-badge.info,
.status-pill.info {
    background: rgba(59, 130, 246, 0.14);
    color: #1d4ed8;
}

.section-badge.warning,
.status-pill.warning {
    background: rgba(245, 158, 11, 0.16);
    color: #b45309;
}

.spotlight-body {
    display: grid;
    gap: 1rem;
    margin-top: 1.2rem;
}

.spotlight-main strong,
.entity-block strong {
    color: #0f172a;
    font-size: 1.08rem;
}

.spotlight-main,
.entity-block,
.amount-cell,
.quality-stack {
    display: grid;
    gap: 0.2rem;
}

.spotlight-main span,
.entity-block span,
.amount-cell span,
.quality-stack span,
.quality-stack small,
.inline-note,
.mobile-footnote {
    color: #64748b;
}

.spotlight-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.8rem;
}

.metric-chip {
    display: grid;
    gap: 0.25rem;
    padding: 0.85rem 0.95rem;
    border-radius: 18px;
    background: #eff6ff;
}

.metric-chip.muted {
    background: #f8fafc;
}

.metric-chip span {
    color: #475569;
    font-size: 0.8rem;
    font-weight: 700;
}

.metric-chip strong {
    color: #0f172a;
    font-size: 1rem;
}

.report-table-wrap {
    margin-top: 1.1rem;
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 980px;
}

.report-table thead th {
    padding: 0.95rem 1rem;
    text-align: left;
    font-size: 0.78rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    border-bottom: 1px solid rgba(226, 232, 240, 0.9);
}

.report-table tbody td {
    padding: 1rem;
    vertical-align: top;
    border-bottom: 1px solid rgba(226, 232, 240, 0.76);
}

.text-right {
    text-align: right;
}

.entity-block.compact strong {
    font-size: 0.98rem;
}

.inline-note,
.status-pill,
.mobile-footnote {
    font-size: 0.76rem;
}

.status-pill {
    width: fit-content;
    padding: 0.38rem 0.65rem;
}

.amount-cell strong,
.mobile-metric strong {
    color: #0f172a;
}

.progress-cell {
    display: grid;
    gap: 0.35rem;
}

.mini-progress-track {
    position: relative;
    width: 100%;
    height: 0.55rem;
    border-radius: 999px;
    overflow: hidden;
    background: #e2e8f0;
}

.mini-progress-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #38bdf8, #2563eb);
}

.mini-progress-fill.complete {
    background: linear-gradient(90deg, #22c55e, #16a34a);
}

.mini-pct {
    color: #0f172a;
    font-size: 0.78rem;
    font-weight: 700;
}

.quality-stack small {
    font-size: 0.78rem;
}

.green-text {
    color: #15803d;
}

.amber-text {
    color: #b45309;
}

.mobile-report-list {
    display: none;
    gap: 1rem;
    margin-top: 1rem;
}

.mobile-report-card {
    padding: 1rem;
    border-radius: 22px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.mobile-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin-top: 1rem;
}

.mobile-metric {
    display: grid;
    gap: 0.25rem;
    padding: 0.75rem 0.8rem;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.9);
}

.mobile-metric span {
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 700;
}

.mobile-footnote {
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem;
    margin-top: 0.9rem;
    line-height: 1.4;
}

.empty-state {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    padding: 2rem 1rem 1rem;
    text-align: center;
}

.empty-state i {
    font-size: 2rem;
    color: #0284c7;
}

.empty-state h3 {
    margin: 0;
    color: #0f172a;
}

.no-data-small {
    margin-top: 1rem;
    font-size: 0.95rem;
}

@media (max-width: 1180px) {
    .revenue-hero,
    .summary-grid,
    .spotlight-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 820px) {
    .desktop-report {
        display: none;
    }

    .mobile-report-list {
        display: grid;
    }

    .filter-fields,
    .spotlight-metrics,
    .mobile-metrics {
        grid-template-columns: 1fr;
    }
}
</style>
