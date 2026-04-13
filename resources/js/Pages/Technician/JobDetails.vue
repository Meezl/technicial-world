<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <Link href="/technician/dashboard" class="btn btn-outline btn-sm" style="width: auto; padding: 0.5rem; border: none; font-size: 1.2rem;">
                    <i class="fas fa-arrow-left"></i>
                </Link>
                <h1 style="font-size: 1.1rem;">Job #{{ job.id }}</h1>
            </div>
            <span class="status-badge" :class="getStatusClass(job.status)">
                {{ formatStatus(job.status) }}
            </span>
        </header>

        <main class="pwa-content">
            <!-- Job Header -->
            <div class="pwa-card">
                <h2 style="font-size: 1.2rem; color: var(--primary-color); margin-bottom: 0.5rem;">{{ job.service_category?.name }}</h2>
                <p style="font-size: 0.9rem; margin-bottom: 1rem;">{{ job.description }}</p>
                <div style="font-size: 0.8rem; color: var(--light-text); display: flex; gap: 1rem;">
                    <span><i class="far fa-calendar"></i> {{ formatDate(job.created_at) }}</span>
                    <span><i class="fas fa-map-marker-alt"></i> {{ job.location || 'On Site' }}</span>
                </div>
            </div>

            <!-- Client Info -->
            <h3 style="font-size: 1rem; color: var(--text-color); margin: 0 0 0.5rem 0.5rem;">Client Details</h3>
            <div class="pwa-card">
                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1rem;">
                    <div style="width: 48px; height: 48px; background: #E0E7FF; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--primary-color); font-size: 1.2rem;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <div style="font-weight: 600;">{{ job.user?.name || 'Guest Client' }}</div>
                        <div style="font-size: 0.9rem; color: var(--light-text);">{{ job.user?.email }}</div>
                    </div>
                </div>
                <a v-if="job.user?.phone" :href="'tel:' + job.user.phone" class="btn btn-outline" style="width: 100%;">
                    <i class="fas fa-phone" style="margin-right: 0.5rem;"></i> Call Client
                </a>
            </div>

            <!-- Sub-Tasks -->
            <h3 style="font-size: 1rem; color: var(--text-color); margin: 1rem 0 0.5rem 0.5rem;">Sub-Tasks & Progress</h3>
            <div class="pwa-card">
                <div v-if="job.sub_tasks && job.sub_tasks.length > 0">
                    <div v-for="task in job.sub_tasks" :key="task.id" style="padding: 1rem 0; border-bottom: 1px solid var(--border-color);">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="font-weight: 500;">{{ task.name }}</span>
                            <span class="status-badge" style="font-size: 0.7rem;">{{ formatStatus(task.status) }}</span>
                        </div>
                        
                        <!-- Progress Control -->
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <input 
                                type="range" 
                                min="0" 
                                max="100" 
                                step="10" 
                                :value="task.progress_percentage" 
                                @change="(e) => updateTaskProgress(task, e.target.value)"
                                :disabled="!canUpdateTask(task)"
                                style="flex: 1;"
                            >
                            <span style="font-size: 0.9rem; width: 3rem; text-align: right;">{{ task.progress_percentage }}%</span>
                        </div>
                        <div v-if="task.technician" style="font-size: 0.8rem; color: var(--light-text); margin-top: 0.5rem;">
                            Assigned to: {{ task.technician.user.name }}
                        </div>
                    </div>
                </div>
                <div v-else style="text-align: center; padding: 1rem; color: var(--light-text);">
                    No sub-tasks defined.
                </div>
            </div>

            <!-- Main Status Actions -->
            <div style="position: fixed; bottom: calc(var(--nav-height) + 1rem); left: 1rem; right: 1rem; z-index: 10;">
                <button 
                    v-if="job.status === 'assigned'" 
                    class="btn btn-primary" 
                    style="box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"
                    @click="updateStatus('en_route')"
                >
                    Start Job (En Route)
                </button>
                <button 
                    v-else-if="job.status === 'in_progress' && !job.technician_arrived" 
                    class="btn btn-primary" 
                    style="box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"
                    @click="updateStatus('on_site')"
                >
                    Arrived On Site
                </button>
                <div v-else-if="job.status === 'in_progress' && job.technician_arrived" style="display: flex; gap: 0.5rem;">
                     <Link href="/technician/tools" class="btn btn-outline" style="background: white; flex: 1;">
                        <i class="fas fa-tools"></i> Tools
                    </Link>
                    <button 
                        class="btn btn-primary" 
                        style="background: var(--success-color); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); flex: 2;"
                        @click="updateStatus('completed')"
                    >
                        Mark Complete
                    </button>
                </div>
            </div>
            
            <!-- Spacer for fixed bottom actions -->
            <div style="height: 4rem;"></div>

        </main>
    </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';

const props = defineProps({
    technician: Object,
    job: Object,
});

const getStatusClass = (status) => {
    switch (status) {
        case 'assigned': return 'status-assigned';
        case 'in_progress': return 'status-in_progress';
        case 'completed': return 'status-completed';
        default: return '';
    }
};

const formatStatus = (status) => {
    return status ? status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'Unknown';
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString();
};

const canUpdateTask = (task) => {
    // Lead technician can update all, assigned tech can update theirs
    return props.technician.id === props.job.lead_technician_id || 
           props.technician.id === task.technician_id;
};

const updateTaskProgress = (task, value) => {
    router.post(`/technician/sub-tasks/${task.id}/progress`, {
        progress_percentage: parseInt(value),
        status: parseInt(value) === 100 ? 'completed' : 'in_progress'
    }, {
        preserveScroll: true,
    });
};

const updateStatus = (action) => {
    if (confirm(`Update job status to: ${action.replace('_', ' ')}?`)) {
        router.post(`/technician/jobs/${props.job.id}/status`, { action }, {
            preserveScroll: true,
        });
    }
};

defineOptions({ layout: null });
</script>

<style scoped>
@import url('../../../css/technician-pwa.css');
</style>
