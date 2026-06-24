<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="payment-processing" />

        <main class="main-content pp-content">

            <!-- Header -->
            <header class="pp-hero">
                <div class="pp-hero-copy">
                    <span class="section-kicker">Finance</span>
                    <h1>Process Technician Payments</h1>
                    <p>Select job references, choose technicians, review auto-populated amounts, then finalize to generate a payment sheet.</p>
                </div>
                <div class="pp-hero-actions">
                    <button
                        v-if="currentView === 'list'"
                        class="btn btn-primary"
                        @click="startNewSheet"
                    >
                        <i class="fas fa-plus"></i> New Payment Sheet
                    </button>
                    <button
                        v-else
                        class="btn btn-secondary"
                        @click="currentView = 'list'"
                    >
                        <i class="fas fa-arrow-left"></i> Back to History
                    </button>
                </div>
            </header>

            <!-- ===== CREATE / EDIT FORM ===== -->
            <template v-if="currentView === 'create'">

                <!-- Period Info -->
                <section class="panel-section">
                    <div class="panel-card">
                        <div class="card-header-row">
                            <div>
                                <span class="section-kicker">Step 1</span>
                                <h3>Payment Period</h3>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Period Start *</label>
                                <input type="date" v-model="form.period_start" required />
                            </div>
                            <div class="form-group">
                                <label>Period End *</label>
                                <input type="date" v-model="form.period_end" required />
                            </div>
                            <div class="form-group full-width">
                                <label>Notes (optional)</label>
                                <textarea v-model="form.notes" rows="2" placeholder="e.g. Week 17 payments — 21 Apr to 25 Apr"></textarea>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Entries Table -->
                <section class="panel-section">
                    <div class="panel-card">
                        <div class="card-header-row">
                            <div>
                                <span class="section-kicker">Step 2</span>
                                <h3>Payment Entries</h3>
                                <p>Add one row per technician–job combination. The system will auto-populate amounts when you select a technician.</p>
                            </div>
                            <div style="display:flex;gap:.5rem;">
                                <button
                                    class="btn btn-secondary btn-sm"
                                    @click="autoCompute"
                                    :disabled="!form.period_start || !form.period_end || autoComputing"
                                    title="Auto-fill all eligible technicians with validated progress in this period"
                                >
                                    <i class="fas fa-magic"></i>
                                    {{ autoComputing ? 'Computing…' : 'Auto-Compute Entries' }}
                                </button>
                                <button class="btn btn-primary btn-sm" @click="addRow">
                                    <i class="fas fa-plus"></i> Add Row
                                </button>
                            </div>
                        </div>

                        <div v-if="form.entries.length === 0" class="empty-hint">
                            <i class="fas fa-plus-circle"></i>
                            <p>Click <strong>Add Row</strong> to begin adding payment entries.</p>
                        </div>

                        <div v-else class="entries-wrapper">
                            <table class="data-table entries-table">
                                <thead>
                                    <tr>
                                        <th style="width:2.5rem;">#</th>
                                        <th>Job Reference</th>
                                        <th>Technician</th>
                                        <th>Total Payable (KES)</th>
                                        <th>Cum. Progress</th>
                                        <th>Cum. Due (KES)</th>
                                        <th>Prev. Paid (KES)</th>
                                        <th class="highlight-col">Payable This Period (KES)</th>
                                        <th style="width:2.5rem;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in form.entries" :key="row._key" :class="{ 'loading-row': row._loading }">
                                        <td class="row-num">{{ idx + 1 }}</td>

                                        <!-- Job Reference -->
                                        <td>
                                            <select
                                                v-model="row.service_request_id"
                                                @change="onJobChange(row)"
                                                class="cell-select"
                                                :disabled="row._loading"
                                            >
                                                <option value="">Select job…</option>
                                                <option
                                                    v-for="sr in serviceRequests"
                                                    :key="sr.id"
                                                    :value="sr.id"
                                                >
                                                    {{ sr.job_reference || sr.request_id }}{{ sr.description ? ' — ' + truncate(sr.description, 30) : '' }}
                                                </option>
                                            </select>
                                        </td>

                                        <!-- Technician (filtered by job) -->
                                        <td>
                                            <select
                                                v-model="row.technician_id"
                                                @change="onTechChange(row)"
                                                class="cell-select"
                                                :disabled="!row.service_request_id || row._loading"
                                            >
                                                <option value="">
                                                    {{ row.service_request_id ? 'Select technician…' : '— select job first —' }}
                                                </option>
                                                <option
                                                    v-for="t in row._technicians"
                                                    :key="t.id"
                                                    :value="t.id"
                                                >
                                                    {{ t.name }} ({{ t.technician_id }})
                                                </option>
                                            </select>
                                        </td>

                                        <!-- Total Payable / Technician Dues (locked to the value from
                                             JobAssignment.agreed_compensation — #29 / #30. To change,
                                             go via Job Management or Assign Technician). -->
                                        <td>
                                            <input
                                                type="number"
                                                v-model="row.approved_amount"
                                                min="0"
                                                step="0.01"
                                                class="cell-input readonly-input"
                                                placeholder="0.00"
                                                readonly
                                                :title="row.approved_amount > 0
                                                    ? 'Locked — sourced from this technician\'s job assignment. Edit the assignment to change.'
                                                    : 'No allocation found on the job assignment. Set agreed compensation on the assignment first.'"
                                            />
                                        </td>

                                        <!-- Cumulative Progress (auto-fetched, editable to override) -->
                                        <td>
                                            <div class="progress-cell">
                                                <input
                                                    type="number"
                                                    v-model="row.cumulative_progress_pct"
                                                    min="0"
                                                    max="100"
                                                    class="cell-input pct-input"
                                                    @input="recalcRow(row)"
                                                />
                                                <span class="pct-sign">%</span>
                                            </div>
                                        </td>

                                        <!-- Cumulative Due = approved_amount × progress% (read-only) -->
                                        <td class="readonly-cell">
                                            {{ formatCurrency(row.cumulative_amount_due) }}
                                        </td>

                                        <!-- Previous Paid -->
                                        <td class="readonly-cell muted">
                                            {{ formatCurrency(row.previous_cumulative_paid) }}
                                        </td>

                                        <!-- Current Payable (editable override) -->
                                        <td class="highlight-col">
                                            <input
                                                type="number"
                                                v-model="row.current_period_payable"
                                                min="0"
                                                step="0.01"
                                                class="cell-input payable-input"
                                                placeholder="0.00"
                                            />
                                        </td>

                                        <!-- Remove -->
                                        <td>
                                            <button class="remove-btn" @click="removeRow(idx)" title="Remove row">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="total-row">
                                        <td colspan="7" class="total-label">Total Payable This Period:</td>
                                        <td class="total-value highlight-col">
                                            KES {{ formatCurrency(grandTotal) }}
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Error messages -->
                        <div v-if="errors.length" class="error-list">
                            <p v-for="(e, i) in errors" :key="i" class="error-item">
                                <i class="fas fa-exclamation-circle"></i> {{ e }}
                            </p>
                        </div>
                    </div>
                </section>

                <!-- Footer Actions -->
                <section class="panel-section">
                    <div class="action-footer">
                        <div class="action-footer-info">
                            <span><strong>{{ form.entries.length }}</strong> entr{{ form.entries.length === 1 ? 'y' : 'ies' }}</span>
                            <span class="bullet">·</span>
                            <span>Total: <strong>KES {{ formatCurrency(grandTotal) }}</strong></span>
                            <span v-if="form.period_start && form.period_end" class="bullet">·</span>
                            <span v-if="form.period_start && form.period_end">
                                {{ formatDate(form.period_start) }} → {{ formatDate(form.period_end) }}
                            </span>
                        </div>
                        <div class="action-footer-btns">
                            <button class="btn btn-secondary" @click="currentView = 'list'">
                                Cancel
                            </button>
                            <button
                                class="btn btn-primary"
                                @click="submitSheet"
                                :disabled="submitting || form.entries.length === 0"
                            >
                                <i class="fas fa-check-circle"></i>
                                {{ submitting ? 'Processing…' : 'Finalize & Process All Payments' }}
                            </button>
                        </div>
                    </div>
                </section>
            </template>

            <!-- ===== HISTORY LIST ===== -->
            <template v-else>

                <!-- View single sheet detail (opened from history) -->
                <template v-if="viewSheet">
                    <section class="panel-section">
                        <div class="panel-card">
                            <div class="card-header-row">
                                <div>
                                    <span class="section-kicker">Sheet Detail</span>
                                    <h3>{{ viewSheet.sheet_reference }}</h3>
                                    <p>
                                        {{ formatDate(viewSheet.period_start) }} → {{ formatDate(viewSheet.period_end) }}
                                        &nbsp;·&nbsp;
                                        Created by {{ viewSheet.creator?.name }}
                                    </p>
                                </div>
                                <div class="header-actions">
                                    <span :class="['status', viewSheet.status === 'finalized' ? 'approved' : 'pending']">
                                        {{ viewSheet.status === 'finalized' ? 'Finalized' : 'Draft' }}
                                    </span>
                                    <a
                                        :href="`/admin/payment-processing/${viewSheet.id}/download`"
                                        class="btn btn-primary btn-sm"
                                        target="_blank"
                                    >
                                        <i class="fas fa-download"></i> Download PDF
                                    </a>
                                    <button class="btn btn-secondary btn-sm" @click="viewSheet = null">
                                        <i class="fas fa-arrow-left"></i> Back to List
                                    </button>
                                </div>
                            </div>

                            <div class="table-scroll">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Technician</th>
                                        <th>Job Reference</th>
                                        <th>Total Payable</th>
                                        <th>Cum. Progress</th>
                                        <th>Cum. Due</th>
                                        <th>Prev. Paid</th>
                                        <th>Payable This Period</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(entry, i) in viewSheet.entries" :key="entry.id">
                                        <td>{{ i + 1 }}</td>
                                        <td>{{ entry.technician?.user?.name || '—' }}</td>
                                        <td>{{ entry.service_request?.job_reference || entry.service_request?.request_id || '—' }}</td>
                                        <td>KES {{ formatCurrency(entry.agreed_compensation) }}</td><!-- stored as agreed_compensation in DB -->
                                        <td>{{ entry.cumulative_progress_pct }}%</td>
                                        <td>KES {{ formatCurrency(entry.cumulative_amount_due) }}</td>
                                        <td class="muted-text">KES {{ formatCurrency(entry.previous_cumulative_paid) }}</td>
                                        <td><strong class="payable-highlight">KES {{ formatCurrency(entry.current_period_payable) }}</strong></td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="total-row">
                                        <td colspan="7" class="total-label">Total Payable:</td>
                                        <td><strong>KES {{ formatCurrency(viewSheet.total_amount) }}</strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                            </div>

                            <div v-if="viewSheet.notes" class="sheet-notes">
                                <i class="fas fa-sticky-note"></i>
                                {{ viewSheet.notes }}
                            </div>
                        </div>
                    </section>
                </template>

                <!-- History list -->
                <section class="panel-section">
                    <div class="panel-card">
                        <div class="card-header-row">
                            <div>
                                <span class="section-kicker">History</span>
                                <h3>Payment Sheets</h3>
                                <p>All payment sheets ever processed. Click any sheet to inspect its details or download it.</p>
                            </div>
                        </div>

                        <div v-if="sheets.data?.length" class="table-shell">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Reference</th>
                                        <th>Period</th>
                                        <th>Status</th>
                                        <th>Entries</th>
                                        <th>Total Payable</th>
                                        <th>Created By</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="sheet in sheets.data"
                                        :key="sheet.id"
                                        class="clickable-row"
                                        @click="openSheet(sheet)"
                                    >
                                        <td><strong>{{ sheet.sheet_reference }}</strong></td>
                                        <td>{{ formatDate(sheet.period_start) }} → {{ formatDate(sheet.period_end) }}</td>
                                        <td>
                                            <span :class="['status', sheet.status === 'finalized' ? 'approved' : 'pending']">
                                                {{ sheet.status === 'finalized' ? 'Finalized' : 'Draft' }}
                                            </span>
                                        </td>
                                        <td>{{ sheet.entries?.length ?? 0 }} technician{{ (sheet.entries?.length ?? 0) !== 1 ? 's' : '' }}</td>
                                        <td><strong>KES {{ formatCurrency(sheet.total_amount) }}</strong></td>
                                        <td>{{ sheet.creator?.name || '—' }}</td>
                                        <td>{{ formatDate(sheet.created_at) }}</td>
                                        <td>
                                            <div class="row-actions" @click.stop>
                                                <button class="btn btn-sm btn-info" @click="openSheet(sheet)" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <a
                                                    :href="`/admin/payment-processing/${sheet.id}/download`"
                                                    class="btn btn-sm btn-primary"
                                                    target="_blank"
                                                    title="Download PDF"
                                                >
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div v-else class="empty-state">
                            <i class="fas fa-file-invoice-dollar"></i>
                            <p>No payment sheets yet. Click <strong>New Payment Sheet</strong> to get started.</p>
                        </div>

                        <!-- Pagination -->
                        <div v-if="sheets.last_page > 1" class="pagination">
                            <a
                                v-for="page in sheets.last_page"
                                :key="page"
                                :href="`/admin/payment-processing?page=${page}`"
                                :class="['page-btn', { active: page === sheets.current_page }]"
                            >
                                {{ page }}
                            </a>
                        </div>
                    </div>
                </section>
            </template>

        </main>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

