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
                <Link href="/admin/rfq" class="nav-item active">
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
                <Link href="/admin/payments" class="nav-item">
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
                <h1>RFQ Management</h1>
                <div class="header-actions">
                    <select v-model="statusFilter" @change="filterRFQs" class="status-filter">
                        <option value="all">All Requests</option>
                        <option value="pending">Pending Review</option>
                        <option value="quoted">Awaiting Client Approval</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </header>

            <!-- RFQ Statistics Cards -->
            <section class="rfq-stats">
                <div class="stat-card">
                    <h4>Pending Review</h4>
                    <p class="stat-value">{{ stats.pending || '0' }}</p>
                    <span class="stat-icon pending"><i class="fas fa-clock"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Quoted</h4>
                    <p class="stat-value">{{ stats.quoted || '0' }}</p>
                    <span class="stat-icon quoted"><i class="fas fa-file-invoice"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Approved</h4>
                    <p class="stat-value">{{ stats.approved || '0' }}</p>
                    <span class="stat-icon approved"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Total Value</h4>
                    <p class="stat-value">KSH {{ formatCurrency(stats.totalValue || 0) }}</p>
                    <span class="stat-icon value"><i class="fas fa-money-bill-wave"></i></span>
                </div>
            </section>

            <!-- RFQ List -->
            <section class="rfq-list-section">
                <div class="panel-card">
                    <div class="card-header">
                        <h3>Service Requests</h3>
                        <div class="header-controls" style="display: flex; gap: 1rem; align-items: center;">
                            <select v-model="sortOrder" class="status-filter" style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                            </select>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input v-model="searchTerm" type="text" placeholder="Search by name, service...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="rfq-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>Client</th>
                                    <th>Service</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Quote Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>

                                <tr v-for="rfq in paginatedRFQs" :key="rfq.id">
                                    <td>
                                        <strong>{{ rfq.request_id || `REQ-${rfq.id}` }}</strong>
                                    </td>
                                    <td>
                                        <div class="client-info">
                                            <strong>{{ rfq.user?.name || 'N/A' }}</strong>
                                            <br>
                                            <small>{{ rfq.user?.email || 'No email' }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="service-info">
                                            <strong>{{ rfq.service_category?.name || 'General Service' }}</strong>
                                            <br>
                                            <small>{{ truncateText(rfq.description, 50) }}</small>
                                        </div>
                                    </td>
                                    <td>{{ formatDate(rfq.created_at) }}</td>
                                    <td>
                                        <span :class="['status-badge', rfq.rfq_status || 'pending']">
                                            {{ getStatusLabel(rfq.rfq_status || 'pending') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span v-if="rfq.quote_amount" class="quote-amount">
                                            KSH {{ formatCurrency(rfq.quote_amount) }}
                                        </span>
                                        <span v-else class="no-quote">-</span>
                                    </td>
                                    <td>
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
                                            <button v-if="rfq.rfq_status === 'quoted'" @click="viewQuote(rfq)" class="btn btn-sm btn-success" title="View Quote">
                                                <i class="fas fa-receipt"></i>
                                            </button>

                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div v-if="filteredRFQs.length > itemsPerPage" class="pagination-controls" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; border-top: 1px solid #dee2e6;">
                            <div class="pagination-info">
                                Showing {{ ((currentPage - 1) * itemsPerPage) + 1 }} - {{ Math.min(currentPage * itemsPerPage, filteredRFQs.length) }} of {{ filteredRFQs.length }}
                            </div>
                            <div class="pagination-buttons" style="display: flex; gap: 0.5rem;">
                                <button 
                                    @click="currentPage--" 
                                    :disabled="currentPage === 1"
                                    class="btn-pagination"
                                    style="padding: 0.5rem 1rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer;"
                                    :style="{ opacity: currentPage === 1 ? '0.5' : '1' }"
                                >
                                    Previous
                                </button>
                                <button 
                                    @click="currentPage++" 
                                    :disabled="currentPage === totalPages"
                                    class="btn-pagination"
                                    style="padding: 0.5rem 1rem; border: 1px solid #dee2e6; background: white; border-radius: 4px; cursor: pointer;"
                                    :style="{ opacity: currentPage === totalPages ? '0.5' : '1' }"
                                >
                                    Next
                                </button>
                            </div>
                        </div>

                        <div v-if="filteredRFQs.length === 0" class="no-data">
                            <i class="fas fa-inbox"></i>
                            <p>No RFQ requests found</p>
                        </div>
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
                        <!-- Request Information -->
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

                        <!-- Attached Files (if any) -->
                        <div v-if="selectedRFQ?.files && selectedRFQ.files.length > 0" class="files-section" style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #eee;">
                            <h4 style="margin-bottom: 1rem; color: #444; font-size: 1rem;">Attached Files</h4>
                            <div class="files-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 1rem;">
                                <div v-for="(file, index) in selectedRFQ.files" :key="index" class="file-card" style="display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
                                    <div class="file-icon" style="font-size: 1.5rem; color: var(--primary-color);">
                                        <i class="fas fa-file-image" v-if="file.mime_type?.includes('image')"></i>
                                        <i class="fas fa-file-pdf" v-else-if="file.mime_type?.includes('pdf')"></i>
                                        <i class="fas fa-file-word" v-else-if="file.mime_type?.includes('word') || file.mime_type?.includes('doc')"></i>
                                        <i class="fas fa-file-alt" v-else></i>
                                    </div>
                                    <div class="file-info" style="flex: 1; min-width: 0;">
                                        <div class="file-name" style="font-weight: 500; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" :title="file.name">
                                            {{ file.name || 'Attachment' }}
                                        </div>
                                        <a :href="`/storage/${file.path}`" target="_blank" download class="file-action" style="font-size: 0.8rem; color: var(--primary-color); text-decoration: none;">
                                            Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quote Information (if exists) -->
                        <div v-if="selectedRFQ?.rfq_status === 'quoted' || selectedRFQ?.rfq_status === 'approved'" class="quote-section">
                            <h4>Quotation Details</h4>
                            <div class="quote-display">
                                <div class="quote-amount">
                                    <span class="label">Total Amount:</span>
                                    <span class="amount">KSH {{ formatCurrency(selectedRFQ?.quote_amount) }}</span>
                                </div>

                                <div v-if="selectedRFQ?.quote_materials" class="materials-display">
                                    <h5>Materials:</h5>
                                    <div class="materials-list-view">
                                        <div v-for="material in selectedRFQ.quote_materials" :key="material.name" class="material-item-view">
                                            <span class="name">{{ material.name }}</span>
                                            <span class="details">{{ material.quantity }} × KSH {{ formatCurrency(material.unit_price) }} = KSH {{ formatCurrency(material.quantity * material.unit_price) }}</span>
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

                        <!-- Rejection Reason (if rejected) -->
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
                    <!-- Request Summary -->
                    <div class="request-summary">
                        <div class="summary-card">
                            <h4><i class="fas fa-user"></i> {{ selectedRFQ?.user?.name }}</h4>
                            <p><strong>Service:</strong> {{ selectedRFQ?.service_category?.name }}</p>
                            <p><strong>Description:</strong> {{ selectedRFQ?.description }}</p>
                            <p v-if="selectedRFQ?.location"><strong>Location:</strong> {{ selectedRFQ?.location }}</p>
                        </div>
                    </div>

                    <!-- Quotation Form -->
                    <div class="quotation-form-section">
                        <form @submit.prevent="submitQuote">
                            <!-- Materials Section -->
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
                                    <input 
                                        type="file" 
                                        @change="e => quotationForm.materials_file = e.target.files[0]" 
                                        accept=".pdf,.docx,.xlsx,.xls"
                                        class="form-control"
                                    >
                                    <small style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem; display: block;">
                                        Upload PDF, DOCX, or Excel file if materials list is too long (Max 10MB)
                                    </small>
                                </div>
                            </div>

                            <!-- Labor & Notes Section -->
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

                            <!-- Cost Summary -->
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
                    <button @click="closeReviewModal" class="btn btn-secondary">
                        Cancel
                    </button>
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
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Requesting payment for RFQ <strong>{{ selectedRFQ?.request_id }}</strong>
                            (Total: KSH {{ formatCurrency(selectedRFQ?.quote_amount) }})
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
                                @input="calculatePaymentAmount"
                            >
                        </div>

                        <div class="form-group">
                            <label>Amount to Request (KSH)</label>
                            <input 
                                :value="formatCurrency(paymentRequestForm.amount)"
                                type="text" 
                                class="form-control" 
                                readonly
                                disabled
                                style="background-color: #f3f4f6;"
                            >
                        </div>
                        
                        <div class="form-group">
                            <label>Notes for Client</label>
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
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-paper-plane"></i> Send Request
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

        <!-- Payment Request Modal -->
        <div v-if="showPaymentRequestModal" class="modal-overlay" @click="closePaymentRequestModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Request Payment</h3>
                    <button @click="closePaymentRequestModal" class="modal-close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

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
                        <label>Payment Percentage (%):</label>
                        <input
                            v-model="paymentRequestForm.percentage"
                            type="number"
                            min="1"
                            max="100"
                            class="form-control"
                            placeholder="Enter percentage (e.g., 50 for 50%)"
                        >
                        <small class="form-help">Enter the percentage of the total amount to request from the client.</small>
                    </div>

                    <div class="calculated-amount" v-if="paymentRequestForm.percentage > 0">
                        <span class="label">Amount to Request:</span>
                        <span class="amount">KSH {{ formatCurrency(calculatedPaymentAmount) }}</span>
                    </div>

                    <div class="form-group">
                        <label>Notes (Optional):</label>
                        <textarea
                            v-model="paymentRequestForm.notes"
                            class="form-control"
                            rows="3"
                            placeholder="Any additional notes for the client..."
                        ></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button @click="closePaymentRequestModal" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="submitPaymentRequest"
                        class="btn btn-success"
                        :disabled="!canSubmitPaymentRequest || isSubmittingPayment"
                    >
                        <i class="fas fa-paper-plane"></i> {{ isSubmittingPayment ? 'Sending...' : 'Send Payment Request' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { ref, computed, onMounted, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps({
    rfqs: {
        type: Array,
        default: () => []
    },
    stats: {
        type: Object,
        default: () => ({
            pending: 0,
            quoted: 0,
            approved: 0,
            totalValue: 0
        })
    }
})

const searchTerm = ref('')
const statusFilter = ref('all')
const showViewModal = ref(false)
const showReviewModal = ref(false)
const showRejectModal = ref(false)
const showPaymentRequestModal = ref(false)
const selectedRFQ = ref(null)
const rejectionReason = ref('')
const sortOrder = ref('newest')
const isSubmittingPayment = ref(false)
const showPaymentModal = ref(false)



// Pagination
const currentPage = ref(1)
const itemsPerPage = 15

const quotationForm = ref({
    materials: [{ name: '', quantity: 1, unit_price: 0 }],

    labor_cost: 0,
    notes: '',
    materials_file: null
})

const paymentRequestForm = ref({
    percentage: 50,
    amount: 0,
    notes: ''
})

const filteredRFQs = computed(() => {
    let filtered = props.rfqs
    
    if (searchTerm.value) {
        const search = searchTerm.value.toLowerCase()
        filtered = filtered.filter(rfq => 
            rfq.user?.name?.toLowerCase().includes(search) ||
            rfq.service_category?.name?.toLowerCase().includes(search) ||
            rfq.description?.toLowerCase().includes(search)
        )

    }
    
    if (statusFilter.value !== 'all') {
        filtered = filtered.filter(rfq => (rfq.rfq_status || 'pending') === statusFilter.value)
    }

    return filtered.sort((a, b) => {
        const dateA = new Date(a.created_at)
        const dateB = new Date(b.created_at)
        return sortOrder.value === 'newest' ? dateB - dateA : dateA - dateB
    })
})

const totalPages = computed(() => Math.ceil(filteredRFQs.value.length / itemsPerPage))

const paginatedRFQs = computed(() => {
    const start = (currentPage.value - 1) * itemsPerPage
    const end = start + itemsPerPage
    return filteredRFQs.value.slice(start, end)
})

// Reset pagination when filters change
watch([searchTerm, statusFilter, sortOrder], () => {
    currentPage.value = 1
})

const materialsTotal = computed(() => {
    return quotationForm.value.materials.reduce((total, material) => {
        return total + (material.quantity * material.unit_price)
    }, 0)
})

const totalQuoteAmount = computed(() => {
    return materialsTotal.value + (quotationForm.value.labor_cost || 0)
})

const canSubmitQuote = computed(() => {
    return quotationForm.value.materials.some(m => m.name && m.quantity > 0 && m.unit_price > 0) &&
           quotationForm.value.labor_cost >= 0
})

const calculatedPaymentAmount = computed(() => {
    if (!selectedRFQ.value?.quote_amount || !paymentRequestForm.value.percentage) return 0
    return (paymentRequestForm.value.percentage / 100) * selectedRFQ.value.quote_amount
})

const canSubmitPaymentRequest = computed(() => {
    return paymentRequestForm.value.percentage > 0 &&
           paymentRequestForm.value.percentage <= 100 &&
           selectedRFQ.value?.id
})

const viewRFQ = (rfq) => {
    selectedRFQ.value = rfq
    showViewModal.value = true
}

const closeViewModal = () => {
    showViewModal.value = false
    selectedRFQ.value = null
}

const editRFQ = () => {
    showViewModal.value = false
    showReviewModal.value = true
    resetQuotationForm()
}

const viewQuote = (rfq) => {
    selectedRFQ.value = rfq
    showViewModal.value = true
}

const reviewRFQ = (rfq) => {
    selectedRFQ.value = rfq
    resetQuotationForm()
    showReviewModal.value = true
}

const closeReviewModal = () => {
    showReviewModal.value = false
    selectedRFQ.value = null
    resetQuotationForm()
}

const closeRejectModal = () => {
    showRejectModal.value = false
    rejectionReason.value = ''
}

const openPaymentRequestModal = (rfq) => {
    selectedRFQ.value = rfq
    paymentRequestForm.value = {
        percentage: 50,
        notes: ''
    }
    showPaymentRequestModal.value = true
}

const closePaymentRequestModal = () => {
    showPaymentRequestModal.value = false
    selectedRFQ.value = null
    paymentRequestForm.value = {
        percentage: 50,
        notes: ''
    }
}

const initiatePaymentRequest = (rfq) => {
    selectedRFQ.value = rfq
    paymentRequestForm.value = {
        percentage: 50,
        amount: (rfq.quote_amount || 0) * 0.5,
        notes: ''
    }
    showPaymentModal.value = true
}

const closePaymentModal = () => {
    showPaymentModal.value = false
    selectedRFQ.value = null
}

const calculatePaymentAmount = () => {
    if (selectedRFQ.value && paymentRequestForm.value.percentage) {
        const percentage = Math.min(Math.max(paymentRequestForm.value.percentage, 0), 100)
        paymentRequestForm.value.amount = (selectedRFQ.value.quote_amount || 0) * (percentage / 100)
    }
}

const submitPaymentRequest = () => {
    if (!selectedRFQ.value) return
    
    isSubmittingPayment.value = true

    axios.post(`/admin/rfq/${selectedRFQ.value.id}/request-payment`, {
        percentage: paymentRequestForm.value.percentage,
        notes: paymentRequestForm.value.notes
    })
    .then(response => {
        if (response.data.success) {
            alert('Payment request sent successfully!')
            closePaymentModal()
            // Reload to ensure state consistency (optional but safe)
            window.location.reload()
        }
    })
    .catch(error => {
        console.error('Payment request error:', error)
        alert(error.response?.data?.error || 'Failed to send payment request.')
    })
    .finally(() => {
        isSubmittingPayment.value = false
    })
}

const resetQuotationForm = () => {
    quotationForm.value = {
        materials: [{ name: '', quantity: 1, unit_price: 0 }],
        labor_cost: 0,
        labor_cost: 0,
        notes: '',
        materials_file: null
    }
}

const addMaterial = () => {
    quotationForm.value.materials.push({ name: '', quantity: 1, unit_price: 0 })
}

const removeMaterial = (index) => {
    if (quotationForm.value.materials.length > 1) {
        quotationForm.value.materials.splice(index, 1)
    }
}

const submitQuote = () => {
    const quoteData = {
        service_request_id: selectedRFQ.value.id,
        materials: quotationForm.value.materials.filter(m => m.name && m.quantity > 0),
        labor_cost: quotationForm.value.labor_cost,
        total_amount: totalQuoteAmount.value,
        labor_cost: quotationForm.value.labor_cost,
        total_amount: totalQuoteAmount.value,
        notes: quotationForm.value.notes,
        materials_file: quotationForm.value.materials_file
    }
    
    router.post('/admin/rfq/quote', quoteData, {
        onSuccess: () => {
            closeReviewModal()
        },
        onError: (errors) => {
            console.error('Quote submission failed:', errors)
        }
    })
}

const rejectRFQ = () => {
    showRejectModal.value = true
}

const confirmReject = () => {
    router.post(`/admin/rfq/${selectedRFQ.value.id}/reject`, {
        reason: rejectionReason.value
    }, {
        onSuccess: () => {
            closeRejectModal()
            closeReviewModal()
        },
        onError: (errors) => {
            console.error('Rejection failed:', errors)
        }
    })
}

const filterRFQs = () => {
    // Filtering is handled by computed property
}

const formatDate = (date) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleDateString()
}

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-KE').format(amount || 0)
}

const truncateText = (text, length) => {
    if (!text) return ''
    return text.length > length ? text.substring(0, length) + '...' : text
}

const getStatusLabel = (status) => {
    const labels = {
        pending: 'Pending Review',
        quoted: 'Awaiting Approval',
        approved: 'Approved',
        rejected: 'Rejected'
    }
    return labels[status] || 'Unknown'
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

/* RFQ specific styles */
.rfq-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

/* View Modal Styles */
.view-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2rem;
}

.info-section,
.quote-section,
.rejection-section {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.info-section h4,
.quote-section h4,
.rejection-section h4 {
    margin-bottom: 1rem;
    color: var(--primary-color);
    border-bottom: 2px solid var(--primary-color);
    padding-bottom: 0.5rem;
}

.quote-display {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
}

.quote-amount {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding: 1rem;
    background: #e8f4fd;
    border-radius: 6px;
}

.quote-amount .label {
    font-weight: 600;
    color: var(--text-color);
}

.quote-amount .amount {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--success-color);
}

.materials-display h5 {
    color: var(--text-color);
    margin-bottom: 0.75rem;
    font-size: 1rem;
}

.materials-list-view {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.material-item-view {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
}

.material-item-view .name {
    font-weight: 500;
    color: var(--text-color);
}

.material-item-view .details {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.cost-summary {
    border-top: 1px solid #dee2e6;
    padding-top: 1rem;
    margin-top: 1rem;
}

.cost-line {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
    font-weight: 500;
}

.quote-notes-display {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 6px;
    margin-top: 1rem;
    border-left: 4px solid var(--info-color);
}

.quote-notes-display h5 {
    color: var(--info-color);
    margin-bottom: 0.5rem;
}

.rejection-display {
    background: #fee2e2;
    padding: 1rem;
    border-radius: 6px;
    border-left: 4px solid #dc2626;
}

.rejection-display p {
    margin: 0;
    color: #7f1d1d;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    position: relative;
    overflow: hidden;
}

.stat-card h4 {
    margin: 0 0 0.5rem 0;
    color: var(--text-color);
    font-size: 0.9rem;
    font-weight: 500;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    margin: 0;
    color: var(--primary-color);
}

.stat-icon {
    position: absolute;
    top: 1rem;
    right: 1rem;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
}

.stat-icon.pending { background: #F97316; }
.stat-icon.quoted { background: #2563EB; }
.stat-icon.approved { background: #16A34A; }
.stat-icon.value { background: #9333EA; }

.status-filter {
    padding: 0.5rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: white;
}

.rfq-table {
    overflow-x: auto;
}

.rfq-table table {
    width: 100%;
    border-collapse: collapse;
}

.rfq-table th,
.rfq-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.rfq-table th {
    background: var(--bg-light);
    font-weight: 600;
    color: var(--text-color);
}

.client-info strong {
    color: var(--text-color);
}

.client-info small {
    color: var(--text-muted);
}

.service-info strong {
    color: var(--text-color);
}

.service-info small {
    color: var(--text-muted);
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: capitalize;
}

.status-badge.pending {
    background: #FEF3C7;
    color: #D97706;
}

.status-badge.quoted {
    background: #DBEAFE;
    color: #2563EB;
}

.status-badge.approved {
    background: #D1FAE5;
    color: #059669;
}

.status-badge.rejected {
    background: #FEE2E2;
    color: #DC2626;
}

.quote-amount {
    font-weight: 600;
    color: var(--success-color);
}

.no-quote {
    color: var(--text-muted);
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.no-data {
    text-align: center;
    padding: 3rem;
    color: var(--text-muted);
}

.no-data i {
    font-size: 3rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

/* Modal styles */
.modal-content.large {
    width: 90%;
    max-width: 1000px;
}

.modal-content.extra-large {
    width: 95%;
    max-width: 1200px;
    max-height: 90vh;
}

/* Request Summary */
.request-summary {
    margin-bottom: 2rem;
}

.summary-card {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    border-left: 4px solid var(--primary-color);
}

.summary-card h4 {
    margin: 0 0 1rem 0;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.summary-card p {
    margin: 0.5rem 0;
    color: var(--text-color);
}

/* Form Sections */
.quotation-form-section {
    background: white;
}

.form-section {
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: #fafafa;
    border-radius: 8px;
    border: 1px solid #e9ecef;
}

.form-section h4 {
    margin: 0 0 1.5rem 0;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border-bottom: 2px solid var(--primary-color);
    padding-bottom: 0.5rem;
}

/* Materials Container */
.materials-container {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
}

.material-row {
    margin-bottom: 1rem;
}

.material-inputs {
    display: grid;
    grid-template-columns: 2fr 100px 120px 100px 40px;
    gap: 0.75rem;
    align-items: center;
}

.material-name {
    font-weight: 500;
}

.material-qty,
.material-price {
    text-align: right;
}

.material-total {
    font-weight: 600;
    color: var(--success-color);
    text-align: right;
    font-size: 0.9rem;
}

.btn-remove {
    background: var(--danger-color);
    color: white;
    border: none;
    border-radius: 4px;
    padding: 0.5rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 35px;
    height: 35px;
}

.btn-remove:hover:not(:disabled) {
    background: #DC2626;
}

.btn-remove:disabled {
    background: #ccc;
    cursor: not-allowed;
}

.add-material-btn {
    margin-top: 1rem;
    border: 2px dashed var(--primary-color);
    background: transparent;
    color: var(--primary-color);
    padding: 0.75rem 1rem;
}

.add-material-btn:hover {
    background: var(--primary-color);
    color: white;
}

/* Form Row */
.form-row {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 2rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 0.5rem;
}

/* Cost Summary */
.cost-summary-section {
    margin-top: 2rem;
}

.cost-summary-card {
    background: linear-gradient(135deg, #e8f4fd 0%, #f0f9ff 100%);
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #bae6fd;
}

.cost-summary-card h4 {
    margin: 0 0 1rem 0;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.cost-breakdown {
    background: white;
    padding: 1rem;
    border-radius: 6px;
    border: 1px solid #dbeafe;
}

.cost-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.cost-item:last-child {
    border-bottom: none;
}

.cost-item.total {
    border-top: 2px solid var(--primary-color);
    margin-top: 0.5rem;
    padding-top: 1rem;
    font-weight: bold;
    font-size: 1.1rem;
    color: var(--primary-color);
}

/* Payment Request Modal Styles */
.payment-request-info {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    border: 1px solid #e9ecef;
}

.payment-request-info .info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #e9ecef;
}

.payment-request-info .info-row:last-child {
    border-bottom: none;
}

.payment-request-info .label {
    color: var(--text-muted);
    font-weight: 500;
}

.payment-request-info .value {
    color: var(--text-color);
    font-weight: 600;
}

.payment-request-info .value.highlight {
    color: var(--success-color);
    font-size: 1.1rem;
}

.calculated-amount {
    background: linear-gradient(135deg, #e8f4fd 0%, #f0f9ff 100%);
    padding: 1rem;
    border-radius: 8px;
    margin: 1rem 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid #bae6fd;
}

.calculated-amount .label {
    color: var(--text-color);
    font-weight: 500;
}

.calculated-amount .amount {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--primary-color);
}

.form-help {
    color: var(--text-muted);
    font-size: 0.85rem;
    margin-top: 0.25rem;
    display: block;
}

.btn-warning {
    background: #F59E0B;
    color: white;
    border: none;
}

.btn-warning:hover {
    background: #D97706;
}

/* Responsive design */
@media (max-width: 768px) {
    .rfq-stats {
        grid-template-columns: 1fr;
    }

    .material-inputs {
        grid-template-columns: 1fr;
        gap: 0.5rem;
    }

    .form-row {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .modal-content.extra-large {
        width: 98%;
        max-height: 95vh;
    }

    .material-total {
        text-align: left;
        margin-top: 0.25rem;
    }

    .rfq-table {
        font-size: 0.9rem;
    }

    .action-buttons {
        flex-direction: column;
    }

    .material-item-view {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.25rem;
    }

    .quote-amount {
        flex-direction: column;
        gap: 0.5rem;
        text-align: center;
    }
}
</style>