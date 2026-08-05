<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <div class="header-main">
                <Link href="/technician/jobs" class="btn btn-outline btn-sm header-back">
                    <i class="fas fa-arrow-left"></i>
                </Link>
                <div>
                    <h1>{{ job.job_reference || job.request_id || `Job #${job.id}` }}</h1>
                    <p class="header-subtitle">{{ job.service_category?.name || 'Service Job' }}</p>
                </div>
            </div>
            <span class="status-badge" :class="getStatusClass(job.status)">
                {{ formatStatus(job.status) }}
            </span>
        </header>

        <main class="pwa-content job-page">
            <section class="hero-card">
                <div class="hero-copy">
                    <span class="hero-kicker">Field Job</span>
                    <h2>{{ job.service_category?.name || 'Service Request' }}</h2>
                    <p>{{ job.description || 'No job description was provided for this request.' }}</p>
                </div>

                <div class="hero-meta-grid">
                    <div class="meta-tile">
                        <span>Location</span>
                        <strong>{{ job.location || 'On site' }}</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Progress</span>
                        <strong>{{ Number(job.progress_percentage || 0) }}%</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Reports</span>
                        <strong>{{ progressReports.length }}</strong>
                    </div>
                </div>
            </section>

            <section class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Client</span>
                        <h3>Client details</h3>
                    </div>
                </div>

                <div class="info-list">
                    <div class="info-row">
                        <span>Name</span>
                        <strong>{{ job.user?.name || 'Guest client' }}</strong>
                    </div>
                    <div class="info-row">
                        <span>Email</span>
                        <strong>{{ job.user?.email || 'Not provided' }}</strong>
                    </div>
                    <div class="info-row">
                        <span>Phone</span>
                        <strong>{{ job.user?.phone || 'Not provided' }}</strong>
                    </div>
                </div>

                <a v-if="job.user?.phone" :href="'tel:' + job.user.phone" class="btn btn-outline full-width">
                    <i class="fas fa-phone"></i>
                    Call Client
                </a>
            </section>

            <!-- What the client sent in — seen before going back to site,
                 rather than found in a WhatsApp thread afterwards. -->
            <section class="panel-card" v-if="jobPhotos.length">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Evidence</span>
                        <h3>Photos on this job</h3>
                    </div>
                    <span class="section-pill">{{ jobPhotos.length }}</span>
                </div>

                <JobPhotoGallery :photos="jobPhotos" />
            </section>

            <section class="panel-card" v-if="compensationSummary">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Compensation</span>
                        <h3>Paid and owed on this job</h3>
                    </div>
                </div>

                <div class="hero-meta-grid">
                    <div class="meta-tile">
                        <span>Agreed</span>
                        <strong>{{ formatCurrency(compensationSummary.agreed_compensation) }}</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Paid so far</span>
                        <strong style="color: var(--success-color);">{{ formatCurrency(compensationSummary.paid_to_date) }}</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Still owed</span>
                        <strong style="color: #c2410c;">{{ formatCurrency(compensationSummary.outstanding_balance) }}</strong>
                    </div>
                </div>

                <div v-if="compensationSummary.latest_progress_pct" class="info-list" style="margin-top: 1rem;">
                    <div class="info-row">
                        <span>Latest approved progress</span>
                        <strong>{{ compensationSummary.latest_progress_pct }}%</strong>
                    </div>
                    <div class="info-row">
                        <span>Latest approved amount</span>
                        <strong>{{ formatCurrency(compensationSummary.latest_period_payable) }}</strong>
                    </div>
                </div>

                <div v-if="recentPayouts.length" style="margin-top: 1rem;">
                    <span class="section-kicker">Recent payouts</span>
                    <div class="info-list" style="margin-top: 0.5rem;">
                        <div v-for="entry in recentPayouts" :key="`${entry.type}-${entry.reference}-${entry.date}`" class="info-row">
                            <span>{{ entry.label }}{{ entry.date ? ` • ${formatShortDate(entry.date)}` : '' }}</span>
                            <strong>{{ formatCurrency(entry.amount) }}</strong>
                        </div>
                    </div>
                </div>
            </section>

            <!-- What was quoted and the dates being held to. The technician
                 was working off a description and a location alone; the scope,
                 the material list and the programme all sat on the REQ where
                 only the office could see them. Client pricing stays out —
                 the fee that matters to them is in Compensation above. -->
            <section class="panel-card" v-if="hasScopeDetail">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">RFQ</span>
                        <h3>Scope &amp; programme</h3>
                    </div>
                </div>

                <p v-if="scope.notes" class="scope-notes">{{ scope.notes }}</p>

                <div
                    v-if="scope.commencement_at || scope.target_completion_at || scope.expected_duration_days"
                    class="info-list"
                    style="margin-top: 1rem;"
                >
                    <div v-if="scope.commencement_at" class="info-row">
                        <span>Start on site</span>
                        <strong>{{ formatShortDate(scope.commencement_at) }}</strong>
                    </div>
                    <div v-if="scope.target_completion_at" class="info-row">
                        <span>Target completion</span>
                        <strong>{{ formatShortDate(scope.target_completion_at) }}</strong>
                    </div>
                    <div v-if="scope.expected_duration_days" class="info-row">
                        <span>Expected duration</span>
                        <strong>{{ scope.expected_duration_days }} day{{ scope.expected_duration_days == 1 ? '' : 's' }}</strong>
                    </div>
                </div>

                <div v-if="scopeMaterials.length" style="margin-top: 1rem;">
                    <span class="section-kicker">Materials on this job</span>
                    <div class="info-list" style="margin-top: 0.5rem;">
                        <div v-for="(material, index) in scopeMaterials" :key="`${material.name}-${index}`" class="info-row">
                            <span>{{ material.name }}</span>
                            <strong v-if="material.quantity">Qty {{ material.quantity }}</strong>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Drawings and briefs: the files attached to this technician's
                 own assignment, plus anything ops deliberately shared on the
                 job. Internal documents stay internal. -->
            <section class="panel-card" v-if="hasJobFiles">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Documents</span>
                        <h3>Drawings &amp; briefs</h3>
                    </div>
                </div>

                <div class="info-list">
                    <a
                        v-for="(file, index) in assignmentFiles"
                        :key="`assignment-${index}`"
                        class="info-row file-row"
                        :href="`/storage/${file.path}`"
                        target="_blank"
                        rel="noopener"
                    >
                        <span><i class="fas fa-paperclip"></i> {{ file.name }}</span>
                        <strong>Open</strong>
                    </a>
                    <a
                        v-for="doc in sharedDocuments"
                        :key="`doc-${doc.id}`"
                        class="info-row file-row"
                        :href="`/storage/${doc.path}`"
                        target="_blank"
                        rel="noopener"
                    >
                        <span><i class="fas fa-file-alt"></i> {{ doc.title || doc.original_name }}</span>
                        <strong>Open</strong>
                    </a>
                </div>
            </section>

            <section class="panel-card" v-if="job.sub_tasks?.length">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Sub-Tasks</span>
                        <h3>Assigned work items</h3>
                    </div>
                </div>

                <div class="subtask-list">
                    <article
                        v-for="task in job.sub_tasks"
                        :key="task.id"
                        class="subtask-card"
                        :class="{ 'subtask-mine': isMyTask(task) }"
                    >
                        <div class="subtask-top">
                            <div>
                                <strong>{{ task.title || task.name }}</strong>
                                <!-- On a job with several trades, which item is
                                     actually yours was left to be inferred from
                                     the name underneath. -->
                                <span v-if="isMyTask(task)" class="mine-pill">Your task</span>
                                <p>{{ task.description || 'No sub-task description.' }}</p>
                            </div>
                            <span class="status-badge" :class="getStatusClass(task.status)">
                                {{ formatStatus(task.status) }}
                            </span>
                        </div>

                        <div class="subtask-meta">
                            <span>Assigned to {{ task.technician?.user?.name || 'Unassigned' }}</span>
                            <strong>{{ task.progress_percentage || 0 }}%</strong>
                        </div>

                        <!-- The amount agreed for this item. Only ever present
                             on the viewer's own sub-tasks — a colleague's fee
                             is not serialised to them at all. -->
                        <div v-if="task.agreed_compensation" class="subtask-fee">
                            <span>Agreed for this task</span>
                            <strong>{{ formatCurrency(task.agreed_compensation) }}</strong>
                        </div>

                        <input
                            type="range"
                            min="0"
                            max="100"
                            step="10"
                            :value="pendingClaimFor(task)?.percent_complete ?? task.progress_percentage ?? 0"
                            :disabled="!canUpdateTask(task) || !!pendingClaimFor(task)"
                            @change="(event) => updateTaskProgress(task, event.target.value)"
                        >

                        <!-- A claim counts only once someone signs it off, so
                             say plainly that it is waiting rather than showing
                             a bar that looks banked. -->
                        <p v-if="pendingClaimFor(task)" class="subtask-pending">
                            <i class="fas fa-hourglass-half"></i>
                            {{ pendingClaimFor(task).percent_complete }}% submitted by
                            {{ pendingClaimFor(task).technician?.user?.name || 'the technician' }} —
                            <template v-if="canSignOffClaim(pendingClaimFor(task))">awaiting your approval.</template>
                            <template v-else-if="isLeadTechnician">awaiting the project team.</template>
                            <template v-else>awaiting approval.</template>
                        </p>

                        <!-- Sent back: the technician needs to know why, not
                             just that their bar never moved. -->
                        <p v-else-if="rejectedClaimFor(task)" class="subtask-rejected">
                            <i class="fas fa-rotate-left"></i>
                            {{ rejectedClaimFor(task).percent_complete }}% was sent back<span
                                v-if="rejectedClaimFor(task).rejector?.name"
                            > by {{ rejectedClaimFor(task).rejector.name }}</span>:
                            <strong>{{ rejectedClaimFor(task).rejection_reason }}</strong>
                            <span v-if="isMyTask(task)"> Put it right and submit again.</span>
                        </p>

                        <div v-if="canSignOffClaim(pendingClaimFor(task))" class="signoff-actions">
                            <button
                                class="btn btn-primary"
                                :disabled="busyReportId === pendingClaimFor(task).id"
                                @click="approveClaim(pendingClaimFor(task))"
                            >
                                <i class="fas fa-check"></i>
                                {{ busyReportId === pendingClaimFor(task).id ? 'Working…' : 'Approve' }}
                            </button>
                            <button
                                class="btn btn-outline btn-reject"
                                :disabled="busyReportId === pendingClaimFor(task).id"
                                @click="openReject(pendingClaimFor(task))"
                            >
                                <i class="fas fa-rotate-left"></i>
                                Send back
                            </button>
                        </div>

                        <!-- Reason is required: sending work back without one
                             leaves the technician nothing to act on. -->
                        <div v-if="rejectingClaim && rejectingClaim.id === pendingClaimFor(task)?.id" class="reject-box">
                            <label class="form-field">
                                <span>Why is this being sent back?</span>
                                <textarea
                                    v-model="rejectReason"
                                    rows="3"
                                    class="input textarea"
                                    placeholder="e.g. Only four of the eight diffusers are actually fitted."
                                ></textarea>
                            </label>
                            <p v-if="rejectError" class="reject-error">{{ rejectError }}</p>
                            <div class="signoff-actions">
                                <button
                                    class="btn btn-primary"
                                    :disabled="busyReportId === rejectingClaim.id"
                                    @click="confirmReject()"
                                >
                                    {{ busyReportId === rejectingClaim.id ? 'Sending…' : 'Send back' }}
                                </button>
                                <button class="btn btn-outline" @click="cancelReject()">Cancel</button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Progress Update</span>
                        <h3>Submit field progress</h3>
                    </div>
                </div>

                <div v-if="page.props.flash?.success" class="flash-success" style="background:var(--success-color,#22c55e);color:#fff;padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-weight:500;">
                    {{ page.props.flash.success }}
                </div>

                <div v-if="job.status === 'assigned'" class="info-banner" style="background:var(--warning-bg,#fef9c3);border:1px solid var(--warning-border,#fde047);color:var(--warning-text,#713f12);padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;">
                    <i class="fas fa-info-circle"></i>
                    You must <strong>Start the Job</strong> before submitting progress reports.
                </div>

                <form v-else @submit.prevent="submitProgressReport" class="progress-form">
                    <div class="form-grid">
                        <label class="form-field">
                            <span>Progress %</span>
                            <input v-model.number="progressForm.percent_complete" type="number" min="0" max="100" class="input">
                        </label>

                        <label class="form-field">
                            <span>Report date</span>
                            <input v-model="progressForm.report_date" type="date" class="input">
                        </label>
                    </div>

                    <label class="form-field" v-if="job.sub_tasks?.length">
                        <span>Sub-task</span>
                        <select v-model="progressForm.service_sub_task_id" class="input">
                            <!-- The job as a whole is the lead's to report on.
                                 Everyone else reports their own sub-task, and
                                 those roll up into the job's percentage. -->
                            <option v-if="isLeadTechnician" value="">Whole job update</option>
                            <option
                                v-for="task in reportableSubTasks"
                                :key="task.id"
                                :value="task.id"
                            >
                                {{ task.title || task.name }}
                            </option>
                        </select>
                        <small v-if="!isLeadTechnician" class="field-hint">
                            Your sub-task progress counts towards the job's overall figure.
                        </small>
                    </label>

                    <label class="form-field">
                        <span>Notes</span>
                        <textarea v-model="progressForm.notes" rows="4" class="input textarea" placeholder="Describe what was completed, what remains, and anything the PM/admin should know."></textarea>
                    </label>

                    <!-- A div, not a label: the uploader renders its own buttons
                         and thumbnails, and a wrapping label would hijack taps
                         on them. -->
                    <div class="form-field">
                        <span>Progress photos</span>
                        <PhotoUploader
                            ref="photoUploader"
                            v-model="photos"
                            :max="6"
                            :disabled="submittingReport"
                            hint="Up to 6 photos showing actual site progress. Take them one at a time or pick several from the gallery — they add up rather than replace each other."
                            @busy="preparingPhotos = $event"
                        />
                    </div>

                    <!-- Blocked while photos are still being resized, so a fast
                         tap can't submit a half-prepared queue. -->
                    <button type="submit" class="btn btn-primary" :disabled="submittingReport || preparingPhotos">
                        <span v-if="preparingPhotos">
                            <i class="fas fa-spinner fa-spin"></i> Preparing photos…
                        </span>
                        <span v-else-if="!submittingReport">
                            <i class="fas fa-camera"></i> Submit Progress Report
                        </span>
                        <span v-else>
                            <i class="fas fa-spinner fa-spin"></i>
                            {{ uploadStage || 'Submitting…' }}
                        </span>
                    </button>

                    <!-- Inline progress bar while uploading. Visible to technician
                         on the field so they know to keep the page open. -->
                    <div v-if="submittingReport" class="upload-progress" style="margin-top:.75rem;">
                        <div class="upload-progress-bar" style="background:#e5e7eb;height:6px;border-radius:999px;overflow:hidden;">
                            <div :style="{
                                width: (uploadPercent || 25) + '%',
                                height: '100%',
                                background: 'linear-gradient(90deg,#10b981,#059669)',
                                transition: 'width .4s ease',
                            }"></div>
                        </div>
                        <p style="font-size:.78rem;color:var(--text-muted);margin:.4rem 0 0;text-align:center;">
                            Please keep this screen open until you see the confirmation.
                        </p>
                    </div>
                </form>
            </section>

            <section class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">History</span>
                        <h3>Recent progress reports</h3>
                    </div>
                    <span class="section-pill">{{ progressReports.length }} total</span>
                </div>

                <div v-if="progressReports.length" class="report-list">
                    <article v-for="report in progressReports" :key="report.id" class="report-card">
                        <div class="report-top">
                            <div>
                                <strong>{{ formatShortDate(report.report_date) }}</strong>
                                <p>
                                    Submitted by {{ report.submitter?.name || report.technician?.user?.name || 'Unknown' }}
                                    <span v-if="report.sub_task"> • {{ report.sub_task.title || report.sub_task.name }}</span>
                                </p>
                            </div>
                            <span :class="['status-badge', report.is_validated ? 'status-approved' : 'status-pending']">
                                {{ report.is_validated
                                    ? `Approved · ${report.validated_percent ?? report.percent_complete}%`
                                    : `Awaiting PM validation · ${report.percent_complete}% submitted` }}
                            </span>
                        </div>

                        <p v-if="report.notes" class="report-notes">{{ report.notes }}</p>
                        <p v-if="report.validation_notes" class="report-validation-note">
                            PM/Admin note: {{ report.validation_notes }}
                        </p>

                        <!-- Tapping a photo used to dump the raw file into a
                             new browser tab, which on a phone leaves the PWA
                             entirely. Now it opens the swipeable carousel. -->
                        <JobPhotoGallery :photos="report.photos" show-removed-badge />
                    </article>
                </div>

                <p v-else class="empty-text">No progress reports submitted yet.</p>
            </section>

            <section class="floating-actions">
                <button
                    v-if="job.status === 'assigned'"
                    class="btn btn-primary"
                    @click="updateStatus('en_route')"
                >
                    Start Job
                </button>
                <button
                    v-else-if="job.status === 'in_progress' && !job.technician_arrived"
                    class="btn btn-primary"
                    @click="updateStatus('on_site')"
                >
                    Arrived On Site
                </button>
                <div v-else-if="job.status === 'in_progress'" class="floating-action-grid">
                    <Link href="/technician/tools" class="btn btn-outline">
                        <i class="fas fa-tools"></i>
                        Tools
                    </Link>
                    <!-- Closing the whole job is the lead's call. A sub-task
                         holder finishes their own item instead. -->
                    <button
                        v-if="isLeadTechnician"
                        class="btn btn-primary complete-btn"
                        @click="updateStatus('completed')"
                    >
                        Mark Complete
                    </button>
                    <span v-else-if="mySubTasks.length" class="lead-closes-note">
                        Set your sub-task to 100% — the lead closes the job.
                    </span>
                </div>
            </section>
        </main>

        <TechnicianBottomNav current-page="jobs" />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue'