defineOptions({ layout: null })

const props = defineProps({
    sheets: { type: Object, default: () => ({ data: [], last_page: 1, current_page: 1 }) },
    serviceRequests: { type: Array, default: () => [] },
    viewSheet: { type: Object, default: null },
})

const currentView = ref(props.viewSheet ? 'list' : 'list')
const viewSheet = ref(props.viewSheet || null)
const submitting = ref(false)
const errors = ref([])

const form = ref({
    period_start: '',
    period_end: '',
    notes: '',
    entries: [],
})

let rowKey = 0

const blankRow = () => ({
    _key: ++rowKey,
    _loading: false,
    _technicians: [],
    service_request_id: '',
    technician_id: '',
    approved_amount: 0,
    cumulative_progress_pct: 0,
    cumulative_amount_due: 0,
    previous_cumulative_paid: 0,
    current_period_payable: '',
})

const addRow = () => {
    form.value.entries.push(blankRow())
}

const autoComputing = ref(false)
const autoCompute = async () => {
    if (!form.value.period_start || !form.value.period_end) return
    if (form.value.entries.length > 0) {
        if (!confirm(`You already have ${form.value.entries.length} entr${form.value.entries.length === 1 ? 'y' : 'ies'}. Auto-compute will replace them. Continue?`)) return
    }
    autoComputing.value = true
    try {
        const { data } = await axios.get('/admin/payment-processing/api/auto-compute-entries', {
            params: {
                period_start: form.value.period_start,
                period_end: form.value.period_end,
            },
        })
        if (data.count === 0) {
            // Use the backend's diagnostic breakdown so admin understands
            // WHY zero rows came back. Common causes: assignment missing
            // agreed_compensation, or no validated progress yet.
            const s = data.skipped || {}
            const reasons = []
            if (s.no_agreed_amount > 0) reasons.push(`${s.no_agreed_amount} assignment${s.no_agreed_amount === 1 ? '' : 's'} missing agreed compensation`)
            if (s.no_validated_progress > 0) reasons.push(`${s.no_validated_progress} assignment${s.no_validated_progress === 1 ? '' : 's'} have no validated progress yet`)
            if (s.already_fully_paid > 0) reasons.push(`${s.already_fully_paid} technician${s.already_fully_paid === 1 ? '' : 's'} already fully paid`)

            if (reasons.length > 0) {
                alert(
                    'No payable technicians found.\n\nDetails:\n• ' + reasons.join('\n• ') +
                    '\n\nFix: set agreed compensation on the assignment, or validate the technician\'s progress reports first.'
                )
            } else {
                alert('No eligible technicians with validated progress and unpaid balances in this period.\n\nThis usually means there are no active job assignments at all.')
            }
            return
        }
        form.value.entries = data.entries.map(e => ({
            ...blankRow(),
            service_request_id: e.service_request_id,
            technician_id: e.technician_id,
            approved_amount: e.approved_amount,
            cumulative_progress_pct: e.cumulative_progress_pct,
            cumulative_amount_due: e.cumulative_amount_due,
            previous_cumulative_paid: e.previous_cumulative_paid,
            current_period_payable: e.current_period_payable,
            _label_sr: e.service_request_label,
            _label_tech: e.technician_label,
        }))
    } catch (e) {
        alert(e.response?.data?.message || 'Auto-compute failed.')
    } finally {
        autoComputing.value = false
    }
}

