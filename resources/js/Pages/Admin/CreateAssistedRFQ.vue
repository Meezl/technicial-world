<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="rfq" />

        <main class="main-content assisted-rfq-page">
            <section class="assisted-hero">
                <div class="hero-copy">
                    <span class="hero-kicker">Admin Assisted RFQ</span>
                    <h1>Create a service request for an existing client</h1>
                    <p>Use this for walk-ins, offline requests, or urgent jobs where admin needs to capture the request while keeping the client as the real owner of the service journey.</p>
                </div>

                <div class="hero-note-card">
                    <span class="section-kicker">Workflow Guardrails</span>
                    <h3>Parallel to the normal client path</h3>
                    <ul class="hero-note-list">
                        <li>The selected client remains the owner of the request.</li>
                        <li>The request is clearly marked as admin-assisted for review and reporting.</li>
                        <li>Quotations can only be proxy-approved later from the admin RFQ queue.</li>
                    </ul>
                </div>
            </section>

            <section class="panel-section">
                <form @submit.prevent="submitRequest" class="panel-card assisted-form">
                    <div class="form-block">
                        <div class="block-heading">
                            <span class="section-kicker">Client</span>
                            <h3>Choose the request owner</h3>
                            <p>Select an existing client account or create a new one on the spot — no need to leave this page.</p>
                        </div>

                        <!-- Mode toggle -->
                        <div class="client-mode-toggle">
                            <button
                                type="button"
                                :class="['mode-btn', { active: clientMode === 'existing' }]"
                                @click="clientMode = 'existing'"
                            >
                                <i class="fas fa-user-check"></i> Existing client
                            </button>
                            <button
                                type="button"
                                :class="['mode-btn', { active: clientMode === 'new' }]"
                                @click="clientMode = 'new'"
                            >
                                <i class="fas fa-user-plus"></i> New client
                            </button>
                        </div>

                        <!-- Existing client picker -->
                        <template v-if="clientMode === 'existing'">
                            <label class="form-field">
                                <span>Client account</span>
                                <select v-model="form.user_id" class="form-control" :required="clientMode === 'existing'">
                                    <option value="">Select client...</option>
                                    <option v-for="client in clients" :key="client.id" :value="client.id">
                                        {{ client.name }} • {{ client.email }}
                                    </option>
                                </select>
                                <small v-if="selectedClient" class="field-note">
                                    {{ selectedClient.email }}{{ selectedClient.phone ? ` • ${selectedClient.phone}` : '' }}
                                </small>
                                <small v-else class="field-note">Search from all registered client accounts.</small>
                                <div v-if="form.errors.user_id" class="error-message">{{ form.errors.user_id }}</div>
                            </label>
                        </template>

                        <!-- New client fields -->
                        <template v-else>
                            <div class="new-client-notice">
                                <i class="fas fa-info-circle"></i>
                                A new client account will be created with a temporary password. The client can reset it on first login.
                            </div>
                            <div class="form-row">
                                <label class="form-field">
                                    <span>Full name *</span>
                                    <input
                                        v-model="form.new_client.name"
                                        type="text"
                                        class="form-control"
                                        placeholder="e.g. Acme Ltd or John Kamau"
                                        required
                                    />
                                    <div v-if="form.errors['new_client.name']" class="error-message">{{ form.errors['new_client.name'] }}</div>
                                </label>
                                <label class="form-field">
                                    <span>Email address *</span>
                                    <input
                                        v-model="form.new_client.email"
                                        type="email"
                                        class="form-control"
                                        placeholder="client@example.com"
                                        required
                                    />
                                    <div v-if="form.errors['new_client.email']" class="error-message">{{ form.errors['new_client.email'] }}</div>
                                </label>
                            </div>
                            <label class="form-field">
                                <span>Phone number (optional)</span>
                                <input
                                    v-model="form.new_client.phone"
                                    type="tel"
                                    class="form-control"
                                    placeholder="+254 7XX XXX XXX"
                                />
                            </label>
                        </template>
                    </div>

                    <div class="form-block">
                        <div class="block-heading">
                            <span class="section-kicker">Service</span>
                            <h3>Capture the request details</h3>
                            <p>These fields mirror the normal client request journey so downstream handling stays unchanged.</p>
                        </div>

                        <div class="form-row">
                            <label class="form-field">
                                <span>Service category</span>
                                <select v-model="form.service_category_id" class="form-control" required>
                                    <option value="">Select category...</option>
                                    <option v-for="category in serviceCategories" :key="category.id" :value="category.id">
                                        {{ category.name }}
                                    </option>
                                </select>
                                <div v-if="form.errors.service_category_id" class="error-message">{{ form.errors.service_category_id }}</div>
                            </label>

                            <label class="form-field">
                                <span>Urgency</span>
                                <select v-model="form.urgency" class="form-control" required>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </label>
                        </div>

                        <label class="form-field">
                            <span>Location</span>
                            <input v-model="form.location" type="text" class="form-control" placeholder="Enter project location" required>
                            <div v-if="form.errors.location" class="error-message">{{ form.errors.location }}</div>
                        </label>

                        <label class="form-field">
                            <span>Description of work required</span>
                            <textarea
                                v-model="form.description"
                                rows="5"
                                class="form-control"
                                placeholder="Describe the work to be quoted and reviewed."
                                required
                            ></textarea>
                            <div v-if="form.errors.description" class="error-message">{{ form.errors.description }}</div>
                        </label>

                        <label class="form-field">
                            <span>Supporting files</span>
                            <input ref="fileInput" type="file" class="form-control" multiple accept="image/*,.pdf,.doc,.docx" @change="handleFileSelect">
                            <small class="field-note">Photos, sketches, PDFs, or client-supplied notes can be attached here.</small>
                            <div v-if="selectedFiles.length" class="selected-files">
                                <div v-for="(file, index) in selectedFiles" :key="`${file.name}-${index}`" class="selected-file-row">
                                    <span>{{ file.name }}</span>
                                    <button type="button" class="remove-link" @click="removeFile(index)">Remove</button>
                                </div>
                            </div>
                            <div v-if="form.errors.files" class="error-message">{{ form.errors.files }}</div>
                        </label>
                    </div>

                    <div class="form-actions">
                        <Link href="/admin/rfq" class="btn btn-secondary">Cancel</Link>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            {{ form.processing ? 'Creating...' : 'Create Assisted RFQ' }}
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { Link, useForm } from '@inertiajs/vue3'
import AdminSidebar from '../../Components/AdminSidebar.vue'

