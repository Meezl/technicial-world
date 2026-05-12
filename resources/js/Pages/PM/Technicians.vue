<template>
    <PMLayout>
        <template #header>
            <div class="directory-header-shell">
                <div class="directory-header-copy">
                    <span class="section-kicker">Technician Directory</span>
                    <h1>Manage technician profiles and compliance in one place</h1>
                    <p>Review availability, vetting, and required documents without hopping between pages.</p>
                </div>

                <div class="header-actions">
                    <label class="search-shell">
                        <i class="fas fa-search"></i>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by name, ID, email, trade, or location..."
                        >
                    </label>

                    <select v-model="filterTrade" class="chart-filter" @change="applyFilter">
                        <option value="">All Trades</option>
                        <option v-for="(label, key) in trades" :key="key" :value="key">{{ label }}</option>
                    </select>

                    <select v-model="filterStatus" class="chart-filter" @change="applyFilter">
                        <option value="">All Vetting Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="under_review">Under Review</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>
        </template>

        <section class="pm-technicians-page">
            <section class="summary-grid">
                <article class="summary-card tone-blue">
                    <div class="summary-topline">
                        <span class="summary-tag">On page</span>
                        <span class="summary-icon"><i class="fas fa-hard-hat"></i></span>
                    </div>
                    <h3>{{ technicians.data.length }}</h3>
                    <p>Technicians in the current result set.</p>
                </article>

                <article class="summary-card tone-green">
                    <div class="summary-topline">
                        <span class="summary-tag">Ready</span>
                        <span class="summary-icon"><i class="fas fa-check-circle"></i></span>
                    </div>
                    <h3>{{ availableCount }}</h3>
                    <p>Currently available for assignment.</p>
                </article>

                <article class="summary-card tone-amber">
                    <div class="summary-topline">
                        <span class="summary-tag">Approved</span>
                        <span class="summary-icon"><i class="fas fa-shield-check"></i></span>
                    </div>
                    <h3>{{ approvedCount }}</h3>
                    <p>Already cleared through vetting.</p>
                </article>

                <article class="summary-card tone-slate">
                    <div class="summary-topline">
                        <span class="summary-tag">Docs complete</span>
                        <span class="summary-icon"><i class="fas fa-folder-open"></i></span>
                    </div>
                    <h3>{{ completeDocsCount }}</h3>
                    <p>Have all mandatory documents uploaded.</p>
                </article>
            </section>

            <section class="directory-grid">
                <article class="panel-card spotlight-card">
                    <div class="card-header">
                        <div>
                            <span class="section-kicker">Quick Highlight</span>
                            <h3>Best-rated technician on this page</h3>
                            <p>A quick signal when you need a proven technician fast.</p>
                        </div>
                    </div>

                    <div v-if="featuredTechnician" class="spotlight-body">
                        <div class="spotlight-top">
                            <div class="spotlight-avatar">{{ getInitials(featuredTechnician.user?.name) }}</div>
                            <div>
                                <h4>{{ featuredTechnician.user?.name || 'Unknown technician' }}</h4>
                                <p>{{ getTradeLabel(featuredTechnician.trade) }} · {{ featuredTechnician.specialization || 'No specialization listed' }}</p>
                            </div>
                        </div>

                        <div class="spotlight-metrics">
                            <div class="metric-chip">
                                <span>Rating</span>
                                <strong>{{ Number(featuredTechnician.rating || 0).toFixed(1) }}/5</strong>
                            </div>
                            <div class="metric-chip">
                                <span>Jobs</span>
                                <strong>{{ featuredTechnician.total_jobs || 0 }}</strong>
                            </div>
                            <div class="metric-chip">
                                <span>Required docs</span>
                                <strong>{{ getRequiredDocsSummary(featuredTechnician) }}</strong>
                            </div>
                        </div>

                        <div class="spotlight-status-row">
                            <span :class="['status-pill', getAvailabilityClass(featuredTechnician.availability)]">
                                {{ formatAvailability(featuredTechnician.availability) }}
                            </span>
                            <span :class="['status-pill', getVettingClass(featuredTechnician.vetting_status)]">
                                {{ formatVetting(featuredTechnician.vetting_status) }}
                            </span>
                        </div>
                    </div>

                    <div v-else class="empty-state compact-empty">
                        <i class="fas fa-user-clock"></i>
                        <p>No featured technician to show yet.</p>
                    </div>
                </article>

                <article class="panel-card filter-card">
                    <div class="card-header">
                        <div>
                            <span class="section-kicker">Filters</span>
                            <h3>Refine the roster</h3>
                            <p>Use trade and vetting filters, then local search for faster scanning.</p>
                        </div>
                    </div>

                    <div class="filter-note-list">
                        <div class="filter-note">
                            <span>Showing</span>
                            <strong>{{ displayedTechnicians.length }} of {{ technicians.data.length }}</strong>
                            <p>Local search filters only this current page.</p>
                        </div>
                        <div class="filter-note">
                            <span>Need documents</span>
                            <strong>{{ techniciansNeedingDocs }}</strong>
                            <p>Missing at least one mandatory file.</p>
                        </div>
                    </div>

                    <div v-if="activeChips.length" class="filter-chip-row">
                        <span v-for="chip in activeChips" :key="chip" class="filter-chip">{{ chip }}</span>
                    </div>

                    <div class="filter-actions">
                        <button @click="clearLocalSearch" class="btn btn-secondary" :disabled="!searchQuery">
                            Clear search
                        </button>
                        <button @click="clearAllFilters" class="btn btn-primary">
                            Reset all filters
                        </button>
                    </div>
                </article>
            </section>

            <section class="main-panel">
                <div class="panel-card roster-shell">
                    <div class="roster-header">
                        <div>
                            <span class="section-kicker">Roster</span>
                            <h3>Technician management view</h3>
                            <p>Open any technician to review or update mandatory onboarding files.</p>
                        </div>
                        <div class="result-pill">
                            {{ displayedTechnicians.length }} result{{ displayedTechnicians.length === 1 ? '' : 's' }}
                        </div>
                    </div>

                    <div v-if="displayedTechnicians.length" class="table-shell desktop-only">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Technician</th>
                                    <th>Trade</th>
                                    <th>Location</th>
                                    <th>Availability</th>
                                    <th>Vetting</th>
                                    <th>Docs</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="tech in displayedTechnicians" :key="tech.id">
                                    <td>
                                        <div class="tech-cell">
                                            <div class="tech-avatar">{{ getInitials(tech.user?.name) }}</div>
                                            <div>
                                                <strong>{{ tech.user?.name }}</strong>
                                                <span class="sub-text">{{ tech.technician_id }}</span>
                                                <span class="sub-text">{{ tech.user?.email }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong>{{ getTradeLabel(tech.trade) }}</strong>
                                        <span class="sub-text">{{ tech.specialization || 'No specialization listed' }}</span>
                                    </td>
                                    <td>{{ tech.location || 'Not set' }}</td>
                                    <td>
                                        <span :class="['status-pill', getAvailabilityClass(tech.availability)]">
                                            {{ formatAvailability(tech.availability) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span :class="['status-pill', getVettingClass(tech.vetting_status)]">
                                            {{ formatVetting(tech.vetting_status) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="doc-summary">
                                            <strong>{{ getRequiredDocsSummary(tech) }}</strong>
                                            <span class="sub-text">{{ getVerifiedDocsSummary(tech) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <button @click="openTechnician(tech)" class="btn btn-primary btn-sm">
                                            Review
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="displayedTechnicians.length" class="mobile-card-list mobile-only">
                        <article v-for="tech in displayedTechnicians" :key="tech.id" class="tech-card">
                            <div class="tech-card-top">
                                <div class="tech-cell">
                                    <div class="tech-avatar">{{ getInitials(tech.user?.name) }}</div>
                                    <div>
                                        <h4>{{ tech.user?.name }}</h4>
                                        <p>{{ tech.technician_id }} · {{ getTradeLabel(tech.trade) }}</p>
                                    </div>
                                </div>
                                <span :class="['status-pill', getAvailabilityClass(tech.availability)]">
                                    {{ formatAvailability(tech.availability) }}
                                </span>
                            </div>

                            <div class="tech-card-grid">
                                <div>
                                    <span>Specialization</span>
                                    <strong>{{ tech.specialization || 'Not listed' }}</strong>
                                </div>
                                <div>
                                    <span>Location</span>
                                    <strong>{{ tech.location || 'Not set' }}</strong>
                                </div>
                                <div>
                                    <span>Required docs</span>
                                    <strong>{{ getRequiredDocsSummary(tech) }}</strong>
                                </div>
                                <div>
                                    <span>Verified docs</span>
                                    <strong>{{ getVerifiedDocsSummary(tech) }}</strong>
                                </div>
                            </div>

                            <div class="tech-card-footer">
                                <span :class="['status-pill', getVettingClass(tech.vetting_status)]">
                                    {{ formatVetting(tech.vetting_status) }}
                                </span>
                                <button @click="openTechnician(tech)" class="btn btn-primary btn-sm">
                                    Review
                                </button>
                            </div>
                        </article>
                    </div>

                    <div v-else class="empty-state">
                        <i class="fas fa-hard-hat"></i>
                        <p>No technicians match the current view.</p>
                    </div>

                    <div class="pagination" v-if="technicians.last_page > 1">
                        <Link
                            v-for="link in technicians.links"
                            :key="`${link.label}-${link.url}`"
                            :href="link.url || '#'"
                            class="btn btn-sm"
                            :class="{ 'btn-primary': link.active, 'btn-secondary': !link.active }"
                            :disabled="!link.url"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </section>
        </section>

        <div v-if="selectedTechnician" class="modal-overlay" @click="closeTechnicianModal">
            <div class="modal-card" @click.stop>
                <div class="modal-header">
                    <div>
                        <span class="section-kicker">Technician Review</span>
                        <h3>{{ selectedTechnician.user?.name }}</h3>
                        <p>{{ selectedTechnician.technician_id }} · {{ getTradeLabel(selectedTechnician.trade) }}</p>
                    </div>
                    <button @click="closeTechnicianModal" class="close-button">&times;</button>
                </div>

                <div class="modal-body">
                    <section class="modal-hero">
                        <div class="modal-hero-main">
                            <div class="tech-avatar modal-avatar">{{ getInitials(selectedTechnician.user?.name) }}</div>
                            <div>
                                <div class="modal-badges">
                                    <span :class="['status-pill', getAvailabilityClass(selectedTechnician.availability)]">
                                        {{ formatAvailability(selectedTechnician.availability) }}
                                    </span>
                                    <span :class="['status-pill', getVettingClass(selectedTechnician.vetting_status)]">
                                        {{ formatVetting(selectedTechnician.vetting_status) }}
                                    </span>
                                </div>
                                <p class="modal-copy">{{ selectedTechnician.specialization || 'No specialization listed' }} · {{ selectedTechnician.location || 'Location not set' }}</p>
                            </div>
                        </div>

                        <div class="modal-stats">
                            <div class="metric-chip">
                                <span>Required docs</span>
                                <strong>{{ getRequiredDocsSummary(selectedTechnician) }}</strong>
                            </div>
                            <div class="metric-chip">
                                <span>Verified docs</span>
                                <strong>{{ getVerifiedDocsSummary(selectedTechnician) }}</strong>
                            </div>
                            <div class="metric-chip">
                                <span>Rating</span>
                                <strong>{{ Number(selectedTechnician.rating || 0).toFixed(1) }}/5</strong>
                            </div>
                        </div>
                    </section>

                    <section class="detail-grid">
                        <article class="detail-card">
                            <div class="card-header">
                                <div>
                                    <span class="section-kicker">Profile</span>
                                    <h4>Contact and role details</h4>
                                </div>
                            </div>

                            <div class="profile-lines">
                                <div class="profile-line">
                                    <span>Email</span>
                                    <strong>{{ selectedTechnician.user?.email }}</strong>
                                </div>
                                <div class="profile-line">
                                    <span>Phone</span>
                                    <strong>{{ selectedTechnician.user?.phone || 'Not provided' }}</strong>
                                </div>
                                <div class="profile-line">
                                    <span>Trade</span>
                                    <strong>{{ getTradeLabel(selectedTechnician.trade) }}</strong>
                                </div>
                                <div class="profile-line">
                                    <span>Total jobs</span>
                                    <strong>{{ selectedTechnician.total_jobs || 0 }}</strong>
                                </div>
                            </div>

                            <div v-if="selectedTechnician.skills?.length" class="skills-list">
                                <span v-for="skill in selectedTechnician.skills" :key="skill" class="skill-tag">{{ skill }}</span>
                            </div>
                        </article>

                        <article class="detail-card">
                            <div class="card-header">
                                <div>
                                    <span class="section-kicker">Mandatory Files</span>
                                    <h4>Compliance checklist</h4>
                                </div>
                            </div>

                            <div class="checklist-grid modal-checklist">
                                <article v-for="type in mandatoryDocTypes" :key="type" class="checklist-item">
                                    <div>
                                        <strong>{{ formatDocType(type) }}</strong>
                                        <p>{{ getDocChecklist(type).description }}</p>
                                    </div>
                                    <span :class="['doc-status-badge', getDocChecklist(type).tone]">
                                        {{ getDocChecklist(type).label }}
                                    </span>
                                </article>
                            </div>
                        </article>
                    </section>

                    <section class="detail-card docs-card">
                        <div class="card-header">
                            <div>
                                <span class="section-kicker">Documents</span>
                                <h4>View, approve, or replace files</h4>
                            </div>
                            <span class="result-pill">{{ selectedTechnician.documents?.length || 0 }} uploaded</span>
                        </div>

                        <div v-if="selectedTechnician.documents?.length" class="doc-review-list">
                            <article v-for="doc in selectedTechnician.documents" :key="doc.id" class="doc-review-card">
                                <div class="doc-review-info">
                                    <div class="document-icon"><i :class="getDocIcon(doc.document_type)"></i></div>
                                    <div>
                                        <strong>{{ formatDocType(doc.document_type) }}</strong>
                                        <p>{{ doc.file_name }}</p>
                                        <small v-if="doc.verified_at">
                                            {{ doc.verified ? 'Verified' : 'Reviewed' }} {{ formatDate(doc.verified_at) }}
                                        </small>
                                    </div>
                                </div>

                                <div class="doc-review-actions">
                                    <span :class="['doc-status-badge', doc.verified ? 'verified' : 'pending']">
                                        {{ doc.verified ? 'Verified' : 'Pending review' }}
                                    </span>
                                    <div class="action-row">
                                        <a :href="`/storage/${doc.file_path}`" target="_blank" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-eye"></i>
                                            View
                                        </a>
                                        <a :href="`/storage/${doc.file_path}`" download class="btn btn-secondary btn-sm">
                                            <i class="fas fa-download"></i>
                                            Download
                                        </a>
                                        <button
                                            v-if="!doc.verified"
                                            @click="verifyDocument(doc.id, 'approve')"
                                            class="btn btn-primary btn-sm"
                                            :disabled="verifyingDoc === doc.id"
                                        >
                                            <i class="fas fa-check"></i>
                                            Approve
                                        </button>
                                        <button
                                            v-else
                                            @click="verifyDocument(doc.id, 'reject')"
                                            class="btn btn-secondary btn-sm"
                                            :disabled="verifyingDoc === doc.id"
                                        >
                                            <i class="fas fa-rotate-left"></i>
                                            Revoke
                                        </button>
                                    </div>
                                </div>
                            </article>
                        </div>
                        <div v-else class="empty-state compact-empty">
                            <i class="fas fa-folder-open"></i>
                            <p>No documents uploaded yet.</p>
                        </div>

                        <div class="upload-box">
                            <div>
                                <h5>Upload or replace a document</h5>
                                <p>Use this when a technician shares an updated certificate or corrected file.</p>
                            </div>

                            <div class="upload-controls">
                                <select v-model="documentType" class="chart-filter">
                                    <option value="">Select document type</option>
                                    <option v-for="(label, key) in documentTypes" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <input ref="documentInput" type="file" class="file-input" :accept="documentAccept">
                                <button @click="uploadDocument" class="btn btn-primary" :disabled="uploadingDoc">
                                    <i class="fas fa-upload"></i>
                                    {{ uploadingDoc ? 'Uploading...' : 'Upload Document' }}
                                </button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </PMLayout>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PMLayout from '../../Layouts/PMLayout.vue'

const props = defineProps({
    technicians: { type: Object, default: () => ({ data: [] }) },
    trades: { type: Object, default: () => ({}) },
    documentTypes: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

const mandatoryDocTypes = ['nca_license', 'tertiary_cert', 'id_card', 'passport_photo', 'pin_cert']

const filterTrade = ref(props.filters.trade || '')
const filterStatus = ref(props.filters.status || '')
const searchQuery = ref('')
const selectedTechnician = ref(null)
const documentType = ref('')
const documentInput = ref(null)
const uploadingDoc = ref(false)
const verifyingDoc = ref(null)

watch(
    () => props.technicians.data,
    (list) => {
        if (!selectedTechnician.value) return
        const updated = (list || []).find((tech) => tech.id === selectedTechnician.value.id)
        if (updated) {
            selectedTechnician.value = updated
        }
    }
)

const applyFilter = () => {
    router.get('/pm/technicians', {
        trade: filterTrade.value || undefined,
        status: filterStatus.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
    })
}

const clearAllFilters = () => {
    searchQuery.value = ''
    filterTrade.value = ''
    filterStatus.value = ''
    applyFilter()
}

const clearLocalSearch = () => {
    searchQuery.value = ''
}

const displayedTechnicians = computed(() => {
    const query = searchQuery.value.trim().toLowerCase()

    if (!query) {
        return props.technicians.data || []
    }

    return (props.technicians.data || []).filter((tech) => {
        const haystack = [
            tech.user?.name,
            tech.user?.email,
            tech.technician_id,
            tech.specialization,
            tech.location,
            props.trades[tech.trade] || tech.trade,
        ].filter(Boolean).join(' ').toLowerCase()

        return haystack.includes(query)
    })
})

const availableCount = computed(() => (props.technicians.data || []).filter((tech) => tech.availability === 'available').length)
const approvedCount = computed(() => (props.technicians.data || []).filter((tech) => tech.vetting_status === 'approved').length)
const completeDocsCount = computed(() => (props.technicians.data || []).filter((tech) => countRequiredDocs(tech) === mandatoryDocTypes.length).length)
const techniciansNeedingDocs = computed(() => (props.technicians.data || []).filter((tech) => countRequiredDocs(tech) < mandatoryDocTypes.length).length)

const featuredTechnician = computed(() => {
    const roster = props.technicians.data || []

    if (!roster.length) return null

    return [...roster].sort((a, b) => {
        const ratingDiff = Number(b.rating || 0) - Number(a.rating || 0)
        if (ratingDiff !== 0) return ratingDiff
        return Number(b.total_jobs || 0) - Number(a.total_jobs || 0)
    })[0]
})

const activeChips = computed(() => {
    const chips = []
    if (filterTrade.value) chips.push(`Trade: ${props.trades[filterTrade.value] || filterTrade.value}`)
    if (filterStatus.value) chips.push(`Vetting: ${formatVetting(filterStatus.value)}`)
    if (searchQuery.value) chips.push(`Search: ${searchQuery.value}`)
    return chips
})

const documentAccept = computed(() => documentType.value === 'passport_photo' ? '.jpg,.jpeg,.png' : '.pdf,.jpg,.jpeg,.png')

function getVettingClass(status) {
    const map = {
        approved: 'completed',
        under_review: 'review',
        pending: 'busy',
        rejected: 'new',
    }

    return map[status] || 'review'
}

function getAvailabilityClass(availability) {
    const map = {
        available: 'available',
        busy: 'busy',
        on_leave: 'leave',
    }

    return map[availability] || 'leave'
}

function formatAvailability(availability) {
    const map = {
        available: 'Available',
        busy: 'Busy',
        on_leave: 'On Leave',
    }

    return map[availability] || 'Unknown'
}

function formatVetting(status) {
    const map = {
        approved: 'Approved',
        under_review: 'Under Review',
        pending: 'Pending',
        rejected: 'Rejected',
    }

    return map[status] || 'Review'
}

function getTradeLabel(trade) {
    return props.trades[trade] || trade || 'Unassigned trade'
}

function getInitials(name) {
    if (!name) return 'T'
    return name.split(' ').map((part) => part[0]).join('').toUpperCase().slice(0, 2)
}

function countRequiredDocs(tech) {
    return mandatoryDocTypes.filter((type) => (tech.documents || []).some((doc) => doc.document_type === type)).length
}

function countVerifiedDocs(tech) {
    return mandatoryDocTypes.filter((type) => (tech.documents || []).some((doc) => doc.document_type === type && doc.verified)).length
}

function getRequiredDocsSummary(tech) {
    return `${countRequiredDocs(tech)}/${mandatoryDocTypes.length}`
}

function getVerifiedDocsSummary(tech) {
    return `${countVerifiedDocs(tech)}/${mandatoryDocTypes.length} verified`
}

function openTechnician(tech) {
    selectedTechnician.value = tech
    documentType.value = ''
    if (documentInput.value) {
        documentInput.value.value = ''
    }
}

function closeTechnicianModal() {
    selectedTechnician.value = null
    documentType.value = ''
}

function findSelectedDoc(type) {
    return (selectedTechnician.value?.documents || []).find((doc) => doc.document_type === type)
}

function getDocChecklist(type) {
    const doc = findSelectedDoc(type)

    if (!doc) {
        return { label: 'Missing', tone: 'missing', description: 'Upload this document to complete the profile.' }
    }

    if (doc.verified) {
        return { label: 'Verified', tone: 'verified', description: 'This file has already been approved.' }
    }

    return { label: 'Pending', tone: 'pending', description: 'Uploaded and waiting for approval.' }
}

function formatDocType(type) {
    return props.documentTypes[type] || type?.replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase()) || 'Document'
}

function getDocIcon(type) {
    const map = {
        nca_license: 'fas fa-id-badge',
        tertiary_cert: 'fas fa-graduation-cap',
        id_card: 'fas fa-id-card',
        passport_photo: 'fas fa-camera',
        pin_cert: 'fas fa-file-invoice',
        technical_cert: 'fas fa-certificate',
        vetting_form: 'fas fa-clipboard-check',
        other: 'fas fa-file-alt',
    }

    return map[type] || 'fas fa-file-alt'
}

function verifyDocument(docId, action) {
    verifyingDoc.value = docId
    router.post(`/pm/technician-documents/${docId}/verify`, { action }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            verifyingDoc.value = null
        },
        onError: () => {
            verifyingDoc.value = null
        },
    })
}

function uploadDocument() {
    if (!selectedTechnician.value || !documentType.value || !documentInput.value?.files?.[0]) return

    uploadingDoc.value = true
    const formData = new FormData()
    formData.append('document_type', documentType.value)
    formData.append('document', documentInput.value.files[0])

    router.post(`/pm/technicians/${selectedTechnician.value.id}/documents`, formData, {
        forceFormData: true,
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadingDoc.value = false
            documentType.value = ''
            if (documentInput.value) {
                documentInput.value.value = ''
            }
        },
        onError: () => {
            uploadingDoc.value = false
        },
    })
}

function formatDate(dateString) {
    if (!dateString) return ''
    return new Date(dateString).toLocaleDateString('en-KE', { month: 'short', day: 'numeric', year: 'numeric' })
}

defineOptions({ layout: null })
</script>

<style scoped>
.pm-technicians-page {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.directory-header-shell {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    width: 100%;
}

.directory-header-copy h1 {
    margin: 0.45rem 0 0;
    font-size: clamp(1.9rem, 3vw, 2.35rem);
    color: #0f172a;
}

.directory-header-copy p,
.card-header p,
.roster-header p,
.spotlight-top p,
.filter-note p,
.modal-header p,
.modal-copy,
.upload-box p,
.checklist-item p,
.doc-review-info p,
.doc-review-info small {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.section-kicker {
    display: inline-flex;
    align-items: center;
    color: #0f6c8f;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.header-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.75rem;
}

.search-shell {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    min-width: min(100%, 21rem);
    padding: 0.82rem 0.95rem;
    border-radius: 16px;
    border: 1px solid #d7dee7;
    background: #ffffff;
    color: #64748b;
}

.search-shell input,
.file-input {
    border: none;
    outline: none;
    width: 100%;
    font: inherit;
    color: #0f172a;
    background: transparent;
}

.summary-grid,
.directory-grid,
.spotlight-metrics,
.tech-card-grid,
.detail-grid,
.checklist-grid {
    display: grid;
    gap: 1rem;
}

.summary-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card,
.panel-card,
.detail-card,
.modal-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.summary-card {
    padding: 1.3rem;
}

.tone-blue {
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.98), #ffffff);
}

.tone-green {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.98), #ffffff);
}

.tone-amber {
    background: linear-gradient(180deg, rgba(255, 251, 235, 0.99), #ffffff);
}

.tone-slate {
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.99), #ffffff);
}

.summary-topline,
.spotlight-top,
.tech-cell,
.tech-card-top,
.tech-card-footer,
.spotlight-status-row,
.filter-actions,
.roster-header,
.modal-header,
.modal-hero-main,
.doc-review-card,
.doc-review-actions,
.action-row,
.profile-line,
.upload-controls,
.document-icon {
    display: flex;
    gap: 0.85rem;
}

.summary-topline,
.roster-header,
.tech-card-top,
.modal-header,
.doc-review-card,
.profile-line {
    align-items: flex-start;
    justify-content: space-between;
}

.summary-tag,
.result-pill,
.sub-text,
.tech-card-grid span,
.filter-note span,
.metric-chip span,
.profile-line span {
    color: #64748b;
    font-size: 0.8rem;
}

.summary-icon,
.tech-avatar,
.spotlight-avatar,
.document-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
}

.summary-icon {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 16px;
}

.summary-card h3,
.metric-chip strong,
.profile-line strong,
.doc-review-info strong,
.checklist-item strong {
    color: #0f172a;
}

.summary-card h3 {
    margin: 0.85rem 0 0;
    font-size: 1.85rem;
}

.directory-grid {
    grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
}

.panel-card,
.detail-card {
    padding: 1.4rem;
}

.card-header h3,
.roster-header h3,
.spotlight-top h4,
.tech-card h4,
.modal-header h3,
.card-header h4,
.upload-box h5 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.spotlight-avatar,
.tech-avatar {
    width: 3.1rem;
    height: 3.1rem;
    border-radius: 18px;
    flex-shrink: 0;
    font-weight: 800;
}

.metric-chip,
.filter-note,
.tech-card-grid > div,
.checklist-item {
    padding: 0.9rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.spotlight-metrics,
.tech-card-grid,
.modal-stats {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 1rem;
}

.metric-chip strong {
    display: block;
    margin-top: 0.3rem;
}

.spotlight-status-row,
.filter-actions,
.action-row,
.upload-controls {
    flex-wrap: wrap;
    margin-top: 1rem;
}

.filter-note-list {
    display: grid;
    gap: 0.85rem;
    margin-top: 1rem;
}

.filter-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-top: 1rem;
}

.filter-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.48rem 0.8rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0f6c8f;
    font-size: 0.8rem;
    font-weight: 700;
}

.status-pill,
.doc-status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.4rem 0.72rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.status-pill.available,
.status-pill.completed,
.doc-status-badge.verified {
    background: #dcfce7;
    color: #166534;
}

.status-pill.busy,
.doc-status-badge.pending {
    background: #fef3c7;
    color: #92400e;
}

.status-pill.leave,
.status-pill.new,
.doc-status-badge.missing {
    background: #fee2e2;
    color: #991b1b;
}

.status-pill.review {
    background: #ede9fe;
    color: #6d28d9;
}

.result-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.7rem 0.95rem;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    font-weight: 700;
}

.table-shell {
    overflow-x: auto;
    margin-top: 1rem;
}

.data-table {
    width: 100%;
    min-width: 940px;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 1rem 0.85rem;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    vertical-align: top;
}

.data-table th {
    font-size: 0.76rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
}

.doc-summary {
    display: grid;
    gap: 0.2rem;
}

.tech-card {
    padding: 1rem;
    border-radius: 22px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
}

.tech-card-top,
.tech-card-footer {
    align-items: center;
    justify-content: space-between;
}

.tech-card-footer {
    margin-top: 1rem;
    flex-wrap: wrap;
}

.main-panel {
    display: block;
}

.pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
}

.mobile-only {
    display: none;
}

.empty-state {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    padding: 2rem 1rem;
    text-align: center;
    color: #64748b;
}

.compact-empty {
    padding: 1.25rem 1rem;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    z-index: 70;
}

.modal-card {
    width: min(1080px, 100%);
    max-height: calc(100vh - 3rem);
    overflow: auto;
    padding: 1.35rem;
}

.close-button {
    border: none;
    background: transparent;
    font-size: 1.8rem;
    color: #64748b;
    cursor: pointer;
    line-height: 1;
}

.modal-body,
.doc-review-list,
.profile-lines {
    display: grid;
    gap: 1rem;
}

.modal-hero {
    display: grid;
    gap: 1rem;
    padding-bottom: 1rem;
}

.modal-avatar {
    width: 3.6rem;
    height: 3.6rem;
}

.modal-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.modal-copy {
    margin: 0.6rem 0 0;
}

.detail-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.profile-line {
    padding: 0.8rem 0;
    border-bottom: 1px solid #e2e8f0;
}

.profile-line:last-child {
    border-bottom: none;
}

.skills-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
}

