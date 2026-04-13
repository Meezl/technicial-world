<template>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2 class="logo">TECHNICIAN WORLD</h2>
            </div>
            <nav class="sidebar-nav">
                <Link href="/admin/dashboard" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </Link>
                <Link href="/admin/projects/dashboard" class="nav-item">
                    <i class="fas fa-project-diagram"></i><span>Project Management</span>
                </Link>
                <Link href="/admin/rfq" class="nav-item">
                    <i class="fas fa-file-alt"></i><span>RFQ Management</span>
                </Link>
                <Link href="/admin/technicians" class="nav-item">
                    <i class="fas fa-hard-hat"></i><span>Technicians</span>
                </Link>
                <Link href="/admin/users" class="nav-item">
                    <i class="fas fa-users"></i><span>User Management</span>
                </Link>
                <Link href="/admin/jobs" class="nav-item">
                    <i class="fas fa-tasks"></i><span>Jobs Monitoring</span>
                </Link>
                <Link href="/admin/tools" class="nav-item">
                    <i class="fas fa-tools"></i><span>Tools Management</span>
                </Link>
                <Link href="/admin/payments" class="nav-item active">
                    <i class="fas fa-credit-card"></i><span>Payments</span>
                </Link>
            </nav>
            <div class="sidebar-footer">
                <Link href="/logout" class="nav-item" method="post">
                    <i class="fas fa-sign-out-alt"></i><span>Log Out</span>
                </Link>
            </div>
        </aside>

        <main class="main-content">
            <header class="main-header">
                <h1>Payments</h1>
                <div class="header-actions">
                    <select v-model="selectedSR" @change="filterByFilters" class="status-filter">
                        <option value="">All Service Requests</option>
                        <option v-for="sr in serviceRequests" :key="sr.id" :value="sr.id">
                            {{ sr.request_id }} - {{ truncate(sr.description, 40) }}
                        </option>
                    </select>

                    <select v-model="selectedTechnician" @change="filterByFilters" class="status-filter" style="margin-left: 10px;">
                        <option value="">All Technicians</option>
                        <option v-for="t in technicians" :key="t.id" :value="t.id">
                            {{ t.user?.name }} ({{ t.technician_id }})
                        </option>
                    </select>
                </div>
            </header>

            <!-- KPI Cards -->
            <section class="rfq-stats">
                <div class="stat-card">
                    <h4>Total Received</h4>
                    <p class="stat-value">KSH {{ formatCurrency(stats.totalReceived) }}</p>
                    <span class="stat-icon approved"><i class="fas fa-arrow-down"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Pending Payments</h4>
                    <p class="stat-value">KSH {{ formatCurrency(stats.pendingPayments) }}</p>
                    <span class="stat-icon pending"><i class="fas fa-clock"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Paid to Technicians</h4>
                    <p class="stat-value">KSH {{ formatCurrency(stats.paidToTechnicians) }}</p>
                    <span class="stat-icon quoted"><i class="fas fa-user-check"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Total Expenses</h4>
                    <p class="stat-value">KSH {{ formatCurrency(stats.totalExpenses) }}</p>
                    <span class="stat-icon value"><i class="fas fa-receipt"></i></span>
                </div>
            </section>

            <!-- Budget Summary (only when filtered by SR) -->
            <section v-if="selectedSR && budgetSummary" class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Budget Summary</h3>
                        <button @click="openBudgetModal" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit"></i> Edit Budget
                        </button>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Budgeted</th>
                                <th>Actual</th>
                                <th>Remaining</th>
                                <th>% Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cat in ['labor', 'materials', 'other']" :key="cat">
                                <td style="text-transform: capitalize;"><strong>{{ cat }}</strong></td>
                                <td>KSH {{ formatCurrency(budgetSummary[cat].budgeted) }}</td>
                                <td>KSH {{ formatCurrency(budgetSummary[cat].actual) }}</td>
                                <td :style="{ color: budgetSummary[cat].remaining < 0 ? '#e74c3c' : '#27ae60' }">
                                    KSH {{ formatCurrency(budgetSummary[cat].remaining) }}
                                </td>
                                <td>
                                    <div class="progress-bar-container">
                                        <div
                                            class="progress-bar-fill"
                                            :style="{
                                                width: Math.min(getPercentUsed(budgetSummary[cat]), 100) + '%',
                                                backgroundColor: getProgressColor(getPercentUsed(budgetSummary[cat]))
                                            }"
                                        ></div>
                                        <span class="progress-text">{{ getPercentUsed(budgetSummary[cat]).toFixed(0) }}%</span>
                                    </div>
                                </td>
                            </tr>
                            <tr style="font-weight: bold; background: #f8f9fa;">
                                <td><strong>Total</strong></td>
                                <td>KSH {{ formatCurrency(budgetSummary.total.budgeted) }}</td>
                                <td>KSH {{ formatCurrency(budgetSummary.total.actual) }}</td>
                                <td :style="{ color: budgetSummary.total.remaining < 0 ? '#e74c3c' : '#27ae60' }">
                                    KSH {{ formatCurrency(budgetSummary.total.remaining) }}
                                </td>
                                <td>
                                    <div class="progress-bar-container">
                                        <div
                                            class="progress-bar-fill"
                                            :style="{
                                                width: Math.min(getPercentUsed(budgetSummary.total), 100) + '%',
                                                backgroundColor: getProgressColor(getPercentUsed(budgetSummary.total))
                                            }"
                                        ></div>
                                        <span class="progress-text">{{ getPercentUsed(budgetSummary.total).toFixed(0) }}%</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- No budget set notice -->
            <section v-if="selectedSR && !budgetSummary" class="main-panel">
                <div class="panel-card full-width">
                    <div style="padding: 1.5rem; text-align: center; color: var(--medium-grey);">
                        <i class="fas fa-calculator" style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                        <p>No budget set for this service request.</p>
                        <button @click="openBudgetModal" class="btn btn-primary" style="margin-top: 0.5rem;">
                            <i class="fas fa-plus"></i> Set Budget
                        </button>
                    </div>
                </div>
            </section>

            <!-- Tabs -->
            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <div class="tab-nav">
                            <button
                                v-for="tab in tabs"
                                :key="tab.key"
                                :class="['tab-btn', { active: activeTab === tab.key }]"
                                @click="activeTab = tab.key"
                            >
                                <i :class="tab.icon"></i> {{ tab.label }}
                            </button>
                        </div>
                        <div class="header-actions">
                            <button v-if="activeTab === 'technician'" @click="openTechPaymentModal" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Record Payment
                            </button>
                            <button v-if="activeTab === 'expenditures'" @click="openExpenditureModal()" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i> Add Expenditure
                            </button>
                        </div>
                    </div>

                    <!-- Tab 1: Client Payments -->
                    <div v-if="activeTab === 'client'">
                        <table class="data-table" v-if="allClientPayments.length">
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Service Request</th>
                                    <th>Client</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in allClientPayments" :key="p.id">
                                    <td><strong>{{ p.payment_id || p.payment_request_id || '-' }}</strong></td>
                                    <td>{{ p.service_request?.request_id || '-' }}</td>
                                    <td>{{ p.user?.name || '-' }}</td>
                                    <td>KSH {{ formatCurrency(p.amount) }}</td>
                                    <td>{{ p.payment_method || p.type || '-' }}</td>
                                    <td>
                                        <span :class="['status', getStatusClass(p.status)]">
                                            {{ p.status }}
                                        </span>
                                    </td>
                                    <td>{{ formatDate(p.paid_at || p.created_at) }}</td>
                                    <td>
                                        <button 
                                            v-if="p.type === 'payment_request' && p.status === 'pending' && ['cash', 'cheque'].includes(p.payment_method)"
                                            @click="approveOfflinePayment(p)"
                                            class="btn btn-sm"
                                            style="background-color: #10b981; color: white;"
                                        >
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="empty-state">
                            <i class="fas fa-money-bill-wave"></i>
                            <p>No client payments found.</p>
                        </div>
                    </div>

                    <!-- Tab 2: Technician Payments -->
                    <div v-if="activeTab === 'technician'">
                        <table class="data-table" v-if="technicianPayments.length">
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>Technician</th>
                                    <th>Service Request</th>
                                    <th>Category</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tp in technicianPayments" :key="tp.id">
                                    <td><strong>{{ tp.payment_id }}</strong></td>
                                    <td>{{ tp.technician?.user?.name || '-' }}</td>
                                    <td>{{ tp.service_request?.request_id || '-' }}</td>
                                    <td>
                                        <span :class="['status', `category-${tp.category}`]" style="text-transform: capitalize;">
                                            {{ tp.category }}
                                        </span>
                                    </td>
                                    <td>KSH {{ formatCurrency(tp.amount) }}</td>
                                    <td>
                                        <span :class="['status', getStatusClass(tp.status)]">
                                            {{ tp.status }}
                                        </span>
                                    </td>
                                    <td>{{ formatDate(tp.paid_at || tp.created_at) }}</td>
                                    <td>
                                        <div class="action-buttons">
                                            <select
                                                v-if="tp.status !== 'completed'"
                                                :value="tp.status"
                                                @change="updateTechPaymentStatus(tp, $event.target.value)"
                                                class="status-select"
                                            >
                                                <option value="pending">Pending</option>
                                                <option value="processing">Processing</option>
                                                <option value="completed">Completed</option>
                                                <option value="failed">Failed</option>
                                            </select>
                                            <span v-else class="status approved" style="font-size: 0.75rem;">Done</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="empty-state">
                            <i class="fas fa-user-check"></i>
                            <p>No technician payments found.</p>
                        </div>
                    </div>

                    <!-- Tab 3: Expenditures -->
                    <div v-if="activeTab === 'expenditures'">
                        <table class="data-table" v-if="expenditures.length">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Service Request</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th>Vendor</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="exp in expenditures" :key="exp.id">
                                    <td><strong>{{ exp.expenditure_id }}</strong></td>
                                    <td>{{ exp.service_request?.request_id || '-' }}</td>
                                    <td style="text-transform: capitalize;">{{ exp.category }}</td>
                                    <td>{{ truncate(exp.description, 40) }}</td>
                                    <td>{{ exp.vendor || '-' }}</td>
                                    <td>KSH {{ formatCurrency(exp.amount) }}</td>
                                    <td>{{ formatDate(exp.expense_date || exp.created_at) }}</td>
                                    <td>
                                        <div class="action-buttons">
                                            <button @click="openExpenditureModal(exp)" class="btn btn-sm btn-info" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button @click="deleteExpenditure(exp)" class="btn btn-sm btn-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-else class="empty-state">
                            <i class="fas fa-receipt"></i>
                            <p>No expenditures found.</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Budget Modal -->
        <div v-if="showBudgetModal" class="modal-overlay" @click="showBudgetModal = false">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ budgetSummary ? 'Edit' : 'Set' }} Budget</h3>
                    <button @click="showBudgetModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveBudget">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Labor Budget (KSH)*</label>
                                <input type="number" v-model="budgetForm.labor_budget" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Materials Budget (KSH)*</label>
                                <input type="number" v-model="budgetForm.materials_budget" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Other Budget (KSH)*</label>
                                <input type="number" v-model="budgetForm.other_budget" step="0.01" min="0" required>
                            </div>
                            <div class="form-group">
                                <label>Total</label>
                                <input type="text" :value="'KSH ' + formatCurrency(budgetTotal)" disabled style="background: #f0f0f0;">
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea v-model="budgetForm.notes" rows="2" placeholder="Budget notes..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="showBudgetModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveBudget" class="btn btn-primary">Save Budget</button>
                </div>
            </div>
        </div>

        <!-- Record Technician Payment Modal -->
        <div v-if="showTechPaymentModal" class="modal-overlay" @click="showTechPaymentModal = false">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Record Technician Payment</h3>
                    <button @click="showTechPaymentModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveTechPayment">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Technician*</label>
                                <select v-model="techPaymentForm.technician_id" required>
                                    <option value="">Select technician</option>
                                    <option v-for="t in technicians" :key="t.id" :value="t.id">
                                        {{ t.user?.name }} ({{ t.technician_id }})
                                    </option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Service Request</label>
                                <select v-model="techPaymentForm.service_request_id">
                                    <option value="">None</option>
                                    <option v-for="sr in filteredTechServiceRequests" :key="sr.id" :value="sr.id">
                                        {{ sr.request_id }} {{ sr.description ? '- ' + (sr.description.length > 30 ? sr.description.substring(0, 30) + '...' : sr.description) : '' }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Category*</label>
                                <select v-model="techPaymentForm.category" required>
                                    <option value="labor">Labor</option>
                                    <option value="materials">Materials</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Amount (KSH)*</label>
                                <input type="number" v-model="techPaymentForm.amount" step="0.01" min="0.01" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Payment Method</label>
                                <input type="text" v-model="techPaymentForm.payment_method" placeholder="e.g., M-Pesa, Bank Transfer">
                            </div>
                            <div class="form-group">
                                <label>Transaction Reference</label>
                                <input type="text" v-model="techPaymentForm.transaction_reference" placeholder="e.g., TXN-12345">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Status</label>
                                <select v-model="techPaymentForm.status">
                                    <option value="completed">Completed</option>
                                    <option value="pending">Pending</option>
                                    <option value="processing">Processing</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea v-model="techPaymentForm.notes" rows="2" placeholder="Payment notes..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="showTechPaymentModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveTechPayment" class="btn btn-primary">Record Payment</button>
                </div>
            </div>
        </div>

        <!-- Add/Edit Expenditure Modal -->
        <div v-if="showExpenditureModal" class="modal-overlay" @click="showExpenditureModal = false">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ editingExpenditure ? 'Edit' : 'Add' }} Expenditure</h3>
                    <button @click="showExpenditureModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveExpenditure">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Service Request*</label>
                                <select v-model="expenditureForm.service_request_id" required :disabled="!!editingExpenditure">
                                    <option value="">Select SR</option>
                                    <option v-for="sr in serviceRequests" :key="sr.id" :value="sr.id">
                                        {{ sr.request_id }}
                                    </option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Category*</label>
                                <select v-model="expenditureForm.category" required>
                                    <option value="materials">Materials</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label>Description*</label>
                            <input type="text" v-model="expenditureForm.description" required placeholder="e.g., PVC pipes, cement bags">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Amount (KSH)*</label>
                                <input type="number" v-model="expenditureForm.amount" step="0.01" min="0.01" required>
                            </div>
                            <div class="form-group">
                                <label>Vendor</label>
                                <input type="text" v-model="expenditureForm.vendor" placeholder="e.g., Hardware Store">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Receipt Reference</label>
                                <input type="text" v-model="expenditureForm.receipt_reference" placeholder="e.g., REC-001">
                            </div>
                            <div class="form-group">
                                <label>Expense Date</label>
                                <input type="date" v-model="expenditureForm.expense_date">
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea v-model="expenditureForm.notes" rows="2" placeholder="Additional notes..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="showExpenditureModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveExpenditure" class="btn btn-primary">
                        {{ editingExpenditure ? 'Update' : 'Add' }} Expenditure
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'

