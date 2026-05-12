<template>
    <PMLayout>
        <template #header>
            <div class="page-header-copy">
                <div>
                    <h1>RFQ Management</h1>
                    <p>Review assigned RFQs, send quotations, request payments, and move approved work into delivery.</p>
                </div>
            </div>
        </template>

        <section class="rfq-hero">
            <div class="hero-card hero-card-primary">
                <span class="hero-kicker">PM Queue</span>
                <h2>Keep quotes, approvals, and payment requests moving.</h2>
                <p>
                    This workspace highlights where each RFQ sits in the handoff from scoping to collection so you can act quickly.
                </p>

                <div class="hero-pills">
                    <span class="hero-pill">
                        <i class="fas fa-file-alt"></i>
                        {{ summary.total || 0 }} assigned RFQs
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-filter"></i>
                        {{ activeStatusLabel }}
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-search"></i>
                        {{ search ? `Searching "${search}"` : 'Full queue view' }}
                    </span>
                </div>
            </div>

            <form class="hero-card filter-card" @submit.prevent="applyFilters">
                <div>
                    <span class="hero-kicker">Filters</span>
                    <h3>Refine your work queue</h3>
                    <p>Search by reference, client, description, or location and narrow the list by stage.</p>
                </div>

                <div class="filter-grid">
                    <label class="filter-field">
                        <span>Status</span>
                        <select v-model="filterStatus" class="filter-input">
                            <option value="">All statuses</option>
                            <option value="awaiting_tech_availability">Awaiting Tech Availability</option>
                            <option value="awaiting_quote_generation">Needs Quote</option>
                            <option value="awaiting_quote_approval">Quote Sent</option>
                            <option value="awaiting_payment">Awaiting Payment</option>
                            <option value="ready_for_assignment">Ready for Assignment</option>
                        </select>
                    </label>

                    <label class="filter-field">
                        <span>Search</span>
                        <input
                            v-model="search"
                            type="text"
                            class="filter-input"
                            placeholder="Reference, client, description, location..."
                        >
                    </label>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm filter-button">
                        <i class="fas fa-filter"></i>
                        Apply filters
                    </button>
                    <button
                        v-if="filterStatus || search"
                        type="button"
                        class="btn btn-secondary btn-sm filter-button filter-button-secondary"
                        @click="clearFilters"
                    >
                        Clear
                    </button>
                </div>
            </form>
        </section>

        <section class="summary-grid">
            <article class="summary-card tone-slate">
                <div class="summary-topline">
                    <span class="summary-tag">Queue</span>
                    <i class="fas fa-layer-group"></i>
                </div>
                <span class="summary-label">Total RFQs</span>
                <strong class="summary-value">{{ summary.total || 0 }}</strong>
                <p class="summary-note">All RFQs currently assigned to you.</p>
            </article>

            <article class="summary-card tone-blue">
                <div class="summary-topline">
                    <span class="summary-tag">Quoting</span>
                    <i class="fas fa-calculator"></i>
                </div>
                <span class="summary-label">Needs Quote</span>
                <strong class="summary-value">{{ summary.awaiting_quote_generation || 0 }}</strong>
                <p class="summary-note">RFQs waiting for a formal quotation.</p>
            </article>

            <article class="summary-card tone-amber">
                <div class="summary-topline">
                    <span class="summary-tag">Client Response</span>
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <span class="summary-label">Awaiting Approval</span>
                <strong class="summary-value">{{ summary.awaiting_quote_approval || 0 }}</strong>
                <p class="summary-note">Quotes already sent and pending client action.</p>
            </article>

            <article class="summary-card tone-green">
                <div class="summary-topline">
                    <span class="summary-tag">Ready</span>
                    <i class="fas fa-user-check"></i>
                </div>
                <span class="summary-label">Ready for Assignment</span>
                <strong class="summary-value">{{ summary.ready_for_assignment || 0 }}</strong>
                <p class="summary-note">Approved and ready to move into execution.</p>
            </article>
        </section>

        <section class="panel-card queue-panel">
            <div class="section-heading">
                <div>
                    <p class="section-kicker">Assigned RFQs</p>
                    <h3>Current Work Queue</h3>
                </div>
                <span class="section-badge">{{ rfqs.total || rfqs.data?.length || 0 }} items</span>
            </div>

            <div v-if="rfqs.data?.length" class="desktop-table">
                <table class="queue-table">
                    <thead>
                        <tr>
                            <th>RFQ</th>
                            <th>Client</th>
                            <th>Status</th>
                            <th>Financials</th>
                            <th>Execution</th>
                            <th>Next Step</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="rfq in rfqs.data" :key="rfq.id">
                            <td>
                                <div class="entity-block">
                                    <strong>{{ rfq.job_reference || rfq.request_id }}</strong>
                                    <span>{{ rfq.request_id }}</span>
                                    <small>{{ rfq.service_category?.name || 'Uncategorised' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="entity-block compact">
                                    <strong>{{ rfq.user?.name || 'N/A' }}</strong>
                                    <span>{{ rfq.user?.email || 'No email available' }}</span>
                                    <small>{{ rfq.location || 'No location provided' }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="status-stack">
                                    <span :class="['status-badge', statusTone(rfq.status)]">{{ formatStatus(rfq.status) }}</span>
                                    <small>{{ formatUrgency(rfq.urgency) }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="entity-block compact">
                                    <strong>{{ quoteLabel(rfq) }}</strong>
                                    <span>{{ formatCurrency(rfq.quote_amount || rfq.latest_quotation?.grand_total || 0) }}</span>
                                    <small v-if="rfq.latest_quotation">
                                        Quote v{{ rfq.latest_quotation.version }} • {{ formatQuoteStatus(rfq.latest_quotation.status) }}
                                    </small>
                                    <small v-else>No quotation created yet</small>
                                </div>
                            </td>
                            <td>
                                <div class="entity-block compact">
                                    <strong>{{ rfq.technician?.user?.name || 'Not assigned' }}</strong>
                                    <span>{{ rfq.technician?.specialization || 'Awaiting assignment' }}</span>
                                    <small>{{ formatDate(rfq.created_at) }}</small>
                                </div>
                            </td>
                            <td>
                                <div class="action-cluster">
                                    <strong class="next-step-title">{{ nextStep(rfq).title }}</strong>
                                    <span class="action-note">{{ nextStep(rfq).note }}</span>

                                    <button
                                        v-if="nextStep(rfq).action === 'quote'"
                                        class="btn btn-primary btn-sm"
                                        @click="openQuoteModal(rfq)"
                                    >
                                        <i class="fas fa-calculator"></i>
                                        Create Quote
                                    </button>

                                    <button
                                        v-else-if="nextStep(rfq).action === 'revise'"
                                        class="btn btn-secondary btn-sm"
                                        @click="reviseQuote(rfq)"
                                    >
                                        <i class="fas fa-redo"></i>
                                        Revise Quote
                                    </button>

                                    <button
                                        v-else-if="nextStep(rfq).action === 'assign'"
                                        class="btn btn-primary btn-sm"
                                        @click="openAssignModal(rfq)"
                                    >
                                        <i class="fas fa-user-plus"></i>
                                        Assign Technician
                                    </button>

                                    <button
                                        v-else-if="nextStep(rfq).action === 'payment'"
                                        class="btn btn-sm btn-success"
                                        @click="requestPayment(rfq)"
                                    >
                                        <i class="fas fa-paper-plane"></i>
                                        Send Payment Request
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="rfqs.data?.length" class="mobile-card-list">
                <article v-for="rfq in rfqs.data" :key="`mobile-${rfq.id}`" class="rfq-card">
                    <div class="rfq-card-head">
                        <div>
                            <strong>{{ rfq.job_reference || rfq.request_id }}</strong>
                            <p>{{ rfq.user?.name || 'N/A' }} • {{ rfq.location || 'No location provided' }}</p>
                        </div>
                        <span :class="['status-badge', statusTone(rfq.status)]">{{ formatStatus(rfq.status) }}</span>
                    </div>

                    <p class="rfq-description">{{ rfq.description || 'No RFQ description provided.' }}</p>

                    <div class="rfq-metrics">
                        <div class="metric-item">
                            <span>Category</span>
                            <strong>{{ rfq.service_category?.name || 'Uncategorised' }}</strong>
                        </div>
                        <div class="metric-item">
                            <span>Quote</span>
                            <strong>{{ formatCurrency(rfq.quote_amount || rfq.latest_quotation?.grand_total || 0) }}</strong>
                        </div>
                        <div class="metric-item">
                            <span>Technician</span>
                            <strong>{{ rfq.technician?.user?.name || 'Not assigned' }}</strong>
                        </div>
                        <div class="metric-item">
                            <span>Created</span>
                            <strong>{{ formatDate(rfq.created_at) }}</strong>
                        </div>
                    </div>

                    <div class="action-cluster">
                        <strong class="next-step-title">{{ nextStep(rfq).title }}</strong>
                        <span class="action-note">{{ nextStep(rfq).note }}</span>

                        <button
                            v-if="nextStep(rfq).action === 'quote'"
                            class="btn btn-primary btn-sm"
                            @click="openQuoteModal(rfq)"
                        >
                            <i class="fas fa-calculator"></i>
                            Create Quote
                        </button>

                        <button
                            v-else-if="nextStep(rfq).action === 'revise'"
                            class="btn btn-secondary btn-sm"
                            @click="reviseQuote(rfq)"
                        >
                            <i class="fas fa-redo"></i>
                            Revise Quote
                        </button>

                        <button
                            v-else-if="nextStep(rfq).action === 'assign'"
                            class="btn btn-primary btn-sm"
                            @click="openAssignModal(rfq)"
                        >
                            <i class="fas fa-user-plus"></i>
                            Assign Technician
                        </button>

                        <button
                            v-else-if="nextStep(rfq).action === 'payment'"
                            class="btn btn-sm btn-success"
                            @click="requestPayment(rfq)"
                        >
                            <i class="fas fa-paper-plane"></i>
                            Payment Request
                        </button>
                    </div>
                </article>
            </div>

            <div v-if="!rfqs.data?.length" class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No RFQs match this view</h3>
                <p>Try clearing the filters or search term to bring back the full PM queue.</p>
            </div>

            <div class="pagination" v-if="rfqs.last_page > 1">
                <Link
                    v-for="link in rfqs.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    class="btn btn-sm"
                    :class="{ 'btn-primary': link.active, 'btn-secondary': !link.active }"
                    v-html="link.label"
                    :disabled="!link.url"
                />
            </div>
        </section>

        <div v-if="showQuoteModal" class="modal-overlay" @click.self="showQuoteModal = false">
            <div class="modal-content large">
                <div class="modal-header">
                    <div>
                        <h3>Create Quotation</h3>
                        <p class="modal-subtitle">{{ selectedRfq?.job_reference || selectedRfq?.request_id }} • {{ selectedRfq?.user?.name }}</p>
                    </div>
                    <button class="modal-close" @click="showQuoteModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="submitQuotation">
                        <div class="form-section">
                            <div class="section-heading compact-heading">
                                <div>
                                    <p class="section-kicker">Line Items</p>
                                    <h3>Quotation Builder</h3>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" @click="addLineItem">
                                    <i class="fas fa-plus"></i>
                                    Add Item
                                </button>
                            </div>

                            <div v-for="(item, index) in quoteForm.line_items" :key="index" class="line-item-card">
                                <div class="line-item-head">
                                    <strong>Item {{ index + 1 }}</strong>
                                    <button
                                        v-if="quoteForm.line_items.length > 1"
                                        type="button"
                                        class="btn btn-sm btn-danger-soft"
                                        @click="removeLineItem(index)"
                                    >
                                        <i class="fas fa-trash"></i>
                                        Remove
                                    </button>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Category</label>
                                        <select v-model="item.category" required>
                                            <option value="material">Material</option>
                                            <option value="labor">Labour</option>
                                            <option value="transport">Transport</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Description</label>
                                        <input v-model="item.description" type="text" required placeholder="Item description">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Quantity</label>
                                        <input v-model.number="item.quantity" type="number" step="0.01" min="0.01" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Unit</label>
                                        <input v-model="item.unit" type="text" required placeholder="pcs, kg, sqm...">
                                    </div>
                                    <div class="form-group">
                                        <label>Unit Price (KES)</label>
                                        <input v-model.number="item.unit_price" type="number" step="0.01" min="0" required>
                                    </div>
                                </div>

                                <div class="line-item-total">
                                    <span>Item total</span>
                                    <strong>{{ formatCurrency((item.quantity || 0) * (item.unit_price || 0)) }}</strong>
                                </div>
                            </div>
                        </div>

                        <div class="total-banner">
                            <span>Grand Total</span>
                            <strong>{{ formatCurrency(grandTotal) }}</strong>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Delivery Timeline</label>
                                <input v-model="quoteForm.delivery_timeline" type="text" placeholder="e.g. 2 weeks from start">
                            </div>
                            <div class="form-group">
                                <label>Valid Until</label>
                                <input v-model="quoteForm.valid_until" type="date">
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label>Notes</label>
                            <textarea v-model="quoteForm.notes" rows="3" placeholder="Additional notes for the client..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showQuoteModal = false">Cancel</button>
                    <button class="btn btn-primary" @click="submitQuotation" :disabled="submitting">
                        <i class="fas fa-paper-plane"></i>
                        {{ submitting ? 'Sending...' : 'Create & Send Quotation' }}
                    </button>
                </div>
            </div>
        </div>

        <div v-if="showAssignModal" class="modal-overlay" @click.self="showAssignModal = false">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h3>Assign Technician</h3>
                        <p class="modal-subtitle">{{ selectedRfq?.job_reference || selectedRfq?.request_id }} • {{ selectedRfq?.user?.name }}</p>
                    </div>
                    <button class="modal-close" @click="showAssignModal = false">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="submitAssignment">
                        <div class="form-group">
                            <label>Select Technician</label>
                            <select v-model="assignForm.technician_id" required>
                                <option value="">Choose...</option>
                                <option v-for="tech in technicians" :key="tech.id" :value="tech.id">
                                    {{ tech.user?.name }} — {{ tech.specialization }} ({{ tech.availability }})
                                </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Agreed Compensation (KES)</label>
                            <input v-model.number="assignForm.agreed_compensation" type="number" step="0.01" min="0" required>
                        </div>

                        <div class="form-group">
                            <label>Compensation Notes</label>
                            <textarea
                                v-model="assignForm.compensation_notes"
                                rows="2"
                                placeholder="Details about the agreed compensation..."
                            ></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Expected Start</label>
                                <input v-model="assignForm.expected_start" type="date" required>
                            </div>
                            <div class="form-group">
                                <label>Expected End</label>
                                <input v-model="assignForm.expected_end" type="date" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" @click="showAssignModal = false">Cancel</button>
                    <button class="btn btn-primary" @click="submitAssignment" :disabled="submitting">
                        <i class="fas fa-user-check"></i>
                        {{ submitting ? 'Assigning...' : 'Assign Technician' }}
                    </button>
                </div>
            </div>
        </div>
    </PMLayout>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PMLayout from '../../Layouts/PMLayout.vue'

const props = defineProps({
    rfqs: { type: Object, default: () => ({ data: [] }) },
    technicians: { type: Array, default: () => [] },
    statusSummary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

const summary = computed(() => props.statusSummary || {})
const filterStatus = ref(props.filters.status || '')
const search = ref(props.filters.search || '')
const showQuoteModal = ref(false)
const showAssignModal = ref(false)
const selectedRfq = ref(null)
const submitting = ref(false)

const quoteForm = ref({
    line_items: [{ category: 'material', description: '', quantity: 1, unit: 'pcs', unit_price: 0 }],
    delivery_timeline: '',
    valid_until: '',
    notes: '',
    send_immediately: true,
})

const assignForm = ref({
    technician_id: '',
    agreed_compensation: 0,
    compensation_notes: '',
    expected_start: '',
    expected_end: '',
})

const grandTotal = computed(() => (
    quoteForm.value.line_items.reduce((sum, item) => sum + (item.quantity || 0) * (item.unit_price || 0), 0)
))

const activeStatusLabel = computed(() => {
    const labels = {
        awaiting_tech_availability: 'Tech availability',
        awaiting_quote_generation: 'Needs quote',
        awaiting_quote_approval: 'Quote sent',
        awaiting_payment: 'Awaiting payment',
        ready_for_assignment: 'Ready for assignment',
    }

    return labels[filterStatus.value] || 'All queue stages'
})

const addLineItem = () => {
    quoteForm.value.line_items.push({ category: 'material', description: '', quantity: 1, unit: 'pcs', unit_price: 0 })
}

const removeLineItem = (index) => {
    quoteForm.value.line_items.splice(index, 1)
}

const openQuoteModal = (rfq) => {
    selectedRfq.value = rfq
    quoteForm.value = {
        line_items: [{ category: 'material', description: '', quantity: 1, unit: 'pcs', unit_price: 0 }],
        delivery_timeline: '',
        valid_until: '',
        notes: '',
        send_immediately: true,
    }
    showQuoteModal.value = true
}

const openAssignModal = (rfq) => {
    selectedRfq.value = rfq
    assignForm.value = {
        technician_id: '',
        agreed_compensation: 0,
        compensation_notes: '',
        expected_start: '',
        expected_end: '',
    }
    showAssignModal.value = true
}

const submitQuotation = () => {
    submitting.value = true
    router.post(`/pm/rfqs/${selectedRfq.value.id}/quotation`, quoteForm.value, {
        onSuccess: () => {
            showQuoteModal.value = false
            submitting.value = false
        },
        onError: () => {
            submitting.value = false
        },
    })
}

const submitAssignment = () => {
    submitting.value = true
    router.post(`/pm/jobs/${selectedRfq.value.id}/assign`, assignForm.value, {
        onSuccess: () => {
            showAssignModal.value = false
            submitting.value = false
        },
        onError: () => {
            submitting.value = false
        },
    })
}

const reviseQuote = (rfq) => {
    if (rfq.latest_quotation) {
        router.post(`/pm/quotations/${rfq.latest_quotation.id}/revise`)
    }
}

const requestPayment = (rfq) => {
    router.post(`/pm/jobs/${rfq.id}/payment-request`)
}

const applyFilters = () => {
    router.get('/pm/rfqs', {
        status: filterStatus.value || undefined,
        search: search.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const clearFilters = () => {
    filterStatus.value = ''
    search.value = ''
    applyFilters()
}

const formatStatus = (status) => {
    const labels = {
        awaiting_tech_availability: 'Tech Check',
        awaiting_quote_generation: 'Needs Quote',
        awaiting_quote_approval: 'Quote Sent',
        awaiting_payment: 'Awaiting Payment',
        ready_for_assignment: 'Ready',
        assigned: 'Assigned',
        in_progress: 'In Progress',
    }

    return labels[status] || status?.replace(/_/g, ' ') || 'Unknown'
}

const formatQuoteStatus = (status) => {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase()) : 'No quote'
}

const formatUrgency = (urgency) => {
    return urgency ? urgency.replace(/\b\w/g, (char) => char.toUpperCase()) : 'Normal priority'
}

const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency: 'KES',
        maximumFractionDigits: 0,
    }).format(Number(value || 0))
}

const formatDate = (value) => {
    if (!value) return 'No date'

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value))
}

const quoteLabel = (rfq) => {
    if (rfq.latest_quotation?.status === 'declined') return 'Revision needed'
    if (rfq.latest_quotation) return 'Quoted value'
    if (rfq.status === 'awaiting_quote_generation') return 'Quote pending'
    return 'Current value'
}

const nextStep = (rfq) => {
    if (rfq.latest_quotation?.status === 'declined') {
        return {
            title: 'Revise quotation',
            note: 'The latest quote was declined. Update pricing or scope and resend it.',
            action: 'revise',
        }
    }

    if (rfq.status === 'awaiting_tech_availability') {
        return {
            title: 'Confirm technician availability',
            note: 'Validate technician readiness and scope before preparing a quotation.',
            action: null,
        }
    }

    if (rfq.status === 'awaiting_quote_generation') {
        return {
            title: 'Create quotation',
            note: 'Prepare and send the first quotation for client review.',
            action: 'quote',
        }
    }

    if (rfq.status === 'awaiting_quote_approval') {
        return {
            title: 'Await client approval',
            note: 'The quotation is with the client. Follow up only if they need clarifications.',
            action: null,
        }
    }

    if (rfq.status === 'awaiting_payment' || rfq.rfq_status === 'approved') {
        return {
            title: 'Request payment',
            note: 'Collect the approved deposit or full payment before moving into delivery.',
            action: 'payment',
        }
    }

    if (rfq.status === 'ready_for_assignment') {
        return {
            title: 'Assign technician',
            note: 'The RFQ is approved and funded. Hand it off to the right technician next.',
            action: 'assign',
        }
    }

    if (rfq.status === 'assigned') {
        return {
            title: 'Track execution',
            note: 'The RFQ has moved into delivery. Monitor progress from the Jobs workspace.',
            action: null,
        }
    }

    if (rfq.status === 'in_progress') {
        return {
            title: 'Monitor progress',
            note: 'Execution is underway. Keep an eye on updates, blockers, and milestones.',
            action: null,
        }
    }

    return {
        title: 'Review RFQ',
        note: 'Check the current state and take the next operational action when needed.',
        action: null,
    }
}

const statusTone = (status) => {
    const map = {
        awaiting_quote_generation: 'tone-blue',
        awaiting_tech_availability: 'tone-amber',
        awaiting_quote_approval: 'tone-purple',
        awaiting_payment: 'tone-orange',
        ready_for_assignment: 'tone-green',
        assigned: 'tone-slate',
        in_progress: 'tone-slate',
    }

    return map[status] || 'tone-slate'
}

defineOptions({ layout: null })
</script>

<style scoped>
.page-header-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    max-width: 58ch;
}

.rfq-hero,
.summary-grid {
    display: grid;
    gap: 1rem;
    margin-bottom: 1.35rem;
}

.rfq-hero {
    grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
}

.hero-card,
.panel-card,
.summary-card {
    border-radius: 28px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
}

.hero-card {
    padding: 1.5rem 1.65rem;
}

.hero-card-primary {
    background:
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 35%),
        linear-gradient(135deg, #ffffff, #eff6ff);
}

.hero-kicker,
.summary-tag,
.section-kicker {
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #0284c7;
}

.hero-card h2,
.filter-card h3,
.section-heading h3 {
    margin: 0.55rem 0 0;
    color: #0f172a;
}

.hero-card p,
.filter-card p,
.summary-note,
.action-note,
.modal-subtitle,
.empty-state p {
    color: #64748b;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.3rem;
}

.hero-pill,
.section-badge,
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.72rem 0.95rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
    background: rgba(226, 232, 240, 0.8);
    color: #0f172a;
}

.hero-pill.muted,
.section-badge {
    background: #f8fafc;
    color: #475569;
}

.filter-card {
    background: #ffffff;
    display: grid;
    gap: 1rem;
}

.filter-grid {
    display: grid;
    gap: 0.85rem;
}

.filter-field {
    display: grid;
    gap: 0.38rem;
    color: #334155;
    font-size: 0.84rem;
    font-weight: 700;
}

.filter-input {
    width: 100%;
    border: 1px solid rgba(148, 163, 184, 0.32);
    border-radius: 16px;
    background: #f8fafc;
    padding: 0.82rem 0.95rem;
}

.filter-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.filter-button {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    border-radius: 999px;
    padding-inline: 1rem;
}

.filter-button-secondary {
    background: #e2e8f0;
    color: #0f172a;
}

.summary-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    padding: 1.25rem;
    background: #ffffff;
}

