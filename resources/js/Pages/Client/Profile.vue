<template>
    <div class="dashboard-container client-pwa-shell">
        <ClientSidebar current-page="profile" />
        <ClientBottomNav current-page="profile" />

        <main class="main-content">
            <header class="main-header">
                <h1>My Profile</h1>
            </header>

            <!-- Mobile-only profile hero -->
            <section class="profile-hero mobile-only">
                <div class="profile-hero-avatar">
                    <span>{{ initials }}</span>
                </div>
                <div class="profile-hero-copy">
                    <strong>{{ $page.props.auth.user?.name || 'Client' }}</strong>
                    <span>{{ $page.props.auth.user?.email || '—' }}</span>
                </div>
                <Link href="/logout" method="post" as="button" class="profile-hero-logout" aria-label="Log out">
                    <i class="fas fa-sign-out-alt"></i>
                </Link>
            </section>

            <!-- Mobile-only quick stats row -->
            <section class="profile-stats-row mobile-only">
                <article class="stat-pill">
                    <span class="stat-pill-value">{{ stats.total_requests }}</span>
                    <span class="stat-pill-label">Total</span>
                </article>
                <article class="stat-pill">
                    <span class="stat-pill-value">{{ stats.completed_requests }}</span>
                    <span class="stat-pill-label">Completed</span>
                </article>
                <article class="stat-pill">
                    <span class="stat-pill-value">{{ stats.pending_requests }}</span>
                    <span class="stat-pill-label">Pending</span>
                </article>
            </section>

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

            <section class="panel-section desktop-only-section">
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
import ClientBottomNav from '../../Components/ClientBottomNav.vue'
import { ref, reactive, computed }
from 'vue'
import { usePage } from '@inertiajs/vue3'

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

const page = usePage()

const editMode = ref(false)
const updating = ref(false)
const updatingPassword = ref(false)

const initials = computed(() => {
    const name = page.props.auth?.user?.name || 'C'
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('') || 'C'
})

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

/* ─────────── Mobile (≤1023px) — senior-design polish ─────────── */
.mobile-only { display: none; }

@media (max-width: 1023.98px) {
    .client-pwa-shell .main-content > .main-header {
        padding: 0;
        margin-bottom: 0.5rem;
    }

    .client-pwa-shell .main-content > .main-header h1 {
        font-size: 1.25rem;
        margin: 0;
        color: #0f172a;
    }

    .mobile-only {
        display: flex;
    }

    /* ── Hero ─────────────────────────────── */
    .profile-hero {
        align-items: center;
        gap: 1rem;
        padding: 1.25rem;
        border-radius: 24px;
        margin-bottom: 1rem;
        color: #ffffff;
        background:
            radial-gradient(circle at top right, rgba(253, 230, 138, 0.25), transparent 50%),
            linear-gradient(135deg, #053272 0%, #1d4ed8 100%);
        box-shadow: 0 20px 44px rgba(5, 50, 114, 0.22);
    }

    .profile-hero-avatar {
        width: 64px;
        height: 64px;
        flex-shrink: 0;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        border: 1.5px solid rgba(255, 255, 255, 0.35);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .profile-hero-copy {
        flex: 1;
        display: grid;
        gap: 0.2rem;
        min-width: 0;
    }

    .profile-hero-copy strong {
        font-size: 1.15rem;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .profile-hero-copy span {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.78);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .profile-hero-logout {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border-radius: 12px;
        border: 0;
        background: rgba(255, 255, 255, 0.16);
        color: #ffffff;
        font-size: 0.95rem;
        cursor: pointer;
    }

    /* ── Stats pills ──────────────────────── */
    .profile-stats-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.55rem;
        margin-bottom: 1rem;
    }

    .stat-pill {
        display: grid;
        gap: 0.2rem;
        padding: 0.85rem 0.65rem;
        text-align: center;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 6px 14px rgba(15, 23, 42, 0.05);
    }

    .stat-pill-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: #0f172a;
    }

    .stat-pill-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    /* ── Hide redundant desktop "Account Statistics" card on mobile ── */
    .client-pwa-shell .desktop-only-section {
        display: none;
    }

    /* ── Sections ─────────────────────────── */
    .client-pwa-shell .panel-section {
        margin-bottom: 1rem;
    }

    .client-pwa-shell .profile-grid {
        gap: 1rem;
    }

    .client-pwa-shell .profile-grid > .panel-card {
        padding: 1.1rem;
        border-radius: 20px;
        background: #ffffff;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
    }

    .client-pwa-shell .profile-grid .card-header {
        margin-bottom: 0.85rem;
        gap: 0.75rem;
    }

    .client-pwa-shell .profile-grid .card-header h3 {
        font-size: 1.02rem;
        margin: 0;
    }

    .client-pwa-shell .profile-grid .card-header .btn {
        padding: 0.45rem 0.75rem;
        font-size: 0.82rem;
        border-radius: 999px;
    }

    /* ── Read-only field list ─────────────── */
    .client-pwa-shell .profile-details {
        display: grid;
        gap: 0.65rem;
    }

    .client-pwa-shell .profile-details p {
        display: grid;
        gap: 0.15rem;
        margin: 0;
        padding: 0.7rem 0.85rem;
        border-radius: 12px;
        border: 1px solid #f1f5f9;
        background: #f8fafc;
        font-size: 0.95rem;
        color: #0f172a;
    }

    .client-pwa-shell .profile-details p span {
        display: block;
        width: auto;
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    /* ── Inputs (shared) ──────────────────── */
    .client-pwa-shell .profile-form {
        display: grid;
        gap: 0.85rem;
    }

    .client-pwa-shell .profile-form .form-group {
        display: grid;
        gap: 0.35rem;
    }

    .client-pwa-shell .profile-form label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .client-pwa-shell .profile-form input {
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

    .client-pwa-shell .profile-form input:focus {
        outline: none;
        border-color: #053272;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(5, 50, 114, 0.1);
    }

    .client-pwa-shell .profile-form .form-actions {
        margin-top: 0.5rem;
    }

    .client-pwa-shell .profile-form .form-actions .btn {
        width: 100%;
        justify-content: center;
        height: 48px;
        border-radius: 12px;
        font-weight: 700;
    }
}
</style>