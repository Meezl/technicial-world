<template>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2 class="logo">TECHNICIAN WORLD</h2>
            </div>
            <nav class="sidebar-nav">
                <Link href="/admin/dashboard" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </Link>
                <Link href="/admin/projects/dashboard" class="nav-item">
                    <i class="fas fa-project-diagram"></i><span>Project Management</span>
                </Link>
                <Link href="/admin/rfq" class="nav-item">
                    <i class="fas fa-file-alt"></i><span>RFQ Management</span>
                </Link>
                <Link href="/admin/technicians" class="nav-item">
                    <i class="fas fa-hard-hat"></i><span>Technicians</span>
                </Link>
                <Link href="/admin/jobs" class="nav-item active">
                    <i class="fas fa-tasks"></i><span>Jobs Monitoring</span>
                </Link>
                <Link href="/admin/tools" class="nav-item">
                    <i class="fas fa-tools"></i><span>Tools Management</span>
                </Link>
                <Link href="/admin/payments" class="nav-item">
                    <i class="fas fa-credit-card"></i><span>Payments</span>
                </Link>
            </nav>
            <div class="sidebar-footer">
                <Link href="/logout" class="nav-item" method="post">
                    <i class="fas fa-sign-out-alt"></i><span>Log Out</span>
                </Link>
            </div>
        </aside>

        <main class="main-content">
            <header class="main-header">
                <h1>Job Details - {{ job.request_id }}</h1>
                <div class="header-actions">
                    <Link href="/admin/jobs" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Jobs
                    </Link>
                </div>
            </header>

            <section class="main-panel">
                <!-- Service Request Information -->
                <div class="panel-card">
                    <div class="card-header">
                        <h3>Service Request Information</h3>
                        <span :class="['status', getStatusClass(job.status)]">
                            {{ formatStatus(job.status) }}
                        </span>
                    </div>

                    <div class="request-details-grid">
                        <div class="detail-item">
                            <h4><i class="fas fa-user"></i> Client Information</h4>
                            <p>{{ job.user.name }}</p>
                            <span class="sub-text">{{ job.user.email }}</span>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-tools"></i> Service Category</h4>
                            <p>{{ job.service_category?.name || 'N/A' }}</p>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-map-marker-alt"></i> Location</h4>
                            <p>{{ job.location || 'Not specified' }}</p>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-exclamation-triangle"></i> Urgency</h4>
                            <p>{{ formatUrgency(job.urgency) }}</p>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-calendar"></i> Created Date</h4>
                            <p>{{ formatDate(job.created_at) }}</p>
                        </div>

                        <div class="detail-item" v-if="job.scheduled_date">
                            <h4><i class="fas fa-clock"></i> Scheduled Date</h4>
                            <p>{{ formatDate(job.scheduled_date) }}</p>
                        </div>
                    </div>

                    <div class="detail-section" v-if="job.description">
                        <h4><i class="fas fa-file-text"></i> Description</h4>
                        <p class="description-text">{{ job.description }}</p>
                    </div>

                    <!-- Lead Technician (for sub-task SRs) -->
                    <div class="detail-section" v-if="job.has_sub_tasks && job.lead_technician">
                        <h4><i class="fas fa-hard-hat"></i> Lead Technician (Main Focal Point)</h4>
                        <div class="technician-card">
                            <div class="technician-info">
                                <h3>{{ job.lead_technician.user.name }} <span class="lead-badge">Lead</span></h3>
                                <p>{{ job.lead_technician.specialization }} | {{ job.lead_technician.location }}</p>
                                <p><strong>Rating:</strong> {{ job.lead_technician.rating }}/5</p>
                                <p><strong>Contact:</strong> {{ job.lead_technician.user.email }}</p>
                            </div>
                            <div class="technician-status">
                                <span :class="['status', job.lead_technician.availability]">
                                    {{ formatAvailability(job.lead_technician.availability) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Single Assigned Technician (for non-sub-task SRs) -->
                    <div class="detail-section" v-if="!job.has_sub_tasks && job.technician">
                        <h4><i class="fas fa-hard-hat"></i> Assigned Technician</h4>
                        <div class="technician-card">
                            <div class="technician-info">
                                <h3>{{ job.technician.user.name }}</h3>
                                <p>{{ job.technician.specialization }} | {{ job.technician.location }}</p>
                                <p><strong>Rating:</strong> {{ job.technician.rating }}/5</p>
                                <p><strong>Contact:</strong> {{ job.technician.user.email }}</p>
                            </div>
                            <div class="technician-status">
                                <span :class="['status', job.technician.availability]">
                                    {{ formatAvailability(job.technician.availability) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="detail-section" v-if="job.progress_percentage !== null">
                        <h4><i class="fas fa-chart-line"></i> Overall Progress</h4>
                        <div class="progress-container">
                            <div class="progress-bar">
                                <div class="progress" :style="`width: ${job.progress_percentage}%;`"></div>
                            </div>
                            <span class="progress-text">{{ job.progress_percentage }}% Complete</span>
                        </div>
                    </div>

                    <div class="detail-section" v-if="job.quoted_amount || job.final_amount">
                        <h4><i class="fas fa-dollar-sign"></i> Pricing</h4>
                        <div class="pricing-info">
                            <div v-if="job.quoted_amount" class="price-item">
                                <span>Quoted Amount:</span>
                                <span class="price">${{ job.quoted_amount }}</span>
                            </div>
                            <div v-if="job.final_amount" class="price-item">
                                <span>Final Amount:</span>
                                <span class="price final">${{ job.final_amount }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="detail-section" v-if="job.completion_notes">
                        <h4><i class="fas fa-sticky-note"></i> Completion Notes</h4>
                        <p class="description-text">{{ job.completion_notes }}</p>
                    </div>

                    <div class="detail-section" v-if="job.rating">
                        <h4><i class="fas fa-star"></i> Client Rating & Review</h4>
                        <div class="rating-display">
                            <div class="rating-stars">
                                <span v-for="i in 5" :key="i" :class="['star', { filled: i <= job.rating }]">
                                    <i class="fas fa-star"></i>
                                </span>
                                <span class="rating-text">{{ job.rating }}/5</span>
                            </div>
                            <p v-if="job.review" class="review-text">{{ job.review }}</p>
                        </div>
                    </div>
                </div>

                <!-- Sub-Tasks Section -->
                <div class="panel-card" v-if="job.has_sub_tasks || canAddSubTasks">
                    <div class="card-header">
                        <h3><i class="fas fa-list-check"></i> Sub-Tasks</h3>
                        <div class="header-actions-row">
                            <span v-if="job.sub_tasks?.length" class="sub-task-count">
                                {{ completedSubTasks }}/{{ job.sub_tasks.length }} completed
                            </span>
                            <button
                                v-if="canAddSubTasks"
                                @click="showAddSubTaskForm = !showAddSubTaskForm"
                                class="btn btn-primary btn-sm"
                            >
                                <i class="fas fa-plus"></i> Add Sub-Task
                            </button>
                        </div>
                    </div>

                    <!-- Add Sub-Task Form -->
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

                    <!-- Sub-Tasks List -->
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
                                            style="flex: 1;"
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

                            <!-- Technician Assignment -->
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

                            <!-- Progress Bar -->
                            <div class="subtask-progress">
                                <div class="progress-bar">
                                    <div class="progress" :style="`width: ${subTask.progress_percentage}%;`"></div>
                                </div>
                                <span class="progress-label">{{ subTask.progress_percentage }}%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-else-if="!showAddSubTaskForm" class="empty-subtasks">
                        <p>No sub-tasks added yet. Click "Add Sub-Task" to break this service request into multiple tasks.</p>
                    </div>
                </div>

                <!-- Budget & Payments Panel -->
                <div class="panel-card">
                    <div class="card-header">
                        <h3><i class="fas fa-calculator"></i> Budget & Payments</h3>
                        <div class="header-actions-row">
                            <Link :href="`/admin/payments?service_request_id=${job.id}`" class="btn btn-secondary btn-sm">
                                <i class="fas fa-external-link-alt"></i> Full Payments
                            </Link>
                            <button @click="openBudgetModal" class="btn btn-primary btn-sm">
                                <i :class="budgetSummary ? 'fas fa-edit' : 'fas fa-plus'"></i>
                                {{ budgetSummary ? 'Edit Budget' : 'Set Budget' }}
                            </button>
                        </div>
                    </div>

                    <div v-if="budgetSummary" class="budget-grid">
                        <div v-for="cat in ['labor', 'materials', 'other']" :key="cat" class="budget-category-card">
                            <h4 style="text-transform: capitalize; margin: 0 0 0.5rem 0;">{{ cat }}</h4>
                            <div class="budget-amounts">
                                <div class="budget-row">
                                    <span>Budgeted:</span>
                                    <strong>KSH {{ formatCurrency(budgetSummary[cat].budgeted) }}</strong>
                                </div>
                                <div class="budget-row">
                                    <span>Spent:</span>
                                    <strong>KSH {{ formatCurrency(budgetSummary[cat].actual) }}</strong>
                                </div>
                                <div class="budget-row">
                                    <span>Remaining:</span>
                                    <strong :style="{ color: budgetSummary[cat].remaining < 0 ? '#e74c3c' : '#27ae60' }">
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
                        </div>
                    </div>

                    <div v-if="budgetSummary" class="budget-total-row">
                        <strong>Total Budget:</strong>
                        <span>KSH {{ formatCurrency(budgetSummary.total.budgeted) }}</span>
                        <strong>Spent:</strong>
                        <span>KSH {{ formatCurrency(budgetSummary.total.actual) }}</span>
                        <strong>Remaining:</strong>
                        <span :style="{ color: budgetSummary.total.remaining < 0 ? '#e74c3c' : '#27ae60' }">
                            KSH {{ formatCurrency(budgetSummary.total.remaining) }}
                        </span>
                    </div>

                    <!-- Client payment summary -->
                    <div v-if="job.quote_amount" class="budget-client-summary">
                        <div class="budget-row">
                            <span><i class="fas fa-file-invoice-dollar"></i> Quoted Amount:</span>
                            <strong>KSH {{ formatCurrency(job.quote_amount) }}</strong>
                        </div>
                    </div>

                    <div v-if="!budgetSummary" class="empty-budget">
                        <i class="fas fa-calculator"></i>
                        <p>No budget set for this service request.</p>
                    </div>
                </div>

                <!-- Payment Milestones Panel -->
                <div class="panel-card" v-if="job.budget">
                    <div class="card-header">
                        <h3><i class="fas fa-flag-checkered"></i> Payment Milestones</h3>
                        <button @click="openAddMilestoneModal" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Milestone
                        </button>
                    </div>

                    <div v-if="job.milestones && job.milestones.length > 0" class="milestones-list">
                        <div v-for="milestone in job.milestones" :key="milestone.id" class="milestone-item">
                            <div class="milestone-progress">
                                <div class="progress-circle" :class="{ reached: milestone.status !== 'pending' }">
                                    {{ milestone.progress_step }}%
                                </div>
                                <div class="progress-line"></div>
                            </div>
                            <div class="milestone-details">
                                <div class="milestone-header">
                                    <h4>{{ milestone.progress_step }}% Completion</h4>
                                    <span :class="['status-badge', milestone.status]">
                                        {{ milestone.status === 'reached' ? 'Due' : milestone.status }}
                                    </span>
                                </div>
                                <p class="milestone-amount">KSH {{ formatCurrency(milestone.amount) }}</p>
                                <p v-if="milestone.notes" class="milestone-notes">{{ milestone.notes }}</p>
                            </div>
                            <div class="milestone-actions">
                                <button
                                    v-if="milestone.status === 'reached'"
                                    @click="markMilestonePaid(milestone)"
                                    class="btn btn-success btn-xs"
                                    title="Mark as Paid"
                                >
                                    <i class="fas fa-check-double"></i> Pay
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
                        <p>No payment milestones set. Add milestones to schedule payments based on progress.</p>
                    </div>
                </div>

                <!-- Single-Task Assignment (when no sub-tasks) -->
                <div class="panel-card" v-if="!job.has_sub_tasks && !job.technician && job.status === 'pending'">
                    <div class="card-header">
                        <h3>Assign Technician</h3>
                    </div>
                    <div class="assign-section">
                        <p>This job has not been assigned to a technician yet. You can assign a single technician or add sub-tasks for multiple technicians.</p>
                        <div class="assign-options">
                            <button @click="showSingleAssignModal = true" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Assign Single Technician
                            </button>
                            <button @click="showAddSubTaskForm = true" class="btn btn-secondary">
                                <i class="fas fa-list-check"></i> Add Sub-Tasks Instead
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Single Technician Assignment Modal -->
        <div v-if="showSingleAssignModal" class="modal-overlay" @click="closeSingleAssignModal">
            <div class="modal-content modal-lg" @click.stop>
                <div class="modal-header">
                    <h3>Assign Technician</h3>
                    <button @click="closeSingleAssignModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p><strong>Job:</strong> {{ job.request_id }} - {{ job.description }}</p>
                    <p><strong>Service Category:</strong> {{ job.service_category?.name }}</p>

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
                </div>
                <div class="modal-footer">
                    <button @click="closeSingleAssignModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="assignSingleTechnician"
                        :disabled="!selectedTechnician"
                        class="btn btn-primary"
                    >
                        Assign Technician
                    </button>
                </div>
            </div>
        </div>

        <!-- Budget Modal -->
        <div v-if="showBudgetModal" class="modal-overlay" @click="showBudgetModal = false">
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

        <!-- Milestone Modal -->
        <div v-if="showMilestoneModal" class="modal-overlay" @click="showMilestoneModal = false">
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
                            <label>Amount (KSH)</label>
                            <input type="number" v-model="milestoneForm.amount" step="0.01" min="0" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea v-model="milestoneForm.notes" rows="2" class="form-control"></textarea>
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
        <div v-if="showSubTaskAssignModal" class="modal-overlay" @click="closeSubTaskAssignModal">
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
                </div>
                <div class="modal-footer">
                    <button @click="closeSubTaskAssignModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="assignSubTaskTechnician"
                        :disabled="!selectedTechnician"
                        class="btn btn-primary"
                    >
                        Assign Technician
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
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
const showAddSubTaskForm = ref(false)
const selectedTechnician = ref(null)
const assigningSubTask = ref(null)
const editingSubTask = ref(null)

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
const budgetForm = reactive({
    labor_budget: 0,
    materials_budget: 0,
    other_budget: 0,
    notes: ''
})

// Computed
const canAddSubTasks = computed(() => {
    return ['pending', 'assigned'].includes(props.job.status) && props.job.status !== 'completed'
})

const completedSubTasks = computed(() => {
    return props.job.sub_tasks?.filter(st => st.status === 'completed').length || 0
})

const sortedTechnicians = computed(() => {
    return [...props.technicians].sort((a, b) => {
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

// Sub-task methods
const addSubTask = () => {
    if (!newSubTask.title) return

    router.post(`/admin/jobs/${props.job.id}/sub-tasks`, {
        title: newSubTask.title,
        description: newSubTask.description
    }, {
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
const showAssignModalFor = (subTask) => {
    assigningSubTask.value = subTask
    selectedTechnician.value = null
    showSubTaskAssignModal.value = true
}

const assignSubTaskTechnician = () => {
    if (!selectedTechnician.value || !assigningSubTask.value) return

    router.post(`/admin/sub-tasks/${assigningSubTask.value.id}/assign`, {
        technician_id: selectedTechnician.value.id
    }, {
        onSuccess: () => {
            closeSubTaskAssignModal()
        }
    })
}

const closeSubTaskAssignModal = () => {
    showSubTaskAssignModal.value = false
    assigningSubTask.value = null
    selectedTechnician.value = null
}

const assignSingleTechnician = () => {
    if (!selectedTechnician.value) return

    router.post(`/admin/jobs/${props.job.id}/assign`, {
        technician_id: selectedTechnician.value.id
    }, {
        onSuccess: () => {
            closeSingleAssignModal()
        }
    })
}

const closeSingleAssignModal = () => {
    showSingleAssignModal.value = false
    selectedTechnician.value = null
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
    amount: 0,
    notes: ''
})

const openAddMilestoneModal = () => {
    editingMilestone.value = null
    milestoneForm.progress_step = 50
    milestoneForm.amount = 0
    milestoneForm.notes = ''
    showMilestoneModal.value = true
}

const editMilestone = (milestone) => {
    editingMilestone.value = milestone
    milestoneForm.progress_step = milestone.progress_step
    milestoneForm.amount = milestone.amount
    milestoneForm.notes = milestone.notes || ''
    showMilestoneModal.value = true
}

const saveMilestone = () => {
    if (editingMilestone.value) {
        router.put(`/admin/milestones/${editingMilestone.value.id}`, { ...milestoneForm }, {
            onSuccess: () => { showMilestoneModal.value = false }
        })
    } else {
        router.post(`/admin/jobs/${props.job.id}/milestones`, { ...milestoneForm }, {
            onSuccess: () => { showMilestoneModal.value = false }
        })
    }
}

const markMilestonePaid = (milestone) => {
    if (!confirm(`Mark milestone for ${milestone.progress_step}% as PAID?`)) return
    router.put(`/admin/milestones/${milestone.id}`, {
        status: 'paid',
        progress_step: milestone.progress_step,
        amount: milestone.amount 
    })
}

const deleteMilestone = (milestone) => {
    if (!confirm('Are you sure you want to delete this milestone?')) return
    router.delete(`/admin/milestones/${milestone.id}`)
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
        'assigned': 'approved',
        'in_progress': 'review',
        'completed': 'available',
        'cancelled': 'leave'
    }
    return statusMap[status] || 'new'
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
        'pending': 'Pending Assignment',
        'assigned': 'Assigned',
        'in_progress': 'In Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled'
    }
    return statusMap[status] || status
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

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

.technician-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    background: var(--light-grey);
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.technician-info h3 {
    margin: 0 0 0.5rem 0;
    color: var(--dark-grey);
}

.technician-info p {
    margin: 0.25rem 0;
    color: var(--medium-grey);
}

.progress-container {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.progress-container .progress-bar {
    flex: 1;
}

.progress-text {
    font-weight: 600;
    color: var(--dark-grey);
    min-width: 80px;
}

.pricing-info {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.price-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
}

.price {
    font-weight: 600;
    color: var(--primary-blue);
}

.price.final {
    color: var(--green);
    font-size: 1.1em;
}

.rating-display {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.rating-stars {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.star {
    color: var(--border-color);
}

.star.filled {
    color: #FCD34D;
}

.rating-text {
    font-weight: 600;
    color: var(--dark-grey);
}

.review-text {
    margin: 0;
    padding: 1rem;
    background: var(--light-grey);
    border-radius: 6px;
    border-left: 4px solid var(--primary-blue);
    line-height: 1.6;
}

.assign-section {
    text-align: center;
    padding: 2rem;
}

.assign-section p {
    margin-bottom: 1.5rem;
    color: var(--medium-grey);
}

.assign-options {
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
}

/* Sub-Task Styles */
.header-actions-row {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.sub-task-count {
    font-size: 0.85rem;
    color: var(--medium-grey);
    font-weight: 500;
}

.add-subtask-form {
    padding: 1.5rem;
    background: #F8FAFC;
    border: 1px dashed var(--border-color);
    border-radius: 8px;
    margin: 1rem 1.5rem;
}

.add-subtask-form .form-group {
    margin-bottom: 1rem;
}

.add-subtask-form label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.35rem;
    color: var(--dark-grey);
    font-size: 0.9rem;
}

.add-subtask-form .form-control {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.9rem;
}

.add-subtask-form .form-actions {
    display: flex;
    gap: 0.5rem;
}

.subtask-list {
    padding: 0 1.5rem 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.subtask-card {
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1rem 1.25rem;
    background: white;
    transition: border-color 0.2s;
}

.subtask-card:hover {
    border-color: var(--primary-blue);
}

.subtask-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.5rem;
}

.subtask-title-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
}

.subtask-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    background: var(--light-grey);
    border-radius: 50%;
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--medium-grey);
    flex-shrink: 0;
}

.subtask-title {
    margin: 0;
    font-size: 0.95rem;
    color: var(--dark-grey);
    flex: 1;
}

.subtask-actions {
    display: flex;
    gap: 0.35rem;
    flex-shrink: 0;
    margin-left: 0.5rem;
}

.subtask-description {
    margin: 0 0 0.75rem 0;
    font-size: 0.85rem;
    color: var(--medium-grey);
    padding-left: 2rem;
}

.subtask-technician {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0.75rem;
    background: var(--light-grey);
    border-radius: 6px;
    margin-bottom: 0.5rem;
}

.assigned-tech {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.assigned-tech i {
    color: var(--primary-blue);
}

.tech-spec {
    color: var(--medium-grey);
    font-size: 0.8rem;
}

.lead-badge {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    background: #DBEAFE;
    color: #1E40AF;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
}

.unassigned {
    font-size: 0.9rem;
}

.subtask-progress {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.subtask-progress .progress-bar {
    flex: 1;
    height: 6px;
}

.progress-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--medium-grey);
    min-width: 35px;
    text-align: right;
}

.empty-subtasks {
    padding: 2rem;
    text-align: center;
    color: var(--medium-grey);
}

.empty-subtasks p {
    margin: 0;
}

.btn-xs {
    padding: 0.2rem 0.5rem;
    font-size: 0.75rem;
    border-radius: 4px;
}

.btn-danger {
    background: #DC2626;
    color: white;
    border: none;
    cursor: pointer;
}

.btn-danger:hover {
    background: #B91C1C;
}

.lead-notice {
    background: #DBEAFE;
    border: 1px solid #93C5FD;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    color: #1E40AF;
    font-size: 0.9rem;
    margin: 0.5rem 0 1rem;
}

.lead-notice i {
    margin-right: 0.35rem;
}

.form-control-sm {
    padding: 0.35rem 0.5rem;
    font-size: 0.85rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    width: 100%;
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-content.modal-lg {
    max-width: 800px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--medium-grey);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.technician-list {
    display: grid;
    gap: 1rem;
    max-height: 400px;
    overflow-y: auto;
}

.technician-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s;
}

.technician-item:hover {
    border-color: var(--primary-blue);
    background-color: #f8f9fa;
}

.technician-item.selected {
    border-color: var(--primary-blue);
    background-color: #EFF6FF;
}

.technician-item .technician-info h4 {
    margin: 0 0 0.5rem 0;
    color: var(--dark-grey);
}

.technician-item .technician-info p {
    margin: 0.25rem 0;
    font-size: 0.9rem;
    color: var(--medium-grey);
}

.text-muted {
    color: var(--medium-grey);
    font-style: italic;
}

/* Budget Panel Styles */
.budget-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    padding: 1.5rem;
}

.budget-category-card {
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.budget-amounts {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin-bottom: 0.5rem;
}

.budget-row {
    display: flex;
    justify-content: space-between;
    font-size: 0.85rem;
    color: var(--medium-grey);
}

.budget-row strong {
    color: var(--dark-grey);
}

.budget-progress-bar {
    width: 100%;
    height: 6px;
    background: #e9ecef;
    border-radius: 3px;
    overflow: hidden;
    margin-bottom: 0.25rem;
}

.budget-progress-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 0.3s;
}

.budget-pct {
    font-size: 0.75rem;
    color: var(--medium-grey);
}

.budget-total-row {
    display: flex;
    gap: 1rem;
    align-items: center;
    flex-wrap: wrap;
    padding: 1rem 1.5rem;
    background: #f0f4ff;
    border-top: 1px solid var(--border-color);
    font-size: 0.9rem;
}

.budget-client-summary {
    padding: 0.75rem 1.5rem;
    border-top: 1px solid var(--border-color);
}

.budget-client-summary .budget-row {
    font-size: 0.9rem;
}

.empty-budget {
    padding: 2rem;
    text-align: center;
    color: var(--medium-grey);
}

.empty-budget i {
    font-size: 2rem;
    margin-bottom: 0.5rem;
    opacity: 0.4;
    display: block;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.35rem;
    color: var(--dark-grey);
    font-size: 0.9rem;
}

@media (max-width: 768px) {
    .budget-grid {
        grid-template-columns: 1fr;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .budget-total-row {
        flex-direction: column;
        align-items: flex-start;
    }
}

/* Payment Milestones Styles */
.milestones-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    padding: 1rem;
}

.milestone-item {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: #fff;
}

.milestone-progress {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 60px;
}

.progress-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: #f1f2f6;
    color: var(--medium-grey);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.9rem;
    border: 3px solid #dfe4ea;
}

.progress-circle.reached {
    background: #e8f5e9;
    color: #27ae60;
    border-color: #27ae60;
}

.milestone-details {
    flex: 1;
}

.milestone-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.25rem;
}

.milestone-header h4 {
    margin: 0;
    font-size: 1rem;
    color: var(--dark-grey);
}

.milestone-amount {
    font-size: 1.1rem;
    font-weight: bold;
    color: var(--dark-grey);
    margin: 0;
}

.milestone-notes {
    font-size: 0.9rem;
    color: var(--medium-grey);
    margin: 0.25rem 0 0 0;
    font-style: italic;
}

.status-badge.pending { background: #f1f2f6; color: #747d8c; }
.status-badge.reached { background: #fff3cd; color: #856404; } /* Due */
.status-badge.paid { background: #d4edda; color: #155724; }

.milestone-actions {
    display: flex;
    gap: 0.5rem;
}
</style>
