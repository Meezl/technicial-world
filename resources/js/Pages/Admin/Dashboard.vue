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

            <!-- Payment data-integrity health alerts. Visible only when
                 a problem is detected so the dashboard stays clean day-to-day. -->
            <div v-if="healthAlerts.length" class="health-alerts">
                <div
                    v-for="(alert, idx) in healthAlerts"
                    :key="idx"
                    :class="['health-alert', `health-alert-${alert.severity}`]"
                >
                    <i :class="alert.severity === 'critical' ? 'fas fa-triangle-exclamation' : 'fas fa-circle-exclamation'"></i>
                    <div>
                        <strong>{{ alert.title }}</strong>
                        <p>{{ alert.message }}</p>
                    </div>
                </div>
            </div>

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
                                <option v-for="y in Object.keys(chartData.trendData)" :key="y" :value="y">{{ y }}</option>
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
        default: () => ({ statusData: {}, categoryData: {}, trendData: {} })
    },
    recentActivity: {
        type: Array,
        default: () => []
    },
    healthAlerts: {
        type: Array,
        default: () => []
    },
})

// Make alerts available to the template (just an alias for readability)
const healthAlerts = props.healthAlerts

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
const trendYear = ref(String(new Date().getFullYear()))

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

// Cycle through palette so any number of categories/statuses gets a colour
const palette = [colors.warning, colors.info, colors.primary, colors.success, colors.danger, colors.purple, colors.teal, colors.gray]
const pickColors = (n) => Array.from({ length: n }, (_, i) => palette[i % palette.length])
const prettyLabel = (s) => String(s || '').replace(/[_-]/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

const initStatusChart = async () => {
    await nextTick()
    if (!statusChart.value) return

    const ctx = statusChart.value.getContext('2d')

    // Render whatever statuses the backend sends — no hardcoded keys
    const entries = Object.entries(props.chartData.statusData || {})
        .filter(([, v]) => Number(v) > 0)
        .sort((a, b) => b[1] - a[1])

    if (!entries.length) {
        ctx.font = '14px sans-serif'
        ctx.fillStyle = '#9ca3af'
        ctx.textAlign = 'center'
        ctx.fillText('No job data yet', ctx.canvas.width / 2, ctx.canvas.height / 2)
        return
    }

    statusChartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: entries.map(([k]) => prettyLabel(k)),
            datasets: [{
                data: entries.map(([, v]) => v),
                backgroundColor: pickColors(entries.length),
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
                    labels: { padding: 20, usePointStyle: true }
                }
            }
        }
    })
}

const initCategoryChart = async () => {
    await nextTick()
    if (!categoryChart.value) return

    const ctx = categoryChart.value.getContext('2d')

    // Render whatever categories the backend sends, sorted descending — no hardcoded keys
    const entries = Object.entries(props.chartData.categoryData || {})
        .filter(([, v]) => Number(v) > 0)
        .sort((a, b) => b[1] - a[1])

    if (!entries.length) {
        ctx.font = '14px sans-serif'
        ctx.fillStyle = '#9ca3af'
        ctx.textAlign = 'center'
        ctx.fillText('No category data yet', ctx.canvas.width / 2, ctx.canvas.height / 2)
        return
    }

    categoryChartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: entries.map(([k]) => prettyLabel(k)),
            datasets: [{
                label: 'Number of Jobs',
                data: entries.map(([, v]) => v),
                backgroundColor: pickColors(entries.length),
                borderRadius: 4,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: (ctx) => `${ctx.label}: ${ctx.parsed.y} job${ctx.parsed.y === 1 ? '' : 's'}`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                },
                x: {
                    ticks: {
                        autoSkip: false,
                        maxRotation: 35,
                        minRotation: 0,
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

.health-alerts { display: flex; flex-direction: column; gap: 0.6rem; padding: 0 1rem; margin-bottom: 1rem; }
.health-alert {
    display: flex;
    gap: 0.85rem;
    align-items: flex-start;
    padding: 0.85rem 1rem;
    border-radius: 8px;
    border: 1px solid transparent;
}
.health-alert i { font-size: 1.25rem; margin-top: 2px; flex-shrink: 0; }
.health-alert strong { display: block; margin-bottom: 0.2rem; }
.health-alert p { margin: 0; font-size: 0.9rem; line-height: 1.45; }
.health-alert-critical {
    background: #fee2e2;
    border-color: #fca5a5;
    color: #7f1d1d;
}
.health-alert-warning {
    background: #fef3c7;
    border-color: #fbbf24;
    color: #78350f;
}
</style>