defineOptions({ layout: null })

const props = defineProps({
    payments: { type: Array, default: () => [] },
    paymentRequests: { type: Array, default: () => [] },
    technicianPayments: { type: Array, default: () => [] },
    expenditures: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ totalReceived: 0, pendingPayments: 0, paidToTechnicians: 0, totalExpenses: 0 }) },
    budgetSummary: { type: Object, default: null },
    serviceRequests: { type: Array, default: () => [] },
    technicians: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
})

const selectedSR = ref(props.filters?.service_request_id || '')
const selectedTechnician = ref(props.filters?.technician_id || '')
const activeTab = ref('client')

const tabs = [
    { key: 'client', label: 'Client Payments', icon: 'fas fa-money-bill-wave' },
    { key: 'technician', label: 'Technician Payments', icon: 'fas fa-user-check' },
    { key: 'expenditures', label: 'Expenditures', icon: 'fas fa-receipt' },
]

// Merge payments + payment requests into one list for the client tab
const allClientPayments = computed(() => {
    const combined = [
        ...props.payments.map(p => ({ ...p, type: 'payment' })),
        ...props.paymentRequests.map(pr => ({ ...pr, type: 'payment_request', payment_id: pr.payment_request_id })),
    ]
    return combined.sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
})

// ---- Budget Modal ----
const showBudgetModal = ref(false)
const budgetForm = ref({ labor_budget: 0, materials_budget: 0, other_budget: 0, notes: '' })
const budgetTotal = computed(() =>
    parseFloat(budgetForm.value.labor_budget || 0) +
    parseFloat(budgetForm.value.materials_budget || 0) +
    parseFloat(budgetForm.value.other_budget || 0)
)

