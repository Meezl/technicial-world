<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <div>
                <h1>My Profile</h1>
                <p class="header-subtitle">Keep your details and compliance documents up to date.</p>
            </div>
            <button @click="logout" class="btn btn-outline btn-sm" style="width: auto; padding: 0.5rem 1rem;">
                <i class="fas fa-sign-out-alt"></i>
            </button>
        </header>

        <main class="pwa-content profile-page">
            <section class="profile-hero-card">
                <div class="hero-top">
                    <div class="hero-avatar">
                        <img v-if="technician?.profile_photo_path" :src="`/storage/${technician.profile_photo_path}`" alt="Profile photo" class="avatar-img" />
                        <i v-else class="fas fa-user"></i>
                    </div>

                    <div class="hero-copy">
                        <div class="hero-title-row">
                            <div>
                                <span class="hero-kicker">Technician Workspace</span>
                                <h2>{{ technician?.user?.name || 'Technician' }}</h2>
                                <p>{{ technician?.specialization || 'General technician' }} · {{ technician?.location || 'Location not set' }}</p>
                            </div>
                            <button @click="toggleEditing" class="btn btn-secondary btn-sm">
                                <i class="fas fa-pen"></i>
                                {{ isEditing ? 'Close Editor' : 'Edit Profile' }}
                            </button>
                        </div>

                        <div class="hero-badges">
                            <span class="badge badge-rating">
                                <i class="fas fa-star"></i>
                                {{ technician?.rating ? Number(technician.rating).toFixed(1) : 'New' }}
                            </span>
                            <span :class="['badge', `badge-${vettingTone}`]">
                                <i :class="vettingIcon"></i>
                                {{ vettingLabel }}
                            </span>
                            <span :class="['badge', `badge-${availabilityTone}`]">
                                <i :class="availabilityIcon"></i>
                                {{ availabilityLabel }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="hero-metrics">
                    <div class="metric-card">
                        <span>Required docs</span>
                        <strong>{{ requiredDocsUploaded }}/{{ requiredDocumentTypes.length }}</strong>
                        <p>Uploaded so far</p>
                    </div>
                    <div class="metric-card">
                        <span>Verified docs</span>
                        <strong>{{ verifiedRequiredDocs }}/{{ requiredDocumentTypes.length }}</strong>
                        <p>Approved by admin or PM</p>
                    </div>
                    <div class="metric-card">
                        <span>Total jobs</span>
                        <strong>{{ jobStats.total || 0 }}</strong>
                        <p>Recorded assignments</p>
                    </div>
                    <div class="metric-card">
                        <span>In progress</span>
                        <strong>{{ jobStats.in_progress || 0 }}</strong>
                        <p>Active right now</p>
                    </div>
                </div>
            </section>

            <!-- Company Documents card — Technician World's own KRA PIN,
                 always downloadable so the technician can hand it to any
                 supplier they're procuring from on TW's behalf without
                 having to message ops first. Same card layout as the
                 dashboard so it feels like one system. -->
            <a
                href="/documents/technician-world-kra-pin.pdf"
                target="_blank"
                rel="noopener"
                download
                class="panel-card"
                style="display: flex; justify-content: space-between; align-items: center; gap: 1rem; text-decoration: none; color: inherit; margin-bottom: 1rem;"
            >
                <div>
                    <span class="section-kicker" style="color: #b45309;">Company Documents</span>
                    <h3 style="margin: 0.35rem 0 0.25rem;">
                        <i class="fas fa-file-pdf" style="color: #dc2626; margin-right: 0.4rem;"></i>
                        Technician World KRA PIN
                    </h3>
                    <p style="margin: 0; font-size: 0.85rem; color: var(--light-text, #64748b);">
                        Download when a supplier asks for TW's PIN during procurement.
                    </p>
                </div>
                <span class="btn btn-primary btn-sm" style="white-space: nowrap;">
                    <i class="fas fa-download"></i> Download
                </span>
            </a>

            <section class="panel-card checklist-card">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Mandatory Documents</span>
                        <h3>Compliance checklist</h3>
                    </div>
                    <span :class="['section-pill', missingRequiredDocs.length ? 'section-pill-alert' : 'section-pill-ok']">
                        {{ missingRequiredDocs.length ? `${missingRequiredDocs.length} missing` : 'Complete set uploaded' }}
                    </span>
                </div>

                <div class="checklist-grid">
                    <article v-for="type in requiredDocumentTypes" :key="type" class="checklist-item">
                        <div>
                            <strong>{{ formatDocType(type) }}</strong>
                            <p>{{ getChecklistStatus(type).description }}</p>
                        </div>
                        <span :class="['doc-state-badge', getChecklistStatus(type).tone]">
                            {{ getChecklistStatus(type).label }}
                        </span>
                    </article>
                </div>
            </section>

            <section class="panel-grid">
                <article class="panel-card">
                    <div class="section-heading">
                        <div>
                            <span class="section-kicker">Profile Details</span>
                            <h3>Personal and trade information</h3>
                        </div>
                    </div>

                    <!-- Pending changes banner — visible when skills/bio submissions
                         are waiting for admin approval. -->
                    <div v-if="hasPendingChanges" class="pending-banner">
                        <i class="fas fa-clock"></i>
                        <div style="flex:1;">
                            <strong>Profile updates submitted</strong>
                            <p style="margin:.25rem 0 .5rem;font-size:.85rem;">
                                Your skills{{ pendingChanges.bio ? ' and bio' : '' }}{{ pendingChanges.skills ? '' : '' }} are awaiting admin approval. The current public version will stay live until they review.
                            </p>
                            <button type="button" class="btn btn-secondary btn-sm" @click="withdrawPending">Withdraw submission</button>
                        </div>
                    </div>

                    <form @submit.prevent="saveProfile" class="profile-form">
                        <!-- Auto-applied fields (no approval needed) -->
                        <div class="form-grid">
                            <label class="form-field">
                                <span>Phone <small style="color:#10b981;">(updates immediately)</small></span>
                                <input v-model="profileForm.phone" type="text" class="input" :disabled="!isEditing">
                            </label>

                            <label class="form-field">
                                <span>Profile photo <small style="color:#10b981;">(updates immediately)</small></span>
                                <input ref="profilePhotoInput" type="file" accept=".jpg,.jpeg,.png" class="input" :disabled="!isEditing">
                            </label>
                        </div>

                        <!-- Approval-gated fields -->
                        <label class="form-field">
                            <span>Skills / Key Competences <small style="color:#92400e;">(requires admin approval)</small></span>
                            <input v-model="skillsInput" type="text" class="input" placeholder="Separate skills with commas" :disabled="!isEditing">
                        </label>

                        <label class="form-field">
                            <span>Bio / About me <small style="color:#92400e;">(requires admin approval)</small></span>
                            <textarea v-model="profileForm.bio" rows="4" class="input textarea" :disabled="!isEditing"></textarea>
                        </label>

                        <!-- Read-only context (not editable from this form) -->
                        <details class="read-only-fields">
                            <summary>Other profile details (contact admin to change)</summary>
                            <div class="form-grid" style="margin-top:.75rem;">
                                <label class="form-field"><span>Full name</span><input :value="profileForm.name" type="text" class="input" disabled></label>
                                <label class="form-field"><span>Location</span><input :value="profileForm.location" type="text" class="input" disabled></label>
                                <label class="form-field"><span>Trade</span><input :value="trades[profileForm.trade] || '—'" type="text" class="input" disabled></label>
                                <label class="form-field"><span>Specialization</span><input :value="profileForm.specialization || '—'" type="text" class="input" disabled></label>
                            </div>
                        </details>

                        <div v-if="isEditing" class="form-actions">
                            <button type="button" @click="resetProfileForm" class="btn btn-secondary">Reset</button>
                            <button type="submit" class="btn btn-primary" :disabled="savingProfile">
                                <i class="fas fa-save"></i>
                                {{ savingProfile ? 'Saving...' : 'Save Profile' }}
                            </button>
                        </div>
                    </form>
                </article>

                <article class="panel-card">
                    <div class="section-heading">
                        <div>
                            <span class="section-kicker">Documents</span>
                            <h3>Upload or replace your files</h3>
                        </div>
                    </div>

                    <form @submit.prevent="uploadDocument" class="upload-form">
                        <label class="form-field">
                            <span>Document type</span>
                            <select v-model="documentForm.document_type" class="input">
                                <option value="">Select document type</option>
                                <option v-for="(label, key) in documentTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </label>

                        <label class="form-field">
                            <span>File</span>
                            <input
                                ref="documentInput"
                                type="file"
                                :accept="documentAccept"
                                class="input"
                            >
                        </label>

                        <button type="submit" class="btn btn-primary" :disabled="uploadingDocument">
                            <i class="fas fa-upload"></i>
                            {{ uploadingDocument ? 'Uploading...' : 'Upload Document' }}
                        </button>
                    </form>

                    <div v-if="documents.length" class="documents-list">
                        <article v-for="doc in documents" :key="doc.id" class="document-card">
                            <div class="document-main">
                                <div class="document-icon"><i :class="getDocIcon(doc.document_type)"></i></div>
                                <div>
                                    <strong>{{ formatDocType(doc.document_type) }}</strong>
                                    <p>{{ doc.file_name || 'Document' }}</p>
                                    <small v-if="doc.verified_at">
                                        {{ doc.verified ? 'Verified' : 'Reviewed' }} {{ formatDateTime(doc.verified_at) }}
                                    </small>
                                </div>
                            </div>

                            <div class="document-actions">
                                <span :class="['doc-state-badge', doc.verified ? 'verified' : 'pending']">
                                    {{ doc.verified ? 'Verified' : 'Pending review' }}
                                </span>
                                <div class="action-row">
                                    <a :href="`/technician/documents/${doc.id}/download`" target="_blank" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-eye"></i>
                                        View
                                    </a>
                                    <a :href="`/technician/documents/${doc.id}/download`" download class="btn btn-secondary btn-sm">
                                        <i class="fas fa-download"></i>
                                        Download
                                    </a>
                                </div>
                            </div>
                        </article>
                    </div>
                    <p v-else class="empty-text">No documents uploaded yet.</p>
                </article>
            </section>

            <section class="panel-grid">
                <article class="panel-card">
                    <div class="section-heading">
                        <div>
                            <span class="section-kicker">Current Snapshot</span>
                            <h3>Profile summary</h3>
                        </div>
                    </div>

                    <div class="info-list">
                        <div class="info-row">
                            <span>Email</span>
                            <strong>{{ technician?.user?.email || 'N/A' }}</strong>
                        </div>
                        <div class="info-row">
                            <span>Technician ID</span>
                            <strong>{{ technician?.technician_id || `#${technician?.id || ''}` }}</strong>
                        </div>
                        <div class="info-row">
                            <span>Trade</span>
                            <strong>{{ trades[technician?.trade] || technician?.trade || 'Not set' }}</strong>
                        </div>
                        <div class="info-row">
                            <span>Skills listed</span>
                            <strong>{{ skills.length }}</strong>
                        </div>
                    </div>

                    <div v-if="skills.length" class="skills-grid">
                        <span v-for="skill in skills" :key="skill" class="skill-tag">
                            <i class="fas fa-check"></i>
                            {{ skill }}
                        </span>
                    </div>
                </article>

                <article class="panel-card">
                    <div class="section-heading">
                        <div>
                            <span class="section-kicker">Recent Jobs</span>
                            <h3>Latest assignments</h3>
                        </div>
                    </div>

                    <div v-if="recentJobs.length" class="jobs-list">
                        <article v-for="job in recentJobs" :key="job.id" class="job-card">
                            <div>
                                <strong>{{ job.request_id || `REQ-${job.id}` }}</strong>
                                <p>{{ job.service_category?.name || 'General' }}</p>
                            </div>
                            <span :class="['doc-state-badge', `status-${getStatusTone(job.status)}`]">
                                {{ formatStatus(job.status) }}
                            </span>
                        </article>
                    </div>
                    <p v-else class="empty-text">No jobs assigned yet.</p>
                </article>
            </section>
        </main>

        <TechnicianBottomNav current-page="profile" />
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue'

const props = defineProps({
    technician: { type: Object, default: null },
    jobStats: { type: Object, default: () => ({}) },
    documents: { type: Array, default: () => [] },
    recentJobs: { type: Array, default: () => [] },
    documentTypes: { type: Object, default: () => ({}) },
    requiredDocumentTypes: { type: Array, default: () => [] },
    trades: { type: Object, default: () => ({}) },
})

const isEditing = ref(false)
const savingProfile = ref(false)
const uploadingDocument = ref(false)
const profilePhotoInput = ref(null)
const documentInput = ref(null)

const profileForm = ref(createProfileForm())

// Pending profile changes (skills + bio) awaiting admin approval.
const pendingChanges = computed(() => props.technician?.pending_profile_changes || null)
const hasPendingChanges = computed(() => !!pendingChanges.value && (pendingChanges.value.skills || pendingChanges.value.bio))

const withdrawPending = () => {
    if (!confirm('Withdraw your pending profile changes? You can resubmit them later.')) return
    router.post('/technician/profile/withdraw-changes', {}, { preserveScroll: true })
}
const documentForm = ref({
    document_type: '',
})
const skillsInput = ref((props.technician?.skills || []).join(', '))

function createProfileForm() {
    return {
        name: props.technician?.user?.name || '',
        phone: props.technician?.user?.phone || '',
        location: props.technician?.location || '',
        trade: props.technician?.trade || '',
        specialization: props.technician?.specialization || '',
        bio: props.technician?.bio || '',
        experience_narrative: props.technician?.experience_narrative || '',
    }
}

const skills = computed(() => {
    if (!props.technician?.skills) return []
    return Array.isArray(props.technician.skills) ? props.technician.skills : []
})

const availabilityLabel = computed(() => {
    const map = { available: 'Available', busy: 'Busy', on_leave: 'On Leave' }
    return map[props.technician?.availability] || 'Unavailable'
})

const availabilityTone = computed(() => {
    const map = { available: 'available', busy: 'busy', on_leave: 'danger' }
    return map[props.technician?.availability] || 'danger'
})

const availabilityIcon = computed(() => {
    const map = {
        available: 'fas fa-check-circle',
        busy: 'fas fa-helmet-safety',
        on_leave: 'fas fa-bed',
    }
    return map[props.technician?.availability] || 'fas fa-minus-circle'
})

const vettingLabel = computed(() => {
    const map = { approved: 'Approved', pending: 'Pending', under_review: 'Under Review', rejected: 'Rejected' }
    return map[props.technician?.vetting_status] || 'Pending'
})

const vettingTone = computed(() => {
    const map = { approved: 'available', pending: 'warning', under_review: 'review', rejected: 'danger' }
    return map[props.technician?.vetting_status] || 'warning'
})

const vettingIcon = computed(() => {
    const map = { approved: 'fas fa-shield-check', pending: 'fas fa-hourglass-half', under_review: 'fas fa-search', rejected: 'fas fa-times-circle' }
    return map[props.technician?.vetting_status] || 'fas fa-hourglass-half'
})

const requiredDocsUploaded = computed(() =>
    props.requiredDocumentTypes.filter((type) => findDocument(type)).length
)

const verifiedRequiredDocs = computed(() =>
    props.requiredDocumentTypes.filter((type) => findDocument(type)?.verified).length
)

const missingRequiredDocs = computed(() =>
    props.requiredDocumentTypes.filter((type) => !findDocument(type))
)

const documentAccept = computed(() =>
    documentForm.value.document_type === 'passport_photo' ? '.jpg,.jpeg,.png' : '.pdf,.jpg,.jpeg,.png'
)

function toggleEditing() {
    isEditing.value = !isEditing.value
    if (!isEditing.value) {
        resetProfileForm()
    }
}

function resetProfileForm() {
    profileForm.value = createProfileForm()
    skillsInput.value = (props.technician?.skills || []).join(', ')
    if (profilePhotoInput.value) {
        profilePhotoInput.value.value = ''
    }
}

function saveProfile() {
    savingProfile.value = true
    const formData = new FormData()

    Object.entries(profileForm.value).forEach(([key, value]) => {
        if (value !== null && value !== undefined) {
            formData.append(key, value)
        }
    })

    skillsInput.value
        .split(',')
        .map((skill) => skill.trim())
        .filter(Boolean)
        .forEach((skill, index) => formData.append(`skills[${index}]`, skill))

    if (profilePhotoInput.value?.files?.[0]) {
        formData.append('profile_photo', profilePhotoInput.value.files[0])
    }

    router.post('/technician/profile/update', formData, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            savingProfile.value = false
            isEditing.value = false
        },
        onError: () => {
            savingProfile.value = false
        },
    })
}

