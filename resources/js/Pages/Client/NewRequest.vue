<template>
    <div class="dashboard-container client-pwa-shell">
        <ClientSidebar current-page="new-request" />
        <ClientBottomNav current-page="new-request" />

        <main class="main-content">
            <header class="main-header">
                <h1>Submit a New Service Request</h1>
                <Link href="/client/dashboard" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </Link>
            </header>

            <section class="panel-section">
                <form @submit.prevent="submitRequest" class="panel-card full-width rfq-form">
                    <div class="request-steps">
                        <article :class="['step-card', currentStep >= 1 ? 'step-card-active' : '']">
                            <span class="step-number">1</span>
                            <div>
                                <strong>Category</strong>
                                <p>Select the service type.</p>
                            </div>
                        </article>
                        <article :class="['step-card', currentStep >= 2 ? 'step-card-active' : '']">
                            <span class="step-number">2</span>
                            <div>
                                <strong>Details</strong>
                                <p>Add the work requirements.</p>
                            </div>
                        </article>
                        <article :class="['step-card', currentStep >= 3 ? 'step-card-active' : '']">
                            <span class="step-number">3</span>
                            <div>
                                <strong>Review</strong>
                                <p>Confirm and submit.</p>
                            </div>
                        </article>
                    </div>

                    <div v-if="submissionFeedback.message" :class="['request-feedback', `request-feedback-${submissionFeedback.type}`]">
                        <i :class="submissionFeedback.type === 'error' ? 'fas fa-circle-exclamation' : 'fas fa-circle-check'"></i>
                        <span>{{ submissionFeedback.message }}</span>
                    </div>

                    <div class="form-section">
                        <div class="card-header">
                            <h3>1. Service Categorization</h3>
                        </div>
                        <p class="form-description">
                            Choose the service category first. Once you continue, the selection stays locked until you explicitly change it.
                        </p>

                        <div v-if="currentStep === 1" class="category-select">
                            <button
                                v-for="category in availableCategories"
                                :key="category.id"
                                type="button"
                                :class="['category-item', { active: form.service_category_id === category.id }]"
                                @click="selectCategory(category.id)"
                            >
                                <i :class="category.icon"></i>
                                <span>{{ category.name }}</span>
                            </button>
                        </div>

                        <div v-else-if="selectedCategory" class="selected-category-card">
                            <div class="selected-category-copy">
                                <span class="selected-category-icon">
                                    <i :class="selectedCategory.icon"></i>
                                </span>
                                <div>
                                    <strong>{{ selectedCategory.name }}</strong>
                                    <p>Your request is now locked to this category for the remaining steps.</p>
                                </div>
                            </div>

                            <button type="button" class="btn btn-secondary" @click="unlockCategory" :disabled="form.processing">
                                Change Category
                            </button>
                        </div>

                        <div v-if="form.errors.service_category_id" class="error-message">
                            {{ form.errors.service_category_id }}
                        </div>

                        <div v-if="currentStep === 1" class="form-step-actions">
                            <button type="button" class="btn btn-primary" @click="goToDetailsStep" :disabled="form.processing">
                                Continue to Details
                            </button>
                        </div>
                    </div>

                    <div v-if="currentStep >= 2" class="form-section">
                        <div class="card-header">
                            <h3>2. Needs Assessment Form</h3>
                        </div>
                        <p class="form-description">Fill in the detailed information about the work required.</p>

                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="work-type">Type of work required</label>
                                <textarea
                                    id="work-type"
                                    v-model="form.description"
                                    rows="4"
                                    placeholder="e.g., Install 5 new power outlets and a ceiling fan. Check main fuse box."
                                    required
                                ></textarea>
                                <div v-if="form.errors.description" class="error-message">
                                    {{ form.errors.description }}
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="location">Project Location</label>
                                <input
                                    type="text"
                                    id="location"
                                    v-model="form.location"
                                    placeholder="e.g., Westlands, Nairobi"
                                    required
                                />
                                <div v-if="form.errors.location" class="error-message">
                                    {{ form.errors.location }}
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="urgency">Level of Urgency</label>
                                <select id="urgency" v-model="form.urgency" required>
                                    <option value="low">Low (within 48 hrs)</option>
                                    <option value="medium">Medium (within 24 hrs)</option>
                                    <option value="high">High (within 12 hrs)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="additional-notes">Additional Notes <span class="optional-tag">(optional)</span></label>
                                <textarea
                                    id="additional-notes"
                                    v-model="additionalNotes"
                                    rows="4"
                                    placeholder="e.g.&#10;• Site availability: Weekdays from 9am–4pm&#10;• Regulated working hours: No work after 6pm&#10;• Access restrictions: Lift only operates until 8pm&#10;• Building age, parking, security check-in, anything else the technician should know"
                                ></textarea>
                                <small class="form-hint">
                                    Include details such as site availability, regulated working hours, access restrictions, or any other relevant site conditions.
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group full-width">
                                <label>Upload Supporting Files (e.g., photos, drawings)</label>
                                <div class="file-upload-box" @click="triggerFileUpload" @drop.prevent="handleFileDrop" @dragover.prevent>
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Drag & drop files here or click to browse.</p>
                                    <input
                                        ref="fileInput"
                                        type="file"
                                        multiple
                                        accept="image/*,.pdf,.doc,.docx"
                                        @change="handleFileSelect"
                                        style="display: none;"
                                    />
                                </div>
                                <div v-if="filePreviews.length > 0" class="file-preview-grid">
                                    <div v-for="(preview, index) in filePreviews" :key="index" class="file-preview-tile">
                                        <img v-if="preview.src" :src="preview.src" :alt="preview.name" class="file-preview-img" />
                                        <div v-else class="file-preview-icon">
                                            <i :class="iconForType(preview.type)"></i>
                                        </div>
                                        <button type="button" @click="removeFile(index)" class="file-preview-remove" :disabled="isSubmitting" aria-label="Remove">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <div class="file-preview-meta">
                                            <span class="file-preview-name">{{ preview.name }}</span>
                                            <span class="file-preview-size">{{ preview.sizeKB }} KB</span>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="totalSizeLabel" class="file-size-hint">
                                    Total to upload: <strong>{{ totalSizeLabel }}</strong>
                                    <span v-if="selectedFiles.length">
                                        — photos will be auto-optimised before sending
                                    </span>
                                </p>
                            </div>
                        </div>

                        <div v-if="currentStep === 2" class="form-step-actions form-step-actions-split">
                            <button type="button" class="btn btn-secondary" @click="unlockCategory" :disabled="form.processing">
                                Back to Category
                            </button>
                            <button type="button" class="btn btn-primary" @click="goToReviewStep" :disabled="form.processing">
                                Continue to Review
                            </button>
                        </div>
                    </div>

                    <div v-if="currentStep === 3" class="form-section">
                        <div class="card-header">
                            <h3>3. Review & Submit</h3>
                        </div>
                        <p class="form-description">Review the summary below before dispatching your request.</p>

                        <div class="review-grid">
                            <article class="review-card">
                                <span class="review-label">Category</span>
                                <strong>{{ selectedCategory?.name || 'Not selected' }}</strong>
                            </article>
                            <article class="review-card">
                                <span class="review-label">Urgency</span>
                                <strong>{{ formatUrgency(form.urgency) }}</strong>
                            </article>
                            <article class="review-card">
                                <span class="review-label">Location</span>
                                <strong>{{ form.location || 'Not provided' }}</strong>
                            </article>
                            <article class="review-card">
                                <span class="review-label">Files</span>
                                <strong>{{ selectedFiles.length }} attached</strong>
                            </article>
                        </div>

                        <div class="review-note">
                            <span class="review-label">Work requested</span>
                            <p>{{ form.description || 'No description provided yet.' }}</p>
                        </div>

                        <div v-if="additionalNotes && additionalNotes.trim().length > 0" class="review-note">
                            <span class="review-label">Additional notes</span>
                            <p style="white-space: pre-wrap;">{{ additionalNotes }}</p>
                        </div>

                        <div class="form-actions">
                            <p>
                                Once submitted, your request is received as an RFQ and appears immediately in your active requests while our team reviews it.
                            </p>
                            <div class="form-step-actions form-step-actions-split">
                                <button type="button" class="btn btn-secondary" @click="currentStep = 2" :disabled="isSubmitting">
                                    Back to Details
                                </button>
                                <button type="submit" class="btn btn-primary btn-lg" :disabled="isSubmitting">
                                    <span v-if="!isSubmitting">Submit Request</span>
                                    <span v-else class="submit-loading">
                                        <i class="fas fa-spinner fa-spin"></i>
                                        {{ isProcessing ? 'Preparing photos…' : `Uploading… ${uploadProgress}%` }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </section>
        </main>

        <!-- Upload progress overlay — shows the user something is happening
             during the slow part. Backdrop blocks accidental double-clicks. -->
        <div v-if="isSubmitting" class="upload-overlay">
            <div class="upload-card">
                <div class="upload-spinner">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <h3>{{ stageMessage || 'Submitting your request…' }}</h3>
                <div class="upload-progress-bar">
                    <div class="upload-progress-fill" :style="{ width: (isProcessing ? 25 : Math.max(30, uploadProgress)) + '%' }"></div>
                </div>
                <p class="upload-hint">
                    Please keep this page open. We're sending your photos and details to our team.
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import ClientSidebar from '../../Components/ClientSidebar.vue'
import ClientBottomNav from '../../Components/ClientBottomNav.vue'
import { compressAll, totalBytes } from '../../composables/useImageCompression.js'
import { useFormAutosave } from '../../composables/useFormAutosave.js'

const props = defineProps({
    serviceCategories: {
        type: Array,
        default: () => [
            { id: 1, name: 'Electrical', icon: 'fas fa-bolt' },
            { id: 2, name: 'Plumbing', icon: 'fas fa-tint' },
            { id: 3, name: 'Painting', icon: 'fas fa-paint-roller' },
            { id: 4, name: 'Tiling', icon: 'fas fa-th' },
        ],
    },
})

const selectedFiles = ref([])
const filePreviews = ref([])           // [{ name, src (objectURL or null), sizeKB }]
const fileInput = ref(null)
const currentStep = ref(1)
const submissionFeedback = ref({
    type: '',
    message: '',
})

// Upload progress state
const isProcessing = ref(false)        // running compression
const isUploading = ref(false)         // bytes going over the wire
const uploadProgress = ref(0)          // 0-100
const stageMessage = ref('')           // status text shown to user

const availableCategories = computed(() => {
    return props.serviceCategories.length > 0 ? props.serviceCategories : [
        { id: 1, name: 'Electrical', icon: 'fas fa-bolt' },
        { id: 2, name: 'Plumbing', icon: 'fas fa-tint' },
        { id: 3, name: 'Painting', icon: 'fas fa-paint-roller' },
        { id: 4, name: 'Tiling', icon: 'fas fa-th' },
        { id: 5, name: 'Other', icon: 'fas fa-ellipsis-h' },
    ]
})

const form = useForm({
    service_category_id: null,
    description: '',
    location: '',
    urgency: 'low',
    files: [],
})

const additionalNotes = ref('')

// Auto-save text fields to localStorage so a client who navigates away or
// closes the tab can resume where they left off. Files are intentionally
// excluded — browsers can't rehydrate a file picker from JSON, so the client
// will still need to re-attach photos when they come back.
const { clear: clearDraft } = useFormAutosave('client-new-request', [
    { ref: form },
    { ref: additionalNotes },
], { exclude: ['files'] })

const selectedCategory = computed(() => {
    return availableCategories.value.find((category) => category.id === form.service_category_id) || null
})

const canContinueToDetails = computed(() => Boolean(form.service_category_id))

const canContinueToReview = computed(() => {
    return form.description.trim().length >= 10
        && form.location.trim().length > 0
        && Boolean(form.urgency)
})

function clearFeedback() {
    submissionFeedback.value = {
        type: '',
        message: '',
    }
}

function selectCategory(categoryId) {
    if (form.processing) return
    form.service_category_id = categoryId
    clearFeedback()
}

function goToDetailsStep() {
    if (!canContinueToDetails.value) {
        submissionFeedback.value = {
            type: 'error',
            message: 'Select a service category before continuing.',
        }
        return
    }

    clearFeedback()
    currentStep.value = 2
}

function unlockCategory() {
    if (form.processing) return
    clearFeedback()
    currentStep.value = 1
}

function goToReviewStep() {
    if (!canContinueToReview.value) {
        const missingItems = []

        if (form.description.trim().length < 10) {
            missingItems.push('add a more detailed description')
        }

        if (form.location.trim().length === 0) {
            missingItems.push('enter the project location')
        }

        if (!form.urgency) {
            missingItems.push('choose the urgency level')
        }

        submissionFeedback.value = {
            type: 'error',
            message: `Complete the request details first: ${missingItems.join(', ')}.`,
        }
        return
    }

    clearFeedback()
    currentStep.value = 3
}

async function submitRequest() {
    clearFeedback()

    // Append additional notes (site availability, hours, access, etc.) to description on submit
    const notes = additionalNotes.value.trim()
    if (notes.length > 0 && !form.description.includes('Additional Notes:')) {
        form.description = `${form.description.trim()}\n\nAdditional Notes:\n${notes}`
    }

    let filesToUpload = selectedFiles.value

    // STAGE 1 — compress images in the browser. Cuts a 5 MB iPhone photo
    // to ~400 KB; total upload time drops ~10× on 4G.
    if (filesToUpload.length > 0) {
        isProcessing.value = true
        const beforeBytes = totalBytes(filesToUpload)
        stageMessage.value = `Preparing ${filesToUpload.length} file${filesToUpload.length > 1 ? 's' : ''}…`

        filesToUpload = await compressAll(filesToUpload, (done, total) => {
            stageMessage.value = `Preparing photos… ${done} of ${total}`
        })

        const afterBytes = totalBytes(filesToUpload)
        const savedPct = beforeBytes > 0 ? Math.round((1 - afterBytes / beforeBytes) * 100) : 0
        if (savedPct >= 20) {
            stageMessage.value = `Photos optimised (${savedPct}% smaller). Uploading…`
        } else {
            stageMessage.value = 'Uploading…'
        }
        isProcessing.value = false
    }

    form.files = filesToUpload

    // STAGE 2 — upload. Inertia gives us a real progress callback we can
    // surface to the user so they see the bar move instead of staring at
    // a frozen button.
    isUploading.value = true
    uploadProgress.value = 0

    form.post(route('service-requests.store'), {
        preserveScroll: true,
        forceFormData: true,
        onProgress: (event) => {
            if (event?.percentage != null) {
                uploadProgress.value = event.percentage
                if (event.percentage < 100) {
                    stageMessage.value = `Uploading… ${event.percentage}%`
                } else {
                    stageMessage.value = 'Almost done — saving your request…'
                }
            }
        },
        onSuccess: () => {
            isUploading.value = false
            uploadProgress.value = 100
            stageMessage.value = ''
            // Draft has done its job — clear it so a fresh new-request
            // starts blank instead of restoring the just-submitted values.
            clearDraft()
        },
        onError: (errors) => {
            isUploading.value = false
            uploadProgress.value = 0
            stageMessage.value = ''
            submissionFeedback.value = {
                type: 'error',
                message: 'Request not submitted yet. Review the highlighted fields and try again.',
            }
            currentStep.value = errors.service_category_id ? 1 : 2
        },
        onFinish: () => {
            isUploading.value = false
        },
    })
}

function triggerFileUpload() {
    fileInput.value?.click()
}

function handleFileSelect(event) {
    const files = Array.from(event.target.files)
    addFiles(files)
}

function handleFileDrop(event) {
    const files = Array.from(event.dataTransfer.files)
    addFiles(files)
}

function addFiles(files) {
    selectedFiles.value = [...selectedFiles.value, ...files]
    // Generate previews so the user sees their photos immediately
    files.forEach((f) => {
        const sizeKB = Math.round(f.size / 1024)
        let src = null
        if (f.type.startsWith('image/')) {
            try { src = URL.createObjectURL(f) } catch {}
        }
        filePreviews.value.push({ name: f.name, src, sizeKB, type: f.type })
    })
    clearFeedback()
}

function removeFile(index) {
    selectedFiles.value.splice(index, 1)
    const removed = filePreviews.value.splice(index, 1)[0]
    if (removed?.src) {
        try { URL.revokeObjectURL(removed.src) } catch {}
    }
}

// Total upload size estimate (shown to user pre-upload)
const totalSizeLabel = computed(() => {
    const bytes = totalBytes(selectedFiles.value)
    if (bytes === 0) return ''
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`
})

const isSubmitting = computed(() => isProcessing.value || isUploading.value)

function iconForType(type) {
    if (!type) return 'fas fa-file'
    if (type.includes('pdf')) return 'fas fa-file-pdf'
    if (type.includes('word') || type.includes('document')) return 'fas fa-file-word'
    if (type.startsWith('image/')) return 'fas fa-image'
    return 'fas fa-file'
}

function formatUrgency(value) {
    return (value || 'low').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

defineOptions({
    layout: null,
})
</script>

<style>

.rfq-form {
    display: grid;
    gap: 1.5rem;
}

.request-steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.85rem;
}

.step-card {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 1rem 1.1rem;
    border-radius: 18px;
    border: 1px solid rgba(203, 213, 225, 0.9);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.step-card-active {
    border-color: rgba(5, 50, 114, 0.28);
    background: linear-gradient(135deg, rgba(219, 234, 254, 0.85), rgba(239, 246, 255, 0.96));
}

.step-card strong,
.step-card p {
    margin: 0;
}

.step-card p {
    color: #64748b;
    font-size: 0.88rem;
}

.step-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.08);
    color: #0f172a;
    font-weight: 800;
}

.step-card-active .step-number {
    background: #053272;
    color: #ffffff;
}

.request-feedback {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.1rem;
    border-radius: 18px;
    font-weight: 600;
}

.request-feedback-error {
    background: rgba(254, 242, 242, 0.96);
    border: 1px solid rgba(248, 113, 113, 0.24);
    color: #b91c1c;
}

.category-select {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.9rem;
}

.category-item {
    display: grid;
    justify-items: center;
    gap: 0.55rem;
    padding: 1.2rem 1rem;
    border-radius: 18px;
    border: 1px solid rgba(203, 213, 225, 0.95);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    color: #0f172a;
    cursor: pointer;
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.category-item:hover {
    transform: translateY(-1px);
    border-color: rgba(5, 50, 114, 0.25);
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
}

.category-item.active {
    border-color: rgba(5, 50, 114, 0.42);
    background: linear-gradient(135deg, rgba(219, 234, 254, 0.9), rgba(239, 246, 255, 0.96));
    color: #053272;
    box-shadow: 0 14px 30px rgba(5, 50, 114, 0.12);
}

.category-item i {
    font-size: 1.35rem;
}

.selected-category-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(239, 246, 255, 0.96), rgba(248, 250, 252, 0.96));
    border: 1px solid rgba(59, 130, 246, 0.18);
}

.selected-category-copy {
    display: flex;
    align-items: center;
    gap: 0.9rem;
}

.selected-category-copy strong,
.selected-category-copy p {
    margin: 0;
}

.selected-category-copy p {
    color: #64748b;
}

.selected-category-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 3rem;
    height: 3rem;
    border-radius: 999px;
    background: #053272;
    color: #ffffff;
    font-size: 1.2rem;
}

.form-step-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1.1rem;
}

.form-step-actions-split {
    justify-content: space-between;
    flex-wrap: wrap;
}

.review-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.review-card,
.review-note {
    padding: 1rem 1.05rem;
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    border: 1px solid rgba(226, 232, 240, 0.92);
}

.review-card strong,
.review-note p {
    margin: 0;
}

.review-label {
    display: block;
    margin-bottom: 0.35rem;
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.review-note p {
    color: #0f172a;
    line-height: 1.7;
    white-space: pre-wrap;
}

@media (max-width: 900px) {
    .review-grid {
        grid-template-columns: 1fr;
    }

    .selected-category-card {
        flex-direction: column;
        align-items: flex-start;
    }
}

@media (max-width: 640px) {
    .form-step-actions,
    .form-step-actions-split {
        flex-direction: column;
    }

    .form-step-actions .btn,
    .form-step-actions-split .btn {
        width: 100%;
    }
}

/* ─────────── Mobile (≤1023px) — senior-design polish ─────────── */
@media (max-width: 1023.98px) {
    .client-pwa-shell .main-content > .main-header {
        align-items: center;
        gap: 0.75rem;
        padding: 0;
        margin-bottom: 0.85rem;
    }

    .client-pwa-shell .main-content > .main-header h1 {
        font-size: 1.2rem;
        margin: 0;
        flex: 1;
    }

    .client-pwa-shell .main-content > .main-header .btn {
        width: 40px;
        height: 40px;
        padding: 0;
        border-radius: 12px;
        font-size: 0;
    }

    .client-pwa-shell .main-content > .main-header .btn::before {
        content: "\f053"; /* fa-chevron-left */
        font-family: "Font Awesome 5 Free", "Font Awesome 6 Free";
        font-weight: 900;
        font-size: 0.9rem;
    }

    .client-pwa-shell .main-content > .main-header .btn i {
        display: none;
    }

    .client-pwa-shell .rfq-form {
        gap: 1rem;
    }

    .client-pwa-shell .panel-section > .panel-card.rfq-form {
        padding: 1.1rem;
        border-radius: 20px;
    }

    /* ── Compact step indicator ─────────────── */
    .client-pwa-shell .request-steps {
        grid-template-columns: repeat(3, 1fr);
        gap: 0.4rem;
        padding: 0.45rem;
        border-radius: 14px;
        background: #f1f5f9;
    }

    .client-pwa-shell .step-card {
        flex-direction: column;
        align-items: center;
        gap: 0.3rem;
        padding: 0.6rem 0.35rem;
        border: 0;
        background: transparent;
        text-align: center;
    }

    .client-pwa-shell .step-card-active {
        background: #ffffff;
        box-shadow: 0 4px 10px rgba(15, 23, 42, 0.05);
    }

    .client-pwa-shell .step-number {
        width: 1.6rem;
        height: 1.6rem;
        font-size: 0.85rem;
    }

    .client-pwa-shell .step-card strong {
        font-size: 0.78rem;
        color: #64748b;
        font-weight: 600;
    }

    .client-pwa-shell .step-card-active strong {
        color: #053272;
    }

    .client-pwa-shell .step-card p {
        display: none;
    }

    /* ── Form sections ──────────────────────── */
    .client-pwa-shell .form-section {
        padding: 0;
    }

    .client-pwa-shell .form-section .card-header h3 {
        font-size: 1.02rem;
        margin: 0;
    }

    .client-pwa-shell .form-description {
        font-size: 0.85rem;
        line-height: 1.5;
        margin: 0.25rem 0 0.85rem;
        color: #64748b;
    }

    /* ── Category tiles ─────────────────────── */
    .client-pwa-shell .category-select {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.55rem;
    }

    .client-pwa-shell .category-item {
        padding: 1rem 0.5rem;
        border-radius: 14px;
        gap: 0.45rem;
    }

    .client-pwa-shell .category-item i {
        font-size: 1.5rem;
        color: #053272;
    }

    .client-pwa-shell .category-item span {
        font-size: 0.82rem;
        font-weight: 600;
    }

    .client-pwa-shell .selected-category-card {
        padding: 0.85rem;
        border-radius: 16px;
        gap: 0.75rem;
    }

    .client-pwa-shell .selected-category-icon {
        width: 2.4rem;
        height: 2.4rem;
        font-size: 1rem;
    }

    .client-pwa-shell .selected-category-copy strong {
        font-size: 0.95rem;
    }

    .client-pwa-shell .selected-category-copy p {
        font-size: 0.82rem;
        line-height: 1.4;
    }

    /* ── Inputs ────────────────────────────── */
    .client-pwa-shell .form-row {
        display: grid;
        gap: 0.85rem;
        margin-bottom: 0.85rem;
    }

    .client-pwa-shell .form-group {
        display: grid;
        gap: 0.35rem;
    }

    .client-pwa-shell .form-group label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .client-pwa-shell .form-group input,
    .client-pwa-shell .form-group select {
        height: 48px;
        padding: 0 0.95rem;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        font-size: 0.95rem;
        color: #0f172a;
        -webkit-appearance: none;
        appearance: none;
    }

    .client-pwa-shell .form-group textarea {
        padding: 0.85rem 0.95rem;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        font-size: 0.95rem;
        color: #0f172a;
        font-family: inherit;
        line-height: 1.5;
        min-height: 110px;
        resize: vertical;
    }

    .client-pwa-shell .form-group input:focus,
    .client-pwa-shell .form-group select:focus,
    .client-pwa-shell .form-group textarea:focus {
        outline: none;
        border-color: #053272;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(5, 50, 114, 0.1);
    }

    /* ── File upload ─────────────────────────── */
    .client-pwa-shell .file-upload-box {
        padding: 1.25rem 1rem;
        border-radius: 14px;
        border: 1.5px dashed #cbd5e1;
        background: #f8fafc;
        text-align: center;
    }

    .client-pwa-shell .file-upload-box i {
        font-size: 1.5rem;
        color: #053272;
    }

    .client-pwa-shell .file-upload-box p {
        margin: 0.4rem 0 0;
        font-size: 0.85rem;
        color: #64748b;
    }

    .client-pwa-shell .selected-files {
        margin-top: 0.65rem;
        display: grid;
        gap: 0.45rem;
    }

    .client-pwa-shell .file-item {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.55rem 0.75rem;
        border-radius: 10px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        font-size: 0.85rem;
    }

    /* ── Review step ─────────────────────────── */
    .client-pwa-shell .review-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.55rem;
    }

    .client-pwa-shell .review-card,
    .client-pwa-shell .review-note {
        padding: 0.85rem;
        border-radius: 14px;
    }

    .client-pwa-shell .review-label {
        font-size: 0.7rem;
    }

    .client-pwa-shell .review-card strong {
        font-size: 0.95rem;
    }

    .client-pwa-shell .review-note p {
        font-size: 0.9rem;
        line-height: 1.55;
    }

    /* ── Step actions ─────────────────────────── */
    .client-pwa-shell .form-step-actions {
        display: grid;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    .client-pwa-shell .form-step-actions-split {
        grid-template-columns: 1fr 1.4fr;
    }

    .client-pwa-shell .form-step-actions .btn {
        width: 100%;
        height: 48px;
        border-radius: 12px;
        font-weight: 700;
        justify-content: center;
    }

    .client-pwa-shell .form-actions p {
        font-size: 0.85rem;
        color: #64748b;
        line-height: 1.5;
    }

    /* ── Feedback banner ─────────────────────── */
    .client-pwa-shell .request-feedback {
        padding: 0.85rem 1rem;
        border-radius: 14px;
        font-size: 0.88rem;
    }
}

.optional-tag {
    font-weight: 500;
    font-size: 0.78rem;
    color: #64748b;
    margin-left: 0.35rem;
}

.form-hint {
    display: block;
    font-size: 0.78rem;
    color: #64748b;
    margin-top: 0.4rem;
    line-height: 1.5;
}

/* === File preview thumbnails === */
.file-preview-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
    gap: 0.7rem;
    margin-top: 0.75rem;
}
.file-preview-tile {
    position: relative;
    aspect-ratio: 1 / 1;
    border-radius: 8px;
    overflow: hidden;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.file-preview-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.file-preview-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #9ca3af;
    font-size: 1.8rem;
}
.file-preview-remove {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 0;
    background: rgba(0,0,0,0.65);
    color: white;
    cursor: pointer;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.file-preview-remove:hover:not(:disabled) {
    background: #dc2626;
}
.file-preview-remove:disabled { opacity: 0.4; cursor: not-allowed; }
.file-preview-meta {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(transparent, rgba(0,0,0,0.75));
    color: white;
    padding: 1rem 0.4rem 0.35rem;
    display: flex;
    justify-content: space-between;
    font-size: 0.68rem;
    align-items: flex-end;
}
.file-preview-name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 70%;
}
.file-preview-size { opacity: 0.85; }
.file-size-hint { font-size: 0.78rem; color: #6b7280; margin-top: 0.5rem; }

/* === Submit button inline spinner === */
.submit-loading {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

/* === Full-page upload overlay === */
.upload-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.upload-card {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    text-align: center;
    max-width: 380px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    animation: upload-pop 0.25s ease-out;
}
@keyframes upload-pop {
    from { transform: scale(0.92); opacity: 0; }
    to   { transform: scale(1);    opacity: 1; }
}
.upload-spinner {
    font-size: 2.4rem;
    color: #053272;
    margin-bottom: 0.75rem;
    animation: upload-float 1.6s ease-in-out infinite;
}
@keyframes upload-float {
    0%, 100% { transform: translateY(0); }
    50%      { transform: translateY(-6px); }
}
.upload-card h3 {
    margin: 0 0 1rem;
    color: #1e293b;
    font-size: 1rem;
    font-weight: 600;
}
.upload-progress-bar {
    background: #e5e7eb;
    height: 8px;
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 0.75rem;
}
.upload-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #053272, #2563eb);
    border-radius: 999px;
    transition: width 0.4s ease;
    box-shadow: 0 0 8px rgba(37, 99, 235, 0.4);
}
.upload-hint { font-size: 0.82rem; color: #6b7280; margin: 0; }
</style>
