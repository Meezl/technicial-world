<template>
    <PMLayout>
        <template #header>
            <div class="dashboard-header-copy">
                <div>
                    <h1>PM Dashboard</h1>
                    <p>Stay on top of RFQs, active delivery, validations, and payment preparation from one calm control view.</p>
                </div>
                <div class="welcome-chip">
                    <span>Signed in</span>
                    <strong>{{ firstName }}</strong>
                </div>
            </div>
        </template>

        <section class="dashboard-hero">
            <article class="hero-card hero-card-primary">
                <span class="hero-kicker">Operations Overview</span>
                <h2>{{ headlineTitle }}</h2>
                <p>{{ headlineMessage }}</p>

                <div class="hero-pills">
                    <span class="hero-pill">
                        <i class="fas fa-file-alt"></i>
                        {{ stats.assignedRfqs || 0 }} assigned RFQs
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-tasks"></i>
                        {{ stats.activeJobs || 0 }} active jobs
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-check-circle"></i>
                        {{ stats.completionRate || '0%' }} completion rate
                    </span>
                </div>
            </article>

            <article class="hero-card hero-card-focus">
                <div class="focus-head">
                    <div>
                        <span class="hero-kicker">Today’s Focus</span>
                        <h3>{{ focusTitle }}</h3>
                    </div>
                    <span class="focus-badge" :class="focusTone">{{ focusMetric }}</span>
                </div>
                <p>{{ focusMessage }}</p>
                <Link :href="focusHref" class="focus-link">
                    Open workspace
                    <i class="fas fa-arrow-right"></i>
                </Link>
            </article>
        </section>

        <section class="kpi-grid">
            <article class="kpi-card tone-blue">
                <div class="kpi-topline">
                    <span class="kpi-tag">RFQs</span>
                    <i class="fas fa-file-signature"></i>
                </div>
                <span class="kpi-label">Assigned RFQs</span>
                <strong class="kpi-value">{{ formatNumber(stats.assignedRfqs) }}</strong>
                <p class="kpi-footnote">Requests currently sitting with you across quoting, approval, and handoff.</p>
            </article>

            <article class="kpi-card tone-amber">
                <div class="kpi-topline">
                    <span class="kpi-tag">Quotes</span>
                    <i class="fas fa-calculator"></i>
                </div>
                <span class="kpi-label">Pending Quotes</span>
                <strong class="kpi-value">{{ formatNumber(stats.pendingQuotes) }}</strong>
                <p class="kpi-footnote">RFQs waiting for technical clarity or a fresh quotation to be issued.</p>
            </article>

            <article class="kpi-card tone-green">
                <div class="kpi-topline">
                    <span class="kpi-tag">Delivery</span>
                    <i class="fas fa-hard-hat"></i>
                </div>
                <span class="kpi-label">Active Jobs</span>
                <strong class="kpi-value">{{ formatNumber(stats.activeJobs) }}</strong>
                <p class="kpi-footnote">Jobs currently assigned, queued, or underway in the field.</p>
            </article>

            <article class="kpi-card tone-orange">
                <div class="kpi-topline">
                    <span class="kpi-tag">Validation</span>
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <span class="kpi-label">Pending Validation</span>
                <strong class="kpi-value">{{ formatNumber(stats.pendingValidation) }}</strong>
                <p class="kpi-footnote">Progress reports needing your review before payment or next-phase decisions.</p>
            </article>

            <article class="kpi-card tone-slate">
                <div class="kpi-topline">
                    <span class="kpi-tag">Outcome</span>
                    <i class="fas fa-bullseye"></i>
                </div>
                <span class="kpi-label">Completion Rate</span>
                <strong class="kpi-value">{{ stats.completionRate || '0%' }}</strong>
                <p class="kpi-footnote">Closed work as a share of the jobs under your portfolio.</p>
            </article>
        </section>

        <section class="insights-grid">
            <article class="panel-card spotlight-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Workload Mix</p>
                        <h3>Current Balance</h3>
                    </div>
                    <span class="section-badge">{{ totalVisibleWork }} visible items</span>
                </div>

                <div class="mix-list">
                    <div class="mix-row">
                        <div>
                            <strong>RFQs in queue</strong>
                            <span>Quoting, approvals, and funding handoffs</span>
                        </div>
                        <strong>{{ formatNumber(stats.assignedRfqs) }}</strong>
                    </div>
                    <div class="mix-row">
                        <div>
                            <strong>Jobs in delivery</strong>
                            <span>Assigned, queued, and in-progress execution</span>
                        </div>
                        <strong>{{ formatNumber(stats.activeJobs) }}</strong>
                    </div>
                    <div class="mix-row">
                        <div>
                            <strong>Reports to validate</strong>
                            <span>Progress items waiting for PM review</span>
                        </div>
                        <strong>{{ formatNumber(stats.pendingValidation) }}</strong>
                    </div>
                </div>
            </article>

            <article class="panel-card spotlight-card">
                <div class="section-heading">
                    <div>
                        <p class="section-kicker">Recent Jobs</p>
                        <h3>What Needs Attention</h3>
                    </div>
                    <span class="section-badge muted">{{ recentJobs.length }} recent jobs</span>
                </div>

                <div v-if="recentJobs.length" class="attention-list">
                    <div class="attention-row">
                        <span>Unassigned recent jobs</span>
                        <strong>{{ unassignedRecentJobs }}</strong>
                    </div>
                    <div class="attention-row">
                        <span>In progress right now</span>
                        <strong>{{ inProgressRecentJobs }}</strong>
                    </div>
                    <div class="attention-row">
                        <span>Awaiting payment follow-up</span>
                        <strong>{{ awaitingPaymentRecentJobs }}</strong>
                    </div>
                </div>
                <div v-else class="no-data-small">No recent active jobs yet.</div>
            </article>
        </section>

        <section class="panel-card recent-jobs-panel">
            <div class="section-heading">
                <div>
                    <p class="section-kicker">Recent Activity</p>
                    <h3>Recent Active Jobs</h3>
                </div>
                <Link href="/pm/jobs" class="section-link">View all jobs</Link>
            </div>

            <div v-if="recentJobs.length" class="jobs-table-wrap desktop-jobs">
                <table class="jobs-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Client</th>
                            <th>Category</th>
                            <th>Technician</th>
                            <th>Status</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="job in recentJobs" :key="job.id">
                            <td>
                                <div class="entity-block">
                                    <strong>{{ job.job_reference || job.request_id }}</strong>
                                    <span>{{ formatDate(job.created_at) }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="entity-block compact">
                                    <strong>{{ job.user?.name || 'N/A' }}</strong>
                                    <span>{{ job.location || 'No location provided' }}</span>
                                </div>
                            </td>
                            <td>{{ job.service_category?.name || 'Uncategorised' }}</td>
                            <td>
                                <div class="entity-block compact">
                                    <strong>{{ job.technician?.user?.name || 'Unassigned' }}</strong>
                                    <span>{{ job.technician?.specialization || 'Awaiting assignment' }}</span>
                                </div>
                            </td>
                            <td>
                                <span :class="['status-badge', statusTone(job.status)]">{{ formatStatus(job.status) }}</span>
                            </td>
                            <td>
                                <div class="progress-stack">
                                    <div class="mini-progress-track">
                                        <div class="mini-progress-fill" :style="{ width: `${job.progress_percentage || 0}%` }"></div>
                                    </div>
                                    <span>{{ job.progress_percentage || 0 }}%</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="recentJobs.length" class="mobile-jobs-list">
                <article v-for="job in recentJobs" :key="`mobile-${job.id}`" class="job-card">
                    <div class="job-card-head">
                        <div class="entity-block">
                            <strong>{{ job.job_reference || job.request_id }}</strong>
                            <span>{{ job.user?.name || 'N/A' }} • {{ job.location || 'No location provided' }}</span>
                        </div>
                        <span :class="['status-badge', statusTone(job.status)]">{{ formatStatus(job.status) }}</span>
                    </div>

                    <div class="job-meta-grid">
                        <div class="meta-chip">
                            <span>Category</span>
                            <strong>{{ job.service_category?.name || 'Uncategorised' }}</strong>
                        </div>
                        <div class="meta-chip">
                            <span>Technician</span>
                            <strong>{{ job.technician?.user?.name || 'Unassigned' }}</strong>
                        </div>
                    </div>

                    <div class="progress-stack">
                        <div class="mini-progress-track">
                            <div class="mini-progress-fill" :style="{ width: `${job.progress_percentage || 0}%` }"></div>
                        </div>
                        <span>{{ job.progress_percentage || 0 }}% progress</span>
                    </div>
                </article>
            </div>

            <div v-if="!recentJobs.length" class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No active jobs assigned yet</h3>
                <p>Your latest execution items will appear here once RFQs move into delivery.</p>
            </div>
        </section>

        <section class="quick-actions-section">
            <div class="section-heading">
                <div>
                    <p class="section-kicker">Shortcuts</p>
                    <h3>Quick Actions</h3>
                </div>
            </div>

            <div class="action-grid">
                <Link href="/pm/rfqs" class="action-card tone-blue">
                    <i class="fas fa-file-alt"></i>
                    <strong>Review RFQs</strong>
                    <span>Prioritize quotations, approvals, and assignment readiness.</span>
                </Link>

                <Link href="/pm/jobs" class="action-card tone-slate">
                    <i class="fas fa-tasks"></i>
                    <strong>Manage Jobs</strong>
                    <span>Track delivery progress, delays, and technician handoffs.</span>
                </Link>

                <Link href="/pm/progress-reports" class="action-card tone-orange">
                    <i class="fas fa-clipboard-check"></i>
                    <strong>Validate Progress</strong>
                    <span>Review field updates before payment and milestone decisions.</span>
                </Link>

                <Link href="/pm/payment-sheets" class="action-card tone-green">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <strong>Payment Sheets</strong>
                    <span>Prepare technician compensation from approved work progress.</span>
                </Link>

                <Link href="/pm/technicians" class="action-card tone-amber">
                    <i class="fas fa-hard-hat"></i>
                    <strong>Technicians</strong>
                    <span>Review field capacity before assigning or reassigning work.</span>
                </Link>

                <Link href="/pm/reports" class="action-card tone-slate">
                    <i class="fas fa-chart-bar"></i>
                    <strong>Reports</strong>
                    <span>Check revenue, RFQ value, and client performance trends.</span>
                </Link>
            </div>
        </section>
    </PMLayout>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import PMLayout from '../../Layouts/PMLayout.vue'

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    recentJobs: { type: Array, default: () => [] },
})

