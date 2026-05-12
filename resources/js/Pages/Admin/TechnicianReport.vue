<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="technicians" />

        <main class="main-content technician-report-page">
            <section class="technician-hero">
                <div class="hero-copy">
                    <Link href="/admin/technicians" class="back-link">
                        <i class="fas fa-arrow-left"></i>
                        Back to Technicians
                    </Link>

                    <div class="hero-identity">
                        <div class="avatar-badge">{{ initials }}</div>
                        <div>
                            <span class="hero-kicker">Technician Payment Report</span>
                            <h1>{{ technician.user.name }}</h1>
                            <p>Review agreed compensation, payouts, milestones, and payment sheet activity across every assigned job.</p>
                        </div>
                    </div>

                    <div class="hero-pills">
                        <span class="hero-pill">
                            <i class="fas fa-id-card"></i>
                            {{ technician.technician_id }}
                        </span>
                        <span class="hero-pill muted" v-if="technician.specialization">
                            <i class="fas fa-tools"></i>
                            {{ technician.specialization }}
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-briefcase"></i>
                            {{ summary.total_jobs }} jobs
                        </span>
                    </div>
                </div>

                <div class="hero-summary-card">
                    <div class="summary-copy">
                        <span class="section-kicker">Snapshot</span>
                        <h3>Earnings Overview</h3>
                        <p>{{ payoutCompletion }}% of agreed compensation has already been paid out.</p>
                    </div>

                    <div class="summary-highlight">
                        <span class="summary-highlight-label">Paid so far</span>
                        <strong>{{ formatCurrency(summary.total_earned) }}</strong>
                    </div>

                    <div class="hero-summary-grid">
                        <div class="hero-summary-tile">
                            <span>Agreed</span>
                            <strong>{{ formatCurrency(summary.total_agreed) }}</strong>
                        </div>
                        <div class="hero-summary-tile">
                            <span>Remaining</span>
                            <strong>{{ formatCurrency(remainingCompensation) }}</strong>
                        </div>
                        <div class="hero-summary-tile">
                            <span>Active Jobs</span>
                            <strong>{{ summary.active_jobs }}</strong>
                        </div>
                        <div class="hero-summary-tile">
                            <span>Completed Jobs</span>
                            <strong>{{ summary.completed_jobs }}</strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="stats-grid">
                <article class="stat-card tone-green">
                    <div class="stat-topline">
                        <span class="stat-tag">Paid</span>
                        <span class="stat-icon"><i class="fas fa-money-bill-wave"></i></span>
                    </div>
                    <h4>Total Earned</h4>
                    <p class="stat-value">{{ formatCurrency(summary.total_earned) }}</p>
                    <span class="stat-footnote">{{ completedRecordsCount }} completed payment record{{ completedRecordsCount === 1 ? '' : 's' }}</span>
                </article>

                <article class="stat-card tone-blue">
                    <div class="stat-topline">
                        <span class="stat-tag">Agreed</span>
                        <span class="stat-icon"><i class="fas fa-handshake"></i></span>
                    </div>
                    <h4>Total Agreed</h4>
                    <p class="stat-value">{{ formatCurrency(summary.total_agreed) }}</p>
                    <span class="stat-footnote">Across {{ summary.total_jobs }} assigned job{{ summary.total_jobs === 1 ? '' : 's' }}.</span>
                </article>

                <article class="stat-card tone-amber">
                    <div class="stat-topline">
                        <span class="stat-tag">Balance</span>
                        <span class="stat-icon"><i class="fas fa-scale-balanced"></i></span>
                    </div>
                    <h4>Outstanding</h4>
                    <p class="stat-value">{{ formatCurrency(remainingCompensation) }}</p>
                    <span class="stat-footnote">Compensation still remaining against agreed totals.</span>
                </article>

                <article class="stat-card tone-slate">
                    <div class="stat-topline">
                        <span class="stat-tag">Jobs</span>
                        <span class="stat-icon"><i class="fas fa-chart-pie"></i></span>
                    </div>
                    <h4>Active / Completed</h4>
                    <p class="stat-value">{{ summary.active_jobs }} / {{ summary.completed_jobs }}</p>
                    <span class="stat-footnote">{{ leadJobsCount }} lead assignment{{ leadJobsCount === 1 ? '' : 's' }}</span>
                </article>
            </section>

            <section v-if="jobPayments.length > 0" class="job-report-list">
                <article v-for="job in jobPayments" :key="job.id" class="panel-card job-report-card">
                    <div class="job-header">
                        <div class="job-header-main">
                            <span class="section-kicker">Job Payment View</span>
                            <h3>
                                <Link :href="`/admin/jobs/${job.id}`" class="job-link">
                                    {{ job.job_reference }}
                                </Link>
                            </h3>
                            <div class="job-meta">
                                <span class="service-label">{{ job.service_name }}</span>
                                <span v-if="job.is_lead" class="lead-badge">Lead Technician</span>
                                <span :class="['status-badge', getStatusClass(job.status)]">
                                    {{ formatStatus(job.status) }}
                                </span>
                            </div>
                        </div>

                        <div class="job-header-side">
                            <div class="job-figure">
                                <span>Agreed Compensation</span>
                                <strong>{{ formatCurrency(job.agreed_compensation) }}</strong>
                            </div>
                            <div class="job-figure paid">
                                <span>Total Paid</span>
                                <strong>{{ formatCurrency(job.total_paid) }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="job-summary-grid">
                        <div class="job-summary-tile">
                            <span>Progress</span>
                            <strong class="blue-text">{{ job.cumulative_progress || 0 }}%</strong>
                        </div>
                        <div class="job-summary-tile">
                            <span>Payout Completion</span>
                            <strong class="green-text">{{ getPaymentPercent(job) }}%</strong>
                        </div>
                        <div class="job-summary-tile">
                            <span>Remaining</span>
                            <strong>{{ formatCurrency(getRemaining(job)) }}</strong>
                        </div>
                        <div class="job-summary-tile">
                            <span>Records</span>
                            <strong>{{ job.direct_payments.length + job.sheet_entries.length }}</strong>
                        </div>
                    </div>

                    <div v-if="job.agreed_compensation > 0" class="payment-progress">
                        <div class="progress-info">
                            <span>Compensation Progress</span>
                            <span class="progress-percent">{{ getPaymentPercent(job) }}%</span>
                        </div>
                        <div class="progress-bar-container">
                            <div
                                class="progress-bar-fill"
                                :style="{ width: Math.min(getPaymentPercent(job), 100) + '%' }"
                                :class="{ complete: getPaymentPercent(job) >= 100 }"
                            ></div>
                        </div>
                        <div class="progress-amounts">
                            <span>Paid: {{ formatCurrency(job.total_paid) }}</span>
                            <span>Remaining: {{ formatCurrency(getRemaining(job)) }}</span>
                        </div>
                    </div>

                    <div v-if="job.milestones.length > 0" class="report-section">
                        <div class="section-header">
                            <h4><i class="fas fa-flag-checkered"></i> Payment Milestones</h4>
                            <span class="section-count">{{ job.milestones.length }} milestone{{ job.milestones.length === 1 ? '' : 's' }}</span>
                        </div>
                        <div class="milestones-grid">
                            <div v-for="m in job.milestones" :key="m.id" class="milestone-card">
                                <div class="milestone-topline">
                                    <span class="milestone-step">{{ m.progress_step }}%</span>
                                    <span :class="['milestone-status', m.status]">{{ formatStatus(m.status) }}</span>
                                </div>
                                <strong class="milestone-amount">{{ formatCurrency(m.amount) }}</strong>
                                <p v-if="m.notes" class="milestone-note">{{ m.notes }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="job.direct_payments.length > 0" class="report-section">
                        <div class="section-header">
                            <h4><i class="fas fa-money-check-alt"></i> Direct Payments</h4>
                            <span class="section-count">{{ job.direct_payments.length }} payment{{ job.direct_payments.length === 1 ? '' : 's' }}</span>
                        </div>
                        <div class="table-shell">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference</th>
                                        <th>Category</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="p in job.direct_payments" :key="p.id">
                                        <td>{{ formatDate(p.paid_at) }}</td>
                                        <td>{{ p.payment_id }}</td>
                                        <td><span class="category-badge">{{ p.category }}</span></td>
                                        <td><strong>{{ formatCurrency(p.amount) }}</strong></td>
                                        <td>{{ formatMethod(p.payment_method) }}</td>
                                        <td>
                                            <span :class="['status-badge', getPaymentStatusClass(p.status)]">
                                                {{ formatStatus(p.status) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3"><strong>Subtotal</strong></td>
                                        <td><strong>{{ formatCurrency(job.direct_payments.reduce((s, p) => s + p.amount, 0)) }}</strong></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div v-if="job.sheet_entries.length > 0" class="report-section">
                        <div class="section-header">
                            <h4><i class="fas fa-file-invoice-dollar"></i> Payment Sheet Entries</h4>
                            <span class="section-count">{{ job.sheet_entries.length }} entr{{ job.sheet_entries.length === 1 ? 'y' : 'ies' }}</span>
                        </div>
                        <div class="table-shell">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Sheet Ref</th>
                                        <th>Period</th>
                                        <th>Progress</th>
                                        <th>Cumulative Due</th>
                                        <th>Previously Paid</th>
                                        <th>Current Payable</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="e in job.sheet_entries" :key="e.id">
                                        <td>{{ e.sheet_reference }}</td>
                                        <td class="period-cell">{{ e.period }}</td>
                                        <td>{{ e.cumulative_progress_pct }}%</td>
                                        <td>{{ formatCurrency(e.cumulative_amount_due) }}</td>
                                        <td>{{ formatCurrency(e.previous_cumulative_paid) }}</td>
                                        <td><strong>{{ formatCurrency(e.current_period_payable) }}</strong></td>
                                        <td>
                                            <span :class="['status-badge', getPaymentStatusClass(e.status)]">
                                                {{ formatStatus(e.status) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="5"><strong>Subtotal</strong></td>
                                        <td><strong>{{ formatCurrency(job.sheet_entries.reduce((s, e) => s + e.current_period_payable, 0)) }}</strong></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div v-if="job.direct_payments.length === 0 && job.sheet_entries.length === 0" class="no-payments">
                        <i class="fas fa-info-circle"></i>
                        No payments recorded for this job yet.
                    </div>
                </article>
            </section>

            <section v-else class="panel-section">
                <div class="panel-card empty-shell">
                    <div class="empty-state">
                        <i class="fas fa-user-hard-hat"></i>
                        <p>No jobs or payments found for this technician.</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    technician: { type: Object, required: true },
    jobPayments: { type: Array, default: () => [] },
    summary: {
        type: Object,
        default: () => ({
            total_earned: 0,
            total_agreed: 0,
            total_jobs: 0,
            active_jobs: 0,
            completed_jobs: 0,
        }),
    },
})

const initials = computed(() => {
    const name = props.technician?.user?.name || ''
    return name.split(' ').map(part => part[0]).join('').toUpperCase().slice(0, 2) || 'T'
})

const remainingCompensation = computed(() => Math.max((props.summary.total_agreed || 0) - (props.summary.total_earned || 0), 0))
const payoutCompletion = computed(() => {
    if (!props.summary.total_agreed) return 0
    return Math.min(Math.round((props.summary.total_earned / props.summary.total_agreed) * 100), 100)
})
const leadJobsCount = computed(() => props.jobPayments.filter(job => job.is_lead).length)
const completedRecordsCount = computed(() => props.jobPayments.reduce((count, job) => {
    const directCompleted = job.direct_payments.filter(payment => ['completed', 'paid', 'approved'].includes(payment.status)).length
    const sheetCompleted = job.sheet_entries.filter(entry => ['approved', 'paid', 'completed'].includes(entry.status)).length
    return count + directCompleted + sheetCompleted
}, 0))

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
    if (!method) return '-'
    const methods = { mpesa: 'M-Pesa', cheque: 'Cheque', cash: 'Cash', bank_deposit: 'Bank Deposit' }
    return methods[method] || method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const formatStatus = (status) => {
    if (!status) return ''
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getStatusClass = (status) => {
    if (['completed', 'closed'].includes(status)) return 'approved'
    if (['assigned', 'in_progress', 'queued'].includes(status)) return 'in-progress'
    if (['cancelled', 'suspended'].includes(status)) return 'rejected'
    return 'pending'
}

const getPaymentStatusClass = (status) => {
    if (['completed', 'paid', 'approved'].includes(status)) return 'approved'
    if (['pending', 'processing', 'invoiced'].includes(status)) return 'pending'
    return 'rejected'
}

const getPaymentPercent = (job) => {
    if (!job.agreed_compensation || job.agreed_compensation === 0) return 0
    return Math.round((job.total_paid / job.agreed_compensation) * 100)
}

const getRemaining = (job) => {
    return Math.max((job.agreed_compensation || 0) - (job.total_paid || 0), 0)
}

defineOptions({ layout: null })
</script>

<style>
@import url('../../../css/dashboard-app.css');

.technician-report-page {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.1), transparent 24rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.technician-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.75rem;
}

.hero-copy,
.hero-summary-card,
.stat-card,
.job-report-card,
.empty-shell {
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

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: rgba(226, 232, 240, 0.92);
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 600;
}

.back-link:hover {
    color: #ffffff;
}

.hero-identity {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-top: 1.4rem;
}

.avatar-badge {
    width: 4rem;
    height: 4rem;
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.14);
    color: #ffffff;
    font-size: 1.2rem;
    font-weight: 800;
    flex-shrink: 0;
}

.hero-kicker,
.section-kicker,
.job-header-label,
.section-count {
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
    margin: 0.55rem 0 0;
    color: #ffffff;
    font-size: clamp(2rem, 3vw, 2.5rem);
}

.hero-copy p {
    margin: 0.9rem 0 0;
    max-width: 38rem;
    color: rgba(226, 232, 240, 0.88);
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
    color: #f8fafc;
    font-size: 0.88rem;
    font-weight: 600;
}

.hero-pill.muted {
    background: rgba(15, 23, 42, 0.22);
}

.hero-summary-card {
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

.summary-copy h3 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.summary-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.summary-highlight {
    padding: 1.15rem;
    border-radius: 20px;
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.98), #ffffff);
    border: 1px solid #dcfce7;
}

.summary-highlight-label,
.hero-summary-tile span,
.stat-footnote,
.service-label,
.lead-badge,
.milestone-note,
.period-cell,
.progress-amounts,
.compensation-info span,
.job-summary-tile span {
    color: #64748b;
}

.summary-highlight strong {
    display: block;
    margin-top: 0.4rem;
    color: #0f172a;
    font-size: 1.5rem;
}

.hero-summary-grid,
.stats-grid,
.job-summary-grid {
    display: grid;
    gap: 1rem;
}

.hero-summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.hero-summary-tile,
.job-summary-tile {
    padding: 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.hero-summary-tile strong,
.job-summary-tile strong {
    display: block;
    margin-top: 0.35rem;
    color: #0f172a;
    font-size: 1.12rem;
}

.stats-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 1.5rem;
}

.stat-card {
    padding: 1.35rem;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.92);
}

.tone-green {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.96), #ffffff);
}

.tone-blue {
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.95), #ffffff);
}