const removeRow = (idx) => {
    form.value.entries.splice(idx, 1)
}

const startNewSheet = () => {
    form.value = { period_start: '', period_end: '', notes: '', entries: [] }
    errors.value = []
    currentView.value = 'create'
}

const openSheet = (sheet) => {
    viewSheet.value = sheet
}

// When job changes: fetch filtered technicians, reset technician + amounts
const onJobChange = async (row) => {
    row.technician_id = ''
    row._technicians = []
    row.approved_amount = 0
    row.cumulative_progress_pct = 0
    row.cumulative_amount_due = 0
    row.previous_cumulative_paid = 0
    row.current_period_payable = ''

    if (!row.service_request_id) return

    row._loading = true
    try {
        const { data } = await axios.get('/admin/payment-processing/api/technicians-for-job', {
            params: { service_request_id: row.service_request_id },
        })
        row._technicians = data
    } catch {
        row._technicians = []
    } finally {
        row._loading = false
    }
}

// When technician changes: fetch auto-computed amounts
const onTechChange = async (row) => {
    if (!row.service_request_id || !row.technician_id) return

    row._loading = true
    try {
        const { data } = await axios.get('/admin/payment-processing/api/compute-amounts', {
            params: {
                service_request_id: row.service_request_id,
                technician_id: row.technician_id,
            },
        })
        row.approved_amount = data.approved_amount
        row.cumulative_progress_pct = data.cumulative_progress_pct
        row.cumulative_amount_due = data.cumulative_amount_due
        row.previous_cumulative_paid = data.previous_cumulative_paid
        row.current_period_payable = data.current_period_payable
    } catch {
        // leave as-is
    } finally {
        row._loading = false
    }
}

