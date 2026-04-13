<template>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2 class="logo">TECHNICIAN WORLD</h2>
            </div>
            <nav class="sidebar-nav">
                <Link href="/admin/dashboard" class="nav-item">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </Link>
                <Link href="/admin/projects/dashboard" class="nav-item">
                    <i class="fas fa-chart-line"></i><span>PM Dashboard</span>
                </Link>
                <Link href="/admin/projects" class="nav-item">
                    <i class="fas fa-list"></i><span>Projects</span>
                </Link>
                <Link href="/admin/projects/timesheets" class="nav-item active">
                    <i class="fas fa-clock"></i><span>Timesheets</span>
                </Link>
                <Link href="/admin/rfq" class="nav-item">
                    <i class="fas fa-file-alt"></i><span>RFQ</span>
                </Link>
                <Link href="/admin/jobs" class="nav-item">
                    <i class="fas fa-tasks"></i><span>Jobs</span>
                </Link>
            </nav>
            <div class="sidebar-footer">
                <Link href="/logout" class="nav-item" method="post">
                    <i class="fas fa-sign-out-alt"></i><span>Log Out</span>
                </Link>
            </div>
        </aside>

        <main class="main-content">
            <header class="main-header">
                <h1>Timesheets & Time Reports</h1>
                <div class="header-actions">
                    <button @click="exportToCSV" class="btn btn-secondary">
                        <i class="fas fa-download"></i> Export CSV
                    </button>
                </div>
            </header>

            <!-- Summary Cards -->
            <section class="summary-section">
                <div class="summary-card">
                    <div class="summary-icon" style="background: #3b82f6;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="summary-content">
                        <h3>{{ getTotalHours() }}</h3>
                        <p>Total Hours</p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="background: #10b981;">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="summary-content">
                        <h3>{{ getBillableHours() }}</h3>
                        <p>Billable Hours</p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon" style="background: #8b5cf6;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div class="summary-content">
                        <h3>{{ timeLogs.data?.length || 0 }}</h3>
                        <p>Time Entries</p>
                    </div>
                </div>
            </section>

            <!-- Filters -->
            <section class="filters-section">
                <div class="filters-card">
                    <h3>Filters</h3>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label>Project</label>
                            <select v-model="filterForm.project_id" @change="applyFilters" class="form-control">
                                <option :value="null">All Projects</option>
                                <option v-for="project in projects" :key="project.id" :value="project.id">
                                    {{ project.name }}
                                </option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Team Member</label>
                            <select v-model="filterForm.user_id" @change="applyFilters" class="form-control">
                                <option :value="null">All Members</option>
                                <option v-for="user in users" :key="user.id" :value="user.id">
                                    {{ user.name }}
                                </option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Start Date</label>
                            <input v-model="filterForm.start_date" @change="applyFilters" type="date" class="form-control">
                        </div>
                        <div class="filter-group">
                            <label>End Date</label>
                            <input v-model="filterForm.end_date" @change="applyFilters" type="date" class="form-control">
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button @click="clearFilters" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </section>

            <!-- Time Logs Table -->
            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Time Log Entries</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Task</th>
                                <th>User</th>
                                <th>Started</th>
                                <th>Ended</th>
                                <th>Duration</th>
                                <th>Billable</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="log in timeLogs.data" :key="log.id">
                                <td>
                                    <Link :href="`/admin/projects/${log.task.project.id}`" class="link">
                                        {{ log.task.project.name }}
                                    </Link>
                                </td>
                                <td>{{ log.task.title }}</td>
                                <td>{{ log.user.name }}</td>
                                <td>{{ formatDateTime(log.started_at) }}</td>
                                <td>
                                    <span v-if="log.ended_at">{{ formatDateTime(log.ended_at) }}</span>
                                    <span v-else class="status review">Running</span>
                                </td>
                                <td>
                                    <strong>{{ formatDuration(log.duration_minutes) }}</strong>
                                </td>
                                <td>
                                    <span v-if="log.is_billable" class="billable-badge">
                                        <i class="fas fa-dollar-sign"></i> Billable
                                    </span>
                                    <span v-else class="sub-text">Non-billable</span>
                                </td>
                                <td>
                                    <span class="sub-text">{{ log.description || 'No description' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <div v-if="timeLogs.data?.length > 0" class="pagination">
                        <Link
                            v-for="link in timeLogs.links"
                            :key="link.label"
                            :href="link.url"
                            :class="['pagination-link', { active: link.active, disabled: !link.url }]"
                            v-html="link.label"
                        ></Link>
                    </div>

                    <div v-if="timeLogs.data?.length === 0" class="no-data">
                        <i class="fas fa-clock" style="font-size: 3rem; color: var(--medium-grey);"></i>
                        <p>No time logs found</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import { reactive } from 'vue'
import { format } from 'date-fns'

const props = defineProps({
    timeLogs: Object,
    projects: Array,
    users: Array,
    filters: Object
})

const filterForm = reactive({
    project_id: props.filters?.project_id || null,
    user_id: props.filters?.user_id || null,
    start_date: props.filters?.start_date || '',
    end_date: props.filters?.end_date || ''
})

const formatDateTime = (dateTime) => {
    return format(new Date(dateTime), 'MMM dd, yyyy HH:mm')
}

const formatDuration = (minutes) => {
    const hours = Math.floor(minutes / 60)
    const mins = minutes % 60
    return `${hours}h ${mins}m`
}

const getTotalHours = () => {
    const totalMinutes = props.timeLogs.data?.reduce((sum, log) => sum + log.duration_minutes, 0) || 0
    const hours = Math.floor(totalMinutes / 60)
    const mins = totalMinutes % 60
    return `${hours}h ${mins}m`
}

const getBillableHours = () => {
    const billableMinutes = props.timeLogs.data?.reduce((sum, log) => {
        return log.is_billable ? sum + log.duration_minutes : sum
    }, 0) || 0
    const hours = Math.floor(billableMinutes / 60)
    const mins = billableMinutes % 60
    return `${hours}h ${mins}m`
}

const applyFilters = () => {
    router.get('/admin/projects/timesheets', filterForm, {
        preserveState: true,
        preserveScroll: true
    })
}

const clearFilters = () => {
    Object.keys(filterForm).forEach(key => {
        filterForm[key] = null
    })
    router.get('/admin/projects/timesheets', {}, {
        preserveState: true
    })
}

const exportToCSV = () => {
    // Create CSV content
    const headers = ['Project', 'Task', 'User', 'Started', 'Ended', 'Duration (min)', 'Billable', 'Description']
    const rows = props.timeLogs.data?.map(log => [
        log.task.project.name,
        log.task.title,
        log.user.name,
        log.started_at,
        log.ended_at || 'Running',
        log.duration_minutes,
        log.is_billable ? 'Yes' : 'No',
        log.description || ''
    ]) || []

    let csvContent = headers.join(',') + '\n'
    rows.forEach(row => {
        csvContent += row.map(cell => `"${cell}"`).join(',') + '\n'
    })

    // Download CSV
    const blob = new Blob([csvContent], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `timesheets_${format(new Date(), 'yyyy-MM-dd')}.csv`
    link.click()
    window.URL.revokeObjectURL(url)
}

defineOptions({
    layout: null
})
</script>

<style scoped>
@import url('../../../../css/dashboard-app.css');

.summary-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.summary-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
}

.summary-content h3 {
    margin: 0;
    font-size: 1.75rem;
    font-weight: bold;
    color: var(--dark-grey);
}

.summary-content p {
    margin: 0.25rem 0 0 0;
    color: var(--medium-grey);
    font-size: 0.9rem;
}

.filters-section {
    margin-bottom: 2rem;
}

.filters-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.filters-card h3 {
    margin: 0 0 1rem 0;
    font-size: 1.1rem;
    color: var(--dark-grey);
}

.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--dark-grey);
    font-size: 0.9rem;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 0.9rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-blue);
}

.filter-actions {
    display: flex;
    justify-content: flex-end;
}

.billable-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    background: #d1fae5;
    color: #065f46;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}

.link {
    color: var(--primary-blue);
    text-decoration: none;
}

.link:hover {
    text-decoration: underline;
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 2rem;
    padding-top: 2rem;
    border-top: 1px solid var(--border-color);
}

.pagination-link {
    padding: 0.5rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    text-decoration: none;
    color: var(--dark-grey);
    transition: all 0.2s;
}

.pagination-link:hover:not(.disabled) {
    background: var(--light-blue);
    border-color: var(--primary-blue);
}

.pagination-link.active {
    background: var(--primary-blue);
    color: white;
    border-color: var(--primary-blue);
}

.pagination-link.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.no-data {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 4rem 2rem;
    color: var(--medium-grey);
}
</style>
