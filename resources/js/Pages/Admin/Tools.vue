<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="tools" />

        <main class="main-content">
            <header class="main-header">
                <h1>Tools Management</h1>
                <div class="header-actions">
                    <button @click="showCreateModal" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New Tool to Inventory
                    </button>
                </div>
            </header>

            <!-- Pending tool requests from technicians -->
            <section v-if="toolRequests.length > 0" class="main-panel">
                <div class="panel-card full-width tool-requests-panel">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-hand-holding"></i>
                            Pending Tool Requests
                            <span class="badge-pending">{{ toolRequests.length }}</span>
                        </h3>
                    </div>
                    <div class="tool-request-list">
                        <div v-for="req in toolRequests" :key="`treq-${req.id}`" class="tool-request-row">
                            <div class="tr-main">
                                <strong>{{ req.tool?.name || req.tool_name_requested }}</strong>
                                <span class="tr-sub">
                                    Requested by {{ req.technician?.user?.name || 'Technician' }}
                                    · Qty {{ req.quantity }}
                                    · <span :class="['urgency-tag', `urgency-tag-${req.urgency}`]">{{ formatUrgency(req.urgency) }}</span>
                                </span>
                                <span class="tr-sub">
                                    <template v-if="req.service_request">
                                        Job {{ req.service_request.job_reference || req.service_request.request_id }} ·
                                    </template>
                                    {{ formatDateShort(req.created_at) }}
                                </span>
                                <p v-if="req.notes" class="tr-notes">"{{ req.notes }}"</p>
                            </div>
                            <div class="tr-actions">
                                <button class="btn btn-success btn-sm" @click="approveToolRequest(req)">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn btn-danger btn-sm" @click="rejectToolRequest(req)">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

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
                                    <template v-if="isStock(tool)">
                                        <span class="status available">{{ tool.quantity_available }} in stock</span>
                                        <span class="sub-text" v-if="tool.quantity_issued">{{ tool.quantity_issued }} issued out</span>
                                    </template>
                                    <span v-else :class="['status', getStatusClass(tool.status)]">
                                        {{ formatStatus(tool.status) }}
                                    </span>
                                </td>
                                <td>
                                    <span v-if="isStock(tool)" class="text-muted" title="See the PPE issuance ledger below">
                                        {{ tool.quantity_issued ? 'Multiple — see ledger' : '-' }}
                                    </span>
                                    <span v-else-if="tool.technician">
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
                                            v-if="isStock(tool) ? tool.quantity_available > 0 : tool.status === 'available'"
                                            @click="showAssignModal(tool)"
                                            class="btn btn-primary btn-sm"
                                            :disabled="['damaged','needs_repair'].includes(tool.condition)"
                                            :title="['damaged','needs_repair'].includes(tool.condition) ? 'Item is ' + tool.condition.replace('_',' ') + ' — repair before issuing' : ''"
                                        >
                                            Issue
                                        </button>
                                        <button
                                            v-if="!isStock(tool) && tool.status === 'issued'"
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

            <!-- PPE issuance ledger: stock hand-outs still carrying an
                 outstanding quantity, with a way to record returns. -->
            <section v-if="stockIssuances.length > 0" class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>PPE Currently Issued</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Technician</th>
                                <th>Job</th>
                                <th>Issued</th>
                                <th>Still Out</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="iss in stockIssuances" :key="`iss-${iss.id}`" :class="{ 'row-pending-return': iss.return_pending_quantity > 0 }">
                                <td>{{ iss.tool?.name }}</td>
                                <td>{{ iss.technician?.user?.name || '-' }}</td>
                                <td>
                                    <span v-if="iss.service_request">{{ iss.service_request.job_reference || iss.service_request.request_id }}</span>
                                    <span v-else class="text-muted">-</span>
                                </td>
                                <td>{{ iss.quantity }} on {{ formatDateShort(iss.issued_at) }}</td>
                                <td>
                                    <strong>{{ iss.quantity_outstanding }}</strong>
                                    <span v-if="iss.return_pending_quantity > 0" class="pending-return-tag">
                                        {{ iss.return_pending_quantity }} return pending
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <template v-if="iss.return_pending_quantity > 0">
                                            <button class="btn btn-success btn-sm" @click="confirmStockReturn(iss)">
                                                Confirm Return
                                            </button>
                                            <button class="btn btn-secondary btn-sm" style="color: var(--red);" @click="rejectStockReturn(iss)">
                                                Reject
                                            </button>
                                        </template>
                                        <button v-else class="btn btn-secondary btn-sm" @click="returnStockIssuance(iss)">
                                            Record Return
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <!-- Create/Edit Tool Modal -->
        <div v-if="showModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ isEditing ? 'Edit' : 'Add New' }} Tool</h3>
                    <button @click="closeModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveTool">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Name*</label>
                                <input
                                    type="text"
                                    v-model="form.name"
                                    required
                                    placeholder="e.g., Power Drill or Safety Helmet"
                                >
                            </div>
                            <div class="form-group">
                                <label>Type*</label>
                                <select v-model="form.tracking_type" :disabled="isEditing">
                                    <option value="serialized">Serialized tool (one unit)</option>
                                    <option value="stock">Stock item / PPE (quantity)</option>
                                </select>
                                <small class="field-hint" v-if="!isEditing">
                                    Stock items (helmets, reflectors) are counted and issued in quantities.
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <!-- Serialized: one physical unit, optional serial. -->
                            <div class="form-group" v-if="form.tracking_type === 'serialized'">
                                <label>Serial Number</label>
                                <input
                                    type="text"
                                    v-model="form.serial_number"
                                    placeholder="e.g., DR-45B1"
                                >
                            </div>
                            <!-- Stock: opening quantity in inventory. -->
                            <div class="form-group" v-else>
                                <label>{{ isEditing ? 'Quantity in stock' : 'Opening stock quantity*' }}</label>
                                <input
                                    type="number"
                                    min="0"
                                    v-model.number="form.quantity_available"
                                    :required="form.tracking_type === 'stock' && !isEditing"
                                    placeholder="e.g., 50"
                                >
                                <small class="field-hint" v-if="isEditing">
                                    This is the shelf count. Issued items are tracked separately.
                                </small>
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
        <div v-if="showAssignToolModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Issue Tool: {{ selectedTool?.name }}</h3>
                    <button @click="closeAssignModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="tool-info">
                        <p><strong>Item:</strong> {{ selectedTool?.name }}</p>
                        <p v-if="selectedTool?.serial_number"><strong>S/N:</strong> {{ selectedTool.serial_number }}</p>
                        <p><strong>Category:</strong> {{ selectedTool?.category }}</p>
                        <p v-if="isStock(selectedTool)"><strong>In stock:</strong> {{ selectedTool.quantity_available }}</p>
                    </div>

                    <div class="form-group" v-if="isStock(selectedTool)">
                        <label>Quantity to issue*</label>
                        <input
                            type="number"
                            min="1"
                            :max="selectedTool.quantity_available"
                            v-model.number="assignForm.quantity"
                        >
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
import AdminSidebar from '../../Components/AdminSidebar.vue'
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
    },
    toolRequests: {
        type: Array,
        default: () => []
    },
    // Outstanding PPE (stock) hand-outs — who holds what, and how many.
    stockIssuances: {
        type: Array,
        default: () => []
    }
})

