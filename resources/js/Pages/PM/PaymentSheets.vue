<template>
    <PMLayout>
        <template #header>
            <h1>Technician Payment Sheets</h1>
            <div class="header-actions">
                <button class="btn btn-primary" @click="showCreateModal = true">
                    <i class="fas fa-plus"></i> Create Payment Sheet
                </button>
            </div>
        </template>

        <section class="main-panel">
            <div class="panel-card" v-for="sheet in sheets.data" :key="sheet.id" style="margin-bottom: 1.5rem;">
                <div class="card-header">
                    <div>
                        <h3>{{ sheet.sheet_reference }}</h3>
                        <span class="sub-text">
                            {{ sheet.period_start }} → {{ sheet.period_end }}
                            · Created by {{ sheet.creator?.name }}
                        </span>
                    </div>
                    <div style="display:flex;align-items:center;gap:1rem;">
                        <span :class="['status', sheet.status === 'finalized' ? 'completed' : 'review']">
                            {{ sheet.status === 'finalized' ? 'Finalized' : 'Draft' }}
                        </span>
                        <strong style="font-size: 1.2rem;">KES {{ Number(sheet.total_amount).toLocaleString() }}</strong>
                    </div>
                </div>

                <!-- Entries Table -->
                <table class="data-table" v-if="sheet.entries?.length">
                    <thead>
                        <tr>
                            <th>Technician</th>
                            <th>Job Reference</th>
                            <th>Agreed Comp.</th>
                            <th>Cum. Progress</th>
                            <th>Cum. Due</th>
                            <th>Previously Paid</th>
                            <th>Current Payable</th>
                            <th>Status / Paid Detail</th>
                            <th v-if="sheet.status === 'finalized'">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in sheet.entries" :key="entry.id">
                            <td>{{ entry.technician?.user?.name }}</td>
                            <td>{{ entry.service_request?.job_reference || entry.service_request?.request_id }}</td>
                            <td>KES {{ Number(entry.agreed_compensation).toLocaleString() }}</td>
                            <td>
                                <div class="progress-bar" style="width: 60px;">
                                    <div class="progress" :style="{ width: entry.cumulative_progress_pct + '%' }"></div>
                                </div>
                                {{ entry.cumulative_progress_pct }}%
                            </td>
                            <td>KES {{ Number(entry.cumulative_amount_due).toLocaleString() }}</td>
                            <td>KES {{ Number(entry.previous_cumulative_paid).toLocaleString() }}</td>
                            <td><strong style="color: #059669;">KES {{ Number(entry.current_period_payable).toLocaleString() }}</strong></td>
                            <td>
                                <span :class="['status', entry.status === 'paid' ? 'completed' : 'review']">{{ entry.status }}</span>
                                <div v-if="entry.status === 'paid'" style="margin-top:0.4rem;font-size:0.8rem;color:#374151;line-height:1.35;">
                                    <div><strong>Paid:</strong> KES {{ Number(entry.paid_amount).toLocaleString() }}</div>
                                    <div v-if="entry.paid_method"><strong>Method:</strong> {{ entry.paid_method }}</div>
                                    <div v-if="entry.paid_reference"><strong>Ref:</strong> {{ entry.paid_reference }}</div>
                                    <div v-if="entry.paid_at" style="color:#6b7280;">{{ new Date(entry.paid_at).toLocaleDateString() }}</div>
                                    <div v-if="entry.paid_by?.name" style="color:#6b7280;">by {{ entry.paid_by.name }}</div>
                                </div>
                            </td>
                            <td v-if="sheet.status === 'finalized'">
                                <button
                                    v-if="entry.status !== 'paid'"
                                    class="btn btn-primary btn-xs"
                                    @click="openMarkPaid(entry)"
                                    title="Record actual cash-out for this entry"
                                >
                                    <i class="fas fa-check-circle"></i> Mark Paid
                                </button>
                                <button
                                    v-else
                                    class="btn btn-secondary btn-xs"
                                    @click="unmarkPaid(entry)"
                                    title="Reverse the mark-paid (audit-logged)"
                                >
                                    <i class="fas fa-rotate-left"></i> Unmark
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 700; background: #f9fafb;">
                            <td colspan="6" style="text-align: right;">Total Payable:</td>
                            <td :colspan="sheet.status === 'finalized' ? 3 : 2">KES {{ Number(sheet.total_amount).toLocaleString() }}</td>
                        </tr>
                    </tfoot>
                </table>

                <div v-else class="empty-state" style="padding: 1.5rem;">
                    <p>No entries in this sheet. Run auto-compute to populate.</p>
                </div>

                <!-- Sheet Actions -->
                <div class="card-footer" v-if="sheet.status === 'draft'">
                    <button class="btn btn-primary btn-sm" @click="finalizeSheet(sheet)">
                        <i class="fas fa-lock"></i> Finalize Sheet
                    </button>
                </div>
            </div>

            <div v-if="!sheets.data?.length" class="empty-state">
                <i class="fas fa-file-invoice-dollar"></i>
                <p>No payment sheets yet. Click "Create Payment Sheet" to get started.</p>
            </div>
        </section>

        <!-- Item 3c — Mark Paid Modal. Captures the ACTUAL amount released
             (may differ from Current Payable), method, reference, and any
             notes. Answers the client's question: 'how do we tell the system
             we've already spent money on this'. -->
        <div v-if="markPaidEntry" class="modal-overlay">
            <div class="modal-content" style="max-width: 520px;">
                <div class="modal-header">
                    <h3>Mark Entry as Paid</h3>
                    <button class="modal-close" @click="closeMarkPaid">&times;</button>
                </div>
                <div class="modal-body">
                    <p style="margin-bottom:1rem;color:#4b5563;">
                        {{ markPaidEntry.technician?.user?.name }} · {{ markPaidEntry.service_request?.job_reference || markPaidEntry.service_request?.request_id }}
                    </p>
                    <div class="form-group">
                        <label>Actual Amount Paid (KES) <span style="color:#dc2626;">*</span></label>
                        <input type="number" step="0.01" v-model="markPaidForm.paid_amount" min="0" required>
                        <small style="color:#6b7280;">Scheduled: KES {{ Number(markPaidEntry.current_period_payable).toLocaleString() }}. Adjust if a different amount was actually paid.</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Payment Date</label>
                            <input type="date" v-model="markPaidForm.paid_at">
                        </div>
                        <div class="form-group">
                            <label>Method</label>
                            <select v-model="markPaidForm.paid_method">
                                <option value="">Select method</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="cash">Cash</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Reference / Transaction ID</label>
                        <input type="text" v-model="markPaidForm.paid_reference" placeholder="e.g. M-Pesa code, cheque no., bank ref">
                    </div>
                    <div class="form-group">
                        <label>Notes (optional)</label>
                        <textarea v-model="markPaidForm.paid_notes" rows="2" placeholder="Any context worth capturing..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="closeMarkPaid">Cancel</button>
                    <button class="btn btn-primary" @click="submitMarkPaid" :disabled="markPaidSubmitting || !markPaidForm.paid_amount">
                        <i class="fas fa-check-circle"></i> {{ markPaidSubmitting ? 'Saving...' : 'Confirm Paid' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Create Sheet Modal -->
        <div v-if="showCreateModal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Create Weekly Payment Sheet</h3>
                    <button class="modal-close" @click="showCreateModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="createSheet">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Period Start (Monday)</label>
                                <input type="date" v-model="createForm.period_start" required>
                            </div>
                            <div class="form-group">
                                <label>Period End (Sunday)</label>
                                <input type="date" v-model="createForm.period_end" required>
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label>Notes (optional)</label>
                            <textarea v-model="createForm.notes" rows="2" placeholder="Any notes about this payment period..."></textarea>
                        </div>
                        <div class="panel-card" style="background: #eff6ff; margin-top: 1rem;">
                            <p style="margin:0;font-size:0.9rem;color:#1e40af;">
                                <i class="fas fa-info-circle"></i>
                                The system will auto-compute payment entries based on validated progress reports and agreed compensation for all active job assignments.
                            </p>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showCreateModal = false">Cancel</button>
                    <button class="btn btn-primary" @click="createSheet" :disabled="submitting">
                        <i class="fas fa-calculator"></i> {{ submitting ? 'Computing...' : 'Create & Auto-Compute' }}
                    </button>
                </div>
            </div>
        </div>
    </PMLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import PMLayout from '../../Layouts/PMLayout.vue';

const props = defineProps({
    sheets: { type: Object, default: () => ({ data: [] }) },
});

const showCreateModal = ref(false);
const submitting = ref(false);

const createForm = ref({
    period_start: '',
    period_end: '',
    notes: '',
});

const createSheet = () => {
    submitting.value = true;
    router.post('/pm/payment-sheets', createForm.value, {
        onSuccess: () => { showCreateModal.value = false; submitting.value = false; },
        onError: () => { submitting.value = false; },
    });
};

const finalizeSheet = (sheet) => {
    if (confirm('Finalize this payment sheet? This action cannot be undone.')) {
        router.post(`/pm/payment-sheets/${sheet.id}/finalize`);
    }
};

// Item 3c — Mark Paid modal state + handlers.
const markPaidEntry = ref(null);
const markPaidSubmitting = ref(false);
const markPaidForm = ref({
    paid_amount: '',
    paid_at: new Date().toISOString().slice(0, 10),
    paid_method: '',
    paid_reference: '',
    paid_notes: '',
});

const openMarkPaid = (entry) => {
    markPaidEntry.value = entry;
    markPaidForm.value = {
        // Prefill with the scheduled amount so the common case (paid what
        // the schedule said) is one click. Ops can adjust for partials.
        paid_amount: entry.current_period_payable,
        paid_at: new Date().toISOString().slice(0, 10),
        paid_method: '',
        paid_reference: '',
        paid_notes: '',
    };
};

const closeMarkPaid = () => {
    markPaidEntry.value = null;
    markPaidSubmitting.value = false;
};

const submitMarkPaid = () => {
    if (!markPaidEntry.value || markPaidSubmitting.value) return;
    markPaidSubmitting.value = true;
    router.post(`/pm/payment-entries/${markPaidEntry.value.id}/mark-paid`, markPaidForm.value, {
        preserveScroll: true,
        onSuccess: () => { closeMarkPaid(); },
        onError: () => { markPaidSubmitting.value = false; },
    });
};

const unmarkPaid = (entry) => {
    const reason = prompt('Reason for reversing the mark-paid? (optional, but recommended)') || '';
    if (reason === null) return; // user hit cancel
    router.post(`/pm/payment-entries/${entry.id}/unmark-paid`, { reason }, {
        preserveScroll: true,
    });
};

defineOptions({ layout: null });
</script>

<style scoped>
.empty-state { text-align: center; padding: 3rem; color: #9CA3AF; }
.empty-state i { font-size: 2.5rem; margin-bottom: 1rem; display: block; }
</style>
