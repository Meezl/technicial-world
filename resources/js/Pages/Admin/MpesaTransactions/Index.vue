<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="mpesa-transactions" />

        <main class="main-content">
            <header class="main-header">
                <h1>M-Pesa Transactions</h1>
                <div class="header-actions" style="display:flex;gap:.5rem;">
                    <button
                        @click="diagnose"
                        :disabled="diagnosing"
                        class="btn btn-secondary btn-sm"
                        title="Test OAuth and fingerprint env credentials (no leakage)"
                    >
                        <i class="fas fa-stethoscope"></i>
                        {{ diagnosing ? 'Checking…' : 'Diagnose' }}
                    </button>
                    <button
                        @click="registerC2BUrls"
                        :disabled="registering"
                        class="btn btn-primary btn-sm"
                        title="Register the C2B confirmation/validation URLs with Safaricom Daraja"
                    >
                        <i class="fas fa-link"></i>
                        {{ registering ? 'Registering…' : 'Register C2B URLs' }}
                    </button>
                </div>
            </header>

            <div v-if="diagnoseResult" class="alert" style="margin: 1rem; background:#f0f9ff; border:1px solid #93c5fd; color:#1e3a8a;">
                <h4 style="margin-top:0;">M-Pesa Diagnostic</h4>
                <div><strong>Environment:</strong> <code>{{ diagnoseResult.environment }}</code>
                    <span v-if="!diagnoseResult.environment_valid" style="color:#dc2626;">⚠ INVALID — must be "production" or "sandbox"</span>
                </div>
                <div><strong>Base URL:</strong> <code>{{ diagnoseResult.base_url_in_use }}</code></div>
                <div><strong>Shortcode:</strong> <code>{{ diagnoseResult.shortcode }}</code></div>
                <h5 style="margin:.75rem 0 .25rem;">Credentials</h5>
                <div v-for="(c, name) in diagnoseResult.credentials" :key="name" style="font-size:.85rem;">
                    <strong>{{ name }}:</strong>
                    <span v-if="!c.set" style="color:#dc2626;">MISSING</span>
                    <span v-else>
                        length={{ c.length }}, preview=<code>{{ c.preview }}</code>
                        <span v-if="c.trimmed_differs" style="color:#dc2626;">⚠ HAS WHITESPACE</span>
                        <span v-if="c.has_quotes" style="color:#dc2626;">⚠ HAS QUOTES</span>
                    </span>
                </div>
                <h5 style="margin:.75rem 0 .25rem;">OAuth Test</h5>
                <div>
                    Token obtained:
                    <strong :style="diagnoseResult.oauth_test.token_obtained ? 'color:#059669' : 'color:#dc2626'">
                        {{ diagnoseResult.oauth_test.token_obtained ? 'YES' : 'NO' }}
                    </strong>
                    <span v-if="diagnoseResult.oauth_test.token_preview"> ({{ diagnoseResult.oauth_test.token_preview }}, len {{ diagnoseResult.oauth_test.token_length }})</span>
                </div>
                <div style="margin-top:.75rem; padding:.5rem; background:#fff; border-left:3px solid #2563eb;">
                    <strong>Next step:</strong> {{ diagnoseResult.next_step }}
                </div>
            </div>

            <div v-if="registerResult" :class="['alert', registerResult.success ? 'alert-success' : 'alert-danger']" style="margin: 1rem;">
                <strong>{{ registerResult.success ? '✓ Success' : '✗ Failed' }}:</strong>
                {{ registerResult.message }}
                <div v-if="registerResult.confirmation_url" style="margin-top:.5rem;font-size:.85rem;">
                    Confirmation URL: <code>{{ registerResult.confirmation_url }}</code><br>
                    Validation URL: <code>{{ registerResult.validation_url }}</code><br>
                    Shortcode: <code>{{ registerResult.shortcode }}</code> ({{ registerResult.environment }})
                </div>
                <div v-if="registerResult.credentials" style="margin-top:.5rem;font-size:.8rem;">
                    <strong>Credential check:</strong>
                    consumer_key={{ registerResult.credentials.consumer_key }},
                    consumer_secret={{ registerResult.credentials.consumer_secret }},
                    passkey={{ registerResult.credentials.passkey }}
                </div>
                <details v-if="registerResult.raw" style="margin-top:.5rem;font-size:.8rem;">
                    <summary>Safaricom raw response</summary>
                    <pre style="background:#1e293b;color:#e2e8f0;padding:.5rem;border-radius:4px;overflow:auto;">{{ JSON.stringify(registerResult.raw, null, 2) }}</pre>
                </details>
            </div>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="card-header">
                        <h3>All Transactions</h3>
                    </div>

                    <div v-if="successMessage" class="alert alert-success">
                        {{ successMessage }}
                    </div>

                    <!-- Filter chips -->
                    <div class="mpesa-filter-bar">
                        <div class="filter-row">
                            <span class="filter-label">Status:</span>
                            <div class="filter-chips">
                                <button
                                    v-for="opt in statusOptions"
                                    :key="opt.value"
                                    @click="applyStatus(opt.value)"
                                    :class="['filter-chip', { active: activeStatus === opt.value }]"
                                >
                                    {{ opt.label }}
                                    <span class="chip-count">{{ counts[opt.value === '' ? 'all' : opt.value] ?? 0 }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="filter-row">
                            <span class="filter-label">Source:</span>
                            <div class="filter-chips">
                                <button
                                    v-for="opt in sourceOptions"
                                    :key="opt.value"
                                    @click="applySource(opt.value)"
                                    :class="['filter-chip', { active: activeSource === opt.value && !showUnmatched }]"
                                >
                                    {{ opt.label }}
                                    <span class="chip-count">{{ counts[opt.value === '' ? 'all' : opt.value] ?? 0 }}</span>
                                </button>
                                <button
                                    @click="applyUnmatched"
                                    :class="['filter-chip', 'chip-warn', { active: showUnmatched }]"
                                >
                                    Unmatched Paybill
                                    <span class="chip-count">{{ counts.unmatched ?? 0 }}</span>
                                </button>
                            </div>
                        </div>
                        <div class="filter-search">
                            <input
                                v-model="searchTerm"
                                @keyup.enter="applySearch"
                                type="text"
                                placeholder="Search receipt, phone, BillRef, payer…"
                                class="form-control"
                            />
                            <button v-if="searchTerm || activeStatus || activeSource || showUnmatched" @click="clearFilters" class="btn btn-secondary btn-sm">
                                Clear
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th>Receipt / Ref</th>
                                    <th>Bill Ref</th>
                                    <th>Payer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Linked Request</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="tx in transactions.data"
                                    :key="tx.id"
                                    :class="['mpesa-row', linkedGroupClass(tx)]"
                                >
                                    <td>
                                        <span :class="['source-badge', tx.source === 'c2b' ? 'src-c2b' : 'src-stk']">
                                            {{ tx.source === 'c2b' ? 'Paybill' : 'STK Push' }}
                                        </span>
                                        <!-- When the same receipt appears more than once on this page, mark the
                                             secondary channels so admin instantly sees they're not separate money. -->
                                        <small
                                            v-if="linkedCount(tx) > 1"
                                            class="linked-pill"
                                            :title="`Same M-Pesa receipt as ${linkedCount(tx) - 1} other ${linkedCount(tx) - 1 === 1 ? 'row' : 'rows'} on this page — one physical payment, multiple Safaricom notifications.`"
                                        >
                                            <i class="fas fa-link"></i> +{{ linkedCount(tx) - 1 }} channel{{ linkedCount(tx) - 1 === 1 ? '' : 's' }}
                                        </small>
                                    </td>
                                    <td>{{ tx.receipt_number || tx.checkout_request_id || 'N/A' }}</td>
                                    <td>
                                        <code v-if="tx.bill_ref_number">{{ tx.bill_ref_number }}</code>
                                        <span v-else class="muted">—</span>
                                    </td>
                                    <td>
                                        <div>{{ tx.payer_name || '—' }}</div>
                                        <small class="muted">{{ tx.phone_number || '' }}</small>
                                    </td>
                                    <td>{{ tx.amount !== null ? 'KES ' + tx.amount : 'N/A' }}</td>
                                    <td>
                                        <span :class="['status-badge', statusBadgeClass(tx.status)]">
                                            {{ statusLabel(tx.status) }}
                                        </span>
                                        <span v-if="tx.source === 'c2b' && tx.reconciled" class="status-badge status-completed" style="margin-left:.25rem;font-size:.7rem;">Matched</span>
                                        <span v-else-if="tx.source === 'c2b' && !tx.reconciled" class="status-badge status-failed" style="margin-left:.25rem;font-size:.7rem;">Unmatched</span>
                                    </td>
                                    <td>
                                        <div v-if="tx.payment_request">
                                            <Link :href="`/admin/payments`" style="font-size:.85rem;">
                                                {{ tx.payment_request.payment_request_id }}
                                            </Link>
                                            <small v-if="tx.payment_request.service_request" class="muted" style="display:block;">
                                                {{ tx.payment_request.service_request.request_id }}
                                            </small>
                                        </div>
                                        <span v-else class="muted">—</span>
                                    </td>
                                    <td>{{ formatDate(tx.created_at) }}</td>
                                    <td class="actions-cell">
                                        <button
                                            v-if="tx.source === 'c2b' && !tx.reconciled"
                                            @click="openReconcile(tx)"
                                            class="btn-icon btn-icon-primary"
                                            title="Reconcile to payment request"
                                        >
                                            <i class="fas fa-link"></i>
                                        </button>
                                        <Link :href="`/admin/mpesa-transactions/${tx.id}`" class="btn-icon" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </Link>
                                        <Link :href="`/admin/mpesa-transactions/${tx.id}/edit`" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </Link>
                                        <button @click="deleteTransaction(tx.id)" class="btn-icon text-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="transactions.data.length === 0">
                                    <td colspan="9" class="text-center">No transactions found.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Simple Pagination -->
                    <div class="pagination-container" v-if="transactions.links">
                        <div class="pagination">
                            <Component
                                :is="link.url ? 'Link' : 'span'"
                                v-for="(link, idx) in transactions.links"
                                :key="idx"
                                :href="link.url"
                                v-html="link.label"
                                :class="['page-link', { 'active': link.active, 'disabled': !link.url }]"
                            />
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Reconcile modal -->
        <div v-if="showReconcileModal" class="modal-overlay" @click.self="showReconcileModal = false">
            <div class="modal-content" style="max-width:520px;">
                <div class="modal-header">
                    <h3>Reconcile Paybill Payment</h3>
                    <button @click="showReconcileModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p v-if="reconcileTarget">
                        Match this <strong>KES {{ reconcileTarget.amount }}</strong> payment
                        (M-Pesa: <code>{{ reconcileTarget.receipt_number }}</code>,
                        BillRef: <code>{{ reconcileTarget.bill_ref_number || '—' }}</code>,
                        from {{ reconcileTarget.payer_name || reconcileTarget.phone_number }})
                        to a pending payment request:
                    </p>
                    <div class="form-group" style="margin-top:1rem;">
                        <label>Pending Payment Request</label>
                        <select v-model="reconcileSelectionId" class="form-control">
                            <option value="">— Select a request —</option>
                            <option v-for="pr in pendingPaymentRequests" :key="pr.id" :value="pr.id">
                                {{ pr.payment_request_id }} —
                                {{ pr.service_request?.request_id || 'No SR' }} —
                                KES {{ pr.amount }}
                            </option>
                        </select>
                        <small v-if="!pendingPaymentRequests.length" style="color:#666">
                            No pending payment requests available.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showReconcileModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="submitReconcile" :disabled="!reconcileSelectionId || reconcileSubmitting" class="btn btn-primary">
                        {{ reconcileSubmitting ? 'Reconciling…' : 'Reconcile & Advance Request' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'
import { computed, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
    transactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({ status: null, source: null, unmatched: false, search: null }) },
    counts: { type: Object, default: () => ({ all: 0, initiated: 0, completed: 0, failed: 0, stk_push: 0, c2b: 0, unmatched: 0 }) },
    pendingPaymentRequests: { type: Array, default: () => [] },
})

const page = usePage()
const successMessage = computed(() => page.props.flash?.success)

// Group rows that share the same M-Pesa receipt number — those are
// notifications about ONE physical payment from multiple Safaricom
// channels (STK callback + C2B confirmation). Each group gets a unique
// background tint so the relationship is visible at a glance.
const receiptGroups = computed(() => {
    const groups = {}
    for (const tx of (props.transactions.data || [])) {
        if (!tx.receipt_number) continue
        groups[tx.receipt_number] = (groups[tx.receipt_number] || 0) + 1
    }
    return groups
})

const linkedCount = (tx) => {
    if (!tx.receipt_number) return 1
    return receiptGroups.value[tx.receipt_number] || 1
}

// A receipt that appears more than once gets a tinted row so the eye
// can pair them. Different receipts get different tints (cycled).
const groupTints = ['linked-tint-a', 'linked-tint-b', 'linked-tint-c', 'linked-tint-d', 'linked-tint-e']
const receiptToTint = computed(() => {
    const map = {}
    let i = 0
    for (const [receipt, count] of Object.entries(receiptGroups.value)) {
        if (count > 1) {
            map[receipt] = groupTints[i % groupTints.length]
            i++
        }
    }
    return map
})

const linkedGroupClass = (tx) => {
    if (!tx.receipt_number) return ''
    return receiptToTint.value[tx.receipt_number] || ''
}

const statusOptions = [
    { value: '', label: 'All' },
    { value: 'initiated', label: 'STK Sent' },
    { value: 'completed', label: 'Completed' },
    { value: 'failed', label: 'Failed' },
]
const sourceOptions = [
    { value: '', label: 'All Sources' },
    { value: 'stk_push', label: 'STK Push' },
    { value: 'c2b', label: 'Paybill' },
]

const activeStatus = ref(props.filters?.status || '')
const activeSource = ref(props.filters?.source || '')
const showUnmatched = ref(!!props.filters?.unmatched)
const searchTerm = ref(props.filters?.search || '')

const buildQuery = () => ({
    status: activeStatus.value || undefined,
    source: activeSource.value || undefined,
    unmatched: showUnmatched.value ? '1' : undefined,
    search: searchTerm.value || undefined,
})

const applyStatus = (status) => {
    activeStatus.value = status
    router.get('/admin/mpesa-transactions', buildQuery(), { preserveState: true, replace: true })
}
const applySource = (source) => {
    activeSource.value = source
    showUnmatched.value = false
    router.get('/admin/mpesa-transactions', buildQuery(), { preserveState: true, replace: true })
}
const applyUnmatched = () => {
    showUnmatched.value = !showUnmatched.value
    activeSource.value = ''
    router.get('/admin/mpesa-transactions', buildQuery(), { preserveState: true, replace: true })
}
const applySearch = () => {
    router.get('/admin/mpesa-transactions', buildQuery(), { preserveState: true, replace: true })
}
const clearFilters = () => {
    activeStatus.value = ''
    activeSource.value = ''
    showUnmatched.value = false
    searchTerm.value = ''
    router.get('/admin/mpesa-transactions', {}, { preserveState: true, replace: true })
}

// C2B URL registration
const registering = ref(false)
const registerResult = ref(null)
const diagnosing = ref(false)
const diagnoseResult = ref(null)

const diagnose = async () => {
    diagnosing.value = true
    diagnoseResult.value = null
    try {
        const { data } = await axios.get('/admin/mpesa/diagnose')
        diagnoseResult.value = data
    } catch (e) {
        diagnoseResult.value = {
            environment: 'unknown',
            environment_valid: false,
            credentials: {},
            oauth_test: { token_obtained: false },
            next_step: e.response?.data?.message || e.message || 'Diagnostic request failed.',
        }
    } finally {
        diagnosing.value = false
    }
}

const registerC2BUrls = async () => {
    if (!confirm('Register C2B Confirmation/Validation URLs with Safaricom Daraja? Run this once per shortcode, or after URL changes.')) return
    registering.value = true
    registerResult.value = null
    try {
        const { data } = await axios.post('/admin/mpesa/register-c2b-urls')
        registerResult.value = data
    } catch (e) {
        registerResult.value = e.response?.data || {
            success: false,
            message: e.message || 'Registration request failed.',
        }
    } finally {
        registering.value = false
    }
}

// Reconcile modal state
const showReconcileModal = ref(false)
const reconcileTarget = ref(null)
const reconcileSelectionId = ref('')
const reconcileSubmitting = ref(false)

const openReconcile = (tx) => {
    reconcileTarget.value = tx
    reconcileSelectionId.value = ''
    showReconcileModal.value = true
}

const submitReconcile = () => {
    if (!reconcileTarget.value || !reconcileSelectionId.value) return
    reconcileSubmitting.value = true
    router.post(`/admin/mpesa-transactions/${reconcileTarget.value.id}/reconcile`, {
        payment_request_id: reconcileSelectionId.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            reconcileSubmitting.value = false
            showReconcileModal.value = false
        },
    })
}

