<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="projects" />

        <main class="main-content">
            <header class="main-header">
                <h1>Project Management Dashboard</h1>
                <div class="header-actions">
                    <Link href="/admin/projects" class="btn btn-secondary">
                        <i class="fas fa-list"></i> View All Projects
                    </Link>
                    <Link href="/admin/projects/timesheets" class="btn btn-secondary">
                        <i class="fas fa-clock"></i> Timesheets
                    </Link>
                </div>
            </header>

            <!-- KPI Cards -->
            <section class="kpi-section">
                <div class="kpi-card">
                    <div class="kpi-icon" style="background: #3b82f6;">
                        <i class="fas fa-project-diagram"></i>
                    </div>
                    <div class="kpi-content">
                        <h3>{{ stats.totalProjects }}</h3>
                        <p>Total Projects</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background: #10b981;">
                        <i class="fas fa-play-circle"></i>
                    </div>
                    <div class="kpi-content">
                        <h3>{{ stats.activeProjects }}</h3>
                        <p>Active Projects</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background: #8b5cf6;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="kpi-content">
                        <h3>{{ stats.completedProjects }}</h3>
                        <p>Completed Projects</p>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon" style="background: #f59e0b;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="kpi-content">
                        <h3>{{ stats.totalHoursLogged }}</h3>
                        <p>Total Hours Logged</p>
                    </div>
                </div>
            </section>

            <!-- Charts Section -->
            <section class="charts-section">
                <div class="chart-card">
                    <h3>Project Status Distribution</h3>
                    <canvas ref="statusChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Team Workload</h3>
                    <canvas ref="workloadChart"></canvas>
                </div>
            </section>

            <!-- Recent Activities -->
            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Recent Activities</h3>
                    </div>
                    <div class="activity-timeline">
                        <div v-for="activity in recentActivities" :key="activity.id" class="activity-item">
                            <div class="activity-icon">
                                <i :class="getActivityIcon(activity.activity_type)"></i>
                            </div>
                            <div class="activity-content">
                                <p class="activity-description">{{ activity.description }}</p>
                                <div class="activity-meta">
                                    <span class="activity-user">{{ activity.user.name }}</span>
                                    <span class="activity-project">{{ activity.project.name }}</span>
                                    <span class="activity-time">{{ formatDate(activity.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-if="recentActivities.length === 0" class="no-activities">
                            No recent activities
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import AdminSidebar from '../../../Components/AdminSidebar.vue'
import { Link } from '@inertiajs/vue3'
import { ref, onMounted } from 'vue'
import { Chart, registerables } from 'chart.js'
import { formatDistanceToNow } from 'date-fns'

Chart.register(...registerables)

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({})
    },
    projectsByStatus: {
        type: Object,
        default: () => ({})
    },
    teamWorkload: {
        type: Array,
        default: () => []
    },
    recentActivities: {
        type: Array,
        default: () => []
    }
})

const statusChart = ref(null)
const workloadChart = ref(null)

onMounted(() => {
    // Project Status Pie Chart
    new Chart(statusChart.value, {
        type: 'pie',
        data: {
            labels: Object.keys(props.projectsByStatus).map(k => k.replace('_', ' ').toUpperCase()),
            datasets: [{
                data: Object.values(props.projectsByStatus),
                backgroundColor: [
                    '#3b82f6', // planning - blue
                    '#10b981', // active - green
                    '#f59e0b', // on_hold - amber
                    '#8b5cf6', // completed - purple
                    '#ef4444', // cancelled - red
                ],
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    })

    // Team Workload Bar Chart
    new Chart(workloadChart.value, {
        type: 'bar',
        data: {
            labels: props.teamWorkload.map(u => u.name),
            datasets: [{
                label: 'Active Tasks',
                data: props.teamWorkload.map(u => u.assigned_tasks_count),
                backgroundColor: '#3b82f6',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    })
})

const getActivityIcon = (type) => {
    const iconMap = {
        'project_created': 'fas fa-plus-circle',
        'project_updated': 'fas fa-edit',
        'task_created': 'fas fa-tasks',
        'task_updated': 'fas fa-edit',
        'task_completed': 'fas fa-check-circle',
        'task_assigned': 'fas fa-user-plus',
        'comment_added': 'fas fa-comment',
        'file_uploaded': 'fas fa-file-upload',
        'member_added': 'fas fa-user-plus',
        'member_removed': 'fas fa-user-minus',
    }
    return iconMap[type] || 'fas fa-circle'
}

const formatDate = (date) => {
    return formatDistanceToNow(new Date(date), { addSuffix: true })
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../../css/dashboard-app.css');

.kpi-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.kpi-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.kpi-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
}

.kpi-content h3 {
    margin: 0;
    font-size: 2rem;
    font-weight: bold;
    color: var(--dark-grey);
}

.kpi-content p {
    margin: 0.25rem 0 0 0;
    color: var(--medium-grey);
    font-size: 0.9rem;
}

.charts-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.chart-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.chart-card h3 {
    margin: 0 0 1rem 0;
    color: var(--dark-grey);
}

.chart-card canvas {
    height: 300px !important;
}

.activity-timeline {
    max-height: 500px;
    overflow-y: auto;
}

.activity-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--light-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-blue);
    flex-shrink: 0;
}

.activity-content {
    flex: 1;
}

.activity-description {
    margin: 0 0 0.5rem 0;
    color: var(--dark-grey);
}

.activity-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.85rem;
    color: var(--medium-grey);
}

.no-activities {
    text-align: center;
    padding: 2rem;
    color: var(--medium-grey);
}
</style>
