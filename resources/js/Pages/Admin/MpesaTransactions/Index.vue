<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="mpesa-transactions" />

        <main class="main-content">
            <header class="main-header">
                <h1>M-Pesa Transactions</h1>
            </header>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="card-header">
                        <h3>All Transactions</h3>
                    </div>

                    <div v-if="successMessage" class="alert alert-success">
                        {{ successMessage }}
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Receipt #</th>
                                    <th>Phone Number</th>
                                    <th>Amount</th>
                                    <th>Result Code</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tx in transactions.data" :key="tx.id">
                                    <td>{{ tx.id }}</td>
                                    <td>{{ tx.receipt_number || 'N/A' }}</td>
                                    <td>{{ tx.phone_number || 'N/A' }}</td>
                                    <td>{{ tx.amount !== null ? 'KES ' + tx.amount : 'N/A' }}</td>
                                    <td>
                                        <span :class="['status-badge', tx.result_code === 0 ? 'status-completed' : 'status-failed']">
                                            {{ tx.result_code === 0 ? 'Success (0)' : (tx.result_code !== null ? 'Failed ('+tx.result_code+')' : 'Pending') }}
                                        </span>
                                    </td>
                                    <td>{{ formatDate(tx.created_at) }}</td>
                                    <td class="actions-cell">
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
                                    <td colspan="7" class="text-center">No transactions found.</td>
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
    </div>
</template>

<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'
import { computed } from 'vue'

const props = defineProps({
    transactions: {
        type: Object,
        required: true
    }
})

const page = usePage()
const successMessage = computed(() => page.props.flash?.success)

const formatDate = (dateString) => {
    if (!dateString) return 'N/A'
    return new Date(dateString).toLocaleString()
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
</style>