const openBudgetModal = () => {
    if (props.budgetSummary?.budget) {
        budgetForm.value = {
            labor_budget: props.budgetSummary.budget.labor_budget,
            materials_budget: props.budgetSummary.budget.materials_budget,
            other_budget: props.budgetSummary.budget.other_budget,
            notes: props.budgetSummary.budget.notes || '',
        }
    } else {
        budgetForm.value = { labor_budget: 0, materials_budget: 0, other_budget: 0, notes: '' }
    }
    showBudgetModal.value = true
}

const saveBudget = () => {
    if (!selectedSR.value) return
    if (props.budgetSummary?.budget?.id) {
        router.put(`/admin/budgets/${props.budgetSummary.budget.id}`, budgetForm.value, {
            onSuccess: () => { showBudgetModal.value = false }
        })
    } else {
        router.post(`/admin/jobs/${selectedSR.value}/budget`, budgetForm.value, {
            onSuccess: () => { showBudgetModal.value = false }
        })
    }
}

// ---- Technician Payment Modal ----
const showTechPaymentModal = ref(false)
const techPaymentForm = ref({
    technician_id: '',
    service_request_id: '',
    category: 'labor',
    amount: '',
    payment_method: '',
    transaction_reference: '',
    status: 'completed',
    notes: '',
})

