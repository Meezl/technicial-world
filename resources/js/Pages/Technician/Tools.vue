<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <h1>My Tools</h1>
            <div class="header-actions">
                <button class="btn btn-primary btn-sm" @click="openRequestModal">
                    <i class="fas fa-plus"></i> Request
                </button>
            </div>
        </header>

        <main class="pwa-content">

            <!-- Flash message -->
            <div v-if="flashMessage" :class="['flash-banner', `flash-${flashType}`]">
                <i :class="flashType === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'"></i>
                <span>{{ flashMessage }}</span>
                <button class="flash-close" @click="flashMessage = ''" aria-label="Dismiss">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Pending requests -->
            <template v-if="pendingRequests.length > 0">
                <h2 class="section-title">
                    Pending Requests
                    <span class="section-count">{{ pendingRequests.length }}</span>
                </h2>
                <div v-for="req in pendingRequests" :key="`pending-${req.id}`" class="pwa-card pending-card">
                    <div class="card-top-row">
                        <div>
                            <h3 class="card-title">{{ req.tool?.name || req.tool_name_requested }}</h3>
                            <span class="card-sub">
                                {{ req.tool?.serial_number ? `SN: ${req.tool.serial_number}` : 'Generic request' }}
                            </span>
                        </div>
                        <span class="urgency-pill" :class="`urgency-${req.urgency}`">{{ req.urgency }}</span>
                    </div>
                    <div class="card-meta-row">
                        <span v-if="req.service_request"><i class="fas fa-briefcase"></i> {{ req.service_request.job_reference || req.service_request.request_id }}</span>
                        <span><i class="fas fa-cubes"></i> Qty {{ req.quantity }}</span>
                        <span><i class="far fa-clock"></i> {{ formatDate(req.created_at) }}</span>
                    </div>
                    <p v-if="req.notes" class="card-notes">"{{ req.notes }}"</p>
                    <div class="card-actions">
                        <span class="status-pill status-pending">Awaiting admin approval</span>
                        <button class="btn btn-sm btn-outline btn-danger" @click="cancelRequest(req)">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            </template>

            <!-- Issued Tools -->
            <h2 class="section-title">Currently Issued</h2>
            <div v-if="issuedTools.length > 0">
                <div v-for="tool in issuedTools" :key="tool.id" class="pwa-card">
                    <div class="card-top-row">
                        <div>
                            <h3 class="card-title">{{ tool.name }}</h3>
                            <span class="card-sub">ID: {{ tool.serial_number || tool.id }}</span>
                        </div>
                        <div class="card-icon">
                            <i class="fas fa-hammer"></i>
                        </div>
                    </div>
                    <p class="card-text">
                        Condition: <strong>{{ formatCondition(tool.condition) }}</strong>
                    </p>
                    <p v-if="tool.service_request" class="card-meta-line">
                        <i class="fas fa-briefcase"></i> Job {{ tool.service_request.job_reference || tool.service_request.request_id || `#${tool.service_request.id}` }}
                    </p>
                    <button class="btn btn-outline btn-danger full" @click="returnTool(tool)">
                        Return Tool
                    </button>
                </div>
            </div>
            <div v-else class="pwa-card empty-state">
                <p>No tools currently issued to you.</p>
            </div>

            <!-- PPE (stock items) currently held. Returns are recorded by the
                 office when you hand them back. -->
            <template v-if="issuedStock.length > 0">
                <h2 class="section-title">PPE In Your Care</h2>
                <div v-for="iss in issuedStock" :key="`stock-${iss.id}`" class="pwa-card">
                    <div class="card-top-row">
                        <div>
                            <h3 class="card-title">{{ iss.tool?.name }}</h3>
                            <span class="card-sub">{{ iss.tool?.category || 'PPE' }}</span>
                        </div>
                        <div class="card-icon"><i class="fas fa-hard-hat"></i></div>
                    </div>
                    <p class="card-text">
                        Holding <strong>{{ iss.quantity_outstanding }}</strong>
                        <span v-if="iss.quantity_outstanding !== iss.quantity">of {{ iss.quantity }} issued</span>
                    </p>
                    <p v-if="iss.service_request" class="card-meta-line">
                        <i class="fas fa-briefcase"></i> Job {{ iss.service_request.job_reference || iss.service_request.request_id }}
                    </p>
                </div>
            </template>

            <!-- Recent decisions -->
            <template v-if="recentDecisions.length > 0">
                <h2 class="section-title">Recent Decisions</h2>
                <div class="pwa-card decision-card">
                    <div v-for="d in recentDecisions" :key="`d-${d.id}`" class="decision-row">
                        <div>
                            <strong>{{ d.tool?.name || d.tool_name_requested }}</strong>
                            <span class="card-sub">{{ formatDate(d.decided_at || d.updated_at) }}</span>
                            <p v-if="d.decision_notes" class="decision-notes">{{ d.decision_notes }}</p>
                        </div>
                        <span class="status-pill" :class="`status-${d.status}`">{{ formatStatus(d.status) }}</span>
                    </div>
                </div>
            </template>

            <!-- Return History -->
            <h2 class="section-title">Recent Returns</h2>
            <div v-if="returnHistory.length > 0" class="pwa-card list-card">
                <div v-for="tool in returnHistory" :key="tool.id" class="list-item">
                    <div>
                        <div class="item-name">{{ tool.name }}</div>
                        <div class="item-meta">Returned: {{ formatDate(tool.returned_at || tool.updated_at) }}</div>
                    </div>
                    <span class="status-pill status-returned">Returned</span>
                </div>
            </div>
            <p v-else class="muted-note">No previous returns yet.</p>
        </main>

        <!-- ================== Request Tool Modal ================== -->
        <div v-if="showRequestModal" class="tools-modal-overlay" @click.self="closeRequestModal">
            <div class="tools-modal">
                <div class="tools-modal-header">
                    <div>
                        <h3>Request a Tool</h3>
                        <small>Send the request to admin. You'll see the decision here.</small>
                    </div>
                    <button class="tools-modal-close" @click="closeRequestModal" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form @submit.prevent="submitRequest" class="tools-modal-body">

                    <!-- Mode toggle -->
                    <div class="mode-toggle">
                        <button type="button" :class="['mode-btn', requestMode === 'inventory' ? 'active' : '']" @click="setMode('inventory')">
                            <i class="fas fa-list"></i> From inventory
                        </button>
                        <button type="button" :class="['mode-btn', requestMode === 'custom' ? 'active' : '']" @click="setMode('custom')">
                            <i class="fas fa-keyboard"></i> Other / not listed
                        </button>
                    </div>

                    <!-- Inventory pick -->
                    <div v-if="requestMode === 'inventory'" class="form-block">
                        <label>Available Tool</label>
                        <input
                            v-model="availableSearch"
                            type="text"
                            placeholder="Search by name, serial, or category..."
                            class="form-input"
                        />
                        <div class="available-list" v-if="filteredAvailableTools.length">
                            <button
                                v-for="tool in filteredAvailableTools"
                                :key="tool.id"
                                type="button"
                                :class="['available-tool', currentItem.tool_id === tool.id ? 'selected' : '']"
                                @click="currentItem.tool_id = tool.id"
                            >
                                <div class="available-tool-main">
                                    <strong>{{ tool.name }}</strong>
                                    <span>{{ tool.category || 'General' }}{{ tool.serial_number ? ` · SN: ${tool.serial_number}` : '' }}</span>
                                </div>
                                <span v-if="tool.tracking_type === 'stock'" class="available-tool-cond">
                                    {{ tool.quantity_available }} in stock
                                </span>
                                <span v-else class="available-tool-cond">{{ formatCondition(tool.condition) }}</span>
                            </button>
                        </div>
                        <p v-else class="muted-note small">No tools matched. Try a different search or use "Other / not listed".</p>
                    </div>

                    <!-- Custom name -->
                    <div v-else class="form-block">
                        <label>What do you need?</label>
                        <input
                            v-model="currentItem.tool_name_requested"
                            type="text"
                            placeholder="e.g. 14mm spanner set, 6m extension ladder"
                            class="form-input"
                        />
                    </div>

                    <!-- Job / Quantity / Add Item Button -->
                    <div class="form-grid">
                        <div class="form-block">
                            <label>Quantity</label>
                            <input v-model.number="currentItem.quantity" type="number" min="1" max="50" class="form-input" />
                        </div>
                        <div class="form-block" style="justify-content: flex-end;">
                            <button type="button" class="btn btn-outline" @click="addItem" :disabled="!canAddItem">
                                <i class="fas fa-plus"></i> Add Item
                            </button>
                        </div>
                    </div>

                    <!-- Items List -->
                    <div class="form-block" v-if="form.items.length > 0">
                        <label>Items to Request</label>
                        <ul style="padding-left: 1.5rem; margin: 0; font-size: 0.85rem;">
                            <li v-for="(item, index) in form.items" :key="index" style="margin-bottom: 0.5rem;">
                                {{ item.tool_id ? (availableTools.find(t => t.id === item.tool_id)?.name || 'Tool') : item.tool_name_requested }}
                                (Qty: {{ item.quantity }})
                                <a href="#" @click.prevent="removeItem(index)" style="color: var(--danger-color); margin-left: 10px; text-decoration: none;">
                                    <i class="fas fa-times"></i> Remove
                                </a>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="form-block">
                        <label>For Job (optional)</label>
                        <select v-model="form.service_request_id" class="form-input">
                            <option :value="null">— Not job-specific —</option>
                            <option v-for="job in activeJobs" :key="job.id" :value="job.id">
                                {{ job.job_reference || job.request_id }}
                            </option>
                        </select>
                    </div>

                    <div class="form-block">
                        <label>Urgency</label>
                        <div class="urgency-row">
                            <button
                                v-for="opt in urgencyOptions"
                                :key="opt.value"
                                type="button"
                                :class="['urgency-option', `urgency-option-${opt.value}`, form.urgency === opt.value ? 'active' : '']"
                                @click="form.urgency = opt.value"
                            >
                                {{ opt.label }}
                            </button>
                        </div>
                    </div>

                    <div class="form-block">
                        <label>Notes (optional)</label>
                        <textarea
                            v-model="form.notes"
                            rows="3"
                            class="form-input"
                            placeholder="Anything the admin should know — e.g. urgent on-site need, alternative okay, etc."
                        ></textarea>
                    </div>

                    <div class="tools-modal-footer">
                        <button type="button" class="btn btn-outline" @click="closeRequestModal">Cancel</button>
                        <button type="submit" class="btn btn-primary" :disabled="!canSubmit || submitting">
                            <i class="fas fa-paper-plane"></i>
                            {{ submitting ? 'Sending...' : 'Send Request' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <TechnicianBottomNav current-page="tools" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue'

const props = defineProps({
    technician: Object,
    issuedTools: { type: Array, default: () => [] },
    issuedStock: { type: Array, default: () => [] },
    returnHistory: { type: Array, default: () => [] },
    availableTools: { type: Array, default: () => [] },
    activeJobs: { type: Array, default: () => [] },
    pendingRequests: { type: Array, default: () => [] },
    recentDecisions: { type: Array, default: () => [] },
})

const inertiaPage = usePage()
const flashMessage = ref('')
const flashType = ref('success')

function readFlash() {
    const s = inertiaPage.props.flash?.success
    const e = inertiaPage.props.flash?.error
    if (s) {
        flashMessage.value = s
        flashType.value = 'success'
        setTimeout(() => { flashMessage.value = '' }, 5000)
    } else if (e) {
        flashMessage.value = e
        flashType.value = 'error'
        setTimeout(() => { flashMessage.value = '' }, 6000)
    }
}
onMounted(readFlash)
watch(() => inertiaPage.props.flash, readFlash, { deep: true })

// ------- Request modal state -------
const showRequestModal = ref(false)
const submitting = ref(false)
const requestMode = ref('inventory') // 'inventory' | 'custom'
const availableSearch = ref('')

const form = ref({
    service_request_id: null,
    urgency: 'normal',
    notes: '',
    items: [],
})

const currentItem = ref({
    tool_id: null,
    tool_name_requested: '',
    quantity: 1,
})

const urgencyOptions = [
    { value: 'low', label: 'Low' },
    { value: 'normal', label: 'Normal' },
    { value: 'high', label: 'High' },
]

const filteredAvailableTools = computed(() => {
    const q = availableSearch.value.trim().toLowerCase()
    if (!q) return props.availableTools
    return props.availableTools.filter((t) => {
        return [t.name, t.serial_number, t.category]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(q))
    })
})

const canAddItem = computed(() => {
    if (requestMode.value === 'inventory') return !!currentItem.value.tool_id
    return currentItem.value.tool_name_requested.trim().length >= 2
})

const canSubmit = computed(() => {
    return form.value.items.length > 0
})

function setMode(mode) {
    requestMode.value = mode
    if (mode === 'inventory') {
        currentItem.value.tool_name_requested = ''
    } else {
        currentItem.value.tool_id = null
        availableSearch.value = ''
    }
}

function addItem() {
    if (!canAddItem.value) return
    form.value.items.push({ ...currentItem.value })
    
    // reset current item
    currentItem.value = {
        tool_id: null,
        tool_name_requested: '',
        quantity: 1,
    }
    availableSearch.value = ''
}

function removeItem(index) {
    form.value.items.splice(index, 1)
}

function openRequestModal() {
    showRequestModal.value = true
    form.value = {
        service_request_id: null,
        urgency: 'normal',
        notes: '',
        items: [],
    }
    currentItem.value = {
        tool_id: null,
        tool_name_requested: '',
        quantity: 1,
    }
    availableSearch.value = ''
    requestMode.value = 'inventory'
}

function closeRequestModal() {
    showRequestModal.value = false
}

function submitRequest() {
    if (!canSubmit.value || submitting.value) return
    submitting.value = true
    router.post('/technician/tool-requests', form.value, {
        preserveScroll: true,
        onSuccess: () => { closeRequestModal() },
        onError: () => {},
        onFinish: () => { submitting.value = false },
    })
}

function cancelRequest(req) {
    if (!confirm('Cancel this tool request?')) return
    router.post(`/technician/tool-requests/${req.id}/cancel`, {}, {
        preserveScroll: true,
    })
}

function returnTool(tool) {
    const condition = prompt('Please confirm tool condition (good, fair, needs_repair, damaged):', 'good')
    if (!condition) return
    if (!['good', 'fair', 'needs_repair', 'damaged'].includes(condition)) {
        alert('Invalid condition entered.')
        return
    }
    router.post(`/technician/tools/${tool.id}/return`, { condition }, { preserveScroll: true })
}

function formatCondition(condition) {
    return condition ? condition.replace('_', ' ').replace(/\b\w/g, (l) => l.toUpperCase()) : 'Unknown'
}

function formatStatus(status) {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase()) : ''
}

