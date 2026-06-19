<template>
    <div class="dashboard-container client-pwa-shell">
        <ClientSidebar current-page="statements" />
        <ClientBottomNav current-page="payments" />

        <main class="main-content">
            <header class="main-header">
                <h1>Payment Statements</h1>
                <div class="header-actions">
                    <input type="date" v-model="fromDate" class="date-input" />
                    <span style="color: var(--light-text); margin: 0 0.25rem;">to</span>
                    <input type="date" v-model="toDate" class="date-input" />
                    <select v-model="selectedSR" class="status-filter">
                        <option value="">All Service Requests</option>
                        <option v-for="sr in serviceRequests" :key="sr.id" :value="sr.id">
                            {{ sr.job_reference || sr.request_id }}
                        </option>
                    </select>
                    <button @click="applyFilters" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
            </header>

            <!-- Summary Card -->
            <section class="stats-section">
                <div class="stat-card">
                    <h4>Total Paid</h4>
                    <p class="stat-value">{{ formatCurrency(statement.total_paid) }}</p>
                    <span class="stat-icon total"><i class="fas fa-money-bill-wave"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Total Payments</h4>
                    <p class="stat-value">{{ statement.payments?.length || 0 }}</p>
                    <span class="stat-icon clients"><i class="fas fa-receipt"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Service Requests</h4>
                    <p class="stat-value">{{ statement.by_rfq?.length || 0 }}</p>
                    <span class="stat-icon technicians"><i class="fas fa-file-alt"></i></span>
                </div>
            </section>

            <!-- Per-RFQ Breakdown -->
            <section class="panel-section" v-for="(rfq, index) in statement.by_rfq" :key="index">
                <div class="panel-card">
                    <div class="card-header">
                        <h3>{{ rfq.job_reference }}</h3>
                        <span class="rfq-total">Total: {{ formatCurrency(rfq.total_paid) }} ({{ rfq.payment_count }} payments)</span>
                    </div>
                    <div class="users-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(payment, pIdx) in rfq.payments" :key="pIdx">
                                    <td>{{ payment.date }}</td>
                                    <td><strong>{{ formatCurrency(payment.amount) }}</strong></td>
                                    <td>
                                        <span class="method-badge">{{ formatMethod(payment.method) }}</span>
                                    </td>
                                    <td>
                                        <span :class="['status-badge', getStatusClass(payment.status)]">
                                            {{ formatStatus(payment.status) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div v-if="!statement.by_rfq || statement.by_rfq.length === 0" class="panel-section">
                <div class="panel-card">
                    <div class="no-data">
                        <i class="fas fa-receipt"></i>
                        <p>No payment records found for the selected filters</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import ClientSidebar from '../../Components/ClientSidebar.vue'
import ClientBottomNav from '../../Components/ClientBottomNav.vue'

const props = defineProps({
    statement: { type: Object, default: () => ({ total_paid: 0, payments: [], by_rfq: [] }) },
    serviceRequests: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
})

const fromDate = ref(props.filters.from || '')
const toDate = ref(props.filters.to || '')
const selectedSR = ref(props.filters.service_request_id || '')

const applyFilters = () => {
    router.get('/client/statements', {
        from: fromDate.value || undefined,
        to: toDate.value || undefined,
        service_request_id: selectedSR.value || undefined,
    }, { preserveState: true, preserveScroll: true })
}

const formatCurrency = (amount) => {
    if (!amount && amount !== 0) return 'KES 0.00'
    return 'KES ' + Number(amount).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })
}

const formatMethod = (method) => {
    if (!method) return 'N/A'
    return method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const formatStatus = (status) => {
    if (!status) return ''
    return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getStatusClass = (status) => {
    if (status === 'completed' || status === 'approved') return 'approved'
    if (status === 'pending' || status === 'pending_approval') return 'review'
    if (status === 'rejected') return 'rejected'
    return 'pending'
}

defineOptions({ layout: null })
</script>

<style>

.date-input {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.9rem;
    background: white;
}

.rfq-total {
    font-weight: 600;
    color: var(--primary-color);
}

.method-badge {
    background: var(--bg-light);
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 500;
}
</style>