function uploadDocument() {
    if (!documentForm.value.document_type || !documentInput.value?.files?.[0]) return

    uploadingDocument.value = true
    const formData = new FormData()
    formData.append('document_type', documentForm.value.document_type)
    formData.append('document', documentInput.value.files[0])

    router.post('/technician/profile/document', formData, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadingDocument.value = false
            documentForm.value.document_type = ''
            if (documentInput.value) {
                documentInput.value.value = ''
            }
        },
        onError: () => {
            uploadingDocument.value = false
        },
    })
}

function findDocument(type) {
    return (props.documents || []).find((doc) => doc.document_type === type)
}

function getChecklistStatus(type) {
    const doc = findDocument(type)

    if (!doc) {
        return { label: 'Missing', tone: 'missing', description: 'Upload this file to complete your profile.' }
    }

    if (doc.verified) {
        return { label: 'Verified', tone: 'verified', description: 'This document has already been approved.' }
    }

    return { label: 'Pending', tone: 'pending', description: 'Uploaded and waiting for review.' }
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

function formatStatus(status) {
    return (status || 'unknown').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

function getStatusTone(status) {
    const map = {
        in_progress: 'info',
        assigned: 'warning',
        queued: 'warning',
        closed: 'verified',
        archived: 'verified',
        completed_pending_confirmation: 'verified',
        suspended: 'danger',
        delayed: 'danger',
    }

    return map[status] || 'neutral'
}

function formatDateTime(dateString) {
    if (!dateString) return ''

    return new Date(dateString).toLocaleDateString('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    })
}

const logout = () => router.post('/logout')

defineOptions({ layout: null })
</script>

<style>

.profile-page {
    display: grid;
    gap: 1rem;
}

.header-subtitle {
    margin: 0.2rem 0 0;
    color: var(--light-text);
    font-size: 0.82rem;
}

.profile-hero-card,
.panel-card,
.metric-card,
.checklist-item,
.document-card,
.job-card {
    border-radius: 18px;
    border: 1px solid var(--border-color);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
}

.profile-hero-card,
.panel-card {
    background: var(--white);
    padding: 1rem;
}

.profile-hero-card {
    background:
        radial-gradient(circle at top right, rgba(56, 189, 248, 0.18), transparent 32%),
        linear-gradient(180deg, #ffffff, #f8fbff);
}

.hero-top,
.hero-title-row,
.section-heading,
.document-card,
.document-main,
.document-actions,
.action-row,
.job-card,
.info-row,
.form-actions {
    display: flex;
    gap: 0.75rem;
}

.hero-top,
.section-heading,
.document-card,
.job-card,
.info-row {
    align-items: flex-start;
    justify-content: space-between;
}

.hero-avatar {
    width: 88px;
    height: 88px;
    border-radius: 24px;
    background: linear-gradient(135deg, #0f4c81, #38bdf8);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    flex-shrink: 0;
    overflow: hidden;
}

.avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.hero-copy {
    flex: 1;
    min-width: 0;
}

.hero-kicker,
.section-kicker {
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #0f6c8f;
}

.hero-title-row {
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
}

.hero-title-row h2,
.section-heading h3 {
    margin: 0.35rem 0 0;
    color: var(--text-color);
}

.hero-title-row p {
    margin: 0.4rem 0 0;
    color: var(--light-text);
}

.hero-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.9rem;
}

.badge,
.section-pill,
.doc-state-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.42rem 0.75rem;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
}

.badge-rating {
    background: rgba(245, 158, 11, 0.14);
    color: #b45309;
}

.badge-available,
.section-pill-ok,
.doc-state-badge.verified,
.doc-state-badge.status-verified {
    background: #dcfce7;
    color: #166534;
}

.badge-review,
.doc-state-badge.status-info {
    background: #dbeafe;
    color: #1d4ed8;
}

.badge-warning,
.doc-state-badge.pending,
.doc-state-badge.status-warning {
    background: #fef3c7;
    color: #92400e;
}

.badge-danger,
.section-pill-alert,
.doc-state-badge.missing,
.doc-state-badge.status-danger {
    background: #fee2e2;
    color: #991b1b;
}

.doc-state-badge.status-neutral {
    background: #e2e8f0;
    color: #475569;
}

.hero-metrics,
.checklist-grid,
.panel-grid,
.form-grid {
    display: grid;
    gap: 0.75rem;
}

.hero-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 1rem;
}

.metric-card {
    background: rgba(255, 255, 255, 0.86);
    padding: 0.9rem;
}

.metric-card span,
.checklist-item p,
.document-card p,
.document-card small,
.info-row span,
.empty-text {
    color: var(--light-text);
}

.metric-card strong {
    display: block;
    margin-top: 0.3rem;
    font-size: 1.35rem;
    color: var(--text-color);
}

.metric-card p {
    margin: 0.35rem 0 0;
    font-size: 0.78rem;
}

.checklist-grid,
.panel-grid {
    grid-template-columns: 1fr;
}

.checklist-item {
    background: #f8fafc;
    padding: 0.9rem;
}

.checklist-item strong,
.document-card strong,
.job-card strong,
.info-row strong {
    color: var(--text-color);
}

.checklist-item p,
.document-card p,
.document-card small {
    margin: 0.25rem 0 0;
    font-size: 0.8rem;
}

.form-grid {
    grid-template-columns: 1fr;
}

.profile-form,
.upload-form,
.documents-list,
.jobs-list,
.info-list,
.skills-grid {
    display: grid;
    gap: 0.75rem;
}

.form-field {
    display: grid;
    gap: 0.35rem;
}

.form-field span {
    font-size: 0.78rem;
    font-weight: 700;
    color: #475569;
}

.input {
    width: 100%;
    border: 1px solid #d7dee7;
    border-radius: 12px;
    padding: 0.8rem 0.9rem;
    background: #ffffff;
    color: var(--text-color);
    font: inherit;
}

.textarea {
    min-height: 110px;
    resize: vertical;
}

.form-actions {
    justify-content: flex-end;
    flex-wrap: wrap;
    margin-top: 0.3rem;
}

.document-card,
.job-card {
    background: #f8fafc;
    padding: 0.9rem;
}

.document-main {
    align-items: center;
    flex: 1;
    min-width: 0;
}

.document-icon {
    width: 42px;
    height: 42px;
    border-radius: 14px;
    background: rgba(14, 165, 233, 0.12);
    color: #0f6c8f;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.document-actions {
    flex-direction: column;
    align-items: flex-end;
}

.action-row {
    flex-wrap: wrap;
    justify-content: flex-end;
}

.info-row {
    padding: 0.8rem 0;
    border-bottom: 1px solid var(--border-color);
}

.info-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.skill-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.42rem 0.75rem;
    border-radius: 999px;
    background: rgba(14, 165, 233, 0.1);
    color: #0f6c8f;
    font-size: 0.8rem;
    font-weight: 700;
}

.empty-text {
    margin: 0;
    text-align: center;
    padding: 0.6rem 0;
}

@media (min-width: 760px) {
    .panel-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hero-metrics {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .hero-top,
    .document-card,
    .job-card,
    .section-heading,
    .hero-title-row {
        flex-direction: column;
    }

    .document-actions {
        width: 100%;
        align-items: stretch;
    }

    .action-row {
        justify-content: stretch;
    }

    .action-row .btn {
        width: 100%;
    }
}

.pending-banner {
    display: flex;
    gap: 0.85rem;
    align-items: flex-start;
    background: #fffbeb;
    border: 1px solid #fbbf24;
    border-radius: 8px;
    padding: 0.85rem 1rem;
    margin-bottom: 1rem;
    color: #78350f;
}
.pending-banner i { font-size: 1.1rem; margin-top: 3px; flex-shrink: 0; }
.pending-banner strong { display: block; margin-bottom: 0.2rem; }

.read-only-fields {
    margin-top: 1rem;
    padding: 0.6rem 0.75rem;
    background: #f9fafb;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
}
.read-only-fields summary {
    cursor: pointer;
    color: #6b7280;
    font-size: 0.85rem;
    font-weight: 500;
}
</style>