const page = usePage()

const firstName = computed(() => {
    const name = page.props.auth?.user?.name || ''
    return name.split(' ')[0] || 'PM'
})

const headlineTitle = computed(() => {
    if (Number(props.stats.pendingValidation || 0) > 0) return 'Progress validations need your attention.'
    if (Number(props.stats.pendingQuotes || 0) > 0) return 'Quotations are the next lever to keep work flowing.'
    if (Number(props.stats.activeJobs || 0) > 0) return 'Delivery is moving and worth a quick health check.'
    return 'Your PM workspace is ready for the next assignments.'
})

const headlineMessage = computed(() => {
    if (Number(props.stats.pendingValidation || 0) > 0) {
        return 'Clear validation items early so payment sheets and milestone decisions are never blocked later in the week.'
    }
    if (Number(props.stats.pendingQuotes || 0) > 0) {
        return 'A few RFQs are waiting on quoting work. Moving those forward will unblock approvals and assignment readiness.'
    }
    if (Number(props.stats.activeJobs || 0) > 0) {
        return 'Use this dashboard to keep delivery balanced between live execution, follow-ups, and commercial handoffs.'
    }

    return 'As new RFQs are assigned and jobs go live, the most important queue signals will surface here.'
})

const focusTitle = computed(() => {
    if (Number(props.stats.pendingValidation || 0) > 0) return 'Validate progress reports'
    if (Number(props.stats.pendingQuotes || 0) > 0) return 'Review RFQs needing quotes'
    if (Number(props.stats.activeJobs || 0) > 0) return 'Check live jobs'
    return 'Review your RFQ queue'
})