const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleString()
}

const statusLabel = (s) => {
    const map = { initiated: 'STK Sent', completed: 'Completed', failed: 'Failed' }
    return map[s] || 'Unknown'
}
const statusBadgeClass = (s) => {
    if (s === 'completed') return 'status-completed'
    if (s === 'failed') return 'status-failed'
    if (s === 'initiated') return 'status-pending'
    return 'status-pending'
}

const deleteTransaction = (id) => {
    if (confirm('Are you sure you want to delete this M-Pesa transaction?')) {
        router.delete(`/admin/mpesa-transactions/${id}`)
    }
}

defineOptions({
    layout: null
})
</script>

<style scoped>

.text-danger {
    color: var(--danger-color);
}
.btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    margin-right: 0.5rem;
    color: var(--text-muted);
}
.btn-icon:hover {
    color: var(--primary-color);
}
.btn-icon.text-danger:hover {
    color: var(--danger-hover);
}
.alert {
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 4px;
}
.alert-success {
    background-color: #d1fae5;
    color: #065f46;
    border: 1px solid #34d399;
}
.pagination-container {
    padding: 1rem;
    display: flex;
    justify-content: center;
}
.pagination {
    display: flex;
    gap: 0.25rem;
}
.page-link {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    color: var(--text-color);
    text-decoration: none;
    background: white;
}
.page-link.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}
.page-link.disabled {
    color: var(--text-muted);
    pointer-events: none;
    background: #f9fafb;
}

