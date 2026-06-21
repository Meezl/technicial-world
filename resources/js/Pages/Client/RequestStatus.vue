<template>
    <div class="dashboard-container client-pwa-shell">
        <ClientSidebar current-page="dashboard" />
        <ClientBottomNav current-page="dashboard" />

        <main class="main-content">
            <header class="main-header">
                <h1>Request Details - {{ serviceRequest.request_id }}</h1>
                <Link href="/client/dashboard" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </Link>
            </header>

            <!-- RFQ Status Section -->
            <section class="panel-section" v-if="serviceRequest.rfq_status">
                <div class="panel-card full-width rfq-status-card">
                    <div class="card-header">
                        <h3>Request for Quotation (RFQ) Status</h3>
                        <span :class="['status', getRFQStatusClass(serviceRequest.rfq_status)]">
                            {{ formatRFQStatus(serviceRequest.rfq_status) }}
                        </span>
                    </div>

                    <!-- Pending RFQ -->
                    <div v-if="serviceRequest.rfq_status === 'pending'" class="rfq-pending">
                        <div class="info-message">
                            <i class="fas fa-clock"></i>
                            <p>Your service request is being reviewed by our team. We will send you a quotation shortly.</p>
                        </div>
                    </div>

                    <!-- Quotation Received -->
                    <div v-else-if="serviceRequest.rfq_status === 'quoted'" class="rfq-quoted">
                        <div class="quotation-details">
                            <div class="quote-header">
                                <h4><i class="fas fa-file-invoice-dollar"></i> Quotation Details</h4>
                                <div class="quote-total">
                                    <span class="amount">KSH {{ formatCurrency(serviceRequest.quote_amount) }}</span>
                                </div>
                            </div>

                            <!-- Materials List -->
                            <div v-if="serviceRequest.quote_materials" class="materials-section">
                                <h5>Materials Required:</h5>
                                <div class="materials-list">
                                    <div v-for="material in serviceRequest.quote_materials" :key="material.name" class="material-item">
                                        <span class="material-name">{{ material.name }}</span>
                                        <span class="material-qty">Qty: {{ material.quantity }}</span>
                                        <span class="material-price">KSH {{ formatCurrency(material.unit_price) }}</span>
                                        <span class="material-total">KSH {{ formatCurrency(material.quantity * material.unit_price) }}</span>
                                    </div>
                                </div>
                                <div v-if="serviceRequest.quote_materials_file_path" class="mt-4" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px dashed #e5e7eb;">
                                    <a :href="`/storage/${serviceRequest.quote_materials_file_path}`" target="_blank" class="btn btn-primary" style="display: flex; align-items: center; justify-content: center; gap: 0.75rem; text-decoration: none; padding: 0.75rem 1.5rem; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);">
                                        <i class="fas fa-file-download" style="font-size: 1.1rem;"></i> Download Detailed Materials List
                                    </a>
                                </div>
                            </div>

                            <!-- Cost Breakdown -->
                            <div class="cost-breakdown">
                                <div class="cost-line">
                                    <span>Materials Total:</span>
                                    <span>KSH {{ formatCurrency(getMaterialsTotal()) }}</span>
                                </div>
                                <div v-if="Number(serviceRequest.quote_transport_cost) > 0" class="cost-line">
                                    <span>Transport:</span>
                                    <span>KSH {{ formatCurrency(serviceRequest.quote_transport_cost) }}</span>
                                </div>
                                <div class="cost-line">
                                    <span>Labor Cost:</span>
                                    <span>KSH {{ formatCurrency(serviceRequest.quote_labor_cost || 0) }}</span>
                                </div>
                                <div class="cost-line total">
                                    <span>Total Amount:</span>
                                    <span>KSH {{ formatCurrency(serviceRequest.quote_amount) }}</span>
                                </div>
                                <div v-if="Number(serviceRequest.quote_down_payment) > 0" class="cost-line" style="border-top: 1px dashed #d1d5db; margin-top: 6px; padding-top: 8px; color: #92400E; font-weight: 600;">
                                    <span>Required Down Payment:</span>
                                    <span>KSH {{ formatCurrency(serviceRequest.quote_down_payment) }}</span>
                                </div>
                            </div>

                            <div v-if="serviceRequest.quote_notes" class="quote-notes">
                                <h5>Additional Information:</h5>
                                <p>{{ serviceRequest.quote_notes }}</p>
                            </div>

                            <!-- Billing Schedule (#21) -->
                            <div v-if="serviceRequest.billing_milestones && serviceRequest.billing_milestones.length" class="billing-schedule-section">
                                <h5><i class="fas fa-calendar-check"></i> Billing Schedule</h5>
                                <p style="font-size:0.85rem;color:var(--text-muted,#6b7280);margin-bottom:0.75rem;">
                                    Payment requests will be raised automatically when each progress milestone is validated.
                                </p>
                                <table class="billing-schedule-table">
                                    <thead>
                                        <tr>
                                            <th>Milestone</th>
                                            <th>Progress</th>
                                            <th>Amount (KSH)</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(ms, idx) in serviceRequest.billing_milestones" :key="idx">
                                            <td>{{ ms.label }}</td>
                                            <td>{{ ms.progress_pct }}%</td>
                                            <td>{{ formatCurrency(ms.amount) }}</td>
                                            <td>
                                                <span :class="ms.triggered ? 'billing-ms-badge triggered' : 'billing-ms-badge pending'">
                                                    {{ ms.triggered ? 'Invoiced' : 'Pending' }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Approval Actions -->
                            <div class="quote-actions">
                                <button @click="approveQuote" class="btn btn-success" :disabled="processingQuote">
                                    <i class="fas fa-check"></i> {{ processingQuote ? 'Processing...' : 'Approve Quotation' }}
                                </button>
                                <button @click="showDeclineModal = true" class="btn btn-danger">
                                    <i class="fas fa-times"></i> Decline Quotation
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Approved RFQ -->
                    <div v-else-if="serviceRequest.rfq_status === 'approved'" class="rfq-approved">
                        <div class="success-message">
                            <i class="fas fa-check-circle"></i>
                            <p>Quotation approved! You will shortly receive a deposit payment request as per the approved quotation. A technician will be assigned once the deposit is confirmed.</p>
                            <div class="approved-amount">
                                <span>Approved Amount: <strong>KSH {{ formatCurrency(serviceRequest.quote_amount) }}</strong></span>
                            </div>
                        </div>

                        <!-- Billing Schedule (approved view) -->
                        <div v-if="serviceRequest.billing_milestones && serviceRequest.billing_milestones.length" class="billing-schedule-section">
                            <h5><i class="fas fa-calendar-check"></i> Billing Schedule</h5>
                            <table class="billing-schedule-table">
                                <thead>
                                    <tr>
                                        <th>Milestone</th>
                                        <th>Progress</th>
                                        <th>Amount (KSH)</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(ms, idx) in serviceRequest.billing_milestones" :key="idx">
                                        <td>{{ ms.label }}</td>
                                        <td>{{ ms.progress_pct }}%</td>
                                        <td>{{ formatCurrency(ms.amount) }}</td>
                                        <td>
                                            <span :class="ms.triggered ? 'billing-ms-badge triggered' : 'billing-ms-badge pending'">
                                                {{ ms.triggered ? 'Invoiced' : 'Pending' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Payment Request Section -->
                        <div v-if="pendingPaymentRequest" class="payment-request-section">
                            <div class="payment-request-header">
                                <i class="fas fa-credit-card"></i>
                                <h4>Payment Required</h4>
                            </div>
                            <div class="payment-request-details">
                                <div class="payment-info-row">
                                    <span>Payment Reference:</span>
                                    <strong>{{ pendingPaymentRequest.payment_request_id }}</strong>
                                </div>
                                <div class="payment-info-row">
                                    <span>Percentage:</span>
                                    <strong>{{ pendingPaymentRequest.percentage }}%</strong>
                                </div>
                                <div class="payment-info-row highlight">
                                    <span>Amount Due:</span>
                                    <strong>KSH {{ formatCurrency(pendingPaymentRequest.amount) }}</strong>
                                </div>
                                <div v-if="pendingPaymentRequest.notes" class="payment-notes">
                                    <span>Notes:</span>
                                    <p>{{ pendingPaymentRequest.notes }}</p>
                                </div>
                            </div>

                            <!-- Payment Method Selection -->
                            <div class="payment-methods">
                                <h5>Select Payment Method:</h5>
                                <div class="payment-method-options">
                                    <button
                                        @click="selectPaymentMethod('mpesa')"
                                        :class="['payment-method-btn', { active: selectedPaymentMethod === 'mpesa' }]"
                                    >
                                        <i class="fas fa-mobile-alt"></i>
                                        <span>M-Pesa</span>
                                    </button>
                                    <button
                                        @click="selectPaymentMethod('cheque')"
                                        :class="['payment-method-btn', { active: selectedPaymentMethod === 'cheque' }]"
                                    >
                                        <i class="fas fa-money-check"></i>
                                        <span>Cheque</span>
                                    </button>
                                    <button
                                        @click="selectPaymentMethod('cash')"
                                        :class="['payment-method-btn', { active: selectedPaymentMethod === 'cash' }]"
                                    >
                                        <i class="fas fa-coins"></i>
                                        <span>Cash</span>
                                    </button>
                                    <button
                                        @click="selectPaymentMethod('bank_deposit')"
                                        :class="['payment-method-btn', { active: selectedPaymentMethod === 'bank_deposit' }]"
                                    >
                                        <i class="fas fa-university"></i>
                                        <span>Bank Deposit</span>
                                    </button>
                                </div>

                                <!-- M-Pesa Payment Form -->
                                <div v-if="selectedPaymentMethod === 'mpesa'" class="payment-form mpesa-form">
                                    <div class="form-group">
                                        <label><i class="fas fa-phone"></i> M-Pesa Phone Number:</label>
                                        <input
                                            v-model="mpesaForm.phone_number"
                                            type="tel"
                                            class="form-control"
                                            placeholder="e.g., 0712345678"
                                        >
                                        <small>Enter the phone number registered with M-Pesa</small>
                                    </div>
                                    <button
                                        @click="initiateMpesaPayment"
                                        class="btn btn-success btn-block"
                                        :disabled="!mpesaForm.phone_number || processingPayment"
                                    >
                                        <i class="fas fa-paper-plane"></i>
                                        {{ processingPayment ? 'Processing...' : 'Pay with M-Pesa' }}
                                    </button>
                                    <p class="payment-instruction">
                                        You will receive a payment prompt on your phone. Enter your M-Pesa PIN to complete the payment.
                                    </p>
                                </div>

                                <!-- Cheque Payment Form -->
                                <div v-if="selectedPaymentMethod === 'cheque'" class="payment-form cheque-form">
                                    <div class="form-group">
                                        <label><i class="fas fa-hashtag"></i> Cheque Number:</label>
                                        <input
                                            v-model="chequeForm.cheque_number"
                                            type="text"
                                            class="form-control"
                                            placeholder="Enter cheque number"
                                        >
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-paperclip"></i> Proof of Payment <span class="required-badge">Required</span></label>
                                        <div class="evidence-upload-area" @click="chequeEvidenceFileInput.click()">
                                            <div v-if="!chequeForm.evidence" class="evidence-placeholder">
                                                <i class="fas fa-upload"></i>
                                                <span>Click to upload a photo or scan of the cheque</span>
                                                <small>JPG, PNG or PDF · Max 10 MB</small>
                                            </div>
                                            <div v-else class="evidence-selected">
                                                <i class="fas fa-check-circle" style="color:#10b981;"></i>
                                                <span>{{ chequeForm.evidence.name }}</span>
                                                <button type="button" class="remove-evidence-btn" @click.stop="chequeForm.evidence = null; chequeEvidenceFileInput.value = ''">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <input
                                            ref="chequeEvidenceFileInput"
                                            type="file"
                                            accept="image/jpeg,image/png,application/pdf"
                                            style="display:none"
                                            @change="handleChequeEvidenceFile"
                                        />
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-sticky-note"></i> Notes (Optional):</label>
                                        <textarea
                                            v-model="chequeForm.notes"
                                            class="form-control"
                                            rows="2"
                                            placeholder="Any additional information..."
                                        ></textarea>
                                    </div>
                                    <button
                                        @click="submitOfflinePayment('cheque')"
                                        class="btn btn-primary btn-block"
                                        :disabled="!chequeForm.cheque_number || !chequeForm.evidence || processingPayment"
                                    >
                                        <i class="fas fa-check"></i>
                                        {{ processingPayment ? 'Processing...' : 'Submit Cheque Details' }}
                                    </button>
                                    <p class="payment-instruction">
                                        Please submit the cheque to our office. Payment will be confirmed once the cheque is processed.
                                    </p>
                                </div>

                                <!-- Cash Payment Form -->
                                <div v-if="selectedPaymentMethod === 'cash'" class="payment-form cash-form">
                                    <div class="form-group">
                                        <label><i class="fas fa-sticky-note"></i> Notes (Optional):</label>
                                        <textarea
                                            v-model="cashForm.notes"
                                            class="form-control"
                                            rows="2"
                                            placeholder="Any additional information..."
                                        ></textarea>
                                    </div>
                                    <button
                                        @click="submitOfflinePayment('cash')"
                                        class="btn btn-primary btn-block"
                                        :disabled="processingPayment"
                                    >
                                        <i class="fas fa-check"></i>
                                        {{ processingPayment ? 'Processing...' : 'Record Cash Payment' }}
                                    </button>
                                    <p class="payment-instruction">
                                        Please pay the amount at our office. Payment will be confirmed by our team.
                                    </p>
                                </div>

                                <!-- Bank Deposit Payment Form -->
                                <div v-if="selectedPaymentMethod === 'bank_deposit'" class="payment-form bank-form">
                                    <div class="form-group">
                                        <label><i class="fas fa-hashtag"></i> Bank Reference / Transaction ID:</label>
                                        <input
                                            v-model="bankForm.bank_reference"
                                            type="text"
                                            class="form-control"
                                            placeholder="Enter bank reference number"
                                        >
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-file-upload"></i> Upload Evidence (Deposit Slip / Screenshot):</label>
                                        <input
                                            type="file"
                                            ref="evidenceFileInput"
                                            @change="handleEvidenceFile"
                                            accept=".jpg,.jpeg,.png,.pdf"
                                            class="form-control"
                                        >
                                        <small>Accepted formats: JPG, PNG, PDF (max 10MB)</small>
                                    </div>
                                    <div class="form-group">
                                        <label><i class="fas fa-sticky-note"></i> Notes (Optional):</label>
                                        <textarea
                                            v-model="bankForm.notes"
                                            class="form-control"
                                            rows="2"
                                            placeholder="Bank name, branch, or any other details..."
                                        ></textarea>
                                    </div>
                                    <button
                                        @click="submitBankDeposit"
                                        class="btn btn-primary btn-block"
                                        :disabled="!bankForm.bank_reference || !bankForm.evidence || processingPayment"
                                    >
                                        <i class="fas fa-upload"></i>
                                        {{ processingPayment ? 'Uploading...' : 'Submit Bank Deposit' }}
                                    </button>
                                    <p class="payment-instruction">
                                        Please upload proof of your bank deposit. Payment will be verified by our admin team.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Paid Payment Requests -->
                        <div v-if="paidPaymentRequests.length > 0" class="paid-payments-section">
                            <h4><i class="fas fa-check-circle"></i> Completed Payments</h4>
                            <div class="paid-payments-list">
                                <div v-for="payment in paidPaymentRequests" :key="payment.id" class="paid-payment-item">
                                    <div class="payment-detail">
                                        <span class="label">Reference:</span>
                                        <span>{{ payment.payment_request_id }}</span>
                                    </div>
                                    <div class="payment-detail">
                                        <span class="label">Amount:</span>
                                        <span>KSH {{ formatCurrency(payment.amount) }}</span>
                                    </div>
                                    <div class="payment-detail">
                                        <span class="label">Method:</span>
                                        <span class="method-badge">{{ payment.payment_method?.toUpperCase() }}</span>
                                    </div>
                                    <div class="payment-detail">
                                        <span class="label">Paid On:</span>
                                        <span>{{ formatDate(payment.paid_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Rejected RFQ -->
                    <div v-else-if="serviceRequest.rfq_status === 'rejected'" class="rfq-rejected">
                        <div class="error-message">
                            <i class="fas fa-times-circle"></i>
                            <p>Unfortunately, your service request has been declined.</p>
                            <div v-if="serviceRequest.rejection_reason" class="rejection-reason">
                                <h5>Reason:</h5>
                                <p>{{ serviceRequest.rejection_reason }}</p>
                            </div>
                            <div class="rejection-actions">
                                <Link href="/client/new-request" class="btn btn-primary">
                                    <i class="fas fa-plus"></i> Submit New Request
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Service Request Information</h3>
                        <span :class="['status', getStatusClass(serviceRequest.status)]">
                            {{ formatStatus(serviceRequest.status) }}
                        </span>
                    </div>

                    <div class="request-details-grid">
                        <div class="detail-item">
                            <h4><i class="fas fa-hashtag"></i> Request ID</h4>
                            <p>{{ serviceRequest.request_id }}</p>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-calendar"></i> Date Submitted</h4>
                            <p>{{ formatDate(serviceRequest.created_at) }}</p>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-tools"></i> Service Category</h4>
                            <p>{{ serviceRequest.service_category?.name || 'Not specified' }}</p>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-exclamation-triangle"></i> Urgency Level</h4>
                            <p>{{ formatUrgency(serviceRequest.urgency) }}</p>
                        </div>

                        <div class="detail-item">
                            <h4><i class="fas fa-map-marker-alt"></i> Location</h4>
                            <p>{{ serviceRequest.location }}</p>
                        </div>

                        <div class="detail-item" v-if="serviceRequest.technician">
                            <h4><i class="fas fa-user-hard-hat"></i> Assigned Technician</h4>
                            <p>{{ serviceRequest.technician.user?.name || 'Not assigned' }}</p>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h4><i class="fas fa-clipboard-list"></i> Description</h4>
                        <p class="description-text">{{ serviceRequest.description }}</p>
                    </div>
                </div>
            </section>

            <!-- Client Action Buttons -->
            <section class="panel-section" v-if="serviceRequest.status === 'assigned' || serviceRequest.status === 'in_progress'">
                <div class="panel-card full-width action-card">
                    <div class="card-header">
                        <h3>Actions Required</h3>
                    </div>

                    <!-- Confirm Technician Arrival -->
                    <div v-if="serviceRequest.status === 'assigned' && !serviceRequest.technician_arrived" class="action-section">
                        <div class="action-info">
                            <i class="fas fa-truck"></i>
                            <div>
                                <h4>Technician En Route</h4>
                                <p>{{ serviceRequest.technician?.user?.name }} has been assigned to your request. Please confirm when they arrive.</p>
                            </div>
                        </div>
                        <button @click="confirmArrival" class="btn btn-success" :disabled="processingAction">
                            <i class="fas fa-check"></i> {{ processingAction ? 'Processing...' : 'Confirm Technician Arrived' }}
                        </button>
                    </div>

                    <!-- Work In Progress Actions -->
                    <div v-if="serviceRequest.status === 'in_progress'" class="action-section">
                        <div class="action-info">
                            <i class="fas fa-hard-hat"></i>
                            <div>
                                <h4>Work In Progress</h4>
                                <p>{{ serviceRequest.technician?.user?.name }} is currently working on your request.</p>
                                <div class="progress-display">
                                    <div class="progress-bar-large">
                                        <div class="progress" :style="`width: ${displayProgressPct}%`"></div>
                                    </div>
                                    <span>
                                        {{ displayProgressPct }}% Complete
                                        <small v-if="hasUnvalidatedProgress" class="pending-validation-pill">
                                            <i class="fas fa-hourglass-half"></i> {{ latestSubmittedPct }}% reported, awaiting validation
                                        </small>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <button @click="confirmCompletion" class="btn btn-success" :disabled="processingAction">
                            <i class="fas fa-check-circle"></i> {{ processingAction ? 'Processing...' : 'Confirm Work Completed' }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- Validated Progress Reports (#21, #22) -->
            <section class="panel-section" v-if="validatedReports.length">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Site Progress Updates</h3>
                        <small style="color:var(--text-muted);">Reports validated by our project team</small>
                    </div>
                    <div class="progress-reports-list">
                        <article v-for="report in validatedReports" :key="report.id" class="progress-report-card">
                            <header class="prc-header">
                                <div>
                                    <div class="prc-pct">
                                        {{ report.validated_percent ?? report.percent_complete }}%
                                    </div>
                                    <div class="prc-meta">
                                        <strong>{{ formatDate(report.report_date) }}</strong>
                                        <span v-if="report.technician?.user?.name"> · {{ report.technician.user.name }}</span>
                                        <span class="prc-validated-at">Validated {{ formatDate(report.validated_at) }}</span>
                                    </div>
                                </div>
                                <div class="prc-progress-bar">
                                    <div :style="`width:${report.validated_percent ?? report.percent_complete}%`"></div>
                                </div>
                            </header>

                            <p v-if="report.client_visible_notes" class="prc-notes">{{ report.client_visible_notes }}</p>
                            <p v-else class="prc-notes prc-notes-muted">No notes provided.</p>

                            <ImageLightbox
                                v-if="(report.photos || []).length"
                                :images="(report.photos || []).map(p => ({ src: p.file_path, caption: p.caption }))"
                            />
                        </article>
                    </div>
                </div>
            </section>

            <section class="panel-section" v-if="serviceRequest.status !== 'pending'">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Progress Timeline</h3>
                    </div>

                    <div class="timeline">
                        <div class="timeline-item completed">
                            <div class="timeline-marker">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="timeline-content">
                                <h4>Request Submitted</h4>
                                <p>{{ formatDate(serviceRequest.created_at) }}</p>
                                <span class="timeline-description">Your service request has been received and is being reviewed.</span>
                            </div>
                        </div>

                        <div :class="['timeline-item', { completed: paymentReceived }]">
                            <div class="timeline-marker">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="timeline-content">
                                <h4>Payment Received</h4>
                                <p v-if="latestPaidAt">{{ formatDate(latestPaidAt) }}</p>
                                <span class="timeline-description">
                                    <template v-if="paymentReceived">
                                        {{ paidMethodLabel ? `Payment confirmed via ${paidMethodLabel}.` : 'Your payment has been received and validated.' }}
                                    </template>
                                    <template v-else>
                                        Waiting for deposit payment to be confirmed.
                                    </template>
                                </span>
                            </div>
                        </div>

                        <div :class="['timeline-item', { completed: ['assigned', 'in_progress', 'completed'].includes(serviceRequest.status) }]">
                            <div class="timeline-marker">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div class="timeline-content">
                                <h4>Technician Assigned</h4>
                                <p v-if="serviceRequest.assigned_at">{{ formatDate(serviceRequest.assigned_at) }}</p>
                                <span class="timeline-description">
                                    {{ serviceRequest.technician
                                        ? `Assigned to ${serviceRequest.technician.user?.name}`
                                        : 'Waiting for technician assignment' }}
                                </span>
                            </div>
                        </div>

                        <div :class="['timeline-item', { completed: ['in_progress', 'completed'].includes(serviceRequest.status) }]">
                            <div class="timeline-marker">
                                <i class="fas fa-play"></i>
                            </div>
                            <div class="timeline-content">
                                <h4>Work in Progress</h4>
                                <p v-if="serviceRequest.started_at">{{ formatDate(serviceRequest.started_at) }}</p>
                                <span class="timeline-description">Technician has started working on your request.</span>
                            </div>
                        </div>

                        <div :class="['timeline-item', { completed: serviceRequest.status === 'completed' }]">
                            <div class="timeline-marker">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="timeline-content">
                                <h4>Work Completed</h4>
                                <p v-if="serviceRequest.completed_at">{{ formatDate(serviceRequest.completed_at) }}</p>
                                <span class="timeline-description">Service has been completed successfully.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel-section" v-if="['completed', 'completed_pending_confirmation', 'closed'].includes(serviceRequest.status) && !serviceRequest.rating">
                <div class="panel-card">
                    <div class="card-header">
                        <h3>Provide Feedback</h3>
                    </div>

                    <form @submit.prevent="submitFeedback" class="feedback-form">
                        <div class="form-group">
                            <label>Rate this service (1-5 stars)</label>
                            <div
                                class="star-rating"
                                @mouseleave="hoverRating = 0"
                            >
                                <button
                                    type="button"
                                    v-for="star in 5"
                                    :key="star"
                                    :class="['star', { active: star <= (hoverRating || feedback.rating) }]"
                                    @click="feedback.rating = star"
                                    @mouseenter="hoverRating = star"
                                    :aria-label="`Rate ${star} of 5`"
                                >
                                    <i class="fas fa-star"></i>
                                </button>
                                <span class="rating-readout">{{ feedback.rating ? `${feedback.rating} / 5` : 'Tap a star to rate' }}</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="comments">Comments (Optional)</label>
                            <textarea id="comments" v-model="feedback.comments" rows="4" placeholder="Tell us about your experience..."></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="submittingFeedback">
                                {{ submittingFeedback ? 'Submitting...' : 'Submit Feedback' }}
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="panel-section" v-if="serviceRequest.rating">
                <div class="panel-card">
                    <div class="card-header">
                        <h3>Your Feedback</h3>
                    </div>

                    <div class="existing-feedback">
                        <div class="rating-display">
                            <span class="rating-label">Rating:</span>
                            <div class="stars">
                                <i v-for="star in 5" :key="star" :class="['fas fa-star', { filled: star <= serviceRequest.rating }]"></i>
                            </div>
                        </div>

                        <div v-if="serviceRequest.review" class="comments-display">
                            <h4>Your Comments:</h4>
                            <p>{{ serviceRequest.review }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Decline Quotation Modal -->
            <div v-if="showDeclineModal" class="modal-overlay">
                <div class="modal-content" @click.stop>
                    <div class="modal-header">
                        <h3>Decline Quotation</h3>
                        <button @click="showDeclineModal = false" class="modal-close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <div class="modal-body">
                        <p>Are you sure you want to decline this quotation? This action cannot be undone.</p>
                        <div class="form-group">
                            <label>Reason for declining (optional):</label>
                            <textarea v-model="declineReason" class="form-control" rows="3" placeholder="Please provide a reason..."></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button @click="showDeclineModal = false" class="btn btn-secondary">Cancel</button>
                        <button @click="declineQuote" class="btn btn-danger" :disabled="processingQuote">
                            {{ processingQuote ? 'Processing...' : 'Decline Quotation' }}
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { reactive, ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import ClientSidebar from '../../Components/ClientSidebar.vue'
import ClientBottomNav from '../../Components/ClientBottomNav.vue'
import ImageLightbox from '../../Components/ImageLightbox.vue'

const props = defineProps({
    serviceRequest: {
        type: Object,
        required: true
    }
})

const feedback = reactive({
    rating: 0,
    comments: ''
})

const submittingFeedback = ref(false)
const hoverRating = ref(0)

// #33 Progress bar — show the highest known progress, falling back to
// the most recent submitted (but not-yet-validated) report so the bar
// actually moves while the PM is reviewing.
const latestValidatedPct = computed(() => {
    const reports = props.serviceRequest.progress_reports || []
    const validated = reports
        .filter((r) => r.is_validated)
        .map((r) => Number(r.validated_percent ?? r.percent_complete) || 0)
    if (validated.length) return Math.max(...validated)
    return Number(props.serviceRequest.progress_percentage) || 0
})
const latestSubmittedPct = computed(() => {
    const reports = props.serviceRequest.progress_reports || []
    if (!reports.length) return 0
    const sorted = [...reports].sort((a, b) =>
        new Date(b.report_date || b.created_at) - new Date(a.report_date || a.created_at),
    )
    return Number(sorted[0].percent_complete) || 0
})
const displayProgressPct = computed(() =>
    Math.max(latestValidatedPct.value, latestSubmittedPct.value),
)
const hasUnvalidatedProgress = computed(() =>
    latestSubmittedPct.value > latestValidatedPct.value,
)

// Client-facing list: only validated reports, freshest first (#21, #22).
const validatedReports = computed(() => {
    const all = props.serviceRequest.progress_reports || []
    return all
        .filter(r => r.is_validated)
        .sort((a, b) => new Date(b.report_date || b.created_at) - new Date(a.report_date || a.created_at))
})
const processingQuote = ref(false)
const processingAction = ref(false)
const processingPayment = ref(false)
const showDeclineModal = ref(false)
const declineReason = ref('')

// Payment Received timeline stage (#10) — reuses the existing
// `paidPaymentRequests` computed defined later in this file, plus a
// `payments` fallback when the legacy table holds the receipt.
const paidPaymentsList = computed(() => {
    const list = props.serviceRequest.payments || []
    return list.filter((p) => p.status === 'completed' || p.status === 'paid')
})
const paymentReceived = computed(() => paidPaymentRequests.value.length > 0 || paidPaymentsList.value.length > 0)
const latestPaidAt = computed(() => {
    const candidates = [
        ...paidPaymentRequests.value.map((pr) => pr.paid_at),
        ...paidPaymentsList.value.map((p) => p.paid_at),
    ].filter(Boolean)
    if (!candidates.length) return null
    return candidates.sort().slice(-1)[0]
})
const paidMethodLabel = computed(() => {
    const methodMap = { mpesa: 'M-Pesa', cheque: 'Cheque', cash: 'Cash', bank_deposit: 'Bank Deposit' }
    const latest = paidPaymentRequests.value[paidPaymentRequests.value.length - 1]
        || paidPaymentsList.value[paidPaymentsList.value.length - 1]
    if (!latest) return null
    const m = latest.payment_method
        || (latest.mpesa_receipt_number ? 'mpesa' : null)
        || (latest.cheque_number ? 'cheque' : null)
        || (latest.bank_reference ? 'bank_deposit' : null)
    return m ? (methodMap[m] || m) : null
})
const selectedPaymentMethod = ref(null)

const mpesaForm = reactive({
    phone_number: ''
})

const chequeForm = reactive({
    cheque_number: '',
    notes: '',
    evidence: null
})

const chequeEvidenceFileInput = ref(null)

const handleChequeEvidenceFile = (e) => {
    chequeForm.evidence = e.target.files[0] || null
}

const cashForm = reactive({
    notes: ''
})

const bankForm = reactive({
    bank_reference: '',
    notes: '',
    evidence: null
})

const evidenceFileInput = ref(null)

const handleEvidenceFile = (e) => {
    bankForm.evidence = e.target.files[0] || null
}

// Computed properties for payment requests
const pendingPaymentRequest = computed(() => {
    return props.serviceRequest.payment_requests?.find(pr => pr.status === 'pending')
})

const paidPaymentRequests = computed(() => {
    return (props.serviceRequest.payment_requests || []).filter(pr => pr.status === 'paid' || pr.status === 'completed')
})

const formatStatus = (status) => {
    // For awaiting_payment, distinguish "request not yet sent" from
    // "request sent, awaiting payment" based on whether a pending
    // PaymentRequest exists for this service request (#13).
    if (status === 'awaiting_payment') {
        const hasPending = (props.serviceRequest.payment_requests || []).some(pr => pr.status === 'pending')
        return hasPending ? 'Awaiting Payment' : 'Awaiting Payment Request from TW'
    }
    const statusMap = {
        'pending': 'Pending Review',
        'assigned': 'Technician Assigned',
        'in_progress': 'Work in Progress',
        'completed': 'Completed',
        'cancelled': 'Cancelled'
    }
    return statusMap[status] || status
}

const formatRFQStatus = (status) => {
    const statusMap = {
        'pending': 'Awaiting Review',
        'quoted': 'Quotation Received',
        'approved': 'Quotation Approved',
        'rejected': 'Request Declined'
    }
    return statusMap[status] || status
}

const getRFQStatusClass = (status) => {
    switch (status) {
        case 'pending':
            return 'review'
        case 'quoted':
            return 'quoted'
        case 'approved':
            return 'approved'
        case 'rejected':
            return 'review'
        default:
            return 'review'
    }
}

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-KE').format(amount || 0)
}

const getMaterialsTotal = () => {
    if (!props.serviceRequest.quote_materials) return 0
    return props.serviceRequest.quote_materials.reduce((total, material) => {
        return total + (material.quantity * material.unit_price)
    }, 0)
}

const approveQuote = async () => {
    processingQuote.value = true
    try {
        // Send the revision number the client is currently viewing so the
        // server can reject the action if a newer revision has been issued
        // after this page was rendered (#19 — superseded quote guard).
        const seenRevision = Number(props.serviceRequest.quote_revision_count) || 0

        const response = await fetch(`/client/rfq/${props.serviceRequest.id}/approve`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({ seen_revision: seenRevision }),
        })

        // Parse defensively — when Laravel returns HTML (CSRF expired / 419 / 500
        // error page) `response.json()` would throw and the user only saw a
        // generic "Failed to approve quotation" alert with no clue.
        let data = {}
        const raw = await response.text()
        try {
            data = raw ? JSON.parse(raw) : {}
        } catch (_e) {
            // Non-JSON body — likely Laravel error page or 419 CSRF
        }

        if (!response.ok) {
            if (response.status === 419) {
                throw new Error('Your session has expired. Please refresh the page and try again.')
            }
            const detail = data?.error || data?.message || `Server returned HTTP ${response.status}.`
            throw new Error(detail)
        }

        // Reload the page to show updated status
        window.location.reload()
    } catch (error) {
        console.error('Approval error:', error)
        alert(error.message || 'Error approving quotation. Please try again.')
    } finally {
        processingQuote.value = false
    }
}

const declineQuote = async () => {
    processingQuote.value = true
    try {
        await fetch(`/client/rfq/${props.serviceRequest.id}/decline`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                reason: declineReason.value
            })
        })

        showDeclineModal.value = false
        window.location.reload()
    } catch (error) {
        console.error('Decline error:', error)
        alert('Error declining quotation. Please try again.')
    } finally {
        processingQuote.value = false
    }
}

const confirmArrival = async () => {
    processingAction.value = true
    try {
        const response = await fetch(`/client/service-request/${props.serviceRequest.id}/confirm-arrival`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                'Accept': 'application/json'
            }
        })

        const data = await response.json()

        if (!response.ok) {
            throw new Error(data.error || 'Failed to confirm arrival')
        }

        window.location.reload()
    } catch (error) {
        console.error('Confirm arrival error:', error)
        alert(error.message || 'Error confirming arrival. Please try again.')
    } finally {
        processingAction.value = false
    }
}

const confirmCompletion = async () => {
    if (!confirm('Are you sure the work has been completed to your satisfaction?')) {
        return
    }

    processingAction.value = true
    try {
        const response = await fetch(`/client/service-request/${props.serviceRequest.id}/confirm-completion`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                'Accept': 'application/json'
            }
        })

        const data = await response.json()

        if (!response.ok) {
            throw new Error(data.error || 'Failed to confirm completion')
        }

        window.location.reload()
    } catch (error) {
        console.error('Confirm completion error:', error)
        alert(error.message || 'Error confirming completion. Please try again.')
    } finally {
        processingAction.value = false
    }
}

const formatUrgency = (urgency) => {
    const urgencyMap = {
        'low': 'Low (within 48 hrs)',
        'medium': 'Medium (within 24 hrs)',
        'high': 'High (within 12 hrs)'
    }
    return urgencyMap[urgency] || urgency
}

const getStatusClass = (status) => {
    switch (status) {
        case 'pending':
            return 'review'
        case 'assigned':
        case 'in_progress':
            return 'approved'
        case 'completed':
            return 'completed'
        case 'cancelled':
            return 'review'
        default:
            return 'review'
    }
}

const formatDate = (date) => {
    if (!date) return 'Not yet'
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

const submitFeedback = () => {
    if (feedback.rating === 0) {
        alert('Please provide a rating before submitting.')
        return
    }

    submittingFeedback.value = true

    router.post(
        `/client/service-request/${props.serviceRequest.id}/rate`,
        { rating: feedback.rating, comments: feedback.comments },
        {
            onSuccess: () => { submittingFeedback.value = false },
            onError:  () => {
                alert('There was an error submitting your feedback. Please try again.')
                submittingFeedback.value = false
            },
        }
    )
}

// Payment Methods
const selectPaymentMethod = (method) => {
    selectedPaymentMethod.value = method
}

const initiateMpesaPayment = async () => {
    if (!pendingPaymentRequest.value || !mpesaForm.phone_number) return

    processingPayment.value = true

    try {
        const response = await axios.post(`/client/payments/${pendingPaymentRequest.value.id}/mpesa`, {
            phone_number: mpesaForm.phone_number
        })

        const data = response.data

        if (data.success) {
            alert(data.message || 'Payment request sent! Please check your phone and enter your M-Pesa PIN.')
            
            // Start polling for payment status
            pollPaymentStatus()
        } else {
            throw new Error(data.message || 'Payment initiation failed')
        }
    } catch (error) {
        console.error('M-Pesa payment error:', error)
        const message = error.response?.data?.error || error.response?.data?.message || error.message || 'Error initiating payment. Please try again.'
        alert(message)
    } finally {
        processingPayment.value = false
    }
}

const pollPaymentStatus = async () => {
    if (!pendingPaymentRequest.value) return

    let attempts = 0
    const maxAttempts = 12 // 1 minute max (5 seconds * 12)

    const checkStatus = async () => {
        try {
            const response = await fetch(`/client/payments/${pendingPaymentRequest.value.id}/status`, {
                headers: {
                    'Accept': 'application/json'
                }
            })

            const data = await response.json()

            if (data.status === 'paid') {
                alert('Payment completed successfully!')
                window.location.reload()
                return
            }

            attempts++
            if (attempts < maxAttempts) {
                setTimeout(checkStatus, 5000) // Check every 5 seconds
            }
        } catch (error) {
            console.error('Status check error:', error)
        }
    }

    setTimeout(checkStatus, 5000) // Start checking after 5 seconds
}

const submitBankDeposit = async () => {
    if (!pendingPaymentRequest.value) return
    if (!bankForm.bank_reference || !bankForm.evidence) {
        alert('Please provide bank reference and upload evidence.')
        return
    }

    processingPayment.value = true

    try {
        const formData = new FormData()
        formData.append('payment_method', 'bank_deposit')
        formData.append('bank_reference', bankForm.bank_reference)
        formData.append('evidence', bankForm.evidence)
        if (bankForm.notes) formData.append('notes', bankForm.notes)

        const response = await axios.post(
            `/client/payments/${pendingPaymentRequest.value.id}/offline`,
            formData,
            { headers: { 'Content-Type': 'multipart/form-data' } }
        )

        alert(response.data.message || 'Bank deposit recorded successfully!')
        window.location.reload()
    } catch (error) {
        console.error('Bank deposit error:', error)
        const message = error.response?.data?.error || error.response?.data?.message || error.message || 'Error recording payment.'
        alert(message)
    } finally {
        processingPayment.value = false
    }
}

const submitOfflinePayment = async (method) => {
    if (!pendingPaymentRequest.value) return

    processingPayment.value = true

    try {
        const body = {
            payment_method: method
        }

        if (method === 'cheque') {
            if (!chequeForm.cheque_number) {
                alert('Please enter the cheque number')
                return
            }
            if (!chequeForm.evidence) {
                alert('Please upload proof of payment')
                return
            }
            const formData = new FormData()
            formData.append('payment_method', 'cheque')
            formData.append('cheque_number', chequeForm.cheque_number)
            formData.append('evidence', chequeForm.evidence)
            if (chequeForm.notes) formData.append('notes', chequeForm.notes)
            const response = await axios.post(`/client/payments/${pendingPaymentRequest.value.id}/offline`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            })
            const data = response.data
            alert(data.message || 'Payment recorded successfully!')
            window.location.reload()
            return
        } else {
            body.notes = cashForm.notes
        }

        const response = await axios.post(`/client/payments/${pendingPaymentRequest.value.id}/offline`, body)
        const data = response.data

        alert(data.message || 'Payment recorded successfully!')
        window.location.reload()
    } catch (error) {
        console.error('Offline payment error:', error)
        const message = error.response?.data?.error || error.response?.data?.message || error.message || 'Error recording payment. Please try again.'
        alert(message)
    } finally {
        processingPayment.value = false
    }
}

defineOptions({
    layout: null
})
</script>

<style>

/* RFQ Status Styles */
.rfq-status-card {
    border-left: 4px solid var(--primary-color);
    margin-bottom: 1.5rem;
}

.rfq-pending .info-message,
.rfq-approved .success-message {
    display: flex;
    align-items: center;
    padding: 1rem;
    background: #F0F9FF;
    border: 1px solid #BAE6FD;
    border-radius: 8px;
    margin: 1rem 0;
}

.rfq-pending .info-message i {
    color: #0284C7;
    font-size: 1.5rem;
    margin-right: 1rem;
}

.rfq-approved .success-message {
    background: #F0FDF4;
    border-color: #BBF7D0;
}

.rfq-approved .success-message i {
    color: #16A34A;
    font-size: 1.5rem;
    margin-right: 1rem;
}

.rfq-rejected .error-message {
    display: flex;
    flex-direction: column;
    padding: 1rem;
    background: #FEF2F2;
    border: 1px solid #FECACA;
    border-radius: 8px;
    margin: 1rem 0;
}

.rfq-rejected .error-message i {
    color: #DC2626;
    font-size: 1.5rem;
    margin-bottom: 0.5rem;
}

.quotation-details {
    padding: 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: #FAFAFA;
    margin: 1rem 0;
}

.quote-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--primary-color);
}

