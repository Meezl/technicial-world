<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="profile" />

        <main class="main-content">
            <header class="main-header">
                <h1>My Profile</h1>
            </header>

            <section class="panel-section">
                <div class="profile-grid">
                    <div class="panel-card">
                        <div class="card-header">
                            <h3>Personal Information</h3>
                            <button @click="editMode = !editMode" class="btn btn-secondary btn-sm">
                                <i class="fas fa-edit"></i> {{ editMode ? 'Cancel' : 'Edit' }}
                            </button>
                        </div>

                        <div v-if="!editMode" class="profile-details">
                            <p><span>Full Name:</span> {{ $page.props.auth.user?.name || 'Not provided' }}</p>
                            <p><span>Email:</span> {{ $page.props.auth.user?.email || 'Not provided' }}</p>
                            <p><span>Phone:</span> {{ profileData.phone || 'Not provided' }}</p>
                            <p><span>Location:</span> {{ profileData.location || 'Not provided' }}</p>
                        </div>

                        <form v-else @submit.prevent="updateProfile" class="profile-form">
                            <div class="form-group">
                                <label for="name">Full Name</label>
                                <input type="text" id="name" v-model="profileForm.name" required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" v-model="profileForm.email" required>
                            </div>
                            <div class="form-group">
                                <label for="phone">Phone</label>
                                <input type="tel" id="phone" v-model="profileForm.phone">
                            </div>
                            <div class="form-group">
                                <label for="location">Location</label>
                                <input type="text" id="location" v-model="profileForm.location">
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" :disabled="updating">
                                    {{ updating ? 'Updating...' : 'Update Profile' }}
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="panel-card">
                        <div class="card-header">
                            <h3>Change Password</h3>
                        </div>
                        <form @submit.prevent="updatePassword" class="profile-form">
                            <div class="form-group">
                                <label for="current-password">Current Password</label>
                                <input type="password" id="current-password" v-model="passwordForm.current_password" required>
                            </div>
                            <div class="form-group">
                                <label for="new-password">New Password</label>
                                <input type="password" id="new-password" v-model="passwordForm.new_password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm-password">Confirm New Password</label>
                                <input type="password" id="confirm-password" v-model="passwordForm.new_password_confirmation" required>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary" :disabled="updatingPassword">
                                    {{ updatingPassword ? 'Updating...' : 'Update Password' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Account Statistics</h3>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <i class="fas fa-tools"></i>
                            <div>
                                <h4>{{ stats.total_requests }}</h4>
                                <p>Total Requests</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-check-circle"></i>
                            <div>
                                <h4>{{ stats.completed_requests }}</h4>
                                <p>Completed Jobs</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-clock"></i>
                            <div>
                                <h4>{{ stats.pending_requests }}</h4>
                                <p>Pending Requests</p>
                            </div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-calendar"></i>
                            <div>
                                <h4>{{ formatDate($page.props.auth.user?.created_at) }}</h4>
                                <p>Member Since</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import ClientSidebar from '../../Components/ClientSidebar.vue'
import { ref, reactive, computed } from 'vue'

const props = defineProps({
    profileData: {
        type: Object,
        default: () => ({
            phone: '',
            location: ''
        })
    },
    stats: {
        type: Object,
        default: () => ({
            total_requests: 0,
            completed_requests: 0,
            pending_requests: 0
        })
    }
})

const editMode = ref(false)
const updating = ref(false)
const updatingPassword = ref(false)

const profileForm = reactive({
    name: '',
    email: '',
    phone: '',
    location: ''
})

const passwordForm = reactive({
    current_password: '',
    new_password: '',
    new_password_confirmation: ''
})

// Initialize profile form with current data
if (typeof window !== 'undefined') {
    profileForm.name = window.$page?.props?.auth?.user?.name || ''
    profileForm.email = window.$page?.props?.auth?.user?.email || ''
    profileForm.phone = props.profileData.phone || ''
    profileForm.location = props.profileData.location || ''
}

const updateProfile = async () => {
    updating.value = true

    try {
        // Here you would typically submit to a backend endpoint
        console.log('Profile updated:', profileForm)
        alert('Profile updated successfully!')
        editMode.value = false
    } catch (error) {
        console.error('Profile update error:', error)
        alert('There was an error updating your profile. Please try again.')
    } finally {
        updating.value = false
    }
}

const updatePassword = async () => {
    if (passwordForm.new_password !== passwordForm.new_password_confirmation) {
        alert('New passwords do not match!')
        return
    }

    updatingPassword.value = true

    try {
        // Here you would typically submit to a backend endpoint
        console.log('Password update requested')
        alert('Password updated successfully!')

        // Reset form
        passwordForm.current_password = ''
        passwordForm.new_password = ''
        passwordForm.new_password_confirmation = ''
    } catch (error) {
        console.error('Password update error:', error)
        alert('There was an error updating your password. Please try again.')
    } finally {
        updatingPassword.value = false
    }
}

const formatDate = (date) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');

.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}

.profile-details p {
    margin: 1rem 0;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border-color);
}

.profile-details p:last-child {
    border-bottom: none;
}

.profile-details span {
    font-weight: 600;
    color: var(--dark-grey);
    display: inline-block;
    width: 100px;
}

.profile-form .form-actions {
    margin-top: 1.5rem;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 2rem;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.stat-item i {
    font-size: 2rem;
    color: var(--primary-blue);
    width: 60px;
    text-align: center;
}

.stat-item h4 {
    margin: 0 0 0.25rem 0;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--dark-grey);
}

.stat-item p {
    margin: 0;
    color: var(--medium-grey);
    font-size: 0.9rem;
}

@media (max-width: 768px) {
    .profile-grid {
        grid-template-columns: 1fr;
    }

    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>