const isStock = (tool) => tool?.tracking_type === 'stock'

// ---- Tool request actions ----
const approveToolRequest = (req) => {
    let toolId = null
    if (!req.tool_id) {
        const tool = props.tools.find((t) => t.status === 'available')
        if (!tool) {
            alert('No available tools in inventory to issue. Add one first.')
            return
        }
        toolId = prompt(
            `This is a freeform request for "${req.tool_name_requested}". Enter the tool ID from inventory to issue, or leave blank to just mark as acknowledged.`,
            ''
        )
        if (toolId === null) return
        toolId = toolId.trim() ? Number(toolId) : null
    }

    if (!confirm(`Approve request from ${req.technician?.user?.name}?`)) return

    router.post(`/admin/tool-requests/${req.id}/approve`, { tool_id: toolId }, {
        preserveScroll: true,
    })
}

const rejectToolRequest = (req) => {
    const reason = prompt('Reason for rejecting this request:')
    if (!reason || reason.trim().length < 3) {
        if (reason !== null) alert('Please provide a brief reason (3+ characters).')
        return
    }
    router.post(`/admin/tool-requests/${req.id}/reject`, { decision_notes: reason.trim() }, {
        preserveScroll: true,
    })
}

const formatUrgency = (u) => {
    const map = { low: 'Low', normal: 'Normal', high: 'High' }
    return map[u] || u
}
const formatDateShort = (date) => date ? new Date(date).toLocaleDateString('en-KE', { day: 'numeric', month: 'short' }) : ''