.quote-header h4 {
    color: var(--primary-color);
    margin: 0;
}

.quote-total .amount {
    font-size: 1.5rem;
    font-weight: bold;
    color: var(--success-color);
}

.materials-section {
    margin: 1.5rem 0;
}

.materials-section h5 {
    color: var(--text-color);
    margin-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
    padding-bottom: 0.5rem;
}

.materials-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.material-item {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr;
    gap: 1rem;
    padding: 0.75rem;
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    align-items: center;
}

.material-name {
    font-weight: 500;
    color: var(--text-color);
}

.material-qty,
.material-price {
    color: var(--text-muted);
    text-align: right;
}

.material-total {
    font-weight: 600;
    color: var(--text-color);
    text-align: right;
}

.cost-breakdown {
    background: white;
    padding: 1rem;
    border-radius: 8px;
    margin: 1.5rem 0;
    border: 1px solid var(--border-color);
}

.cost-line {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #E5E7EB;
}

.cost-line:last-child {
    border-bottom: none;
}

.cost-line.total {
    font-weight: bold;
    font-size: 1.1rem;
    color: var(--primary-color);
    border-top: 2px solid var(--primary-color);
    margin-top: 0.5rem;
    padding-top: 1rem;
}

.quote-notes {
    background: #F8FAFC;
    padding: 1rem;
    border-radius: 8px;
    border-left: 4px solid var(--info-color);
    margin: 1rem 0;
}

