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
                <Link href="/admin/rfq" class="nav-item">
                    <i class="fas fa-file-alt"></i><span>RFQ Management</span>
                </Link>
                <Link href="/admin/technicians" class="nav-item active">
                    <i class="fas fa-hard-hat"></i><span>Technicians</span>
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
                <h1>Technician Management</h1>
                <div class="header-actions">
                    <button @click="showCreateModal" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Add New Technician
                    </button>
                </div>
            </header>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>All Technicians</h3>
                        <div class="filter-controls">
                            <input
                                type="text"
                                v-model="searchQuery"
                                @input="filterTechnicians"
                                placeholder="Search by name or ID..."
                                style="margin-right: 1rem; padding: 0.5rem; border: 1px solid var(--border-color); border-radius: 4px;"
                            >
                            <select v-model="specializationFilter" @change="filterTechnicians" class="btn btn-secondary">
                                <option value="">All Specializations</option>
                                <option v-for="spec in uniqueSpecializations" :key="spec" :value="spec">{{ spec }}</option>
                            </select>
                        </div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name / ID</th>
                                <th>Specialization</th>
                                <th>Location</th>
                                <th>Skills</th>
                                <th>Availability</th>
                                <th>Rating</th>
                                <th>Total Jobs</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="technician in filteredTechnicians" :key="technician.id">
                                <td>
                                    {{ technician.user.name }}
                                    <span class="sub-text">{{ technician.technician_id }}</span>
                                </td>
                                <td>{{ technician.specialization }}</td>
                                <td>{{ technician.location }}</td>
                                <td>
                                    <div class="skills-list">
                                        <span
                                            v-for="skill in technician.skills?.slice(0, 3)"
                                            :key="skill"
                                            class="skill-tag"
                                        >
                                            {{ skill }}
                                        </span>
                                        <span v-if="technician.skills?.length > 3" class="skill-tag more">
                                            +{{ technician.skills.length - 3 }} more
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span :class="['status', technician.availability]">
                                        {{ formatAvailability(technician.availability) }}
                                    </span>
                                </td>
                                <td>{{ technician.rating || 0 }}/5</td>
                                <td>{{ technician.total_jobs || 0 }}</td>
                                <td>
                                    <div class="action-buttons">
                                        <button
                                            @click="viewTechnician(technician)"
                                            class="btn btn-secondary btn-sm"
                                        >
                                            View
                                        </button>
                                        <button
                                            @click="editTechnician(technician)"
                                            class="btn btn-secondary btn-sm"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            @click="deleteTechnician(technician)"
                                            class="btn btn-secondary btn-sm"
                                            style="color: var(--red);"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="filteredTechnicians.length === 0">
                                <td colspan="8" style="text-align: center; padding: 2rem; color: var(--medium-grey);">
                                    No technicians found matching your criteria
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <!-- Create/Edit Technician Modal -->
        <div v-if="showModal" class="modal-overlay" @click="closeModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>{{ isEditing ? 'Edit' : 'Add New' }} Technician</h3>
                    <button @click="closeModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <form @submit.prevent="saveTechnician">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Full Name*</label>
                                <input
                                    type="text"
                                    v-model="form.name"
                                    required
                                    placeholder="Enter full name"
                                >
                            </div>
                            <div class="form-group">
                                <label>Email*</label>
                                <input
                                    type="email"
                                    v-model="form.email"
                                    required
                                    placeholder="Enter email address"
                                >
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Phone</label>
                                <input
                                    type="tel"
                                    v-model="form.phone"
                                    placeholder="Enter phone number"
                                >
                            </div>
                            <div class="form-group">
                                <label>Technician ID</label>
                                <input
                                    type="text"
                                    v-model="form.technician_id"
                                    placeholder="Auto-generated if empty"
                                    :disabled="isEditing"
                                >
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Specialization*</label>
                                <select v-model="form.specialization" required>
                                    <option value="">Select specialization</option>
                                    <option value="Electrical">Electrical</option>
                                    <option value="Plumbing">Plumbing</option>
                                    <option value="HVAC">HVAC</option>
                                    <option value="Carpentry">Carpentry</option>
                                    <option value="Painting">Painting</option>
                                    <option value="Roofing">Roofing</option>
                                    <option value="Flooring">Flooring</option>
                                    <option value="General Maintenance">General Maintenance</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Location*</label>
                                <input
                                    type="text"
                                    v-model="form.location"
                                    required
                                    placeholder="Enter location/city"
                                >
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label>Skills (comma-separated)</label>
                            <input
                                type="text"
                                v-model="skillsInput"
                                placeholder="e.g. Wiring, Panel Installation, Troubleshooting"
                            >
                            <small style="color: var(--medium-grey); font-size: 0.8rem;">
                                Enter skills separated by commas
                            </small>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Availability</label>
                                <select v-model="form.availability">
                                    <option value="available">Available</option>
                                    <option value="busy">Busy</option>
                                    <option value="on_leave">On Leave</option>
                                </select>
                            </div>
                            <div class="form-group" v-if="!isEditing">
                                <label>Password*</label>
                                <input
                                    type="password"
                                    v-model="form.password"
                                    :required="!isEditing"
                                    placeholder="Enter password"
                                >
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label>Bio</label>
                            <textarea
                                v-model="form.bio"
                                rows="3"
                                placeholder="Brief description about the technician's experience and expertise"
                            ></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button @click="closeModal" class="btn btn-secondary">Cancel</button>
                    <button @click="saveTechnician" class="btn btn-primary">
                        {{ isEditing ? 'Update' : 'Create' }} Technician
                    </button>
                </div>
            </div>
        </div>

        <!-- View Technician Modal -->
        <div v-if="showViewModal" class="modal-overlay" @click="closeViewModal">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Technician Profile</h3>
                    <button @click="closeViewModal" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div v-if="viewingTechnician" class="technician-profile">
                        <div class="profile-header">
                            <h2>{{ viewingTechnician.user.name }}</h2>
                            <span class="technician-id">{{ viewingTechnician.technician_id }}</span>
                        </div>

                        <div class="profile-details">
                            <div class="detail-row">
                                <strong>Email:</strong> {{ viewingTechnician.user.email }}
                            </div>
                            <div class="detail-row">
                                <strong>Phone:</strong> {{ viewingTechnician.user.phone || 'Not provided' }}
                            </div>
                            <div class="detail-row">
                                <strong>Specialization:</strong> {{ viewingTechnician.specialization }}
                            </div>
                            <div class="detail-row">
                                <strong>Location:</strong> {{ viewingTechnician.location }}
                            </div>
                            <div class="detail-row">
                                <strong>Rating:</strong> {{ viewingTechnician.rating || 0 }}/5
                            </div>
                            <div class="detail-row">
                                <strong>Total Jobs:</strong> {{ viewingTechnician.total_jobs || 0 }}
                            </div>
                            <div class="detail-row">
                                <strong>Availability:</strong>
                                <span :class="['status', viewingTechnician.availability]">
                                    {{ formatAvailability(viewingTechnician.availability) }}
                                </span>
                            </div>
                        </div>

                        <div v-if="viewingTechnician.skills?.length" class="skills-section">
                            <h4>Skills</h4>
                            <div class="skills-list">
                                <span v-for="skill in viewingTechnician.skills" :key="skill" class="skill-tag">
                                    {{ skill }}
                                </span>
                            </div>
                        </div>

                        <div v-if="viewingTechnician.bio" class="bio-section">
                            <h4>Bio</h4>
                            <p>{{ viewingTechnician.bio }}</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="closeViewModal" class="btn btn-secondary">Close</button>
                    <button @click="editTechnician(viewingTechnician)" class="btn btn-primary">
                        Edit Technician
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    technicians: {
        type: Array,
        default: () => []
    }
})

