<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="mpesa-transactions" />

        <main class="main-content">
            <header class="main-header">
                <h1>Transaction Details</h1>
                <div class="header-actions">
                    <Link href="/admin/mpesa-transactions" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </Link>
                    <Link :href="`/admin/mpesa-transactions/${transaction.id}/edit`" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Transaction
                    </Link>
                </div>
            </header>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>M-Pesa Transaction #{{ transaction.id }}</h3>
                    </div>

                    <div class="details-grid">
                        <div class="detail-group">
                            <label>Receipt Number</label>
                            <p class="detail-value">{{ transaction.receipt_number || 'N/A' }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Amount</label>
                            <p class="detail-value">{{ transaction.amount !== null ? 'KES ' + transaction.amount : 'N/A' }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Phone Number</label>
                            <p class="detail-value">{{ transaction.phone_number || 'N/A' }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Result Code</label>
                            <p class="detail-value">
                                <span :class="['status-badge', transaction.result_code === 0 ? 'status-completed' : 'status-failed']">
                                    {{ transaction.result_code !== null ? transaction.result_code : 'N/A' }}
                                </span>
                            </p>
                        </div>
                        <div class="detail-group">
                            <label>Result Description</label>
                            <p class="detail-value">{{ transaction.result_desc || 'N/A' }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Transaction Date (M-Pesa)</label>
                            <p class="detail-value">{{ transaction.transaction_date || 'N/A' }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Checkout Request ID</label>
                            <p class="detail-value">{{ transaction.checkout_request_id || 'N/A' }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Merchant Request ID</label>
                            <p class="detail-value">{{ transaction.merchant_request_id || 'N/A' }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Created At</label>
                            <p class="detail-value">{{ formatDate(transaction.created_at) }}</p>
                        </div>
                        <div class="detail-group">
                            <label>Updated At</label>
                            <p class="detail-value">{{ formatDate(transaction.updated_at) }}</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    transaction: {
        type: Object,
        required: true
    }
})

const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleString()
}

defineOptions({
    layout: null
})
</script>

<style scoped>
@import url('../../../../css/dashboard-app.css');

.details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    padding: 1.5rem;
}

.detail-group label {
    display: block;
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 1rem;
    color: var(--text-color);
    font-weight: 500;
    word-break: break-all;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-color);
}
.btn-outline:hover {
    background: #f9fafb;
}
</style>
