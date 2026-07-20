<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="jobs" />

        <main class="main-content job-details-page">
            <section class="job-details-hero">
                <div class="hero-copy">
                    <div class="hero-topline">
                        <span class="hero-kicker">Job Details</span>
                        <span :class="['hero-status-pill', getStatusClass(job.status)]">
                            {{ formatStatus(job.status) }}
                        </span>
                    </div>

                    <h1>{{ job.request_id }}</h1>
                    <p>{{ job.description || 'No job description was provided for this service request.' }}</p>

                    <div class="hero-meta-row">
                        <span class="hero-meta-pill">
                            <i class="fas fa-tools"></i>
                            {{ serviceCategoryName }}
                        </span>
                        <span class="hero-meta-pill">
                            <i class="fas fa-map-marker-alt"></i>
                            {{ job.location || 'Location not specified' }}
                        </span>
                        <span class="hero-meta-pill">
                            <i class="fas fa-exclamation-triangle"></i>
                            {{ formatUrgency(job.urgency) }}
                        </span>
                    </div>
                </div>

                <div class="hero-action-card">
                    <div class="hero-action-copy">
                        <span class="section-kicker">Operations View</span>
                        <h3>{{ workflowLabel }}</h3>
                        <p>{{ assignmentSummary }}</p>
                    </div>

                    <div class="hero-action-grid">
                        <div class="hero-action-tile">
                            <span>Progress</span>
                            <strong>{{ normalizedProgress }}%</strong>
                        </div>
                        <div class="hero-action-tile">
                            <span>Available techs</span>
                            <strong>{{ availableTechnicianCount }}</strong>
                        </div>
                    </div>

                    <div class="hero-actions">
                        <Link href="/admin/jobs" class="btn btn-secondary hero-action-btn">
                            <i class="fas fa-arrow-left"></i>
                            Back to Jobs
                        </Link>
                        <Link :href="`/admin/payments?service_request_id=${job.id}`" class="btn btn-info hero-action-btn">
                            <i class="fas fa-wallet"></i>
                            Payments
                        </Link>
                    </div>
                </div>
            </section>

            <section class="job-metrics-grid">
                <article class="metric-card tone-blue">
                    <div class="metric-topline">
                        <span class="metric-tag">Progress</span>
                        <span class="metric-icon"><i class="fas fa-chart-line"></i></span>
                    </div>
                    <h3>{{ normalizedProgress }}%</h3>
                    <p>{{ progressLabel }}</p>
                </article>

                <article class="metric-card tone-green">
                    <div class="metric-topline">
                        <span class="metric-tag">Assignment</span>
                        <span class="metric-icon"><i class="fas fa-hard-hat"></i></span>
                    </div>
                    <h3>{{ assignmentHeadline }}</h3>
                    <p>{{ assignmentSupportText }}</p>
                </article>

                <article class="metric-card tone-amber">
                    <div class="metric-topline">
                        <span class="metric-tag">Sub-tasks</span>
                        <span class="metric-icon"><i class="fas fa-list-check"></i></span>
                    </div>
                    <h3>{{ subTaskCount }}</h3>
                    <p>{{ completedSubTasks }} completed, {{ activeSubTasks }} active.</p>
                </article>

                <article class="metric-card tone-slate">
                    <div class="metric-topline">
                        <span class="metric-tag">Budget</span>
                        <span class="metric-icon"><i class="fas fa-calculator"></i></span>
                    </div>
                    <h3>{{ budgetSummary ? `${budgetUsagePercent.toFixed(0)}%` : 'Not set' }}</h3>
                    <p>{{ budgetSummary ? budgetHeadline : 'Create a budget to track labor, materials, and other costs.' }}</p>
                </article>
            </section>

            <section class="job-layout-grid">
                <div class="job-primary-column">
                    <article class="job-shell-card">
                        <div class="job-card-header">
                            <div>
                                <span class="section-kicker">Request Overview</span>
                                <h3>Service request information</h3>
                                <p>Core request details, timing, and client context in one place.</p>
                            </div>
                        </div>

                        <div class="overview-grid">
                            <div class="overview-item">
                                <span>Client</span>
                                <strong>{{ job.user.name }}</strong>
                                <p>{{ job.user.email }}</p>
                            </div>
                            <div class="overview-item">
                                <span>Service category</span>
                                <strong>{{ serviceCategoryName }}</strong>
                                <p>{{ workflowLabel }}</p>
                            </div>
                            <div class="overview-item">
                                <span>Created</span>
                                <strong>{{ formatDate(job.created_at) }}</strong>
                                <p>Request entered the system.</p>
                            </div>
                            <div class="overview-item" v-if="job.scheduled_date">
                                <span>Scheduled</span>
                                <strong>{{ formatDate(job.scheduled_date) }}</strong>
                                <p>Planned execution window.</p>
                            </div>
                            <div class="overview-item">
                                <span>Urgency</span>
                                <strong>{{ formatUrgency(job.urgency) }}</strong>
                                <p>Use this to prioritize field response.</p>
                            </div>
                            <div class="overview-item">
                                <span>Location</span>
                                <strong>{{ job.location || 'Not specified' }}</strong>
                                <p>Service delivery site for this request.</p>
                            </div>
                        </div>

                        <div class="detail-note-section" v-if="job.description">
                            <h4>Description</h4>
                            <p class="description-text">{{ job.description }}</p>
                        </div>

                        <div class="detail-note-section" v-if="job.completion_notes">
                            <h4>Completion notes</h4>
                            <p class="description-text">{{ job.completion_notes }}</p>
                        </div>
                    </article>

                    <article class="job-shell-card">
                        <div class="job-card-header">
                            <div>
                                <span class="section-kicker">Assignment</span>
                                <h3>Technician coverage</h3>
                                <p>Review who owns the work and adjust the assignment plan when needed.</p>
                            </div>
                        </div>

                        <div v-if="primaryTechnician" class="assignment-profile-card">
                            <div class="assignment-profile-top">
                                <div class="assignment-avatar">{{ getInitials(primaryTechnician.user.name) }}</div>
                                <div class="assignment-copy">
                                    <div class="assignment-name-row">
                                        <h4>{{ primaryTechnician.user.name }}</h4>
                                        <span v-if="job.has_sub_tasks" class="lead-badge">Lead</span>
                                    </div>
                                    <p>{{ primaryTechnician.specialization }} · {{ primaryTechnician.location }}</p>
                                    <div class="assignment-chip-row">
                                        <span :class="['status-badge', primaryTechnicianAvailabilityClass]">
                                            {{ formatAvailability(primaryTechnician.availability) }}
                                        </span>
                                        <span class="soft-chip">{{ Number(primaryTechnician.rating || 0).toFixed(1) }}/5 rating</span>
                                        <span class="soft-chip">{{ primaryTechnician.total_jobs || 0 }} jobs</span>
                                    </div>
                                </div>
                            </div>

                            <div class="assignment-profile-grid">
                                <div class="profile-info-card">
                                    <span>Email</span>
                                    <strong>{{ primaryTechnician.user.email }}</strong>
                                </div>
                                <div class="profile-info-card">
                                    <span>Location</span>
                                    <strong>{{ primaryTechnician.location || 'Not set' }}</strong>
                                </div>
                                <div class="profile-info-card">
                                    <span>Specialization</span>
                                    <strong>{{ primaryTechnician.specialization || 'General technician' }}</strong>
                                </div>
                                <div class="profile-info-card">
                                    <span>Skills</span>
                                    <strong>{{ primaryTechnician.skills?.length || 0 }} listed</strong>
                                </div>
                            </div>

                            <!-- Agreed fee display + inline edit (#22) -->
                            <div class="assignment-fee-panel">
                                <div class="assignment-fee-row">
                                    <div class="assignment-fee-info">
                                        <span class="assignment-fee-label">Agreed compensation</span>
                                        <strong class="assignment-fee-value">
                                            KSH {{ formatCurrency(job.has_sub_tasks ? (currentLeadAssignment?.agreed_compensation || 0) : (currentSingleAssignment?.agreed_compensation || 0)) }}
                                        </strong>
                                    </div>
                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-xs"
                                        @click="showFeeEditPanel ? showFeeEditPanel = false : openFeeEdit()"
                                    >
                                        <i class="fas fa-pen"></i> Edit fee
                                    </button>
                                </div>

                                <div v-if="showFeeEditPanel" class="assignment-fee-edit-form">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>New agreed amount (KSH)</label>
                                            <input
                                                type="number"
                                                v-model.number="feeEditForm.agreed_compensation"
                                                min="0"
                                                step="0.01"
                                                class="form-control"
                                            />
                                        </div>
                                        <div class="form-group">
                                            <label>Reason / notes</label>
                                            <input
                                                type="text"
                                                v-model="feeEditForm.compensation_notes"
                                                class="form-control"
                                                placeholder="Optional reason for the change…"
                                            />
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                                        <button type="button" class="btn btn-primary btn-sm" @click="saveAgreedFee">
                                            <i class="fas fa-save"></i> Save
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" @click="showFeeEditPanel = false">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="assignment-action-row" v-if="canReassignTechnician">
                                <button
                                    v-if="job.has_sub_tasks"
                                    @click="openLeadAssignModal"
                                    class="btn btn-warning btn-sm"
                                >
                                    <i class="fas fa-user-edit"></i>
                                    Change Lead Technician
                                </button>
                                <button
                                    v-else
                                    @click="openSingleAssignModal"
                                    class="btn btn-warning btn-sm"
                                >
                                    <i class="fas fa-user-edit"></i>
                                    Reassign Technician
                                </button>
                            </div>
                        </div>

                        <div v-else-if="job.has_sub_tasks && canAssignTechnician" class="assignment-empty-card">
                            <div class="assignment-empty-copy">
                                <h4>No lead technician assigned yet</h4>
                                <p>Choose a lead technician to act as the main focal point for this service request and coordinate sub-task execution.</p>
                            </div>
                            <button @click="openLeadAssignModal" class="btn btn-primary btn-sm">
                                <i class="fas fa-user-plus"></i>
                                Assign Lead Technician
                            </button>
                        </div>

                        <div v-else class="assignment-empty-card">
                            <div class="assignment-empty-copy">
                                <h4>No technician assigned yet</h4>
                                <p>This job is still waiting for ownership. You can assign one technician directly or break the work into sub-tasks.</p>
                            </div>
                            <button
                                v-if="canAssignTechnician"
                                @click="openSingleAssignModal"
                                class="btn btn-primary btn-sm"
                            >
                                <i class="fas fa-user-plus"></i>
                                Assign Technician
                            </button>
                        </div>
                    </article>

                    <article class="job-shell-card" v-if="job.has_sub_tasks || canAddSubTasks">
                        <div class="job-card-header">
                            <div>
                                <span class="section-kicker">Execution</span>
                                <h3>Sub-task management</h3>
                                <p>Break work into trackable tasks and keep assignments easy to review.</p>
                            </div>
                            <div class="header-actions-row">
                                <span v-if="job.sub_tasks?.length" class="sub-task-count">
                                    {{ completedSubTasks }}/{{ job.sub_tasks.length }} completed
                                </span>
                                <button
                                    v-if="canAddSubTasks"
                                    @click="showAddSubTaskForm = !showAddSubTaskForm"
                                    class="btn btn-primary btn-sm"
                                >
                                    <i class="fas fa-plus"></i>
                                    Add Sub-Task
                                </button>
                            </div>
                        </div>

                        <div v-if="job.sub_tasks?.length" class="subtask-summary-grid">
                            <div class="subtask-summary-card">
                                <span>Total</span>
                                <strong>{{ subTaskCount }}</strong>
                            </div>
                            <div class="subtask-summary-card">
                                <span>Completed</span>
                                <strong>{{ completedSubTasks }}</strong>
                            </div>
                            <div class="subtask-summary-card">
                                <span>Active</span>
                                <strong>{{ activeSubTasks }}</strong>
                            </div>
                        </div>

                        <div v-if="showAddSubTaskForm" class="add-subtask-form">
                            <div class="form-group">
                                <label>Title</label>
                                <input
                                    v-model="newSubTask.title"
                                    type="text"
                                    class="form-control"
                                    placeholder="Enter sub-task title..."
                                >
                            </div>
                            <div class="form-group">
                                <label>Description (optional)</label>
                                <textarea
                                    v-model="newSubTask.description"
                                    class="form-control"
                                    rows="2"
                                    placeholder="Enter sub-task description..."
                                ></textarea>
                            </div>
                            <div class="form-actions">
                                <button @click="addSubTask" class="btn btn-primary btn-sm" :disabled="!newSubTask.title">
                                    Add Sub-Task
                                </button>
                                <button @click="cancelAddSubTask" class="btn btn-secondary btn-sm">
                                    Cancel
                                </button>
                            </div>
                        </div>

                        <div v-if="job.sub_tasks?.length" class="subtask-list">
                            <div v-for="subTask in job.sub_tasks" :key="subTask.id" class="subtask-card">
                                <div class="subtask-header">
                                    <div class="subtask-title-row">
                                        <span class="subtask-order">#{{ subTask.order }}</span>
                                        <template v-if="editingSubTask === subTask.id">
                                            <input
                                                v-model="editSubTaskForm.title"
                                                type="text"
                                                class="form-control form-control-sm"
                                            >
                                        </template>
                                        <template v-else>
                                            <h4 class="subtask-title">{{ subTask.title }}</h4>
                                        </template>
                                        <span :class="['status', getSubTaskStatusClass(subTask.status)]">
                                            {{ formatSubTaskStatus(subTask.status) }}
                                        </span>
                                    </div>
                                    <div class="subtask-actions">
                                        <template v-if="editingSubTask === subTask.id">
                                            <button @click="saveSubTask(subTask)" class="btn btn-primary btn-xs">Save</button>
                                            <button @click="editingSubTask = null" class="btn btn-secondary btn-xs">Cancel</button>
                                        </template>
                                        <template v-else>
                                            <button
                                                v-if="subTask.status !== 'completed'"
                                                @click="startEditSubTask(subTask)"
                                                class="btn btn-secondary btn-xs"
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button
                                                v-if="subTask.status === 'pending'"
                                                @click="deleteSubTask(subTask)"
                                                class="btn btn-danger btn-xs"
                                            >
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </template>
                                    </div>
                                </div>

                                <p v-if="editingSubTask === subTask.id" class="subtask-description">
                                    <textarea
                                        v-model="editSubTaskForm.description"
                                        class="form-control form-control-sm"
                                        rows="2"
                                    ></textarea>
                                </p>
                                <p v-else-if="subTask.description" class="subtask-description">{{ subTask.description }}</p>

                                <div class="subtask-technician">
                                    <div v-if="subTask.technician" class="assigned-tech">
                                        <i class="fas fa-user"></i>
                                        <span>{{ subTask.technician.user.name }}</span>
                                        <span class="tech-spec">{{ subTask.technician.specialization }}</span>
                                        <span
                                            v-if="job.lead_technician_id === subTask.technician.id"
                                            class="lead-badge"
                                        >Lead</span>
                                    </div>
                                    <div v-else class="unassigned">
                                        <span class="text-muted"><i class="fas fa-user-slash"></i> Unassigned</span>
                                    </div>
                                    <button
                                        v-if="subTask.status !== 'completed' && (!job.rfq_status || job.rfq_status === 'approved')"
                                        @click="showAssignModalFor(subTask)"
                                        class="btn btn-primary btn-xs"
                                    >
                                        <i class="fas fa-user-plus"></i>
                                        {{ subTask.technician ? 'Reassign' : 'Assign' }}
                                    </button>
                                </div>

                                <div class="subtask-progress">
                                    <div class="progress-bar">
                                        <div class="progress" :style="`width: ${subTask.progress_percentage}%;`"></div>
                                    </div>
                                    <span class="progress-label">{{ subTask.progress_percentage }}%</span>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="!showAddSubTaskForm" class="empty-subtasks">
                            <p>No sub-tasks added yet. Click "Add Sub-Task" to break this service request into smaller work items.</p>
                        </div>
                    </article>

                    <article class="job-shell-card">
                        <div class="job-card-header">
                            <div>
                                <span class="section-kicker">Progress Reports</span>
                                <h3>Field updates and photo evidence</h3>
                                <p>Review technician submissions, inspect site photos, and validate the progress that should count operationally.</p>
                            </div>
                            <span class="sub-task-count">{{ progressReports.length }} report{{ progressReports.length === 1 ? '' : 's' }}</span>
                        </div>

                        <!-- Backfill banner — shows when job is closed (status=completed
                             OR progress_percentage = 100) but the latest validated
                             progress report sits below 100%. This is the
                             "Mark Complete tapped without 100% report" scenario. -->
                        <div
                            v-if="needsFinalReportBackfill"
                            class="backfill-banner"
                        >
                            <div>
                                <strong>⚠ Job is marked complete but the validated progress is only {{ latestValidatedReportPct }}%</strong>
                                <p style="margin:.35rem 0 0;font-size:.88rem;">
                                    The technician marked the job complete without submitting a final 100% progress report,
                                    so the payment system can't bill the remaining balance.
                                    Click below to backfill a 100% report on their behalf — this will sync the data
                                    and let you process the final payment.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="btn btn-warning"
                                @click="backfillFinalProgressReport"
                                :disabled="backfilling"
                            >
                                <i class="fas fa-sync-alt"></i>
                                {{ backfilling ? 'Backfilling…' : 'Backfill 100% Report' }}
                            </button>
                        </div>

                        <!-- Payment duplicate-row banner. Surfaces when this
                             job's payments table has more than one completed
                             row for the same payment request — the data bug
                             reported on REQ-I73N1L. -->
                        <div
                            v-if="hasDuplicatePayments"
                            class="backfill-banner"
                            style="background:#fee2e2;border-color:#fca5a5;color:#7f1d1d;"
                        >
                            <div>
                                <strong>⚠ Duplicate payment records detected on this job</strong>
                                <p style="margin:.35rem 0 0;font-size:.88rem;">
                                    {{ duplicatePaymentCount }} extra payment row{{ duplicatePaymentCount === 1 ? '' : 's' }} found.
                                    Client portal balances will be wrong until cleaned up.
                                    Run dry-run first to see what will change, then apply the fix.
                                </p>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:.4rem;">
                                <button type="button" class="btn btn-secondary btn-sm" @click="dedupePayments(true)" :disabled="deduping">
                                    <i class="fas fa-eye"></i> Dry-run
                                </button>
                                <button type="button" class="btn btn-danger btn-sm" @click="dedupePayments(false)" :disabled="deduping">
                                    <i class="fas fa-broom"></i> {{ deduping ? 'Cleaning…' : 'Remove Duplicates' }}
                                </button>
                            </div>
                        </div>

                        <div v-if="progressReports.length" class="admin-report-list">
                            <article v-for="report in progressReports" :key="report.id" class="admin-report-card">
                                <div class="admin-report-top">
                                    <div>
                                        <strong>{{ report.service_sub_task ? `Sub-task: ${report.service_sub_task.title || report.service_sub_task.name}` : 'Whole job update' }}</strong>
                                        <p>
                                            Submitted by {{ report.submitter?.name || report.technician?.user?.name || 'Unknown' }}
                                            on {{ formatDateTime(report.created_at) }}
                                            <small v-if="report.report_date && !isSameDay(report.report_date, report.created_at)" style="color:var(--text-muted);">
                                                (for {{ formatDateOnly(report.report_date) }})
                                            </small>
                                        </p>
                                    </div>
                                    <span :class="['status-badge', report.is_validated ? 'approved' : 'review']">
                                        {{ report.is_validated ? `Approved ${report.validated_percent ?? report.percent_complete}%` : `Pending ${report.percent_complete}%` }}
                                    </span>
                                </div>

                                <div class="admin-report-metrics">
                                    <div class="subtask-summary-card">
                                        <span>Reported</span>
                                        <strong>{{ report.percent_complete }}%</strong>
                                    </div>
                                    <div class="subtask-summary-card">
                                        <span>Validated</span>
                                        <strong>{{ report.is_validated ? `${report.validated_percent ?? report.percent_complete}%` : 'Pending' }}</strong>
                                    </div>
                                    <div class="subtask-summary-card">
                                        <span>Photos</span>
                                        <strong>{{ report.photos?.length || 0 }}</strong>
                                    </div>
                                    <div class="subtask-summary-card" v-if="report.technician_id">
                                        <span>Labor payout</span>
                                        <strong>KSH {{ formatCurrency(getProgressPayableAmount(report)) }}</strong>
                                    </div>
                                </div>

                                <p v-if="report.notes" class="admin-report-notes">{{ report.notes }}</p>
                                <p v-if="report.validation_notes" class="admin-report-validation-note">
                                    Validation note: {{ report.validation_notes }}
                                </p>
                                <p v-if="report.client_visible_notes" class="admin-report-client-notes">
                                    <i class="fas fa-eye"></i>
                                    <strong>What the client sees:</strong>
                                    {{ report.client_visible_notes }}
                                </p>

                                <!-- Ops-only edit history for this report's notes.
                                     Populated whenever validate re-saves with a
                                     different client_visible_notes / validation_notes.
                                     Never rendered on the client portal. -->
                                <details v-if="report.note_versions && report.note_versions.length" class="notes-history-panel">
                                    <summary>
                                        <i class="fas fa-clock-rotate-left"></i>
                                        Notes edit history ({{ report.note_versions.length }})
                                    </summary>
                                    <ol class="notes-history-list">
                                        <li v-for="version in report.note_versions" :key="version.id" class="notes-history-item">
                                            <div class="notes-history-meta">
                                                <strong>{{ formatNoteFieldName(version.field_name) }}</strong>
                                                <span>edited by {{ version.editor?.name || 'Unknown' }} · {{ formatDate(version.created_at) }}</span>
                                            </div>
                                            <div class="notes-history-diff">
                                                <div class="notes-history-cell before">
                                                    <span>Before</span>
                                                    <p>{{ version.previous_text || '(empty)' }}</p>
                                                </div>
                                                <div class="notes-history-cell after">
                                                    <span>After</span>
                                                    <p>{{ version.new_text || '(empty)' }}</p>
                                                </div>
                                            </div>
                                        </li>
                                    </ol>
                                </details>

                                <div v-if="report.photos?.length" class="admin-photo-grid">
                                    <div
                                        v-for="photo in report.photos"
                                        :key="photo.id"
                                        :class="[
                                            'admin-photo-card',
                                            { removed: photo.removed_by_pm || progressValidationForms[report.id]?.remove_photo_ids.includes(photo.id) }
                                        ]"
                                    >
                                        <a :href="`/storage/${photo.file_path}`" target="_blank">
                                            <img :src="`/storage/${photo.file_path}`" :alt="photo.caption || 'Progress photo'">
                                        </a>
                                        <div class="admin-photo-overlay">
                                            <span v-if="photo.removed_by_pm" class="admin-photo-flag">Removed</span>
                                            <button
                                                v-else-if="!report.is_validated"
                                                type="button"
                                                class="btn btn-secondary btn-xs"
                                                @click="toggleReportPhotoRemoval(report.id, photo.id)"
                                            >
                                                {{ progressValidationForms[report.id]?.remove_photo_ids.includes(photo.id) ? 'Undo remove' : 'Remove from approval' }}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="!report.is_validated && progressValidationForms[report.id]" class="admin-validation-form">
                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>Validated %</label>
                                            <input
                                                v-model.number="progressValidationForms[report.id].validated_percent"
                                                type="number"
                                                min="0"
                                                max="100"
                                                class="form-control"
                                            >
                                        </div>
                                        <div class="form-group">
                                            <label>Internal validation note (admin/PM only)</label>
                                            <input
                                                v-model="progressValidationForms[report.id].validation_notes"
                                                type="text"
                                                class="form-control"
                                                placeholder="Hidden from client. For internal record only."
                                            >
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin-top:.5rem;">
                                        <label>Notes the client will see <small style="color:var(--text-muted);">(editable — pre-filled with technician's report)</small></label>
                                        <textarea
                                            v-model="progressValidationForms[report.id].client_visible_notes"
                                            rows="3"
                                            class="form-control"
                                            placeholder="Polished version of the technician's update that will appear on the client's portal and progress email."
                                        ></textarea>
                                        <small v-if="report.notes" style="display:block;color:var(--text-muted);margin-top:.25rem;">
                                            <strong>Technician's original:</strong> "{{ report.notes }}"
                                        </small>
                                    </div>
                                    <div class="form-group" style="margin-top:.5rem;">
                                        <label>Attach photos (optional, up to 6)</label>
                                        <input
                                            type="file"
                                            accept="image/*"
                                            multiple
                                            class="form-control"
                                            @change="e => progressValidationForms[report.id].admin_photo_files = Array.from(e.target.files)"
                                        >
                                        <small v-if="progressValidationForms[report.id].admin_photo_files?.length" style="color:var(--success-color)">
                                            {{ progressValidationForms[report.id].admin_photo_files.length }} photo(s) selected
                                        </small>
                                    </div>
                                    <button class="btn btn-primary btn-sm" @click="validateProgressReport(report.id)">
                                        <i class="fas fa-check-circle"></i>
                                        Approve Progress
                                    </button>
                                </div>

                                <div v-else-if="report.is_validated && report.technician_id" class="admin-payout-row">
                                    <div class="admin-payout-copy">
                                        <strong>{{ report.technician?.user?.name || 'Technician payout' }}</strong>
                                        <p>
                                            Agreed fee at {{ report.validated_percent ?? report.percent_complete }}% validated progress:
                                            KSH {{ formatCurrency(getProgressPayableAmount(report)) }} outstanding.
                                            Process payment through the Payments section.
                                        </p>
                                    </div>
                                    <button
                                        v-if="canPayProgressReport(report)"
                                        class="btn btn-success btn-sm"
                                        disabled
                                        title="Use the Payments section to process technician payouts"
                                        style="opacity:0.5;cursor:not-allowed;"
                                    >
                                        <i class="fas fa-money-bill-wave"></i>
                                        KSH {{ formatCurrency(getProgressPayableAmount(report)) }} due
                                    </button>
                                    <span v-else class="paid-progress-note">
                                        Already paid up to this approved progress level.
                                    </span>
                                </div>
                            </article>
                        </div>

                        <div v-else class="empty-state">
                            <p>No progress reports submitted for this job yet.</p>
                        </div>
                    </article>

                    <article class="job-shell-card">
                        <div class="job-card-header">
                            <div>
                                <span class="section-kicker">Finance</span>
                                <h3>Budget and payments</h3>
                                <p>Track budget consumption, actual spend, and payment context without leaving the job.</p>
                            </div>
                            <div class="header-actions-row">
                                <Link :href="`/admin/payments?service_request_id=${job.id}`" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-external-link-alt"></i>
                                    Full Payments
                                </Link>
                                <button @click="openBudgetModal" class="btn btn-primary btn-sm">
                                    <i :class="budgetSummary ? 'fas fa-edit' : 'fas fa-plus'"></i>
                                    {{ budgetSummary ? 'Edit Budget' : 'Set Budget' }}
                                </button>
                            </div>
                        </div>

                        <div v-if="budgetSummary" class="budget-overview-card">
                            <div class="budget-overview-top">
                                <div>
                                    <span>Total budget</span>
                                    <strong>KSH {{ formatCurrency(budgetSummary.total.budgeted) }}</strong>
                                </div>
                                <div>
                                    <span>Spent</span>
                                    <strong>KSH {{ formatCurrency(budgetSummary.total.actual) }}</strong>
                                </div>
                                <div>
                                    <span>Remaining</span>
                                    <strong :class="{ negative: budgetSummary.total.remaining < 0, positive: budgetSummary.total.remaining >= 0 }">
                                        KSH {{ formatCurrency(budgetSummary.total.remaining) }}
                                    </strong>
                                </div>
                            </div>

                            <div class="budget-progress-bar large">
                                <div
                                    class="budget-progress-fill"
                                    :style="{
                                        width: Math.min(budgetUsagePercent, 100) + '%',
                                        backgroundColor: getProgressColor(budgetUsagePercent)
                                    }"
                                ></div>
                            </div>
                            <span class="budget-pct">{{ budgetUsagePercent.toFixed(0) }}% of budget used</span>
                        </div>

                        <div v-if="budgetSummary" class="budget-grid">
                            <div v-for="cat in ['labor', 'materials', 'other']" :key="cat" class="budget-category-card">
                                <h4>{{ cat }}</h4>
                                <div class="budget-amounts">
                                    <div class="budget-row">
                                        <span>Budgeted</span>
                                        <strong>KSH {{ formatCurrency(budgetSummary[cat].budgeted) }}</strong>
                                    </div>
                                    <div class="budget-row">
                                        <span>Spent</span>
                                        <strong>KSH {{ formatCurrency(budgetSummary[cat].actual) }}</strong>
                                    </div>
                                    <div class="budget-row">
                                        <span>Remaining</span>
                                        <strong :class="{ negative: budgetSummary[cat].remaining < 0, positive: budgetSummary[cat].remaining >= 0 }">
                                            KSH {{ formatCurrency(budgetSummary[cat].remaining) }}
                                        </strong>
                                    </div>
                                </div>
                                <div class="budget-progress-bar">
                                    <div
                                        class="budget-progress-fill"
                                        :style="{
                                            width: Math.min(getPercentUsed(budgetSummary[cat]), 100) + '%',
                                            backgroundColor: getProgressColor(getPercentUsed(budgetSummary[cat]))
                                        }"
                                    ></div>
                                </div>
                                <span class="budget-pct">{{ getPercentUsed(budgetSummary[cat]).toFixed(0) }}% used</span>
                                <!-- #11 — Record Expense button per category so the admin
                                     can log a material / other expenditure right where they
                                     see the remaining budget. -->
                                <button
                                    v-if="cat !== 'labor'"
                                    type="button"
                                    class="btn btn-sm btn-secondary record-expense-btn"
                                    @click="openExpenseModal(cat)"
                                    style="margin-top: 0.5rem; width: 100%;"
                                >
                                    <i class="fas fa-receipt"></i> Record {{ cat }} expense
                                </button>
                            </div>
                        </div>

                        <div v-if="displayQuoteAmount || displayFinalAmount" class="pricing-strip">
                            <div v-if="displayQuoteAmount" class="pricing-chip">
                                <span>Quoted amount</span>
                                <strong>KSH {{ formatCurrency(displayQuoteAmount) }}</strong>
                            </div>
                            <div v-if="displayFinalAmount" class="pricing-chip success">
                                <span>Final amount</span>
                                <strong>KSH {{ formatCurrency(displayFinalAmount) }}</strong>
                            </div>
                        </div>

                        <div v-if="!budgetSummary" class="empty-budget">
                            <i class="fas fa-calculator"></i>
                            <p>No budget set for this service request yet.</p>
                        </div>
                    </article>

                    <article class="job-shell-card" v-if="job.budget">
                        <div class="job-card-header">
                            <div>
                                <span class="section-kicker">Milestones</span>
                                <h3>Payment milestones</h3>
                                <p>Schedule payouts against progress and keep due stages easy to spot.</p>
                            </div>
                            <button @click="openAddMilestoneModal" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i>
                                Add Milestone
                            </button>
                        </div>

                        <div v-if="job.milestones && job.milestones.length > 0" class="milestone-summary-grid">
                            <div class="milestone-summary-card">
                                <span>Total milestones</span>
                                <strong>{{ milestoneCount }}</strong>
                            </div>
                            <div class="milestone-summary-card">
                                <span>Due now</span>
                                <strong>{{ dueMilestonesCount }}</strong>
                            </div>
                            <div class="milestone-summary-card">
                                <span>Paid</span>
                                <strong>{{ paidMilestonesCount }}</strong>
                            </div>
                            <div class="milestone-summary-card">
                                <span>Labor released</span>
                                <strong>KSH {{ formatCurrency(milestoneLaborReleasedTotal) }}</strong>
                            </div>
                        </div>

                        <div v-if="job.milestones && job.milestones.length > 0" class="milestones-list">
                            <div v-for="milestone in job.milestones" :key="milestone.id" class="milestone-item">
                                <div class="milestone-progress">
                                    <div class="progress-circle" :class="{ reached: milestone.status !== 'pending' }">
                                        {{ milestone.progress_step }}%
                                    </div>
                                </div>
                                <div class="milestone-details">
                                    <div class="milestone-header">
                                        <h4>{{ milestone.progress_step }}% Completion</h4>
                                        <span :class="['status-badge', milestone.status]">
                                            {{ milestone.status === 'reached' ? 'Due' : milestone.status }}
                                        </span>
                                    </div>
                                    <div class="milestone-finance-grid">
                                        <div>
                                            <span class="milestone-label">Labor release</span>
                                            <p class="milestone-amount labor">KSH {{ formatCurrency(milestone.labor_release_amount || 0) }}</p>
                                        </div>
                                        <div>
                                            <span class="milestone-label">Allocated</span>
                                            <p class="milestone-amount">KSH {{ formatCurrency(getMilestoneAllocatedAmount(milestone)) }}</p>
                                        </div>
                                        <div>
                                            <span class="milestone-label">Remaining</span>
                                            <p class="milestone-amount" :class="getMilestoneRemainingAmount(milestone) > 0 ? 'remaining' : 'settled'">
                                                KSH {{ formatCurrency(getMilestoneRemainingAmount(milestone)) }}
                                            </p>
                                        </div>
                                    </div>
                                    <p v-if="milestone.notes" class="milestone-notes">{{ milestone.notes }}</p>
                                    <div v-if="milestone.allocations && milestone.allocations.length > 0" class="milestone-allocation-list">
                                        <div v-for="allocation in milestone.allocations" :key="allocation.id" class="milestone-allocation-row">
                                            <div>
                                                <strong>{{ allocation.technician?.user?.name || `Technician #${allocation.technician_id}` }}</strong>
                                                <span v-if="allocation.notes">{{ allocation.notes }}</span>
                                            </div>
                                            <strong>KSH {{ formatCurrency(allocation.allocated_amount) }}</strong>
                                        </div>
                                    </div>
                                    <p v-else class="milestone-allocation-empty">
                                        No technician allocations planned for this milestone yet.
                                    </p>
                                </div>
                                <div class="milestone-actions">
                                    <button
                                        v-if="milestone.status === 'reached'"
                                        @click="markMilestonePaid(milestone)"
                                        class="btn btn-success btn-xs"
                                        title="Mark as Paid"
                                    >
                                        <i class="fas fa-check-double"></i>
                                        Pay
                                    </button>
                                    <button @click="editMilestone(milestone)" class="btn btn-secondary btn-xs">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button @click="deleteMilestone(milestone)" class="btn btn-danger btn-xs">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div v-else class="empty-state">
                            <p>No payment milestones set. Add milestones to schedule payments against progress.</p>
                        </div>
                    </article>

                    <article class="job-shell-card" v-if="!job.has_sub_tasks && !job.technician && canAssignTechnician">
                        <div class="job-card-header">
                            <div>
                                <span class="section-kicker">Next Step</span>
                                <h3>Choose an assignment approach</h3>
                                <p>This request is ready for technician coverage.</p>
                            </div>
                        </div>
                        <div class="assign-section">
                            <p>This job has not been assigned to a technician yet. Assign a single technician directly or split the work into multiple sub-tasks.</p>
                            <div class="assign-options">
                                <button @click="openSingleAssignModal" class="btn btn-primary">
                                    <i class="fas fa-user-plus"></i>
                                    Assign Single Technician
                                </button>
                                <button @click="showAddSubTaskForm = true" class="btn btn-secondary">
                                    <i class="fas fa-list-check"></i>
                                    Add Sub-Tasks Instead
                                </button>
                            </div>
                        </div>
                    </article>
                </div>

                <aside class="job-sidebar-column">
                    <article class="job-shell-card sidebar-card">
                        <div class="job-card-header compact">
                            <div>
                                <span class="section-kicker">Client</span>
                                <h3>Client snapshot</h3>
                            </div>
                        </div>

                        <div class="sidebar-detail-list">
                            <div class="sidebar-detail-item">
                                <span>Name</span>
                                <strong>{{ job.user.name }}</strong>
                            </div>
                            <div class="sidebar-detail-item">
                                <span>Email</span>
                                <strong>{{ job.user.email }}</strong>
                            </div>
                            <div class="sidebar-detail-item">
                                <span>Request type</span>
                                <strong>{{ workflowLabel }}</strong>
                            </div>
                            <div class="sidebar-detail-item">
                                <span>Status</span>
                                <strong>{{ formatStatus(job.status) }}</strong>
                            </div>
                        </div>
                    </article>

                    <article class="job-shell-card sidebar-card">
                        <div class="job-card-header compact">
                            <div>
                                <span class="section-kicker">Schedule</span>
                                <h3>Timing and progress</h3>
                            </div>
                        </div>

                        <div class="sidebar-detail-list">
                            <div class="sidebar-detail-item">
                                <span>Created</span>
                                <strong>{{ formatDate(job.created_at) }}</strong>
                            </div>
                            <div class="sidebar-detail-item" v-if="job.scheduled_date">
                                <span>Scheduled</span>
                                <strong>{{ formatDate(job.scheduled_date) }}</strong>
                            </div>
                            <div class="sidebar-detail-item">
                                <span>Overall progress</span>
                                <strong>{{ normalizedProgress }}%</strong>
                            </div>
                            <div class="sidebar-progress-shell">
                                <div class="progress-bar">
                                    <div class="progress" :style="`width: ${normalizedProgress}%;`"></div>
                                </div>
                            </div>
                        </div>
                    </article>

                    <article class="job-shell-card sidebar-card" v-if="displayQuoteAmount || displayFinalAmount || job.rating">
                        <div class="job-card-header compact">
                            <div>
                                <span class="section-kicker">Commercial</span>
                                <h3>Pricing and feedback</h3>
                            </div>
                        </div>

                        <div class="sidebar-detail-list" v-if="displayQuoteAmount || displayFinalAmount">
                            <div class="sidebar-detail-item" v-if="displayQuoteAmount">
                                <span>Quoted amount</span>
                                <strong>KSH {{ formatCurrency(displayQuoteAmount) }}</strong>
                            </div>
                            <div class="sidebar-detail-item" v-if="displayFinalAmount">
                                <span>Final amount</span>
                                <strong>KSH {{ formatCurrency(displayFinalAmount) }}</strong>
                            </div>
                        </div>

                        <div v-if="job.rating" class="rating-display">
                            <div class="rating-stars">
                                <span v-for="i in 5" :key="i" :class="['star', { filled: i <= job.rating }]">
                                    <i class="fas fa-star"></i>
                                </span>
                                <span class="rating-text">{{ job.rating }}/5</span>
                            </div>
                            <p v-if="job.review" class="review-text">{{ job.review }}</p>
                        </div>
                    </article>
                </aside>
            </section>
        </main>

        <!-- Single Technician Assignment Modal -->
        <div v-if="showSingleAssignModal" class="modal-overlay">
            <div class="modal-content modal-lg" @click.stop>
                <div class="modal-header">
                    <h3>Assign Technician</h3>
                    <button @click="closeSingleAssignModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p><strong>Job:</strong> {{ job.request_id }} - {{ job.description }}</p>
                    <p><strong>Service Category:</strong> {{ job.service_category?.name }}</p>

                    <div class="assignment-budget-panel">
                        <div class="assignment-budget-grid">
                            <div class="assignment-budget-stat">
                                <span>Labor budget</span>
                                <strong>KSH {{ formatCurrency(laborBudgetTotal) }}</strong>
                            </div>
                            <div class="assignment-budget-stat">
                                <span>Allocated dues</span>
                                <strong>KSH {{ formatCurrency(totalLaborAllocated) }}</strong>
                            </div>
                            <div class="assignment-budget-stat">
                                <span>Available here</span>
                                <strong>KSH {{ formatCurrency(availableAssignmentBudget) }}</strong>
                            </div>
                        </div>
                        <p v-if="!hasLaborBudget" class="assignment-budget-warning">
                            Set the labor budget first before assigning technician dues.
                        </p>
                        <p v-else class="assignment-budget-hint">
                            Reserve this technician's dues from the labor budget. The agreed amount cannot exceed
                            KSH {{ formatCurrency(availableAssignmentBudget) }} for this assignment.
                        </p>
                    </div>

                    <div class="tech-picker-filters">
                        <input
                            v-model="techFilterSearch"
                            type="text"
                            placeholder="Search by name…"
                            class="tech-filter-input"
                        />
                        <select v-model="techFilterTrade" class="tech-filter-select">
                            <option value="">All trades</option>
                            <option v-for="trade in availableTrades" :key="trade" :value="trade">
                                {{ trade.charAt(0).toUpperCase() + trade.slice(1) }}
                            </option>
                        </select>
                        <select v-model="techFilterStatus" class="tech-filter-select">
                            <option value="">All statuses</option>
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                        <span class="tech-filter-count">{{ sortedTechnicians.length }} shown</span>
                    </div>

                    <div class="form-group">
                        <label>Available Technicians:</label>
                        <div class="technician-list">
                            <div
                                v-for="tech in sortedTechnicians"
                                :key="tech.id"
                                :class="['technician-item', { selected: selectedTechnician?.id === tech.id }]"
                                @click="selectedTechnician = tech"
                            >
                                <div class="technician-info">
                                    <h4>{{ tech.user.name }}</h4>
                                    <p>{{ tech.specialization }} | {{ tech.location }}</p>
                                    <p><strong>Rating:</strong> {{ tech.rating }}/5</p>
                                    <p><strong>Skills:</strong> {{ tech.skills?.join(', ') || 'N/A' }}</p>
                                </div>
                                <div class="technician-status">
                                    <span :class="['status', tech.availability]">
                                        {{ formatAvailability(tech.availability) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row assignment-finance-row">
                        <div class="form-group">
                            <label>Technician Dues (KSH)</label>
                            <input
                                type="number"
                                v-model.number="assignmentForm.agreed_compensation"
                                step="0.01"
                                min="0"
                                :max="availableAssignmentBudget"
                                required
                                class="form-control"
                            >
                            <small class="assignment-field-help">
                                Maximum available for this assignment: KSH {{ formatCurrency(availableAssignmentBudget) }}
                            </small>
                        </div>
                        <div class="form-group">
                            <label>Compensation Notes</label>
                            <textarea
                                v-model="assignmentForm.compensation_notes"
                                rows="3"
                                class="form-control"
                                placeholder="Optional notes about the agreed labor dues."
                            ></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Expected on-site contact time (minutes)</label>
                            <input
                                type="number"
                                v-model.number="assignmentForm.contact_time_minutes"
                                min="5"
                                max="480"
                                step="5"
                                class="form-control"
                                placeholder="e.g. 45"
                            >
                            <small class="assignment-field-help">
                                Shown to the client after assignment so they know how long to plan around the visit. 5–480 minutes.
                            </small>
                        </div>
                    </div>

                    <p v-if="assignmentOverBudget" class="assignment-inline-error">
                        This amount is above the remaining labor budget for the current assignment mix.
                    </p>
                </div>
                <div class="modal-footer">
                    <button @click="closeSingleAssignModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="assignSingleTechnician"
                        :disabled="!canSubmitAssignment"
                        class="btn btn-primary"
                    >
                        Assign Technician
                    </button>
                </div>
            </div>
        </div>

        <!-- Budget Modal -->
        <div v-if="showBudgetModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ job.budget ? 'Edit' : 'Set' }} Budget</h3>
                    <button @click="showBudgetModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveBudget">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Labor Budget (KSH)*</label>
                                <input type="number" v-model="budgetForm.labor_budget" step="0.01" min="0" required class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Materials Budget (KSH)*</label>
                                <input type="number" v-model="budgetForm.materials_budget" step="0.01" min="0" required class="form-control">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Other Budget (KSH)*</label>
                                <input type="number" v-model="budgetForm.other_budget" step="0.01" min="0" required class="form-control">
                            </div>
                            <div class="form-group">
                                <label>Total</label>
                                <input type="text" :value="'KSH ' + formatCurrency(budgetTotal)" disabled class="form-control" style="background: #f0f0f0;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea v-model="budgetForm.notes" rows="2" placeholder="Budget notes..." class="form-control"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="showBudgetModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveBudget" class="btn btn-primary">Save Budget</button>
                </div>
            </div>
        </div>

        <!-- Record Expense Modal (#11) -->
        <div v-if="showExpenseModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Record {{ expenseForm.category }} expense</h3>
                    <button @click="showExpenseModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveExpenseFromBudget">
                        <div class="form-group">
                            <label>Description *</label>
                            <input v-model="expenseForm.description" type="text" class="form-control" required placeholder="e.g. 5 bags of cement" />
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="flex:1">
                                <label>Amount (KSH) *</label>
                                <input v-model.number="expenseForm.amount" type="number" step="0.01" min="0.01" class="form-control" required />
                            </div>
                            <div class="form-group" style="flex:1">
                                <label>Expense date</label>
                                <input v-model="expenseForm.expense_date" type="date" class="form-control" />
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group" style="flex:1">
                                <label>Vendor</label>
                                <input v-model="expenseForm.vendor" type="text" class="form-control" placeholder="Supplier name" />
                            </div>
                            <div class="form-group" style="flex:1">
                                <label>Receipt reference</label>
                                <input v-model="expenseForm.receipt_reference" type="text" class="form-control" placeholder="e.g. RCT-123" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea v-model="expenseForm.notes" rows="2" class="form-control" placeholder="Optional — e.g. allocation, delivery instructions"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="showExpenseModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveExpenseFromBudget" class="btn btn-primary" :disabled="!canSaveExpense">
                        Save expense
                    </button>
                </div>
            </div>
        </div>

        <!-- Milestone Modal -->
        <div v-if="showMilestoneModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ editingMilestone ? 'Edit' : 'Add' }} Milestone</h3>
                    <button @click="showMilestoneModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveMilestone">
                        <div class="form-group">
                            <label>Progress Step (%)</label>
                            <select v-model="milestoneForm.progress_step" class="form-control" required>
                                <option value="25">25%</option>
                                <option value="50">50%</option>
                                <option value="75">75%</option>
                                <option value="100">100%</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Labor Release Amount (KSH)</label>
                            <input type="number" v-model="milestoneForm.labor_release_amount" step="0.01" min="0" required class="form-control">
                            <small class="assignment-field-help">
                                This is the maximum technician labor value this milestone can unlock.
                            </small>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea v-model="milestoneForm.notes" rows="2" class="form-control"></textarea>
                        </div>

                        <div class="milestone-allocation-editor">
                            <div class="milestone-allocation-editor-head">
                                <div>
                                    <h4>Technician Allocation Plan</h4>
                                    <p>Split this milestone's labor release across assigned technicians.</p>
                                </div>
                                <button type="button" @click="addMilestoneAllocation" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-plus"></i>
                                    Add Technician
                                </button>
                            </div>

                            <div class="milestone-allocation-summary">
                                <span>Allocated: KSH {{ formatCurrency(milestoneAllocatedTotal) }}</span>
                                <span>Remaining: KSH {{ formatCurrency(milestoneAllocationRemaining) }}</span>
                            </div>

                            <div v-if="milestoneForm.allocations.length > 0" class="milestone-allocation-editor-list">
                                <div v-for="(allocation, index) in milestoneForm.allocations" :key="`allocation-${index}`" class="milestone-allocation-editor-row">
                                    <div class="alloc-tech-col">
                                        <select
                                            v-model="allocation.technician_id"
                                            class="form-control"
                                            required
                                            @change="onMilestoneAllocationTechnicianChange(index)"
                                        >
                                            <option value="">Select technician</option>
                                            <option
                                                v-for="tech in availableMilestoneTechnicians(index)"
                                                :key="tech.id"
                                                :value="tech.id"
                                            >
                                                {{ tech.user?.name || tech.technician_id }}
                                            </option>
                                        </select>
                                        <div v-if="allocation.technician_id" class="alloc-tech-budget">
                                            <span>Agreed: KSH {{ formatCurrency(milestoneAssignedTechnicians.find(t => Number(t.id) === Number(allocation.technician_id))?.agreed_compensation || 0) }}</span>
                                            <span class="alloc-sep">·</span>
                                            <span>Free: KSH {{ formatCurrency(getTechRemainingBudget(allocation.technician_id)) }}</span>
                                        </div>
                                    </div>
                                    <input
                                        type="number"
                                        v-model.number="allocation.allocated_amount"
                                        step="0.01"
                                        min="0"
                                        :max="getTechRemainingBudget(allocation.technician_id) + Number(allocation.allocated_amount || 0)"
                                        class="form-control"
                                        placeholder="Release amount"
                                        required
                                    >
                                    <input
                                        type="text"
                                        v-model="allocation.notes"
                                        class="form-control"
                                        placeholder="Optional note"
                                    >
                                    <button type="button" @click="removeMilestoneAllocation(index)" class="btn btn-danger btn-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <p v-else class="milestone-allocation-empty">
                                No technician allocations added yet. You can still save the milestone and plan allocations later.
                            </p>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="showMilestoneModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveMilestone" class="btn btn-primary">Save Milestone</button>
                </div>
            </div>
        </div>

        <!-- Sub-Task Technician Assignment Modal -->
        <div v-if="showSubTaskAssignModal" class="modal-overlay">
            <div class="modal-content modal-lg" @click.stop>
                <div class="modal-header">
                    <h3>Assign Technician to Sub-Task</h3>
                    <button @click="closeSubTaskAssignModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p><strong>Sub-Task:</strong> {{ assigningSubTask?.title }}</p>
                    <p v-if="!job.lead_technician_id" class="lead-notice">
                        <i class="fas fa-info-circle"></i>
                        The first technician assigned will become the <strong>Lead / Main Focal Point</strong> for this service request.
                    </p>

                    <div class="assignment-budget-panel">
                        <div class="assignment-budget-grid">
                            <div class="assignment-budget-stat">
                                <span>Labor budget</span>
                                <strong>KSH {{ formatCurrency(laborBudgetTotal) }}</strong>
                            </div>
                            <div class="assignment-budget-stat">
                                <span>Allocated dues</span>
                                <strong>KSH {{ formatCurrency(totalLaborAllocated) }}</strong>
                            </div>
                            <div class="assignment-budget-stat">
                                <span>Available here</span>
                                <strong>KSH {{ formatCurrency(availableAssignmentBudget) }}</strong>
                            </div>
                        </div>
                        <p v-if="!hasLaborBudget" class="assignment-budget-warning">
                            Set the labor budget first before assigning technician dues.
                        </p>
                        <p v-else class="assignment-budget-hint">
                            Keep each sub-task dues amount inside the remaining labor budget for this job.
                        </p>
                    </div>

                    <div class="tech-picker-filters">
                        <input
                            v-model="techFilterSearch"
                            type="text"
                            placeholder="Search by name…"
                            class="tech-filter-input"
                        />
                        <select v-model="techFilterTrade" class="tech-filter-select">
                            <option value="">All trades</option>
                            <option v-for="trade in availableTrades" :key="trade" :value="trade">
                                {{ trade.charAt(0).toUpperCase() + trade.slice(1) }}
                            </option>
                        </select>
                        <select v-model="techFilterStatus" class="tech-filter-select">
                            <option value="">All statuses</option>
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                        <span class="tech-filter-count">{{ sortedTechnicians.length }} shown</span>
                    </div>

                    <div class="form-group">
                        <label>Select Technician:</label>
                        <div class="technician-list">
                            <div
                                v-for="tech in sortedTechnicians"
                                :key="tech.id"
                                :class="['technician-item', { selected: selectedTechnician?.id === tech.id }]"
                                @click="selectedTechnician = tech"
                            >
                                <div class="technician-info">
                                    <h4>{{ tech.user.name }}</h4>
                                    <p>{{ tech.specialization }} | {{ tech.location }}</p>
                                    <p><strong>Rating:</strong> {{ tech.rating }}/5</p>
                                    <p><strong>Skills:</strong> {{ tech.skills?.join(', ') || 'N/A' }}</p>
                                </div>
                                <div class="technician-status">
                                    <span :class="['status', tech.availability]">
                                        {{ formatAvailability(tech.availability) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row assignment-finance-row">
                        <div class="form-group">
                            <label>Technician Dues (KSH)</label>
                            <input
                                type="number"
                                v-model.number="assignmentForm.agreed_compensation"
                                step="0.01"
                                min="0"
                                :max="availableAssignmentBudget"
                                required
                                class="form-control"
                            >
                            <small class="assignment-field-help">
                                Maximum available for this assignment: KSH {{ formatCurrency(availableAssignmentBudget) }}
                            </small>
                        </div>
                        <div class="form-group">
                            <label>Compensation Notes</label>
                            <textarea
                                v-model="assignmentForm.compensation_notes"
                                rows="3"
                                class="form-control"
                                placeholder="Optional notes about the agreed labor dues."
                            ></textarea>
                        </div>
                    </div>

                    <p v-if="assignmentOverBudget" class="assignment-inline-error">
                        This amount is above the remaining labor budget for the current assignment mix.
                    </p>
                </div>
                <div class="modal-footer">
                    <button @click="closeSubTaskAssignModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="assignSubTaskTechnician"
                        :disabled="!canSubmitAssignment"
                        class="btn btn-primary"
                    >
                        Assign Technician
                    </button>
                </div>
            </div>
        </div>

        <!-- Lead Technician Assignment Modal -->
        <div v-if="showLeadAssignModal" class="modal-overlay">
            <div class="modal-content modal-lg" @click.stop>
                <div class="modal-header">
                    <h3>{{ job.lead_technician ? 'Change' : 'Assign' }} Lead Technician</h3>
                    <button @click="closeLeadAssignModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p><strong>Job:</strong> {{ job.request_id }} - {{ job.description }}</p>
                    <p class="lead-notice">
                        <i class="fas fa-info-circle"></i>
                        The lead technician is the <strong>main focal point</strong> for this service request and oversees all sub-tasks.
                    </p>

                    <div class="assignment-budget-panel">
                        <div class="assignment-budget-grid">
                            <div class="assignment-budget-stat">
                                <span>Labor budget</span>
                                <strong>KSH {{ formatCurrency(laborBudgetTotal) }}</strong>
                            </div>
                            <div class="assignment-budget-stat">
                                <span>Allocated dues</span>
                                <strong>KSH {{ formatCurrency(totalLaborAllocated) }}</strong>
                            </div>
                            <div class="assignment-budget-stat">
                                <span>Available here</span>
                                <strong>KSH {{ formatCurrency(availableAssignmentBudget) }}</strong>
                            </div>
                        </div>
                        <p v-if="!hasLaborBudget" class="assignment-budget-warning">
                            Set the labor budget first before assigning technician dues.
                        </p>
                        <p v-else class="assignment-budget-hint">
                            The lead technician dues are also reserved from the labor budget, so the total allocation stays controlled.
                        </p>
                    </div>

                    <div class="tech-picker-filters">
                        <input
                            v-model="techFilterSearch"
                            type="text"
                            placeholder="Search by name…"
                            class="tech-filter-input"
                        />
                        <select v-model="techFilterTrade" class="tech-filter-select">
                            <option value="">All trades</option>
                            <option v-for="trade in availableTrades" :key="trade" :value="trade">
                                {{ trade.charAt(0).toUpperCase() + trade.slice(1) }}
                            </option>
                        </select>
                        <select v-model="techFilterStatus" class="tech-filter-select">
                            <option value="">All statuses</option>
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                        <span class="tech-filter-count">{{ sortedTechnicians.length }} shown</span>
                    </div>

                    <div class="form-group">
                        <label>Select Lead Technician:</label>
                        <div class="technician-list">
                            <div
                                v-for="tech in sortedTechnicians"
                                :key="tech.id"
                                :class="['technician-item', { selected: selectedTechnician?.id === tech.id }]"
                                @click="selectedTechnician = tech"
                            >
                                <div class="technician-info">
                                    <h4>{{ tech.user.name }}</h4>
                                    <p>{{ tech.specialization }} | {{ tech.location }}</p>
                                    <p><strong>Rating:</strong> {{ tech.rating }}/5</p>
                                    <p><strong>Skills:</strong> {{ tech.skills?.join(', ') || 'N/A' }}</p>
                                </div>
                                <div class="technician-status">
                                    <span :class="['status', tech.availability]">
                                        {{ formatAvailability(tech.availability) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row assignment-finance-row">
                        <div class="form-group">
                            <label>Lead Technician Dues (KSH)</label>
                            <input
                                type="number"
                                v-model.number="assignmentForm.agreed_compensation"
                                step="0.01"
                                min="0"
                                :max="availableAssignmentBudget"
                                required
                                class="form-control"
                            >
                            <small class="assignment-field-help">
                                Maximum available for this assignment: KSH {{ formatCurrency(availableAssignmentBudget) }}
                            </small>
                        </div>
                        <div class="form-group">
                            <label>Compensation Notes</label>
                            <textarea
                                v-model="assignmentForm.compensation_notes"
                                rows="3"
                                class="form-control"
                                placeholder="Optional notes about the agreed labor dues."
                            ></textarea>
                        </div>
                    </div>

                    <p v-if="assignmentOverBudget" class="assignment-inline-error">
                        This amount is above the remaining labor budget for the current assignment mix.
                    </p>
                </div>
                <div class="modal-footer">
                    <button @click="closeLeadAssignModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="assignLeadTechnician"
                        :disabled="!canSubmitAssignment"
                        class="btn btn-primary"
                    >
                        {{ job.lead_technician ? 'Change' : 'Assign' }} Lead Technician
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { Link } from '@inertiajs/vue3'
import { ref, computed, reactive } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    job: {
        type: Object,
        required: true
    },
    technicians: {
        type: Array,
        default: () => []
    },
    budgetSummary: {
        type: Object,
        default: null
    }
})

// State
const showSingleAssignModal = ref(false)
const showSubTaskAssignModal = ref(false)
const showLeadAssignModal = ref(false)
const showAddSubTaskForm = ref(false)

// Agreed-fee inline edit (#22)
const showFeeEditPanel = ref(false)
const feeEditForm = reactive({ agreed_compensation: 0, compensation_notes: '' })
const savingFee = ref(false)

const openFeeEdit = () => {
    const assignment = props.job.has_sub_tasks ? currentLeadAssignment.value : currentSingleAssignment.value
    feeEditForm.agreed_compensation = Number(assignment?.agreed_compensation || 0)
    feeEditForm.compensation_notes = assignment?.compensation_notes || ''
    showFeeEditPanel.value = true
}

const saveAgreedFee = () => {
    if (savingFee.value) return
    const technicianId = props.job.has_sub_tasks
        ? props.job.lead_technician_id
        : props.job.technician_id
    savingFee.value = true
    router.post(
        `/admin/jobs/${props.job.id}/assignment-fee`,
        {
            technician_id: technicianId,
            agreed_compensation: feeEditForm.agreed_compensation,
            compensation_notes: feeEditForm.compensation_notes,
        },
        {
            preserveScroll: true,
            onSuccess: () => { showFeeEditPanel.value = false },
            onFinish: () => { savingFee.value = false },
        }
    )
}

const selectedTechnician = ref(null)
const assigningSubTask = ref(null)
const editingSubTask = ref(null)
const assignmentContext = ref('single')

// Technician picker filters
const techFilterTrade = ref('')
const techFilterStatus = ref('')
const techFilterSearch = ref('')

const newSubTask = reactive({
    title: '',
    description: ''
})

const editSubTaskForm = reactive({
    title: '',
    description: ''
})

// Budget state
const showBudgetModal = ref(false)
const showExpenseModal = ref(false)
const expenseForm = ref({
    category: 'materials',
    description: '',
    amount: null,
    expense_date: new Date().toISOString().slice(0, 10),
    vendor: '',
    receipt_reference: '',
    notes: '',
})
const canSaveExpense = computed(() => !!expenseForm.value.description && Number(expenseForm.value.amount) > 0)

function openExpenseModal(category) {
    expenseForm.value = {
        category,
        description: '',
        amount: null,
        expense_date: new Date().toISOString().slice(0, 10),
        vendor: '',
        receipt_reference: '',
        notes: '',
    }
    showExpenseModal.value = true
}

function saveExpenseFromBudget() {
    if (!canSaveExpense.value) return
    router.post('/admin/expenditures', {
        service_request_id: props.job.id,
        ...expenseForm.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { showExpenseModal.value = false },
    })
}
const budgetForm = reactive({
    labor_budget: 0,
    materials_budget: 0,
    other_budget: 0,
    notes: ''
})
const assignmentForm = reactive({
    agreed_compensation: '',
    compensation_notes: '',
    contact_time_minutes: null,
})
const progressValidationForms = reactive({})

// Computed
const assignableStatuses = ['pending', 'ready_for_assignment', 'assigned', 'in_progress', 'suspended', 'delayed', 'reassigned']

const canAssignTechnician = computed(() => {
    return assignableStatuses.includes(props.job.status)
})

const canReassignTechnician = computed(() => {
    return assignableStatuses.includes(props.job.status) && !['completed', 'cancelled'].includes(props.job.status)
})

const canAddSubTasks = computed(() => {
    return assignableStatuses.includes(props.job.status)
})

const completedSubTasks = computed(() => {
    return props.job.sub_tasks?.filter(st => st.status === 'completed').length || 0
})

const subTaskCount = computed(() => props.job.sub_tasks?.length || 0)
const activeSubTasks = computed(() => Math.max(subTaskCount.value - completedSubTasks.value, 0))
const normalizedProgress = computed(() => Number(props.job.progress_percentage ?? 0))
const serviceCategoryName = computed(() => props.job.service_category?.name || 'Uncategorized service')
const workflowLabel = computed(() => props.job.has_sub_tasks ? 'Multi-technician workflow' : 'Single-technician workflow')
const primaryTechnician = computed(() => props.job.has_sub_tasks ? props.job.lead_technician : props.job.technician)
const primaryTechnicianAvailabilityClass = computed(() => {
    if (!primaryTechnician.value) return 'pending'
    return getAvailabilityBadgeClass(primaryTechnician.value.availability)
})
const availableTechnicianCount = computed(() => props.technicians.filter(tech => tech.availability === 'available').length)
const displayQuoteAmount = computed(() => props.job.quote_amount ?? props.job.quoted_amount ?? null)
const displayFinalAmount = computed(() => props.job.final_amount ?? null)
const milestoneCount = computed(() => props.job.milestones?.length || 0)
const dueMilestonesCount = computed(() => props.job.milestones?.filter(milestone => milestone.status === 'reached').length || 0)
const paidMilestonesCount = computed(() => props.job.milestones?.filter(milestone => milestone.status === 'paid').length || 0)
const milestoneLaborReleasedTotal = computed(() => {
    return (props.job.milestones || []).reduce((sum, milestone) => {
        return sum + Number(milestone.labor_release_amount || 0)
    }, 0)
})
const progressReports = computed(() => props.job.progress_reports || [])

// Detects the "Mark Complete tapped without 100% progress report" scenario:
// the SR shows progress=100 or status=completed, but the latest validated
// progress report sits below 100%. Admin needs to backfill to unlock final
// payment processing.
const latestValidatedReportPct = computed(() => {
    const validated = progressReports.value
        .filter(r => r.is_validated)
        .map(r => Number(r.validated_percent ?? r.percent_complete) || 0)
    return validated.length ? Math.max(...validated) : 0
})
const needsFinalReportBackfill = computed(() => {
    const jobIsClosed = props.job.status === 'completed' || Number(props.job.progress_percentage) >= 100
    return jobIsClosed && latestValidatedReportPct.value < 100
})

const backfilling = ref(false)
const backfillFinalProgressReport = () => {
    if (!confirm('Backfill a validated 100% progress report on the technician\'s behalf? This will sync the data and allow the final payment to be processed.')) return
    backfilling.value = true
    router.post(`/admin/jobs/${props.job.id}/backfill-final-progress`, {
        notes: 'Final report backfilled by admin to close out completion.',
    }, {
        preserveScroll: true,
        onFinish: () => { backfilling.value = false },
    })
}

// Detect duplicate completed Payment rows on this job — same payment_request_id
// appearing twice, which inflates client portal totals.
const duplicatePaymentCount = computed(() => {
    const payments = (props.job.payments || []).filter(p => p.status === 'completed' && p.payment_request_id)
    const counts = {}
    payments.forEach(p => { counts[p.payment_request_id] = (counts[p.payment_request_id] || 0) + 1 })
    return Object.values(counts).reduce((sum, n) => sum + Math.max(0, n - 1), 0)
})
const hasDuplicatePayments = computed(() => duplicatePaymentCount.value > 0)

const deduping = ref(false)
const dedupePayments = (dryRun) => {
    const verb = dryRun ? 'Run a dry-run of payment dedup' : 'Remove duplicate payments (mark them refunded)'
    if (!confirm(`${verb} on this job?`)) return
    deduping.value = true
    router.post(`/admin/jobs/${props.job.id}/deduplicate-payments`, {
        dry_run: dryRun ? 1 : 0,
    }, {
        preserveScroll: true,
        onFinish: () => { deduping.value = false },
    })
}
const completedLaborPayments = computed(() => {
    return (props.job.technician_payments || []).filter((payment) => {
        return payment.category === 'labor' && payment.status === 'completed'
    })
})
const activePrimaryAssignments = computed(() => {
    return (props.job.job_assignments || []).filter((assignment) => {
        return !assignment.service_sub_task_id && ['pending', 'accepted', 'completed'].includes(assignment.status)
    })
})
const currentSingleAssignment = computed(() => {
    return [...activePrimaryAssignments.value]
        .reverse()
        .find((assignment) => Number(assignment.technician_id) === Number(props.job.technician_id)) || null
})
const currentLeadAssignment = computed(() => {
    return [...activePrimaryAssignments.value]
        .reverse()
        .find((assignment) => Number(assignment.technician_id) === Number(props.job.lead_technician_id)) || null
})
const laborBudgetTotal = computed(() => Number(props.job.budget?.labor_budget || 0))
const totalLaborAllocated = computed(() => {
    const directAllocated = activePrimaryAssignments.value.reduce((sum, assignment) => {
        return sum + Number(assignment.agreed_compensation || 0)
    }, 0)

    const subTaskAllocated = (props.job.sub_tasks || []).reduce((sum, subTask) => {
        if (!subTask.technician_id) return sum
        return sum + Number(subTask.agreed_compensation || 0)
    }, 0)

    return directAllocated + subTaskAllocated
})
const hasLaborBudget = computed(() => laborBudgetTotal.value > 0)
const currentAssignmentAmount = computed(() => {
    if (assignmentContext.value === 'subtask') {
        return Number(assigningSubTask.value?.agreed_compensation || 0)
    }

    if (assignmentContext.value === 'lead') {
        return Number(currentLeadAssignment.value?.agreed_compensation || 0)
    }

    return Number(currentSingleAssignment.value?.agreed_compensation || 0)
})
const availableAssignmentBudget = computed(() => {
    return Math.max(laborBudgetTotal.value - (totalLaborAllocated.value - currentAssignmentAmount.value), 0)
})
const normalizedAssignmentCompensation = computed(() => {
    const value = Number(assignmentForm.agreed_compensation)
    return Number.isFinite(value) ? value : -1
})
const assignmentOverBudget = computed(() => {
    return normalizedAssignmentCompensation.value > availableAssignmentBudget.value
})
const canSubmitAssignment = computed(() => {
    return Boolean(selectedTechnician.value)
        && hasLaborBudget.value
        && normalizedAssignmentCompensation.value >= 0
        && !assignmentOverBudget.value
})
const budgetUsagePercent = computed(() => {
    if (!props.budgetSummary?.total?.budgeted) return props.budgetSummary?.total?.actual ? 100 : 0
    return (props.budgetSummary.total.actual / props.budgetSummary.total.budgeted) * 100
})
const budgetHeadline = computed(() => {
    if (!props.budgetSummary?.total) return 'Budget has not been created.'

    if (props.budgetSummary.total.remaining < 0) {
        return 'This job is currently over budget and needs attention.'
    }

    return `KSH ${formatCurrency(props.budgetSummary.total.remaining)} remaining across tracked costs.`
})
const assignmentHeadline = computed(() => {
    if (primaryTechnician.value) {
        return props.job.has_sub_tasks ? 'Lead assigned' : 'Assigned'
    }

    return 'Unassigned'
})
const assignmentSupportText = computed(() => {
    if (primaryTechnician.value) {
        return props.job.has_sub_tasks
            ? `${primaryTechnician.value.user.name} is coordinating this job.`
            : `${primaryTechnician.value.user.name} owns the current field work.`
    }

    return 'No technician has been assigned yet.'
})
const assignmentSummary = computed(() => {
    if (primaryTechnician.value) {
        return props.job.has_sub_tasks
            ? `${primaryTechnician.value.user.name} is acting as lead while ${subTaskCount.value} sub-task${subTaskCount.value === 1 ? '' : 's'} move through execution.`
            : `${primaryTechnician.value.user.name} is currently assigned to deliver this service request.`
    }

    return 'This job is still waiting for technician ownership or a lead assignment.'
})
const progressLabel = computed(() => {
    if (normalizedProgress.value >= 100) return 'Work is fully complete.'
    if (normalizedProgress.value >= 75) return 'Job is in the closing stretch.'
    if (normalizedProgress.value > 0) return 'Execution is currently underway.'
    return 'Work has not started yet.'
})

const availableTrades = computed(() => {
    const trades = new Set(props.technicians.map(t => t.trade).filter(Boolean))
    return [...trades].sort()
})

const milestoneAssignedTechnicians = computed(() => {
    // Build a map of technician id → { technician object, agreed_compensation }
    // agreed_compensation = sum of active job-assignment dues (primary) or sub-task dues (fallback)
    const techMap = new Map()

    const merge = (tech, compensation) => {
        if (!tech) return
        const id = Number(tech.id)
        const existing = techMap.get(id)
        techMap.set(id, {
            ...(existing || tech),
            agreed_compensation: (existing?.agreed_compensation || 0) + Number(compensation || 0),
        })
    }

    ;(props.job.job_assignments || []).forEach((assignment) => {
        if (!assignment.technician) return
        if (!['pending', 'accepted', 'completed'].includes(assignment.status)) return
        merge(assignment.technician, assignment.agreed_compensation)
    })

    ;(props.job.sub_tasks || []).forEach((subTask) => {
        if (!subTask.technician) return
        // Only use sub-task dues if this technician has no direct assignment yet
        const id = Number(subTask.technician.id)
        if (!techMap.has(id)) merge(subTask.technician, subTask.agreed_compensation)
    })

    // Ensure lead and primary are always present even without explicit assignment records
    if (props.job.technician && !techMap.has(Number(props.job.technician.id))) {
        techMap.set(Number(props.job.technician.id), { ...props.job.technician, agreed_compensation: 0 })
    }
    if (props.job.lead_technician && !techMap.has(Number(props.job.lead_technician.id))) {
        techMap.set(Number(props.job.lead_technician.id), { ...props.job.lead_technician, agreed_compensation: 0 })
    }

    return [...techMap.values()].sort((a, b) =>
        (a.user?.name || '').localeCompare(b.user?.name || '')
    )
})

/**
 * For each technician: sum of allocated_amount across ALL milestones EXCEPT
 * the one currently being edited (so we know how much budget is already spoken for).
 */
const techAllocatedElsewhere = computed(() => {
    const result = {}
    const excludeId = editingMilestone.value?.id
    ;(props.job.milestones || []).forEach((milestone) => {
        if (milestone.id === excludeId) return
        ;(milestone.allocations || []).forEach((alloc) => {
            const id = Number(alloc.technician_id)
            result[id] = (result[id] || 0) + Number(alloc.allocated_amount || 0)
        })
    })
    return result
})

/**
 * How much of a technician's agreed dues are still free to allocate
 * in milestones (considering what's already locked in other milestones).
 */
const getTechRemainingBudget = (technicianId) => {
    const tech = milestoneAssignedTechnicians.value.find(t => Number(t.id) === Number(technicianId))
    const agreed = tech?.agreed_compensation || 0
    const usedElsewhere = techAllocatedElsewhere.value[Number(technicianId)] || 0
    return Math.max(0, agreed - usedElsewhere)
}

/**
 * When a technician is selected in an allocation row, auto-suggest the
 * lesser of: their remaining budget or this milestone's unallocated balance.
 */
const onMilestoneAllocationTechnicianChange = (index) => {
    const allocation = milestoneForm.allocations[index]
    if (!allocation?.technician_id) return
    const techRemaining = getTechRemainingBudget(allocation.technician_id)
    const milestoneRemaining = milestoneAllocationRemaining.value + Number(allocation.allocated_amount || 0)
    allocation.allocated_amount = Math.min(techRemaining, milestoneRemaining)
}

const sortedTechnicians = computed(() => {
    let list = [...props.technicians]

    // Filter by trade
    if (techFilterTrade.value) {
        list = list.filter(t => t.trade === techFilterTrade.value)
    }

    // Filter by availability status
    if (techFilterStatus.value) {
        list = list.filter(t => t.availability === techFilterStatus.value)
    }

    // Filter by name search
    if (techFilterSearch.value.trim()) {
        const q = techFilterSearch.value.toLowerCase()
        list = list.filter(t => t.user?.name?.toLowerCase().includes(q))
    }

    return list.sort((a, b) => {
        const aAvailable = a.availability === 'available'
        const bAvailable = b.availability === 'available'
        if (aAvailable && !bAvailable) return -1
        if (!aAvailable && bAvailable) return 1

        if (props.job.service_category) {
            const aHasSkills = a.skills?.some(skill =>
                skill.toLowerCase().includes(props.job.service_category.name.toLowerCase()) ||
                props.job.service_category.name.toLowerCase().includes(skill.toLowerCase())
            )
            const bHasSkills = b.skills?.some(skill =>
                skill.toLowerCase().includes(props.job.service_category.name.toLowerCase()) ||
                props.job.service_category.name.toLowerCase().includes(skill.toLowerCase())
            )
            if (aHasSkills && !bHasSkills) return -1
            if (!aHasSkills && bHasSkills) return 1
        }

        return b.rating - a.rating
    })
})

progressReports.value.forEach((report) => {
    progressValidationForms[report.id] = {
        validated_percent: report.validated_percent ?? report.percent_complete,
        validation_notes: report.validation_notes || '',
        // Pre-fill with technician's original — admin can edit before approving
        client_visible_notes: report.client_visible_notes || report.notes || '',
        remove_photo_ids: [],
    }
})

// Sub-task methods
const addSubTask = () => {
    if (!newSubTask.title) return

    router.post(`/admin/jobs/${props.job.id}/sub-tasks`, {
        title: newSubTask.title,
        description: newSubTask.description
    }, {
        preserveState: false,
        onSuccess: () => {
            newSubTask.title = ''
            newSubTask.description = ''
            showAddSubTaskForm.value = false
        }
    })
}

const cancelAddSubTask = () => {
    newSubTask.title = ''
    newSubTask.description = ''
    showAddSubTaskForm.value = false
}

const startEditSubTask = (subTask) => {
    editingSubTask.value = subTask.id
    editSubTaskForm.title = subTask.title
    editSubTaskForm.description = subTask.description || ''
}

const saveSubTask = (subTask) => {
    router.put(`/admin/sub-tasks/${subTask.id}`, {
        title: editSubTaskForm.title,
        description: editSubTaskForm.description
    }, {
        onSuccess: () => {
            editingSubTask.value = null
        }
    })
}

const deleteSubTask = (subTask) => {
    if (!confirm(`Are you sure you want to delete "${subTask.title}"?`)) return

    router.delete(`/admin/sub-tasks/${subTask.id}`)
}

// Assignment methods
const findTechnicianById = (technicianId) => {
    return props.technicians.find((technician) => Number(technician.id) === Number(technicianId)) || null
}

const seedAssignmentForm = ({ amount = '', notes = '' } = {}) => {
    assignmentForm.agreed_compensation = amount === '' || amount === null || typeof amount === 'undefined'
        ? ''
        : Number(amount)
    assignmentForm.compensation_notes = notes || ''
}

const resetAssignmentForm = () => {
    assignmentForm.agreed_compensation = ''
    assignmentForm.compensation_notes = ''
}

const showAssignModalFor = (subTask) => {
    assigningSubTask.value = subTask
    assignmentContext.value = 'subtask'
    selectedTechnician.value = findTechnicianById(subTask.technician_id)
    seedAssignmentForm({
        amount: subTask.agreed_compensation,
        notes: subTask.compensation_notes,
    })
    showSubTaskAssignModal.value = true
}

const assignSubTaskTechnician = () => {
    if (!canSubmitAssignment.value || !assigningSubTask.value) return

    router.post(`/admin/sub-tasks/${assigningSubTask.value.id}/assign`, {
        technician_id: selectedTechnician.value.id,
        agreed_compensation: normalizedAssignmentCompensation.value,
        compensation_notes: assignmentForm.compensation_notes,
    }, {
        preserveState: false,
        onSuccess: () => {
            closeSubTaskAssignModal()
        }
    })
}

const resetTechFilters = () => {
    techFilterTrade.value = ''
    techFilterStatus.value = ''
    techFilterSearch.value = ''
}

const closeSubTaskAssignModal = () => {
    showSubTaskAssignModal.value = false
    assigningSubTask.value = null
    selectedTechnician.value = null
    resetAssignmentForm()
    resetTechFilters()
}

const openSingleAssignModal = () => {
    assignmentContext.value = 'single'
    selectedTechnician.value = findTechnicianById(props.job.technician_id)
    seedAssignmentForm({
        amount: currentSingleAssignment.value?.agreed_compensation,
        notes: currentSingleAssignment.value?.compensation_notes,
    })
    showSingleAssignModal.value = true
}

const assignSingleTechnician = () => {
    if (!canSubmitAssignment.value) return

    router.post(`/admin/jobs/${props.job.id}/assign`, {
        technician_id: selectedTechnician.value.id,
        agreed_compensation: normalizedAssignmentCompensation.value,
        compensation_notes: assignmentForm.compensation_notes,
        contact_time_minutes: assignmentForm.contact_time_minutes || null,
    }, {
        preserveState: false,
        onSuccess: () => {
            closeSingleAssignModal()
        }
    })
}

const closeSingleAssignModal = () => {
    showSingleAssignModal.value = false
    selectedTechnician.value = null
    resetAssignmentForm()
    resetTechFilters()
}

// Lead technician methods
const openLeadAssignModal = () => {
    assignmentContext.value = 'lead'
    selectedTechnician.value = findTechnicianById(props.job.lead_technician_id)
    seedAssignmentForm({
        amount: currentLeadAssignment.value?.agreed_compensation,
        notes: currentLeadAssignment.value?.compensation_notes,
    })
    showLeadAssignModal.value = true
}

const closeLeadAssignModal = () => {
    showLeadAssignModal.value = false
    selectedTechnician.value = null
    resetAssignmentForm()
    resetTechFilters()
}

const assignLeadTechnician = () => {
    if (!canSubmitAssignment.value) return

    router.post(`/admin/jobs/${props.job.id}/assign-lead`, {
        technician_id: selectedTechnician.value.id,
        agreed_compensation: normalizedAssignmentCompensation.value,
        compensation_notes: assignmentForm.compensation_notes,
    }, {
        preserveState: false,
        onSuccess: () => {
            closeLeadAssignModal()
        }
    })
}

// Budget methods
const budgetTotal = computed(() =>
    parseFloat(budgetForm.labor_budget || 0) +
    parseFloat(budgetForm.materials_budget || 0) +
    parseFloat(budgetForm.other_budget || 0)
)

const openBudgetModal = () => {
    if (props.job.budget) {
        budgetForm.labor_budget = props.job.budget.labor_budget
        budgetForm.materials_budget = props.job.budget.materials_budget
        budgetForm.other_budget = props.job.budget.other_budget
        budgetForm.notes = props.job.budget.notes || ''
    } else {
        budgetForm.labor_budget = 0
        budgetForm.materials_budget = 0
        budgetForm.other_budget = 0
        budgetForm.notes = ''
    }
    showBudgetModal.value = true
}

const saveBudget = () => {
    if (props.job.budget?.id) {
        router.put(`/admin/budgets/${props.job.budget.id}`, { ...budgetForm }, {
            onSuccess: () => { showBudgetModal.value = false }
        })
    } else {
        router.post(`/admin/jobs/${props.job.id}/budget`, { ...budgetForm }, {
            onSuccess: () => { showBudgetModal.value = false }
        })
    }
}

// Milestone state
const showMilestoneModal = ref(false)
const editingMilestone = ref(null)
const milestoneForm = reactive({
    progress_step: 50,
    labor_release_amount: 0,
    notes: '',
    allocations: [],
})
const milestoneAllocatedTotal = computed(() => {
    return milestoneForm.allocations.reduce((sum, allocation) => {
        return sum + Number(allocation.allocated_amount || 0)
    }, 0)
})
const milestoneAllocationRemaining = computed(() => {
    return Math.max(Number(milestoneForm.labor_release_amount || 0) - milestoneAllocatedTotal.value, 0)
})

const newMilestoneAllocationRow = () => ({
    technician_id: '',
    allocated_amount: 0,
    notes: '',
})

const resetMilestoneForm = () => {
    milestoneForm.progress_step = 50
    milestoneForm.labor_release_amount = 0
    milestoneForm.notes = ''
    milestoneForm.allocations = []
}

const addMilestoneAllocation = () => {
    milestoneForm.allocations.push(newMilestoneAllocationRow())
}

const removeMilestoneAllocation = (index) => {
    milestoneForm.allocations.splice(index, 1)
}

const availableMilestoneTechnicians = (currentIndex) => {
    const selectedIds = milestoneForm.allocations
        .map((allocation, index) => index === currentIndex ? null : Number(allocation.technician_id || 0))
        .filter(Boolean)

    return milestoneAssignedTechnicians.value.filter((technician) => {
        return !selectedIds.includes(Number(technician.id))
            || Number(milestoneForm.allocations[currentIndex]?.technician_id) === Number(technician.id)
    })
}

const sanitizeMilestoneAllocations = () => {
    return milestoneForm.allocations
        .filter((allocation) => allocation.technician_id && Number(allocation.allocated_amount || 0) > 0)
        .map((allocation) => ({
            technician_id: Number(allocation.technician_id),
            allocated_amount: Number(allocation.allocated_amount),
            notes: allocation.notes || '',
        }))
}

const getMilestoneAllocatedAmount = (milestone) => {
    return (milestone.allocations || []).reduce((sum, allocation) => {
        return sum + Number(allocation.allocated_amount || 0)
    }, 0)
}

const getMilestoneRemainingAmount = (milestone) => {
    return Math.max(Number(milestone.labor_release_amount || 0) - getMilestoneAllocatedAmount(milestone), 0)
}

const openAddMilestoneModal = () => {
    editingMilestone.value = null
    resetMilestoneForm()
    showMilestoneModal.value = true
}

const editMilestone = (milestone) => {
    editingMilestone.value = milestone
    milestoneForm.progress_step = milestone.progress_step
    milestoneForm.labor_release_amount = milestone.labor_release_amount || 0
    milestoneForm.notes = milestone.notes || ''
    milestoneForm.allocations = (milestone.allocations || []).map((allocation) => ({
        technician_id: Number(allocation.technician_id),
        allocated_amount: Number(allocation.allocated_amount || 0),
        notes: allocation.notes || '',
    }))
    showMilestoneModal.value = true
}

const saveMilestone = () => {
    const payload = {
        progress_step: milestoneForm.progress_step,
        labor_release_amount: milestoneForm.labor_release_amount,
        notes: milestoneForm.notes,
        allocations: sanitizeMilestoneAllocations(),
    }

    if (editingMilestone.value) {
        router.put(`/admin/milestones/${editingMilestone.value.id}`, payload, {
            onSuccess: () => { showMilestoneModal.value = false; resetMilestoneForm() }
        })
    } else {
        router.post(`/admin/jobs/${props.job.id}/milestones`, payload, {
            onSuccess: () => { showMilestoneModal.value = false; resetMilestoneForm() }
        })
    }
}

const markMilestonePaid = (milestone) => {
    if (!confirm(`Mark milestone for ${milestone.progress_step}% as PAID?`)) return
    router.put(`/admin/milestones/${milestone.id}`, {
        status: 'paid',
        progress_step: milestone.progress_step,
        labor_release_amount: milestone.labor_release_amount || 0,
        notes: milestone.notes || '',
        allocations: (milestone.allocations || []).map((allocation) => ({
            technician_id: Number(allocation.technician_id),
            allocated_amount: Number(allocation.allocated_amount || 0),
            notes: allocation.notes || '',
        })),
    })
}

const deleteMilestone = (milestone) => {
    if (!confirm('Are you sure you want to delete this milestone?')) return
    router.delete(`/admin/milestones/${milestone.id}`)
}

const toggleReportPhotoRemoval = (reportId, photoId) => {
    const form = progressValidationForms[reportId]
    if (!form) return

    const index = form.remove_photo_ids.indexOf(photoId)
    if (index > -1) {
        form.remove_photo_ids.splice(index, 1)
    } else {
        form.remove_photo_ids.push(photoId)
    }
}

const validateProgressReport = (reportId) => {
    const form = progressValidationForms[reportId]
    if (!form) return

    const photos = form.admin_photo_files || []
    if (photos.length > 0) {
        const fd = new FormData()
        fd.append('validated_percent', form.validated_percent)
        fd.append('validation_notes', form.validation_notes || '')
        fd.append('client_visible_notes', form.client_visible_notes || '')
        ;(form.remove_photo_ids || []).forEach((id, i) => fd.append(`remove_photo_ids[${i}]`, id))
        photos.forEach((file, i) => fd.append(`admin_photos[${i}]`, file))
        router.post(`/admin/progress-reports/${reportId}/validate`, fd, {
            forceFormData: true,
            preserveScroll: true,
        })
    } else {
        router.post(`/admin/progress-reports/${reportId}/validate`, {
            validated_percent: form.validated_percent,
            validation_notes: form.validation_notes,
            client_visible_notes: form.client_visible_notes,
            remove_photo_ids: form.remove_photo_ids,
        }, { preserveScroll: true })
    }
}

const getTechnicianAgreedCompensation = (report) => {
    // Look for the technician's agreed compensation from their direct assignment
    const directAssignment = (props.job.job_assignments || [])
        .filter(a => !a.service_sub_task_id && ['pending', 'accepted', 'completed'].includes(a.status))
        .reverse()
        .find(a => Number(a.technician_id) === Number(report.technician_id))
    if (directAssignment) return Number(directAssignment.agreed_compensation || 0)

    // Check sub-task assignments
    const subTask = (props.job.sub_tasks || [])
        .find(st => Number(st.technician_id) === Number(report.technician_id))
    if (subTask) return Number(subTask.agreed_compensation || 0)

    // No assignment found — return 0 so the payout row shows nothing rather
    // than the project-wide labour budget which is not what the technician is owed.
    return 0
}

const getProgressTargetAmount = (report) => {
    const agreedCompensation = getTechnicianAgreedCompensation(report)
    const validatedPercent = Number(report.validated_percent ?? report.percent_complete ?? 0)

    if (!agreedCompensation || !validatedPercent) return 0

    return (agreedCompensation * validatedPercent) / 100
}

const getTechnicianLaborPaid = (report) => {
    return completedLaborPayments.value
        .filter((payment) => Number(payment.technician_id) === Number(report.technician_id))
        .reduce((sum, payment) => sum + Number(payment.amount || 0), 0)
}

const getProgressPayableAmount = (report) => {
    const outstanding = getProgressTargetAmount(report) - getTechnicianLaborPaid(report)
    return outstanding > 0 ? outstanding : 0
}

const canPayProgressReport = (report) => {
    return report.is_validated && Number(report.technician_id) > 0 && getProgressPayableAmount(report) > 0
}

const payProgressReport = (reportId) => {
    router.post(`/admin/progress-reports/${reportId}/pay-technician`, {}, {
        preserveScroll: true,
    })
}



const formatCurrency = (val) => {
    const num = parseFloat(val) || 0
    return num.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const getPercentUsed = (cat) => {
    if (!cat.budgeted || cat.budgeted === 0) return cat.actual > 0 ? 100 : 0
    return (cat.actual / cat.budgeted) * 100
}

const getProgressColor = (pct) => {
    if (pct >= 100) return '#e74c3c'
    if (pct >= 75) return '#f39c12'
    return '#27ae60'
}

// Formatters
const getStatusClass = (status) => {
    const statusMap = {
        'pending': 'new',
        'ready_for_assignment': 'new',
        'awaiting_payment': 'review',
        'payment_pending_approval': 'review',
        'awaiting_quote_approval': 'review',
        'awaiting_quote_generation': 'review',
        'assigned': 'approved',
        'queued': 'approved',
        'in_progress': 'review',
        'completed': 'available',
        'completed_pending_confirmation': 'available',
        'closed': 'available',
        'cancelled': 'leave',
        'suspended': 'leave',
        'delayed': 'leave',
        'reassigned': 'new',
    }
    return statusMap[status] || 'new'
}

const getAvailabilityBadgeClass = (availability) => {
    const availabilityMap = {
        available: 'approved',
        busy: 'review',
        on_leave: 'rejected'
    }

    return availabilityMap[availability] || 'pending'
}

const getSubTaskStatusClass = (status) => {
    const statusMap = {
        'pending': 'new',
        'assigned': 'approved',
        'in_progress': 'review',
        'completed': 'available'
    }
    return statusMap[status] || 'new'
}

const formatStatus = (status) => {
    const statusMap = {
        'pending': 'Pending',
        'ready_for_assignment': 'Ready for Assignment',
        'assigned': 'Assigned',
        'queued': 'Queued',
        'in_progress': 'In Progress',
        'delayed': 'Delayed',
        'suspended': 'Suspended',
        'reassigned': 'Reassigned',
        'completed': 'Completed',
        'completed_pending_confirmation': 'Completed - Pending Confirmation',
        'closed': 'Closed',
        'archived': 'Archived',
        'cancelled': 'Cancelled',
        'awaiting_payment': 'Awaiting Payment',
        'payment_pending_approval': 'Payment Pending Approval',
        'awaiting_quote_approval': 'Awaiting Quote Approval',
        'awaiting_quote_generation': 'Awaiting Quote',
        'draft_rfq': 'Draft RFQ',
    }
    return statusMap[status] || status?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || status
}

const formatSubTaskStatus = (status) => {
    const statusMap = {
        'pending': 'Pending',
        'assigned': 'Assigned',
        'in_progress': 'In Progress',
        'completed': 'Completed'
    }
    return statusMap[status] || status
}

const formatUrgency = (urgency) => {
    if (!urgency) return 'Not set'
    return urgency.charAt(0).toUpperCase() + urgency.slice(1)
}

const formatAvailability = (availability) => {
    const availabilityMap = {
        'available': 'Available',
        'busy': 'Busy',
        'on_leave': 'On Leave'
    }
    return availabilityMap[availability] || availability
}

const getInitials = (name) => {
    if (!name) return 'T'
    return name.split(' ').map(part => part[0]).join('').toUpperCase().slice(0, 2)
}

const formatDate = (dateString) => {
    if (!dateString) return 'Not scheduled'
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: 'Africa/Nairobi',
    })
}

// Human labels for the note-versions history panel. Stored server-side as
// raw column names; rendered here so ops don't see 'client_visible_notes'.
const formatNoteFieldName = (field) => {
    switch (field) {
        case 'client_visible_notes': return 'Client-visible notes'
        case 'validation_notes': return 'Internal validation note'
        case 'notes': return 'Technician notes'
        default: return field
    }
}

// For timestamps where a real time-of-day matters (progress submissions,
// validation events). Falls back gracefully if no value.
const formatDateTime = (dateString) => {
    if (!dateString) return '—'
    return new Date(dateString).toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Africa/Nairobi',
    })
}

const formatDateOnly = (dateString) => {
    if (!dateString) return '—'
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'Africa/Nairobi',
    })
}