// When progress % is manually changed, recompute cumulative_due and current payable
// cumulative_due = approved_amount × (progress / 100)
const recalcRow = (row) => {
    if (row.approved_amount > 0 && row.cumulative_progress_pct !== '') {
        const due = parseFloat(row.approved_amount) * (parseFloat(row.cumulative_progress_pct) / 100)
        row.cumulative_amount_due = Math.round(due * 100) / 100
        row.current_period_payable = Math.max(0, Math.round((row.cumulative_amount_due - parseFloat(row.previous_cumulative_paid || 0)) * 100) / 100)
    }
}

const grandTotal = computed(() =>
    form.value.entries.reduce((sum, row) => sum + (parseFloat(row.current_period_payable) || 0), 0)
)

const submitSheet = () => {
    errors.value = []

    if (!form.value.period_start || !form.value.period_end) {
        errors.value.push('Period start and end dates are required.')
        return
    }
    if (form.value.entries.length === 0) {
        errors.value.push('Please add at least one payment entry.')
        return
    }
    for (let i = 0; i < form.value.entries.length; i++) {
        const row = form.value.entries[i]
        if (!row.service_request_id) {
            errors.value.push(`Row ${i + 1}: Job reference is required.`)
        }
        if (!row.technician_id) {
            errors.value.push(`Row ${i + 1}: Technician is required.`)
        }
        if (row.current_period_payable === '' || row.current_period_payable === null) {
            errors.value.push(`Row ${i + 1}: Payable amount is required.`)
        }

        // Overpayment guard (client-side preview)
        // Only cap against agreed compensation. Validated progress is for
        // information only — admin can pay any cash-flow amount within the
        // agreed total.
        const agreed = parseFloat(row.approved_amount) || 0
        const previousPaid = parseFloat(row.previous_cumulative_paid) || 0
        const payable = parseFloat(row.current_period_payable) || 0

        if (agreed > 0 && (previousPaid + payable) > agreed + 0.001) {
            errors.value.push(
                `Row ${i + 1}: Payment of KES ${formatCurrency(payable)} would bring total paid to KES ${formatCurrency(previousPaid + payable)}, exceeding the agreed compensation of KES ${formatCurrency(agreed)}.`
            )
        }
    }
    if (errors.value.length) return

    submitting.value = true

    router.post('/admin/payment-processing', {
        period_start: form.value.period_start,
        period_end: form.value.period_end,
        notes: form.value.notes,
        finalize: true,
        entries: form.value.entries.map(row => ({
            service_request_id: row.service_request_id,
            technician_id: row.technician_id,
            approved_amount: parseFloat(row.approved_amount) || 0,
            cumulative_progress_pct: parseInt(row.cumulative_progress_pct) || 0,
            cumulative_amount_due: parseFloat(row.cumulative_amount_due) || 0,
            previous_cumulative_paid: parseFloat(row.previous_cumulative_paid) || 0,
            current_period_payable: parseFloat(row.current_period_payable) || 0,
        })),
    }, {
        onSuccess: () => {
            submitting.value = false
            currentView.value = 'list'
        },
        onError: (errs) => {
            submitting.value = false
            errors.value = Object.values(errs).flat()
        },
    })
}

