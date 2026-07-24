<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="payments" />

        <main class="main-content payments-content">
            <section class="payments-hero">
                <div class="payments-hero-copy">
                    <span class="hero-kicker">Finance Workspace</span>
                    <h1>Payments</h1>
                    <p>Manage client collections, technician payouts, approvals, and job spend from one calmer workspace.</p>
                    <div class="hero-pills">
                        <span class="hero-pill">
                            <i class="fas fa-layer-group"></i>
                            {{ activeTabMeta.label }}
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-clipboard-check"></i>
                            {{ pendingOfflineApprovals }} offline approvals
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-filter"></i>
                            {{ activeFilterChips.length || 0 }} active filters
                        </span>
                    </div>
                </div>

                <div class="payments-filter-card">
                    <div class="filter-copy">
                        <span class="section-kicker">Filters</span>
                        <h3>Focus the payment workspace</h3>
                        <p>Filter by service request or technician to review collections, payouts, and spend for a specific job.</p>
                    </div>

                    <div class="filter-grid">
                        <label class="filter-field">
                            <span>Service Request</span>
                            <select v-model="selectedSR" class="status-filter">
                                <option value="">All Service Requests</option>
                                <option v-for="sr in serviceRequests" :key="sr.id" :value="sr.id">
                                    {{ sr.request_id }} - {{ truncate(sr.description, 40) }}
                                </option>
                            </select>
                        </label>

                        <label class="filter-field">
                            <span>Technician</span>
                            <select v-model="selectedTechnician" class="status-filter">
                                <option value="">All Technicians</option>
                                <option v-for="t in technicians" :key="t.id" :value="t.id">
                                    {{ t.user?.name }} ({{ t.technician_id }})
                                </option>
                            </select>
                        </label>

                        <label class="filter-field">
                            <span>From</span>
                            <input type="date" v-model="fromDate" class="status-filter" />
                        </label>
                        <label class="filter-field">
                            <span>To</span>
                            <input type="date" v-model="toDate" class="status-filter" />
                        </label>
                    </div>

                    <div v-if="activeFilterChips.length" class="filter-chip-row">
                        <span v-for="chip in activeFilterChips" :key="chip" class="filter-chip">{{ chip }}</span>
                    </div>

                    <div class="filter-actions">
                        <button @click="filterByFilters" class="btn btn-primary btn-sm filter-button">
                            <i class="fas fa-filter"></i>
                            Apply filters
                        </button>
                        <button v-if="activeFilterChips.length" @click="clearFilters" class="btn btn-secondary btn-sm filter-button filter-button-secondary">
                            Clear
                        </button>
                    </div>
                </div>
            </section>

            <section class="payments-stat-grid">
                <article class="payment-stat-card tone-green">
                    <div class="payment-stat-topline">
                        <span class="payment-stat-tag">Collections</span>
                        <span class="payment-stat-icon"><i class="fas fa-arrow-down"></i></span>
                    </div>
                    <h4>Total Received</h4>
                    <p class="payment-stat-value">KSH {{ formatCurrency(stats.totalReceived) }}</p>
                    <span class="payment-stat-footnote">{{ totalClientPaymentCount }} client payment records</span>
                </article>

                <article class="payment-stat-card tone-amber">
                    <div class="payment-stat-topline">
                        <span class="payment-stat-tag">Awaiting Action</span>
                        <span class="payment-stat-icon"><i class="fas fa-clock"></i></span>
                    </div>
                    <h4>Pending Payments</h4>
                    <p class="payment-stat-value">KSH {{ formatCurrency(stats.pendingPayments) }}</p>
                    <span class="payment-stat-footnote">{{ pendingOfflineApprovals }} offline approvals need review</span>
                </article>

                <article class="payment-stat-card tone-blue">
                    <div class="payment-stat-topline">
                        <span class="payment-stat-tag">Payouts</span>
                        <span class="payment-stat-icon"><i class="fas fa-user-check"></i></span>
                    </div>
                    <h4>Paid to Technicians</h4>
                    <p class="payment-stat-value">KSH {{ formatCurrency(stats.paidToTechnicians) }}</p>
                    <span class="payment-stat-footnote">{{ completedTechPaymentsCount }} completed technician payouts</span>
                </article>

                <article class="payment-stat-card tone-slate">
                    <div class="payment-stat-topline">
                        <span class="payment-stat-tag">Spend</span>
                        <span class="payment-stat-icon"><i class="fas fa-receipt"></i></span>
                    </div>
                    <h4>Total Expenses</h4>
                    <p class="payment-stat-value">KSH {{ formatCurrency(stats.totalExpenses) }}</p>
                    <span class="payment-stat-footnote">{{ totalExpenditureCount }} expenditure record{{ totalExpenditureCount === 1 ? '' : 's' }}</span>
                </article>
            </section>

            <section v-if="selectedSR && budgetSummary" class="panel-section">
                <div class="panel-card budget-shell">
                    <div class="budget-header">
                        <div>
                            <span class="section-kicker">Budget Snapshot</span>
                            <h3>{{ selectedServiceRequestLabel }}</h3>
                            <p>Compare planned spend against actual spend across labor, materials, and other costs.</p>
                        </div>
                        <button @click="openBudgetModal" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit"></i>
                            Edit Budget
                        </button>
                    </div>

                    <div class="budget-grid">
                        <article v-for="card in budgetCards" :key="card.key" class="budget-card">
                            <div class="budget-card-topline">
                                <span class="budget-card-title">{{ card.label }}</span>
                                <span class="budget-card-percent">{{ card.percent.toFixed(0) }}%</span>
                            </div>
                            <strong>KSH {{ formatCurrency(card.actual) }}</strong>
                            <span>Budgeted: KSH {{ formatCurrency(card.budgeted) }}</span>
                            <span :class="card.remaining < 0 ? 'negative-text' : 'positive-text'">
                                Remaining: KSH {{ formatCurrency(card.remaining) }}
                            </span>
                            <div class="budget-progress-track">
                                <div
                                    class="budget-progress-fill"
                                    :style="{
                                        width: Math.min(card.percent, 100) + '%',
                                        backgroundColor: getProgressColor(card.percent),
                                    }"
                                ></div>
                            </div>
                        </article>
                    </div>

                    <div class="table-shell budget-table-shell">
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
                                    <td class="capitalize-cell"><strong>{{ cat }}</strong></td>
                                    <td>KSH {{ formatCurrency(budgetSummary[cat].budgeted) }}</td>
                                    <td>KSH {{ formatCurrency(budgetSummary[cat].actual) }}</td>
                                    <td :class="budgetSummary[cat].remaining < 0 ? 'negative-text' : 'positive-text'">
                                        KSH {{ formatCurrency(budgetSummary[cat].remaining) }}
                                    </td>
                                    <td>
                                        <div class="progress-bar-container">
                                            <div
                                                class="progress-bar-fill"
                                                :style="{
                                                    width: Math.min(getPercentUsed(budgetSummary[cat]), 100) + '%',
                                                    backgroundColor: getProgressColor(getPercentUsed(budgetSummary[cat])),
                                                }"
                                            ></div>
                                            <span class="progress-text">{{ getPercentUsed(budgetSummary[cat]).toFixed(0) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="summary-row">
                                    <td><strong>Total</strong></td>
                                    <td>KSH {{ formatCurrency(budgetSummary.total.budgeted) }}</td>
                                    <td>KSH {{ formatCurrency(budgetSummary.total.actual) }}</td>
                                    <td :class="budgetSummary.total.remaining < 0 ? 'negative-text' : 'positive-text'">
                                        KSH {{ formatCurrency(budgetSummary.total.remaining) }}
                                    </td>
                                    <td>
                                        <div class="progress-bar-container">
                                            <div
                                                class="progress-bar-fill"
                                                :style="{
                                                    width: Math.min(getPercentUsed(budgetSummary.total), 100) + '%',
                                                    backgroundColor: getProgressColor(getPercentUsed(budgetSummary.total)),
                                                }"
                                            ></div>
                                            <span class="progress-text">{{ getPercentUsed(budgetSummary.total).toFixed(0) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section v-if="selectedSR && !budgetSummary" class="panel-section">
                <div class="panel-card budget-empty-state">
                    <i class="fas fa-calculator"></i>
                    <div>
                        <h3>No budget set yet</h3>
                        <p>Add a budget for this service request to compare planned and actual spend.</p>
                    </div>
                    <button @click="openBudgetModal" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i>
                        Set Budget
                    </button>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card workspace-shell">
                    <div class="workspace-header">
                        <div>
                            <span class="section-kicker">Records</span>
                            <h3>{{ activeTabMeta.label }}</h3>
                            <p>{{ activeTabMeta.description }}</p>
                        </div>

                        <div class="header-actions">
                            <button v-if="activeTab === 'technician'" @click="openTechPaymentModal" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i>
                                Record Payment
                            </button>
                            <button v-if="activeTab === 'expenditures'" @click="openExpenditureModal()" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus"></i>
                                Add Expenditure
                            </button>
                        </div>
                    </div>

                    <div class="tab-nav modern-tab-nav">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            :class="['tab-btn', { active: activeTab === tab.key }]"
                            @click="activeTab = tab.key"
                        >
                            <span class="tab-btn-main">
                                <i :class="tab.icon"></i>
                                {{ tab.label }}
                            </span>
                            <span class="tab-btn-count">{{ getTabCount(tab.key) }}</span>
                        </button>
                    </div>

                    <div v-if="activeTab === 'client'" class="tab-panel">
                        <!-- Pending offline verifications: cards, always visible,
                             View Proof + Approve inline. Buried before this
                             because the Actions column on the wide table got
                             clipped on mobile (table-shell overflow: hidden)
                             — ops had no way to reach the button on a phone. -->
                        <section v-if="pendingOfflineVerifications.length" class="pending-verifications-panel">
                            <div class="pending-verifications-header">
                                <div>
                                    <h3><i class="fas fa-triangle-exclamation"></i> Payments Awaiting Verification</h3>
                                    <p>Clients have submitted proof of payment. Review the POP and approve to move the job to the next stage.</p>
                                </div>
                                <span class="pending-verifications-count">{{ pendingOfflineVerifications.length }}</span>
                            </div>
                            <ul class="pending-verifications-list">
                                <li v-for="p in pendingOfflineVerifications" :key="p.id" class="pending-verifications-item">
                                    <div class="pv-item-copy">
                                        <div class="pv-item-title">
                                            <strong>{{ p.user?.name || 'Unknown client' }}</strong>
                                            <span class="pv-item-meta">
                                                {{ p.service_request?.request_id || '—' }}
                                                · {{ formatPaymentMethod(p.payment_method) }}
                                                · Submitted {{ formatDate(p.updated_at || p.created_at) }}
                                            </span>
                                        </div>
                                        <div class="pv-item-amount">KSH {{ formatCurrency(p.amount) }}</div>
                                        <div v-if="p.cheque_number || p.bank_reference" class="pv-item-ref">
                                            <span v-if="p.cheque_number"><strong>Cheque:</strong> {{ p.cheque_number }}</span>
                                            <span v-if="p.bank_reference"><strong>Bank ref:</strong> {{ p.bank_reference }}</span>
                                        </div>
                                        <div v-if="p.notes" class="pv-item-notes">"{{ p.notes }}"</div>
                                    </div>
                                    <div class="pv-item-actions">
                                        <a
                                            v-if="p.evidence_path"
                                            :href="'/storage/' + p.evidence_path"
                                            target="_blank"
                                            rel="noopener"
                                            class="btn btn-primary pv-action"
                                        >
                                            <i class="fas fa-file-image"></i> View POP
                                        </a>
                                        <span v-else class="pv-no-proof" title="Client didn't attach a proof of payment file">
                                            <i class="fas fa-file-circle-question"></i> No POP attached
                                        </span>
                                        <button
                                            type="button"
                                            class="btn btn-success pv-action"
                                            @click="approveOfflinePayment(p)"
                                        >
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-secondary pv-action"
                                            @click="rejectOfflinePayment(p)"
                                            title="Reject payment (e.g. wrong amount / fake POP)"
                                        >
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </section>

                        <!-- Recently-approved offline payments (last 30 days).
                             Kept visible in a green sibling panel so ops can
                             reverse an approval that later turns out to be
                             wrong (bounced cheque, faked POP, wrong amount).
                             Empty-state renders a helpful note so the ops
                             team knows the section exists even when there's
                             nothing to reverse — solves the 'desktop is not
                             visible' feedback where the amber panel hid
                             entirely once no pending items remained. -->
                        <section class="recently-approved-panel">
                            <div class="recently-approved-header">
                                <div>
                                    <h3><i class="fas fa-clock-rotate-left"></i> Recently Approved Offline Payments</h3>
                                    <p>Last 30 days. Reverse an approval here if a cheque bounced or a POP turned out to be wrong.</p>
                                </div>
                                <span v-if="recentlyApprovedOfflinePayments.length" class="recently-approved-count">
                                    {{ recentlyApprovedOfflinePayments.length }}
                                </span>
                            </div>
                            <ul v-if="recentlyApprovedOfflinePayments.length" class="pending-verifications-list">
                                <li v-for="p in recentlyApprovedOfflinePayments" :key="p.id" class="pending-verifications-item ra-item">
                                    <div class="pv-item-copy">
                                        <div class="pv-item-title">
                                            <strong>{{ p.user?.name || 'Unknown client' }}</strong>
                                            <span class="pv-item-meta">
                                                {{ p.service_request?.request_id || '—' }}
                                                · {{ formatPaymentMethod(p.payment_method) }}
                                                · Approved {{ formatDate(p.paid_at || p.updated_at) }}
                                            </span>
                                        </div>
                                        <div class="pv-item-amount">KSH {{ formatCurrency(p.amount) }}</div>
                                        <div v-if="p.cheque_number || p.bank_reference" class="pv-item-ref">
                                            <span v-if="p.cheque_number"><strong>Cheque:</strong> {{ p.cheque_number }}</span>
                                            <span v-if="p.bank_reference"><strong>Bank ref:</strong> {{ p.bank_reference }}</span>
                                        </div>
                                    </div>
                                    <div class="pv-item-actions">
                                        <a
                                            v-if="p.evidence_path"
                                            :href="'/storage/' + p.evidence_path"
                                            target="_blank"
                                            rel="noopener"
                                            class="btn btn-primary pv-action"
                                        >
                                            <i class="fas fa-file-image"></i> View POP
                                        </a>
                                        <button
                                            type="button"
                                            class="btn btn-danger pv-action"
                                            @click="rejectOfflinePayment(p)"
                                            title="Reverse this approval (audit-logged). Use for bounced cheques, wrong amount, or fake POP."
                                        >
                                            <i class="fas fa-rotate-left"></i> Reverse Approval
                                        </button>
                                    </div>
                                </li>
                            </ul>
                            <p v-else class="recently-approved-empty">
                                No offline payments approved in the last 30 days.
                            </p>
                        </section>

                        <div class="table-shell" v-if="allClientPayments.length">
                            <table class="data-table">
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
                                        <td>{{ formatPaymentMethod(p.payment_method || p.type) }}</td>
                                        <td>
                                            <span :class="['status', getStatusClass(p.status)]">
                                                {{ formatStatusLabel(p.status) }}
                                            </span>
                                        </td>
                                        <td>{{ formatDate(p.paid_at || p.created_at) }}</td>
                                        <td>
                                            <div class="row-actions">
                                                <button
                                                    v-if="p.type === 'payment_request' && p.status === 'pending' && ['cash', 'cheque', 'bank_deposit'].includes(p.payment_method)"
                                                    @click="viewPaymentDetails(p)"
                                                    class="btn btn-sm btn-info"
                                                >
                                                    <i class="fas fa-eye"></i>
                                                    View
                                                </button>
                                                <button
                                                    v-if="p.type === 'payment_request' && p.status === 'pending' && ['cash', 'cheque', 'bank_deposit'].includes(p.payment_method)"
                                                    @click="approveOfflinePayment(p)"
                                                    class="btn btn-sm btn-success-soft"
                                                >
                                                    <i class="fas fa-check"></i>
                                                    Approve
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="empty-state">
                            <i class="fas fa-money-bill-wave"></i>
                            <p>No client payments found.</p>
                        </div>
                    </div>

                    <div v-if="activeTab === 'technician'" class="tab-panel">
                        <div class="table-shell" v-if="technicianPayments.length">
                            <table class="data-table">
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
                                            <span :class="['status', `category-${tp.category}`, 'capitalize-cell']">
                                                {{ tp.category }}
                                            </span>
                                        </td>
                                        <td>KSH {{ formatCurrency(tp.amount) }}</td>
                                        <td>
                                            <span :class="['status', getStatusClass(tp.status)]">
                                                {{ formatStatusLabel(tp.status) }}
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
                                                <span v-else class="status approved compact-status">Done</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-else class="empty-state">
                            <i class="fas fa-user-check"></i>
                            <p>No technician payments found.</p>
                        </div>
                    </div>

                    <div v-if="activeTab === 'expenditures'" class="tab-panel">
                        <div class="table-shell" v-if="expenditures.length">
                            <table class="data-table">
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
                                        <td class="capitalize-cell">{{ exp.category }}</td>
                                        <td>{{ truncate(exp.description, 40) }}</td>
                                        <td>{{ exp.vendor || '-' }}</td>
                                        <td>KSH {{ formatCurrency(exp.amount) }}</td>
                                        <td>{{ formatDate(exp.expense_date || exp.created_at) }}</td>
                                        <td>
                                            <div class="row-actions">
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
                        </div>
                        <div v-else class="empty-state">
                            <i class="fas fa-receipt"></i>
                            <p>No expenditures found.</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Budget Modal -->
        <div v-if="showBudgetModal" class="modal-overlay">
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
        <div v-if="showTechPaymentModal" class="modal-overlay">
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
        <div v-if="showExpenditureModal" class="modal-overlay">
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
        <!-- Payment Details Modal -->
        <div v-if="showPaymentDetailModal" class="modal-overlay">
            <div class="modal-content" @click.stop style="max-width: 600px;">
                <div class="modal-header">
                    <h3>Payment Details</h3>
                    <button @click="showPaymentDetailModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body" v-if="selectedPaymentDetail">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>Payment ID</label>
                            <span>{{ selectedPaymentDetail.payment_request_id || selectedPaymentDetail.payment_id || '-' }}</span>
                        </div>
                        <div class="detail-item">
                            <label>Client</label>
                            <span>{{ selectedPaymentDetail.user?.name || '-' }}</span>
                        </div>
                        <div class="detail-item">
                            <label>Amount</label>
                            <span><strong>KSH {{ formatCurrency(selectedPaymentDetail.amount) }}</strong></span>
                        </div>
                        <div class="detail-item">
                            <label>Method</label>
                            <span>{{ formatPaymentMethod(selectedPaymentDetail.payment_method) }}</span>
                        </div>
                        <div class="detail-item" v-if="selectedPaymentDetail.bank_reference">
                            <label>Bank Reference</label>
                            <span>{{ selectedPaymentDetail.bank_reference }}</span>
                        </div>
                        <div class="detail-item" v-if="selectedPaymentDetail.cheque_number">
                            <label>Cheque Number</label>
                            <span>{{ selectedPaymentDetail.cheque_number }}</span>
                        </div>
                        <div class="detail-item" v-if="selectedPaymentDetail.notes">
                            <label>Notes</label>
                            <span>{{ selectedPaymentDetail.notes }}</span>
                        </div>
                        <div class="detail-item">
                            <label>Status</label>
                            <span :class="['status', getStatusClass(selectedPaymentDetail.status)]">
                                {{ selectedPaymentDetail.status }}
                            </span>
                        </div>
                        <div class="detail-item">
                            <label>Date</label>
                            <span>{{ formatDate(selectedPaymentDetail.paid_at || selectedPaymentDetail.created_at) }}</span>
                        </div>
                    </div>

                    <!-- Evidence Attachment -->
                    <div v-if="selectedPaymentDetail.evidence_path" class="evidence-section">
                        <label><i class="fas fa-paperclip"></i> Payment Evidence</label>
                        <div class="evidence-preview">
                            <img
                                v-if="isImage(selectedPaymentDetail.evidence_path)"
                                :src="'/storage/' + selectedPaymentDetail.evidence_path"
                                alt="Payment evidence"
                                class="evidence-image"
                                @click="openEvidence(selectedPaymentDetail.evidence_path)"
                            >
                            <div v-else class="evidence-file">
                                <i class="fas fa-file-pdf" style="font-size: 2rem; color: #e53e3e;"></i>
                                <span>PDF Document</span>
                            </div>
                            <a
                                :href="'/storage/' + selectedPaymentDetail.evidence_path"
                                target="_blank"
                                class="btn btn-sm btn-info"
                                style="margin-top: 0.5rem;"
                            >
                                <i class="fas fa-external-link-alt"></i> Open / Download
                            </a>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showPaymentDetailModal = false" class="btn btn-secondary">Close</button>
                    <button
                        v-if="selectedPaymentDetail && selectedPaymentDetail.status === 'pending'"
                        @click="showPaymentDetailModal = false; approveOfflinePayment(selectedPaymentDetail)"
                        class="btn btn-primary"
                        style="background-color: #10b981;"
                    >
                        <i class="fas fa-check"></i> Approve Payment
                    </button>
                </div>
            </div>
        </div>

        <!-- Offline payment confirmation modal (evidence upload) -->
        <div v-if="showConfirmModal" class="modal-overlay" @click.self="showConfirmModal = false">
            <div class="modal-content" style="max-width:480px;">
                <div class="modal-header">
                    <h3>Confirm Offline Payment</h3>
                    <button @click="showConfirmModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p>
                        Approving <strong>{{ formatPaymentMethod(confirmingPayment?.payment_method) }}</strong>
                        payment of <strong>KSH {{ formatCurrency(confirmingPayment?.amount) }}</strong>.
                    </p>
                    <div class="form-group" style="margin-top:1rem;">
                        <label>Proof of Payment (optional for cash, required for Cheque / Bank Transfer)</label>
                        <input
                            ref="confirmEvidenceInput"
                            type="file"
                            accept="image/jpeg,image/png,application/pdf"
                            @change="onConfirmEvidenceChange"
                            class="form-control"
                            style="margin-top:.4rem;"
                        />
                        <small v-if="confirmEvidenceFile" style="color:var(--success-color)">
                            {{ confirmEvidenceFile.name }}
                        </small>
                    </div>
                    <div class="form-group" style="margin-top:.75rem;">
                        <label>Notes (optional)</label>
                        <input v-model="confirmNotes" type="text" class="form-control" placeholder="e.g. Cheque #12345 received" style="margin-top:.4rem;" />
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showConfirmModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="submitOfflineApproval" :disabled="confirmSubmitting" class="btn btn-primary" style="background:#10b981;">
                        <i class="fas fa-check"></i>
                        {{ confirmSubmitting ? 'Saving...' : 'Confirm Payment' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
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
// Coerce to Number so Vue v-model matches numeric option values after page reload (#43)
const selectedTechnician = ref(props.filters?.technician_id ? Number(props.filters.technician_id) : '')
const fromDate = ref(props.filters?.from || '')
const toDate = ref(props.filters?.to || '')
// Auto-show technician tab when a technician filter is active with no SR filter (#43)
const activeTab = ref(props.filters?.technician_id && !props.filters?.service_request_id ? 'technician' : 'client')
const showPaymentDetailModal = ref(false)
const selectedPaymentDetail = ref(null)

const tabs = [
    { key: 'client', label: 'Client Payments', icon: 'fas fa-money-bill-wave', description: 'Review received payments, pending requests, and offline approvals.' },
    { key: 'technician', label: 'Technician Payments', icon: 'fas fa-user-check', description: 'Track technician payouts and update their processing status.' },
    { key: 'expenditures', label: 'Expenditures', icon: 'fas fa-receipt', description: 'Review recorded spend across jobs, vendors, and categories.' },
]

// Merge payments + payment requests into one list for the client tab
const allClientPayments = computed(() => {
    const combined = [
        ...props.payments.map(p => ({ ...p, type: 'payment' })),
        ...props.paymentRequests.map(pr => ({ ...pr, type: 'payment_request', payment_id: pr.payment_request_id })),
    ]
    return combined.sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
})

const totalClientPaymentCount = computed(() => allClientPayments.value.length)
const completedTechPaymentsCount = computed(() => props.technicianPayments.filter(tp => tp.status === 'completed').length)
const totalExpenditureCount = computed(() => props.expenditures.length)
const pendingOfflineApprovals = computed(() =>
    props.paymentRequests.filter(pr =>
        pr.status === 'pending' &&
        ['cash', 'cheque', 'bank_deposit'].includes(pr.payment_method)
    ).length
)

// Same predicate but returns the FULL rows so the top-of-page verification
// panel can render each one with a View POP + Approve + Reject action.
// Sorted with the oldest first so the queue processes FIFO.
const pendingOfflineVerifications = computed(() =>
    props.paymentRequests
        .filter(pr =>
            pr.status === 'pending' &&
            ['cash', 'cheque', 'bank_deposit'].includes(pr.payment_method)
        )
        .sort((a, b) => new Date(a.updated_at || a.created_at) - new Date(b.updated_at || b.created_at))
)

// Recently-approved offline payments — kept visible in the panel for 30
// days after approval so ops can reverse a mistaken approval (e.g. cheque
// bounced, POP turned out to be fake) without hunting through the merged
// payments table. Newest first — reversals almost always target the most
// recent one.
const recentlyApprovedOfflinePayments = computed(() => {
    const thirtyDaysAgo = new Date()
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30)
    return props.paymentRequests
        .filter(pr =>
            pr.status === 'paid' &&
            ['cash', 'cheque', 'bank_deposit'].includes(pr.payment_method) &&
            new Date(pr.paid_at || pr.updated_at || pr.created_at) >= thirtyDaysAgo
        )
        .sort((a, b) => new Date(b.paid_at || b.updated_at || b.created_at) - new Date(a.paid_at || a.updated_at || a.created_at))
})

const selectedServiceRequestLabel = computed(() => {
    if (!selectedSR.value) return 'All service requests'

    const match = props.serviceRequests.find(sr => String(sr.id) === String(selectedSR.value))
    return match ? `${match.request_id}${match.description ? ` - ${truncate(match.description, 28)}` : ''}` : 'Selected service request'
})

const selectedTechnicianLabel = computed(() => {
    if (!selectedTechnician.value) return ''

    const match = props.technicians.find(tech => String(tech.id) === String(selectedTechnician.value))
    return match ? `Technician: ${match.user?.name || match.technician_id}` : 'Selected technician'
})

const activeFilterChips = computed(() => {
    const chips = []

    if (selectedSR.value) {
        chips.push(selectedServiceRequestLabel.value)
    }

    if (selectedTechnician.value) {
        chips.push(selectedTechnicianLabel.value)
    }

    return chips
})

const activeTabMeta = computed(() =>
    tabs.find(tab => tab.key === activeTab.value) || tabs[0]
)

const budgetCards = computed(() => {
    if (!props.budgetSummary) return []

    return [
        { key: 'labor', label: 'Labor', ...props.budgetSummary.labor, percent: getPercentUsed(props.budgetSummary.labor) },
        { key: 'materials', label: 'Materials', ...props.budgetSummary.materials, percent: getPercentUsed(props.budgetSummary.materials) },
        { key: 'other', label: 'Other', ...props.budgetSummary.other, percent: getPercentUsed(props.budgetSummary.other) },
        { key: 'total', label: 'Total', ...props.budgetSummary.total, percent: getPercentUsed(props.budgetSummary.total) },
    ]
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
const showConfirmModal = ref(false)
const confirmingPayment = ref(null)
const confirmEvidenceFile = ref(null)
const confirmEvidenceInput = ref(null)
const confirmNotes = ref('')
const confirmSubmitting = ref(false)

const approveOfflinePayment = (payment) => {
    confirmingPayment.value = payment
    confirmEvidenceFile.value = null
    confirmNotes.value = ''
    showConfirmModal.value = true
}

// Rejects a pending payment OR reverses an already-approved one — the
// backend picks the right transition based on current status. Uses two
// slightly different wordings so ops sees what they're about to do
// (irreversible reject vs recoverable reversal). Both require a reason
// that lands in the audit log.
const rejectOfflinePayment = async (payment) => {
    const isPaid = payment.status === 'paid'
    const heading = isPaid
        ? `Reverse the approval of KSH ${Number(payment.amount).toLocaleString()} from ${payment.user?.name || 'this client'}?\n\nThis rolls the service request back to Payment Pending Approval so you can act on it again.`
        : `Reject the payment of KSH ${Number(payment.amount).toLocaleString()} from ${payment.user?.name || 'this client'}?\n\nThe client will need to submit a fresh proof of payment.`
    const reason = prompt(heading + '\n\nEnter a reason (visible in the audit log):')
    if (reason === null) return
    if (!reason.trim()) {
        alert('A reason is required so ops can trace why this payment was ' + (isPaid ? 'reversed.' : 'rejected.'))
        return
    }
    try {
        const res = await axios.post(`/admin/payments/${payment.id}/reject`, { reason })
        alert(res.data?.message || (isPaid ? 'Approval reversed.' : 'Payment rejected.'))
        router.reload({ only: ['payments', 'paymentRequests', 'stats'] })
    } catch (error) {
        alert(error.response?.data?.error || 'Failed to process the rejection.')
    }
}

const onConfirmEvidenceChange = (e) => {
    confirmEvidenceFile.value = e.target.files?.[0] || null
}

const submitOfflineApproval = async () => {
    if (!confirmingPayment.value) return
    confirmSubmitting.value = true
    try {
        const formData = new FormData()
        if (confirmEvidenceFile.value) formData.append('evidence', confirmEvidenceFile.value)
        if (confirmNotes.value) formData.append('notes', confirmNotes.value)
        await axios.post(`/admin/payments/${confirmingPayment.value.id}/confirm`, formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
        showConfirmModal.value = false
        alert('Payment approved successfully!')
        router.reload({ only: ['payments', 'paymentRequests', 'stats'] })
    } catch (error) {
        alert(error.response?.data?.error || 'Failed to approve payment')
    } finally {
        confirmSubmitting.value = false
    }
}

// ---- Filter ----
// #43 — wire the date range through to the controller so the filter
// actually narrows results.
const filterByFilters = () => {
    const params = {}
    if (selectedSR.value) params.service_request_id = selectedSR.value
    if (selectedTechnician.value) params.technician_id = selectedTechnician.value
    if (fromDate.value) params.from = fromDate.value
    if (toDate.value) params.to = toDate.value

    router.get('/admin/payments', params, { preserveState: true, preserveScroll: true })
}

const clearFilters = () => {
    selectedSR.value = ''
    selectedTechnician.value = ''
    fromDate.value = ''
    toDate.value = ''
    router.get('/admin/payments', {}, { preserveState: true, preserveScroll: true })
}

const viewPaymentDetails = (payment) => {
    selectedPaymentDetail.value = payment
    showPaymentDetailModal.value = true
}

const formatPaymentMethod = (method) => {
    const map = {
        mpesa: 'M-Pesa',
        cheque: 'Cheque',
        cash: 'Cash',
        bank_deposit: 'Bank Deposit',
        payment_request: 'Payment Request',
        payment: 'Payment',
    }
    return map[method] || method || '-'
}

const isImage = (path) => {
    if (!path) return false
    return /\.(jpg|jpeg|png|gif|webp)$/i.test(path)
}

const openEvidence = (path) => {
    window.open('/storage/' + path, '_blank')
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

const formatStatusLabel = (status) => {
    if (!status) return '-'
    return status.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase())
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

const getTabCount = (tabKey) => {
    if (tabKey === 'client') return totalClientPaymentCount.value
    if (tabKey === 'technician') return props.technicianPayments.length
    if (tabKey === 'expenditures') return props.expenditures.length
    return 0
}
</script>

<style>

.payments-content {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.1), transparent 24rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.payments-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.75rem;
}

.payments-hero-copy,
.payments-filter-card,
.payment-stat-card,
.panel-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.payments-hero-copy {
    padding: 2rem;
    border-radius: 26px;
    background:
        linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92)),
        #00334b;
    color: #f8fafc;
}

.hero-kicker,
.section-kicker {
    display: inline-flex;
    align-items: center;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.hero-kicker {
    color: rgba(226, 232, 240, 0.82);
}

.payments-hero-copy h1 {
    margin: 0.9rem 0 0;
    color: #ffffff;
    font-size: clamp(2rem, 3vw, 2.5rem);
}

.payments-hero-copy p {
    margin: 0.9rem 0 0;
    color: rgba(226, 232, 240, 0.88);
    max-width: 38rem;
    line-height: 1.6;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.5rem;
}

.hero-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    padding: 0.75rem 1rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
    font-size: 0.88rem;
    font-weight: 600;
    color: #f8fafc;
}

.hero-pill.muted {
    background: rgba(15, 23, 42, 0.22);
}

.payments-filter-card {
    padding: 1.4rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.92);
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.filter-copy h3 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.filter-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.55;
    font-size: 0.92rem;
}

.section-kicker {
    color: #0f6c8f;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    font-size: 0.88rem;
    font-weight: 600;
    color: #334155;
}

.status-filter,
.status-select,
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.8rem 0.9rem;
    border: 1px solid #d7dee7;
    border-radius: 14px;
    background: #f8fafc;
    color: #0f172a;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
}

.status-filter:focus,
.status-select:focus,
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.45);
    box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12);
    background: #ffffff;
}

