<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="dashboard" />

        <main class="main-content">
            <header class="main-header">
                <h1>Dashboard Overview</h1>
                <div class="header-actions">
                    <Link href="/admin/rfq" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Manual RFQ
                    </Link>
                </div>
            </header>

            <section class="kpi-grid">
                <div class="kpi-card">
                    <h4>Total Job Volume</h4>
                    <p class="kpi-value">{{ stats.totalJobs || '1,530' }}</p>
                    <span class="kpi-trend up">
                        <i class="fas fa-arrow-up"></i> {{ stats.jobsGrowth || '5%' }} this month
                    </span>
                </div>
                <div class="kpi-card">
                    <h4>Completion Rate</h4>
                    <p class="kpi-value">{{ stats.completionRate || '98.2%' }}</p>
                    <span class="kpi-trend up">
                        <i class="fas fa-arrow-up"></i> {{ stats.completionGrowth || '0.5%' }}
                    </span>
                </div>
                <div class="kpi-card">
                    <h4>Average Client Rating</h4>
                    <p class="kpi-value">{{ stats.averageRating || '4.8' }}/5</p>
                    <span class="kpi-trend stable">
                        <i class="fas fa-minus"></i> Stable
                    </span>
                </div>
                <div class="kpi-card">
                    <h4>Pending RFQs</h4>
                    <p class="kpi-value">{{ stats.pendingRfqs || '12' }}</p>
                    <span class="kpi-trend down">
                        <i class="fas fa-arrow-down"></i> {{ stats.rfqsChange || '3' }} fewer than yesterday
                    </span>
                </div>
            </section>

            <!-- Charts Section -->
            <section class="charts-section">
                <div class="chart-grid">
                    <div class="panel-card chart-card">
                        <div class="card-header">
                            <h3>Jobs by Status</h3>
                            <a href="#" @click.prevent="generateReport">Generate Report</a>
                        </div>
                        <div class="chart-container">
                            <canvas ref="statusChart" id="statusChart"></canvas>
                        </div>
                    </div>

                    <div class="panel-card chart-card">
                        <div class="card-header">
                            <h3>Jobs by Service Category</h3>
                            <select v-model="categoryTimeframe" @change="updateCategoryChart" class="chart-filter">
                                <option value="all">All Time</option>
                                <option value="month">This Month</option>
                                <option value="week">This Week</option>
                            </select>
                        </div>
                        <div class="chart-container">
                            <canvas ref="categoryChart" id="categoryChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Monthly Job Trends</h3>
                        <div class="chart-controls">
                            <select v-model="trendYear" @change="updateTrendChart" class="chart-filter">
                                <option value="2024">2024</option>
                                <option value="2025">2025</option>
                            </select>
                        </div>
                    </div>
                    <div class="chart-container large">
                        <canvas ref="trendChart" id="trendChart"></canvas>
                    </div>
                </div>
            </section>


            <section class="main-panel">
                <div class="panel-card activity-card">
                    <div class="card-header">
                        <h3>Recent Activity</h3>
                        <Link href="/admin/activity">View All</Link>
                    </div>
                    <ul class="activity-list">
                        <li v-for="activity in recentActivity" :key="activity.id">
                            <span :class="['activity-icon', activity.type]">
                                <i :class="activity.icon"></i>
                            </span>
                            {{ activity.message }}
                        </li>
                    </ul>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { Link } from '@inertiajs/vue3'
import { ref, onMounted, nextTick } from 'vue'
import Chart from 'chart.js/auto'

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({})
    },
    chartData: {
        type: Object,
        default: () => ({
            statusData: { pending: 12, assigned: 8, in_progress: 15, completed: 45, cancelled: 3 },
            categoryData: { electrical: 25, plumbing: 18, hvac: 12, carpentry: 15, painting: 20, roofing: 8, other: 12 },
            trendData: {
                2024: [10, 15, 18, 22, 28, 25, 30, 35, 32, 38, 42, 45],
                2025: [48, 52, 45, 58, 62, 55, 68, 72, 65, 75, 78, 82]
            }
        })
    },
    recentActivity: {
        type: Array,
        default: () => [
            {
                id: 1,
                type: 'job',
                icon: 'fas fa-check',
                message: 'Job #J4512 completed by T. Mwangi'
            },
            {
                id: 2,
                type: 'rfq',
                icon: 'fas fa-file-alt',
                message: 'New RFQ received from Client A'
            },
            {
                id: 3,
                type: 'user',
                icon: 'fas fa-user-plus',
                message: 'New technician, L. Otieno, onboarded'
            },
            {
                id: 4,
                type: 'payment',
                icon: 'fas fa-credit-card',
                message: 'Payment received for Job #J4509'
            }
        ]
    }
})