.quote-notes h5 {
    color: var(--info-color);
    margin-bottom: 0.5rem;
}

.quote-actions {
    display: flex;
    gap: 1rem;
    justify-content: center;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

.approved-amount {
    margin-top: 1rem;
    padding: 1rem;
    background: white;
    border-radius: 8px;
    text-align: center;
}

.rejection-reason {
    background: white;
    padding: 1rem;
    border-radius: 8px;
    margin: 1rem 0;
    border-left: 4px solid #DC2626;
}

.rejection-reason h5 {
    color: #DC2626;
    margin-bottom: 0.5rem;
}

.rejection-actions {
    text-align: center;
    margin-top: 1rem;
}

/* Payment Request Section Styles */
.payment-request-section {
    background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border: 2px solid #F59E0B;
    border-radius: 12px;
    padding: 1.5rem;
    margin-top: 1.5rem;
}

.payment-request-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #F59E0B;
}

.payment-request-header i {
    font-size: 1.5rem;
    color: #D97706;
}

.payment-request-header h4 {
    margin: 0;
    color: #92400E;
    font-size: 1.25rem;
}

.payment-request-details {
    background: white;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1.5rem;
}

.payment-info-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid #E5E7EB;
}

.payment-info-row:last-child {
    border-bottom: none;
}