const filteredTechServiceRequests = computed(() => {
    if (!techPaymentForm.value.technician_id) return props.serviceRequests;
    
    const tid = Number(techPaymentForm.value.technician_id);
    return props.serviceRequests.filter(sr => {
        return sr.technician_id === tid || 
               sr.lead_technician_id === tid || 
               (sr.sub_tasks && sr.sub_tasks.some(st => st.technician_id === tid));
    });
});

const openTechPaymentModal = () => {
    techPaymentForm.value = {
        technician_id: '',
        service_request_id: selectedSR.value || '',
        category: 'labor',
        amount: '',
        payment_method: '',
        transaction_reference: '',
        status: 'completed',
        notes: '',
    }
    showTechPaymentModal.value = true
}

const saveTechPayment = () => {
    router.post('/admin/technician-payments', techPaymentForm.value, {
        onSuccess: () => { showTechPaymentModal.value = false }
    })
}

const updateTechPaymentStatus = (tp, newStatus) => {
    router.put(`/admin/technician-payments/${tp.id}`, { status: newStatus })
}

// ---- Expenditure Modal ----
const showExpenditureModal = ref(false)
const editingExpenditure = ref(null)
const expenditureForm = ref({
    service_request_id: '',
    category: 'materials',
    description: '',
    amount: '',
    vendor: '',
    receipt_reference: '',
    expense_date: '',
    notes: '',
})