const focusMessage = computed(() => {
    if (Number(props.stats.pendingValidation || 0) > 0) return 'There are field updates waiting for your approval before downstream payment work can proceed.'
    if (Number(props.stats.pendingQuotes || 0) > 0) return 'RFQs still need pricing or technical confirmation before the client journey can continue.'
    if (Number(props.stats.activeJobs || 0) > 0) return 'A quick scan of active jobs helps catch delays, unassigned work, or stalled progress early.'
    return 'Start with your assigned RFQs so the next opportunities can move into approvals and execution.'
})

const focusHref = computed(() => {
    if (Number(props.stats.pendingValidation || 0) > 0) return '/pm/progress-reports'
    if (Number(props.stats.pendingQuotes || 0) > 0) return '/pm/rfqs'
    if (Number(props.stats.activeJobs || 0) > 0) return '/pm/jobs'
    return '/pm/rfqs'
})

const focusMetric = computed(() => {
    if (Number(props.stats.pendingValidation || 0) > 0) return formatNumber(props.stats.pendingValidation)
    if (Number(props.stats.pendingQuotes || 0) > 0) return formatNumber(props.stats.pendingQuotes)
    if (Number(props.stats.activeJobs || 0) > 0) return formatNumber(props.stats.activeJobs)
    return formatNumber(props.stats.assignedRfqs)
})