.skill-tag {
    display: inline-flex;
    align-items: center;
    padding: 0.42rem 0.75rem;
    border-radius: 999px;
    background: #e0f2fe;
    color: #0f6c8f;
    font-size: 0.8rem;
    font-weight: 700;
}

.doc-review-card,
.upload-box {
    padding: 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.doc-review-info {
    display: flex;
    gap: 0.8rem;
    min-width: 0;
}

.doc-review-actions {
    flex-direction: column;
    align-items: flex-end;
}

.file-input {
    padding: 0.85rem 0.95rem;
    border: 1px solid #d7dee7;
    border-radius: 16px;
    background: #ffffff;
}

@media (max-width: 1180px) {
    .summary-grid,
    .directory-grid,
    .detail-grid,
    .modal-stats,
    .spotlight-metrics {
        grid-template-columns: 1fr;
    }

    .directory-header-shell {
        flex-direction: column;
    }
}

@media (max-width: 860px) {
    .desktop-only {
        display: none;
    }

    .mobile-only {
        display: grid;
        gap: 1rem;
        margin-top: 1rem;
    }
}

@media (max-width: 640px) {
    .modal-overlay {
        padding: 0.75rem;
    }

    .modal-card,
    .summary-card,
    .panel-card,
    .detail-card {
        padding: 1rem;
    }

    .modal-header,
    .modal-hero-main,
    .doc-review-card,
    .doc-review-actions {
        flex-direction: column;
    }

    .doc-review-actions {
        align-items: stretch;
    }

    .action-row .btn,
    .upload-controls > * {
        width: 100%;
    }
}
</style>