const openExpenditureModal = (exp = null) => {
    editingExpenditure.value = exp
    if (exp) {
        expenditureForm.value = {
            service_request_id: exp.service_request_id,
            category: exp.category,
            description: exp.description,
            amount: exp.amount,
            vendor: exp.vendor || '',
            receipt_reference: exp.receipt_reference || '',
            expense_date: exp.expense_date ? exp.expense_date.split('T')[0] : '',
            notes: exp.notes || '',
        }
    } else {
        expenditureForm.value = {
            service_request_id: selectedSR.value || '',
            category: 'materials',
            description: '',
            amount: '',
            vendor: '',
            receipt_reference: '',
            expense_date: '',
            notes: '',
        }
    }
    showExpenditureModal.value = true
}

const saveExpenditure = () => {
    if (editingExpenditure.value) {
        router.put(`/admin/expenditures/${editingExpenditure.value.id}`, expenditureForm.value, {
            onSuccess: () => { showExpenditureModal.value = false }
        })
    } else {
        router.post('/admin/expenditures', expenditureForm.value, {
            onSuccess: () => { showExpenditureModal.value = false }
        })
    }
}

const deleteExpenditure = (exp) => {
    if (confirm(`Delete expenditure ${exp.expenditure_id}? This cannot be undone.`)) {
        router.delete(`/admin/expenditures/${exp.id}`)
    }
}