const searchQuery = ref('')
const specializationFilter = ref('')
const showModal = ref(false)
const showViewModal = ref(false)
const isEditing = ref(false)
const viewingTechnician = ref(null)
const skillsInput = ref('')

const form = ref({
    name: '',
    email: '',
    phone: '',
    technician_id: '',
    specialization: '',
    location: '',
    availability: 'available',
    password: '',
    bio: '',
    skills: []
})

const uniqueSpecializations = computed(() => {
    const specializations = props.technicians.map(tech => tech.specialization).filter(Boolean)
    return [...new Set(specializations)].sort()
})

const filteredTechnicians = computed(() => {
    let filtered = props.technicians

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase()
        filtered = filtered.filter(tech =>
            tech.user.name.toLowerCase().includes(query) ||
            tech.technician_id.toLowerCase().includes(query) ||
            tech.user.email.toLowerCase().includes(query)
        )
    }

    if (specializationFilter.value) {
        filtered = filtered.filter(tech => tech.specialization === specializationFilter.value)
    }

    return filtered
})

// Watch skillsInput to update form.skills
watch(skillsInput, (newValue) => {
    if (newValue) {
        form.value.skills = newValue.split(',').map(skill => skill.trim()).filter(skill => skill)
    } else {
        form.value.skills = []
    }
})