import PhotoUploader from '@/Components/PhotoUploader.vue'
import JobPhotoGallery from '@/Components/JobPhotoGallery.vue'

const page = usePage()

const props = defineProps({
    technician: { type: Object, required: true },
    job: { type: Object, required: true },
    compensationSummary: { type: Object, default: null },
    isLeadTechnician: { type: Boolean, default: false },
    scope: { type: Object, default: () => ({}) },
    assignmentFiles: { type: Array, default: () => [] },
})

// What was quoted, what to install, and the dates being held to. Deliberately
// carries no client pricing — see TechnicianController::jobScopeForTechnician.
const scopeMaterials = computed(() => props.scope?.materials || [])
const sharedDocuments = computed(() => props.job.documents || [])
const hasScopeDetail = computed(() =>
    Boolean(
        props.scope?.notes ||
        scopeMaterials.value.length ||
        props.scope?.expected_duration_days ||
        props.scope?.commencement_at ||
        props.scope?.target_completion_at,
    ),
)
const hasJobFiles = computed(() =>
    props.assignmentFiles.length > 0 || sharedDocuments.value.length > 0,
)

// Camera and gallery picks both land here, already compressed by the
// uploader (#27).
const photoUploader = ref(null)
const photos = ref([])
const preparingPhotos = ref(false)

