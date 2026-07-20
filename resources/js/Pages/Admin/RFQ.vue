<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="rfq" />

        <main class="main-content rfq-page">
            <transition name="flash">
                <div v-if="flashSuccess" class="flash-banner flash-banner-success">
                    <i class="fas fa-check-circle"></i>
                    <span>{{ flashSuccess }}</span>
                    <button class="flash-close" @click="flashSuccess = ''" aria-label="Dismiss">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </transition>
            <transition name="flash">
                <div v-if="flashError" class="flash-banner flash-banner-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>{{ flashError }}</span>
                    <button class="flash-close" @click="flashError = ''" aria-label="Dismiss">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </transition>

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

                    <Link href="/admin/rfq/create" class="btn btn-primary hero-create-btn">
                        <i class="fas fa-user-plus"></i>
                        Create For Client
                    </Link>
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
                        <!-- Needs-Action toggle: single-click way to jump to the
                             REQs waiting on ops instead of scrolling every row. -->
                        <button
                            type="button"
                            class="needs-action-pill"
                            :class="{ 'needs-action-pill-on': localNeedsAction }"
                            @click="toggleNeedsAction"
                            :title="localNeedsAction ? 'Showing only REQs that need action — click to show all' : 'Show only REQs that need action'"
                        >
                            <i class="fas fa-bell"></i>
                            <span>Needs Action</span>
                            <span v-if="stats?.needsAction" class="needs-action-count">{{ stats.needsAction }}</span>
                        </button>

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
                                <option value="awaiting_payment">Awaiting Payment</option>
                                <option value="ready_for_assignment">Pending Assignment</option>
                                <option value="en_route">Technician En Route</option>
                                <option value="in_progress">In Progress</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </label>

                        <label class="toolbar-field">
                            <span>Origin</span>
                            <select v-model="localOrigin" @change="applyFilters" class="status-filter">
                                <option value="all">All Origins</option>
                                <option value="client_self">Client Submitted</option>
                                <option value="admin_proxy">Admin Assisted</option>
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
                                            <span :class="['origin-badge', rfq.submission_mode === 'admin_proxy' ? 'proxy' : 'self']">
                                                {{ getSubmissionModeLabel(rfq.submission_mode) }}
                                            </span>
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
                                        <!-- Per-row action reasons — small amber chips that say WHY
                                             this REQ needs attention. Server-computed from the same
                                             rules as the filter pill (ServiceRequest::action_reasons). -->
                                        <div v-if="rfq.action_reasons && rfq.action_reasons.length" class="row-action-reasons">
                                            <span v-for="(reason, i) in rfq.action_reasons" :key="i" class="row-action-chip">
                                                <i class="fas fa-circle-exclamation"></i> {{ reason }}
                                            </span>
                                        </div>
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
                                            <button
                                                v-if="rfq.rfq_status === 'approved' && !isRfqFullyPaid(rfq)"
                                                @click="initiatePaymentRequest(rfq)"
                                                class="btn btn-sm btn-success"
                                                :title="rfq.submission_mode === 'admin_proxy' ? 'Send Payment Request to Client Portal' : 'Request Payment'"
                                            >
                                                <i class="fas fa-hand-holding-usd"></i>
                                            </button>
                                            <button
                                                v-if="rfq.rfq_status === 'approved' && rfq.submission_mode === 'admin_proxy' && !isRfqFullyPaid(rfq)"
                                                @click="openProxyPaymentModal(rfq)"
                                                class="btn btn-sm btn-teal"
                                                title="Confirm Direct Payment Received"
                                            >
                                                <i class="fas fa-cash-register"></i>
                                            </button>
                                            <span
                                                v-if="rfq.rfq_status === 'approved' && isRfqFullyPaid(rfq)"
                                                class="paid-pill"
                                                title="All payments received in full"
                                            >
                                                <i class="fas fa-check-circle"></i> Paid in full
                                            </span>
                                            <button v-if="rfq.rfq_status === 'quoted'" @click="viewRFQ(rfq)" class="btn btn-sm btn-success" title="View Quote">
                                                <i class="fas fa-receipt"></i>
                                            </button>
                                            <button
                                                v-if="rfq.rfq_status === 'quoted' && rfq.submission_mode === 'admin_proxy'"
                                                @click="openApproveOnBehalfModal(rfq)"
                                                class="btn btn-sm btn-warning"
                                                title="Approve on behalf of client"
                                            >
                                                <i class="fas fa-user-check"></i>
                                            </button>
                                            <button
                                                v-if="canCancelRfq(rfq)"
                                                @click="openCancelModal(rfq)"
                                                class="btn btn-sm btn-danger"
                                                title="Cancel this request"
                                            >
                                                <i class="fas fa-ban"></i>
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

                            <span :class="['origin-badge', rfq.submission_mode === 'admin_proxy' ? 'proxy' : 'self']">
                                {{ getSubmissionModeLabel(rfq.submission_mode) }}
                            </span>

                            <div class="action-buttons mobile-actions">
                                <button @click="viewRFQ(rfq)" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button v-if="rfq.rfq_status === 'pending'" @click="reviewRFQ(rfq)" class="btn btn-sm btn-primary">
                                    <i class="fas fa-file-invoice-dollar"></i> Quote
                                </button>
                                <button
                                    v-if="rfq.rfq_status === 'approved' && !isRfqFullyPaid(rfq)"
                                    @click="initiatePaymentRequest(rfq)"
                                    class="btn btn-sm btn-success"
                                    :title="rfq.submission_mode === 'admin_proxy' ? 'Send to client portal' : 'Request payment'"
                                >
                                    <i class="fas fa-hand-holding-usd"></i> Payment
                                </button>
                                <button
                                    v-if="rfq.rfq_status === 'approved' && rfq.submission_mode === 'admin_proxy' && !isRfqFullyPaid(rfq)"
                                    @click="openProxyPaymentModal(rfq)"
                                    class="btn btn-sm btn-teal"
                                    title="Confirm direct cash/cheque payment"
                                >
                                    <i class="fas fa-cash-register"></i> Confirm Payment
                                </button>
                                <span
                                    v-if="rfq.rfq_status === 'approved' && isRfqFullyPaid(rfq)"
                                    class="paid-pill"
                                >
                                    <i class="fas fa-check-circle"></i> Paid in full
                                </span>
                                <button
                                    v-if="rfq.rfq_status === 'quoted' && rfq.submission_mode === 'admin_proxy'"
                                    @click="openApproveOnBehalfModal(rfq)"
                                    class="btn btn-sm btn-warning"
                                >
                                    <i class="fas fa-user-check"></i> Approve
                                </button>
                                <button
                                    v-if="canCancelRfq(rfq)"
                                    @click="openCancelModal(rfq)"
                                    class="btn btn-sm btn-danger"
                                >
                                    <i class="fas fa-ban"></i> Cancel
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
        <div v-if="showViewModal" class="modal-overlay">
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
                                <div class="info-item">
                                    <label>Origin:</label>
                                    <span :class="['origin-badge', selectedRFQ?.submission_mode === 'admin_proxy' ? 'proxy' : 'self']">
                                        {{ getSubmissionModeLabel(selectedRFQ?.submission_mode) }}
                                    </span>
                                </div>
                                <div class="info-item" v-if="selectedRFQ?.urgency">
                                    <label>Job Urgency:</label>
                                    <span :class="['urgency-badge', `urgency-${selectedRFQ.urgency}`]">
                                        {{ formatUrgencyLabel(selectedRFQ.urgency) }}
                                    </span>
                                </div>
                                <div class="info-item" v-if="selectedRFQ?.created_by_admin">
                                    <label>Created By Admin:</label>
                                    <span>{{ selectedRFQ?.created_by_admin?.name }}</span>
                                </div>
                                <div class="info-item" v-if="selectedRFQ?.proxy_quote_approver">
                                    <label>Proxy Approved By:</label>
                                    <span>{{ selectedRFQ?.proxy_quote_approver?.name }}</span>
                                </div>
                                <div class="info-item" v-if="selectedRFQ?.proxy_quote_approved_at">
                                    <label>Proxy Approval Date:</label>
                                    <span>{{ formatDateTime(selectedRFQ?.proxy_quote_approved_at) }}</span>
                                </div>
                                <div class="info-item full-width">
                                    <label>Description:</label>
                                    <p>{{ selectedRFQ?.description }}</p>
                                </div>
                                <div class="info-item full-width" v-if="selectedRFQ?.location">
                                    <label>Location:</label>
                                    <p>{{ selectedRFQ?.location }}</p>
                                </div>
                                <div class="info-item full-width" v-if="selectedRFQ?.proxy_quote_approval_note">
                                    <label>Proxy Approval Note:</label>
                                    <p>{{ selectedRFQ?.proxy_quote_approval_note }}</p>
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
                                    <h5>Quotation:</h5>
                                    <div class="materials-list-view">
                                        <div v-for="material in selectedRFQ.quote_materials" :key="material.name" class="material-item-view">
                                            <span class="name">{{ material.name }}</span>
                                            <span class="details">{{ material.quantity }} x KSH {{ formatCurrency(material.unit_price) }} = KSH {{ formatCurrency(material.quantity * material.unit_price) }}</span>
                                        </div>
                                    </div>
                                    <div v-if="quotationAttachmentUrls.length" style="margin-top: 0.75rem; display:flex; flex-direction:column; gap:0.35rem;">
                                        <a v-for="(url, i) in quotationAttachmentUrls" :key="i" :href="url.href" target="_blank" style="display: inline-flex; align-items: center; gap: 0.5rem; color: var(--primary-color); text-decoration: none; font-weight: 500;">
                                            <i class="fas fa-paperclip"></i> {{ url.label }}
                                        </a>
                                    </div>
                                </div>

                                <div class="cost-summary">
                                    <div v-if="Number(selectedRFQ?.quote_transport_cost) > 0" class="cost-line">
                                        <span>Transport:</span>
                                        <span>KSH {{ formatCurrency(selectedRFQ?.quote_transport_cost) }}</span>
                                    </div>
                                    <div class="cost-line">
                                        <span>Labor Cost:</span>
                                        <span>KSH {{ formatCurrency(selectedRFQ?.quote_labor_cost || 0) }}</span>
                                    </div>
                                    <div v-if="Number(selectedRFQ?.quote_down_payment) > 0" class="cost-line" style="margin-top: 6px; padding-top: 8px; border-top: 1px dashed #d1d5db; color: #92400E; font-weight: 600;">
                                        <span>Required Down Payment:</span>
                                        <span>KSH {{ formatCurrency(selectedRFQ?.quote_down_payment) }}</span>
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
                    <button
                        v-if="canReviseSelectedRfq"
                        @click="reviseExistingQuotation(selectedRFQ)"
                        class="btn btn-primary"
                        :title="selectedRFQ?.rfq_status === 'rejected'
                            ? 'Send a revised quotation that addresses the client\'s rejection.'
                            : 'Send a revised quotation. Client will be asked to disregard the previous one.'"
                    >
                        <i class="fas fa-pen-to-square"></i>
                        {{ selectedRFQ?.rfq_status === 'rejected' ? 'Revise &amp; Resubmit' : 'Revise Quotation' }}
                    </button>
                    <button
                        v-if="selectedRFQ?.rfq_status === 'quoted' && selectedRFQ?.submission_mode === 'admin_proxy'"
                        @click="openApproveOnBehalfModal(selectedRFQ)"
                        class="btn btn-warning"
                    >
                        <i class="fas fa-user-check"></i> Approve On Behalf
                    </button>
                    <button @click="closeViewModal" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>

        <!-- RFQ Review/Edit Modal -->
        <div v-if="showReviewModal" class="modal-overlay">
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
                                        <div class="material-row-head">
                                            <span class="material-row-label">Material #{{ index + 1 }}</span>
                                            <button
                                                @click="removeMaterial(index)"
                                                type="button"
                                                class="btn-remove-row"
                                                :disabled="quotationForm.materials.length <= 1"
                                                :title="quotationForm.materials.length <= 1 ? 'Add another material before removing this one' : 'Delete this material row'"
                                                aria-label="Delete material row"
                                            >
                                                <i class="fas fa-trash"></i>
                                                <span>Delete row</span>
                                            </button>
                                        </div>
                                        <div class="material-inputs">
                                            <input v-model="material.name" type="text" placeholder="Material name" class="form-control material-name" required>
                                            <input v-model="material.quantity" type="number" step="0.01" min="0.01" placeholder="Qty" class="form-control material-qty" required>
                                            <CurrencyInput v-model="material.unit_price" placeholder="Unit Price (KSH)" class="material-price" required />
                                            <div class="material-total">KSH {{ formatCurrency((material.quantity || 0) * (material.unit_price || 0)) }}</div>
                                        </div>
                                    </div>
                                    <button @click="addMaterial" type="button" class="btn btn-sm btn-outline-primary add-material-btn">
                                        <i class="fas fa-plus"></i> Add Another Material
                                    </button>
                                </div>
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label><i class="fas fa-file-upload"></i> Attach Supporting Documents (Optional)</label>
                                    <input type="file" multiple @change="onMaterialsFilesChange" accept=".pdf,.docx,.xlsx,.xls,.jpg,.jpeg,.png" class="form-control">
                                    <small style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                                        PDF, DOCX, XLSX, or image files — up to 10 files, 10MB each. Attach materials lists, spec sheets, drawings, etc.
                                    </small>
                                    <ul v-if="quotationForm.materials_files && quotationForm.materials_files.length" style="list-style:none; padding:0; margin-top:0.5rem; display:flex; flex-direction:column; gap:0.25rem;">
                                        <li v-for="(f, i) in quotationForm.materials_files" :key="i" style="display:flex; align-items:center; gap:0.5rem; padding:0.35rem 0.5rem; background:#F3F4F6; border-radius:4px; font-size:0.85rem;">
                                            <i class="fas fa-paperclip" style="color: var(--text-muted);"></i>
                                            <span style="flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ f.name }}</span>
                                            <span style="color: var(--text-muted); font-size:0.8rem;">{{ Math.round(f.size / 1024) }} KB</span>
                                            <button type="button" @click="removeMaterialsFile(i)" class="btn btn-xs btn-danger" title="Remove"><i class="fas fa-times"></i></button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="form-section">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-truck"></i> Transport Cost (KSH):</label>
                                        <input v-model="quotationForm.transport_cost" type="number" step="0.01" class="form-control" placeholder="0.00">
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-tools"></i> Labor Cost (KSH):</label>
                                        <CurrencyInput v-model="quotationForm.labor_cost" placeholder="0.00" required />
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label><i class="fas fa-hand-holding-usd"></i> Down Payment (KSH):</label>
                                        <input v-model="quotationForm.down_payment" type="number" step="0.01" class="form-control" placeholder="Leave blank for 50% of total">
                                        <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 0.2rem;">
                                            Fixed KES amount required upfront. Shown in the client's quotation email and pre-fills the first payment request.
                                        </small>
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-clock"></i> Expected Duration:</label>
                                        <div style="display:flex;gap:.5rem;align-items:center;">
                                            <input v-model.number="quotationForm.duration_weeks" type="number" min="0" class="form-control" placeholder="0" style="width:80px;">
                                            <span>weeks</span>
                                            <input v-model.number="quotationForm.duration_extra_days" type="number" min="0" max="6" class="form-control" placeholder="0" style="width:80px;">
                                            <span>days</span>
                                        </div>
                                        <small style="color: var(--text-muted); font-size: 0.8rem; display: block; margin-top: 0.2rem;">
                                            Quoted to the client as the expected time on site after the technician starts.
                                        </small>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-sticky-note"></i> Additional Notes:</label>
                                    <textarea v-model="quotationForm.notes" class="form-control" rows="3" placeholder="Any additional information for the client..."></textarea>
                                </div>

                                <!-- Billing schedule (#21) -->
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label style="display:flex;align-items:center;justify-content:space-between;">
                                        <span><i class="fas fa-calendar-check"></i> Billing Schedule <small style="font-weight:400;color:var(--text-muted);margin-left:0.4rem;">(optional — triggers payment requests automatically)</small></span>
                                        <button type="button" class="btn btn-secondary btn-xs" @click="addBillingMilestone">
                                            <i class="fas fa-plus"></i> Add milestone
                                        </button>
                                    </label>
                                    <div v-if="quotationForm.billing_milestones.length" class="billing-milestones-table">
                                        <div class="billing-ms-header">
                                            <span>Milestone label</span>
                                            <span>Progress %</span>
                                            <span>Amount (KSH)</span>
                                            <span>Running total / Balance</span>
                                            <span></span>
                                        </div>
                                        <div
                                            v-for="(ms, idx) in quotationForm.billing_milestones"
                                            :key="idx"
                                            class="billing-ms-row"
                                        >
                                            <input
                                                type="text"
                                                v-model="ms.label"
                                                class="form-control"
                                                placeholder="e.g. Mobilisation"
                                            />
                                            <input
                                                type="number"
                                                v-model.number="ms.progress_pct"
                                                class="form-control"
                                                min="1" max="100" step="1"
                                                placeholder="30"
                                            />
                                            <input
                                                type="number"
                                                v-model.number="ms.amount"
                                                class="form-control"
                                                min="0" step="0.01"
                                                placeholder="0.00"
                                            />
                                            <span class="billing-ms-running" :class="{ 'billing-ms-warn': milestoneRunningRows[idx]?.over }">
                                                <strong>{{ formatCurrency(milestoneRunningRows[idx]?.cumulative || 0) }}</strong>
                                                <small style="display:block;color:var(--text-muted);font-size:0.7rem;">
                                                    Balance: KSH {{ formatCurrency(milestoneRunningRows[idx]?.balance || 0) }}
                                                </small>
                                            </span>
                                            <button
                                                type="button"
                                                class="btn btn-danger btn-xs"
                                                @click="quotationForm.billing_milestones.splice(idx, 1)"
                                                title="Remove milestone"
                                            >
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                        <div v-if="milestoneTotalsSummary.set > 0" class="billing-ms-summary" :class="{ 'billing-ms-warn': milestoneTotalsSummary.over }">
                                            <span>
                                                <strong>Total scheduled:</strong>
                                                KSH {{ formatCurrency(milestoneTotalsSummary.scheduled) }}
                                                <small>({{ milestoneTotalsSummary.pct }}% of quote)</small>
                                            </span>
                                            <span v-if="milestoneTotalsSummary.downPayment > 0">
                                                <strong>Deposit (already committed):</strong>
                                                KSH {{ formatCurrency(milestoneTotalsSummary.downPayment) }}
                                            </span>
                                            <span>
                                                <strong>Unscheduled balance:</strong>
                                                KSH {{ formatCurrency(milestoneTotalsSummary.unscheduled) }}
                                            </span>
                                            <span v-if="milestoneTotalsSummary.over" style="color:var(--danger-color,#dc2626);font-weight:600;">
                                                ⚠ Deposit + milestones exceed total quote of KSH {{ formatCurrency(milestoneTotalsSummary.total) }}
                                            </span>
                                        </div>
                                    </div>
                                    <p v-else style="color:var(--text-muted);font-size:0.85rem;margin-top:0.25rem;">
                                        No billing milestones set. Add one above to auto-raise payment requests when progress is validated.
                                    </p>
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
                                            <span>Transport:</span>
                                            <span>KSH {{ formatCurrency(quotationForm.transport_cost || 0) }}</span>
                                        </div>
                                        <div class="cost-item">
                                            <span>Labor Cost:</span>
                                            <span>KSH {{ formatCurrency(quotationForm.labor_cost || 0) }}</span>
                                        </div>
                                        <div class="cost-item total">
                                            <span>Total Amount:</span>
                                            <span>KSH {{ formatCurrency(totalQuoteAmount) }}</span>
                                        </div>
                                        <div v-if="quotationForm.down_payment" class="cost-item" style="border-top: 1px dashed #e5e7eb; margin-top: 0.4rem; padding-top: 0.4rem;">
                                            <span>Required Down Payment:</span>
                                            <span>KSH {{ formatCurrency(quotationForm.down_payment) }}</span>
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
                    <button @click="submitQuote" class="btn btn-success" :disabled="!canSubmitQuote || isSubmittingQuote">
                        <i class="fas fa-paper-plane"></i>
                        {{ isSubmittingQuote ? 'Sending...' : (isRevision ? 'Send Revised Quotation' : 'Send Quotation') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Proxy Payment Confirmation Modal (admin-assisted RFQs only) -->
        <div v-if="showProxyPaymentModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3><i class="fas fa-cash-register" style="margin-right:0.5rem;color:#0f766e;"></i>Confirm Payment Received</h3>
                    <button @click="closeProxyPaymentModal" class="modal-close"><i class="fas fa-times"></i></button>
                </div>
                <form @submit.prevent="submitProxyPayment">
                    <div class="modal-body">
                        <div class="proxy-payment-notice">
                            <i class="fas fa-info-circle"></i>
                            This is an admin-assisted request. You are confirming payment on behalf of the client — no payment request will be sent to them.
                        </div>

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
                                <span class="label">Total Quote:</span>
                                <span class="value highlight">KSH {{ formatCurrency(selectedRFQ?.quote_amount) }}</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Payment Percentage (%)</label>
                            <input v-model.number="proxyPaymentForm.percentage" type="number" min="1" max="100" class="form-control" required>
                        </div>
                        <div class="calculated-amount" v-if="proxyPaymentForm.percentage > 0">
                            <span class="label">Amount Confirmed:</span>
                            <span class="amount">KSH {{ formatCurrency((proxyPaymentForm.percentage / 100) * (selectedRFQ?.quote_amount || 0)) }}</span>
                        </div>

                        <div class="form-group">
                            <label>Payment Method</label>
                            <select v-model="proxyPaymentForm.payment_method" class="form-control" required>
                                <option value="">Select method...</option>
                                <option value="mpesa">M-Pesa</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank_deposit">Bank Deposit</option>
                            </select>
                        </div>

                        <div v-if="proxyPaymentForm.payment_method === 'mpesa'" class="form-group" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:0.85rem;">
                            <label style="color:#065f46;font-weight:600;">
                                <i class="fab fa-google-pay" style="color:#16a34a;"></i> M-Pesa Receipt Details
                            </label>
                            <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-top:0.5rem;">
                                <div>
                                    <label style="font-size:0.85rem;">M-Pesa Receipt *</label>
                                    <input v-model="proxyPaymentForm.mpesa_receipt_number" type="text" class="form-control" placeholder="e.g. SHL3K8M2X1" required>
                                </div>
                                <div>
                                    <label style="font-size:0.85rem;">Client Phone</label>
                                    <input v-model="proxyPaymentForm.phone_number" type="text" class="form-control" placeholder="e.g. 254712345678">
                                </div>
                            </div>
                            <small style="color:#065f46;font-size:0.78rem;display:block;margin-top:0.5rem;">
                                The 10-character M-Pesa confirmation code (sender's "MPESA Transaction Cost"  SMS). Use the RFQ reference as the account number when instructing the client to pay.
                            </small>
                        </div>
                        <div class="form-group" v-if="proxyPaymentForm.payment_method === 'cheque'">
                            <label>Cheque Number *</label>
                            <input v-model="proxyPaymentForm.cheque_number" type="text" class="form-control" placeholder="e.g. 001234" required>
                        </div>
                        <div class="form-group" v-if="proxyPaymentForm.payment_method === 'bank_deposit'">
                            <label>Bank Reference *</label>
                            <input v-model="proxyPaymentForm.bank_reference" type="text" class="form-control" placeholder="e.g. TXN-20260512-001" required>
                        </div>

                        <div class="form-group">
                            <label>Proof of Payment (optional)</label>
                            <div class="evidence-upload-area" @click="proxyEvidenceInput.click()">
                                <div v-if="!proxyPaymentForm.evidence" class="evidence-placeholder">
                                    <i class="fas fa-upload"></i>
                                    <span>Click to upload receipt, cheque scan, or deposit slip</span>
                                    <small>JPG, PNG or PDF · Max 10 MB</small>
                                </div>
                                <div v-else class="evidence-selected">
                                    <i class="fas fa-check-circle" style="color:#10b981;"></i>
                                    <span>{{ proxyPaymentForm.evidence.name }}</span>
                                    <button type="button" class="remove-evidence-btn" @click.stop="proxyPaymentForm.evidence = null; proxyEvidenceInput.value = ''">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            <input ref="proxyEvidenceInput" type="file" accept="image/jpeg,image/png,application/pdf" style="display:none" @change="e => proxyPaymentForm.evidence = e.target.files[0] || null">
                        </div>

                        <div class="form-group">
                            <label>Notes (Optional)</label>
                            <textarea v-model="proxyPaymentForm.notes" class="form-control" rows="2" placeholder="e.g. Paid in cash at office on 12 May 2026"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" @click="closeProxyPaymentModal" class="btn btn-secondary">Cancel</button>
                        <button type="submit"
                            class="btn btn-teal"
                            :disabled="isSubmittingProxyPayment
                                || !proxyPaymentForm.payment_method
                                || (['cheque','bank_deposit','mpesa'].includes(proxyPaymentForm.payment_method) && !proxyPaymentForm.evidence)"
                            :title="(['cheque','bank_deposit','mpesa'].includes(proxyPaymentForm.payment_method) &amp;&amp; !proxyPaymentForm.evidence)
                                ? 'Upload supporting documentation before confirming.'
                                : ''">
                            <i class="fas fa-check-circle"></i>
                            {{ isSubmittingProxyPayment ? 'Confirming...' : 'Confirm Payment Received' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Request Payment Modal -->
        <div v-if="showPaymentModal" class="modal-overlay">
            <div class="modal-content pr-modal" @click.stop>
                <div class="modal-header pr-modal-header">
                    <div class="pr-header-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="pr-header-copy">
                        <h3>Request Payment</h3>
                        <p class="pr-header-sub">
                            {{ selectedRFQ?.request_id }} · {{ selectedRFQ?.user?.name }}
                        </p>
                    </div>
                    <button @click="closePaymentModal" class="modal-close pr-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form @submit.prevent="submitPaymentRequest">
                    <div class="modal-body pr-modal-body">

                        <!-- Balance summary cards -->
                        <div class="pr-balance-grid">
                            <div class="pr-balance-tile tile-blue">
                                <span class="pr-tile-label">Total Quote</span>
                                <strong>KSH {{ formatCurrency(selectedRFQ?.quote_amount) }}</strong>
                            </div>
                            <div class="pr-balance-tile tile-amber">
                                <span class="pr-tile-label">Already Billed</span>
                                <strong>KSH {{ formatCurrency(alreadyBilledAmount) }}</strong>
                            </div>
                            <div :class="['pr-balance-tile', remainingAmount <= 0 ? 'tile-red' : 'tile-green']">
                                <span class="pr-tile-label">Remaining Balance</span>
                                <strong>KSH {{ formatCurrency(remainingAmount) }}</strong>
                            </div>
                        </div>

                        <!-- Down payment hint -->
                        <div v-if="!hasPriorPayments && selectedRFQ?.quote_down_payment > 0" class="pr-info-strip pr-info-success">
                            <i class="fas fa-info-circle"></i>
                            <span>Down payment of <strong>KSH {{ formatCurrency(selectedRFQ.quote_down_payment) }}</strong> was specified on this quotation.</span>
                        </div>

                        <!-- Prior payment requests -->
                        <div v-if="priorPaymentRequests.length" class="pr-prior-section">
                            <div class="pr-section-head">
                                <i class="fas fa-history"></i>
                                <span>Prior payment requests</span>
                                <span class="pr-prior-count">{{ priorPaymentRequests.length }}</span>
                            </div>
                            <div class="pr-prior-list">
                                <div v-for="pr in priorPaymentRequests" :key="pr.id" class="pr-prior-item">
                                    <div class="pr-prior-meta">
                                        <strong>{{ pr.payment_request_id }}</strong>
                                        <span>KSH {{ formatCurrency(pr.amount) }}</span>
                                    </div>
                                    <span :class="['pr-prior-status', `pr-prior-status-${pr.status}`]">
                                        {{ pr.status }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Input fields. Down-payment percentage is locked to
                             the quotation value (#31): when the admin specified a
                             quote_down_payment on the quotation, both fields are
                             read-only and reflect that amount so it stays
                             consistent with the agreed deposit terms. -->
                        <div class="pr-section">
                            <label class="pr-section-label">
                                Bill the client
                                <span v-if="downPaymentLocked" class="pr-locked-pill">
                                    <i class="fas fa-lock"></i> Locked to quotation deposit
                                </span>
                            </label>
                            <div class="pr-input-grid">
                                <div class="pr-input-field">
                                    <label>Percentage</label>
                                    <div class="pr-input-wrap">
                                        <input
                                            v-model.number="paymentRequestForm.percentage"
                                            @input="onPercentageInput"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            class="pr-input"
                                            :readonly="downPaymentLocked"
                                        >
                                        <span class="pr-input-suffix">%</span>
                                    </div>
                                </div>
                                <div class="pr-input-or">or</div>
                                <div class="pr-input-field">
                                    <label>Fixed Amount</label>
                                    <div class="pr-input-wrap">
                                        <span class="pr-input-prefix">KSH</span>
                                        <input
                                            v-model.number="paymentRequestForm.amount"
                                            @input="onAmountInput"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            class="pr-input pr-input-with-prefix"
                                            :placeholder="`Max ${formatCurrency(remainingAmount)}`"
                                            :readonly="downPaymentLocked"
                                        >
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Calculated amount -->
                        <div v-if="resolvedPaymentAmount > 0" :class="['pr-summary', capExceeded ? 'pr-summary-error' : '']">
                            <span class="pr-summary-label">Amount to request</span>
                            <strong class="pr-summary-value">KSH {{ formatCurrency(resolvedPaymentAmount) }}</strong>
                        </div>

                        <!-- Cap exceeded warning -->
                        <div v-if="capExceeded" class="pr-info-strip pr-info-error">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span>This amount exceeds the remaining approved balance. Reduce it or get additional client approval first.</span>
                        </div>

                        <!-- Down payment checkbox -->
                        <div v-if="!hasPriorPayments" class="pr-checkbox-card">
                            <label class="pr-checkbox-label">
                                <input
                                    type="checkbox"
                                    v-model="paymentRequestForm.is_down_payment"
                                    class="pr-checkbox-input"
                                />
                                <span class="pr-checkbox-text">
                                    <strong>Treat as down payment</strong>
                                    <small>Marks the deposit as requested on this job — only one down payment per job.</small>
                                </span>
                            </label>
                        </div>

                        <!-- Notes -->
                        <div class="pr-section">
                            <label class="pr-section-label">Notes for client <span class="pr-optional">(optional)</span></label>
                            <textarea
                                v-model="paymentRequestForm.notes"
                                class="pr-textarea"
                                rows="3"
                                placeholder="E.g., 50% deposit required before work begins..."
                            ></textarea>
                        </div>
                    </div>

                    <div class="modal-footer pr-modal-footer">
                        <button type="button" @click="closePaymentModal" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-success pr-submit" :disabled="!canSubmitPaymentRequest || isSubmittingPayment">
                            <i class="fas fa-paper-plane"></i>
                            {{ isSubmittingPayment ? 'Sending...' : 'Send Payment Request' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Proxy Approval Modal -->
        <div v-if="showApproveOnBehalfModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Approve On Behalf Of Client</h3>
                    <button @click="closeApproveOnBehalfModal" class="modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form @submit.prevent="submitApproveOnBehalf">
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
                                <span class="label">Origin:</span>
                                <span class="value">{{ getSubmissionModeLabel(selectedRFQ?.submission_mode) }}</span>
                            </div>
                        </div>

                        <div v-if="selectedRFQ?.submission_mode !== 'admin_proxy'" style="margin: 0.75rem 0; padding: 0.75rem; background: #FEF2F2; border-left: 3px solid #DC2626; font-size: 0.85rem; color: #991B1B;">
                            <i class="fas fa-exclamation-triangle"></i>
                            Proxy approval is only available for admin-assisted RFQs. This request was submitted directly by the client and must be approved by them.
                        </div>

                        <div v-else-if="selectedRFQ?.rfq_status !== 'quoted'" style="margin: 0.75rem 0; padding: 0.75rem; background: #FEF3C7; border-left: 3px solid #F59E0B; font-size: 0.85rem; color: #92400E;">
                            <i class="fas fa-info-circle"></i>
                            This quotation is in "{{ selectedRFQ?.rfq_status }}" status — only "quoted" quotations can be proxy-approved.
                        </div>

                        <div class="form-group">
                            <label>Approval note <span style="color:#DC2626;">*</span></label>
                            <textarea
                                v-model="proxyApprovalNote"
                                class="form-control"
                                rows="4"
                                placeholder="Document how the client approved this quotation offline."
                                required
                            ></textarea>
                            <small class="helper-text">
                                Example: Client approved via signed quote received by email on 12 May 2026.
                                Minimum 10 characters. <strong>{{ proxyApprovalNote.length }}/10</strong>
                            </small>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" @click="closeApproveOnBehalfModal" class="btn btn-secondary">Cancel</button>
                        <button
                            type="submit"
                            class="btn btn-warning"
                            :disabled="proxyApprovalNote.trim().length < 10 || selectedRFQ?.submission_mode !== 'admin_proxy' || selectedRFQ?.rfq_status !== 'quoted'"
                        >
                            <i class="fas fa-user-check"></i> Confirm Proxy Approval
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Rejection Modal -->
        <div v-if="showRejectModal" class="modal-overlay">
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

        <!-- Cancellation Modal -->
        <div v-if="showCancelModal" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Cancel Service Request</h3>
                    <button @click="closeCancelModal" class="modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="modal-body">
                    <p v-if="rfqToCancel" style="margin-bottom: 0.75rem; color: var(--text-muted);">
                        Cancelling request <strong>#{{ rfqToCancel.id }}</strong> for
                        <strong>{{ rfqToCancel.user?.name || 'this client' }}</strong>.
                        This is recorded in the audit log.
                    </p>
                    <div class="form-group">
                        <label>Reason for cancellation (min. 10 characters):</label>
                        <textarea v-model="cancelReason" class="form-control" rows="4" placeholder="Why is this request being cancelled? e.g. client withdrew, out of scope, duplicate of #123..." required></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button @click="closeCancelModal" class="btn btn-secondary" :disabled="isSubmittingCancel">Back</button>
                    <button @click="confirmCancel" class="btn btn-danger" :disabled="cancelReason.trim().length < 10 || isSubmittingCancel">
                        <i class="fas fa-ban"></i>
                        {{ isSubmittingCancel ? 'Cancelling...' : 'Cancel Request' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import CurrencyInput from '../../Components/CurrencyInput.vue'
import { ref, computed, onMounted, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import axios from 'axios'

const inertiaPage = usePage()
const flashSuccess = ref('')
const flashError = ref('')

function showFlash() {
    const s = inertiaPage.props.flash?.success
    const e = inertiaPage.props.flash?.error
    if (s) {
        flashSuccess.value = s
        setTimeout(() => { flashSuccess.value = '' }, 6000)
    }
    if (e) {
        flashError.value = e
        setTimeout(() => { flashError.value = '' }, 7000)
    }
}

onMounted(showFlash)
watch(() => inertiaPage.props.flash, showFlash, { deep: true })

const props = defineProps({
    rfqs: { type: Object, default: () => ({ data: [], current_page: 1, last_page: 1, total: 0, from: 0, to: 0 }) },
    stats: { type: Object, default: () => ({ pending: 0, quoted: 0, approved: 0, rejected: 0, total: 0, totalValue: 0 }) },
    filters: { type: Object, default: () => ({ search: '', status: 'all', origin: 'all', sort: 'newest', per_page: 15 }) },
})

// Local filter state (bound to inputs)
const localSearch = ref(props.filters.search || '')
const localStatus = ref(props.filters.status || 'all')
const localOrigin = ref(props.filters.origin || 'all')
const localSort = ref(props.filters.sort || 'newest')
const localPerPage = ref(props.filters.per_page || 15)
const localNeedsAction = ref(Boolean(props.filters.needs_action))

// Modal state
const showViewModal = ref(false)
const showReviewModal = ref(false)
const showRejectModal = ref(false)
const showPaymentModal = ref(false)
const showApproveOnBehalfModal = ref(false)
const showProxyPaymentModal = ref(false)
const selectedRFQ = ref(null)
const rejectionReason = ref('')
const proxyApprovalNote = ref('')
const isSubmittingPayment = ref(false)
const isSubmittingProxyPayment = ref(false)
const proxyEvidenceInput = ref(null)

const proxyPaymentForm = ref({
    percentage: 50,
    payment_method: '',
    cheque_number: '',
    bank_reference: '',
    notes: '',
    evidence: null,
})

const quotationForm = ref({
    materials: [{ name: '', quantity: 1, unit_price: 0 }],
    labor_cost: 0,
    transport_cost: 0,
    down_payment: null,
    duration_weeks: 0,
    duration_extra_days: 0,
    notes: '',
    materials_file: null,
    materials_files: [],
    billing_milestones: [],
})

const addBillingMilestone = () => {
    quotationForm.value.billing_milestones.push({ label: '', progress_pct: null, amount: null })
}

/**
 * Running totals for the milestone schedule. For each row, compute the
 * cumulative scheduled amount up to and including that row, and the
 * remaining unscheduled balance against the quote total. Lets the admin
 * see at-a-glance whether the milestones cover the full quote.
 */
const quoteTotalForMilestones = computed(() => {
    const materials = (quotationForm.value.materials || [])
        .reduce((sum, m) => sum + ((Number(m.quantity) || 0) * (Number(m.unit_price) || 0)), 0)
    const labor = Number(quotationForm.value.labor_cost) || 0
    const transport = Number(quotationForm.value.transport_cost) || 0
    return materials + labor + transport
})

const milestoneRunningRows = computed(() => {
    const total = quoteTotalForMilestones.value
    const downPayment = Number(quotationForm.value.down_payment) || 0
    let cumulative = 0
    return (quotationForm.value.billing_milestones || []).map((ms) => {
        const amt = Number(ms.amount) || 0
        cumulative += amt
        return {
            cumulative,
            balance: Math.max(0, total - cumulative - downPayment),
            over: (cumulative + downPayment) > total + 0.001 && total > 0,
        }
    })
})

const milestoneTotalsSummary = computed(() => {
    const total = quoteTotalForMilestones.value
    const rows = quotationForm.value.billing_milestones || []
    const scheduled = rows.reduce((s, m) => s + (Number(m.amount) || 0), 0)
    const downPayment = Number(quotationForm.value.down_payment) || 0
    const committed = scheduled + downPayment
    const set = rows.filter(m => Number(m.amount) > 0).length
    return {
        total,
        scheduled,
        downPayment,
        committed,
        unscheduled: Math.max(0, total - committed),
        pct: total > 0 ? Math.round((scheduled / total) * 100) : 0,
        over: committed > total + 0.001 && total > 0,
        set,
    }
})

// Set to true when admin clicks "Revise Quotation" so the controller
// emails the client a revision notice asking them to disregard the
// previous version (#5).
const isRevision = ref(false)

const paymentRequestForm = ref({
    percentage: 50,
    amount: null,
    is_down_payment: false,
    notes: '',
})

// Prior payment requests on the selected RFQ (#14a display + #14b detection)
const priorPaymentRequests = computed(() => {
    const list = selectedRFQ.value?.payment_requests || []
    // Don't include cancelled/failed in the "billed" stack
    return list.filter((pr) => !['cancelled', 'failed'].includes(pr.status))
})
const hasPriorPayments = computed(() => priorPaymentRequests.value.length > 0)
const alreadyBilledAmount = computed(() => priorPaymentRequests.value.reduce((sum, pr) => sum + (Number(pr.amount) || 0), 0))
const remainingAmount = computed(() => Math.max(0, (Number(selectedRFQ.value?.quote_amount) || 0) - alreadyBilledAmount.value))

// Keep percentage <-> amount in sync so admin can type either
const onPercentageInput = () => {
    const pct = Number(paymentRequestForm.value.percentage) || 0
    const total = Number(selectedRFQ.value?.quote_amount) || 0
    paymentRequestForm.value.amount = total > 0 ? Math.round(((pct / 100) * total) * 100) / 100 : null
}
const onAmountInput = () => {
    const amt = Number(paymentRequestForm.value.amount) || 0
    const total = Number(selectedRFQ.value?.quote_amount) || 0
    paymentRequestForm.value.percentage = total > 0 ? Math.round(((amt / total) * 100) * 100) / 100 : 0
}

const resolvedPaymentAmount = computed(() => Number(paymentRequestForm.value.amount) || 0)
const capExceeded = computed(() => resolvedPaymentAmount.value > remainingAmount.value + 0.001)

/**
 * Lock the down-payment row to the value specified on the quotation
 * (#31). Triggers only when this would be the first billing AND the
 * admin set quote_down_payment when sending the quote. Subsequent
 * progress payments stay editable.
 */
const downPaymentLocked = computed(() => {
    return !hasPriorPayments.value
        && Number(selectedRFQ.value?.quote_down_payment) > 0
        && Boolean(paymentRequestForm.value.is_down_payment)
})

// --- Server-side pagination / filtering ---
const buildFilterQuery = (extra = {}) => ({
    search: localSearch.value || undefined,
    status: localStatus.value !== 'all' ? localStatus.value : undefined,
    origin: localOrigin.value !== 'all' ? localOrigin.value : undefined,
    sort: localSort.value !== 'newest' ? localSort.value : undefined,
    per_page: localPerPage.value !== 15 ? localPerPage.value : undefined,
    needs_action: localNeedsAction.value ? 1 : undefined,
    ...extra,
})

const applyFilters = () => {
    router.get('/admin/rfq', buildFilterQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const goToPage = (page) => {
    if (page < 1 || page > props.rfqs.last_page) return
    router.get('/admin/rfq', buildFilterQuery({ page }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const toggleNeedsAction = () => {
    localNeedsAction.value = !localNeedsAction.value
    applyFilters()
}

const clearFilters = () => {
    localSearch.value = ''
    localStatus.value = 'all'
    localOrigin.value = 'all'
    localSort.value = 'newest'
    localPerPage.value = 15
    localNeedsAction.value = false
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
    if (localOrigin.value !== 'all') chips.push(`Origin: ${getSubmissionModeLabel(localOrigin.value)}`)
    if (localSort.value !== 'newest') chips.push('Sort: Oldest first')
    if (localPerPage.value !== 15) chips.push(`Rows: ${localPerPage.value}`)
    return chips
})

const materialsTotal = computed(() => roundCurrency(
    quotationForm.value.materials.reduce((t, m) => t + roundCurrency((Number(m.quantity) || 0) * (Number(m.unit_price) || 0)), 0)
))
const totalQuoteAmount = computed(() => roundCurrency(
    materialsTotal.value
    + (Number(quotationForm.value.labor_cost) || 0)
    + (Number(quotationForm.value.transport_cost) || 0)
))
const canSubmitQuote = computed(() => {
    const hasMaterials = quotationForm.value.materials.some(m => m.name && m.quantity > 0 && m.unit_price > 0)
    const hasLaborOnly = quotationForm.value.labor_cost > 0
    return (hasMaterials || hasLaborOnly) && quotationForm.value.labor_cost >= 0
})

// Issue #7 — show Revise button for quoted, approved, AND rejected RFQs
const canReviseSelectedRfq = computed(() =>
    ['quoted', 'approved', 'rejected'].includes(selectedRFQ.value?.rfq_status)
)
const calculatedPaymentAmount = computed(() => {
    if (!selectedRFQ.value?.quote_amount || !paymentRequestForm.value.percentage) return 0
    return (paymentRequestForm.value.percentage / 100) * selectedRFQ.value.quote_amount
})
const canSubmitPaymentRequest = computed(() =>
    resolvedPaymentAmount.value > 0
    && !capExceeded.value
    && selectedRFQ.value?.id
)

// --- Modal actions ---
const viewRFQ = (rfq) => { selectedRFQ.value = rfq; showViewModal.value = true }
const closeViewModal = () => { showViewModal.value = false; selectedRFQ.value = null }
const editRFQ = () => { showViewModal.value = false; showReviewModal.value = true; resetQuotationForm() }
const reviewRFQ = (rfq) => { selectedRFQ.value = rfq; resetQuotationForm(); showReviewModal.value = true }
const closeReviewModal = () => { showReviewModal.value = false; selectedRFQ.value = null; resetQuotationForm() }
const closeRejectModal = () => { showRejectModal.value = false; rejectionReason.value = '' }
const rejectRFQ = () => { showRejectModal.value = true }

// ── Cancel Request ─────────────────────────────────────────────────────────
// Complements the reject flow: reject targets the quotation, cancel voids
// the whole request. Primarily filling the admin-assisted gap where clients
// couldn't withdraw the request from their side.
const showCancelModal = ref(false)
const cancelReason = ref('')
const rfqToCancel = ref(null)
const isSubmittingCancel = ref(false)

const canCancelRfq = (rfq) => {
    if (!rfq) return false
    const terminalStatuses = ['completed', 'completed_pending_confirmation', 'closed', 'archived', 'cancelled']
    if (terminalStatuses.includes(rfq.status)) return false
    if (rfq.rfq_status === 'rejected') return false
    return true
}

const openCancelModal = (rfq) => {
    rfqToCancel.value = rfq
    cancelReason.value = ''
    showCancelModal.value = true
}

const closeCancelModal = () => {
    if (isSubmittingCancel.value) return
    showCancelModal.value = false
    cancelReason.value = ''
    rfqToCancel.value = null
}

const confirmCancel = () => {
    if (!rfqToCancel.value) return
    if (cancelReason.value.trim().length < 10) return
    isSubmittingCancel.value = true
    router.post(`/admin/rfq/${rfqToCancel.value.id}/cancel`, { reason: cancelReason.value }, {
        preserveScroll: true,
        onSuccess: () => { closeCancelModal() },
        onError: (errors) => {
            const messages = Object.values(errors).flat().filter(Boolean)
            alert("Couldn't cancel the request:\n" + (messages.join('\n') || 'Unknown error.'))
        },
        onFinish: () => { isSubmittingCancel.value = false },
    })
}

const initiatePaymentRequest = (rfq) => {
    selectedRFQ.value = rfq

    // If this is the first request and admin specified a down payment on the
    // quotation, pre-fill it. Otherwise fall back to 50% of the quote.
    const noPrior = !(rfq.payment_requests || []).some((pr) => !['cancelled', 'failed'].includes(pr.status))
    const downPayment = Number(rfq.quote_down_payment) || 0
    const total = Number(rfq.quote_amount) || 0

    let initialAmount = null
    let initialPercentage = 50
    if (noPrior && downPayment > 0) {
        initialAmount = downPayment
        initialPercentage = total > 0 ? Math.round(((downPayment / total) * 100) * 100) / 100 : 50
    } else if (total > 0) {
        initialAmount = Math.round((0.5 * total) * 100) / 100
    }

    paymentRequestForm.value = {
        percentage: initialPercentage,
        amount: initialAmount,
        is_down_payment: noPrior,
        notes: '',
    }
    showPaymentModal.value = true
}
const closePaymentModal = () => { showPaymentModal.value = false; selectedRFQ.value = null }

const openProxyPaymentModal = (rfq) => {
    selectedRFQ.value = rfq

    // Match the percentage to the deposit amount admin set on the quotation
    // (just like initiatePaymentRequest does for client-initiated RFQs).
    // If no down payment was specified, fall back to 50%.
    const downPayment = Number(rfq.quote_down_payment) || 0
    const total = Number(rfq.quote_amount) || 0
    const noPrior = !(rfq.payment_requests || []).some((pr) => !['cancelled', 'failed'].includes(pr.status))

    let initialPercentage = 50
    if (noPrior && downPayment > 0 && total > 0) {
        initialPercentage = Math.round(((downPayment / total) * 100) * 100) / 100
    }

    proxyPaymentForm.value = {
        percentage: initialPercentage,
        payment_method: '',
        cheque_number: '',
        bank_reference: '',
        mpesa_receipt_number: '',
        phone_number: '',
        notes: '',
        evidence: null,
    }
    showProxyPaymentModal.value = true
}
const closeProxyPaymentModal = () => {
    showProxyPaymentModal.value = false
    selectedRFQ.value = null
    if (proxyEvidenceInput.value) proxyEvidenceInput.value.value = ''
}

const submitProxyPayment = () => {
    if (!selectedRFQ.value) return
    isSubmittingProxyPayment.value = true
    const fd = new FormData()
    fd.append('percentage', proxyPaymentForm.value.percentage)
    fd.append('payment_method', proxyPaymentForm.value.payment_method)
    if (proxyPaymentForm.value.cheque_number) fd.append('cheque_number', proxyPaymentForm.value.cheque_number)
    if (proxyPaymentForm.value.bank_reference) fd.append('bank_reference', proxyPaymentForm.value.bank_reference)
    if (proxyPaymentForm.value.mpesa_receipt_number) fd.append('mpesa_receipt_number', proxyPaymentForm.value.mpesa_receipt_number)
    if (proxyPaymentForm.value.phone_number) fd.append('phone_number', proxyPaymentForm.value.phone_number)
    if (proxyPaymentForm.value.notes) fd.append('notes', proxyPaymentForm.value.notes)
    if (proxyPaymentForm.value.evidence) fd.append('evidence', proxyPaymentForm.value.evidence)
    axios.post(`/admin/rfq/${selectedRFQ.value.id}/confirm-payment-on-behalf`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
    }).then(response => {
        if (response.data.success) {
            alert(response.data.message || 'Payment confirmed successfully.')
            closeProxyPaymentModal()
            router.reload()
        }
    }).catch(error => {
        alert(error.response?.data?.error || 'Failed to confirm payment.')
    }).finally(() => {
        isSubmittingProxyPayment.value = false
    })
}

const openApproveOnBehalfModal = (rfq) => {
    selectedRFQ.value = rfq
    proxyApprovalNote.value = rfq?.proxy_quote_approval_note || ''
    showApproveOnBehalfModal.value = true
}
const closeApproveOnBehalfModal = () => {
    showApproveOnBehalfModal.value = false
    proxyApprovalNote.value = ''
}

const resetQuotationForm = () => {
    quotationForm.value = {
        materials: [{ name: '', quantity: 1, unit_price: 0 }],
        labor_cost: 0,
        transport_cost: 0,
        down_payment: null,
        notes: '',
        materials_file: null,
    materials_files: [],
        billing_milestones: [],
    }
    isRevision.value = false
}

/**
 * Open the quotation modal pre-filled with the existing quote so the
 * admin can adjust it (price change, missing line, negotiated discount)
 * and re-send. Marks isRevision so the backend sends the revised-quote
 * email instead of the original.
 */
const reviseExistingQuotation = (rfq) => {
    if (!rfq) return
    selectedRFQ.value = rfq

    const existingMaterials = Array.isArray(rfq.quote_materials) && rfq.quote_materials.length
        ? rfq.quote_materials.map((m) => ({
            name: m.name || '',
            quantity: Number(m.quantity) || 1,
            unit_price: Number(m.unit_price) || 0,
        }))
        : [{ name: '', quantity: 1, unit_price: 0 }]

    quotationForm.value = {
        materials: existingMaterials,
        labor_cost: Number(rfq.quote_labor_cost) || 0,
        transport_cost: Number(rfq.quote_transport_cost) || 0,
        down_payment: rfq.quote_down_payment !== null && rfq.quote_down_payment !== undefined
            ? Number(rfq.quote_down_payment)
            : null,
        notes: rfq.quote_notes || '',
        materials_file: null,
    materials_files: [],
        billing_milestones: Array.isArray(rfq.billing_milestones)
            ? rfq.billing_milestones.map(m => ({ label: m.label, progress_pct: m.progress_pct, amount: m.amount }))
            : [],
    }

    isRevision.value = true
    showViewModal.value = false
    showReviewModal.value = true
}

const addMaterial = () => { quotationForm.value.materials.push({ name: '', quantity: 1, unit_price: 0 }) }
const removeMaterial = (index) => { if (quotationForm.value.materials.length > 1) quotationForm.value.materials.splice(index, 1) }

// Multi-file quotation attachments. Appending (not replacing) so an admin
// picking a batch, then adding one more file, keeps everything.
const onMaterialsFilesChange = (e) => {
    const picked = Array.from(e.target.files || [])
    if (!picked.length) return
    quotationForm.value.materials_files = [
        ...(quotationForm.value.materials_files || []),
        ...picked,
    ].slice(0, 10) // enforce the same cap as the server validator
    // Reset the input so re-picking the same file re-triggers change.
    e.target.value = ''
}
const removeMaterialsFile = (index) => {
    if (!quotationForm.value.materials_files) return
    quotationForm.value.materials_files.splice(index, 1)
}

// Merge legacy single-file path with the new array so the "view attached"
// list surfaces every document regardless of when it was uploaded.
const quotationAttachmentUrls = computed(() => {
    const out = []
    const single = selectedRFQ.value?.quote_materials_file_path
    if (single) out.push({ href: `/storage/${single}`, label: fileNameFromPath(single) || 'Attached materials list' })
    const many = selectedRFQ.value?.quote_materials_file_paths
    if (Array.isArray(many)) {
        many.forEach((p, i) => {
            if (p) out.push({ href: `/storage/${p}`, label: fileNameFromPath(p) || `Attachment ${i + 1}` })
        })
    }
    return out
})
const fileNameFromPath = (path) => {
    if (typeof path !== 'string') return ''
    const parts = path.split('/')
    return parts[parts.length - 1] || path
}

const isSubmittingQuote = ref(false)

const submitQuote = () => {
    // Guard against double-tap / re-entry. The technician reported that
    // tapping Send a second time during the in-flight request caused the
    // backend to treat the second submission as a revision (#3).
    if (isSubmittingQuote.value || !canSubmitQuote.value) return
    isSubmittingQuote.value = true

    const validMilestones = quotationForm.value.billing_milestones.filter(
        m => m.label && m.progress_pct > 0 && m.amount >= 0
    )
    const durationDays = ((quotationForm.value.duration_weeks || 0) * 7) + (quotationForm.value.duration_extra_days || 0)

    router.post('/admin/rfq/quote', {
        service_request_id: selectedRFQ.value.id,
        materials: quotationForm.value.materials.filter(m => m.name && m.quantity > 0),
        labor_cost: quotationForm.value.labor_cost,
        transport_cost: quotationForm.value.transport_cost || 0,
        down_payment: quotationForm.value.down_payment || null,
        expected_duration_days: durationDays || null,
        total_amount: totalQuoteAmount.value,
        notes: quotationForm.value.notes,
        materials_file: quotationForm.value.materials_file,
        materials_files: (quotationForm.value.materials_files || []),
        is_revision: isRevision.value,
        billing_milestones: validMilestones.length ? validMilestones : null,
    }, {
        forceFormData: true, // ensure the file array is posted as multipart
        // Keep the modal + form state on validation errors so the admin
        // doesn't lose everything they typed if e.g. a numeric field trips.
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => closeReviewModal(),
        onError: (errors) => {
            const messages = Object.values(errors).flat().filter(Boolean)
            const summary = messages.length
                ? messages.slice(0, 5).join('\n• ')
                : 'Please check the highlighted fields and try again.'
            alert("Couldn't send the quotation:\n• " + summary)
        },
        onFinish: () => { isSubmittingQuote.value = false },
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
        amount: paymentRequestForm.value.amount,
        is_down_payment: paymentRequestForm.value.is_down_payment,
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

const submitApproveOnBehalf = () => {
    if (!selectedRFQ.value?.id) return
    if (proxyApprovalNote.value.trim().length < 10) {
        alert('Please add a note (at least 10 characters) explaining how the client approved.')
        return
    }

    router.post(`/admin/rfq/${selectedRFQ.value.id}/approve-on-behalf`, {
        note: proxyApprovalNote.value,
    }, {
        preserveState: false,
        preserveScroll: false,
        onSuccess: () => {
            closeApproveOnBehalfModal()
            closeViewModal()
        },
        onError: (errors) => {
            const firstError = Object.values(errors || {})[0]
            alert(firstError || 'Failed to approve on behalf. Please ensure this is an admin-assisted RFQ in "quoted" status and try again.')
        },
    })
}

// --- Helpers ---
const formatDate = (date) => date ? new Date(date).toLocaleDateString() : 'N/A'
const formatDateTime = (date) => date ? new Date(date).toLocaleString() : 'N/A'

/**
 * Round to 2 decimal places using safe integer math to avoid
 * floating-point drift (e.g. 22000 ending up as 21999.99 after
 * intermediate computations).
 */
const roundCurrency = (value) => Math.round((Number(value) || 0) * 100) / 100

const formatCurrency = (amount) => new Intl.NumberFormat('en-KE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
}).format(roundCurrency(amount))

const truncateText = (text, length) => (!text ? '' : text.length > length ? text.substring(0, length) + '...' : text)

const getDaysOpenLabel = (date) => {
    if (!date) return 'Date unavailable'
    const days = Math.max(0, Math.floor((Date.now() - new Date(date).getTime()) / 86400000))
    if (days === 0) return 'Opened today'
    if (days === 1) return 'Opened 1 day ago'
    return `Opened ${days} days ago`
}

const getStatusLabel = (status) => ({ pending: 'Pending Review', quoted: 'Awaiting Approval', approved: 'Approved', rejected: 'Rejected' })[status] || 'Unknown'
const getSubmissionModeLabel = (mode) => ({ client_self: 'Client Submitted', admin_proxy: 'Admin Assisted' })[mode] || 'Client Submitted'
const formatUrgencyLabel = (urgency) => ({ low: 'Low', medium: 'Medium', high: 'High' })[urgency] || (urgency || '—')

// Sum of all paid payment requests vs the quote total. Treats anything
// within 1 cent of the quote as "fully paid" to absorb rounding drift.
const isRfqFullyPaid = (rfq) => {
    if (!rfq || !rfq.quote_amount) return false
    const total = Number(rfq.quote_amount) || 0
    if (total <= 0) return false
    const paid = (rfq.payment_requests || [])
        .filter(pr => pr.status === 'paid')
        .reduce((sum, pr) => sum + (Number(pr.amount) || 0), 0)
    return paid + 0.01 >= total
}

defineOptions({ layout: null })
</script>

<style>

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
.hero-action-note { margin-bottom: 1.35rem; }
.hero-create-btn { display: inline-flex; margin-top: 5px; }

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
.toolbar-grid {
    /* Was a fixed 4-column grid; switched to flex-wrap so the new Needs
       Action pill and any future filters flow naturally onto the next line
       on narrow viewports instead of breaking the row. */
    display: flex !important;
    flex-wrap: wrap;
    align-items: end;
    gap: 1rem;
    margin-top: 1rem;
}
.toolbar-grid > .toolbar-field { flex: 1 1 180px; min-width: 180px; }
.toolbar-grid > .toolbar-field-wide { flex: 2 1 260px; min-width: 260px; }
.toolbar-grid > .needs-action-pill { flex: 0 0 auto; }
.toolbar-field { display: flex; flex-direction: column; gap: 0.45rem; font-weight: 700; color: #334155; }
.search-shell { display: inline-flex; align-items: center; gap: 0.65rem; padding: 0.82rem 0.95rem; border-radius: 14px; border: 1px solid #d7dee7; background: #f8fafc; }
.search-shell input, .status-filter { width: 100%; box-sizing: border-box; padding: 0.82rem 0.95rem; border: 1px solid #d7dee7; border-radius: 14px; background: #f8fafc; color: #0f172a; font: inherit; }
.search-shell input { padding: 0; border: none; background: transparent; outline: none; }
.search-shell:focus-within, .status-filter:focus { border-color: rgba(14, 116, 144, 0.45); box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12); background: #ffffff; outline: none; }
.filter-chip-row { margin-top: 1rem; }
.filter-chip { background: #e0f2fe; color: #0f6c8f; }
.clear-chip-btn { border: none; background: transparent; color: #0f6c8f; font-size: 0.82rem; font-weight: 700; cursor: pointer; }

/* Needs-Action pill — sits inline with the search + status filters and
   toggles the server-side needs_action=1 filter on/off. Amber when off
   (attention-worthy but calm), solid red when the filter is active so
   the admin sees they're viewing a subset. */
.needs-action-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.82rem 1rem;
    background: #fef3c7;
    border: 1px solid #fbbf24;
    color: #92400e;
    border-radius: 14px;
    font-weight: 700;
    font-size: 0.88rem;
    cursor: pointer;
    transition: transform 0.12s ease, background 0.12s ease, border-color 0.12s ease;
    white-space: nowrap;
    align-self: end;
}
.needs-action-pill:hover { transform: translateY(-1px); }
.needs-action-pill-on {
    background: #dc2626;
    border-color: #dc2626;
    color: #fff;
    box-shadow: 0 6px 14px -8px rgba(220, 38, 38, 0.55);
}
.needs-action-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 22px;
    padding: 0 0.4rem;
    background: rgba(220, 38, 38, 0.15);
    color: #b91c1c;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
}
.needs-action-pill-on .needs-action-count {
    background: rgba(255, 255, 255, 0.28);
    color: #fff;
}

/* Per-row amber chip that names WHY a REQ needs attention. Small and
   understated so it doesn't blow up the row; wraps if there are many. */
.row-action-reasons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    margin-top: 0.4rem;
}
.row-action-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.2rem 0.55rem;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fde68a;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
    white-space: nowrap;
}

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
.material-row {
    margin-bottom: 1rem;
    padding: 0.85rem 0.85rem 1rem;
    background: #FAFBFD;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
}
.material-row-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.6rem;
    gap: 0.5rem;
}
.material-row-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.btn-remove-row {
    background: #FEE2E2;
    color: #B91C1C;
    border: 1px solid #FCA5A5;
    border-radius: 8px;
    padding: 0.4rem 0.75rem;
    font-size: 0.8rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.btn-remove-row:hover:not(:disabled) {
    background: #DC2626;
    color: #fff;
    border-color: #DC2626;
}
.btn-remove-row:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.material-inputs { display: grid; grid-template-columns: 2fr 100px 120px 100px; gap: 0.75rem; align-items: center; }
.material-total { font-weight: 600; color: var(--success-color); text-align: right; font-size: 0.9rem; }
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
@media (max-width: 1024px) {
    .toolbar-grid { grid-template-columns: 1fr 1fr; }
    .toolbar-field-wide { grid-column: 1 / -1; }
    .rfq-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .hero-action-grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    .rfq-stats { grid-template-columns: 1fr 1fr; }
    .rfq-hero, .hero-action-grid, .toolbar-grid, .mobile-rfq-grid, .pagination-shell, .pagination-controls { grid-template-columns: 1fr; }
    .rfq-hero, .table-shell-header, .mobile-card-top, .pagination-shell { display: flex; flex-direction: column; align-items: flex-start; }
    .toolbar-grid { display: grid; }
    .desktop-table { display: none; }
    .mobile-rfq-list { display: grid; margin-top: 0; }
    .material-inputs { grid-template-columns: 1fr; gap: 0.5rem; }
    .material-total { text-align: left; }
    .btn-remove-row { padding: 0.5rem 0.85rem; font-size: 0.85rem; }
    .form-row { grid-template-columns: 1fr; gap: 1rem; }
    .modal-content.extra-large { width: 98%; max-height: 95vh; }
    .material-item-view { flex-direction: column; align-items: flex-start; gap: 0.25rem; }
    .quote-amount-bar { flex-direction: column; gap: 0.5rem; text-align: center; }
    .btn-pagination, .page-number-btn { width: 100%; justify-content: center; }
}

@media (min-width: 769px) {
    .mobile-rfq-list { display: none; }
}

/* Proxy payment */
.btn-teal { background: #0f766e; color: #ffffff; border: none; }
.btn-teal:hover:not(:disabled) { background: #0d6460; }
.btn-teal:disabled { opacity: 0.55; cursor: not-allowed; }

.proxy-payment-notice {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.8rem 1rem;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    color: #166534;
    font-size: 0.88rem;
    line-height: 1.5;
    margin-bottom: 1.25rem;
}
.proxy-payment-notice i { margin-top: 0.15rem; flex-shrink: 0; }

.evidence-upload-area {
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 1.1rem;
    cursor: pointer;
    transition: border-color 0.2s ease, background 0.2s ease;
    background: #f8fafc;
}
.evidence-upload-area:hover { border-color: #0f766e; background: #f0fdf4; }
.evidence-placeholder { display: flex; flex-direction: column; align-items: center; gap: 0.4rem; color: #64748b; text-align: center; }
.evidence-placeholder i { font-size: 1.5rem; }
.evidence-placeholder small { font-size: 0.78rem; }
.evidence-selected { display: flex; align-items: center; gap: 0.65rem; color: #0f172a; font-weight: 500; }
.remove-evidence-btn { margin-left: auto; border: none; background: transparent; color: #b91c1c; font-size: 1rem; cursor: pointer; }

/* ─────────────────────────────────────────────
   Request Payment modal (presentable redesign)
   ───────────────────────────────────────────── */
.pr-modal {
    max-width: 560px;
    width: 100%;
}

.pr-modal-header {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 1.1rem 1.25rem;
    border-bottom: 1px solid #E2E8F0;
}
.pr-header-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #38bdf8, #0ea5e9);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    flex-shrink: 0;
}
.pr-header-copy { flex: 1; min-width: 0; }
.pr-header-copy h3 { margin: 0; font-size: 1.05rem; color: #0F172A; }
.pr-header-sub {
    margin: 2px 0 0;
    font-size: 0.78rem;
    color: #64748B;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.pr-close {
    background: transparent;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    font-size: 1rem;
    padding: 6px 8px;
    border-radius: 8px;
}
.pr-close:hover { background: #F1F5F9; color: #0F172A; }

.pr-modal-body {
    padding: 1.1rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.95rem;
    max-height: 70vh;
    overflow-y: auto;
}

.pr-modal-footer {
    border-top: 1px solid #E2E8F0;
    padding: 0.9rem 1.25rem;
    display: flex;
    justify-content: flex-end;
    gap: 0.6rem;
}

/* Balance summary cards */
.pr-balance-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.6rem;
}
.pr-balance-tile {
    padding: 0.7rem 0.75rem;
    border-radius: 12px;
    border: 1px solid transparent;
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.pr-balance-tile.tile-blue { background: #EFF6FF; border-color: #BFDBFE; color: #1E3A8A; }
.pr-balance-tile.tile-amber { background: #FFFBEB; border-color: #FDE68A; color: #92400E; }
.pr-balance-tile.tile-green { background: #ECFDF5; border-color: #A7F3D0; color: #065F46; }
.pr-balance-tile.tile-red { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }
.pr-tile-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    opacity: 0.85;
}
.pr-balance-tile strong { font-size: 0.92rem; font-weight: 700; }

/* Info strips */
.pr-info-strip {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.75rem 0.85rem;
    border-radius: 10px;
    font-size: 0.85rem;
    line-height: 1.4;
    border: 1px solid transparent;
}
.pr-info-strip i.fas { margin-top: 2px; flex-shrink: 0; }
.pr-info-success { background: #ECFDF5; border-color: #A7F3D0; color: #065F46; }
.pr-info-error { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }

/* Prior payment requests */
.pr-prior-section {
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    overflow: hidden;
    background: #FAFBFD;
}
.pr-section-head {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.65rem 0.85rem;
    background: #F1F5F9;
    border-bottom: 1px solid #E2E8F0;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #475569;
}
.pr-section-head i.fas { color: #64748B; }
.pr-prior-count {
    margin-left: auto;
    background: #CBD5E1;
    color: #1E293B;
    padding: 1px 8px;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 800;
}
.pr-prior-list { display: flex; flex-direction: column; }
.pr-prior-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.6rem 0.85rem;
    border-bottom: 1px solid #E2E8F0;
    font-size: 0.85rem;
}
.pr-prior-item:last-child { border-bottom: none; }
.pr-prior-meta { display: flex; flex-direction: column; gap: 2px; }
.pr-prior-meta strong { color: #0F172A; font-size: 0.83rem; }
.pr-prior-meta span { color: #475569; font-size: 0.78rem; }
.pr-prior-status {
    text-transform: capitalize;
    font-size: 0.72rem;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 999px;
    background: #E2E8F0;
    color: #475569;
}
.pr-prior-status-paid { background: #DCFCE7; color: #166534; }
.pr-prior-status-pending { background: #FEF3C7; color: #92400E; }
.pr-prior-status-cancelled,
.pr-prior-status-failed { background: #FEE2E2; color: #991B1B; }

/* Sections + inputs */
.pr-section { display: flex; flex-direction: column; gap: 0.45rem; }
.pr-section-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    display: flex;
    align-items: center;
    gap: 0.55rem;
    flex-wrap: wrap;
}
.pr-locked-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    background: #FEF3C7;
    color: #92400E;
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
}
.pr-input[readonly] {
    background: #F1F5F9;
    color: #475569;
    cursor: not-allowed;
}
.pr-optional {
    text-transform: none;
    font-weight: 500;
    color: #94A3B8;
    letter-spacing: 0;
}

.pr-input-grid {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 0.6rem;
    align-items: end;
}
.pr-input-field { display: flex; flex-direction: column; gap: 4px; }
.pr-input-field > label {
    font-size: 0.72rem;
    font-weight: 600;
    color: #64748B;
    margin: 0;
}
.pr-input-or {
    text-align: center;
    font-size: 0.75rem;
    font-weight: 600;
    color: #94A3B8;
    padding-bottom: 12px;
}
.pr-input-wrap { position: relative; }
.pr-input {
    width: 100%;
    box-sizing: border-box;
    padding: 0.55rem 0.7rem;
    border: 1px solid #CBD5E1;
    border-radius: 10px;
    background: #ffffff;
    font-size: 0.95rem;
    color: #0F172A;
    font-family: inherit;
}
.pr-input:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}
.pr-input-with-prefix { padding-left: 2.8rem; }
.pr-input-suffix,
.pr-input-prefix {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.82rem;
    color: #64748B;
    pointer-events: none;
}
.pr-input-suffix { right: 0.7rem; }
.pr-input-prefix {
    left: 0.7rem;
    font-weight: 700;
    background: #F1F5F9;
    padding: 2px 6px;
    border-radius: 6px;
    font-size: 0.72rem;
}

.pr-textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 0.65rem 0.8rem;
    border: 1px solid #CBD5E1;
    border-radius: 10px;
    background: #ffffff;
    font-size: 0.9rem;
    color: #0F172A;
    font-family: inherit;
    resize: vertical;
    min-height: 70px;
}
.pr-textarea:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

/* Calculated amount summary */
.pr-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    background: #EFF6FF;
    border: 1px solid #BFDBFE;
    border-radius: 12px;
}
.pr-summary-error {
    background: #FEF2F2;
    border-color: #FECACA;
}
.pr-summary-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #1E40AF;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.pr-summary-error .pr-summary-label { color: #991B1B; }
.pr-summary-value {
    font-size: 1.15rem;
    color: #1E40AF;
    font-weight: 800;
}
.pr-summary-error .pr-summary-value { color: #991B1B; }

/* Down payment checkbox card */
.pr-checkbox-card {
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    background: #FAFBFD;
    padding: 0.75rem 0.85rem;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.pr-checkbox-card:has(.pr-checkbox-input:checked) {
    border-color: #93C5FD;
    background: #EFF6FF;
}
.pr-checkbox-label {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    cursor: pointer;
    margin: 0;
}
.pr-checkbox-input {
    /* Override global .form-group input rule that stretches inputs full-width */
    width: 18px !important;
    height: 18px !important;
    margin: 2px 0 0 !important;
    padding: 0 !important;
    flex-shrink: 0;
    accent-color: #2563EB;
    cursor: pointer;
}
.pr-checkbox-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}
.pr-checkbox-text strong { font-size: 0.88rem; color: #0F172A; font-weight: 600; }
.pr-checkbox-text small { font-size: 0.78rem; color: #64748B; line-height: 1.4; }

.pr-submit { gap: 0.4rem; }

@media (max-width: 560px) {
    .pr-balance-grid { grid-template-columns: 1fr; }
    .pr-input-grid {
        grid-template-columns: 1fr;
    }
    .pr-input-or {
        padding-bottom: 0;
        text-align: left;
    }
}

/* Flash banners */
.flash-banner {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1.1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
    font-weight: 500;
    border: 1px solid transparent;
}
.flash-banner-success { background: #ECFDF5; color: #065F46; border-color: #A7F3D0; }
.flash-banner-error { background: #FEF2F2; color: #991B1B; border-color: #FECACA; }
.flash-banner i.fas { font-size: 1.1rem; }
.flash-banner span { flex: 1; }
.flash-close { background: transparent; border: none; cursor: pointer; color: inherit; opacity: 0.6; padding: 4px 6px; }
.flash-close:hover { opacity: 1; }
.flash-enter-active, .flash-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.flash-enter-from { opacity: 0; transform: translateY(-6px); }
.flash-leave-to { opacity: 0; transform: translateY(-6px); }

.billing-milestones-table { margin-top: 0.5rem; border: 1px solid var(--border-color, #e5e7eb); border-radius: 0.375rem; overflow: hidden; }
.billing-ms-header, .billing-ms-row { display: grid; grid-template-columns: 3fr 1fr 2fr 2fr auto; gap: 0.5rem; padding: 0.5rem 0.75rem; align-items: center; }
.billing-ms-running { font-size: 0.85rem; }
.billing-ms-warn { color: var(--danger-color, #dc2626); }
.billing-ms-summary { display: flex; gap: 1rem; flex-wrap: wrap; padding: 0.6rem 0.75rem; background: var(--bg-secondary, #f9fafb); border-top: 2px solid var(--border-color, #e5e7eb); font-size: 0.85rem; }
.billing-ms-summary > span { display: flex; flex-direction: column; }
.billing-ms-header { background: var(--bg-secondary, #f9fafb); font-size: 0.75rem; font-weight: 600; color: var(--text-muted, #6b7280); text-transform: uppercase; letter-spacing: 0.04em; }
.billing-ms-row { border-top: 1px solid var(--border-color, #e5e7eb); }
.billing-ms-row input { padding: 0.25rem 0.5rem; font-size: 0.85rem; }
.btn-xs { padding: 0.25rem 0.625rem; font-size: 0.75rem; line-height: 1.25; border-radius: 0.25rem; }
.urgency-badge { display: inline-block; padding: 0.2rem 0.55rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
.urgency-low { background: #E0F2FE; color: #075985; }
.urgency-medium { background: #FEF3C7; color: #92400E; }
.urgency-high { background: #FEE2E2; color: #991B1B; }
.paid-pill { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.2rem 0.5rem; border-radius: 999px; background: #DCFCE7; color: #166534; font-size: 0.7rem; font-weight: 700; }
.paid-pill i { font-size: 0.65rem; }
</style>
