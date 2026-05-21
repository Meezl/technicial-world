<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="users" />

        <main class="main-content">
            <header class="main-header">
                <h1>User Management</h1>
                <div class="header-actions">
                    <button @click="showCreateModal = true" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add New User
                    </button>
                </div>
            </header>

            <!-- User Statistics -->
            <section class="stats-section">
                <div class="stat-card">
                    <h4>Total Users</h4>
                    <p class="stat-value">{{ stats.total || '0' }}</p>
                    <span class="stat-icon total"><i class="fas fa-users"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Clients</h4>
                    <p class="stat-value">{{ stats.clients || '0' }}</p>
                    <span class="stat-icon clients"><i class="fas fa-user"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Technicians</h4>
                    <p class="stat-value">{{ stats.technicians || '0' }}</p>
                    <span class="stat-icon technicians"><i class="fas fa-hard-hat"></i></span>
                </div>
                <div class="stat-card">
                    <h4>Admins</h4>
                    <p class="stat-value">{{ stats.admins || '0' }}</p>
                    <span class="stat-icon admins"><i class="fas fa-user-shield"></i></span>
                </div>
            </section>

            <!-- Users List -->
            <section class="users-section">
                <div class="panel-card">
                    <div class="card-header">
                        <h3>All Users</h3>
                        <div class="filters">
                            <select v-model="roleFilter" class="filter-select">
                                <option value="all">All Roles</option>
                                <option value="client">Clients</option>
                                <option value="technician">Technicians</option>
                                <option value="admin">Admins</option>
                            </select>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input v-model="searchTerm" type="text" placeholder="Search users...">
                            </div>
                        </div>
                    </div>

                    <div class="users-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Phone</th>
                                    <th>Joined</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="user in users.data" :key="user.id">
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="user-details">
                                                <strong>{{ user.name }}</strong>
                                                <small v-if="user.technician">{{ user.technician.specialization }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>{{ user.email }}</td>
                                    <td>
                                        <span :class="['role-badge', user.role]">
                                            {{ formatRole(user.role) }}
                                        </span>
                                    </td>
                                    <td>{{ user.phone || 'N/A' }}</td>
                                    <td>{{ formatDate(user.created_at) }}</td>
                                    <td>
                                        <span :class="['status-badge', user.email_verified_at ? 'verified' : 'pending']">
                                            {{ user.email_verified_at ? 'Verified' : 'Pending' }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button @click="viewUser(user)" class="btn btn-sm btn-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button @click="editUser(user)" class="btn btn-sm btn-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button v-if="user.role !== 'admin'" @click="deleteUser(user)" class="btn btn-sm btn-danger" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="users.data.length === 0" class="no-data">
                            <i class="fas fa-users"></i>
                            <p>No users found</p>
                        </div>

                        <!-- Pagination -->
                        <div class="pagination-container" v-if="users.links.length > 3">
                            <div class="pagination">
                                <Component
                                    :is="link.url ? Link : 'span'"
                                    v-for="(link, index) in users.links"
                                    :key="index"
                                    :href="link.url"
                                    v-html="link.label"
                                    class="page-link"
                                    :class="{ 'active': link.active, 'disabled': !link.url }"
                                    preserve-scroll
                                />
                            </div>
                            <div class="pagination-info">
                                Showing {{ users.from }} to {{ users.to }} of {{ users.total }} results
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>


    <!-- Create/Edit User Modal -->
    <div v-if="showCreateModal || showEditModal" class="modal-overlay" @click="closeModals">
        <div class="modal-content large" @click.stop>
            <div class="modal-header">
                <h3>{{ showCreateModal ? 'Add New User' : 'Edit User' }}</h3>
                <button @click="closeModals" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <form @submit.prevent="submitUser">
                    <div class="form-sections">
                        <!-- Basic Information -->
                        <div class="form-section">
                            <h4><i class="fas fa-user"></i> Basic Information</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Full Name *</label>
                                    <input v-model="userForm.name" type="text" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Email Address *</label>
                                    <input v-model="userForm.email" type="email" class="form-control" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Phone Number</label>
                                    <input v-model="userForm.phone" type="tel" class="form-control" placeholder="+254 700 000 000">
                                </div>
                                <div class="form-group">
                                    <label>Role *</label>
                                    <select v-model="userForm.role" class="form-control" required @change="onRoleChange">
                                        <option value="">Select Role</option>
                                        <option value="client">Client</option>
                                        <option value="technician">Technician</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row" v-if="showCreateModal">
                                <div class="form-group">
                                    <label>Password *</label>
                                    <input v-model="userForm.password" type="password" class="form-control" :required="showCreateModal">
                                </div>
                                <div class="form-group">
                                    <label>Confirm Password *</label>
                                    <input v-model="userForm.password_confirmation" type="password" class="form-control" :required="showCreateModal">
                                </div>
                            </div>
                        </div>

                        <!-- Technician Specific Fields -->
                        <div v-if="userForm.role === 'technician'" class="form-section">
                            <h4><i class="fas fa-hard-hat"></i> Technician Details</h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Specialization *</label>
                                    <select v-model="userForm.specialization" class="form-control" :required="userForm.role === 'technician'">
                                        <option value="">Select Specialization</option>
                                        <option value="Electrical">Electrical</option>
                                        <option value="Plumbing">Plumbing</option>
                                        <option value="HVAC">HVAC</option>
                                        <option value="Carpentry">Carpentry</option>
                                        <option value="Painting">Painting</option>
                                        <option value="Roofing">Roofing</option>
                                        <option value="General Maintenance">General Maintenance</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Location *</label>
                                    <input v-model="userForm.location" type="text" class="form-control" :required="userForm.role === 'technician'" placeholder="City/Area">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Availability</label>
                                    <select v-model="userForm.availability" class="form-control">
                                        <option value="available">Available</option>
                                        <option value="busy">Busy</option>
                                        <option value="on_leave">On Leave</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Skills</label>
                                    <input v-model="skillsInput" type="text" class="form-control" placeholder="Enter skills separated by commas">
                                    <small class="form-text">Separate multiple skills with commas</small>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Bio</label>
                                <textarea v-model="userForm.bio" class="form-control" rows="3" placeholder="Brief description of experience and expertise..."></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <button @click="closeModals" class="btn btn-secondary">Cancel</button>
                <button @click="submitUser" class="btn btn-primary" :disabled="submitting">
                    <i class="fas fa-save"></i> {{ submitting ? 'Saving...' : (showCreateModal ? 'Create User' : 'Update User') }}
                </button>
            </div>
        </div>
    </div>

    <!-- View User Modal -->
    <div v-if="showViewModal" class="modal-overlay" @click="closeViewModal">
        <div class="modal-content" @click.stop>
            <div class="modal-header">
                <h3>User Details</h3>
                <button @click="closeViewModal" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="user-profile" v-if="selectedUser">
                    <div class="profile-header">
                        <div class="profile-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="profile-info">
                            <h4>{{ selectedUser.name }}</h4>
                            <p class="role">{{ formatRole(selectedUser.role) }}</p>
                            <p class="email">{{ selectedUser.email }}</p>
                        </div>
                    </div>

                    <div class="profile-details">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label>Phone:</label>
                                <span>{{ selectedUser.phone || 'Not provided' }}</span>
                            </div>
                            <div class="detail-item">
                                <label>Joined:</label>
                                <span>{{ formatDate(selectedUser.created_at) }}</span>
                            </div>
                            <div class="detail-item">
                                <label>Email Verified:</label>
                                <span :class="selectedUser.email_verified_at ? 'text-success' : 'text-danger'">
                                    {{ selectedUser.email_verified_at ? 'Yes' : 'No' }}
                                </span>
                            </div>
                            <div class="detail-item">
                                <label>Last Active:</label>
                                <span>{{ formatDate(selectedUser.updated_at) }}</span>
                            </div>
                        </div>

                        <!-- Technician Details -->
                        <div v-if="selectedUser.technician" class="technician-details">
                            <h5><i class="fas fa-hard-hat"></i> Technician Information</h5>
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <label>Specialization:</label>
                                    <span>{{ selectedUser.technician.specialization }}</span>
                                </div>
                                <div class="detail-item">
                                    <label>Location:</label>
                                    <span>{{ selectedUser.technician.location }}</span>
                                </div>
                                <div class="detail-item">
                                    <label>Availability:</label>
                                    <span :class="['availability-badge', selectedUser.technician.availability]">
                                        {{ formatAvailability(selectedUser.technician.availability) }}
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <label>Rating:</label>
                                    <span>{{ selectedUser.technician.rating || '0' }}/5 ⭐</span>
                                </div>
                                <div class="detail-item">
                                    <label>Total Jobs:</label>
                                    <span>{{ selectedUser.technician.total_jobs || '0' }}</span>
                                </div>
                            </div>
                            <div v-if="selectedUser.technician.bio" class="bio-section">
                                <label>Bio:</label>
                                <p>{{ selectedUser.technician.bio }}</p>
                            </div>
                            <div v-if="selectedUser.technician.skills" class="skills-section">
                                <label>Skills:</label>
                                <div class="skills-tags">
                                    <span v-for="skill in selectedUser.technician.skills" :key="skill" class="skill-tag">
                                        {{ skill }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button @click="closeViewModal" class="btn btn-secondary">Close</button>
                <button @click="editUser(selectedUser)" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit User
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="modal-overlay" @click="closeDeleteModal">
        <div class="modal-content" @click.stop>
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button @click="closeDeleteModal" class="modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="modal-body">
                <div class="delete-confirmation">
                    <div class="warning-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h4>Delete User Account</h4>
                    <p>Are you sure you want to delete <strong>{{ userToDelete?.name }}</strong>?</p>
                    <p class="warning-text">This action cannot be undone. All user data will be permanently removed.</p>
                </div>
            </div>

            <div class="modal-footer">
                <button @click="closeDeleteModal" class="btn btn-secondary">Cancel</button>
                <button @click="confirmDelete" class="btn btn-danger" :disabled="deleting">
                    <i class="fas fa-trash"></i> {{ deleting ? 'Deleting...' : 'Delete User' }}
                </button>
            </div>
        </div>
    </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, reactive, watch } from 'vue'
import debounce from 'lodash/debounce'

const props = defineProps({
    users: Object, // Changed to Object for pagination
    stats: {
        type: Object,
        default: () => ({
            total: 0,
            clients: 0,
            technicians: 0,
            admins: 0
        })
    },
    filters: Object
})

// Reactive data
const searchTerm = ref(props.filters?.search || '')
const roleFilter = ref(props.filters?.role || 'all')
const showCreateModal = ref(false)
const showEditModal = ref(false)
const showViewModal = ref(false)
const showDeleteModal = ref(false)
const selectedUser = ref(null)
const userToDelete = ref(null)
const submitting = ref(false)
const deleting = ref(false)
const skillsInput = ref('')

const userForm = reactive({
    name: '',
    email: '',
    phone: '',
    role: '',
    password: '',
    password_confirmation: '',
    specialization: '',
    location: '',
    availability: 'available',
    bio: '',
    skills: []
})

// Watchers for server-side filtering
watch(searchTerm, debounce((value) => {
    router.get('/admin/users', { search: value, role: roleFilter.value }, {
        preserveState: true,
        replace: true
    })
}, 300))

watch(roleFilter, (value) => {
    router.get('/admin/users', { search: searchTerm.value, role: value }, {
        preserveState: true,
        replace: true
    })
})

// Methods
const formatRole = (role) => {
    const roles = {
        'client': 'Client',
        'technician': 'Technician',
        'admin': 'Administrator'
    }
    return roles[role] || role
}

const formatAvailability = (availability) => {
    const statuses = {
        'available': 'Available',
        'busy': 'Busy',
        'on_leave': 'On Leave'
    }
    return statuses[availability] || availability
}

const formatDate = (date) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleDateString()
}

const filterUsers = () => {
    // Filtering handled by computed property
}

const resetForm = () => {
    Object.assign(userForm, {
        name: '',
        email: '',
        phone: '',
        role: '',
        password: '',
        password_confirmation: '',
        specialization: '',
        location: '',
        availability: 'available',
        bio: '',
        skills: []
    })
    skillsInput.value = ''
}

const closeModals = () => {
    showCreateModal.value = false
    showEditModal.value = false
    resetForm()
}

const closeViewModal = () => {
    showViewModal.value = false
    selectedUser.value = null
}

const closeDeleteModal = () => {
    showDeleteModal.value = false
    userToDelete.value = null
}

const onRoleChange = () => {
    if (userForm.role !== 'technician') {
        userForm.specialization = ''
        userForm.location = ''
        userForm.availability = 'available'
        userForm.bio = ''
        userForm.skills = []
        skillsInput.value = ''
    }
}

const viewUser = (user) => {
    selectedUser.value = user
    showViewModal.value = true
}

const editUser = (user) => {
    selectedUser.value = user
    Object.assign(userForm, {
        name: user.name,
        email: user.email,
        phone: user.phone || '',
        role: user.role,
        password: '',
        password_confirmation: '',
        specialization: user.technician?.specialization || '',
        location: user.technician?.location || '',
        availability: user.technician?.availability || 'available',
        bio: user.technician?.bio || '',
        skills: user.technician?.skills || []
    })
    skillsInput.value = (user.technician?.skills || []).join(', ')
    showViewModal.value = false
    showEditModal.value = true
}

const deleteUser = (user) => {
    userToDelete.value = user
    showDeleteModal.value = true
}

const submitUser = async () => {
    submitting.value = true

    // Parse skills
    if (skillsInput.value.trim()) {
        userForm.skills = skillsInput.value.split(',').map(skill => skill.trim()).filter(skill => skill)
    }

    try {
        if (showCreateModal.value) {
            await router.post('/admin/users', userForm)
        } else {
            await router.put(`/admin/users/${selectedUser.value.id}`, userForm)
        }
        closeModals()
    } catch (error) {
        console.error('Error submitting user:', error)
    } finally {
        submitting.value = false
    }
}

const confirmDelete = async () => {
    deleting.value = true
    try {
        await router.delete(`/admin/users/${userToDelete.value.id}`)
        closeDeleteModal()
    } catch (error) {
        console.error('Error deleting user:', error)
    } finally {
        deleting.value = false
    }
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

/* User Management Specific Styles */
.stats-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
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

.stat-icon.total { background: #2563EB; }
.stat-icon.clients { background: #16A34A; }
.stat-icon.technicians { background: #F97316; }
.stat-icon.admins { background: #9333EA; }

.filters {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.filter-select {
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: white;
}

.users-table {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.users-table table {
    width: 100%;
    border-collapse: collapse;
    min-width: 820px;
}

.users-table th,
.users-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.users-table th {
    background: var(--bg-light);
    font-weight: 600;
    color: var(--text-color);
}

.user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.user-avatar {
    width: 40px;
    height: 40px;
    background: var(--primary-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.user-details strong {
    color: var(--text-color);
    display: block;
}

.user-details small {
    color: var(--text-muted);
}

.role-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
    text-transform: capitalize;
}

.role-badge.client {
    background: #DBEAFE;
    color: #1E40AF;
}

.role-badge.technician {
    background: #FED7AA;
    color: #C2410C;
}

.role-badge.admin {
    background: #F3E8FF;
    color: #7C3AED;
}

.status-badge.verified {
    background: #D1FAE5;
    color: #059669;
}

.status-badge.pending {
    background: #FEF3C7;
    color: #D97706;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
}

/* Modal Styles */
.form-sections {
    display: flex;
    flex-direction: column;
    gap: 2rem;
}

.form-section {
    padding: 1.5rem;
    background: #f8f9fa;
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

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 0.5rem;
}

.form-text {
    color: var(--text-muted);
    font-size: 0.85rem;
    margin-top: 0.25rem;
}

/* Pagination Styles */
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-top: 1px solid var(--border-color);
    margin-top: 1rem;
}

.pagination {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.page-link {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: white;
    color: var(--text-color);
    text-decoration: none;
    font-size: 0.9rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.page-link:hover:not(.disabled):not(.active) {
    background: #f8f9fa;
    border-color: var(--primary-color);
    color: var(--primary-color);
}

.page-link.active {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.page-link.disabled {
    color: var(--text-muted);
    cursor: not-allowed;
    background: #f8f9fa;
    opacity: 0.6;
}

.pagination-info {
    color: var(--text-muted);
    font-size: 0.9rem;
}

/* User Profile */
.user-profile {
    padding: 1rem;
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--border-color);
}

.profile-avatar {
    width: 60px;
    height: 60px;
    background: var(--primary-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
}

.profile-info h4 {
    margin: 0;
    color: var(--text-color);
}

.profile-info .role {
    color: var(--primary-color);
    font-weight: 600;
    margin: 0.25rem 0;
}

.profile-info .email {
    color: var(--text-muted);
    margin: 0;
}

.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-item label {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.detail-item span {
    color: var(--text-color);
}

.technician-details {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 1.5rem;
}

.technician-details h5 {
    margin: 0 0 1rem 0;
    color: var(--primary-color);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.availability-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.availability-badge.available {
    background: #D1FAE5;
    color: #059669;
}

.availability-badge.busy {
    background: #FEE2E2;
    color: #DC2626;
}

.availability-badge.on_leave {
    background: #FEF3C7;
    color: #D97706;
}

.bio-section,
.skills-section {
    margin-top: 1rem;
}

.bio-section label,
.skills-section label {
    font-weight: 600;
    color: var(--text-color);
    display: block;
    margin-bottom: 0.5rem;
}

.skills-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.skill-tag {
    background: var(--primary-color);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
}

/* Delete Modal */
.delete-confirmation {
    text-align: center;
    padding: 1rem;
}

.warning-icon {
    width: 60px;
    height: 60px;
    background: #FEE2E2;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    color: #DC2626;
    font-size: 1.5rem;
}

.delete-confirmation h4 {
    color: var(--text-color);
    margin-bottom: 1rem;
}

.warning-text {
    color: #DC2626;
    font-weight: 500;
}

.text-success {
    color: #16A34A !important;
}

.text-danger {
    color: #DC2626 !important;
}

/* Responsive */
@media (max-width: 1024px) {
    .stats-section {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .stats-section {
        grid-template-columns: 1fr;
    }

    .filters {
        flex-direction: column;
        align-items: stretch;
    }

    .users-table {
        font-size: 0.9rem;
    }

    .action-buttons {
        flex-direction: column;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }

    .profile-header {
        flex-direction: column;
        text-align: center;
    }

    :deep(.modal-content) {
        width: 95% !important;
        max-width: 95% !important;
        max-height: 95vh;
    }

    :deep(.modal-body),
    :deep(.modal-header),
    :deep(.modal-footer) {
        padding: 1rem !important;
    }
}
</style>