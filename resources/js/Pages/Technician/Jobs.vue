<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <h1>My Jobs</h1>
            <div class="filter-controls">
                <span style="font-size: 0.8rem; color: var(--light-text); background: var(--app-bg); padding: 4px 8px; border-radius: 4px;">{{ sortedJobs.length }} Total</span>
            </div>
        </header>

        <main class="pwa-content">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.75rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.15rem; color: var(--success-color);">{{ formatCurrency(earningsSummary?.total_paid || 0) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Paid So Far</p>
                </div>
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.75rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.15rem; color: var(--warning-color);">{{ formatCurrency(earningsSummary?.total_outstanding || 0) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Still Owed</p>
                </div>
            </div>

            <div class="pwa-card" style="padding: 0.5rem;">
                <input 
                    type="text" 
                    v-model="searchQuery" 
                    placeholder="Search jobs..." 
                    style="width: 100%; border: none; outline: none; padding: 0.5rem; font-size: 1rem;"
                >
            </div>

            <div v-if="sortedJobs.length > 0">
                <div v-for="job in sortedJobs" :key="job.id" class="pwa-card" @click="viewJobDetails(job)">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div class="category-icon" :class="getCategoryClass(job.service_category?.name)">
                                <i :class="getCategoryIcon(job.service_category?.name)"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 1rem;">{{ job.service_category?.name }}</h3>
                                <span style="font-size: 0.8rem; color: var(--light-text);">#{{ job.id }}</span>
                            </div>
                        </div>
                        <span class="status-badge" :class="getStatusClass(job.status)">
                            {{ formatStatus(job.status) }}
                        </span>
                    </div>
                    
                    <div style="margin-left: 3rem;">
                        <p style="font-size: 0.9rem; margin-bottom: 0.5rem; color: var(--text-color); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                            {{ job.description }}
                        </p>
                        <p style="font-size: 0.8rem; color: var(--light-text);">
                            <i class="fas fa-map-marker-alt" style="margin-right: 5px;"></i> {{ job.location || 'Location provided' }}
                        </p>
                        <p style="font-size: 0.8rem; color: var(--light-text);">
                            <i class="far fa-calendar" style="margin-right: 5px;"></i> {{ formatDate(job.created_at) }}
                        </p>

                        <!-- On a project split between trades, the card named
                             the job but never the part of it that is yours.
                             Show the technician their own sub-tasks here so
                             they know what they are being called to without
                             opening every job. -->
                        <div v-if="mySubTasks(job).length" class="my-subtasks">
                            <span class="my-subtasks-label">Your work on this job</span>
                            <div v-for="task in mySubTasks(job)" :key="task.id" class="my-subtask-row">
                                <span class="my-subtask-title">{{ task.title }}</span>
                                <span class="my-subtask-progress">
                                    <span class="my-subtask-bar">
                                        <span :style="`width:${task.progress_percentage || 0}%`"></span>
                                    </span>
                                    {{ task.progress_percentage || 0 }}%
                                </span>
                            </div>
                        </div>

                        <div v-if="job.compensation_summary" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem; margin-top: 0.85rem;">
                            <div style="background: #F8FAFC; padding: 0.65rem; border-radius: 10px;">
                                <span style="display: block; font-size: 0.7rem; color: var(--light-text); margin-bottom: 0.2rem;">Agreed</span>
                                <strong style="font-size: 0.8rem;">{{ formatCurrency(job.compensation_summary.agreed_compensation) }}</strong>
                            </div>
                            <div style="background: #ECFDF5; padding: 0.65rem; border-radius: 10px;">
                                <span style="display: block; font-size: 0.7rem; color: var(--light-text); margin-bottom: 0.2rem;">Paid</span>
                                <strong style="font-size: 0.8rem; color: var(--success-color);">{{ formatCurrency(job.compensation_summary.paid_to_date) }}</strong>
                            </div>
                            <div style="background: #FFF7ED; padding: 0.65rem; border-radius: 10px;">
                                <span style="display: block; font-size: 0.7rem; color: var(--light-text); margin-bottom: 0.2rem;">Owed</span>
                                <strong style="font-size: 0.8rem; color: #c2410c;">{{ formatCurrency(job.compensation_summary.outstanding_balance) }}</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="empty-state" style="text-align: center; padding: 3rem 1rem;">
                <i class="fas fa-search" style="font-size: 2rem; color: var(--secondary-color); margin-bottom: 1rem;"></i>
                <p style="color: var(--light-text);">No jobs found matching your criteria.</p>
            </div>
        </main>

        <TechnicianBottomNav current-page="jobs" />
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue';

