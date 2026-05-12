<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="rfq" />

        <main class="main-content rfq-page">
            <!-- Hero Section -->
            <section class="rfq-hero">
                <div class="hero-copy">
                    <span class="hero-kicker">RFQ Management</span>
                    <h1>Review requests, quotations, and approvals with less clutter</h1>
                    <p>Track incoming service requests, respond faster, and keep payment follow-up visible from one cleaner workspace.</p>
                    <div class="hero-pills">
                        <span class="hero-pill">
                            <i class="fas fa-inbox"></i>
                            {{ stats.total || 0 }} total RFQs
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-clock"></i>
                            {{ stats.pending || 0 }} pending review
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-check-circle"></i>
                            {{ stats.approved || 0 }} approved
                        </span>
                    </div>
                </div>

                <div class="hero-action-card">
                    <div class="hero-action-copy">
                        <span class="section-kicker">Queue Health</span>
                        <h3>{{ rfqs.total }} request{{ rfqs.total === 1 ? '' : 's' }} matched</h3>
                        <p>{{ heroSummaryText }}</p>
                    </div>

                    <div class="hero-action-grid">
                        <div class="hero-action-tile">
                            <span>Awaiting quote</span>
                            <strong>{{ stats.pending || 0 }}</strong>
                        </div>
                        <div class="hero-action-tile">
                            <span>On this page</span>
                            <strong>{{ rfqs.data.length }}</strong>
                        </div>
                    </div>

                    <div class="hero-action-note">
                        <span>Current page</span>
                        <strong>{{ rfqs.last_page ? `${rfqs.current_page} / ${rfqs.last_page}` : '0 / 0' }}</strong>
                    </div>
                </div>
            </section>

            <!-- Stats Cards -->
            <section class="rfq-stats">
                <article class="stat-card tone-amber">
                    <div class="stat-topline">
                        <span class="stat-tag">Pending</span>
                        <span class="stat-icon pending"><i class="fas fa-clock"></i></span>
                    </div>
                    <h4>Pending Review</h4>
                    <p class="stat-value">{{ stats.pending || 0 }}</p>
                    <span class="stat-footnote">Requests waiting for quotation work.</span>
                </article>

                <article class="stat-card tone-blue">
                    <div class="stat-topline">
                        <span class="stat-tag">Quoted</span>
                        <span class="stat-icon quoted"><i class="fas fa-file-invoice"></i></span>
                    </div>
                    <h4>Awaiting Approval</h4>
                    <p class="stat-value">{{ stats.quoted || 0 }}</p>
                    <span class="stat-footnote">Quotes already sent to clients for review.</span>
                </article>

                <article class="stat-card tone-green">
                    <div class="stat-topline">
                        <span class="stat-tag">Approved</span>
                        <span class="stat-icon approved"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <h4>Approved RFQs</h4>
                    <p class="stat-value">{{ stats.approved || 0 }}</p>
                    <span class="stat-footnote">Ready for follow-up, assignment, or payment action.</span>
                </article>

                <article class="stat-card tone-slate">
                    <div class="stat-topline">
                        <span class="stat-tag">Value</span>
                        <span class="stat-icon value"><i class="fas fa-money-bill-wave"></i></span>
                    </div>
                    <h4>Approved Value</h4>
                    <p class="stat-value">KSH {{ formatCurrency(stats.totalValue || 0) }}</p>
                    <span class="stat-footnote">Approved quotation value across the RFQ pipeline.</span>
                </article>
            </section>

            <!-- Toolbar / Filters -->
            <section class="rfq-toolbar-section">
                <div class="panel-card toolbar-shell">
                    <div class="toolbar-header">
                        <div>
                            <span class="section-kicker">Filters</span>
                            <h3>Find the right request faster</h3>
                            <p>Search by client, request ID, service, description, or location, then refine the list with filters and page size.</p>
                        </div>
                    </div>

                    <div class="toolbar-grid">
                        <label class="search-shell toolbar-field toolbar-field-wide">
                            <i class="fas fa-search"></i>
                            <input
                                v-model="localSearch"
                                type="text"
                                placeholder="Search by client, request ID, service, location..."
                                @keyup.enter="applyFilters"
                            >
                        </label>

                        <label class="toolbar-field">
                            <span>Status</span>
                            <select v-model="localStatus" @change="applyFilters" class="status-filter">
                                <option value="all">All Requests</option>
                                <option value="pending">Pending Review</option>
                                <option value="quoted">Awaiting Client Approval</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </label>

                        <label class="toolbar-field">
                            <span>Sort</span>
                            <select v-model="localSort" @change="applyFilters" class="status-filter">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                            </select>
                        </label>

                        <label class="toolbar-field">
                            <span>Rows per page</span>
                            <select v-model.number="localPerPage" @change="applyFilters" class="status-filter">
                                <option :value="10">10</option>
                                <option :value="15">15</option>
                                <option :value="25">25</option>
                                <option :value="50">50</option>
                            </select>
                        </label>
                    </div>

                    <div v-if="activeFilterChips.length" class="filter-chip-row">
                        <span v-for="chip in activeFilterChips" :key="chip" class="filter-chip">{{ chip }}</span>
                        <button @click="clearFilters" class="clear-chip-btn">Clear filters</button>
                    </div>
                </div>
            </section>

            <!-- RFQ Table -->
            <section class="rfq-list-section">
                <div class="panel-card table-shell">
                    <div class="table-shell-header">
                        <div>
                            <span class="section-kicker">Requests</span>
                            <h3>RFQ workspace</h3>
                            <p>{{ paginationSummary }}</p>
                        </div>
                        <div class="table-summary-chips">
                            <span class="summary-chip">Pending: {{ stats.pending || 0 }}</span>
                            <span class="summary-chip muted">Quoted: {{ stats.quoted || 0 }}</span>
                        </div>
                    </div>

                    <!-- Desktop Table -->
                    <div v-if="rfqs.data.length" class="rfq-table desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Request</th>
                                    <th>Client</th>
                                    <th>Service</th>
                                    <th>Submitted</th>
                                    <th>Assigned PM</th>
                                    <th>Status</th>
                                    <th>Quote Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="rfq in rfqs.data" :key="rfq.id" @click="viewRFQ(rfq)" class="table-row-clickable">
                                    <td>
                                        <div class="request-cell">
                                            <strong>{{ rfq.request_id || `REQ-${rfq.id}` }}</strong>
                                            <span class="cell-subtext">{{ rfq.location || 'Location not specified' }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="client-info">
                                            <strong>{{ rfq.user?.name || 'N/A' }}</strong>
                                            <span class="cell-subtext">{{ rfq.user?.email || 'No email' }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="service-info">
                                            <strong>{{ rfq.service_category?.name || 'General Service' }}</strong>
                                            <span class="cell-subtext">{{ truncateText(rfq.description, 72) || 'No description provided' }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="request-cell">
                                            <strong>{{ formatDate(rfq.created_at) }}</strong>
                                            <span class="cell-subtext">{{ getDaysOpenLabel(rfq.created_at) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span v-if="rfq.assigned_pm" class="pm-badge">
                                            <i class="fas fa-user-tie"></i> {{ rfq.assigned_pm.name }}
                                        </span>
                                        <span v-else class="no-pm">Unassigned</span>
                                    </td>
                                    <td>
                                        <span :class="['status-badge', rfq.rfq_status || 'pending']">
                                            {{ getStatusLabel(rfq.rfq_status || 'pending') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span v-if="rfq.quote_amount" class="quote-amount-text">
                                            KSH {{ formatCurrency(rfq.quote_amount) }}
                                        </span>
                                        <span v-else class="no-quote">Not quoted</span>
                                    </td>
                                    <td @click.stop>
                                        <div class="action-buttons">
                                            <button @click="viewRFQ(rfq)" class="btn btn-sm btn-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button v-if="rfq.rfq_status === 'pending'" @click="reviewRFQ(rfq)" class="btn btn-sm btn-primary" title="Create Quote">
                                                <i class="fas fa-file-invoice-dollar"></i>
                                            </button>
                                            <button v-if="rfq.rfq_status === 'approved'" @click="initiatePaymentRequest(rfq)" class="btn btn-sm btn-success" title="Request Payment">
                                                <i class="fas fa-hand-holding-usd"></i>
                                            </button>
                                            <button v-if="rfq.rfq_status === 'quoted'" @click="viewRFQ(rfq)" class="btn btn-sm btn-success" title="View Quote">
                                                <i class="fas fa-receipt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Cards -->
                    <div v-if="rfqs.data.length" class="mobile-rfq-list">
                        <article v-for="rfq in rfqs.data" :key="rfq.id" class="mobile-rfq-card">
                            <div class="mobile-card-top">
                                <div>
                                    <h4>{{ rfq.request_id || `REQ-${rfq.id}` }}</h4>
                                    <p>{{ rfq.service_category?.name || 'General Service' }}</p>
                                </div>
                                <span :class="['status-badge', rfq.rfq_status || 'pending']">
                                    {{ getStatusLabel(rfq.rfq_status || 'pending') }}
                                </span>
                            </div>

                            <div class="mobile-rfq-grid">
                                <div>
                                    <span>Client</span>
                                    <strong>{{ rfq.user?.name || 'N/A' }}</strong>
                                </div>
                                <div>
                                    <span>Submitted</span>
                                    <strong>{{ formatDate(rfq.created_at) }}</strong>
                                </div>
                                <div>
                                    <span>Location</span>
                                    <strong>{{ rfq.location || 'Not specified' }}</strong>
                                </div>
                                <div>
                                    <span>Quote</span>
                                    <strong>{{ rfq.quote_amount ? `KSH ${formatCurrency(rfq.quote_amount)}` : 'Not quoted' }}</strong>
                                </div>
                            </div>

                            <p class="mobile-description">{{ truncateText(rfq.description, 120) || 'No description provided.' }}</p>

                            <div class="action-buttons mobile-actions">
                                <button @click="viewRFQ(rfq)" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button v-if="rfq.rfq_status === 'pending'" @click="reviewRFQ(rfq)" class="btn btn-sm btn-primary">
                                    <i class="fas fa-file-invoice-dollar"></i> Quote
                                </button>
                                <button v-if="rfq.rfq_status === 'approved'" @click="initiatePaymentRequest(rfq)" class="btn btn-sm btn-success">
                                    <i class="fas fa-hand-holding-usd"></i> Payment
                                </button>
                            </div>
                        </article>
                    </div>

                    <!-- Server-side Pagination -->
                    <div v-if="rfqs.data.length && rfqs.last_page > 1" class="pagination-shell">
                        <div class="pagination-info">
                            {{ paginationSummary }}
                        </div>

                        <div class="pagination-controls">
                            <button
                                @click="goToPage(1)"
                                :disabled="rfqs.current_page === 1"
                                class="btn-pagination"
                            >
                                <i class="fas fa-angle-double-left"></i>
                            </button>
                            <button
                                @click="goToPage(rfqs.current_page - 1)"
                                :disabled="rfqs.current_page === 1"
                                class="btn-pagination"
                            >
                                <i class="fas fa-angle-left"></i> Prev
                            </button>

                            <div class="page-number-row">
                                <button
                                    v-for="page in visiblePageNumbers"
                                    :key="page"
                                    @click="goToPage(page)"
                                    :class="['page-number-btn', { active: page === rfqs.current_page }]"
                                >
                                    {{ page }}
                                </button>
                            </div>

                            <button
                                @click="goToPage(rfqs.current_page + 1)"
                                :disabled="rfqs.current_page === rfqs.last_page"
                                class="btn-pagination"
                            >
                                Next <i class="fas fa-angle-right"></i>
                            </button>
                            <button
                                @click="goToPage(rfqs.last_page)"
                                :disabled="rfqs.current_page === rfqs.last_page"
                                class="btn-pagination"
                            >
                                <i class="fas fa-angle-double-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div v-if="rfqs.data.length === 0" class="no-data">
                        <i class="fas fa-inbox"></i>
                        <p>No RFQ requests found</p>
                        <span>Try clearing one or more filters to widen the list.</span>
                    </div>
                </div>
            </section>
        </main>

        <!-- View RFQ Details Modal -->
        <div v-if="showViewModal" class="modal-overlay" @click="closeViewModal">
            <div class="modal-content large" @click.stop>
                <div class="modal-header">
                    <h3>Service Request Details</h3>
                    <button @click="closeViewModal" class="modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="view-grid">
                        <div class="info-section">
                            <h4>Request Information</h4>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Request ID:</label>
                                    <span>{{ selectedRFQ?.request_id || `REQ-${selectedRFQ?.id}` }}</span>
                                </div>
                                <div class="info-item">
                                    <label>Client:</label>
                                    <span>{{ selectedRFQ?.user?.name }}</span>
                                </div>
                                <div class="info-item">
                                    <label>Email:</label>
                                    <span>{{ selectedRFQ?.user?.email }}</span>
                                </div>
                                <div class="info-item">
                                    <label>Service Category:</label>
                                    <span>{{ selectedRFQ?.service_category?.name }}</span>
                                </div>
                                <div class="info-item">
                                    <label>Date Requested:</label>
                                    <span>{{ formatDate(selectedRFQ?.created_at) }}</span>
                                </div>
                                <div class="info-item">
                                    <label>Status:</label>
                                    <span :class="['status-badge', selectedRFQ?.rfq_status || 'pending']">
                                        {{ getStatusLabel(selectedRFQ?.rfq_status || 'pending') }}
                                    </span>
                                </div>
                                <div class="info-item full-width">
                                    <label>Description:</label>
                                    <p>{{ selectedRFQ?.description }}</p>
                                </div>
                                <div class="info-item full-width" v-if="selectedRFQ?.location">
                                    <label>Location:</label>
                                    <p>{{ selectedRFQ?.location }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Attached Files -->
                        <div v-if="selectedRFQ?.files && selectedRFQ.files.length > 0" class="info-section">
                            <h4>Attached Files</h4>
                            <div class="files-grid">
                                <div v-for="(file, index) in selectedRFQ.files" :key="index" class="file-card">
                                    <div class="file-icon">
                                        <i class="fas fa-file-image" v-if="file.mime_type?.includes('image')"></i>
                                        <i class="fas fa-file-pdf" v-else-if="file.mime_type?.includes('pdf')"></i>
                                        <i class="fas fa-file-alt" v-else></i>
                                    </div>
                                    <div class="file-info">
                                        <div class="file-name" :title="file.name">{{ file.name || 'Attachment' }}</div>
                                        <a :href="`/storage/${file.path}`" target="_blank" download class="file-action">Download</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quote Information -->
                        <div v-if="selectedRFQ?.rfq_status === 'quoted' || selectedRFQ?.rfq_status === 'approved'" class="quote-section">
                            <h4>Quotation Details</h4>
                            <div class="quote-display">
                                <div class="quote-amount-bar">
                                    <span class="label">Total Amount:</span>
                                    <span class="amount">KSH {{ formatCurrency(selectedRFQ?.quote_amount) }}</span>
                                </div>

                                <div v-if="selectedRFQ?.quote_materials" class="materials-display">
                                    <h5>Materials:</h5>
                                    <div class="materials-list-view">
                                        <div v-for="material in selectedRFQ.quote_materials" :key="material.name" class="material-item-view">
                                            <span class="name">{{ material.name }}</span>
                                            <span class="details">{{ material.quantity }} x KSH {{ formatCurrency(material.unit_price) }} = KSH {{ formatCurrency(material.quantity * material.unit_price) }}</span>
                                        </div>
                                    </div>
                                    <div v-if="selectedRFQ?.quote_materials_file_path" style="margin-top: 0.75rem;">
                                        <a :href="`/storage/${selectedRFQ.quote_materials_file_path}`" target="_blank" style="display: inline-flex; align-items: center; gap: 0.5rem; color: var(--primary-color); text-decoration: none; font-weight: 500;">
                                            <i class="fas fa-paperclip"></i> View Attached Materials List
                                        </a>
                                    </div>
                                </div>

                                <div class="cost-summary">
                                    <div class="cost-line">
                                        <span>Labor Cost:</span>
                                        <span>KSH {{ formatCurrency(selectedRFQ?.quote_labor_cost || 0) }}</span>
                                    </div>
                                </div>

                                <div v-if="selectedRFQ?.quote_notes" class="quote-notes-display">
                                    <h5>Notes:</h5>
                                    <p>{{ selectedRFQ.quote_notes }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Rejection Reason -->
                        <div v-if="selectedRFQ?.rfq_status === 'rejected'" class="rejection-section">
                            <h4>Rejection Information</h4>
                            <div class="rejection-display">
                                <p><strong>Reason:</strong> {{ selectedRFQ?.rejection_reason }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button v-if="selectedRFQ?.rfq_status === 'pending'" @click="editRFQ" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Create Quotation
                    </button>
                    <button @click="closeViewModal" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>

        <!-- RFQ Review/Edit Modal -->
        <div v-if="showReviewModal" class="modal-overlay" @click="closeReviewModal">
            <div class="modal-content extra-large" @click.stop>
                <div class="modal-header">
                    <h3>Create Quotation - {{ selectedRFQ?.request_id || `REQ-${selectedRFQ?.id}` }}</h3>
                    <button @click="closeReviewModal" class="modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="request-summary">
                        <div class="summary-card">
                            <h4><i class="fas fa-user"></i> {{ selectedRFQ?.user?.name }}</h4>
                            <p><strong>Service:</strong> {{ selectedRFQ?.service_category?.name }}</p>
                            <p><strong>Description:</strong> {{ selectedRFQ?.description }}</p>
                            <p v-if="selectedRFQ?.location"><strong>Location:</strong> {{ selectedRFQ?.location }}</p>
                        </div>
                    </div>

                    <div class="quotation-form-section">
                        <form @submit.prevent="submitQuote">
                            <div class="form-section">
                                <h4><i class="fas fa-boxes"></i> Materials Required</h4>
                                <div class="materials-container">
                                    <div v-for="(material, index) in quotationForm.materials" :key="index" class="material-row">
                                        <div class="material-inputs">
                                            <input v-model="material.name" type="text" placeholder="Material name" class="form-control material-name" required>
                                            <input v-model="material.quantity" type="number" min="1" placeholder="Qty" class="form-control material-qty" required>
                                            <input v-model="material.unit_price" type="number" step="0.01" placeholder="Unit Price (KSH)" class="form-control material-price" required>
                                            <div class="material-total">KSH {{ formatCurrency((material.quantity || 0) * (material.unit_price || 0)) }}</div>
                                            <button @click="removeMaterial(index)" type="button" class="btn-remove" :disabled="quotationForm.materials.length === 1">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <button @click="addMaterial" type="button" class="btn btn-sm btn-outline-primary add-material-btn">
                                        <i class="fas fa-plus"></i> Add Another Material
                                    </button>
                                </div>
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label><i class="fas fa-file-upload"></i> Upload Materials List (Optional)</label>
                                    <input type="file" @change="e => quotationForm.materials_file = e.target.files[0]" accept=".pdf,.docx,.xlsx,.xls" class="form-control">
                                    <small style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                                        Upload PDF, DOCX, or Excel file if materials list is too long (Max 10MB)
                                    </small>
                                </div>
                            </div>

                            <div class="form-section">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-tools"></i> Labor Cost (KSH):</label>
                                        <input v-model="quotationForm.labor_cost" type="number" step="0.01" class="form-control" placeholder="0.00" required>
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-sticky-note"></i> Additional Notes:</label>
                                        <textarea v-model="quotationForm.notes" class="form-control" rows="3" placeholder="Any additional information for the client..."></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="cost-summary-section">
                                <div class="cost-summary-card">
                                    <h4><i class="fas fa-calculator"></i> Cost Summary</h4>
                                    <div class="cost-breakdown">
                                        <div class="cost-item">
                                            <span>Materials Total:</span>
                                            <span>KSH {{ formatCurrency(materialsTotal) }}</span>
                                        </div>
                                        <div class="cost-item">
                                            <span>Labor Cost:</span>
                                            <span>KSH {{ formatCurrency(quotationForm.labor_cost || 0) }}</span>
                                        </div>
                                        <div class="cost-item total">
                                            <span>Total Amount:</span>
                                            <span>KSH {{ formatCurrency(totalQuoteAmount) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" @click="rejectRFQ" class="btn btn-danger">
                        <i class="fas fa-times"></i> Reject Request
                    </button>
                    <button @click="closeReviewModal" class="btn btn-secondary">Cancel</button>
                    <button @click="submitQuote" class="btn btn-success" :disabled="!canSubmitQuote">
                        <i class="fas fa-paper-plane"></i> Send Quotation
                    </button>
                </div>
            </div>
        </div>

        <!-- Request Payment Modal -->
        <div v-if="showPaymentModal" class="modal-overlay" @click="closePaymentModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Request Payment</h3>
                    <button @click="closePaymentModal" class="modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form @submit.prevent="submitPaymentRequest">
                    <div class="modal-body">
                        <div class="payment-request-info">
                            <div class="info-row">
                                <span class="label">Request ID:</span>
                                <span class="value">{{ selectedRFQ?.request_id }}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Client:</span>
                                <span class="value">{{ selectedRFQ?.user?.name }}</span>
                            </div>
                            <div class="info-row">
                                <span class="label">Total Quote Amount:</span>
                                <span class="value highlight">KSH {{ formatCurrency(selectedRFQ?.quote_amount) }}</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Payment Percentage (%)</label>
                            <input
                                v-model.number="paymentRequestForm.percentage"
                                type="number"
                                min="1"
                                max="100"
                                class="form-control"
                                required
                            >
                        </div>

                        <div class="calculated-amount" v-if="paymentRequestForm.percentage > 0">
                            <span class="label">Amount to Request:</span>
                            <span class="amount">KSH {{ formatCurrency(calculatedPaymentAmount) }}</span>
                        </div>

                        <div class="form-group">
                            <label>Notes for Client (Optional)</label>
                            <textarea
                                v-model="paymentRequestForm.notes"
                                class="form-control"
                                rows="3"
                                placeholder="E.g., 50% deposit required before work begins..."
                            ></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" @click="closePaymentModal" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-success" :disabled="!canSubmitPaymentRequest || isSubmittingPayment">
                            <i class="fas fa-paper-plane"></i> {{ isSubmittingPayment ? 'Sending...' : 'Send Payment Request' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Rejection Modal -->
        <div v-if="showRejectModal" class="modal-overlay" @click="closeRejectModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Reject Service Request</h3>
                    <button @click="closeRejectModal" class="modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label>Reason for Rejection:</label>
                        <textarea v-model="rejectionReason" class="form-control" rows="4" placeholder="Please provide a reason for rejecting this request..." required></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button @click="closeRejectModal" class="btn btn-secondary">Cancel</button>
                    <button @click="confirmReject" class="btn btn-danger" :disabled="!rejectionReason.trim()">
                        <i class="fas fa-times"></i> Reject Request
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

const props = defineProps({
    rfqs: { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0 }) },
    stats: { type: Object, default: () => ({ pending: 0, quoted: 0, approved: 0, rejected: 0, total: 0, totalValue: 0 }) },
    filters: { type: Object, default: () => ({ search: '', status: 'all', sort: 'newest', per_page: 15 }) },
})

// Local filter state (bound to inputs)
const localSearch = ref(props.filters.search || '')
const localStatus = ref(props.filters.status || 'all')
const localSort = ref(props.filters.sort || 'newest')
const localPerPage = ref(props.filters.per_page || 15)

// Modal state
const showViewModal = ref(false)
const showReviewModal = ref(false)
const showRejectModal = ref(false)
const showPaymentModal = ref(false)
const selectedRFQ = ref(null)
const rejectionReason = ref('')
const isSubmittingPayment = ref(false)

const quotationForm = ref({
    materials: [{ name: '', quantity: 1, unit_price: 0 }],
    labor_cost: 0,
    notes: '',
    materials_file: null,
})

const paymentRequestForm = ref({
    percentage: 50,
    notes: '',
})

// --- Server-side pagination / filtering ---
const applyFilters = () => {
    router.get('/admin/rfq', {
        search: localSearch.value || undefined,
        status: localStatus.value !== 'all' ? localStatus.value : undefined,
        sort: localSort.value !== 'newest' ? localSort.value : undefined,
        per_page: localPerPage.value !== 15 ? localPerPage.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const goToPage = (page) => {
    if (page < 1 || page > props.rfqs.last_page) return
    router.get('/admin/rfq', {
        page,
        search: localSearch.value || undefined,
        status: localStatus.value !== 'all' ? localStatus.value : undefined,
        sort: localSort.value !== 'newest' ? localSort.value : undefined,
        per_page: localPerPage.value !== 15 ? localPerPage.value : undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const clearFilters = () => {
    localSearch.value = ''
    localStatus.value = 'all'
    localSort.value = 'newest'
    localPerPage.value = 15
    router.get('/admin/rfq', {}, { preserveState: true, preserveScroll: true, replace: true })
}

// --- Computed ---
const paginationSummary = computed(() => {
    if (!props.rfqs.total) return 'No RFQs match the current view.'
    return `Showing ${props.rfqs.from}-${props.rfqs.to} of ${props.rfqs.total} requests`
})

const heroSummaryText = computed(() => {
    if (!props.rfqs.total) return 'There are no RFQs in the current view. Adjust filters to widen the queue.'
    if (props.stats.pending > 0) return `${props.stats.pending} request${props.stats.pending === 1 ? '' : 's'} still need quotation work.`
    if (props.stats.quoted > 0) return `${props.stats.quoted} quoted request${props.stats.quoted === 1 ? '' : 's'} are waiting on client approval.`
    return 'The current RFQ list is mostly in completed downstream states.'
})

const visiblePageNumbers = computed(() => {
    const total = props.rfqs.last_page
    if (total <= 1) return []
    const windowSize = 5
    let start = Math.max(1, props.rfqs.current_page - 2)
    let end = Math.min(total, start + windowSize - 1)
    if (end - start + 1 < windowSize) start = Math.max(1, end - windowSize + 1)
    const pages = []
    for (let p = start; p <= end; p++) pages.push(p)
    return pages
})

const activeFilterChips = computed(() => {
    const chips = []
    if (localSearch.value) chips.push(`Search: ${localSearch.value}`)
    if (localStatus.value !== 'all') chips.push(`Status: ${getStatusLabel(localStatus.value)}`)
    if (localSort.value !== 'newest') chips.push('Sort: Oldest first')
    if (localPerPage.value !== 15) chips.push(`Rows: ${localPerPage.value}`)
    return chips
})

const materialsTotal = computed(() => quotationForm.value.materials.reduce((t, m) => t + (m.quantity * m.unit_price), 0))
const totalQuoteAmount = computed(() => materialsTotal.value + (quotationForm.value.labor_cost || 0))
const canSubmitQuote = computed(() => quotationForm.value.materials.some(m => m.name && m.quantity > 0 && m.unit_price > 0) && quotationForm.value.labor_cost >= 0)
const calculatedPaymentAmount = computed(() => {
    if (!selectedRFQ.value?.quote_amount || !paymentRequestForm.value.percentage) return 0
    return (paymentRequestForm.value.percentage / 100) * selectedRFQ.value.quote_amount
})
const canSubmitPaymentRequest = computed(() => paymentRequestForm.value.percentage > 0 && paymentRequestForm.value.percentage <= 100 && selectedRFQ.value?.id)

// --- Modal actions ---
const viewRFQ = (rfq) => { selectedRFQ.value = rfq; showViewModal.value = true }
const closeViewModal = () => { showViewModal.value = false; selectedRFQ.value = null }
const editRFQ = () => { showViewModal.value = false; showReviewModal.value = true; resetQuotationForm() }
const reviewRFQ = (rfq) => { selectedRFQ.value = rfq; resetQuotationForm(); showReviewModal.value = true }
const closeReviewModal = () => { showReviewModal.value = false; selectedRFQ.value = null; resetQuotationForm() }
const closeRejectModal = () => { showRejectModal.value = false; rejectionReason.value = '' }
const rejectRFQ = () => { showRejectModal.value = true }

const initiatePaymentRequest = (rfq) => {
    selectedRFQ.value = rfq
    paymentRequestForm.value = { percentage: 50, notes: '' }
    showPaymentModal.value = true
}
const closePaymentModal = () => { showPaymentModal.value = false; selectedRFQ.value = null }

const resetQuotationForm = () => {
    quotationForm.value = { materials: [{ name: '', quantity: 1, unit_price: 0 }], labor_cost: 0, notes: '', materials_file: null }
}

const addMaterial = () => { quotationForm.value.materials.push({ name: '', quantity: 1, unit_price: 0 }) }
const removeMaterial = (index) => { if (quotationForm.value.materials.length > 1) quotationForm.value.materials.splice(index, 1) }

const submitQuote = () => {
    router.post('/admin/rfq/quote', {
        service_request_id: selectedRFQ.value.id,
        materials: quotationForm.value.materials.filter(m => m.name && m.quantity > 0),
        labor_cost: quotationForm.value.labor_cost,
        total_amount: totalQuoteAmount.value,
        notes: quotationForm.value.notes,
        materials_file: quotationForm.value.materials_file,
    }, {
        preserveState: false,
        onSuccess: () => closeReviewModal(),
        onError: (errors) => console.error('Quote submission failed:', errors),
    })
}

const confirmReject = () => {
    router.post(`/admin/rfq/${selectedRFQ.value.id}/reject`, { reason: rejectionReason.value }, {
        preserveState: false,
        onSuccess: () => { closeRejectModal(); closeReviewModal() },
        onError: (errors) => console.error('Rejection failed:', errors),
    })
}

const submitPaymentRequest = () => {
    if (!selectedRFQ.value) return
    isSubmittingPayment.value = true
    axios.post(`/admin/rfq/${selectedRFQ.value.id}/request-payment`, {
        percentage: paymentRequestForm.value.percentage,
        notes: paymentRequestForm.value.notes,
    }).then(response => {
        if (response.data.success) {
            alert('Payment request sent successfully!')
            closePaymentModal()
            router.reload()
        }
    }).catch(error => {
        console.error('Payment request error:', error)
        alert(error.response?.data?.error || 'Failed to send payment request.')
    }).finally(() => { isSubmittingPayment.value = false })
}

// --- Helpers ---
const formatDate = (date) => date ? new Date(date).toLocaleDateString() : 'N/A'
const formatCurrency = (amount) => new Intl.NumberFormat('en-KE').format(amount || 0)
const truncateText = (text, length) => (!text ? '' : text.length > length ? text.substring(0, length) + '...' : text)

const getDaysOpenLabel = (date) => {
    if (!date) return 'Date unavailable'
    const days = Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 86400000))
    if (days === 0) return 'Opened today'
    if (days === 1) return 'Opened 1 day ago'
    return `Opened ${days} days ago`
}

const getStatusLabel = (status) => ({ pending: 'Pending Review', quoted: 'Awaiting Approval', approved: 'Approved', rejected: 'Rejected' })[status] || 'Unknown'

defineOptions({ layout: null })
</script>

<style>
@import url('../../../css/dashboard-app.css');

.rfq-page {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.1), transparent 24rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.rfq-hero, .rfq-stats, .hero-action-grid, .toolbar-grid, .mobile-rfq-grid {
    display: grid;
    gap: 1rem;
}

.rfq-hero {
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.hero-copy, .hero-action-card, .stat-card, .toolbar-shell, .table-shell, .mobile-rfq-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.hero-copy {
    padding: 2rem;
    border-radius: 26px;
    background: linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92)), #00334b;
    color: #f8fafc;
}

.hero-kicker, .section-kicker {
    display: inline-flex;
    align-items: center;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.hero-kicker { color: rgba(226, 232, 240, 0.82); }
.section-kicker { color: #0f6c8f; }

.hero-copy h1 { margin: 0.85rem 0 0; color: #ffffff; font-size: clamp(2rem, 3vw, 2.5rem); }
.hero-copy p { margin: 0.9rem 0 0; max-width: 42rem; color: rgba(226, 232, 240, 0.88); line-height: 1.6; }

.hero-pills, .filter-chip-row, .table-summary-chips, .action-buttons, .mobile-actions, .pagination-controls, .page-number-row, .pagination-shell {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
}
.hero-pills { margin-top: 1.5rem; }

.hero-pill, .summary-chip, .filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 0.9rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
}
.hero-pill { background: rgba(255, 255, 255, 0.12); color: #f8fafc; }
.hero-pill.muted { background: rgba(15, 23, 42, 0.24); }

.hero-action-card, .toolbar-shell, .table-shell { padding: 1.4rem; border-radius: 24px; background: rgba(255, 255, 255, 0.94); }
.hero-action-copy h3, .toolbar-header h3, .table-shell-header h3 { margin: 0.35rem 0 0; color: #0f172a; }
.hero-action-copy p, .toolbar-header p, .table-shell-header p { margin: 0.45rem 0 0; color: #64748b; line-height: 1.55; }

.hero-action-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 1rem 0 1.1rem; }
.hero-action-tile, .hero-action-note, .mobile-rfq-grid > div { padding: 1rem; border-radius: 18px; background: #f8fafc; border: 1px solid #e2e8f0; }
.hero-action-tile span, .hero-action-note span, .toolbar-field span, .cell-subtext, .mobile-rfq-grid span, .pagination-info, .no-data span { color: #64748b; font-size: 0.8rem; }
.hero-action-tile strong, .hero-action-note strong, .mobile-rfq-grid strong { display: block; margin-top: 0.35rem; color: #0f172a; }

.rfq-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom: 1.5rem; }
.stat-card { padding: 1.35rem; border-radius: 22px; position: relative; overflow: hidden; }
.tone-amber { background: linear-gradient(180deg, rgba(255, 251, 235, 0.98), #ffffff); }
.tone-blue { background: linear-gradient(180deg, rgba(239, 246, 255, 0.98), #ffffff); }
.tone-green { background: linear-gradient(180deg, rgba(240, 253, 244, 0.98), #ffffff); }
.tone-slate { background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), #ffffff); }

.stat-topline { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.9rem; }
.stat-tag { display: inline-flex; padding: 0.35rem 0.65rem; border-radius: 999px; background: rgba(255, 255, 255, 0.8); color: #475569; font-size: 0.74rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }
.stat-card h4 { margin: 0; color: #475569; font-size: 0.92rem; }
.stat-value { margin: 0.45rem 0 0; font-size: 1.8rem; font-weight: 800; color: #0f172a; }
.stat-footnote { display: block; margin-top: 0.55rem; color: #64748b; font-size: 0.84rem; line-height: 1.45; }
.stat-icon { width: 2.8rem; height: 2.8rem; border-radius: 16px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1rem; }
.stat-icon.pending { background: #F97316; }
.stat-icon.quoted { background: #2563EB; }
.stat-icon.approved { background: #16A34A; }
.stat-icon.value { background: #9333EA; }

.rfq-toolbar-section { margin-bottom: 1.5rem; }
.toolbar-grid { grid-template-columns: 2fr 1fr 1fr 0.8fr; margin-top: 1rem; }
.toolbar-field { display: flex; flex-direction: column; gap: 0.45rem; font-weight: 700; color: #334155; }
.search-shell { display: inline-flex; align-items: center; gap: 0.65rem; padding: 0.82rem 0.95rem; border-radius: 14px; border: 1px solid #d7dee7; background: #f8fafc; }
.search-shell input, .status-filter { width: 100%; box-sizing: border-box; padding: 0.82rem 0.95rem; border: 1px solid #d7dee7; border-radius: 14px; background: #f8fafc; color: #0f172a; font: inherit; }
.search-shell input { padding: 0; border: none; background: transparent; outline: none; }
.search-shell:focus-within, .status-filter:focus { border-color: rgba(14, 116, 144, 0.45); box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12); background: #ffffff; outline: none; }
.filter-chip-row { margin-top: 1rem; }
.filter-chip { background: #e0f2fe; color: #0f6c8f; }
.clear-chip-btn { border: none; background: transparent; color: #0f6c8f; font-size: 0.82rem; font-weight: 700; cursor: pointer; }

.table-shell-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.summary-chip { background: #eff6ff; color: #0f6c8f; }
.summary-chip.muted { background: #fff7ed; color: #c2410c; }

.rfq-table { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 18px; background: #ffffff; }
.rfq-table table { width: 100%; border-collapse: collapse; }
.rfq-table th, .rfq-table td { padding: 1rem; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
.rfq-table th { background: #f8fafc; font-weight: 600; color: #475569; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.06em; }
.rfq-table tbody tr { transition: background 0.15s ease; }
.rfq-table tbody tr:hover, .table-row-clickable:hover { background: #f0f9ff; cursor: pointer; }

.request-cell, .client-info, .service-info { display: flex; flex-direction: column; gap: 0.2rem; }
.client-info strong, .service-info strong, .request-cell strong { color: #0f172a; }

.pm-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.6rem; border-radius: 999px; background: #ede9fe; color: #7c3aed; font-size: 0.8rem; font-weight: 600; }
.no-pm { color: #94a3b8; font-size: 0.85rem; }

.status-badge { display: inline-flex; align-items: center; justify-content: center; padding: 0.38rem 0.72rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; white-space: nowrap; }
.status-badge.pending { background: #FEF3C7; color: #D97706; }
.status-badge.quoted { background: #DBEAFE; color: #2563EB; }
.status-badge.approved { background: #D1FAE5; color: #059669; }
.status-badge.rejected { background: #FEE2E2; color: #DC2626; }

.quote-amount-text { font-weight: 700; color: #166534; }
.no-quote { color: #94a3b8; }
.action-buttons { align-items: center; }

.mobile-rfq-list { display: none; gap: 1rem; }
.mobile-rfq-card { padding: 1rem; border-radius: 20px; background: #ffffff; }
.mobile-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
.mobile-card-top h4 { margin: 0; color: #0f172a; }
.mobile-card-top p, .mobile-description { margin: 0.35rem 0 0; color: #64748b; }
.mobile-rfq-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 1rem; }
.mobile-description { margin-top: 1rem; line-height: 1.55; }
.mobile-actions { margin-top: 1rem; }

.pagination-shell { align-items: center; justify-content: space-between; gap: 1rem; padding-top: 1rem; }
.pagination-controls { align-items: center; }
.btn-pagination, .page-number-btn { padding: 0.6rem 0.9rem; border-radius: 12px; border: 1px solid #d7dee7; background: #ffffff; color: #334155; font-size: 0.88rem; font-weight: 700; cursor: pointer; transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease; }
.btn-pagination:hover:not(:disabled), .page-number-btn:hover:not(.active) { border-color: #7dd3fc; background: #f8fbfd; }
.page-number-btn.active { border-color: #0f6c8f; background: #0f6c8f; color: #ffffff; }
.btn-pagination:disabled { opacity: 0.45; cursor: not-allowed; }

.no-data { text-align: center; padding: 3rem 1rem; color: #94a3b8; border: 1px dashed #cbd5e1; border-radius: 18px; background: #f8fafc; }
.no-data i { font-size: 3rem; margin-bottom: 1rem; opacity: 0.5; display: block; }

/* View Modal */
.view-grid { display: grid; grid-template-columns: 1fr; gap: 2rem; }
.info-section, .quote-section, .rejection-section { background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border: 1px solid #e9ecef; }
.info-section h4, .quote-section h4, .rejection-section h4 { margin-bottom: 1rem; color: var(--primary-color); border-bottom: 2px solid var(--primary-color); padding-bottom: 0.5rem; }
.files-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem; }
.file-card { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: #fff; border: 1px solid #e9ecef; border-radius: 6px; }
.file-icon { font-size: 1.5rem; color: var(--primary-color); }
.file-info { flex: 1; min-width: 0; }
.file-name { font-weight: 500; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-action { font-size: 0.8rem; color: var(--primary-color); text-decoration: none; }
.quote-display { background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dee2e6; }
.quote-amount-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding: 1rem; background: #e8f4fd; border-radius: 6px; }
.quote-amount-bar .label { font-weight: 600; }
.quote-amount-bar .amount { font-size: 1.5rem; font-weight: bold; color: var(--success-color); }
.materials-display h5 { color: var(--text-color); margin-bottom: 0.75rem; font-size: 1rem; }
.materials-list-view { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
.material-item-view { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; }
.material-item-view .name { font-weight: 500; }
.material-item-view .details { color: var(--text-muted); font-size: 0.9rem; }
.cost-summary { border-top: 1px solid #dee2e6; padding-top: 1rem; margin-top: 1rem; }
.cost-line { display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-weight: 500; }
.quote-notes-display { background: #f8f9fa; padding: 1rem; border-radius: 6px; margin-top: 1rem; border-left: 4px solid var(--info-color); }
.quote-notes-display h5 { color: var(--info-color); margin-bottom: 0.5rem; }
.rejection-display { background: #fee2e2; padding: 1rem; border-radius: 6px; border-left: 4px solid #dc2626; }
.rejection-display p { margin: 0; color: #7f1d1d; }

/* Review Modal */
.modal-content.large { width: 90%; max-width: 1000px; }
.modal-content.extra-large { width: 95%; max-width: 1200px; max-height: 90vh; }
.request-summary { margin-bottom: 2rem; }
.summary-card { background: #f8f9fa; padding: 1.5rem; border-radius: 8px; border-left: 4px solid var(--primary-color); }
.summary-card h4 { margin: 0 0 1rem 0; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; }
.summary-card p { margin: 0.5rem 0; }
.form-section { margin-bottom: 2rem; padding: 1.5rem; background: #fafafa; border-radius: 8px; border: 1px solid #e9ecef; }
.form-section h4 { margin: 0 0 1.5rem 0; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; border-bottom: 2px solid var(--primary-color); padding-bottom: 0.5rem; }
.materials-container { background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dee2e6; }
.material-row { margin-bottom: 1rem; }
.material-inputs { display: grid; grid-template-columns: 2fr 100px 120px 100px 40px; gap: 0.75rem; align-items: center; }
.material-total { font-weight: 600; color: var(--success-color); text-align: right; font-size: 0.9rem; }
.btn-remove { background: var(--danger-color); color: white; border: none; border-radius: 4px; padding: 0.5rem; cursor: pointer; display: flex; align-items: center; justify-content: center; min-width: 35px; height: 35px; }
.btn-remove:hover:not(:disabled) { background: #DC2626; }
.btn-remove:disabled { background: #ccc; cursor: not-allowed; }
.add-material-btn { margin-top: 1rem; border: 2px dashed var(--primary-color); background: transparent; color: var(--primary-color); padding: 0.75rem 1rem; }
.add-material-btn:hover { background: var(--primary-color); color: white; }
.form-row { display: grid; grid-template-columns: 1fr 2fr; gap: 2rem; }
.form-group { margin-bottom: 1rem; }
.form-group label { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: var(--text-color); margin-bottom: 0.5rem; }
.cost-summary-section { margin-top: 2rem; }
.cost-summary-card { background: linear-gradient(135deg, #e8f4fd 0%, #f0f9ff 100%); padding: 1.5rem; border-radius: 8px; border: 1px solid #bae6fd; }
.cost-summary-card h4 { margin: 0 0 1rem 0; color: var(--primary-color); display: flex; align-items: center; gap: 0.5rem; }
.cost-breakdown { background: white; padding: 1rem; border-radius: 6px; border: 1px solid #dbeafe; }
.cost-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #f1f5f9; }
.cost-item:last-child { border-bottom: none; }
.cost-item.total { border-top: 2px solid var(--primary-color); margin-top: 0.5rem; padding-top: 1rem; font-weight: bold; font-size: 1.1rem; color: var(--primary-color); }

/* Payment Modal */
.payment-request-info { background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border: 1px solid #e9ecef; }
.payment-request-info .info-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #e9ecef; }
.payment-request-info .info-row:last-child { border-bottom: none; }
.payment-request-info .label { color: var(--text-muted); font-weight: 500; }
.payment-request-info .value { color: var(--text-color); font-weight: 600; }
.payment-request-info .value.highlight { color: var(--success-color); font-size: 1.1rem; }
.calculated-amount { background: linear-gradient(135deg, #e8f4fd 0%, #f0f9ff 100%); padding: 1rem; border-radius: 8px; margin: 1rem 0; display: flex; justify-content: space-between; align-items: center; border: 1px solid #bae6fd; }
.calculated-amount .label { font-weight: 500; }
.calculated-amount .amount { font-size: 1.5rem; font-weight: bold; color: var(--primary-color); }

/* Responsive */
@media (max-width: 768px) {
    .rfq-stats { grid-template-columns: 1fr 1fr; }
    .rfq-hero, .hero-action-grid, .toolbar-grid, .mobile-rfq-grid, .pagination-shell, .pagination-controls { grid-template-columns: 1fr; }
    .rfq-hero, .table-shell-header, .mobile-card-top, .pagination-shell { display: flex; flex-direction: column; align-items: flex-start; }
    .toolbar-grid { display: grid; }
    .desktop-table { display: none; }
    .mobile-rfq-list { display: grid; margin-top: 0; }
    .material-inputs { grid-template-columns: 1fr; gap: 0.5rem; }
    .form-row { grid-template-columns: 1fr; gap: 1rem; }
    .modal-content.extra-large { width: 98%; max-height: 95vh; }
    .material-item-view { flex-direction: column; align-items: flex-start; gap: 0.25rem; }
    .quote-amount-bar { flex-direction: column; gap: 0.5rem; text-align: center; }
    .btn-pagination, .page-number-btn { width: 100%; justify-content: center; }
}

@media (min-width: 769px) {
    .mobile-rfq-list { display: none; }
}
</style>