// Chart refs
const statusChart = ref(null)
const categoryChart = ref(null)
const trendChart = ref(null)

// Chart instances
let statusChartInstance = null
let categoryChartInstance = null
let trendChartInstance = null

// Reactive data
const categoryTimeframe = ref('all')
const trendYear = ref('2025')

// Chart colors
const colors = {
    primary: '#053272',
    success: '#16A34A',
    warning: '#F97316',
    danger: '#DC2626',
    info: '#2563EB',
    purple: '#9333EA',
    teal: '#0D9488',
    gray: '#6B7280'
}

const initStatusChart = async () => {
    await nextTick()
    if (!statusChart.value) return

    const ctx = statusChart.value.getContext('2d')

    statusChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'Assigned', 'In Progress', 'Completed', 'Cancelled'],
            datasets: [{
                data: [
                    props.chartData.statusData.pending,
                    props.chartData.statusData.assigned,
                    props.chartData.statusData.in_progress,
                    props.chartData.statusData.completed,
                    props.chartData.statusData.cancelled
                ],
                backgroundColor: [
                    colors.warning,
                    colors.info,
                    colors.primary,
                    colors.success,
                    colors.danger
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true
                    }
                }
            }
        }
    })
}

const initCategoryChart = async () => {
    await nextTick()
    if (!categoryChart.value) return

    const ctx = categoryChart.value.getContext('2d')

    categoryChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Electrical', 'Plumbing', 'HVAC', 'Carpentry', 'Painting', 'Roofing', 'Other'],
            datasets: [{
                label: 'Number of Jobs',
                data: [
                    props.chartData.categoryData.electrical,
                    props.chartData.categoryData.plumbing,
                    props.chartData.categoryData.hvac,
                    props.chartData.categoryData.carpentry,
                    props.chartData.categoryData.painting,
                    props.chartData.categoryData.roofing,
                    props.chartData.categoryData.other
                ],
                backgroundColor: [
                    colors.warning,
                    colors.info,
                    colors.success,
                    colors.purple,
                    colors.teal,
                    colors.danger,
                    colors.gray
                ],
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 5
                    }
                }
            }
        }
    })
}

const initTrendChart = async () => {
    await nextTick()
    if (!trendChart.value) return

    const ctx = trendChart.value.getContext('2d')

    trendChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: `Jobs in ${trendYear.value}`,
                data: props.chartData.trendData[trendYear.value],
                borderColor: colors.primary,
                backgroundColor: colors.primary + '20',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: colors.primary,
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 10
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    })
}

const updateCategoryChart = () => {
    // In a real app, this would fetch filtered data
    console.log('Updating category chart for timeframe:', categoryTimeframe.value)
}

const updateTrendChart = () => {
    if (trendChartInstance) {
        trendChartInstance.data.datasets[0].data = props.chartData.trendData[trendYear.value]
        trendChartInstance.data.datasets[0].label = `Jobs in ${trendYear.value}`
        trendChartInstance.update()
    }
}

const generateReport = () => {
    console.log('Generating report...')
}

onMounted(() => {
    initStatusChart()
    initCategoryChart()
    initTrendChart()
})

defineOptions({
    layout: null
})
</script>

<style>

/* Chart specific styles */
.charts-section {
    margin-bottom: 2.5rem;
}

.chart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.chart-container {
    position: relative;
    height: 300px;
    padding: 1rem;
}

.chart-container.large {
    height: 400px;
}

.chart-filter {
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: white;
    font-size: 0.9rem;
}

.chart-controls {
    display: flex;
    gap: 1rem;
    align-items: center;
}

/* Responsive chart layout */
@media (max-width: 1024px) {
    .chart-grid {
        grid-template-columns: 1fr;
    }

    .chart-container {
        height: 280px;
    }
}

@media (max-width: 768px) {
    .chart-container {
        height: 240px;
        padding: 0.75rem;
    }

    .chart-container.large {
        height: 300px;
    }

    .chart-controls {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
}
</style>
