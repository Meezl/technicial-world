<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="payments" />

        <main class="main-content">
            <header class="main-header">
                <h1>Payments & Transactions</h1>
            </header>

            <section class="panel-section">
                <div class="card-header">
                    <h3>Active Project Milestones</h3>
                </div>
                <div class="panel-card" v-if="activeMilestones.length > 0">
                    <div v-for="milestone in activeMilestones" :key="milestone.id" class="milestone-item">
                        <h4>{{ milestone.job_id }}: {{ milestone.service_name }} (Total: KES {{ formatCurrency(milestone.total_amount) }})</h4>
                        <p>{{ milestone.description }}</p>
                        <ul class="payment-milestones-list">
                            <li v-for="payment in milestone.payments" :key="payment.id">
                                <span>{{ payment.description }}</span>
                                <span :class="['status', payment.status]">{{ formatStatus(payment.status) }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <div v-else class="panel-card">
                    <p class="empty-state">No active payment milestones at the moment.</p>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Transaction History</h3>
                        <a href="#" class="btn btn-secondary btn-sm">Download Statements</a>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Amount (KES)</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="transactions.length === 0">
                                <td colspan="5" style="text-align: center; color: var(--medium-grey);">
                                    No transactions yet.
                                </td>
                            </tr>
                            <tr v-for="transaction in transactions" :key="transaction.id">
                                <td>{{ formatDate(transaction.date) }}</td>
                                <td>{{ transaction.description }}</td>
                                <td>{{ formatCurrency(transaction.amount) }}</td>
                                <td>{{ transaction.payment_method }}</td>
                                <td>
                                    <span :class="['status', transaction.status]">
                                        {{ formatStatus(transaction.status) }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import ClientSidebar from '../../Components/ClientSidebar.vue'
import { ref, computed } from 'vue'

const props = defineProps({
    payments: {
        type: Array,
        default: () => []
    },
    transactions: {
        type: Array,
        default: () => [
            {
                id: 1,
                date: '2025-08-02',
                description: 'Upfront payment for JOB-0987',
                amount: 120000.00,
                payment_method: 'M-PESA',
                status: 'completed'
            },
            {
                id: 2,
                date: '2025-07-15',
                description: 'Final payment for JOB-0980',
                amount: 45000.00,
                payment_method: 'Bank Card',
                status: 'completed'
            }
        ]
    }
})

const activeMilestones = computed(() => {
    return props.payments || [
        {
            id: 1,
            job_id: 'JOB-0987',
            service_name: 'Electrical Installation',
            total_amount: 150000,
            description: 'This project follows the payment structure for projects up to KES 200,000.',
            payments: [
                {
                    id: 1,
                    description: '80% upon quotation approval',
                    status: 'completed'
                },
                {
                    id: 2,
                    description: '20% upon completion and client confirmation',
                    status: 'review'
                }
            ]
        }
    ]
})

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-KE').format(amount)
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString()
}

const formatStatus = (status) => {
    const statusMap = {
        'completed': 'Completed',
        'review': 'Pending Completion',
        'pending': 'Pending',
        'failed': 'Failed'
    }
    return statusMap[status] || status
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

.milestone-item {
    margin-bottom: 2rem;
}

.milestone-item:last-child {
    margin-bottom: 0;
}

.payment-milestones-list {
    list-style: none;
    padding: 0;
    margin: 1rem 0 0 0;
}

.payment-milestones-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    border-bottom: 1px solid var(--border-color);
}

.payment-milestones-list li:last-child {
    border-bottom: none;
}

.empty-state {
    text-align: center;
    color: var(--medium-grey);
    font-style: italic;
}
</style>