.mpesa-filter-bar {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}
.filter-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.filter-label {
    font-size: 0.8rem;
    color: #6b7280;
    font-weight: 600;
    min-width: 60px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.source-badge {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    border-radius: 4px;
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.src-c2b { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }
.src-stk { background: #eff6ff; color: #1e3a8a; border: 1px solid #93c5fd; }
.chip-warn.active { background: #f59e0b; border-color: #f59e0b; }
.chip-warn { border-color: #fbbf24; color: #92400e; }
.muted { color: #9ca3af; }
.btn-icon-primary { color: #3b82f6; }
.filter-chips {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.9rem;
    border: 1px solid #d1d5db;
    background: #fff;
    border-radius: 999px;
    cursor: pointer;
    font-size: 0.85rem;
    color: #374151;
    transition: all 0.15s;
}
.filter-chip:hover {
    border-color: #3b82f6;
    background: #eff6ff;
}
.filter-chip.active {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
}
.chip-count {
    background: rgba(0, 0, 0, 0.08);
    padding: 0.1rem 0.45rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.filter-chip.active .chip-count {
    background: rgba(255, 255, 255, 0.22);
}
.filter-search {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.filter-search input {
    min-width: 260px;
}

/* Linked notifications — when multiple rows share an M-Pesa receipt
   (STK callback + C2B confirmation for the same payment), tint the
   group so admin instantly sees they're not separate money. */
.linked-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-left: 0.4rem;
    padding: 0.1rem 0.45rem;
    background: #ddd6fe;
    color: #5b21b6;
    border-radius: 999px;
    font-size: 0.65rem;
    font-weight: 600;
    cursor: help;
}
.linked-pill i { font-size: 0.55rem; }

.mpesa-row.linked-tint-a td { background: #faf5ff; }
.mpesa-row.linked-tint-b td { background: #fefce8; }
.mpesa-row.linked-tint-c td { background: #ecfdf5; }
.mpesa-row.linked-tint-d td { background: #eff6ff; }
.mpesa-row.linked-tint-e td { background: #fdf2f8; }
.mpesa-row.linked-tint-a:hover td,
.mpesa-row.linked-tint-b:hover td,
.mpesa-row.linked-tint-c:hover td,
.mpesa-row.linked-tint-d:hover td,
.mpesa-row.linked-tint-e:hover td { filter: brightness(0.96); }
</style>
