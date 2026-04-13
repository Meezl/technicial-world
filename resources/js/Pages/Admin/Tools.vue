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
                <Link href="/admin/jobs" class="nav-item">
                    <i class="fas fa-tasks"></i><span>Jobs Monitoring</span>
                </Link>
                <Link href="/admin/tools" class="nav-item active">
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
                <h1>Tools Management</h1>
                <div class="header-actions">
                    <button @click="showCreateModal" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Tool to Inventory
                    </button>
                </div>
            </header>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Tool Inventory & Status</h3>
                        <div class="filter-controls">
                            <input
                                type="text"
                                v-model="searchQuery"
                                @input="filterTools"
                                placeholder="Search by Tool Name or S/N..."
                                style="margin-right: 1rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;"
                            >
                            <select v-model="statusFilter" @change="filterTools" class="btn btn-secondary">
                                <option value="">All Status</option>
                                <option value="available">Available</option>
                                <option value="issued">Issued</option>
                                <option value="maintenance">Maintenance</option>
                                <option value="damaged">Damaged</option>
                            </select>
                        </div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Tool Name / S/N</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Assigned To</th>
                                <th>Linked Project</th>
                                <th>Condition</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tool in filteredTools" :key="tool.id">
                                <td>
                                    {{ tool.name }}
                                    <span class="sub-text" v-if="tool.serial_number">S/N: {{ tool.serial_number }}</span>
                                </td>
                                <td>{{ tool.category }}</td>
                                <td>
                                    <span :class="['status', getStatusClass(tool.status)]">
                                        {{ formatStatus(tool.status) }}
                                    </span>
                                </td>
                                <td>
                                    <span v-if="tool.technician">
                                        {{ tool.technician.user.name }}
                                        <span class="sub-text">{{ tool.technician.technician_id }}</span>
                                    </span>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td>
                                    <span v-if="tool.service_request">
                                        {{ tool.service_request.request_id }}
                                    </span>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td>
                                    <span :class="['status', getConditionClass(tool.condition)]">
                                        {{ formatCondition(tool.condition) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button
                                            v-if="tool.status === 'available'"
                                            @click="showAssignModal(tool)"
                                            class="btn btn-primary btn-sm"
                                        >
                                            Issue Tool
                                        </button>
                                        <button
                                            v-if="tool.status === 'issued'"
                                            @click="returnTool(tool)"
                                            class="btn btn-secondary btn-sm"
                                        >
                                            Return
                                        </button>
                                        <button
                                            @click="editTool(tool)"
                                            class="btn btn-secondary btn-sm"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            @click="deleteTool(tool)"
                                            class="btn btn-secondary btn-sm"
                                            style="color: var(--red);"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredTools.length === 0">
                                <td colspan="7" style="text-align: center; padding: 2rem; color: var(--medium-grey);">
                                    No tools found matching your criteria
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <!-- Create/Edit Tool Modal -->
        <div v-if="showModal" class="modal-overlay" @click="closeModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ isEditing ? 'Edit' : 'Add New' }} Tool</h3>
                    <button @click="closeModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveTool">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Tool Name*</label>
                                <input
                                    type="text"
                                    v-model="form.name"
                                    required
                                    placeholder="e.g., Power Drill"
                                >
                            </div>
                            <div class="form-group">
                                <label>Serial Number</label>
                                <input
                                    type="text"
                                    v-model="form.serial_number"
                                    placeholder="e.g., DR-45B1"
                                >
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Tool Category*</label>
                                <select v-model="form.category" required>
                                    <option value="">Select category</option>
                                    <option value="Power Tool">Power Tool</option>
                                    <option value="Hand Tool">Hand Tool</option>
                                    <option value="Safety Equipment">Safety Equipment</option>
                                    <option value="Measurement Tool">Measurement Tool</option>
                                    <option value="Ladders & Scaffolding">Ladders & Scaffolding</option>
                                    <option value="Cutting Tools">Cutting Tools</option>
                                    <option value="Electrical Tools">Electrical Tools</option>
                                    <option value="Plumbing Tools">Plumbing Tools</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Condition*</label>
                                <select v-model="form.condition" required>
                                    <option value="new">New</option>
                                    <option value="good">Good</option>
                                    <option value="fair">Fair</option>
                                    <option value="needs_repair">Needs Repair</option>
                                    <option value="damaged">Damaged</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                placeholder="Tool description, specifications, purchase info, etc."
                            ></textarea>
                        </div>

                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea
                                v-model="form.notes"
                                rows="2"
                                placeholder="Additional notes about the tool"
                            ></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="closeModal" class="btn btn-secondary">Cancel</button>
                    <button @click="saveTool" class="btn btn-primary">
                        {{ isEditing ? 'Update' : 'Add' }} Tool
                    </button>
                </div>
            </div>
        </div>

        <!-- Assign Tool Modal -->
        <div v-if="showAssignToolModal" class="modal-overlay" @click="closeAssignModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Issue Tool: {{ selectedTool?.name }}</h3>
                    <button @click="closeAssignModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="tool-info">
                        <p><strong>Tool:</strong> {{ selectedTool?.name }}</p>
                        <p v-if="selectedTool?.serial_number"><strong>S/N:</strong> {{ selectedTool.serial_number }}</p>
                        <p><strong>Category:</strong> {{ selectedTool?.category }}</p>
                    </div>

                    <div class="form-group">
                        <label>Assign to Technician*</label>
                        <select v-model="assignForm.technician_id" required>
                            <option value="">Select technician</option>
                            <option
                                v-for="technician in availableTechnicians"
                                :key="technician.id"
                                :value="technician.id"
                            >
                                {{ technician.user.name }} ({{ technician.technician_id }})
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Link to Service Request (Optional)</label>
                        <select v-model="assignForm.service_request_id">
                            <option value="">No specific job</option>
                            <option
                                v-for="job in activeJobs"
                                :key="job.id"
                                :value="job.id"
                            >
                                {{ job.request_id }} - {{ job.user.name }}
                            </option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Expected Return Date</label>
                        <input
                            type="date"
                            v-model="assignForm.expected_return_date"
                        >
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea
                            v-model="assignForm.notes"
                            rows="2"
                            placeholder="Issue notes, special instructions, etc."
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="closeAssignModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="assignTool"
                        :disabled="!assignForm.technician_id"
                        class="btn btn-primary"
                    >
                        Issue Tool
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    tools: {
        type: Array,
        default: () => []
    },
    technicians: {
        type: Array,
        default: () => []
    },
    activeJobs: {
        type: Array,
        default: () => []
    }
})