// ---- Offline Payment Approval ----
const approveOfflinePayment = async (payment) => {
    if (confirm('Are you sure you want to approve this offline payment?')) {
        try {
            await axios.post(`/admin/payments/${payment.id}/confirm`)
            alert('Payment approved successfully!')
            router.reload({ only: ['payments', 'paymentRequests', 'stats'] })
        } catch (error) {
            alert(error.response?.data?.error || 'Failed to approve payment')
        }
    }
}

// ---- Filter ----
const filterByFilters = () => {
    const params = {}
    if (selectedSR.value) params.service_request_id = selectedSR.value
    if (selectedTechnician.value) params.technician_id = selectedTechnician.value

    router.get('/admin/payments', params, { preserveState: true })
}

// ---- Helpers ----
const formatCurrency = (val) => {
    const num = parseFloat(val) || 0
    return num.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const formatDate = (dateStr) => {
    if (!dateStr) return '-'
    return new Date(dateStr).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' })
}

const truncate = (text, len) => {
    if (!text) return ''
    return text.length > len ? text.substring(0, len) + '...' : text
}

const getStatusClass = (status) => {
    const map = {
        completed: 'approved',
        paid: 'approved',
        pending: 'pending',
        processing: 'quoted',
        failed: 'rejected',
    }
    return map[status] || 'pending'
}

const getPercentUsed = (cat) => {
    if (!cat.budgeted || cat.budgeted === 0) return cat.actual > 0 ? 100 : 0
    return (cat.actual / cat.budgeted) * 100
}

const getProgressColor = (pct) => {
    if (pct >= 100) return '#e74c3c'
    if (pct >= 75) return '#f39c12'
    return '#27ae60'
}
</script>

<style>
@import url('../../../css/dashboard-app.css');

.tab-nav {
    display: flex;
    gap: 0;
}

.tab-btn {
    padding: 0.6rem 1.2rem;
    border: none;
    background: transparent;
    cursor: pointer;
    font-size: 0.85rem;
    color: var(--medium-grey);
    border-bottom: 2px solid transparent;
    transition: all 0.2s;
}

.tab-btn:hover {
    color: var(--primary-color, #667eea);
}

.tab-btn.active {
    color: var(--primary-color, #667eea);
    border-bottom-color: var(--primary-color, #667eea);
    font-weight: 600;
}

.tab-btn i {
    margin-right: 0.4rem;
}

.progress-bar-container {
    position: relative;
    width: 100%;
    height: 20px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    min-width: 80px;
}

.progress-bar-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s;
}

.progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.7rem;
    font-weight: 600;
    color: #333;
}

.empty-state {
    padding: 3rem;
    text-align: center;
    color: var(--medium-grey);
}

.empty-state i {
    font-size: 2.5rem;
    margin-bottom: 0.75rem;
    opacity: 0.4;
    display: block;
}

.status-select {
    padding: 0.3rem 0.5rem;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    font-size: 0.8rem;
    cursor: pointer;
}

.category-labor { background: #e8f5e9; color: #2e7d32; }
.category-materials { background: #e3f2fd; color: #1565c0; }
.category-other { background: #fff3e0; color: #e65100; }

.btn-sm {
    padding: 0.35rem 0.75rem;
    font-size: 0.8rem;
}

.btn-danger {
    background: #e74c3c;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.btn-danger:hover {
    background: #c0392b;
}
</style>