const focusTone = computed(() => {
    if (Number(props.stats.pendingValidation || 0) > 0) return 'tone-orange'
    if (Number(props.stats.pendingQuotes || 0) > 0) return 'tone-amber'
    if (Number(props.stats.activeJobs || 0) > 0) return 'tone-green'
    return 'tone-blue'
})

const totalVisibleWork = computed(() => {
    return Number(props.stats.assignedRfqs || 0) + Number(props.stats.activeJobs || 0) + Number(props.stats.pendingValidation || 0)
})

const unassignedRecentJobs = computed(() => props.recentJobs.filter((job) => !job.technician).length)
const inProgressRecentJobs = computed(() => props.recentJobs.filter((job) => job.status === 'in_progress').length)
const awaitingPaymentRecentJobs = computed(() => props.recentJobs.filter((job) => job.status === 'awaiting_payment').length)

const statusLabels = {
    draft_rfq: 'Draft',
    awaiting_pm_assignment: 'Awaiting PM',
    awaiting_tech_availability: 'Tech Check',
    awaiting_client_date_response: 'Client Response',
    awaiting_quote_generation: 'Needs Quote',
    awaiting_quote_approval: 'Quote Sent',
    awaiting_payment: 'Awaiting Payment',
    payment_pending_approval: 'Payment Check',
    ready_for_assignment: 'Ready',
    assigned: 'Assigned',
    queued: 'Queued',
    in_progress: 'In Progress',
    delayed: 'Delayed',
    suspended: 'Suspended',
    reassigned: 'Reassigned',
    completed_pending_confirmation: 'Completed',
    closed: 'Closed',
    archived: 'Archived',
}

function formatStatus(status) {
    return statusLabels[status] || status?.replace(/_/g, ' ') || 'Unknown'
}