const searchQuery = ref('')
const statusFilter = ref('')
const showModal = ref(false)
const showAssignToolModal = ref(false)
const isEditing = ref(false)
const selectedTool = ref(null)

const form = ref({
    name: '',
    serial_number: '',
    category: '',
    condition: 'good',
    description: '',
    notes: ''
})

const assignForm = ref({
    technician_id: '',
    service_request_id: '',
    expected_return_date: '',
    notes: ''
})

const filteredTools = computed(() => {
    let filtered = props.tools

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase()
        filtered = filtered.filter(tool =>
            tool.name.toLowerCase().includes(query) ||
            tool.serial_number?.toLowerCase().includes(query) ||
            tool.category.toLowerCase().includes(query)
        )
    }

    if (statusFilter.value) {
        filtered = filtered.filter(tool => tool.status === statusFilter.value)
    }

    return filtered
})

const availableTechnicians = computed(() => {
    return props.technicians.filter(tech => tech.availability === 'available')
})

const filterTools = () => {
    // Reactive computed property handles this automatically
}

const getStatusClass = (status) => {
    const statusMap = {
        'available': 'available',
        'issued': 'busy',
        'maintenance': 'review',
        'damaged': 'new'
    }
    return statusMap[status] || 'available'
}

const formatStatus = (status) => {
    const statusMap = {
        'available': 'Available',
        'issued': 'Issued',
        'maintenance': 'Maintenance',
        'damaged': 'Damaged'
    }
    return statusMap[status] || status
}

const getConditionClass = (condition) => {
    const conditionMap = {
        'new': 'available',
        'good': 'available',
        'fair': 'review',
        'needs_repair': 'review',
        'damaged': 'new'
    }
    return conditionMap[condition] || 'available'
}

const formatCondition = (condition) => {
    const conditionMap = {
        'new': 'New',
        'good': 'Good',
        'fair': 'Fair',
        'needs_repair': 'Needs Repair',
        'damaged': 'Damaged'
    }
    return conditionMap[condition] || condition
}

const showCreateModal = () => {
    isEditing.value = false
    resetForm()
    showModal.value = true
}

const editTool = (tool) => {
    isEditing.value = true
    form.value = {
        id: tool.id,
        name: tool.name,
        serial_number: tool.serial_number || '',
        category: tool.category,
        condition: tool.condition,
        description: tool.description || '',
        notes: tool.notes || ''
    }
    showModal.value = true
}

const deleteTool = (tool) => {
    if (confirm(`Are you sure you want to delete ${tool.name}? This action cannot be undone.`)) {
        router.delete(`/admin/tools/${tool.id}`)
    }
}

const showAssignModal = (tool) => {
    selectedTool.value = tool
    resetAssignForm()
    showAssignToolModal.value = true
}

const returnTool = (tool) => {
    if (confirm(`Return ${tool.name} to inventory?`)) {
        router.post(`/admin/tools/${tool.id}/return`)
    }
}

const saveTool = () => {
    const url = isEditing.value ? `/admin/tools/${form.value.id}` : '/admin/tools'
    const method = isEditing.value ? 'put' : 'post'

    router[method](url, form.value, {
        onSuccess: () => {
            closeModal()
        }
    })
}

const assignTool = () => {
    router.post(`/admin/tools/${selectedTool.value.id}/assign`, assignForm.value, {
        onSuccess: () => {
            closeAssignModal()
        }
    })
}

const resetForm = () => {
    form.value = {
        name: '',
        serial_number: '',
        category: '',
        condition: 'good',
        description: '',
        notes: ''
    }
}

const resetAssignForm = () => {
    assignForm.value = {
        technician_id: '',
        service_request_id: '',
        expected_return_date: '',
        notes: ''
    }
}

const closeModal = () => {
    showModal.value = false
    resetForm()
}

const closeAssignModal = () => {
    showAssignToolModal.value = false
    selectedTool.value = null
    resetAssignForm()
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

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.text-muted {
    color: var(--medium-grey);
    font-style: italic;
}

.tool-info {
    background: var(--light-grey);
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
}

.tool-info p {
    margin: 0.5rem 0;
    color: var(--dark-grey);
}
</style>