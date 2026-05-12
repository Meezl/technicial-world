<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="jobs" />

        <main class="main-content">
            <header class="main-header">
                <h1>Jobs Monitoring</h1>
                <div class="header-actions">
                    <select v-model="statusFilter" @change="filterJobs" class="btn btn-secondary">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="assigned">Assigned</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </header>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>All Service Requests & Jobs</h3>
                        <div class="filter-controls">
                            <input
                                type="text"
                                v-model="searchQuery"
                                @input="filterJobs"
                                placeholder="Search by Job ID or Client..."
                                style="margin-right: 1rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;"
                            >
                        </div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Job ID</th>
                                <th>Client Name</th>
                                <th>Service Category</th>
                                <th>Assigned Technician</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th>Urgency</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="job in jobs.data" :key="job.id">
                                <td>{{ job.request_id }}</td>
                                <td>
                                    {{ job.user.name }}
                                    <span class="sub-text">{{ job.user.email }}</span>
                                </td>
                                <td>{{ job.service_category?.name || 'N/A' }}</td>
                                <td>
                                    <template v-if="job.has_sub_tasks">
                                        <span v-if="job.lead_technician">
                                            {{ job.lead_technician.user.name }}
                                            <span class="lead-badge">Lead</span>
                                            <span class="sub-text">{{ getAssignedTechCount(job) }} technician(s)</span>
                                        </span>
                                        <span v-else class="text-muted">Not Assigned</span>
                                        <span class="sub-task-badge">{{ job.sub_tasks?.length || 0 }} sub-tasks</span>
                                    </template>
                                    <template v-else>
                                        <span v-if="job.technician">
                                            {{ job.technician.user.name }}
                                            <span class="sub-text">{{ job.technician.technician_id }}</span>
                                        </span>
                                        <span v-else class="text-muted">Not Assigned</span>
                                    </template>
                                </td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress" :style="`width: ${job.progress_percentage || 0}%;`"></div>
                                    </div>
                                    <span class="sub-text">{{ job.progress_percentage || 0 }}%</span>
                                </td>
                                <td>
                                    <span :class="['status', getStatusClass(job.status)]">
                                        {{ formatStatus(job.status) }}
                                    </span>
                                </td>
                                <td>
                                    <span :class="['urgency', job.urgency]">
                                        {{ formatUrgency(job.urgency) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button
                                            @click="viewJobDetails(job)"
                                            class="btn btn-secondary btn-sm"
                                            style="margin-right: 0.5rem;"
                                        >
                                            View Details
                                        </button>
                                        <template v-if="job.has_sub_tasks">
                                            <button
                                                @click="viewJobDetails(job)"
                                                class="btn btn-primary btn-sm"
                                            >
                                                Manage Sub-Tasks
                                            </button>
                                        </template>
                                        <template v-else>
                                            <button
                                                v-if="!job.technician && job.status === 'pending' && (!job.rfq_status || job.rfq_status === 'approved')"
                                                @click="showAssignModal(job)"
                                                class="btn btn-primary btn-sm"
                                            >
                                                Assign
                                            </button>
                                            <span
                                                v-if="!job.technician && job.status === 'pending' && job.rfq_status && job.rfq_status !== 'approved'"
                                                class="text-muted"
                                                style="font-size: 0.85rem;"
                                            >
                                                Awaiting RFQ Approval
                                            </span>
                                            <button
                                                v-if="job.technician && job.status !== 'completed'"
                                                @click="showReassignModal(job)"
                                                class="btn btn-secondary btn-sm"
                                            >
                                                Reassign
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="jobs.data.length === 0">
                                <td colspan="8" style="text-align: center; padding: 2rem; color: var(--medium-grey);">
                                    No jobs found matching your criteria
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="pagination-container" v-if="jobs.links.length > 3">
                        <div class="pagination">
                            <Component
                                :is="link.url ? Link : 'span'"
                                v-for="(link, index) in jobs.links"
                                :key="index"
                                :href="link.url"
                                v-html="link.label"
                                class="page-link"
                                :class="{ 'active': link.active, 'disabled': !link.url }"
                                preserve-scroll
                            />
                        </div>
                        <div class="pagination-info">
                            Showing {{ jobs.from }} to {{ jobs.to }} of {{ jobs.total }} results
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Assignment Modal -->
        <div v-if="showModal" class="modal-overlay" @click="closeModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ isReassigning ? 'Reassign' : 'Assign' }} Technician</h3>
                    <button @click="closeModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p><strong>Job:</strong> {{ selectedJob?.request_id }} - {{ selectedJob?.description }}</p>
                    <p><strong>Service Category:</strong> {{ selectedJob?.service_category?.name }}</p>

                    <div class="form-group">
                        <label>Available Technicians:</label>
                        <div class="technician-list">
                            <div
                                v-for="technician in availableTechnicians"
                                :key="technician.id"
                                :class="['technician-item', { selected: selectedTechnician?.id === technician.id }]"
                                @click="selectTechnician(technician)"
                            >
                                <div class="technician-info">
                                    <h4>{{ technician.user.name }}</h4>
                                    <p>{{ technician.specialization }} | {{ technician.location }}</p>
                                    <p><strong>Rating:</strong> {{ technician.rating }}/5</p>
                                    <p><strong>Skills:</strong> {{ technician.skills?.join(', ') || 'N/A' }}</p>
                                </div>
                                <div class="technician-status">
                                    <span :class="['status', technician.availability]">
                                        {{ formatAvailability(technician.availability) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="closeModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="assignTechnician"
                        :disabled="!selectedTechnician"
                        class="btn btn-primary"
                    >
                        {{ isReassigning ? 'Reassign' : 'Assign' }} Technician
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { Link } from '@inertiajs/vue3'
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    jobs: Object, // Changed from Array to Object (Pagination)
    technicians: {
        type: Array,
        default: () => []
    },
    filters: Object
})

const searchQuery = ref(props.filters?.search || '')
const statusFilter = ref(props.filters?.status || '')
const showModal = ref(false)
const selectedJob = ref(null)
const selectedTechnician = ref(null)
const isReassigning = ref(false)

// Watch for changes in search and status to trigger server-side filtering
import { watch } from 'vue'
import debounce from 'lodash/debounce'

watch(searchQuery, debounce((value) => {
    router.get('/admin/jobs', { search: value, status: statusFilter.value }, {
        preserveState: true,
        replace: true
    })
}, 300))

watch(statusFilter, (value) => {
    router.get('/admin/jobs', { search: searchQuery.value, status: value }, {
        preserveState: true,
        replace: true
    })
})

const availableTechnicians = computed(() => {
    if (!selectedJob.value?.service_category) return props.technicians

    return [...props.technicians].sort((a, b) => {
        // Prioritize available technicians
        const aAvailable = a.availability === 'available'
        const bAvailable = b.availability === 'available'
        if (aAvailable && !bAvailable) return -1
        if (!aAvailable && bAvailable) return 1

        // Then prioritize technicians with matching skills
        const aHasSkills = a.skills?.some(skill =>
            skill.toLowerCase().includes(selectedJob.value.service_category.name.toLowerCase()) ||
            selectedJob.value.service_category.name.toLowerCase().includes(skill.toLowerCase())
        )
        const bHasSkills = b.skills?.some(skill =>
            skill.toLowerCase().includes(selectedJob.value.service_category.name.toLowerCase()) ||
            selectedJob.value.service_category.name.toLowerCase().includes(skill.toLowerCase())
        )
        if (aHasSkills && !bHasSkills) return -1
        if (!aHasSkills && bHasSkills) return 1

        // Finally sort by rating
        return b.rating - a.rating
    })
})

const filterJobs = () => {
    // Handled by watchers now
}

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

const viewJobDetails = (job) => {
    router.visit(`/admin/jobs/${job.id}`)
}

const showAssignModal = (job) => {
    selectedJob.value = job
    isReassigning.value = false
    selectedTechnician.value = null
    showModal.value = true
}

const showReassignModal = (job) => {
    selectedJob.value = job
    isReassigning.value = true
    selectedTechnician.value = null
    showModal.value = true
}

const selectTechnician = (technician) => {
    selectedTechnician.value = technician
}

const assignTechnician = () => {
    if (!selectedTechnician.value || !selectedJob.value) return

    router.post(`/admin/jobs/${selectedJob.value.id}/assign`, {
        technician_id: selectedTechnician.value.id
    }, {
        onSuccess: () => {
            closeModal()
        }
    })
}

const getAssignedTechCount = (job) => {
    if (!job.sub_tasks) return 0
    const uniqueTechIds = new Set(
        job.sub_tasks
            .filter(st => st.technician_id)
            .map(st => st.technician_id)
    )
    return uniqueTechIds.size
}

const closeModal = () => {
    showModal.value = false
    selectedJob.value = null
    selectedTechnician.value = null
    isReassigning.value = false
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

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
    max-width: 800px;
    max-height: 90vh;
    overflow-y: auto;
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

.technician-info h4 {
    margin: 0 0 0.5rem 0;
    color: var(--dark-grey);
}

.technician-info p {
    margin: 0.25rem 0;
    font-size: 0.9rem;
    color: var(--medium-grey);
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.text-muted {
    color: var(--medium-grey);
    font-style: italic;
}

.lead-badge {
    display: inline-block;
    padding: 0.15rem 0.4rem;
    background: #DBEAFE;
    color: #1E40AF;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-left: 0.35rem;
    vertical-align: middle;
}

.sub-task-badge {
    display: block;
    padding: 0.15rem 0.4rem;
    background: #F3E8FF;
    color: #7C3AED;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
    margin-top: 0.25rem;
    width: fit-content;
}

.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-top: 1px solid var(--border-color);
}

.pagination {
    display: flex;
    gap: 0.5rem;
}

.page-link {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: white;
    color: var(--text-color);
    text-decoration: none;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
}

.page-link:hover:not(.disabled):not(.active) {
    background: #f8f9fa;
    border-color: var(--primary-blue);
    color: var(--primary-blue);
}

.page-link.active {
    background: var(--primary-blue);
    color: white;
    border-color: var(--primary-blue);
}

.page-link.disabled {
    color: var(--medium-grey);
    cursor: not-allowed;
    background: #f8f9fa;
}

.pagination-info {
    color: var(--medium-grey);
    font-size: 0.9rem;
}
</style>