const formatCurrency = (val) => {
    const n = parseFloat(val) || 0
    return n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const formatDate = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' })
}

const truncate = (text, len) => {
    if (!text) return ''
    return text.length > len ? text.slice(0, len) + '…' : text
}
</script>

<style scoped>

.pp-content {
    background: linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.pp-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.5rem;
    margin-bottom: 1.75rem;
    padding: 2rem;
    border-radius: 26px;
    background: linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92));
    color: #f8fafc;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.pp-hero-copy h1 {
    margin: 0.6rem 0 0;
    font-size: clamp(1.6rem, 2.5vw, 2.2rem);
    color: #fff;
}

.pp-hero-copy p {
    margin: 0.7rem 0 0;
    color: rgba(226, 232, 240, 0.88);
    max-width: 44rem;
    line-height: 1.6;
}

.pp-hero-actions {
    flex-shrink: 0;
    padding-top: 0.25rem;
}

.section-kicker {
    display: inline-flex;
    align-items: center;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
    color: rgba(226, 232, 240, 0.75);
}

.panel-section {
    margin-bottom: 1.25rem;
}

.panel-card {
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
    border-radius: 24px;
    padding: 1.4rem;
}

.card-header-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.card-header-row .section-kicker {
    color: #0f6c8f;
}

