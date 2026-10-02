<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="progress-reports" />

        <main class="main-content">
            <header class="page-head">
                <div>
                    <span class="page-kicker">Progress Reports</span>
                    <h1>What is waiting on the office</h1>
                    <p>
                        Every field report across every job, in one queue. Validate settles what counts
                        and pays the technician; signing off stands in for the on-site review a
                        single-technician job never had; releasing sends the client one update rather
                        than one per report.
                    </p>
                </div>
                <div class="pr-stats">
                    <div class="pr-stat">
                        <span class="pr-stat-value">{{ summary.pending || 0 }}</span>
                        <span class="pr-stat-label">To validate</span>
                    </div>
                    <div class="pr-stat pr-stat-warn">
                        <span class="pr-stat-value">{{ summary.awaiting_sign_off || 0 }}</span>
                        <span class="pr-stat-label">Need sign-off</span>
                    </div>
                    <div class="pr-stat pr-stat-good">
                        <span class="pr-stat-value">{{ summary.awaiting_release || 0 }}</span>
                        <span class="pr-stat-label">Ready to release</span>
                    </div>
                    <!-- Reported on site, not yet posted by the lead. The
                         office cannot action these from here — the count is so
                         that work sitting unposted is at least visible. -->
                    <div class="pr-stat" v-if="summary.held_with_lead">
                        <span class="pr-stat-value">{{ summary.held_with_lead }}</span>
                        <span class="pr-stat-label">Held with leads</span>
                    </div>
                </div>
            </header>

            <!-- Settled work the client has not been told about, job by job. A
                 batch nobody releases is a client who hears nothing, which is
                 worse than the fragmentation the batching replaced. -->
            <section v-if="releasableByJob.length" class="pr-panel pr-panel-release">
                <div class="pr-panel-head">
                    <div>
                        <span class="page-kicker">Settled, not yet sent</span>
                        <h2>Release to the client</h2>
                    </div>
                </div>

                <div class="pr-release-list">
                    <div v-for="job in releasableByJob" :key="job.id" class="pr-release-row">
                        <div class="pr-release-copy">
                            <strong>{{ job.job_reference || job.request_id }}</strong>
                            <span>
                                {{ job.count }} settled {{ job.count === 1 ? 'report' : 'reports' }} — the client hears once
                            </span>
                            <span v-if="job.needs_sign_off" class="pr-blocked">
                                <i class="fas fa-lock"></i>
                                {{ job.needs_sign_off }} of {{ job.count }} still {{ job.needs_sign_off === 1 ? 'needs' : 'need' }}
                                the office's sign-off — no lead reviewed {{ job.needs_sign_off === 1 ? 'it' : 'them' }}.
                            </span>
                        </div>
                        <div class="pr-release-actions">
                            <Link :href="`/admin/jobs/${job.id}`" class="btn btn-secondary btn-sm">
                                <i class="fas fa-up-right-from-square"></i> Open job
                            </Link>
                            <button
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="!!job.needs_sign_off || releasingJobId === job.id"
                                :title="job.needs_sign_off ? 'Sign off the unreviewed reports first' : 'Send one collective update'"
                                @click="releaseJob(job)"
                            >
                                <i class="fas fa-paper-plane"></i>
                                {{ releasingJobId === job.id ? 'Releasing…' : `Release ${job.count}` }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="pr-panel">
                <div class="pr-panel-head">
                    <div>
                        <span class="page-kicker">Queue</span>
                        <h2>{{ reports.total || reports.data?.length || 0 }} reports</h2>
                    </div>
                    <div class="pr-filters">
                        <!-- Typing a REQ here and clearing the toggle is the
                             whole history of one job's reporting, which ops
                             previously had to go to the database for. -->
                        <input
                            v-model="jobFilter"
                            type="search"
                            class="pr-search"
                            placeholder="Job reference, e.g. REQ-BGM4N7…"
                            @keyup.enter="applyFilters"
                            @search="applyFilters"
                        >
                        <label class="pr-toggle">
                            <input type="checkbox" v-model="pendingOnly" @change="applyFilters">
                            <span>Waiting on the office only</span>
                        </label>
                        <p v-if="!pendingOnly" class="pr-filter-note">
                            Showing every report, including those still held by a lead.
                        </p>
                    </div>
                </div>

                <ProgressStateLegend :states="progressStateGuide" />

                <div v-if="reports.data?.length" class="pr-list">
                    <article v-for="report in reports.data" :key="report.id" class="pr-card">
                        <div class="pr-card-head">
                            <div>
                                <h3>{{ report.service_request?.job_reference || report.service_request?.request_id }}</h3>
                                <p class="pr-meta">
                                    {{ report.sub_task?.title || 'Whole job update' }}
                                    · {{ report.technician?.user?.name || report.submitter?.name || 'Unknown' }}
                                    · {{ formatDate(report.report_date) }}
                                </p>
                            </div>
                            <div class="pr-badges">
                                <!-- Where the report stands, in one phrase.
                                     Four badges to combine was four chances to
                                     combine them wrong — a report the lead had
                                     not posted read "To validate", which is the
                                     one thing the office could not do with it. -->
                                <span :class="['status-badge', `tone-${report.pipeline_state?.tone || 'slate'}`]">
                                    {{ report.pipeline_state?.label }}
                                </span>
                                <!-- Kept beside it because it names a person,
                                     which the state cannot. -->
                                <span v-if="report.ops_verified_at && report.ops_verifier?.name" class="status-badge tone-blue">
                                    Signed off by {{ report.ops_verifier.name }}
                                </span>
                            </div>
                        </div>

                        <div class="pr-metrics">
                            <div class="pr-metric">
                                <span>Reported</span>
                                <strong>{{ report.percent_complete }}%</strong>
                            </div>
                            <div class="pr-metric">
                                <span>Validated</span>
                                <strong>{{ report.is_validated && report.validated_percent !== null ? `${report.validated_percent}%` : '—' }}</strong>
                            </div>
                            <div class="pr-metric">
                                <span>Photos</span>
                                <strong>{{ report.photos?.length || 0 }}</strong>
                            </div>
                        </div>

                        <!-- Said on the row, not on hover: whoever is working
                             this queue may never have seen it before. -->
                        <div v-if="report.pipeline_state" class="pr-explain">
                            <p class="pr-explain-waiting">
                                <i class="fas fa-hourglass-half"></i>
                                Waiting on <strong>{{ report.pipeline_state.waiting_on }}</strong>
                            </p>
                            <p class="pr-explain-meaning">{{ report.pipeline_state.meaning }}</p>
                            <p class="pr-explain-next"><strong>Next:</strong> {{ report.pipeline_state.next }}</p>
                        </div>

                        <p v-if="report.notes" class="pr-notes">{{ report.notes }}</p>

                        <div class="pr-card-actions">
                            <!-- Validating and viewing photos happen on the job
                                 page, where that form already lives. A second
                                 copy here would be two to keep in step. -->
                            <Link :href="`/admin/jobs/${report.service_request_id}`" class="btn btn-secondary btn-sm">
                                <i class="fas fa-up-right-from-square"></i>
                                {{ report.is_validated ? 'Open job' : 'Validate on the job page' }}
                            </Link>

                            <button
                                v-if="needsSignOff(report)"
                                type="button"
                                class="btn btn-primary btn-sm"
                                :disabled="signingId === report.id"
                                @click="signOff(report)"
                            >
                                <i class="fas fa-user-check"></i>
                                {{ signingId === report.id ? 'Recording…' : 'Sign off' }}
                            </button>
                        </div>
                    </article>
                </div>

                <p v-else class="pr-empty">
                    Nothing is waiting on the office. Reports appear here as technicians file them.
                </p>

                <div v-if="reports.links?.length > 3" class="pr-pagination">
                    <Link
                        v-for="link in reports.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        class="btn btn-sm"
                        :class="link.active ? 'btn-primary' : 'btn-secondary'"
                        v-html="link.label"
                    />
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminSidebar from '../../Components/AdminSidebar.vue'
import ProgressStateLegend from '../../Components/ProgressStateLegend.vue'

const props = defineProps({
    reports: { type: Object, default: () => ({ data: [] }) },
    summary: { type: Object, default: () => ({}) },
    releasableByJob: { type: Array, default: () => [] },
    // The status vocabulary, printed on the page by ProgressStateLegend.
    progressStateGuide: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
})

const pendingOnly = ref(props.filters.pending_only !== false)
const jobFilter = ref(props.filters.job || '')
const releasingJobId = ref(null)
const signingId = ref(null)

/**
 * Settled, unsent, and nobody but its author has read it. A crew report on a
 * lead-run job carries the lead's review; this one carries nothing.
 */
const needsSignOff = (report) =>
    report.is_validated
    && !report.released_to_client_at
    && !report.lead_reviewed_at
    && !report.ops_verified_at

const applyFilters = () => {
    router.get('/admin/progress-reports', {
        pending_only: pendingOnly.value,
        job: jobFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

const signOff = (report) => {
    signingId.value = report.id
    router.post(`/admin/progress-reports/${report.id}/verify`, {}, {
        preserveScroll: true,
        onFinish: () => { signingId.value = null },
    })
}

const releaseJob = (job) => {
    releasingJobId.value = job.id
    router.post(`/admin/jobs/${job.id}/release-reports`, {}, {
        preserveScroll: true,
        onFinish: () => { releasingJobId.value = null },
    })
}

const formatDate = (value) => value ? new Date(value).toLocaleDateString() : '—'
</script>

<style scoped>
.pr-stats { display: flex; gap: 0.75rem; }
.pr-stat {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.6rem 0.9rem;
    display: flex;
    flex-direction: column;
    min-width: 92px;
}
.pr-stat-warn { background: #fffbeb; border-color: #fde68a; }
.pr-stat-good { background: #ecfdf5; border-color: #a7f3d0; }
.pr-stat-value { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
.pr-stat-label { font-size: 0.75rem; color: #64748b; }

.pr-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1.15rem 1.25rem;
    margin-top: 1.25rem;
}
.pr-panel-release { background: #fffdf7; border-color: #fde68a; }
.pr-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 0.9rem;
}
.pr-panel-head h2 { margin: 0.15rem 0 0; font-size: 1.05rem; color: #0f172a; }

.pr-filters { display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap; }
.pr-search {
    padding: 0.45rem 0.7rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.85rem;
}
.pr-toggle { display: flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; color: #475569; }
/* Says what the cleared toggle widened the list to, so "every report" is not
   something the reader has to infer from the row count. */
.pr-filter-note { margin: 0; flex-basis: 100%; font-size: 0.78rem; color: #64748b; }

.pr-release-list { display: flex; flex-direction: column; gap: 0.6rem; }
.pr-release-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.9rem;
    flex-wrap: wrap;
    background: #fff;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: 0.7rem 0.9rem;
}
.pr-release-copy { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.pr-release-copy span { font-size: 0.82rem; color: #64748b; }
.pr-blocked { color: #92400e !important; font-weight: 600; }
.pr-release-actions { display: flex; gap: 0.45rem; }

.pr-list { display: flex; flex-direction: column; gap: 0.7rem; }
.pr-card {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.9rem 1rem;
    background: #fff;
}
.pr-card-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-start;
}
.pr-card-head h3 { margin: 0; font-size: 0.95rem; color: #0f172a; }
.pr-meta { margin: 0.15rem 0 0; font-size: 0.8rem; color: #64748b; }
.pr-badges { display: flex; gap: 0.35rem; flex-wrap: wrap; }

.pr-metrics { display: flex; gap: 1.25rem; margin-top: 0.7rem; }
.pr-metric { display: flex; flex-direction: column; }
.pr-metric span { font-size: 0.72rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; }
.pr-metric strong { font-size: 0.95rem; color: #0f172a; }

/* Why this row is where it is, and what to do with it. */
.pr-explain {
    margin: 0.6rem 0 0;
    padding: 0.6rem 0.75rem;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #eef2f7;
}
.pr-explain p { margin: 0; font-size: 0.82rem; line-height: 1.5; color: #475569; }
.pr-explain-waiting { color: #64748b !important; font-size: 0.78rem !important; }
.pr-explain-waiting strong { color: #334155; }
.pr-explain-waiting i { margin-right: 0.3rem; color: #94a3b8; }
.pr-explain-meaning { margin-top: 0.3rem !important; }
.pr-explain-next { margin-top: 0.35rem !important; }
.pr-explain-next strong { color: #1e293b; }

.pr-notes {
    margin: 0.7rem 0 0;
    font-size: 0.85rem;
    color: #475569;
    background: #f8fafc;
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
}
.pr-card-actions { display: flex; gap: 0.45rem; margin-top: 0.8rem; flex-wrap: wrap; }
.pr-empty { color: #64748b; font-size: 0.88rem; margin: 0; }
.pr-pagination { display: flex; gap: 0.35rem; flex-wrap: wrap; margin-top: 1rem; }

.tone-amber { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.tone-blue { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
</style>
