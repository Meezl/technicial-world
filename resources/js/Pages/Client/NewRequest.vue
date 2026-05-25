<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="new-request" />

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
                                <div v-if="selectedFiles.length > 0" class="selected-files">
                                    <div v-for="(file, index) in selectedFiles" :key="index" class="file-item">
                                        <i class="fas fa-file"></i>
                                        <span>{{ file.name }}</span>
                                        <button type="button" @click="removeFile(index)" class="remove-file">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
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

                        <div class="form-actions">
                            <p>
                                Once submitted, your request is received as an RFQ and appears immediately in your active requests while our team reviews it.
                            </p>
                            <div class="form-step-actions form-step-actions-split">
                                <button type="button" class="btn btn-secondary" @click="currentStep = 2" :disabled="form.processing">
                                    Back to Details
                                </button>
                                <button type="submit" class="btn btn-primary btn-lg" :disabled="form.processing">
                                    {{ form.processing ? 'Submitting Request...' : 'Submit Request' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import ClientSidebar from '../../Components/ClientSidebar.vue'

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
const fileInput = ref(null)
const currentStep = ref(1)
const submissionFeedback = ref({
    type: '',
    message: '',
})

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

function submitRequest() {
    clearFeedback()
    form.files = selectedFiles.value

    form.post(route('service-requests.store'), {
        preserveScroll: true,
        onError: (errors) => {
            submissionFeedback.value = {
                type: 'error',
                message: 'Request not submitted yet. Review the highlighted fields and try again.',
            }

            currentStep.value = errors.service_category_id ? 1 : 2
        },
    })
}

function triggerFileUpload() {
    fileInput.value?.click()
}

function handleFileSelect(event) {
    const files = Array.from(event.target.files)
    selectedFiles.value = [...selectedFiles.value, ...files]
    clearFeedback()
}

function handleFileDrop(event) {
    const files = Array.from(event.dataTransfer.files)
    selectedFiles.value = [...selectedFiles.value, ...files]
    clearFeedback()
}

function removeFile(index) {
    selectedFiles.value.splice(index, 1)
}

function formatUrgency(value) {
    return (value || 'low').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

defineOptions({
    layout: null,
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

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
    .request-steps,
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
</style>