.payment-info-row.highlight {
    background: #FEF3C7;
    margin: 0.5rem -1rem;
    padding: 0.75rem 1rem;
    border-radius: 6px;
    font-size: 1.1rem;
}

.payment-info-row.highlight strong {
    color: #D97706;
}

.payment-notes {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px dashed #E5E7EB;
}

.payment-notes span {
    font-weight: 500;
    color: var(--text-muted);
    display: block;
    margin-bottom: 0.25rem;
}

.payment-methods h5 {
    margin: 0 0 1rem 0;
    color: #92400E;
}

.payment-method-options {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.payment-method-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1.25rem;
    border: 2px solid #E5E7EB;
    border-radius: 12px;
    background: white;
    cursor: pointer;
    transition: all 0.2s ease;
}

.payment-method-btn:hover {
    border-color: var(--primary-color);
    background: #F0F9FF;
}

.payment-method-btn.active {
    border-color: var(--primary-color);
    background: #DBEAFE;
}

.payment-method-btn i {
    font-size: 2rem;
    color: var(--primary-color);
}

.payment-method-btn span {
    font-weight: 600;
    color: var(--text-color);
}

.payment-form {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    border: 1px solid #E5E7EB;
}

.payment-form .form-group {
    margin-bottom: 1rem;
}

