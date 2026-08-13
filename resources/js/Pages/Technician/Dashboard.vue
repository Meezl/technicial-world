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
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.25rem; color: var(--success-color);">{{ formatCurrency(earningsSummary?.total_paid || 0) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Paid So Far</p>
                </div>
                <div class="pwa-card" style="text-align: center; padding: 1rem 0.5rem; margin-bottom: 0;">
                    <h3 style="margin: 0; font-size: 1.25rem; color: var(--warning-color);">{{ formatCurrency(earningsSummary?.total_outstanding || 0) }}</h3>
                    <p style="margin: 0; font-size: 0.8rem; color: var(--light-text);">Still Owed</p>
                </div>
            </div>

            <!-- Reviewed reports waiting to be posted to the office. The office
                 sees nothing on a lead-run job until the lead posts, so this
                 reminds them before they even open the job. -->
            <div
                v-if="pendingReportPosts > 0"
                class="post-reports-reminder"
            >
                <div class="prr-icon"><i class="fas fa-paper-plane"></i></div>
                <div class="prr-body">
                    <span class="prr-kicker">Reports to send</span>
                    <h3>{{ pendingReportPosts }} reviewed {{ pendingReportPosts === 1 ? 'report' : 'reports' }} ready to post</h3>
                    <p>The office won't see this progress until you post it. Open the job to send it up.</p>
                    <div class="prr-jobs">
                        <Link
                            v-for="job in jobsAwaitingPost"
                            :key="job.id"
                            :href="`/technician/jobs/${job.id}`"
                            class="prr-chip"
                        >
                            {{ job.service_category?.name || job.request_id }}
                            <span class="prr-count">{{ job.postable_report_count }}</span>
                        </Link>
                    </div>
                </div>
            </div>

            <Link href="/technician/earnings" class="pwa-card" style="display: block; text-decoration: none; color: inherit; margin-bottom: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                    <div>
                        <span style="display: inline-block; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--primary-color); margin-bottom: 0.35rem;">Money Overview</span>
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 1rem;">See paid and owed amounts per job</h3>
                        <p style="margin: 0; font-size: 0.85rem; color: var(--light-text);">
                            {{ earningsSummary?.job_count || 0 }} job{{ earningsSummary?.job_count === 1 ? '' : 's' }} with compensation tracking.
                        </p>
                    </div>
                    <i class="fas fa-chevron-right" style="color: var(--light-text);"></i>
                </div>
            </Link>

            <!-- Company Documents — Technician World's KRA PIN, always
                 downloadable so technicians doing procurement or purchases
                 on TW's behalf can hand it to the supplier on the spot
                 instead of asking ops every time. -->
            <a
                href="/documents/technician-world-kra-pin.pdf"
                target="_blank"
                rel="noopener"
                download
                class="pwa-card"
                style="display: block; text-decoration: none; color: inherit; margin-bottom: 1rem;"
            >
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                    <div>
                        <span style="display: inline-block; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #b45309; margin-bottom: 0.35rem;">Company Documents</span>
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 1rem;">
                            <i class="fas fa-file-pdf" style="color: #dc2626; margin-right: 0.4rem;"></i>
                            Technician World KRA PIN
                        </h3>
                        <p style="margin: 0; font-size: 0.85rem; color: var(--light-text);">
                            Download for suppliers when doing procurement on behalf of Technician World.
                        </p>
                    </div>
                    <i class="fas fa-download" style="color: var(--primary-color);"></i>
                </div>
            </a>

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

                    <div v-if="job.compensation_summary" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem;">
                        <div style="background: #F8FAFC; padding: 0.75rem; border-radius: 10px;">
                            <span style="display: block; font-size: 0.72rem; color: var(--light-text); margin-bottom: 0.2rem;">Paid</span>
                            <strong style="color: var(--success-color);">{{ formatCurrency(job.compensation_summary.paid_to_date) }}</strong>
                        </div>
                        <div style="background: #FFF7ED; padding: 0.75rem; border-radius: 10px;">
                            <span style="display: block; font-size: 0.72rem; color: var(--light-text); margin-bottom: 0.2rem;">Still Owed</span>
                            <strong style="color: #c2410c;">{{ formatCurrency(job.compensation_summary.outstanding_balance) }}</strong>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <!-- Using plain button for now, can implement accept/decline logic later -->
                        <button class="btn btn-primary" @click.stop="startJob(job)">
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

                    <div v-if="job.compensation_summary" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 1rem;">
                        <div style="background: #F8FAFC; padding: 0.75rem; border-radius: 10px;">
                            <span style="display: block; font-size: 0.72rem; color: var(--light-text); margin-bottom: 0.2rem;">Paid</span>
                            <strong style="color: var(--success-color);">{{ formatCurrency(job.compensation_summary.paid_to_date) }}</strong>
                        </div>
                        <div style="background: #FFF7ED; padding: 0.75rem; border-radius: 10px;">
                            <span style="display: block; font-size: 0.72rem; color: var(--light-text); margin-bottom: 0.2rem;">Still Owed</span>
                            <strong style="color: #c2410c;">{{ formatCurrency(job.compensation_summary.outstanding_balance) }}</strong>
                        </div>
                    </div>

                    <!-- Which part of a multi-trade job is actually theirs. -->
                    <div v-if="mySubTasks(job).length" class="my-subtasks">
                        <span class="my-subtasks-label">Your work on this job</span>
                        <div v-for="task in mySubTasks(job)" :key="task.id" class="my-subtask-row">
                            <span class="my-subtask-title">{{ task.title }}</span>
                            <span class="my-subtask-progress">{{ task.progress_percentage || 0 }}%</span>
                        </div>
                    </div>

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
                            @click.stop="updateStatus(job, 'on_site')"
                        >
                            Arrived On Site
                        </button>
                        <!-- Closing the job is the lead's call; a crew member
                             finishes their own sub-task instead. -->
                        <button
                            v-else-if="leadsJob(job)"
                            class="btn btn-outline"
                            style="border-color: var(--success-color); color: var(--success-color);"
                            @click.stop="updateStatus(job, 'completed')"
                        >
                            Mark Complete
                        </button>
                        
                        <Link href="/technician/tools" class="btn btn-outline" @click.stop>
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
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue';