const isSameDay = (a, b) => {
    if (!a || !b) return true
    const da = new Date(a).toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' })
    const db = new Date(b).toLocaleDateString('en-CA', { timeZone: 'Africa/Nairobi' })
    return da === db
}

defineOptions({
    layout: null
})
</script>

<style>

.job-details-page {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.12), transparent 26rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.job-details-hero,
.job-metrics-grid,
.job-layout-grid,
.overview-grid,
.assignment-profile-grid,
.subtask-summary-grid,
.budget-grid,
.milestone-summary-grid {
    display: grid;
    gap: 1rem;
}

.job-details-hero {
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
    margin-bottom: 1.5rem;
}

.hero-copy,
.hero-action-card,
.metric-card,
.job-shell-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.hero-copy {
    padding: 2rem;
    border-radius: 28px;
    background:
        linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92)),
        #00334b;
    color: #f8fafc;
}

.hero-topline,
.hero-actions,
.assignment-name-row,
.assignment-chip-row,
.header-actions-row,
.form-actions,
.assign-options,
.subtask-header,
.subtask-title-row,
.subtask-actions,
.subtask-technician,
.subtask-progress,
.budget-overview-top,
.budget-row,
.pricing-strip,
.milestone-header,
.milestone-actions,
.rating-stars,
.modal-header,
.modal-footer,
.technician-item,
.hero-meta-row {
    display: flex;
    gap: 0.85rem;
}