function statusTone(status) {
    const map = {
        awaiting_payment: 'tone-orange',
        awaiting_quote_generation: 'tone-amber',
        assigned: 'tone-blue',
        queued: 'tone-blue',
        in_progress: 'tone-green',
        delayed: 'tone-orange',
        suspended: 'tone-slate',
        completed_pending_confirmation: 'tone-green',
        closed: 'tone-green',
    }

    return map[status] || 'tone-slate'
}

function formatNumber(value) {
    return new Intl.NumberFormat('en-KE').format(Number(value || 0))
}

function formatDate(value) {
    if (!value) return 'No date'

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value))
}

defineOptions({ layout: null })
</script>

<style scoped>
.dashboard-header-copy {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.dashboard-header-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    max-width: 58ch;
}

.welcome-chip {
    display: inline-flex;
    flex-direction: column;
    gap: 0.12rem;
    padding: 0.85rem 1rem;
    border-radius: 18px;
    background: #ffffff;
    border: 1px solid rgba(226, 232, 240, 0.95);
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
}

.welcome-chip span,
.hero-kicker,
.kpi-tag,
.section-kicker {
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.welcome-chip span {
    color: #64748b;
}

.welcome-chip strong {
    color: #0f172a;
    font-size: 1rem;
}

.dashboard-hero,
.kpi-grid,
.insights-grid {
    display: grid;
    gap: 1rem;
}

.dashboard-hero {
    grid-template-columns: minmax(0, 1.55fr) minmax(0, 0.9fr);
    margin-bottom: 1.25rem;
}

/* Defensive wrapping so long headings never overlap the focus card or
   the sidebar at narrow viewports (#5). */
.dashboard-hero h1,
.dashboard-hero h2,
.dashboard-hero h3,
.dashboard-hero p,
.dashboard-hero .hero-kicker {
    overflow-wrap: anywhere;
    word-break: break-word;
    min-width: 0;
}

.hero-card,
.kpi-card,
.panel-card,
.action-card {
    border-radius: 28px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: 0 22px 48px rgba(15, 23, 42, 0.08);
}

.hero-card {
    padding: 1.6rem 1.75rem;
}

.hero-card-primary {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.18), transparent 34%),
        linear-gradient(135deg, #ffffff, #eff6ff);
}

.hero-card-focus {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.hero-kicker,
.section-kicker,
.kpi-tag {
    color: #0284c7;
}

.hero-card h2,
.hero-card h3,
.section-heading h3 {
    margin: 0.55rem 0 0;
    color: #0f172a;
}

.hero-card p,
.kpi-footnote,
.mix-row span,
.attention-row span,
.section-link,
.entity-block span,
.entity-block small,
.action-card span,
.empty-state p,
.no-data-small {
    color: #64748b;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.25rem;
}

.hero-pill,
.section-badge,
.status-badge,
.focus-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 999px;
    font-weight: 700;
}

.hero-pill {
    padding: 0.72rem 0.95rem;
    background: rgba(226, 232, 240, 0.75);
    color: #0f172a;
}

.hero-pill.muted,
.section-badge {
    background: #f8fafc;
    color: #475569;
}

.focus-head,
.kpi-topline,
.section-heading,
.mix-row,
.attention-row,
.job-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.focus-badge {
    padding: 0.55rem 0.85rem;
}

.focus-link {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    margin-top: 0.9rem;
    color: #0f4c81;
    font-weight: 700;
    text-decoration: none;
}

.kpi-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
    margin-bottom: 1.25rem;
}

.kpi-card {
    padding: 1.25rem;
    background: #ffffff;
}

.kpi-topline i {
    color: #0f172a;
}

.kpi-label {
    display: block;
    margin-top: 0.95rem;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #475569;
}

.kpi-value {
    display: block;
    margin-top: 0.3rem;
    color: #0f172a;
    font-size: 1.65rem;
    line-height: 1.08;
}

.kpi-footnote {
    margin: 0.75rem 0 0;
    line-height: 1.55;
    font-size: 0.9rem;
}

