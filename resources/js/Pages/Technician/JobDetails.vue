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

            <section class="panel-card" v-if="job.sub_tasks?.length">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Sub-Tasks</span>
                        <h3>Assigned work items</h3>
                    </div>
                </div>

                <div class="subtask-list">
                    <article v-for="task in job.sub_tasks" :key="task.id" class="subtask-card">
                        <div class="subtask-top">
                            <div>
                                <strong>{{ task.title || task.name }}</strong>
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

                        <input
                            type="range"
                            min="0"
                            max="100"
                            step="10"
                            :value="task.progress_percentage || 0"
                            :disabled="!canUpdateTask(task)"
                            @change="(event) => updateTaskProgress(task, event.target.value)"
                        >
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

                <form @submit.prevent="submitProgressReport" class="progress-form">
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
                            <option value="">Whole job update</option>
                            <option
                                v-for="task in reportableSubTasks"
                                :key="task.id"
                                :value="task.id"
                            >
                                {{ task.title || task.name }}
                            </option>
                        </select>
                    </label>

                    <label class="form-field">
                        <span>Notes</span>
                        <textarea v-model="progressForm.notes" rows="4" class="input textarea" placeholder="Describe what was completed, what remains, and anything the PM/admin should know."></textarea>
                    </label>

                    <label class="form-field">
                        <span>Progress photos</span>
                        <input ref="photoInput" type="file" accept=".jpg,.jpeg,.png,.webp" class="input" multiple>
                        <small class="form-hint">Upload up to 6 photos to show actual site progress.</small>
                    </label>

                    <button type="submit" class="btn btn-primary" :disabled="submittingReport">
                        <i class="fas fa-camera"></i>
                        {{ submittingReport ? 'Submitting...' : 'Submit Progress Report' }}
                    </button>
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
                                {{ report.is_validated ? `Approved ${report.validated_percent ?? report.percent_complete}%` : `Pending ${report.percent_complete}%` }}
                            </span>
                        </div>

                        <p v-if="report.notes" class="report-notes">{{ report.notes }}</p>
                        <p v-if="report.validation_notes" class="report-validation-note">
                            PM/Admin note: {{ report.validation_notes }}
                        </p>

                        <div v-if="report.photos?.length" class="photo-grid">
                            <a
                                v-for="photo in report.photos"
                                :key="photo.id"
                                :href="`/storage/${photo.file_path}`"
                                class="photo-card"
                                target="_blank"
                            >
                                <img :src="`/storage/${photo.file_path}`" :alt="photo.caption || 'Progress photo'">
                                <span v-if="photo.removed_by_pm" class="photo-flag">Removed from approval</span>
                            </a>
                        </div>
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
                    <button class="btn btn-primary complete-btn" @click="updateStatus('completed')">
                        Mark Complete
                    </button>
                </div>
            </section>
        </main>

        <TechnicianBottomNav current-page="jobs" />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue'

const props = defineProps({
    technician: { type: Object, required: true },
    job: { type: Object, required: true },
    compensationSummary: { type: Object, default: null },
})

const photoInput = ref(null)
const submittingReport = ref(false)
const progressForm = ref({
    percent_complete: Number(props.job.progress_percentage || 0),
    report_date: new Date().toISOString().slice(0, 10),
    notes: '',
    service_sub_task_id: '',
})

const progressReports = computed(() => props.job.progress_reports || [])
const recentPayouts = computed(() => (props.compensationSummary?.history || []).slice(0, 3))

const reportableSubTasks = computed(() => {
    return (props.job.sub_tasks || []).filter((task) => {
        return props.technician.id === props.job.lead_technician_id || props.technician.id === task.technician_id
    })
})

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

function canUpdateTask(task) {
    return props.technician.id === props.job.lead_technician_id || props.technician.id === task.technician_id
}

function updateTaskProgress(task, value) {
    router.post(`/technician/sub-tasks/${task.id}/progress`, {
        progress_percentage: parseInt(value, 10),
        status: parseInt(value, 10) === 100 ? 'completed' : 'in_progress',
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

function submitProgressReport() {
    submittingReport.value = true
    const formData = new FormData()
    formData.append('percent_complete', progressForm.value.percent_complete)
    formData.append('report_date', progressForm.value.report_date)
    formData.append('notes', progressForm.value.notes || '')

    if (progressForm.value.service_sub_task_id) {
        formData.append('service_sub_task_id', progressForm.value.service_sub_task_id)
    }

    Array.from(photoInput.value?.files || []).forEach((file, index) => {
        formData.append(`photos[${index}]`, file)
    })

    router.post(`/technician/jobs/${props.job.id}/progress-report`, formData, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            submittingReport.value = false
            progressForm.value.notes = ''
            progressForm.value.service_sub_task_id = ''
            if (photoInput.value) {
                photoInput.value.value = ''
            }
        },
        onError: () => {
            submittingReport.value = false
        },
    })
}

defineOptions({ layout: null })
</script>

<style scoped>
@import url('../../../css/technician-pwa.css');

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