const props = defineProps({
    technician: Object,
    jobs: Array,
    earningsSummary: Object,
});

const searchQuery = ref('');

const sortedJobs = computed(() => {
    let filtered = props.jobs;
    
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter(job => 
            job.description?.toLowerCase().includes(query) ||
            job.service_category?.name?.toLowerCase().includes(query) ||
            job.id.toString().includes(query)
        );
    }
    
    return filtered;
});

const viewJobDetails = (job) => {
    router.visit(`/technician/jobs/${job.id}`);
};

// The sub-tasks on this job that belong to the technician looking at it.
const mySubTasks = (job) =>
    (job.sub_tasks || []).filter((task) => task.technician_id === props.technician?.id);

const getStatusClass = (status) => {
    switch (status) {
        case 'assigned': return 'status-assigned';
        case 'in_progress': return 'status-in_progress';
        case 'completed': return 'status-completed';
        default: return '';
    }
};

const formatStatus = (status) => {
    // #28 — Make status labels descriptive instead of just "Pending".
    const friendly = {
        pending: 'Awaiting Assignment',
        awaiting_quote_generation: 'Awaiting Quote',
        awaiting_quote_approval: 'Awaiting Client Approval',
        awaiting_payment: 'Awaiting Client Payment',
        payment_pending_approval: 'Verifying Payment',
        ready_for_assignment: 'Ready for You',
        assigned: 'Assigned to You',
        queued: 'Queued',
        in_progress: 'Work In Progress',
        delayed: 'Delayed',
        suspended: 'Suspended',
        completed: 'Completed',
        completed_pending_confirmation: 'Completed · Awaiting Client Confirmation',
        closed: 'Closed',
        archived: 'Archived',
        cancelled: 'Cancelled',
    }
    if (!status) return 'Unknown'
    return friendly[status] || status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getCategoryIcon = (category) => {
    const map = {
        'Electrical': 'fas fa-bolt',
        'Plumbing': 'fas fa-faucet',
        'HVAC': 'fas fa-wind',
        'Carpentry': 'fas fa-hammer',
        'Painting': 'fas fa-paint-roller'
    };
    return map[category] || 'fas fa-tools';
};

const getCategoryClass = (category) => {
    // Return class for background color etc if needed
    return '';
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric'
    });
};

const formatCurrency = (amount) => {
    return 'KES ' + Number(amount || 0).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

defineOptions({ layout: null });
</script>

<style>

.my-subtasks {
    margin-top: .85rem;
    padding: .65rem .75rem;
    background: #F8FAFC;
    border-left: 3px solid var(--primary-color);
    border-radius: 8px;
}

.my-subtasks-label {
    display: block;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--primary-color);
    margin-bottom: .4rem;
}

.my-subtask-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    font-size: .82rem;
    padding: .2rem 0;
}

.my-subtask-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.my-subtask-progress {
    display: flex;
    align-items: center;
    gap: .45rem;
    white-space: nowrap;
    font-weight: 600;
    color: var(--light-text);
}

.my-subtask-bar {
    display: inline-block;
    width: 54px;
    height: 5px;
    border-radius: 999px;
    background: #e2e8f0;
    overflow: hidden;
}

.my-subtask-bar > span {
    display: block;
    height: 100%;
    background: var(--primary-color);
}

.category-icon {
    width: 40px;
    height: 40px;
    background: var(--secondary-color);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-color);
    font-size: 1.2rem;
}
</style>