.payment-form label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 0.5rem;
}

.payment-form small {
    display: block;
    margin-top: 0.25rem;
    color: var(--text-muted);
    font-size: 0.85rem;
}

.payment-form .btn-block {
    width: 100%;
    padding: 1rem;
    font-size: 1rem;
    font-weight: 600;
}

.required-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.15rem 0.5rem;
    background: #fee2e2;
    color: #b91c1c;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-left: 0.25rem;
}

.evidence-upload-area {
    border: 2px dashed #d1d5db;
    border-radius: 10px;
    padding: 1.25rem;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    background: #f9fafb;
}

.evidence-upload-area:hover {
    border-color: var(--primary-color, #0f6c8f);
    background: #f0f9ff;
}

.evidence-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.35rem;
    color: #6b7280;
    text-align: center;
}

.evidence-placeholder i {
    font-size: 1.5rem;
    color: #9ca3af;
}

.evidence-placeholder span {
    font-size: 0.9rem;
    font-weight: 500;
}

.evidence-placeholder small {
    font-size: 0.78rem;
    color: #9ca3af;
}

.evidence-selected {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.9rem;
    color: #065f46;
    font-weight: 500;
}

.evidence-selected span {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.remove-evidence-btn {
    background: none;
    border: none;
    color: #dc2626;
    cursor: pointer;
    padding: 0.2rem;
    border-radius: 4px;
    line-height: 1;
}

.remove-evidence-btn:hover {
    background: #fee2e2;
}

.payment-instruction {
    margin-top: 1rem;
    padding: 1rem;
    background: #F0F9FF;
    border-radius: 8px;
    color: #0369A1;
    font-size: 0.9rem;
    text-align: center;
    border-left: 4px solid var(--primary-color);
}

.paid-payments-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 2px solid #E5E7EB;
}