.hero-topline,
.subtask-header,
.subtask-technician,
.budget-overview-top,
.milestone-header,
.technician-item,
.modal-header,
.modal-footer {
    align-items: center;
    justify-content: space-between;
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

.section-kicker {
    color: #0f6c8f;
}

.hero-copy h1 {
    margin: 0.85rem 0 0;
    font-size: clamp(2rem, 3vw, 2.6rem);
    color: #ffffff;
}

.hero-copy p {
    margin: 0.9rem 0 0;
    max-width: 42rem;
    color: rgba(226, 232, 240, 0.88);
    line-height: 1.65;
}

.hero-meta-row {
    flex-wrap: wrap;
    margin-top: 1.4rem;
}

.hero-meta-pill,
.soft-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 0.9rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
    color: #f8fafc;
    font-size: 0.82rem;
    font-weight: 600;
}

.hero-action-card,
.metric-card,
.job-shell-card {
    padding: 1.4rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.94);
}

.hero-action-copy h3,
.job-card-header h3,
.assignment-empty-copy h4,
.subtask-title,
.modal-header h3 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.hero-action-copy p,
.job-card-header p,
.assignment-empty-copy p,
.overview-item p,
.sidebar-detail-item p,
.subtask-description,
.milestone-notes,
.modal-body p,
.text-muted {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.hero-action-grid,
.job-metrics-grid,
.overview-grid,
.assignment-profile-grid,
.subtask-summary-grid,
.budget-grid,
.milestone-summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.hero-action-grid {
    margin: 1rem 0 1.2rem;
}

.hero-action-tile,
.overview-item,
.profile-info-card,
.subtask-summary-card,
.budget-category-card,
.milestone-summary-card,
.sidebar-detail-item,
.metric-card,
.technician-item,
.filter-note,
.assignment-empty-card,
.assignment-profile-card,
.subtask-card,
.budget-overview-card,
.pricing-chip {
    background: #ffffff;
}

.hero-action-tile,
.overview-item,
.profile-info-card,
.subtask-summary-card,
.budget-category-card,
.milestone-summary-card,
.sidebar-detail-item,
.budget-overview-card,
.pricing-chip {
    padding: 1rem;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
}

.hero-action-tile span,
.overview-item span,
.profile-info-card span,
.subtask-summary-card span,
.budget-overview-top span,
.budget-row span,
.milestone-summary-card span,
.sidebar-detail-item span,
.pricing-chip span,
.budget-pct,
.sub-task-count,
.progress-label,
.rating-text,
.text-muted,
.lead-badge,
.soft-chip {
    color: #64748b;
    font-size: 0.8rem;
}

.hero-action-tile strong,
.overview-item strong,
.profile-info-card strong,
.subtask-summary-card strong,
.budget-overview-top strong,
.budget-row strong,
.milestone-summary-card strong,
.sidebar-detail-item strong,
.pricing-chip strong {
    display: block;
    margin-top: 0.35rem;
    color: #0f172a;
}

.hero-actions {
    flex-wrap: wrap;
}

.hero-action-btn {
    flex: 1 1 180px;
    justify-content: center;
}

.hero-status-pill,
.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.42rem 0.75rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.hero-status-pill.new {
    background: #e0f2fe;
    color: #0f6c8f;
}

.status-badge.pending {
    background: #f1f5f9;
    color: #64748b;
}

.hero-status-pill.review,
.status-badge.review,
.status-badge.reached {
    background: #fef3c7;
    color: #92400e;
}

.hero-status-pill.approved,
.status-badge.approved,
.status-badge.paid {
    background: #dcfce7;
    color: #166534;
}

.hero-status-pill.available {
    background: #dcfce7;
    color: #166534;
}

.hero-status-pill.leave,
.status-badge.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.job-metrics-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 1.5rem;
}