.card-header-row h3 {
    margin: 0.3rem 0 0;
    color: #0f172a;
    font-size: 1.1rem;
}

.card-header-row p {
    margin: 0.3rem 0 0;
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.5;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    font-size: 0.84rem;
    font-weight: 600;
    color: #334155;
}

.form-group input,
.form-group textarea,
.form-group select {
    padding: 0.75rem 0.9rem;
    border: 1px solid #d7dee7;
    border-radius: 14px;
    background: #f8fafc;
    color: #0f172a;
    font-size: 0.9rem;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.45);
    box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12);
    background: #fff;
}

/* Entries table */
.entries-wrapper {
    overflow-x: auto;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
}

.entries-table {
    min-width: 900px;
}

.entries-table th,
.entries-table td {
    padding: 0.75rem 0.8rem;
    vertical-align: middle;
    font-size: 0.87rem;
    border-bottom: 1px solid #edf2f7;
}

.entries-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    white-space: nowrap;
}

.highlight-col {
    background: rgba(224, 242, 254, 0.35);
}

.entries-table thead th.highlight-col {
    background: #e0f2fe;
    color: #0369a1;
}

.loading-row {
    opacity: 0.6;
    pointer-events: none;
}

.row-num {
    color: #94a3b8;
    font-size: 0.8rem;
    font-weight: 700;
}

.cell-select,
.cell-input {
    width: 100%;
    padding: 0.5rem 0.6rem;
    border: 1px solid #d7dee7;
    border-radius: 10px;
    background: #f8fafc;
    color: #0f172a;
    font-size: 0.87rem;
}

.cell-select:focus,
.cell-input:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.5);
    box-shadow: 0 0 0 3px rgba(14, 116, 144, 0.1);
    background: #fff;
}