.paid-payments-section h4 {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--success-color);
    margin-bottom: 1rem;
}

.paid-payments-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.paid-payment-item {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    background: #F0FDF4;
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid #BBF7D0;
}

.paid-payment-item .payment-detail {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.paid-payment-item .label {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.paid-payment-item .method-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    background: var(--success-color);
    color: white;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status.quoted {
    background: #DBEAFE;
    color: #1E40AF;
}

/* Modal Styles */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    margin: 0;
    color: var(--text-color);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.2rem;
    color: var(--text-muted);
    cursor: pointer;
    padding: 0.25rem;
}

.modal-close:hover {
    color: var(--text-color);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
}

/* Responsive design */
@media (max-width: 768px) {
    .quote-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }

    .material-item {
        grid-template-columns: 1fr;
        gap: 0.25rem;
        text-align: left;
    }

    .quote-actions {
        flex-direction: column;
    }

    .modal-content {
        width: 95%;
        margin: 1rem;
    }
}

/* Action Card Styles */
.action-card {
    border-left: 4px solid var(--success-color);
}

.action-section {
    padding: 1.5rem;
    background: linear-gradient(135deg, #F0FDF4 0%, #DCFCE7 100%);
    border-radius: 8px;
    margin: 1rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.action-info {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.action-info > i {
    font-size: 2rem;
    color: var(--success-color);
    margin-top: 0.25rem;
}

.action-info h4 {
    margin: 0 0 0.5rem 0;
    color: var(--text-color);
}

.action-info p {
    margin: 0;
    color: var(--text-muted);
}

.progress-display {
    margin-top: 1rem;
}

.progress-bar-large {
    height: 12px;
    background: #E5E7EB;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.progress-bar-large .progress {
    height: 100%;
    background: linear-gradient(90deg, var(--success-color) 0%, #22C55E 100%);
    border-radius: 6px;
    transition: width 0.3s ease;
}

.progress-display span {
    font-weight: 600;
    color: var(--success-color);
}

.action-section .btn {
    align-self: flex-start;
}

@media (max-width: 1024px) {
    .payment-method-options {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .action-info {
        flex-direction: column;
        text-align: center;
    }

    .action-section .btn {
        align-self: stretch;
    }

    .payment-method-options {
        grid-template-columns: 1fr;
    }

    .paid-payment-item {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 480px) {
    .paid-payment-item {
        grid-template-columns: 1fr;
    }
}

/* Pending-validation badge on progress (#33) */
.pending-validation-pill {
    display: inline-block;
    margin-left: 0.5rem;
    background: #FEF3C7;
    color: #92400E;
    border-radius: 999px;
    padding: 1px 9px;
    font-size: 0.72rem;
    font-weight: 600;
}

/* Feedback star rating (#10) */
.star-rating {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.45rem;
    margin-top: 0.3rem;
}
.star-rating .star {
    background: transparent;
    border: 1px solid transparent;
    padding: 6px;
    cursor: pointer;
    color: #CBD5E1;
    font-size: 1.6rem;
    line-height: 1;
    border-radius: 8px;
    transition: color 0.12s ease, transform 0.12s ease, background 0.12s ease;
}
.star-rating .star:hover,
.star-rating .star:focus-visible {
    background: #FFFBEB;
    transform: scale(1.08);
    outline: none;
}
.star-rating .star.active {
    color: #F59E0B;
}
.star-rating .star.active i { filter: drop-shadow(0 1px 2px rgba(245, 158, 11, 0.4)); }
.rating-readout {
    margin-left: 0.5rem;
    font-size: 0.85rem;
    color: #64748B;
    font-weight: 500;
}

/* Read-only stars elsewhere on the page */
.rating-display .stars .fa-star { color: #CBD5E1; font-size: 1.05rem; }
.rating-display .stars .fa-star.filled { color: #F59E0B; }

/* Billing schedule (#21) */
.billing-schedule-section { margin: 1.25rem 0; }
.billing-schedule-section h5 { font-size: 0.95rem; font-weight: 600; margin-bottom: 0.5rem; }
.billing-schedule-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
.billing-schedule-table th, .billing-schedule-table td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
.billing-schedule-table th { background: #f9fafb; font-weight: 600; color: #6b7280; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; }
.billing-ms-badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
.billing-ms-badge.pending { background: #fef9c3; color: #854d0e; }
.billing-ms-badge.triggered { background: #dcfce7; color: #166534; }

/* Progress reports for client (#21, #22) */
.progress-reports-list { display: flex; flex-direction: column; gap: 1rem; padding: 0 1rem 1rem; }
.progress-report-card { border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem 1.25rem; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
.prc-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: .75rem; }
.prc-pct { font-size: 1.4rem; font-weight: 700; color: #059669; }
.prc-meta { font-size: .85rem; color: #6b7280; display: flex; flex-direction: column; gap: .15rem; }
.prc-meta strong { color: #111827; }
.prc-validated-at { font-size: .75rem; opacity: .75; }
.prc-progress-bar { width: 160px; height: 8px; background: #e5e7eb; border-radius: 999px; overflow: hidden; }
.prc-progress-bar > div { height: 100%; background: linear-gradient(90deg,#10b981,#059669); transition: width .3s; }
.prc-notes { white-space: pre-wrap; line-height: 1.5; color: #374151; margin: 0 0 .75rem; }
.prc-notes-muted { color: #9ca3af; font-style: italic; }
</style>

