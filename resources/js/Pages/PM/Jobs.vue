<template>
    <PMLayout>
        <template #header>
            <div class="page-header-copy">
                <div>
                    <h1>Job Management</h1>
                    <p>Track delivery, spot blockers early, and act quickly on assignments, reassignments, and suspended work.</p>
                </div>
            </div>
        </template>

        <section class="jobs-hero">
            <div class="hero-card hero-card-primary">
                <span class="hero-kicker">Delivery Workspace</span>
                <h2>{{ heroTitle }}</h2>
                <p>{{ heroMessage }}</p>

                <div class="hero-pills">
                    <span class="hero-pill">
                        <i class="fas fa-briefcase"></i>
                        {{ summary.total || 0 }} tracked jobs
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-filter"></i>
                        {{ activeStatusLabel }}
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-search"></i>
                        {{ search ? `Searching "${search}"` : 'All active filters cleared' }}
                    </span>
                </div>
            </div>

            <form class="hero-card filter-card" @submit.prevent="applyFilter">
                <div>
                    <span class="hero-kicker">Filters</span>
                    <h3>Refine the job list</h3>
                    <p>Search by reference, client, description, or location and narrow the queue by status.</p>
                </div>

                <div class="filter-grid">
                    <label class="filter-field">
                        <span>Status</span>
                        <select v-model="filterStatus" class="filter-input">
                            <option value="">All statuses</option>
                            <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </label>

                    <label class="filter-field">
                        <span>Search</span>
                        <input
                            v-model="search"
                            type="text"
                            class="filter-input"
                            placeholder="Reference, client, description, location..."
                        >
                    </label>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm filter-button">
                        <i class="fas fa-filter"></i>
                        Apply filters
                    </button>
                    <button
                        v-if="filterStatus || search"
                        type="button"
                        class="btn btn-secondary btn-sm filter-button filter-button-secondary"
                        @click="clearFilters"
                    >
                        Clear
                    </button>
                </div>
            </form>
        </section>

        <section class="summary-grid">
            <article class="summary-card tone-slate">
                <div class="summary-topline">
                    <span class="summary-tag">Portfolio</span>
                    <i class="fas fa-layer-group"></i>
                </div>
                <span class="summary-label">Total Jobs</span>
                <strong class="summary-value">{{ summary.total || 0 }}</strong>
                <p class="summary-note">All jobs currently under your PM scope.</p>
            </article>

            <article class="summary-card tone-blue">
                <div class="summary-topline">
                    <span class="summary-tag">Ready</span>
                    <i class="fas fa-user-plus"></i>
                </div>
                <span class="summary-label">Ready for Assignment</span>
                <strong class="summary-value">{{ summary.ready_for_assignment || 0 }}</strong>
                <p class="summary-note">Approved jobs waiting for technician handoff.</p>
            </article>

            <article class="summary-card tone-green">
                <div class="summary-topline">
                    <span class="summary-tag">Live</span>
                    <i class="fas fa-play-circle"></i>
                </div>
                <span class="summary-label">In Progress</span>
                <strong class="summary-value">{{ summary.in_progress || 0 }}</strong>
                <p class="summary-note">Jobs actively moving through execution in the field.</p>
            </article>

            <article class="summary-card tone-amber">
                <div class="summary-topline">
                    <span class="summary-tag">Risk</span>
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <span class="summary-label">Delayed</span>
                <strong class="summary-value">{{ summary.delayed || 0 }}</strong>
                <p class="summary-note">Items likely needing intervention or stakeholder follow-up.</p>
            </article>

            <article class="summary-card tone-orange">
                <div class="summary-topline">
                    <span class="summary-tag">Paused</span>
                    <i class="fas fa-pause-circle"></i>
                </div>
                <span class="summary-label">Suspended</span>
                <strong class="summary-value">{{ summary.suspended || 0 }}</strong>
                <p class="summary-note">Jobs currently stopped and waiting on a restart decision.</p>
            </article>
        </section>

        <section class="panel-card jobs-panel">
            <div class="section-heading">
                <div>
                    <p class="section-kicker">Job Queue</p>
                    <h3>Current Delivery Board</h3>
                </div>
                <span class="section-badge">{{ jobs.total || jobs.data?.length || 0 }} items</span>
            </div>

            <div v-if="jobs.data?.length" class="desktop-table">
                <table class="jobs-table">
                    <thead>
                        <tr>
                            <th>Job</th>
                            <th>Client</th>
                            <th>Team</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Next Step</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="job in jobs.data" :key="job.id">
                            <td>
                                <div class="entity-block">
                                    <strong>{{ job.job_reference || job.request_id }}</strong>
                                    <span>{{ job.location || 'No location provided' }}</span>
                                    <small>{{ job.service_category?.name || 'Uncategorised' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="entity-block compact">
                                    <strong>{{ job.user?.name || 'N/A' }}</strong>
                                    <span>{{ job.user?.email || 'No email available' }}</span>
                                    <small>{{ formatDate(job.created_at) }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="entity-block compact">
                                    <strong>{{ job.technician?.user?.name || 'Unassigned' }}</strong>
                                    <span>{{ job.technician?.specialization || 'Awaiting technician' }}</span>
                                    <small v-if="job.leadTechnician?.user?.name">Lead: {{ job.leadTechnician.user.name }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="status-stack">
                                    <span :class="['status-badge', statusTone(job.status)]">{{ formatStatus(job.status) }}</span>
                                    <small>{{ urgencyLabel(job.urgency) }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="progress-stack">
                                    <div class="mini-progress-track">
                                        <div class="mini-progress-fill" :style="{ width: `${job.progress_percentage || 0}%` }"></div>
                                    </div>
                                    <span>{{ job.progress_percentage || 0 }}%</span>
                                </div>
                            </td>
                            <td>
                                <div class="action-cluster">
                                    <strong class="next-step-title">{{ nextStep(job).title }}</strong>
                                    <span class="action-note">{{ nextStep(job).note }}</span>

                                    <div class="action-row">
                                        <button
                                            v-if="nextStep(job).action === 'assign'"
                                            class="btn btn-sm btn-primary"
                                            @click="openAssignModal(job)"
                                        >
                                            <i class="fas fa-user-plus"></i>
                                            Assign
                                        </button>

                                        <button
                                            v-if="canSuspend(job)"
                                            class="btn btn-sm btn-warning"
                                            @click="openSuspendModal(job)"
                                        >
                                            <i class="fas fa-pause"></i>
                                            Suspend
                                        </button>

                                        <button
                                            v-if="job.status === 'suspended'"
                                            class="btn btn-sm btn-success"
                                            @click="resumeJob(job)"
                                        >
                                            <i class="fas fa-play"></i>
                                            Resume
                                        </button>

                                        <button
                                            v-if="canReassign(job)"
                                            class="btn btn-sm btn-indigo"
                                            @click="openReassignModal(job)"
                                        >
                                            <i class="fas fa-exchange-alt"></i>
                                            Reassign
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="jobs.data?.length" class="mobile-card-list">
                <article v-for="job in jobs.data" :key="`mobile-${job.id}`" class="job-card">
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
                        <div class="meta-chip">
                            <span>Progress</span>
                            <strong>{{ job.progress_percentage || 0 }}%</strong>
                        </div>
                        <div class="meta-chip">
                            <span>Urgency</span>
                            <strong>{{ urgencyLabel(job.urgency) }}</strong>
                        </div>
                    </div>

                    <div class="progress-stack">
                        <div class="mini-progress-track">
                            <div class="mini-progress-fill" :style="{ width: `${job.progress_percentage || 0}%` }"></div>
                        </div>
                        <span>{{ nextStep(job).title }}</span>
                    </div>

                    <p class="action-note mobile-note">{{ nextStep(job).note }}</p>

                    <div class="action-row">
                        <button
                            v-if="nextStep(job).action === 'assign'"
                            class="btn btn-sm btn-primary"
                            @click="openAssignModal(job)"
                        >
                            <i class="fas fa-user-plus"></i>
                            Assign
                        </button>

                        <button
                            v-if="canSuspend(job)"
                            class="btn btn-sm btn-warning"
                            @click="openSuspendModal(job)"
                        >
                            <i class="fas fa-pause"></i>
                            Suspend
                        </button>

                        <button
                            v-if="job.status === 'suspended'"
                            class="btn btn-sm btn-success"
                            @click="resumeJob(job)"
                        >
                            <i class="fas fa-play"></i>
                            Resume
                        </button>

                        <button
                            v-if="canReassign(job)"
                            class="btn btn-sm btn-indigo"
                            @click="openReassignModal(job)"
                        >
                            <i class="fas fa-exchange-alt"></i>
                            Reassign
                        </button>
                    </div>
                </article>
            </div>

            <div v-if="!jobs.data?.length" class="empty-state">
                <i class="fas fa-briefcase"></i>
                <h3>No jobs found</h3>
                <p>Try clearing the filters or widening the search to bring more jobs into view.</p>
            </div>

            <div class="pagination" v-if="jobs.last_page > 1">
                <Link
                    v-for="link in jobs.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    class="btn btn-sm"
                    :class="{ 'btn-primary': link.active, 'btn-secondary': !link.active }"
                    v-html="link.label"
                    :disabled="!link.url"
                />
            </div>
        </section>

        <div v-if="showSuspendModal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h3>Suspend Job</h3>
                        <p class="modal-subtitle">{{ selectedJob?.job_reference || selectedJob?.request_id }} • {{ selectedJob?.user?.name }}</p>
                    </div>
                    <button class="modal-close" @click="showSuspendModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Reason for Suspension</label>
                        <textarea
                            v-model="suspendReason"
                            rows="4"
                            required
                            minlength="10"
                            placeholder="Explain why this job is being suspended..."
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showSuspendModal = false">Cancel</button>
                    <button class="btn btn-warning" @click="submitSuspend" :disabled="submitting">
                        <i class="fas fa-pause"></i>
                        {{ submitting ? 'Suspending...' : 'Suspend Job' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showReassignModal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h3>Reassign Job</h3>
                        <p class="modal-subtitle">{{ selectedJob?.job_reference || selectedJob?.request_id }} • Current: {{ selectedJob?.technician?.user?.name || 'Unassigned' }}</p>
                    </div>
                    <button class="modal-close" @click="showReassignModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>New Technician</label>
                        <select v-model="reassignForm.technician_id" required>
                            <option value="">Choose...</option>
                            <option
                                v-for="tech in technicians"
                                :key="tech.id"
                                :value="tech.id"
                                :disabled="tech.id === selectedJob?.technician_id"
                            >
                                {{ tech.user?.name }} — {{ tech.specialization }}
                            </option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reason for Reassignment</label>
                        <textarea
                            v-model="reassignForm.reason"
                            rows="3"
                            required
                            minlength="10"
                            placeholder="Why is this job being reassigned?"
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showReassignModal = false">Cancel</button>
                    <button class="btn btn-indigo" @click="submitReassign" :disabled="submitting">
                        <i class="fas fa-exchange-alt"></i>
                        {{ submitting ? 'Processing...' : 'Reassign' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showAssignModal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h3>Assign Technician</h3>
                        <p class="modal-subtitle">{{ selectedJob?.job_reference || selectedJob?.request_id }} • {{ selectedJob?.user?.name }}</p>
                    </div>
                    <button class="modal-close" @click="showAssignModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Select Technician</label>
                        <select v-model="assignForm.technician_id" required>
                            <option value="">Choose...</option>
                            <option v-for="tech in technicians" :key="tech.id" :value="tech.id">
                                {{ tech.user?.name }} — {{ tech.specialization }} ({{ tech.availability }})
                            </option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Agreed Compensation (KES)</label>
                        <input type="number" v-model.number="assignForm.agreed_compensation" step="0.01" min="0" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Expected Start</label>
                            <input type="date" v-model="assignForm.expected_start" required>
                        </div>
                        <div class="form-group">
                            <label>Expected End</label>
                            <input type="date" v-model="assignForm.expected_end" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showAssignModal = false">Cancel</button>
                    <button class="btn btn-primary" @click="submitAssignment" :disabled="submitting">
                        <i class="fas fa-user-check"></i>
                        {{ submitting ? 'Assigning...' : 'Assign' }}
                    </button>
                </div>
            </div>
        </div>
    </PMLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PMLayout from '../../Layouts/PMLayout.vue'

const props = defineProps({
    jobs: { type: Object, default: () => ({ data: [] }) },
    technicians: { type: Array, default: () => [] },
    statusSummary: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

const summary = computed(() => props.statusSummary || {})
const filterStatus = ref(props.filters.status || '')
const search = ref(props.filters.search || '')
const submitting = ref(false)
const selectedJob = ref(null)
const showSuspendModal = ref(false)
const showReassignModal = ref(false)
const showAssignModal = ref(false)
const suspendReason = ref('')
const reassignForm = ref({ technician_id: '', reason: '' })
const assignForm = ref({ technician_id: '', agreed_compensation: 0, expected_start: '', expected_end: '' })

const canSuspend = (job) => ['in_progress', 'delayed'].includes(job.status)
const canReassign = (job) => ['assigned', 'suspended', 'in_progress'].includes(job.status)

const heroTitle = computed(() => {
    if (Number(summary.value.delayed || 0) > 0) return 'A few jobs need intervention to stay on track.'
    if (Number(summary.value.ready_for_assignment || 0) > 0) return 'There is work ready to hand over to technicians.'
    if (Number(summary.value.suspended || 0) > 0) return 'Suspended jobs are waiting on a decision to resume or redirect.'
    return 'Your delivery board is in a healthy place right now.'
})

const heroMessage = computed(() => {
    if (Number(summary.value.delayed || 0) > 0) return 'Start with delayed jobs to unblock field teams, reset expectations, and reduce downstream payment or reporting delays.'
    if (Number(summary.value.ready_for_assignment || 0) > 0) return 'Assigning ready jobs quickly keeps approved work from stalling between commercial approval and execution.'
    if (Number(summary.value.suspended || 0) > 0) return 'Review paused jobs to determine whether they should resume, be reassigned, or remain on hold with a clear reason.'
    return 'Use this page to keep live work balanced between assignments, execution monitoring, and exception handling.'
})

const activeStatusLabel = computed(() => props.statuses[filterStatus.value] || 'All job stages')

const applyFilter = () => {
    router.get('/pm/jobs', {
        status: filterStatus.value || undefined,
        search: search.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const clearFilters = () => {
    filterStatus.value = ''
    search.value = ''
    applyFilter()
}

const openSuspendModal = (job) => {
    selectedJob.value = job
    suspendReason.value = ''
    showSuspendModal.value = true
}

const openReassignModal = (job) => {
    selectedJob.value = job
    reassignForm.value = { technician_id: '', reason: '' }
    showReassignModal.value = true
}

const openAssignModal = (job) => {
    selectedJob.value = job
    assignForm.value = { technician_id: '', agreed_compensation: 0, expected_start: '', expected_end: '' }
    showAssignModal.value = true
}

const submitSuspend = () => {
    submitting.value = true
    router.post(`/pm/jobs/${selectedJob.value.id}/suspend`, { reason: suspendReason.value }, {
        onSuccess: () => {
            showSuspendModal.value = false
            submitting.value = false
        },
        onError: () => {
            submitting.value = false
        },
    })
}

const resumeJob = (job) => {
    router.post(`/pm/jobs/${job.id}/resume`)
}

const submitReassign = () => {
    submitting.value = true
    router.post(`/pm/jobs/${selectedJob.value.id}/reassign`, reassignForm.value, {
        onSuccess: () => {
            showReassignModal.value = false
            submitting.value = false
        },
        onError: () => {
            submitting.value = false
        },
    })
}

const submitAssignment = () => {
    submitting.value = true
    router.post(`/pm/jobs/${selectedJob.value.id}/assign`, assignForm.value, {
        onSuccess: () => {
            showAssignModal.value = false
            submitting.value = false
        },
        onError: () => {
            submitting.value = false
        },
    })
}

const formatStatus = (status) => props.statuses[status] || status?.replace(/_/g, ' ') || 'Unknown'

const statusTone = (status) => {
    const map = {
        ready_for_assignment: 'tone-blue',
        assigned: 'tone-blue',
        queued: 'tone-slate',
        in_progress: 'tone-green',
        delayed: 'tone-amber',
        suspended: 'tone-orange',
        completed_pending_confirmation: 'tone-slate',
        closed: 'tone-slate',
    }

    return map[status] || 'tone-slate'
}

const nextStep = (job) => {
    if (job.status === 'ready_for_assignment') {
        return {
            title: 'Assign technician',
            note: 'This job is approved and ready to move into execution.',
            action: 'assign',
        }
    }

    if (job.status === 'assigned') {
        return {
            title: 'Confirm kickoff',
            note: 'Make sure the assigned technician has dates, scope, and compensation alignment.',
            action: null,
        }
    }

    if (job.status === 'in_progress') {
        return {
            title: 'Monitor execution',
            note: 'Track progress updates closely and suspend only if field blockers appear.',
            action: null,
        }
    }

    if (job.status === 'delayed') {
        return {
            title: 'Resolve blocker',
            note: 'Investigate the delay, update the plan, or suspend if work cannot continue safely.',
            action: null,
        }
    }

    if (job.status === 'suspended') {
        return {
            title: 'Resume or reassign',
            note: 'Decide whether this job can restart or should move to a different technician.',
            action: null,
        }
    }

    if (job.status === 'queued') {
        return {
            title: 'Prepare start',
            note: 'Check readiness and confirm when the technician can begin on site.',
            action: null,
        }
    }

    return {
        title: 'Review job',
        note: 'Check delivery status and decide the next operational step.',
        action: null,
    }
}

const urgencyLabel = (urgency) => urgency ? urgency.replace(/\b\w/g, (char) => char.toUpperCase()) : 'Standard'

const formatDate = (value) => {
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
.page-header-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    max-width: 58ch;
}

.jobs-hero,
.summary-grid {
    display: grid;
    gap: 1rem;
    margin-bottom: 1.35rem;
}

.jobs-hero {
    grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
}

.hero-card,
.panel-card,
.summary-card {
    border-radius: 28px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
}

.hero-card {
    padding: 1.5rem 1.65rem;
}

.hero-card-primary {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 35%),
        linear-gradient(135deg, #ffffff, #eff6ff);
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

.hero-card h2,
.filter-card h3,
.section-heading h3 {
    margin: 0.55rem 0 0;
    color: #0f172a;
}

.hero-card p,
.filter-card p,
.summary-note,
.action-note,
.modal-subtitle,
.empty-state p {
    color: #64748b;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.3rem;
}

.hero-pill,
.section-badge,
.status-badge {
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

.filter-card {
    background: #ffffff;
    display: grid;
    gap: 1rem;
}

.filter-grid {
    display: grid;
    gap: 0.85rem;
}

.filter-field {
    display: grid;
    gap: 0.38rem;
    color: #334155;
    font-size: 0.84rem;
    font-weight: 700;
}

.filter-input {
    width: 100%;
    border: 1px solid rgba(148, 163, 184, 0.32);
    border-radius: 16px;
    background: #f8fafc;
    padding: 0.82rem 0.95rem;
}

.filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.filter-button {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 999px;
    padding-inline: 1rem;
}

.filter-button-secondary {
    background: #e2e8f0;
    color: #0f172a;
}

.summary-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
}

.summary-card {
    padding: 1.25rem;
    background: #ffffff;
}

.summary-topline,
.section-heading,
.job-card-head,
.modal-header {
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

.tone-slate {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
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
    background: linear-gradient(180deg, #fff7ed, #fed7aa);
}

.jobs-panel {
    padding: 1.4rem;
    background: #ffffff;
}

.desktop-table {
    margin-top: 1rem;
    overflow-x: auto;
}

.jobs-table {
    width: 100%;
    min-width: 1080px;
    border-collapse: collapse;
}

.jobs-table th {
    padding: 0.9rem 1rem;
    text-align: left;
    color: #64748b;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 1px solid rgba(226, 232, 240, 0.9);
}

.jobs-table td {
    padding: 1rem;
    vertical-align: top;
    border-bottom: 1px solid rgba(226, 232, 240, 0.84);
}

.entity-block,
.status-stack,
.progress-stack,
.action-cluster,
.meta-chip {
    display: grid;
    gap: 0.24rem;
}

.entity-block strong,
.job-card strong,
.summary-card strong,
.meta-chip strong {
    color: #0f172a;
}

.entity-block span,
.entity-block small,
.status-stack small,
.meta-chip span {
    color: #64748b;
}

.entity-block.compact strong {
    font-size: 0.98rem;
}

.status-badge {
    width: fit-content;
    padding: 0.42rem 0.72rem;
    font-size: 0.74rem;
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

.next-step-title {
    color: #0f172a;
    font-size: 0.92rem;
    line-height: 1.35;
}

.action-note {
    font-size: 0.78rem;
    line-height: 1.45;
    max-width: 24ch;
}

.action-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    margin-top: 0.15rem;
}

.btn-warning {
    background: #f59e0b;
    color: white;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-indigo {
    background: #6366f1;
    color: white;
}

.mobile-card-list {
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
    padding: 0.8rem;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.88);
}

.mobile-note {
    margin: 0.9rem 0;
}

.empty-state {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    text-align: center;
    padding: 3rem 1rem 1rem;
}

.empty-state i {
    font-size: 2.1rem;
    color: #0284c7;
}

.empty-state h3 {
    margin: 0;
    color: #0f172a;
}

.pagination {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.55rem;
    margin-top: 1.5rem;
}

.modal-subtitle {
    margin: 0.3rem 0 0;
}

@media (max-width: 1280px) {
    .jobs-hero,
    .summary-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 900px) {
    .jobs-hero,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .desktop-table {
        display: none;
    }

    .mobile-card-list {
        display: grid;
    }
}

@media (max-width: 640px) {
    .job-meta-grid,
    .filter-grid {
        grid-template-columns: 1fr;
    }

    .hero-card,
    .summary-card,
    .jobs-panel {
        padding: 1.1rem;
    }
}
</style>
