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
                            <th>Status</th>
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
                            <td><span :class="['status', entry.status === 'paid' ? 'completed' : 'review']">{{ entry.status }}</span></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight: 700; background: #f9fafb;">
                            <td colspan="6" style="text-align: right;">Total Payable:</td>
                            <td colspan="2">KES {{ Number(sheet.total_amount).toLocaleString() }}</td>
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

defineOptions({ layout: null });
</script>

<style scoped>
.empty-state { text-align: center; padding: 3rem; color: #9CA3AF; }
.empty-state i { font-size: 2.5rem; margin-bottom: 1rem; display: block; }
</style>