const props = defineProps({
    technician: Object,
    incomingJobs: Array,
    activeJobs: Array,
    completedJobsCount: Number,
    earningsSummary: Object,
    pendingReportPosts: { type: Number, default: 0 },
});

const isAvailable = computed(() => props.technician?.availability === 'available');

// Active jobs where this lead has reviewed reports waiting to be posted —
// the ones the reminder links straight to.
const jobsAwaitingPost = computed(() =>
    (props.activeJobs || []).filter((job) => (job.postable_report_count || 0) > 0)
);

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

// The sub-tasks on this job that belong to the technician looking at it.
const mySubTasks = (job) =>
    (job.sub_tasks || []).filter((task) => task.technician_id === props.technician?.id);

// Mirrors ServiceRequest::isLeadTechnician — lead_technician_id wherever it
// is set, falling back to technician_id on a job that was never split.
const leadsJob = (job) =>
    job.lead_technician_id
        ? job.lead_technician_id === props.technician?.id
        : job.technician_id === props.technician?.id;

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

const formatCurrency = (amount) => {
    return 'KES ' + Number(amount || 0).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

// Disable default layout
defineOptions({ layout: null });
</script>

<style>
/* Lead reminder: reviewed reports waiting to be posted to the office. */
.post-reports-reminder {
    display: flex;
    gap: .85rem;
    margin-bottom: 1rem;
    padding: .95rem 1rem;
    background: #fef3c7;
    border: 1px solid #fcd34d;
    border-radius: 12px;
}
.prr-icon {
    flex: none;
    width: 2.2rem;
    height: 2.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #f59e0b;
    color: #fff;
    font-size: .9rem;
}
.prr-body { min-width: 0; }
.prr-kicker {
    display: block;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #b45309;
    margin-bottom: .2rem;
}
.prr-body h3 { margin: 0 0 .2rem; font-size: 1rem; color: #78350f; }
.prr-body p { margin: 0; font-size: .84rem; color: #92400e; }
.prr-jobs {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: .6rem;
}
.prr-chip {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .28rem .6rem;
    background: #fff;
    border: 1px solid #f4d58d;
    border-radius: 999px;
    font-size: .78rem;
    font-weight: 600;
    color: #92400e;
    text-decoration: none;
}
.prr-chip .prr-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.15rem;
    height: 1.15rem;
    padding: 0 .3rem;
    border-radius: 999px;
    background: #f59e0b;
    color: #fff;
    font-size: .68rem;
}

.my-subtasks {
    margin-bottom: 1rem;
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
    white-space: nowrap;
    font-weight: 600;
    color: var(--light-text);
}
</style>