.tone-blue {
    background: linear-gradient(180deg, #eff6ff, #dbeafe);
}

.tone-amber {
    background: linear-gradient(180deg, #fffbeb, #fef3c7);
}

.tone-green {
    background: linear-gradient(180deg, #ecfdf5, #dcfce7);
}

.tone-orange {
    background: linear-gradient(180deg, #fff7ed, #fed7aa);
}

.tone-slate {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.insights-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-bottom: 1.25rem;
}

.panel-card {
    padding: 1.35rem;
    background: #ffffff;
}

.section-link {
    font-weight: 700;
    text-decoration: none;
}

.mix-list,
.attention-list {
    display: grid;
    gap: 0.85rem;
    margin-top: 1rem;
}

.mix-row,
.attention-row {
    padding: 0.95rem 1rem;
    border-radius: 18px;
    background: #f8fafc;
}

.mix-row strong,
.attention-row strong,
.entity-block strong,
.meta-chip strong,
.action-card strong {
    color: #0f172a;
}

.jobs-table-wrap {
    margin-top: 1rem;
    overflow-x: auto;
}

.jobs-table {
    width: 100%;
    min-width: 920px;
    border-collapse: collapse;
}

.jobs-table th {
    padding: 0.9rem 1rem;
    text-align: left;
    font-size: 0.76rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    border-bottom: 1px solid rgba(226, 232, 240, 0.9);
}

.jobs-table td {
    padding: 1rem;
    border-bottom: 1px solid rgba(226, 232, 240, 0.84);
    vertical-align: top;
}

.entity-block,
.progress-stack {
    display: grid;
    gap: 0.25rem;
}

.entity-block.compact strong {
    font-size: 0.98rem;
}

.status-badge {
    width: fit-content;
    padding: 0.42rem 0.72rem;
    font-size: 0.74rem;
}

.status-badge.tone-orange {
    color: #c2410c;
    background: rgba(249, 115, 22, 0.16);
}

.status-badge.tone-amber {
    color: #b45309;
    background: rgba(245, 158, 11, 0.16);
}

.status-badge.tone-blue {
    color: #1d4ed8;
    background: rgba(59, 130, 246, 0.15);
}

.status-badge.tone-green {
    color: #15803d;
    background: rgba(34, 197, 94, 0.15);
}

.status-badge.tone-slate {
    color: #475569;
    background: rgba(148, 163, 184, 0.2);
}

.mini-progress-track {
    position: relative;
    width: 100%;
    height: 0.55rem;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
}

.mini-progress-fill {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #38bdf8, #2563eb);
}

.mobile-jobs-list {
    display: none;
    gap: 1rem;
    margin-top: 1rem;
}

.job-card {
    padding: 1rem;
    border-radius: 22px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.job-meta-grid {
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

.empty-state {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    padding: 2.5rem 1rem 1rem;
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

.quick-actions-section {
    margin-top: 1.4rem;
}

.action-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.action-card {
    display: grid;
    gap: 0.55rem;
    padding: 1.3rem;
    text-decoration: none;
}

.action-card i {
    font-size: 1.25rem;
    color: #0f172a;
}

.action-card strong {
    font-size: 1.02rem;
}

.action-card span {
    line-height: 1.55;
}

@media (max-width: 1400px) {
    .dashboard-hero {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1280px) {
    .kpi-grid,
    .insights-grid,
    .action-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 900px) {
    .dashboard-header-copy,
    .dashboard-hero,
    .insights-grid,
    .action-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-header-copy {
        flex-direction: column;
    }

    .kpi-grid {
        grid-template-columns: 1fr;
    }

    .desktop-jobs {
        display: none;
    }

    .mobile-jobs-list {
        display: grid;
    }
}

@media (max-width: 640px) {
    .job-meta-grid,
    .action-grid {
        grid-template-columns: 1fr;
    }

    .hero-card,
    .panel-card,
    .kpi-card {
        padding: 1.1rem;
    }
}
</style>
