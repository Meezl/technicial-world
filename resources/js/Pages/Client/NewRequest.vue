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
                    <div class="form-section">
                        <div class="card-header">
                            <h3>1. Service Categorization</h3>
                        </div>
                        <p class="form-description">Select the service category you require.</p>
                        <div class="category-select">
                            <div
                                v-for="category in availableCategories"
                                :key="category.id"
                                :class="['category-item', { active: form.service_category_id === category.id }]"
                                @click="form.service_category_id = category.id"
                            >
                                <i :class="category.icon"></i>
                                <span>{{ category.name }}</span>
                            </div>
                        </div>
                        <div v-if="form.errors.service_category_id" class="error-message">
                            {{ form.errors.service_category_id }}
                        </div>
                    </div>

                    <div class="form-section">
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
                                    placeholder="e.g., Westlands, Nairobi (Google Maps will be integrated for accuracy)"
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
                    </div>

                    <div class="form-actions">
                        <p>Upon submission, your input will be received as a Request for Quotation (RFQ). Our technical team will review it and may contact you for clarification.</p>
                        <button type="submit" class="btn btn-primary btn-lg" :disabled="form.processing">
                            {{ form.processing ? 'Submitting Request...' : 'Submit Request' }}
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import ClientSidebar from '../../Components/ClientSidebar.vue'

const props = defineProps({
    serviceCategories: {
        type: Array,
        default: () => [
            { id: 1, name: 'Electrical', icon: 'fas fa-bolt' },
            { id: 2, name: 'Plumbing', icon: 'fas fa-tint' },
            { id: 3, name: 'Painting', icon: 'fas fa-paint-roller' },
            { id: 4, name: 'Tiling', icon: 'fas fa-th' },
        ]
    }
})

const selectedFiles = ref([])
const fileInput = ref(null)

const availableCategories = computed(() => {
    return props.serviceCategories.length > 0 ? props.serviceCategories : [
        { id: 1, name: 'Electrical', icon: 'fas fa-bolt' },
        { id: 2, name: 'Plumbing', icon: 'fas fa-tint' },
        { id: 3, name: 'Painting', icon: 'fas fa-paint-roller' },
        { id: 4, name: 'Tiling', icon: 'fas fa-th' },
        { id: 5, name: 'Other', icon: 'fas fa-ellipsis-h' }
    ]
})

const form = useForm({
    service_category_id: null,
    description: '',
    location: '',
    urgency: 'low',
    files: []
})

const submitRequest = () => {
    form.files = selectedFiles.value
    form.post(route('service-requests.store'), {
        onSuccess: () => {
            // Redirect will be handled by Inertia
        }
    })
}

const triggerFileUpload = () => {
    fileInput.value?.click()
}

const handleFileSelect = (event) => {
    const files = Array.from(event.target.files)
    selectedFiles.value = [...selectedFiles.value, ...files]
}

const handleFileDrop = (event) => {
    const files = Array.from(event.dataTransfer.files)
    selectedFiles.value = [...selectedFiles.value, ...files]
}

const removeFile = (index) => {
    selectedFiles.value.splice(index, 1)
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');
</style>