const props = defineProps({
    clients: { type: Array, default: () => [] },
    serviceCategories: { type: Array, default: () => [] },
})

const clientMode = ref('existing')
const selectedFiles = ref([])
const fileInput = ref(null)

const form = useForm({
    client_mode: 'existing',
    user_id: '',
    new_client: {
        name: '',
        email: '',
        phone: '',
    },
    service_category_id: '',
    description: '',
    location: '',
    urgency: 'medium',
    files: [],
})

const selectedClient = computed(() => {
    return props.clients.find((client) => Number(client.id) === Number(form.user_id)) || null
})

const handleFileSelect = (event) => {
    selectedFiles.value = Array.from(event.target.files || [])
}

const removeFile = (index) => {
    selectedFiles.value.splice(index, 1)
}

const submitRequest = () => {
    form.client_mode = clientMode.value
    form.files = selectedFiles.value
    form.post('/admin/rfq/create')
}

defineOptions({ layout: null })
</script>

<style>
@import url('../../../css/dashboard-app.css');

.assisted-rfq-page {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.1), transparent 24rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.assisted-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(300px, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.hero-copy,
.hero-note-card,
.assisted-form {
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.hero-copy {
    padding: 2rem;
    border-radius: 26px;
    background: linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92)), #00334b;
    color: #f8fafc;
}

.hero-kicker,
.section-kicker {
    display: inline-flex;
    align-items: center;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.hero-kicker { color: rgba(226, 232, 240, 0.82); }
.section-kicker { color: #0f6c8f; }

.hero-copy h1,
.hero-note-card h3,
.block-heading h3 {
    margin: 0.85rem 0 0;
}

.hero-copy p,
.block-heading p {
    margin: 0.9rem 0 0;
    line-height: 1.6;
}

.hero-note-card {
    padding: 1.6rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.92);
}

.hero-note-list {
    margin: 1rem 0 0;
    padding-left: 1.1rem;
    color: #475569;
    display: grid;
    gap: 0.55rem;
}

.assisted-form {
    padding: 1.5rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.96);
    display: grid;
    gap: 1.5rem;
}

.form-block {
    display: grid;
    gap: 1rem;
}

.block-heading h3 {
    color: #0f172a;
}

.block-heading p {
    color: #64748b;
}

.form-row {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.form-field {
    display: grid;
    gap: 0.45rem;
}

.form-field span {
    font-weight: 600;
    color: #0f172a;
}

.form-control {
    width: 100%;
    padding: 0.85rem 1rem;
    border-radius: 14px;
    border: 1px solid #d7dee7;
    background: #f8fafc;
    color: #0f172a;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: rgba(14, 116, 144, 0.45);
    box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12);
    background: #ffffff;
}

.field-note {
    color: #64748b;
    font-size: 0.82rem;
}

.selected-files {
    display: grid;
    gap: 0.45rem;
}

.selected-file-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 0.75rem 0.9rem;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.remove-link {
    border: none;
    background: transparent;
    color: #b91c1c;
    font-weight: 600;
    cursor: pointer;
}

.client-mode-toggle {
    display: flex;
    gap: 0.5rem;
    background: #f1f5f9;
    border-radius: 14px;
    padding: 0.35rem;
    width: fit-content;
}

.mode-btn {
    padding: 0.55rem 1.1rem;
    border: none;
    border-radius: 10px;
    background: transparent;
    color: #64748b;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    transition: background 0.18s, color 0.18s;
}

.mode-btn.active {
    background: #ffffff;
    color: #0f172a;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.1);
}

.mode-btn:hover:not(.active) {
    color: #0f172a;
    background: rgba(255, 255, 255, 0.6);
}

.new-client-notice {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.8rem 1rem;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    color: #1e40af;
    font-size: 0.88rem;
    line-height: 1.5;
}

.new-client-notice i {
    margin-top: 0.1rem;
    flex-shrink: 0;
}

.error-message {
    color: #b91c1c;
    font-size: 0.82rem;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

@media (max-width: 980px) {
    .assisted-hero,
    .form-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
    }
}
</style>