function formatDate(dateString) {
    if (!dateString) return ''
    return new Date(dateString).toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: 'numeric' })
}

defineOptions({ layout: null })
</script>

<style>

/* Section headings */
.section-title {
    font-size: 0.85rem;
    color: var(--light-text);
    margin: 1.25rem 0 0.5rem;
    padding-left: 0.25rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.section-count {
    background: var(--primary-color);
    color: #fff;
    border-radius: 999px;
    padding: 1px 8px;
    font-size: 0.7rem;
    font-weight: 700;
}

/* Card primitives */
.card-top-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}
.card-title { margin: 0; font-size: 1.05rem; font-weight: 700; }
.card-sub { font-size: 0.78rem; color: var(--light-text); }
.card-icon {
    background: #E0E7FF;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-color);
    flex-shrink: 0;
}
.card-text { font-size: 0.9rem; margin-bottom: 0.4rem; }
.card-meta-line { font-size: 0.78rem; color: var(--light-text); margin-bottom: 0.65rem; }
.card-meta-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.85rem;
    font-size: 0.78rem;
    color: var(--light-text);
    margin-top: 0.5rem;
}
.card-meta-row i { margin-right: 4px; }
.card-notes {
    font-size: 0.85rem;
    background: #F8FAFC;
    border-left: 3px solid #cbd5e1;
    padding: 0.5rem 0.75rem;
    margin: 0.6rem 0 0;
    color: #475569;
    border-radius: 6px;
    font-style: italic;
}
.card-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.85rem;
}
.btn.full { width: 100%; margin-top: 0.5rem; }
.btn.btn-danger { color: var(--danger-color); border-color: var(--danger-color); background: transparent; }
.btn.btn-danger:hover { background: var(--danger-color); color: #fff; }

/* Pending */
.pending-card { border-left: 4px solid var(--warning-color); background: #FFFBEB; }

/* Status pills */
.status-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.status-pending { background: #FEF3C7; color: #92400E; }
.status-approved { background: #DCFCE7; color: #166534; }
.status-rejected { background: #FEE2E2; color: #991B1B; }
.status-cancelled { background: #E2E8F0; color: #475569; }
.status-returned { background: #F1F5F9; color: #475569; }

.urgency-pill {
    padding: 2px 10px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
}
.urgency-low { background: #E0F2FE; color: #075985; }
.urgency-normal { background: #F1F5F9; color: #475569; }
.urgency-high { background: #FEE2E2; color: #991B1B; }

/* Decisions */
.decision-card { padding: 0; }
.decision-row {
    padding: 0.85rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    border-bottom: 1px solid var(--border-color);
}
.decision-row:last-child { border-bottom: 0; }
.decision-row strong { display: block; font-size: 0.92rem; }
.decision-notes { font-size: 0.78rem; color: var(--light-text); margin: 0.25rem 0 0; }

/* Returns list */
.list-card { padding: 0; }
.list-card .list-item {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.list-card .list-item:last-child { border-bottom: 0; }
.item-name { font-weight: 600; }
.item-meta { font-size: 0.78rem; color: var(--light-text); }

.empty-state { text-align: center; padding: 2rem 1rem; color: var(--light-text); }
.muted-note { color: var(--light-text); font-size: 0.85rem; padding: 0 0.25rem; }
.muted-note.small { font-size: 0.78rem; }

/* Flash */
.flash-banner {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.7rem 0.9rem;
    border-radius: 10px;
    margin-bottom: 0.9rem;
    font-size: 0.88rem;
    border: 1px solid transparent;
}
.flash-success { background: #ECFDF5; color: #065F46; border-color: #A7F3D0; }
.flash-error { background: #FEF2F2; color: #991B1B; border-color: #FECACA; }
.flash-banner span { flex: 1; }
.flash-close {
    background: transparent;
    border: none;
    color: inherit;
    opacity: 0.6;
    cursor: pointer;
    font-size: 0.85rem;
}

/* ================ MODAL ================= */
.tools-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: flex;
    align-items: flex-end;
    justify-content: center;
    z-index: 1000;
    padding: 0;
}
.tools-modal {
    background: #fff;
    width: 100%;
    max-width: 520px;
    max-height: 92vh;
    border-radius: 18px 18px 0 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 -8px 32px rgba(0,0,0,0.18);
}
@media (min-width: 540px) {
    .tools-modal-overlay { align-items: center; padding: 1rem; }
    .tools-modal { border-radius: 18px; }
}
.tools-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid var(--border-color);
    gap: 0.75rem;
}
.tools-modal-header h3 { margin: 0; font-size: 1rem; }
.tools-modal-header small { display: block; font-size: 0.78rem; color: var(--light-text); margin-top: 2px; }
.tools-modal-close {
    background: transparent;
    border: none;
    color: var(--light-text);
    font-size: 1rem;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
}
.tools-modal-close:hover { background: var(--app-bg); color: var(--text-color); }
.tools-modal-body {
    padding: 1rem 1.1rem;
    overflow-y: auto;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.tools-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.6rem;
    padding-top: 0.5rem;
    border-top: 1px solid var(--border-color);
    margin-top: 0.5rem;
    padding: 0.85rem 0 0;
}

.mode-toggle {
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: #F1F5F9;
    border-radius: 10px;
    padding: 4px;
    gap: 4px;
}
.mode-btn {
    background: transparent;
    border: none;
    padding: 0.55rem 0.5rem;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--light-text);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
}
.mode-btn.active { background: #fff; color: var(--primary-color); box-shadow: 0 1px 3px rgba(0,0,0,0.08); }

.form-block { display: flex; flex-direction: column; gap: 0.4rem; }
.form-block label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.form-input {
    width: 100%;
    padding: 0.65rem 0.8rem;
    border: 1px solid #CBD5E1;
    border-radius: 10px;
    font-size: 0.92rem;
    box-sizing: border-box;
    font-family: inherit;
    background: #fff;
}
.form-input:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.7rem; }

.available-list {
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    background: #F8FAFC;
}
.available-tool {
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    padding: 0.7rem 0.85rem;
    border-bottom: 1px solid #E2E8F0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    gap: 0.5rem;
}
.available-tool:last-child { border-bottom: 0; }
.available-tool.selected { background: #EFF6FF; }
.available-tool-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.available-tool-main strong { font-size: 0.9rem; color: var(--text-color); }
.available-tool-main span { font-size: 0.75rem; color: var(--light-text); }
.available-tool-cond { font-size: 0.72rem; color: var(--primary-color); font-weight: 700; }

.urgency-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.urgency-option {
    padding: 0.55rem 0.3rem;
    border-radius: 10px;
    border: 1px solid #CBD5E1;
    background: #fff;
    font-size: 0.82rem;
    cursor: pointer;
    color: var(--text-color);
    font-weight: 600;
}
.urgency-option.active.urgency-option-low { background: #E0F2FE; border-color: #38BDF8; color: #075985; }
.urgency-option.active.urgency-option-normal { background: #E2E8F0; border-color: #94A3B8; color: #1E293B; }
.urgency-option.active.urgency-option-high { background: #FEE2E2; border-color: #F87171; color: #991B1B; }

.btn.btn-outline { background: transparent; }
</style>