.cell-input.readonly-input,
.cell-input[readonly] {
    background: #F1F5F9;
    color: #475569;
    cursor: not-allowed;
    border-color: #E2E8F0;
    font-weight: 600;
}
.cell-input.readonly-input:focus { box-shadow: none; }

.pct-input {
    width: 4rem;
}

.payable-input {
    font-weight: 700;
    color: #065f46;
    background: #f0fdf4;
    border-color: #86efac;
}

.payable-input:focus {
    background: #fff;
}

.progress-cell {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.pct-sign {
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 600;
}

.readonly-cell {
    color: #475569;
    font-size: 0.87rem;
    white-space: nowrap;
}

.muted {
    color: #94a3b8 !important;
}

.remove-btn {
    width: 2rem;
    height: 2rem;
    border: none;
    border-radius: 8px;
    background: #fee2e2;
    color: #dc2626;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.remove-btn:hover {
    background: #fecaca;
}

.total-row td {
    background: #f8fafc;
    font-weight: 700;
    padding: 0.9rem 0.8rem;
    border-top: 2px solid #e2e8f0;
}

.total-label {
    text-align: right;
    color: #475569;
}

.total-value {
    font-size: 1rem;
    color: #065f46;
    white-space: nowrap;
}

.empty-hint {
    text-align: center;
    padding: 3rem 1.5rem;
    color: #94a3b8;
}

.empty-hint i {
    display: block;
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    opacity: 0.4;
}

.error-list {
    margin-top: 1rem;
    padding: 0.85rem 1rem;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-radius: 14px;
}

.error-item {
    color: #be123c;
    font-size: 0.87rem;
    margin: 0.2rem 0;
}

.action-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.1rem 1.4rem;
    background: rgba(255, 255, 255, 0.9);
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
}

.action-footer-info {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    color: #475569;
    font-size: 0.9rem;
}

.bullet {
    color: #cbd5e1;
    font-size: 1.1rem;
}

.action-footer-btns {
    display: flex;
    gap: 0.75rem;
    flex-shrink: 0;
}

/* History list */
.table-shell {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    background: #fff;
}

.table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    background: #fff;
    margin-top: 1rem;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 720px;
}

.data-table th,
.data-table td {
    padding: 0.9rem 1rem;
    border-bottom: 1px solid #edf2f7;
    vertical-align: middle;
    font-size: 0.88rem;
}

.data-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.data-table tbody tr:hover {
    background: rgba(248, 250, 252, 0.8);
}

.clickable-row {
    cursor: pointer;
}

.row-actions {
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.empty-state {
    text-align: center;
    padding: 3rem 1.5rem;
    color: #94a3b8;
}

.empty-state i {
    display: block;
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    opacity: 0.4;
}

.status {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: capitalize;
    white-space: nowrap;
}

.status.approved {
    background: #dcfce7;
    color: #166534;
}

.status.pending {
    background: #fef3c7;
    color: #92400e;
}

.payable-highlight {
    color: #065f46;
}

.muted-text {
    color: #94a3b8;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.sheet-notes {
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border-radius: 12px;
    color: #475569;
    font-size: 0.88rem;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.pagination {
    display: flex;
    gap: 0.5rem;
    justify-content: center;
    padding: 1rem;
}

.page-btn {
    padding: 0.45rem 0.9rem;
    border-radius: 10px;
    border: 1px solid #d7dee7;
    background: #f8fafc;
    color: #475569;
    font-size: 0.87rem;
    font-weight: 600;
    text-decoration: none;
}

.page-btn.active {
    background: #0f6c8f;
    border-color: #0f6c8f;
    color: #fff;
}

@media (max-width: 900px) {
    .pp-hero,
    .action-footer {
        flex-direction: column;
        align-items: flex-start;
    }

    .form-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 640px) {
    .header-actions {
        flex-wrap: wrap;
        gap: 0.4rem;
    }

    .header-actions .btn {
        flex: 1 1 auto;
    }

    .entries-table th,
    .entries-table td,
    .data-table th,
    .data-table td {
        padding: 0.6rem 0.55rem;
        font-size: 0.8rem;
    }

    .cell-input,
    .cell-select,
    .payable-input {
        min-height: 38px;
    }
}
</style>
