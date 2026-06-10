<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="projects" />

        <main class="main-content">
            <header class="main-header">
                <h1>Projects</h1>
                <div class="header-actions">
                    <button @click="showCreateModal = true" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Project
                    </button>
                </div>
            </header>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Project Name</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Team</th>
                                <th>Dates</th>
                                <th>Budget</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="project in projects" :key="project.id">
                                <td>
                                    <strong>{{ project.name }}</strong>
                                    <span v-if="project.service_request" class="sub-text">
                                        SR: {{ project.service_request.request_id }}
                                    </span>
                                </td>
                                <td>
                                    <span :class="['status', getStatusClass(project.status)]">
                                        {{ formatStatus(project.status) }}
                                    </span>
                                </td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress" :style="`width: ${project.progress_percentage}%;`"></div>
                                    </div>
                                    <span class="sub-text">{{ project.progress_percentage }}%</span>
                                </td>
                                <td>{{ project.team_members?.length || 0 }} members</td>
                                <td>
                                    <div v-if="project.start_date">
                                        {{ formatDate(project.start_date) }}
                                        <span v-if="project.end_date"> - {{ formatDate(project.end_date) }}</span>
                                    </div>
                                    <span v-else class="sub-text">Not scheduled</span>
                                </td>
                                <td>${{ project.budget_amount || 0 }}</td>
                                <td>
                                    <div class="action-buttons">
                                        <Link :href="`/admin/projects/${project.id}`" class="btn btn-secondary btn-sm">
                                            View
                                        </Link>
                                        <Link :href="`/admin/projects/${project.id}/kanban`" class="btn btn-primary btn-sm">
                                            Kanban
                                        </Link>
                                        <Link :href="`/admin/projects/${project.id}/gantt`" class="btn btn-secondary btn-sm">
                                            Gantt
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <!-- Create Project Modal -->
        <div v-if="showCreateModal" class="modal-overlay">
            <div class="modal-content" @click.stop style="max-width: 600px;">
                <div class="modal-header">
                    <h3>Create New Project</h3>
                    <button @click="showCreateModal = false" class="close-btn">&times;</button>
                </div>
                <form @submit.prevent="createProject">
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Project Name *</label>
                            <input v-model="form.name" type="text" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea v-model="form.description" rows="3" class="form-control"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Start Date</label>
                                <input v-model="form.start_date" type="date" class="form-control">
                            </div>
                            <div class="form-group">
                                <label>End Date</label>
                                <input v-model="form.end_date" type="date" class="form-control">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Budget Amount</label>
                            <input v-model="form.budget_amount" type="number" step="0.01" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" @click="showCreateModal = false" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Project</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../../Components/AdminSidebar.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, reactive } from 'vue'
import { format } from 'date-fns'

const props = defineProps({
    projects: Array,
    users: Array
})

const showCreateModal = ref(false)
const form = reactive({
    name: '',
    description: '',
    start_date: '',
    end_date: '',
    budget_amount: ''
})

const getStatusClass = (status) => {
    const statusMap = {
        'planning': 'new',
        'active': 'approved',
        'on_hold': 'review',
        'completed': 'available',
        'cancelled': 'leave'
    }
    return statusMap[status] || 'new'
}

const formatStatus = (status) => {
    return status.replace('_', ' ').toUpperCase()
}

const formatDate = (date) => {
    return format(new Date(date), 'MMM dd, yyyy')
}

const createProject = () => {
    router.post('/admin/projects', form, {
        onSuccess: () => {
            showCreateModal.value = false
            Object.keys(form).forEach(key => form[key] = '')
        }
    })
}

defineOptions({
    layout: null
})
</script>

<style>

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-control {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 1rem;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-blue);
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    margin: 0;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--medium-grey);
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    padding: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--dark-grey);
}
</style>