const searchQuery = ref('')
const statusFilter = ref('')
const showModal = ref(false)
const showAssignToolModal = ref(false)
const isEditing = ref(false)
const selectedTool = ref(null)

const form = ref({
    name: '',
    tracking_type: 'serialized',
    serial_number: '',
    quantity_available: 1,
    category: '',
    condition: 'good',
    description: '',
    notes: ''
})

const assignForm = ref({
    technician_id: '',
    service_request_id: '',
    quantity: 1,
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
        tracking_type: tool.tracking_type || 'serialized',
        serial_number: tool.serial_number || '',
        quantity_available: tool.quantity_available ?? 0,
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
        tracking_type: 'serialized',
        serial_number: '',
        quantity_available: 1,
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
        quantity: 1,
        expected_return_date: '',
        notes: ''
    }
}

// Record a return of issued PPE against its ledger row (ops has the items).
const returnStockIssuance = (issuance) => {
    const max = issuance.quantity_outstanding
    const input = prompt(`Return how many ${issuance.tool?.name}? (up to ${max})`, String(max))
    if (input === null) return
    const quantity = Number(input)
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > max) {
        alert(`Enter a whole number between 1 and ${max}.`)
        return
    }
    router.post(`/admin/tool-issuances/${issuance.id}/return`, { quantity }, { preserveScroll: true })
}

// Confirm a technician's pending return — this is what actually restocks it.
const confirmStockReturn = (issuance) => {
    if (!confirm(`Confirm return of ${issuance.return_pending_quantity} × ${issuance.tool?.name}? This puts them back in stock.`)) return
    router.post(`/admin/tool-issuances/${issuance.id}/confirm-return`, {}, { preserveScroll: true })
}

// Reject a pending return — nothing restocks, it stays against the technician.
const rejectStockReturn = (issuance) => {
    if (!confirm(`Reject this return? The ${issuance.return_pending_quantity} × ${issuance.tool?.name} stay recorded against the technician.`)) return
    router.post(`/admin/tool-issuances/${issuance.id}/reject-return`, {}, { preserveScroll: true })
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

.field-hint { display: block; margin-top: 0.25rem; font-size: 0.72rem; color: #64748B; line-height: 1.4; }

.row-pending-return { background: #FFFBEB; }
.pending-return-tag {
    display: inline-block;
    margin-left: 0.4rem;
    padding: 1px 8px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    background: #FEF3C7;
    color: #92400E;
}

/* Pending tool requests panel */
.tool-requests-panel { border-left: 4px solid #F59E0B; background: #FFFBEB; }
.tool-requests-panel .card-header h3 { display: flex; align-items: center; gap: 0.5rem; color: #92400E; }
.tool-requests-panel .card-header h3 i { color: #F59E0B; }
.badge-pending {
    background: #F59E0B;
    color: #fff;
    border-radius: 999px;
    padding: 2px 10px;
    font-size: 0.72rem;
    font-weight: 700;
}
.tool-request-list { display: flex; flex-direction: column; gap: 0.75rem; padding: 1rem; }
.tool-request-row {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    background: #fff;
    border: 1px solid #FCD34D;
    border-radius: 12px;
    padding: 0.85rem 1rem;
}
.tr-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 0.2rem; }
.tr-main strong { font-size: 0.95rem; }
.tr-sub { font-size: 0.78rem; color: #64748B; }
.tr-notes {
    font-size: 0.82rem;
    color: #475569;
    background: #F8FAFC;
    border-left: 3px solid #cbd5e1;
    padding: 0.4rem 0.7rem;
    margin: 0.4rem 0 0;
    border-radius: 6px;
    font-style: italic;
}
.tr-actions { display: flex; flex-direction: column; gap: 0.4rem; flex-shrink: 0; }
.urgency-tag {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
}
.urgency-tag-low { background: #E0F2FE; color: #075985; }
.urgency-tag-normal { background: #E2E8F0; color: #475569; }
.urgency-tag-high { background: #FEE2E2; color: #991B1B; }

@media (max-width: 640px) {
    .tool-request-row { flex-direction: column; }
    .tr-actions { flex-direction: row; width: 100%; }
    .tr-actions .btn { flex: 1; }
}

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