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

                    <!-- Filter chips -->
                    <div class="mpesa-filter-bar">
                        <div class="filter-chips">
                            <button
                                v-for="opt in filterOptions"
                                :key="opt.value"
                                @click="applyStatus(opt.value)"
                                :class="['filter-chip', { active: activeStatus === opt.value }]"
                            >
                                {{ opt.label }}
                                <span class="chip-count">{{ counts[opt.value === '' ? 'all' : opt.value] ?? 0 }}</span>
                            </button>
                        </div>
                        <div class="filter-search">
                            <input
                                v-model="searchTerm"
                                @keyup.enter="applySearch"
                                type="text"
                                placeholder="Search receipt, phone, or checkout ID…"
                                class="form-control"
                            />
                            <button v-if="searchTerm || activeStatus" @click="clearFilters" class="btn btn-secondary btn-sm">
                                Clear
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Receipt #</th>
                                    <th>Phone Number</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Result</th>
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
                                        <span :class="['status-badge', statusBadgeClass(tx.status)]">
                                            {{ statusLabel(tx.status) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span :title="tx.result_desc || ''" style="font-size:.85rem;">
                                            {{ tx.result_code === 0 ? 'Success (0)' : (tx.result_code !== null ? 'Code '+tx.result_code : (tx.result_desc ? tx.result_desc.substring(0,40) : '—')) }}
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
                                    <td colspan="8" class="text-center">No transactions found.</td>
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
import { computed, ref } from 'vue'

const props = defineProps({
    transactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({ status: null, search: null }) },
    counts: { type: Object, default: () => ({ all: 0, initiated: 0, completed: 0, failed: 0 }) },
})

const page = usePage()
const successMessage = computed(() => page.props.flash?.success)

const filterOptions = [
    { value: '', label: 'All' },
    { value: 'initiated', label: 'STK Sent' },
    { value: 'completed', label: 'Completed' },
    { value: 'failed', label: 'Failed' },
]

const activeStatus = ref(props.filters?.status || '')
const searchTerm = ref(props.filters?.search || '')

const applyStatus = (status) => {
    activeStatus.value = status
    router.get('/admin/mpesa-transactions', {
        status: status || undefined,
        search: searchTerm.value || undefined,
    }, { preserveState: true, replace: true })
}

const applySearch = () => {
    router.get('/admin/mpesa-transactions', {
        status: activeStatus.value || undefined,
        search: searchTerm.value || undefined,
    }, { preserveState: true, replace: true })
}

const clearFilters = () => {
    activeStatus.value = ''
    searchTerm.value = ''
    router.get('/admin/mpesa-transactions', {}, { preserveState: true, replace: true })
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
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 0.75rem 1rem;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}
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
</style>