.filter-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
}

.filter-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 0.8rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0f6c8f;
    font-size: 0.82rem;
    font-weight: 700;
}

.filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.filter-button {
    min-width: 8rem;
    justify-content: center;
    border-radius: 999px;
}

.filter-button-secondary {
    margin: 0;
    background: #e2e8f0;
}

.filter-button-secondary:hover {
    background: #cbd5e1;
}

.payments-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.payment-stat-card {
    padding: 1.35rem;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.92);
}

.tone-green {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.96), #ffffff);
}

.tone-amber {
    background: linear-gradient(180deg, rgba(255, 251, 235, 0.98), #ffffff);
}

.tone-blue {
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.95), #ffffff);
}

.tone-slate {
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), #ffffff);
}

.payment-stat-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.95rem;
}

.payment-stat-tag {
    display: inline-flex;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
    color: #475569;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.payment-stat-icon {
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
}

.tone-green .payment-stat-icon {
    background: linear-gradient(135deg, #0f766e, #22c55e);
}

.tone-amber .payment-stat-icon {
    background: linear-gradient(135deg, #c2410c, #f59e0b);
}

.tone-slate .payment-stat-icon {
    background: linear-gradient(135deg, #475569, #94a3b8);
}

.payment-stat-card h4 {
    margin: 0;
    color: #475569;
    font-size: 0.92rem;
}

.payment-stat-value {
    margin: 0.45rem 0 0;
    font-size: 1.65rem;
    font-weight: 800;
    color: #0f172a;
}

.payment-stat-footnote {
    display: block;
    margin-top: 0.6rem;
    color: #64748b;
    font-size: 0.84rem;
    line-height: 1.45;
}

.budget-shell,
.workspace-shell {
    border-radius: 24px;
    padding: 1.4rem;
    background: rgba(255, 255, 255, 0.9);
}

.budget-header,
.workspace-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.budget-header h3,
.workspace-header h3 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.budget-header p,
.workspace-header p {
    margin: 0.4rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.budget-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.budget-card {
    padding: 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.budget-card-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.budget-card-title,
.budget-card-percent {
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #475569;
}

.budget-card strong {
    color: #0f172a;
    font-size: 1.1rem;
}

.budget-card span {
    color: #64748b;
    font-size: 0.84rem;
}

.budget-progress-track,
.progress-bar-container {
    position: relative;
    width: 100%;
    overflow: hidden;
    background: #e2e8f0;
}

.budget-progress-track {
    height: 0.55rem;
    border-radius: 999px;
    margin-top: 0.3rem;
}

.budget-progress-fill {
    height: 100%;
    border-radius: 999px;
}

.budget-table-shell {
    margin-top: 0.35rem;
}

.table-shell {
    /* Was overflow: hidden — which silently clipped the Actions column
       on mobile so ops literally couldn't reach the Approve button.
       Auto-scroll horizontally instead; the border-radius stays via
       the wrapper's own rounding. */
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 20px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
}

/* Pending verifications panel — top-of-tab card list surfacing offline
   payments that need approval. Amber tone so it reads as attention-
   worthy without being alarming. Renders in card layout on any width
   so the View POP + Approve buttons are always reachable. */
.pending-verifications-panel {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1px solid #fbbf24;
    border-radius: 16px;
    padding: 1.15rem 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 10px 22px -16px rgba(217, 119, 6, 0.35);
}
.pending-verifications-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}
.pending-verifications-header h3 {
    margin: 0 0 0.25rem;
    color: #78350f;
    font-size: 1.05rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.pending-verifications-header p {
    margin: 0;
    color: #92400e;
    font-size: 0.85rem;
    line-height: 1.4;
}
.pending-verifications-count {
    background: #dc2626;
    color: #fff;
    font-weight: 700;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    font-size: 0.85rem;
    min-width: 32px;
    text-align: center;
    flex-shrink: 0;
}

.pending-verifications-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.pending-verifications-item {
    display: flex;
    gap: 1rem;
    align-items: flex-start;
    justify-content: space-between;
    padding: 0.85rem 1rem;
    background: #ffffff;
    border: 1px solid #fde68a;
    border-radius: 12px;
    flex-wrap: wrap;
}
.pv-item-copy { flex: 1 1 260px; min-width: 0; }
.pv-item-title strong {
    color: #0f172a;
    display: block;
    font-size: 0.95rem;
}
.pv-item-meta {
    display: block;
    color: #6b7280;
    font-size: 0.78rem;
    margin-top: 0.2rem;
}
.pv-item-amount {
    margin-top: 0.4rem;
    font-size: 1.1rem;
    font-weight: 700;
    color: #065f46;
}
.pv-item-ref {
    margin-top: 0.35rem;
    font-size: 0.8rem;
    color: #374151;
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.pv-item-notes {
    margin-top: 0.35rem;
    padding: 0.35rem 0.55rem;
    background: #f8fafc;
    border-left: 3px solid #cbd5e1;
    border-radius: 4px;
    color: #374151;
    font-size: 0.82rem;
    font-style: italic;
}
.pv-item-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.pv-action {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.55rem 0.9rem;
    font-size: 0.85rem;
    white-space: nowrap;
}
.pv-no-proof {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.55rem 0.75rem;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
}
@media (max-width: 520px) {
    .pv-item-actions { width: 100%; }
    .pv-action, .pv-no-proof { flex: 1 1 auto; justify-content: center; }
}

/* Recently approved panel — sibling to the amber pending panel but green
   so it reads as "already handled, but here if you need to reverse".
   Always renders (with an empty-state note) so ops sees the section
   exists even when there's nothing to act on. */
.recently-approved-panel {
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    border: 1px solid #6ee7b7;
    border-radius: 16px;
    padding: 1.15rem 1.25rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 10px 22px -16px rgba(5, 150, 105, 0.25);
}
.recently-approved-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}
.recently-approved-header h3 {
    margin: 0 0 0.25rem;
    color: #065f46;
    font-size: 1.05rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.recently-approved-header p {
    margin: 0;
    color: #047857;
    font-size: 0.85rem;
    line-height: 1.4;
}
.recently-approved-count {
    background: #059669;
    color: #fff;
    font-weight: 700;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    font-size: 0.85rem;
    min-width: 32px;
    text-align: center;
    flex-shrink: 0;
}
.recently-approved-empty {
    margin: 0;
    padding: 0.85rem 1rem;
    background: #ffffff;
    border: 1px dashed #6ee7b7;
    border-radius: 12px;
    color: #047857;
    font-size: 0.88rem;
    text-align: center;
    font-style: italic;
}
/* Nudge the approved-panel rows so they read as green not amber. */
.ra-item { border-color: #a7f3d0 !important; }

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 0.95rem 1rem;
    border-bottom: 1px solid #edf2f7;
    vertical-align: middle;
}

.data-table th {
    background: #f8fafc;
    color: #64748b;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.data-table tbody tr:hover {
    background: rgba(248, 250, 252, 0.9);
}

.summary-row {
    background: #f8fafc;
    font-weight: 700;
}

.positive-text {
    color: #15803d;
}

.negative-text {
    color: #dc2626;
}

.capitalize-cell {
    text-transform: capitalize;
}

.budget-empty-state {
    border-radius: 24px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.95), #ffffff);
}

.budget-empty-state i {
    width: 3.25rem;
    height: 3.25rem;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e0f2fe;
    color: #0f6c8f;
    font-size: 1.25rem;
}

.budget-empty-state h3 {
    margin: 0;
    color: #0f172a;
}

.budget-empty-state p {
    margin: 0.35rem 0 0;
    color: #64748b;
}

.modern-tab-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.tab-btn {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.9rem;
    min-width: 13rem;
    padding: 0.85rem 1rem;
    border: 1px solid #d7dee7;
    border-radius: 18px;
    background: #f8fafc;
    cursor: pointer;
    color: #475569;
    transition: all 0.2s ease;
}

.tab-btn:hover {
    border-color: #93c5fd;
    background: #eff6ff;
}

.tab-btn.active {
    border-color: rgba(14, 116, 144, 0.25);
    background: linear-gradient(135deg, rgba(224, 242, 254, 0.96), rgba(236, 254, 255, 0.96));
    color: #0f172a;
}

.tab-btn-main {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    font-weight: 700;
}

.tab-btn-count {
    min-width: 2rem;
    height: 2rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(148, 163, 184, 0.18);
    font-size: 0.8rem;
    font-weight: 700;
}

.tab-panel {
    min-height: 16rem;
}

.row-actions,
.action-buttons {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
}

.status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.38rem 0.7rem;
    border-radius: 999px;
    font-size: 0.78rem;
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

.status.quoted {
    background: #dbeafe;
    color: #1d4ed8;
}

.status.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.category-labor {
    background: #e8f5e9;
    color: #2e7d32;
}

.category-materials {
    background: #e3f2fd;
    color: #1565c0;
}

.category-other {
    background: #fff3e0;
    color: #e65100;
}

.btn-success-soft {
    background: #10b981;
    color: #ffffff;
}

.btn-success-soft:hover {
    background: #059669;
}

.btn-danger {
    background: #e74c3c;
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
}

.btn-danger:hover {
    background: #c0392b;
}

.compact-status {
    font-size: 0.75rem;
}

.progress-bar-container {
    height: 1.1rem;
    border-radius: 999px;
    min-width: 7rem;
}

.progress-bar-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}

.progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.7rem;
    font-weight: 700;
    color: #1f2937;
}

.empty-state {
    padding: 3rem 1.5rem;
    text-align: center;
    color: #64748b;
}

.empty-state i {
    display: block;
    margin-bottom: 0.8rem;
    font-size: 2.5rem;
    opacity: 0.4;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.detail-item label {
    font-size: 0.76rem;
    color: #64748b;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.detail-item span {
    font-size: 0.95rem;
    color: #0f172a;
}

.evidence-section {
    border-top: 1px solid var(--border-color, #e5e7eb);
    padding-top: 1rem;
}

.evidence-section > label {
    display: block;
    font-weight: 700;
    margin-bottom: 0.5rem;
    color: #0f172a;
}

.evidence-preview {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
}

.evidence-image {
    max-width: 100%;
    max-height: 300px;
    border-radius: 12px;
    border: 1px solid var(--border-color, #e5e7eb);
    cursor: pointer;
    transition: opacity 0.2s;
}

.evidence-image:hover {
    opacity: 0.9;
}

.evidence-file {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem;
    background: #fef2f2;
    border-radius: 12px;
    border: 1px solid #fecaca;
}

@media (max-width: 1180px) {
    .payments-hero,
    .payments-stat-grid,
    .budget-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 860px) {
    .payments-hero,
    .payments-stat-grid,
    .budget-grid,
    .filter-grid,
    .detail-grid {
        grid-template-columns: 1fr;
    }

    .budget-header,
    .workspace-header,
    .budget-empty-state {
        flex-direction: column;
        align-items: flex-start;
    }

    .tab-btn {
        width: 100%;
    }
}
</style>