const filterTechnicians = () => {
    // Reactive computed property handles this automatically
}

const formatAvailability = (availability) => {
    const availabilityMap = {
        'available': 'Available',
        'busy': 'Busy',
        'on_leave': 'On Leave'
    }
    return availabilityMap[availability] || availability
}

const showCreateModal = () => {
    isEditing.value = false
    resetForm()
    showModal.value = true
}

const editTechnician = (technician) => {
    isEditing.value = true
    form.value = {
        id: technician.id,
        name: technician.user.name,
        email: technician.user.email,
        phone: technician.user.phone || '',
        technician_id: technician.technician_id,
        specialization: technician.specialization,
        location: technician.location,
        availability: technician.availability,
        bio: technician.bio || '',
        skills: technician.skills || []
    }
    skillsInput.value = technician.skills?.join(', ') || ''
    showViewModal.value = false
    showModal.value = true
}

const viewTechnician = (technician) => {
    viewingTechnician.value = technician
    showViewModal.value = true
}

const deleteTechnician = (technician) => {
    if (confirm(`Are you sure you want to delete ${technician.user.name}? This action cannot be undone.`)) {
        router.delete(`/admin/technicians/${technician.id}`)
    }
}

const saveTechnician = () => {
    const url = isEditing.value ? `/admin/technicians/${form.value.id}` : '/admin/technicians'
    const method = isEditing.value ? 'put' : 'post'

    router[method](url, form.value, {
        onSuccess: () => {
            closeModal()
        }
    })
}

const resetForm = () => {
    form.value = {
        name: '',
        email: '',
        phone: '',
        technician_id: '',
        specialization: '',
        location: '',
        availability: 'available',
        password: '',
        bio: '',
        skills: []
    }
    skillsInput.value = ''
}

const closeModal = () => {
    showModal.value = false
    resetForm()
}

const closeViewModal = () => {
    showViewModal.value = false
    viewingTechnician.value = null
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

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
    max-width: 900px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--medium-grey);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.skills-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.skill-tag {
    background: var(--light-grey);
    color: var(--dark-grey);
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
    border: 1px solid var(--border-color);
}

.skill-tag.more {
    background: var(--primary-blue);
    color: white;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.technician-profile {
    max-width: 600px;
}

.profile-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.profile-header h2 {
    margin: 0;
    color: var(--dark-grey);
}

.technician-id {
    background: var(--light-grey);
    padding: 0.5rem 1rem;
    border-radius: 4px;
    font-weight: 600;
    color: var(--medium-grey);
}

.profile-details {
    margin-bottom: 1.5rem;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.detail-row strong {
    color: var(--dark-grey);
    min-width: 150px;
}

.skills-section,
.bio-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.skills-section h4,
.bio-section h4 {
    margin: 0 0 1rem 0;
    color: var(--dark-grey);
}

.bio-section p {
    margin: 0;
    line-height: 1.6;
    color: var(--medium-grey);
}
</style>