<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <h1>My Profile</h1>
            <button @click="logout" class="btn btn-outline btn-sm" style="width: auto; padding: 0.5rem 1rem;">
                <i class="fas fa-sign-out-alt"></i>
            </button>
        </header>

        <main class="pwa-content">
            <!-- Profile Header -->
            <div class="pwa-card" style="text-align: center; padding: 2rem 1rem;">
                <div style="width: 80px; height: 80px; background: var(--secondary-color); border-radius: 50%; margin: 0 auto 1rem auto; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: var(--primary-color);">
                    <i class="fas fa-user"></i>
                </div>
                <h2 style="margin: 0; font-size: 1.5rem; color: var(--primary-color);">{{ technician?.user?.name }}</h2>
                <p style="margin: 0; color: var(--light-text);">ID: #{{ technician?.id }}</p>
                <div style="margin-top: 1rem;">
                    <span class="status-badge" style="background: #FEF3C7; color: #D97706; font-size: 0.9rem; padding: 0.25rem 0.75rem;">
                        <i class="fas fa-star"></i> {{ technician?.rating || 'New' }}
                    </span>
                </div>
            </div>

            <!-- Details -->
            <div class="pwa-card">
                <div class="list-item">
                    <span style="color: var(--light-text);">Email</span>
                    <span style="font-weight: 500;">{{ technician?.user?.email }}</span>
                </div>
                <div class="list-item">
                    <span style="color: var(--light-text);">Phone</span>
                    <span style="font-weight: 500;">{{ technician?.user?.phone || 'N/A' }}</span>
                </div>
                <div class="list-item">
                    <span style="color: var(--light-text);">Specialization</span>
                    <span style="font-weight: 500;">{{ technician?.specialization || 'General' }}</span>
                </div>
                <div class="list-item">
                    <span style="color: var(--light-text);">Join Date</span>
                    <span style="font-weight: 500;">{{ formatDate(technician?.created_at) }}</span>
                </div>
            </div>

            <!-- Skills -->
            <h2 style="font-size: 1rem; color: var(--light-text); margin-bottom: 0.5rem; padding-left: 0.5rem;">Skills & Certifications</h2>
            <div class="pwa-card">
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <span v-for="skill in skills" :key="skill" class="status-badge" style="background: var(--secondary-color); color: var(--text-color);">
                        {{ skill }}
                    </span>
                    <span v-if="skills.length === 0" style="color: var(--light-text);">No skills listed.</span>
                </div>
            </div>

            <button @click="logout" class="btn btn-primary" style="background: var(--danger-color); margin-top: 1rem;">
                Log Out
            </button>
        </main>

        <TechnicianBottomNav current-page="profile" />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue';

const props = defineProps({
    technician: Object,
});

const skills = computed(() => {
    if (!props.technician?.skills) return [];
    return typeof props.technician.skills === 'string' 
        ? JSON.parse(props.technician.skills) 
        : props.technician.skills;
});

const logout = () => {
    router.post('/logout');
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'long', year: 'numeric'
    });
};

defineOptions({ layout: null });
</script>

<style>
@import url('../../../css/technician-pwa.css');
</style>