.metric-card {
    padding: 1.35rem;
}

.metric-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.9rem;
}

.metric-tag {
    display: inline-flex;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
    color: #475569;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.metric-icon,
.assignment-avatar {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
}

.metric-icon {
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 16px;
}

.assignment-avatar {
    width: 3.5rem;
    height: 3.5rem;
    border-radius: 18px;
    font-weight: 800;
    flex-shrink: 0;
}

.metric-card h3 {
    margin: 0;
    color: #0f172a;
    font-size: 1.8rem;
}

.metric-card p {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.tone-blue {
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.98), #ffffff);
}

.tone-green {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.98), #ffffff);
}

.tone-amber {
    background: linear-gradient(180deg, rgba(255, 251, 235, 0.99), #ffffff);
}

.tone-slate {
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.99), #ffffff);
}

.tone-green .metric-icon {
    background: linear-gradient(135deg, #0f766e, #22c55e);
}

.tone-amber .metric-icon {
    background: linear-gradient(135deg, #c2410c, #f59e0b);
}

.tone-slate .metric-icon {
    background: linear-gradient(135deg, #475569, #94a3b8);
}

.job-layout-grid {
    grid-template-columns: minmax(0, 1.7fr) minmax(300px, 0.95fr);
    align-items: start;
}

.job-primary-column,
.job-sidebar-column {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.job-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.job-card-header.compact {
    margin-bottom: 0.75rem;
}

.overview-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.detail-note-section {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}

.detail-note-section h4 {
    margin: 0 0 0.65rem;
    color: #0f172a;
}

.description-text {
    margin: 0;
    color: #475569;
    line-height: 1.7;
}

.assignment-profile-card,
.assignment-empty-card {
    padding: 1rem;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    background: linear-gradient(180deg, #f8fbfd 0%, #ffffff 100%);
}

.assignment-profile-top {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: 1rem;
}

.assignment-copy h4 {
    margin: 0;
    color: #0f172a;
}

.assignment-copy p {
    margin: 0.35rem 0 0;
    color: #64748b;
}

.assignment-chip-row {
    flex-wrap: wrap;
    margin-top: 0.8rem;
}

.soft-chip {
    padding: 0.42rem 0.72rem;
    border-radius: 999px;
    background: #eef2ff;
    color: #334155;
    font-weight: 700;
}

.assignment-profile-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 1rem;
}

.assignment-action-row {
    margin-top: 1rem;
}

.assignment-fee-panel {
    margin-top: 0.75rem;
    border-top: 1px solid var(--border-color, #e5e7eb);
    padding-top: 0.75rem;
}

.assignment-fee-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.assignment-fee-info {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.assignment-fee-label {
    font-size: 0.75rem;
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.assignment-fee-value {
    font-size: 1rem;
    color: var(--text-primary, #111827);
}

.assignment-fee-edit-form {
    margin-top: 0.75rem;
    padding: 0.75rem;
    background: var(--bg-secondary, #f9fafb);
    border-radius: 0.375rem;
    border: 1px solid var(--border-color, #e5e7eb);
}

.assignment-fee-edit-form .form-row {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 0.75rem;
}

.btn-xs {
    padding: 0.25rem 0.625rem;
    font-size: 0.75rem;
    line-height: 1.25;
    border-radius: 0.25rem;
}

.assignment-empty-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.subtask-summary-grid,
.milestone-summary-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-bottom: 1rem;
}

.add-subtask-form {
    padding: 1.25rem;
    background: #f8fbfd;
    border: 1px dashed #cbd5e1;
    border-radius: 18px;
    margin-bottom: 1rem;
}

.subtask-list,
.milestones-list {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}

.subtask-card,
.milestone-item {
    padding: 1rem 1.1rem;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
}

.subtask-title-row {
    flex: 1;
    flex-wrap: wrap;
    align-items: center;
}

.subtask-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0f6c8f;
    font-size: 0.8rem;
    font-weight: 800;
    flex-shrink: 0;
}

.subtask-title {
    flex: 1;
}

.subtask-actions {
    flex-shrink: 0;
}

.subtask-description {
    padding-left: 2.8rem;
}

.assigned-tech {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    font-size: 0.9rem;
    color: #0f172a;
}

.assigned-tech i {
    color: #0f6c8f;
}

.tech-spec {
    color: #64748b;
    font-size: 0.8rem;
}

.lead-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.55rem;
    border-radius: 999px;
    background: #dbeafe;
    color: #1e40af;
    font-weight: 700;
}

.subtask-technician {
    padding: 0.8rem 0.9rem;
    border-radius: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    margin-bottom: 0.7rem;
}

.progress-bar,
.budget-progress-bar {
    width: 100%;
    overflow: hidden;
    background: #e2e8f0;
}

.progress-bar {
    height: 0.58rem;
    border-radius: 999px;
}

.progress {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #0f6c8f, #38bdf8);
}

.budget-progress-bar {
    height: 0.6rem;
    border-radius: 999px;
}

.budget-progress-bar.large {
    margin-top: 1rem;
}

.budget-progress-fill {
    height: 100%;
    border-radius: inherit;
    transition: width 0.3s ease;
}

.progress-label {
    min-width: 2.5rem;
    text-align: right;
    font-weight: 700;
}

.empty-subtasks,
.empty-state,
.empty-budget {
    text-align: center;
    padding: 2rem 1rem;
    color: #64748b;
}

.admin-report-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.admin-report-card {
    padding: 1rem 1.1rem;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
}

.admin-report-top,
.admin-photo-overlay {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.admin-report-top p,
.admin-report-notes,
.admin-report-validation-note {
    margin: 0.4rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.admin-report-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.85rem;
    margin-top: 1rem;
}

.admin-report-notes,
.admin-report-validation-note {
    margin-top: 0.85rem;
}

.admin-report-validation-note {
    color: #0f6c8f;
    font-weight: 600;
}

.admin-report-client-notes {
    margin-top: 0.85rem;
    padding: 0.7rem 0.9rem;
    background: #ecfdf5;
    border-left: 3px solid #10b981;
    border-radius: 6px;
    color: #065f46;
    font-size: 0.9rem;
    line-height: 1.4;
}
.admin-report-client-notes i { margin-right: 0.35rem; color: #10b981; }
.admin-report-client-notes strong { margin-right: 0.25rem; }

/* Ops-only edit history for a report's notes. Collapsed by default so
   it doesn't clutter the report card; expands to show each version's
   before/after with editor + timestamp. Never shown to clients. */
.notes-history-panel {
    margin-top: 0.85rem;
    padding: 0.6rem 0.8rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.85rem;
}
.notes-history-panel > summary {
    cursor: pointer;
    color: #475569;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    list-style: none;
}
.notes-history-panel > summary::-webkit-details-marker { display: none; }
.notes-history-panel > summary::before {
    content: '▶';
    display: inline-block;
    font-size: 0.7rem;
    transition: transform 0.2s ease;
}
.notes-history-panel[open] > summary::before { transform: rotate(90deg); }

.notes-history-list {
    list-style: none;
    padding: 0;
    margin: 0.75rem 0 0;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}
.notes-history-item {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 0.6rem 0.7rem;
}
.notes-history-meta {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 0.5rem;
    color: #64748b;
    font-size: 0.8rem;
}
.notes-history-meta strong { color: #0f172a; font-weight: 700; }
.notes-history-diff {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}
.notes-history-cell {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    padding: 0.45rem 0.55rem;
}
.notes-history-cell.before {
    background: #fef2f2;
    border-color: #fecaca;
}
.notes-history-cell.after {
    background: #f0fdf4;
    border-color: #bbf7d0;
}
.notes-history-cell > span {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    margin-bottom: 0.25rem;
}
.notes-history-cell.before > span { color: #b91c1c; }
.notes-history-cell.after > span { color: #15803d; }
.notes-history-cell > p {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
    color: #0f172a;
    line-height: 1.4;
}
@media (max-width: 640px) {
    .notes-history-diff { grid-template-columns: 1fr; }
}

.admin-photo-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.85rem;
    margin-top: 1rem;
}

.admin-photo-card {
    overflow: hidden;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}

.admin-photo-card.removed {
    opacity: 0.62;
}

.admin-photo-card img {
    display: block;
    width: 100%;
    height: 170px;
    object-fit: cover;
}

.admin-photo-overlay {
    padding: 0.75rem;
}

.admin-photo-flag {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.55rem;
    border-radius: 999px;
    background: #fee2e2;
    color: #991b1b;
    font-size: 0.72rem;
    font-weight: 700;
}

.admin-validation-form {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 16px;
    background: #f8fbfd;
    border: 1px dashed #cbd5e1;
}

.admin-payout-row {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 16px;
    border: 1px solid #dcfce7;
    background: #f0fdf4;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.admin-payout-copy p {
    margin: 0.35rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.paid-progress-note {
    display: inline-flex;
    align-items: center;
    padding: 0.42rem 0.72rem;
    border-radius: 999px;
    background: #dcfce7;
    color: #166534;
    font-size: 0.78rem;
    font-weight: 700;
}

.empty-budget i {
    display: block;
    margin-bottom: 0.8rem;
    font-size: 2rem;
    color: #94a3b8;
}

.btn-xs {
    padding: 0.28rem 0.6rem;
    font-size: 0.75rem;
    border-radius: 999px;
}

.btn-danger {
    background: #dc2626;
    color: white;
    border: none;
    cursor: pointer;
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-success {
    background: #16a34a;
    color: white;
    border: none;
}

.budget-overview-card {
    background: linear-gradient(180deg, #eff6ff 0%, #ffffff 100%);
}

.budget-overview-top {
    flex-wrap: wrap;
}

.budget-overview-top > div {
    flex: 1 1 150px;
}

.budget-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 1rem;
}

.budget-category-card h4 {
    margin: 0 0 0.8rem;
    color: #0f172a;
    text-transform: capitalize;
}

.budget-amounts {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    margin-bottom: 0.85rem;
}

.budget-row {
    gap: 1rem;
}

.positive {
    color: #166534;
}

.negative {
    color: #991b1b !important;
}

.pricing-strip {
    flex-wrap: wrap;
    margin-top: 1rem;
}

.pricing-chip {
    flex: 1 1 200px;
}

.pricing-chip.success {
    background: #f0fdf4;
    border-color: #bbf7d0;
}

.milestone-item {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 1rem;
    align-items: center;
}

.milestone-progress {
    display: flex;
    align-items: center;
}

.progress-circle {
    width: 3.3rem;
    height: 3.3rem;
    border-radius: 999px;
    background: #f1f5f9;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.9rem;
    border: 3px solid #dbe4ee;
}

.progress-circle.reached {
    background: #ecfccb;
    color: #3f6212;
    border-color: #84cc16;
}

.milestone-details {
    min-width: 0;
}

.milestone-header h4 {
    margin: 0;
    color: #0f172a;
}

.milestone-finance-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.milestone-label {
    display: block;
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.milestone-amount {
    margin: 0.35rem 0 0;
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 800;
}

.milestone-amount.labor {
    color: #0f6c8f;
}

.milestone-amount.remaining {
    color: #92400e;
}

.milestone-amount.settled {
    color: #166534;
}

.milestone-allocation-list,
.milestone-allocation-editor-list {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    margin-top: 0.9rem;
}

.milestone-allocation-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 0.9rem;
    border-radius: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.milestone-allocation-row span {
    display: block;
    margin-top: 0.2rem;
    color: #64748b;
    font-size: 0.84rem;
}

.milestone-allocation-empty {
    margin: 0.9rem 0 0;
    color: #64748b;
    line-height: 1.5;
}

.milestone-allocation-editor {
    margin-top: 1.25rem;
    padding: 1rem;
    border-radius: 18px;
    background: #f8fbfd;
    border: 1px solid #dbe4ee;
}

.milestone-allocation-editor-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.85rem;
}

.milestone-allocation-editor-head h4 {
    margin: 0;
    color: #0f172a;
}

.milestone-allocation-editor-head p {
    margin: 0.3rem 0 0;
    color: #64748b;
    line-height: 1.5;
}

.milestone-allocation-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 0.85rem;
    margin-bottom: 0.85rem;
    color: #0f172a;
    font-size: 0.88rem;
    font-weight: 700;
}

.milestone-allocation-editor-row {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(140px, 0.7fr) minmax(0, 1fr) auto;
    gap: 0.65rem;
    align-items: start;
}

.alloc-tech-col {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.alloc-tech-budget {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.78rem;
    color: #64748b;
    padding: 0 0.1rem;
}

.alloc-sep { color: #cbd5e1; }

.alloc-tech-budget span:last-child { color: #0f766e; font-weight: 600; }

.job-sidebar-column {
    gap: 1rem;
}

.sidebar-detail-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.sidebar-progress-shell {
    margin-top: 0.35rem;
}

.rating-display {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    margin-top: 1rem;
}

.rating-stars {
    align-items: center;
}

.star {
    color: #cbd5e1;
}

.star.filled {
    color: #fbbf24;
}

.review-text {
    margin: 0;
    padding: 1rem;
    border-radius: 16px;
    background: #f8fafc;
    border-left: 4px solid #0f6c8f;
}

.assign-section {
    text-align: center;
    padding: 1rem 0 0.25rem;
}

.assign-section p {
    max-width: 42rem;
    margin: 0 auto 1.25rem;
    color: #64748b;
}

.assign-options {
    justify-content: center;
    flex-wrap: wrap;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.48);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 1rem;
}

.modal-content {
    width: min(100%, 560px);
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    border-radius: 24px;
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.24);
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2);
}

.modal-content.modal-lg {
    width: min(100%, 840px);
}

.modal-header,
.modal-footer {
    padding: 1.25rem 1.5rem;
}

.modal-header {
    border-bottom: 1px solid #e2e8f0;
}

.modal-body {
    padding: 1.25rem 1.5rem;
}

.modal-footer {
    border-top: 1px solid #e2e8f0;
    justify-content: flex-end;
    flex-wrap: wrap;
}

.close-btn {
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 1.6rem;
    cursor: pointer;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.45rem;
    color: #334155;
    font-size: 0.88rem;
    font-weight: 700;
}

.form-control,
.form-control-sm {
    width: 100%;
    box-sizing: border-box;
    padding: 0.82rem 0.9rem;
    border-radius: 14px;
    border: 1px solid #d7dee7;
    background: #f8fafc;
    color: #0f172a;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.form-control-sm {
    padding: 0.55rem 0.7rem;
}

.form-control:focus,
.form-control-sm:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.45);
    box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12);
    background: #ffffff;
}

.tech-picker-filters {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
    padding: 0.75rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
}

.tech-filter-input {
    flex: 1;
    min-width: 140px;
    padding: 0.55rem 0.75rem;
    border: 1px solid #d7dee7;
    border-radius: 10px;
    font-size: 0.88rem;
    background: #fff;
    color: #0f172a;
}

.tech-filter-input:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.5);
    box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.1);
}

.tech-filter-select {
    padding: 0.55rem 0.75rem;
    border: 1px solid #d7dee7;
    border-radius: 10px;
    font-size: 0.88rem;
    background: #fff;
    color: #0f172a;
    cursor: pointer;
}

.tech-filter-select:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.5);
}

.tech-filter-count {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 600;
    white-space: nowrap;
    margin-left: auto;
}

.technician-list {
    display: grid;
    gap: 0.9rem;
    max-height: 380px;
    overflow-y: auto;
    padding-right: 0.2rem;
}

.technician-item {
    align-items: flex-start;
    padding: 1rem;
    border: 1px solid #dbe4ee;
    border-radius: 18px;
    cursor: pointer;
    transition: border-color 0.2s ease, transform 0.2s ease, background-color 0.2s ease;
}

.technician-item:hover {
    border-color: #7dd3fc;
    background: #f8fbfd;
    transform: translateY(-1px);
}

.technician-item.selected {
    border-color: #38bdf8;
    background: #eff6ff;
}

.technician-item .technician-info h4 {
    margin: 0 0 0.45rem;
    color: #0f172a;
}

.technician-item .technician-info p {
    margin: 0.2rem 0;
    color: #64748b;
    font-size: 0.9rem;
}

.lead-notice {
    padding: 0.95rem 1rem;
    border-radius: 16px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1d4ed8;
}

.lead-notice i {
    margin-right: 0.35rem;
}

.assignment-budget-panel {
    margin-bottom: 1rem;
    padding: 1rem;
    border-radius: 18px;
    border: 1px solid #dbe4ee;
    background: linear-gradient(135deg, #f8fbff 0%, #f8fafc 100%);
}

.assignment-budget-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.85rem;
}

.assignment-budget-stat {
    padding: 0.85rem 0.9rem;
    border-radius: 14px;
    background: #ffffff;
    border: 1px solid #e2e8f0;
}

.assignment-budget-stat span {
    display: block;
    margin-bottom: 0.35rem;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
}

.assignment-budget-stat strong {
    color: #0f172a;
    font-size: 1rem;
}

.assignment-budget-hint,
.assignment-budget-warning,
.assignment-inline-error,
.assignment-field-help {
    display: block;
    margin-top: 0.8rem;
    font-size: 0.88rem;
}

.assignment-budget-hint,
.assignment-field-help {
    color: #475569;
}

.assignment-budget-warning,
.assignment-inline-error {
    color: #b45309;
}

.assignment-finance-row {
    align-items: flex-start;
}

.text-muted {
    font-style: italic;
}

@media (max-width: 1240px) {
    .job-details-hero,
    .job-layout-grid {
        grid-template-columns: 1fr;
    }

    .job-sidebar-column {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
    }

    .sidebar-card {
        position: static;
    }
}

@media (max-width: 980px) {
    .job-metrics-grid,
    .overview-grid,
    .budget-grid,
    .job-sidebar-column {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .milestone-item {
        grid-template-columns: 1fr;
        align-items: start;
    }

    .milestone-finance-grid,
    .milestone-allocation-editor-row {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 760px) {
    .job-metrics-grid,
    .overview-grid,
    .assignment-profile-grid,
    .assignment-budget-grid,
    .subtask-summary-grid,
    .budget-grid,
    .milestone-summary-grid,
    .milestone-finance-grid,
    .job-sidebar-column,
    .form-row,
    .hero-action-grid,
    .milestone-allocation-editor-row {
        grid-template-columns: 1fr;
    }

    .hero-topline,
    .job-card-header,
    .assignment-empty-card,
    .subtask-header,
    .subtask-technician,
    .budget-overview-top,
    .milestone-header,
    .modal-header,
    .modal-footer,
    .technician-item {
        flex-direction: column;
        align-items: flex-start;
    }

    .assignment-profile-top {
        grid-template-columns: 1fr;
    }

    .subtask-description {
        padding-left: 0;
    }

    .milestone-allocation-editor-head,
    .milestone-allocation-row,
    .milestone-allocation-summary {
        flex-direction: column;
        align-items: flex-start;
    }
}

.backfill-banner {
    margin: 0.75rem 0 1rem;
    padding: 0.85rem 1rem;
    border-radius: 8px;
    background: #fef3c7;
    border: 1px solid #fbbf24;
    color: #78350f;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.backfill-banner strong { display: block; color: #92400e; }
.backfill-banner .btn { white-space: nowrap; flex-shrink: 0; }
</style>
