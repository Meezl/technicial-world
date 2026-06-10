<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <h1>My Earnings</h1>
        </header>

        <main class="pwa-content">
            <div class="pwa-card" style="margin-bottom: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <span style="display: inline-block; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--primary-color); margin-bottom: 0.35rem;">Compensation Snapshot</span>
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 1rem;">See what has been paid and what is still outstanding per job</h3>
                        <p style="margin: 0; font-size: 0.85rem; color: var(--light-text);">Use the date filter to narrow the payout history below without losing your all-time job balances.</p>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <input type="date" v-model="fromDate" class="pwa-input" />
                        <span style="color: var(--light-text); font-size: 0.9rem;">to</span>
                        <input type="date" v-model="toDate" class="pwa-input" />
                        <button @click="applyFilters" class="pwa-btn-primary">Filter</button>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.2rem; color: var(--primary-color);">{{ formatCurrency(earnings.total_agreed) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Total Agreed</p>
                </div>
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.2rem; color: var(--success-color);">{{ formatCurrency(earnings.total_paid) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Paid So Far</p>
                </div>
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.2rem; color: var(--warning-color);">{{ formatCurrency(earnings.total_outstanding) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Still Owed</p>
                </div>
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.2rem; color: var(--primary-color);">{{ formatCurrency(earnings.paid_in_period) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Paid In Filtered Period</p>
                </div>
            </div>

            <h2 style="font-size: 1rem; color: var(--light-text); margin-bottom: 0.5rem; padding-left: 0.5rem;">Per Job Breakdown</h2>

            <div v-if="earnings.by_job && earnings.by_job.length > 0">
                <div v-for="job in earnings.by_job" :key="job.service_request_id" class="pwa-card">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.75rem;">
                        <div>
                            <h3 style="margin: 0 0 0.25rem 0; font-size: 1rem;">{{ job.job_reference }}</h3>
                            <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">
                                {{ job.service_name }} • {{ job.client_name }}
                            </p>
                        </div>
                        <span style="font-size: 0.78rem; font-weight: 700; color: var(--primary-color); background: #EFF6FF; padding: 0.35rem 0.55rem; border-radius: 999px;">
                            {{ formatStatus(job.status) }}
                        </span>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem;">
                        <div style="background: var(--bg-light, #f8f9fa); padding: 0.5rem; border-radius: 6px;">
                            <span style="color: var(--light-text); display: block; font-size: 0.75rem;">Agreed</span>
                            <strong>{{ formatCurrency(job.agreed_compensation) }}</strong>
                        </div>
                        <div style="background: #ECFDF5; padding: 0.5rem; border-radius: 6px;">
                            <span style="color: var(--light-text); display: block; font-size: 0.75rem;">Paid So Far</span>
                            <strong style="color: var(--success-color);">{{ formatCurrency(job.paid_to_date) }}</strong>
                        </div>
                        <div style="background: #FFF7ED; padding: 0.5rem; border-radius: 6px;">
                            <span style="color: var(--light-text); display: block; font-size: 0.75rem;">Still Owed</span>
                            <strong style="color: #c2410c;">{{ formatCurrency(job.outstanding_balance) }}</strong>
                        </div>
                        <div style="background: var(--bg-light, #f8f9fa); padding: 0.5rem; border-radius: 6px;">
                            <span style="color: var(--light-text); display: block; font-size: 0.75rem;">Latest Approved Due</span>
                            <strong>{{ formatCurrency(job.latest_cumulative_due) }}</strong>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: space-between; gap: 0.75rem; align-items: center; margin-top: 0.85rem; flex-wrap: wrap;">
                        <div style="font-size: 0.8rem; color: var(--light-text);">
                            <span v-if="job.latest_progress_pct">Latest approved progress: {{ job.latest_progress_pct }}%</span>
                            <span v-else>No approved progress payout yet.</span>
                        </div>
                        <Link :href="`/technician/jobs/${job.service_request_id}`" class="btn btn-outline btn-sm">
                            View Job
                        </Link>
                    </div>
                </div>
            </div>

            <div v-else class="pwa-card" style="text-align: center; padding: 2rem;">
                <i class="fas fa-money-bill-wave" style="font-size: 2rem; color: var(--light-text); margin-bottom: 0.5rem;"></i>
                <p style="color: var(--light-text); margin: 0;">No compensation data found yet.</p>
            </div>

            <h2 style="font-size: 1rem; color: var(--light-text); margin: 1rem 0 0.5rem; padding-left: 0.5rem;">Payout History</h2>

            <div v-if="earnings.payment_history?.length" class="pwa-card">
                <div v-for="entry in earnings.payment_history" :key="`${entry.type}-${entry.reference}-${entry.date}`" style="display: flex; justify-content: space-between; align-items: start; gap: 1rem; padding: 0.85rem 0; border-bottom: 1px solid #eef2f7;">
                    <div>
                        <strong style="display: block; margin-bottom: 0.2rem;">{{ entry.job_reference }}</strong>
                        <span style="display: block; font-size: 0.8rem; color: var(--light-text);">
                            {{ entry.type === 'payment_sheet' ? 'Payment sheet' : 'Direct payout' }}
                            <span v-if="entry.date"> • {{ formatDate(entry.date) }}</span>
                        </span>
                        <span v-if="entry.reference" style="display: block; font-size: 0.78rem; color: var(--light-text); margin-top: 0.2rem;">
                            Ref: {{ entry.reference }}
                        </span>
                    </div>
                    <strong style="white-space: nowrap; color: var(--success-color);">{{ formatCurrency(entry.amount) }}</strong>
                </div>
            </div>

            <div v-else class="pwa-card" style="text-align: center; padding: 1.5rem;">
                <p style="color: var(--light-text); margin: 0;">No payouts found for the selected period.</p>
            </div>
        </main>

        <TechnicianBottomNav current-page="earnings" />
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import TechnicianBottomNav from '../../Components/TechnicianBottomNav.vue'

const props = defineProps({
    technician: { type: Object, default: null },
    earnings: { type: Object, default: () => ({ total_earned: 0, entries: [], by_job: [] }) },
    filters: { type: Object, default: () => ({}) },
})

const fromDate = ref(props.filters.from || '')
const toDate = ref(props.filters.to || '')

const applyFilters = () => {
    router.get('/technician/earnings', {
        from: fromDate.value || undefined,
        to: toDate.value || undefined,
    }, { preserveState: true, preserveScroll: true })
}

const formatCurrency = (amount) => {
    if (!amount && amount !== 0) return 'KES 0.00'
    return 'KES ' + Number(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })
}

const formatStatus = (status) => {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'Unknown'
}

const formatDate = (dateString) => {
    if (!dateString) return ''
    return new Date(dateString).toLocaleDateString('en-KE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}

defineOptions({ layout: null })
</script>

<style>

.pwa-input {
    padding: 0.5rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.9rem;
    background: white;
    flex: 1;
    min-width: 0;
}

.pwa-btn-primary {
    padding: 0.5rem 1rem;
    background: var(--primary-color);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
}
</style>
