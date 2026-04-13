<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <h1>Dashboard</h1>
            <div class="availability-toggle">
                <label class="toggle-switch">
                    <span class="toggle-label" style="margin-right: 10px; font-size: 0.9rem;">
                        {{ isAvailable ? 'Available' : 'Busy' }}
                    </span>
                    <div 
                        @click="toggleAvailability"
                        :style="{
                            width: '44px',
                            height: '24px',
                            backgroundColor: isAvailable ? 'var(--success-color)' : '#D1D5DB',
                            borderRadius: '12px',
                            position: 'relative',
                            cursor: 'pointer',
                            transition: 'background-color 0.2s'
                        }"
                    >
                        <div 
                            :style="{
                                width: '20px',
                                height: '20px',
                                backgroundColor: 'white',
                                borderRadius: '50%',
                                position: 'absolute',
                                top: '2px',
                                left: isAvailable ? '22px' : '2px',
                                transition: 'left 0.2s',
                                boxShadow: '0 1px 2px rgba(0,0,0,0.2)'
                            }"
                        ></div>
                    </div>
                </label>
            </div>
        </header>

        <main class="pwa-content">
            <!-- Stats Summary -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 2rem; color: var(--primary-color);">{{ activeJobs.length }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Active Jobs</p>
                </div>
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 2rem; color: var(--success-color);">{{ completedJobsCount || 0 }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Completed</p>
                </div>
            </div>

            <!-- Incoming Jobs (Assigned but not started) -->
            <div v-if="incomingJobs.length > 0">
                <h2 style="font-size: 1rem; color: var(--light-text); margin-bottom: 0.5rem; padding-left: 0.5rem;">Incoming Requests</h2>
                <div v-for="job in incomingJobs" :key="job.id" class="pwa-card" style="cursor: pointer;" @click="router.visit(`/technician/jobs/${job.id}`)">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <div>
                            <span class="status-badge status-assigned">New Assignment</span>
                            <h3 style="margin: 0.5rem 0 0.25rem 0; font-size: 1.1rem;">{{ job.service_category?.name }}</h3>
                        </div>
                        <div style="background: #EFF6FF; padding: 0.5rem; borderRadius: 8px; color: var(--primary-color);">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                    </div>
                    
                    <p style="font-size: 0.9rem; color: var(--light-text); margin-bottom: 1rem;">
                        <i class="far fa-clock" style="margin-right: 5px;"></i> {{ formatDate(job.created_at) }}
                    </p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <!-- Using plain button for now, can implement accept/decline logic later -->
                        <button class="btn btn-primary" @click="startJob(job)">
                            Start Job
                        </button>
                    </div>
                </div>
            </div>

            <!-- Active Jobs (In Progress) -->
            <div v-if="activeJobs.length > 0">
                <h2 style="font-size: 1rem; color: var(--light-text); margin-bottom: 0.5rem; padding-left: 0.5rem; margin-top: 1rem;">In Progress</h2>
                <div v-for="job in activeJobs" :key="job.id" class="pwa-card" style="border-left: 4px solid var(--warning-color); cursor: pointer;" @click="router.visit(`/technician/jobs/${job.id}`)">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <h3 style="margin: 0; font-size: 1.1rem;">{{ job.service_category?.name }}</h3>
                        <span class="status-badge status-in_progress">Active</span>
                    </div>
                    <p style="font-size: 0.9rem; margin-bottom: 0.5rem;">
                        {{ job.description || 'No description provided.' }}
                    </p>
                     <p style="font-size: 0.85rem; color: var(--light-text); margin-bottom: 1rem;">
                        <i class="fas fa-user" style="margin-right: 5px;"></i> {{ job.user?.name || 'Client' }}
                    </p>

                    <div style="background: #F9FAFB; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; margin-bottom: 0.5rem;">
                            <span>Progress</span>
                            <span>{{ job.progress_percentage || 0 }}%</span>
                        </div>
                        <div style="width: 100%; height: 6px; background: #E5E7EB; border-radius: 3px;">
                            <div 
                                :style="{ 
                                    width: (job.progress_percentage || 0) + '%', 
                                    height: '100%', 
                                    background: 'var(--warning-color)', 
                                    borderRadius: '3px' 
                                }"
                            ></div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <button 
                            v-if="!job.technician_arrived" 
                            class="btn btn-primary" 
                            @click="updateStatus(job, 'on_site')"
                        >
                            Arrived On Site
                        </button>
                        <button 
                            v-else 
                            class="btn btn-outline" 
                            style="border-color: var(--success-color); color: var(--success-color);"
                            @click="updateStatus(job, 'completed')"
                        >
                            Mark Complete
                        </button>
                        
                        <Link href="/technician/tools" class="btn btn-outline">
                            <i class="fas fa-tools" style="margin-right: 5px;"></i> Request Tools
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="incomingJobs.length === 0 && activeJobs.length === 0" style="text-align: center; padding: 3rem 1rem;">
                <div style="background: #E0E7FF; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto;">
                    <i class="fas fa-check-circle" style="font-size: 2.5rem; color: var(--primary-color);"></i>
                </div>
                <h3 style="margin-bottom: 0.5rem;">All Caught Up!</h3>
                <p style="color: var(--light-text);">You have no active or pending jobs right now.</p>
            </div>

        </main>

        <TechnicianBottomNav current-page="dashboard" />
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3'; // Import router correctly
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue';

const props = defineProps({
    technician: Object,
    incomingJobs: Array,
    activeJobs: Array,
    completedJobsCount: Number,
});

const isAvailable = computed(() => props.technician?.availability === 'available');

const toggleAvailability = () => {
    const newStatus = isAvailable.value ? 'busy' : 'available';
    router.post('/technician/availability', { availability: newStatus }, {
        preserveScroll: true,
    });
};

const startJob = (job) => {
    // Starting a job usually means moving to "En Route"
    updateStatus(job, 'en_route');
};

const updateStatus = (job, action) => {
    if (confirm(`Are you sure you want to update status to: ${action.replace('_', ' ')}?`)) {
        router.post(`/technician/jobs/${job.id}/status`, { action }, {
            preserveScroll: true,
        });
    }
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });
};

// Disable default layout
defineOptions({ layout: null });
</script>

<style>
@import url('../../../css/technician-pwa.css');
</style>