const submittingReport = ref(false)
const uploadStage = ref('')
const uploadPercent = ref(0)
// A technician who does not lead the job has no "whole job" option, so the
// form opens on their own sub-task rather than on a choice the server will
// reject — and on that sub-task's progress, not the job's blended figure.
const ownSubTasks = (props.job.sub_tasks || []).filter(
    (task) => task.technician_id === props.technician.id,
)
const defaultSubTaskId =
    !props.isLeadTechnician && ownSubTasks.length ? ownSubTasks[0].id : ''

const progressForm = ref({
    percent_complete: Number(
        (defaultSubTaskId ? ownSubTasks[0].progress_percentage : props.job.progress_percentage) || 0,
    ),
    report_date: new Date().toISOString().slice(0, 10),
    notes: '',
    service_sub_task_id: defaultSubTaskId,
})

const progressReports = computed(() => props.job.progress_reports || [])
const jobPhotos = computed(() => props.job.photos || [])
const recentPayouts = computed(() => (props.compensationSummary?.history || []).slice(0, 3))

// Mirrors the server rule: your own sub-task, or all of them when you
// carry the job (lead / sole assignee).
const reportableSubTasks = computed(() => {
    return (props.job.sub_tasks || []).filter(
        (task) => props.isLeadTechnician || props.technician.id === task.technician_id,
    )
})