.tone-amber {
    background: linear-gradient(180deg, rgba(255, 251, 235, 0.98), #ffffff);
}

.tone-slate {
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), #ffffff);
}

.stat-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.95rem;
}

.stat-tag {
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

.stat-icon {
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
}

.tone-green .stat-icon {
    background: linear-gradient(135deg, #0f766e, #22c55e);
}

.tone-amber .stat-icon {
    background: linear-gradient(135deg, #c2410c, #f59e0b);
}

.tone-slate .stat-icon {
    background: linear-gradient(135deg, #475569, #94a3b8);
}

.stat-card h4 {
    margin: 0;
    color: #475569;
    font-size: 0.92rem;
}

.stat-value {
    margin: 0.45rem 0 0;
    font-size: 1.65rem;
    font-weight: 800;
    color: #0f172a;
}

.stat-footnote {
    display: block;
    margin-top: 0.6rem;
    font-size: 0.84rem;
    line-height: 1.45;
}

.job-report-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.job-report-card,
.empty-shell {
    border-radius: 24px;
    padding: 1.4rem;
    background: rgba(255, 255, 255, 0.92);
}

.job-header,
.section-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.job-header {
    margin-bottom: 1.2rem;
}

.job-header-main h3 {
    margin: 0.35rem 0 0;
}

.job-link {
    color: #0f6c8f;
    text-decoration: none;
}

.job-link:hover {
    text-decoration: underline;
}

.job-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-top: 0.8rem;
}

.service-label,
.lead-badge,
.status-badge,
.category-badge,
.milestone-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.38rem 0.7rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
    text-transform: capitalize;
}

.service-label {
    background: #eef2ff;
    color: #334155;
}

.lead-badge {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.approved,
.milestone-status.paid,
.milestone-status.completed {
    background: #dcfce7;
    color: #166534;
}

.status-badge.pending,
.status-badge.in-progress,
.milestone-status.pending,
.milestone-status.invoiced,
.milestone-status.reached {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.job-header-side {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    text-align: right;
}

.job-figure {
    padding: 0.9rem 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    min-width: 12rem;
}

.job-figure.paid {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.98), #ffffff);
}

.job-figure strong {
    display: block;
    margin-top: 0.35rem;
    color: #0f172a;
    font-size: 1.1rem;
}

.blue-text {
    color: #0f6c8f;
}

.green-text {
    color: #15803d;
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
}

.report-section {
    margin-top: 1.35rem;
    padding-top: 1.1rem;
    border-top: 1px solid #e2e8f0;
}

.section-header h4 {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
}

.milestones-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 0.85rem;
    margin-top: 0.9rem;
}

.milestone-card {
    padding: 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.milestone-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.milestone-step {
    font-weight: 800;
    color: #0f6c8f;
}

.milestone-amount {
    display: block;
    margin-top: 0.8rem;
    color: #0f172a;
    font-size: 1rem;
}

.milestone-note {
    margin: 0.65rem 0 0;
    font-size: 0.82rem;
    line-height: 1.45;
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

.data-table tfoot td {
    border-top: 2px solid #e2e8f0;
    padding-top: 0.75rem;
    background: #f8fafc;
}

.category-badge {
    background: #eef2ff;
    color: #334155;
}

.period-cell {
    font-size: 0.82rem;
    white-space: nowrap;
}

.no-payments {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    margin-top: 1rem;
    padding: 1.3rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px dashed #d7dee7;
    color: #64748b;
    font-style: italic;
}

.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #64748b;
}

.empty-state i {
    display: block;
    margin-bottom: 1rem;
    font-size: 2.5rem;
    color: #94a3b8;
}

@media (max-width: 1180px) {
    .technician-hero,
    .stats-grid,
    .job-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 860px) {
    .technician-hero,
    .stats-grid,
    .hero-summary-grid,
    .job-summary-grid {
        grid-template-columns: 1fr;
    }

    .hero-identity,
    .job-header,
    .section-header,
    .progress-info,
    .progress-amounts {
        flex-direction: column;
        align-items: flex-start;
    }

    .job-header-side {
        width: 100%;
        text-align: left;
    }
}
</style>