.summary-topline,
.section-heading,
.rfq-card-head,
.line-item-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.summary-topline i {
    color: #0f172a;
}

.summary-label {
    display: block;
    margin-top: 0.95rem;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.summary-value {
    display: block;
    margin-top: 0.3rem;
    color: #0f172a;
    font-size: 1.55rem;
}

.summary-note {
    margin: 0.7rem 0 0;
    line-height: 1.55;
}

.tone-slate {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.tone-blue {
    background: linear-gradient(180deg, #eff6ff, #dbeafe);
}

.tone-amber {
    background: linear-gradient(180deg, #fffbeb, #fef3c7);
}

.tone-green {
    background: linear-gradient(180deg, #ecfdf5, #dcfce7);
}

.queue-panel {
    padding: 1.4rem;
    background: #ffffff;
}

.section-kicker {
    color: #0369a1;
}

.section-badge {
    font-size: 0.76rem;
}

.desktop-table {
    margin-top: 1rem;
    overflow-x: auto;
}

.queue-table {
    width: 100%;
    min-width: 1040px;
    border-collapse: collapse;
}

.queue-table th {
    padding: 0.9rem 1rem;
    text-align: left;
    color: #64748b;
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 1px solid rgba(226, 232, 240, 0.9);
}

.queue-table td {
    padding: 1rem;
    vertical-align: top;
    border-bottom: 1px solid rgba(226, 232, 240, 0.84);
}

.entity-block,
.status-stack,
.action-cluster,
.metric-item {
    display: grid;
    gap: 0.24rem;
}

.entity-block strong,
.rfq-card strong,
.line-item-head strong {
    color: #0f172a;
}

.entity-block span,
.entity-block small,
.status-stack small,
.rfq-card p,
.metric-item span {
    color: #64748b;
}

.entity-block.compact strong {
    font-size: 0.98rem;
}

.status-badge {
    width: fit-content;
    padding: 0.42rem 0.72rem;
    font-size: 0.74rem;
}

.status-badge.tone-blue {
    background: rgba(59, 130, 246, 0.14);
    color: #1d4ed8;
}

.status-badge.tone-amber {
    background: rgba(245, 158, 11, 0.15);
    color: #b45309;
}

.status-badge.tone-purple {
    background: rgba(139, 92, 246, 0.14);
    color: #6d28d9;
}

.status-badge.tone-orange {
    background: rgba(249, 115, 22, 0.14);
    color: #c2410c;
}

.status-badge.tone-green {
    background: rgba(34, 197, 94, 0.14);
    color: #15803d;
}

.status-badge.tone-slate {
    background: rgba(148, 163, 184, 0.18);
    color: #475569;
}

.action-cluster {
    gap: 0.55rem;
}

.next-step-title {
    color: #0f172a;
    font-size: 0.92rem;
    line-height: 1.35;
}

.action-note {
    font-size: 0.78rem;
    line-height: 1.45;
    max-width: 24ch;
}

.btn-success {
    background: #059669;
    color: #ffffff;
}

.mobile-card-list {
    display: none;
    gap: 1rem;
    margin-top: 1rem;
}

.rfq-card {
    padding: 1rem;
    border-radius: 22px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.rfq-card-head p {
    margin: 0.3rem 0 0;
}

.rfq-description {
    margin: 0.85rem 0 0;
    color: #334155;
    line-height: 1.55;
}

.rfq-metrics {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin: 1rem 0 0;
}

.metric-item {
    padding: 0.8rem;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.85);
}

.metric-item strong {
    color: #0f172a;
}

.empty-state {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    text-align: center;
    padding: 3rem 1rem 1rem;
}

.empty-state i {
    font-size: 2.1rem;
    color: #0284c7;
}

.empty-state h3 {
    margin: 0;
    color: #0f172a;
}

.pagination {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.55rem;
    margin-top: 1.5rem;
}

.compact-heading {
    margin-bottom: 1rem;
}

.line-item-card {
    border: 1px solid rgba(226, 232, 240, 0.9);
    border-radius: 22px;
    padding: 1rem;
    margin-bottom: 1rem;
    background: #f8fafc;
}

.line-item-total,
.total-banner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.line-item-total {
    margin-top: 0.85rem;
    padding-top: 0.85rem;
    border-top: 1px solid rgba(226, 232, 240, 0.9);
}

.line-item-total span,
.total-banner span {
    color: #475569;
    font-weight: 700;
}

.line-item-total strong,
.total-banner strong {
    color: #0f172a;
    font-size: 1.05rem;
}

.total-banner {
    padding: 1rem 1.1rem;
    border-radius: 20px;
    margin: 0 0 1rem;
    background: linear-gradient(135deg, #dbeafe, #eff6ff);
}

.modal-subtitle {
    margin: 0.3rem 0 0;
}

.btn-danger-soft {
    background: rgba(239, 68, 68, 0.12);
    color: #b91c1c;
}

@media (max-width: 1180px) {
    .rfq-hero,
    .summary-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 880px) {
    .desktop-table {
        display: none;
    }

    .mobile-card-list {
        display: grid;
    }
}

@media (max-width: 680px) {
    .rfq-metrics,
    .summary-grid,
    .filter-grid {
        grid-template-columns: 1fr;
    }

    .hero-card,
    .queue-panel {
        padding: 1.1rem;
    }
}
</style>