// The sub-tasks this technician is personally on — what they update from
// the job page when someone else leads the project.
const mySubTasks = computed(() =>
    (props.job.sub_tasks || []).filter((task) => task.technician_id === props.technician.id),
)

function getStatusClass(status) {
    const map = {
        assigned: 'status-assigned',
        in_progress: 'status-in_progress',
        completed: 'status-completed',
        completed_pending_confirmation: 'status-completed',
        pending: 'status-pending',
        approved: 'status-approved',
    }

    return map[status] || 'status-pending'
}

function formatStatus(status) {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Unknown'
}

function formatShortDate(dateString) {
    if (!dateString) return 'No date'

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(dateString))
}

function formatCurrency(amount) {
    return 'KES ' + Number(amount || 0).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

function isMyTask(task) {
    return props.technician.id === task.technician_id
}

function canUpdateTask(task) {
    return props.isLeadTechnician || isMyTask(task)
}

function claimsFor(task) {
    return progressReports.value
        .filter((report) => report.service_sub_task_id === task.id)
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
}

// The newest claim still waiting on somebody. Until it is signed off the
// sub-task's own percentage is still the banked figure. A claim that was sent
// back is not waiting — it is the technician's to redo.
function pendingClaimFor(task) {
    return claimsFor(task).find((report) => !report.is_validated && !report.rejected_at)
}

// Shown only when the most recent word on the sub-task was a rejection.
function rejectedClaimFor(task) {
    const latest = claimsFor(task)[0]
    return latest?.rejected_at ? latest : undefined
}

// The lead settles the crew's claims. Their own go to the project team, so
// nobody rules on their own work.
function canSignOffClaim(claim) {
    return Boolean(
        claim &&
        props.isLeadTechnician &&
        claim.technician_id !== props.technician.id,
    )
}

const busyReportId = ref(null)
const rejectingClaim = ref(null)
const rejectReason = ref('')
const rejectError = ref('')

function approveClaim(claim) {
    if (!claim) return
    busyReportId.value = claim.id
    router.post(`/technician/progress-reports/${claim.id}/approve`, {}, {
        preserveScroll: true,
        onFinish: () => { busyReportId.value = null },
    })
}

function openReject(claim) {
    rejectingClaim.value = claim
    rejectReason.value = ''
    rejectError.value = ''
}

function cancelReject() {
    rejectingClaim.value = null
    rejectReason.value = ''
    rejectError.value = ''
}

function confirmReject() {
    const claim = rejectingClaim.value
    if (!claim) return

    if (rejectReason.value.trim().length < 5) {
        rejectError.value = 'Give the technician something to act on — a few words at least.'
        return
    }

    busyReportId.value = claim.id
    router.post(`/technician/progress-reports/${claim.id}/reject`, {
        rejection_reason: rejectReason.value.trim(),
    }, {
        preserveScroll: true,
        onSuccess: () => cancelReject(),
        onError: (errors) => { rejectError.value = errors.rejection_reason || 'Could not send that back.' },
        onFinish: () => { busyReportId.value = null },
    })
}

function updateTaskProgress(task, value) {
    router.post(`/technician/sub-tasks/${task.id}/progress`, {
        progress_percentage: parseInt(value, 10),
    }, {
        preserveScroll: true,
    })
}

function updateStatus(action) {
    if (!confirm(`Update job status to ${action.replace('_', ' ')}?`)) return

    router.post(`/technician/jobs/${props.job.id}/status`, { action }, {
        preserveScroll: true,
    })
}

async function submitProgressReport() {
    if (preparingPhotos.value) return

    submittingReport.value = true
    uploadStage.value = 'Uploading…'

    // Photos arrive already compressed from PhotoUploader — without that a
    // 5-photo submission from an iPhone over 4G easily tops 30 MB and times
    // out.
    const allFiles = photos.value

    const formData = new FormData()
    formData.append('percent_complete', progressForm.value.percent_complete)
    formData.append('report_date', progressForm.value.report_date)
    formData.append('notes', progressForm.value.notes || '')

    if (progressForm.value.service_sub_task_id) {
        formData.append('service_sub_task_id', progressForm.value.service_sub_task_id)
    }

    allFiles.forEach((file, index) => {
        formData.append(`photos[${index}]`, file)
    })

    // STAGE 2 — upload with real-time progress feedback
    uploadPercent.value = 0
    router.post(`/technician/jobs/${props.job.id}/progress-report`, formData, {
        forceFormData: true,
        preserveScroll: true,
        onProgress: (event) => {
            if (event?.percentage != null) {
                uploadPercent.value = event.percentage
                if (event.percentage < 100) {
                    uploadStage.value = `Uploading… ${event.percentage}%`
                } else {
                    uploadStage.value = 'Saving your report…'
                }
            }
        },
        onSuccess: () => {
            submittingReport.value = false
            uploadStage.value = ''
            uploadPercent.value = 0
            progressForm.value.notes = ''
            // Back to their own sub-task, not to a "whole job" they may not
            // be allowed to report on.
            progressForm.value.service_sub_task_id = defaultSubTaskId
            // Only clear the queue on success — on failure the technician
            // keeps their photos and can retry without re-shooting.
            photoUploader.value?.reset()
        },
        onError: () => {
            submittingReport.value = false
            uploadStage.value = ''
            uploadPercent.value = 0
        },
    })
}

defineOptions({ layout: null })
</script>

<style scoped>

.job-page {
    display: grid;
    gap: 1rem;
    padding-bottom: calc(var(--nav-height) + 5rem);
}

.header-main {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.header-back {
    width: auto;
    padding: 0.5rem;
    border: none;
    font-size: 1rem;
}

.header-subtitle {
    margin: 0.15rem 0 0;
    font-size: 0.8rem;
    color: var(--light-text);
}

.hero-card,
.panel-card,
.meta-tile,
.subtask-card,
.report-card,
.photo-card {
    border-radius: 16px;
    border: 1px solid var(--border-color);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
}

.hero-card,
.panel-card {
    background: var(--white);
    padding: 1rem;
}

.hero-card {
    background:
        radial-gradient(circle at top right, rgba(56, 189, 248, 0.18), transparent 30%),
        linear-gradient(180deg, #ffffff, #f8fbff);
}

.hero-kicker,
.section-kicker {
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #0f6c8f;
}

.hero-copy h2,
.section-heading h3 {
    margin: 0.35rem 0 0;
    color: var(--text-color);
}

.hero-copy p,
.subtask-top p,
.report-top p,
.report-notes,
.report-validation-note,
.form-hint,
.empty-text {
    color: var(--light-text);
}

.hero-meta-grid,
.form-grid {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 1rem;
}

.meta-tile {
    background: rgba(255, 255, 255, 0.9);
    padding: 0.85rem;
}

.meta-tile span,
.info-row span,
.subtask-meta span,
.section-pill {
    color: var(--light-text);
    font-size: 0.78rem;
}

.meta-tile strong,
.info-row strong,
.subtask-meta strong,
.subtask-top strong,
.report-top strong {
    display: block;
    margin-top: 0.25rem;
    color: var(--text-color);
}

.section-heading,
.subtask-top,
.subtask-meta,
.report-top,
.floating-action-grid {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.section-pill {
    display: inline-flex;
    padding: 0.42rem 0.72rem;
    border-radius: 999px;
    background: #f1f5f9;
    font-weight: 700;
}

.info-list,
.progress-form,
.subtask-list,
.report-list {
    display: grid;
    gap: 0.75rem;
}

.info-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.8rem 0;
    border-bottom: 1px solid var(--border-color);
}

.info-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.full-width {
    width: 100%;
    margin-top: 0.75rem;
}

.subtask-card,
.report-card {
    background: #f8fafc;
    padding: 0.9rem;
}

.subtask-meta {
    margin: 0.75rem 0 0.5rem;
    align-items: center;
}

.form-field {
    display: grid;
    gap: 0.35rem;
}

.form-field span {
    font-size: 0.78rem;
    font-weight: 700;
    color: #475569;
}

.input {
    width: 100%;
    border: 1px solid #d7dee7;
    border-radius: 12px;
    padding: 0.8rem 0.9rem;
    background: #ffffff;
    color: var(--text-color);
    font: inherit;
}

.textarea {
    min-height: 110px;
    resize: vertical;
}

.form-hint {
    font-size: 0.75rem;
}

.report-notes,
.report-validation-note {
    margin: 0.65rem 0 0;
    line-height: 1.55;
}

.report-validation-note {
    color: #0f6c8f;
    font-weight: 600;
}

.photo-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin-top: 0.9rem;
}

.photo-card {
    position: relative;
    overflow: hidden;
    display: block;
    min-height: 110px;
    background: #e2e8f0;
}

.photo-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.photo-flag {
    position: absolute;
    left: 0.5rem;
    bottom: 0.5rem;
    padding: 0.3rem 0.55rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.78);
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 700;
}

.floating-actions {
    position: fixed;
    left: 1rem;
    right: 1rem;
    bottom: calc(var(--nav-height) + 0.9rem);
    z-index: 20;
}

.floating-action-grid {
    gap: 0.5rem;
}

.floating-action-grid .btn {
    flex: 1;
}

.complete-btn {
    background: var(--success-color);
}

.subtask-mine {
    border-left: 3px solid var(--primary-color);
}

.mine-pill {
    display: inline-block;
    margin-left: .45rem;
    padding: .1rem .45rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: .68rem;
    font-weight: 700;
    vertical-align: middle;
}

.subtask-pending {
    margin: .5rem 0 0;
    font-size: .8rem;
    line-height: 1.4;
    color: #92400e;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: .45rem;
    padding: .5rem .6rem;
}

.subtask-rejected {
    margin: .5rem 0 0;
    font-size: .8rem;
    line-height: 1.45;
    color: #9f1239;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-radius: .45rem;
    padding: .5rem .6rem;
}

.signoff-actions {
    display: flex;
    gap: .5rem;
    margin-top: .6rem;
}

.signoff-actions .btn {
    flex: 1;
}

.btn-reject {
    border-color: #fecdd3;
    color: #9f1239;
}

.reject-box {
    margin-top: .6rem;
    padding: .7rem;
    border: 1px dashed #cbd5e1;
    border-radius: .5rem;
    background: #f8fafc;
}

.reject-error {
    margin: .4rem 0 0;
    font-size: .78rem;
    color: #b91c1c;
}

.field-hint {
    display: block;
    margin-top: .35rem;
    font-size: .78rem;
    color: var(--text-muted, #64748b);
}

.scope-notes {
    white-space: pre-wrap;
    line-height: 1.55;
    color: var(--text-muted, #475569);
    margin: 0;
}

.subtask-fee {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    margin-top: .6rem;
    padding-top: .6rem;
    border-top: 1px dashed #e2e8f0;
    font-size: .85rem;
}

.subtask-fee span { color: var(--text-muted, #64748b); }

.file-row {
    text-decoration: none;
    color: inherit;
}

.file-row strong { color: var(--primary-color); }

.lead-closes-note {
    flex: 1;
    align-self: center;
    font-size: .8rem;
    line-height: 1.35;
    color: var(--text-muted, #64748b);
}

@media (max-width: 640px) {
    .hero-meta-grid,
    .form-grid,
    .photo-grid {
        grid-template-columns: 1fr;
    }

    .section-heading,
    .report-top,
    .subtask-top,
    .subtask-meta {
        flex-direction: column;
    }
}
</style>
