<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="projects" />

        <!-- Project Navigation Tree (Wrike-like Pane 1) -->
        <aside class="project-tree-sidebar">
            <div class="tree-header">
                <h3>Projects</h3>
            </div>
            <ProjectNavigationTree 
                :projects="allProjects" 
                :currentProjectId="project.id" 
            />
        </aside>

        <main class="main-content">
            <header class="main-header">
                <div>
                    <h1>{{ project.name }}</h1>
                    <p class="sub-text">{{ project.description }}</p>
                </div>
                <div class="header-actions">
                    <button @click="showEditProject = true" class="btn btn-secondary">
                        <i class="fas fa-edit"></i> Edit Project
                    </button>
                    <button @click="showAddTask = true" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Task
                    </button>
                </div>
            </header>

            <!-- Project Info Cards -->
            <section class="project-info-section">
                <div class="info-card">
                    <label>Status</label>
                    <span :class="['status', getStatusClass(project.status)]">
                        {{ formatStatus(project.status) }}
                    </span>
                </div>
                <div class="info-card">
                    <label>Progress</label>
                    <div class="progress-bar">
                        <div class="progress" :style="`width: ${project.progress_percentage}%;`"></div>
                    </div>
                    <span>{{ project.progress_percentage }}%</span>
                </div>
                <div class="info-card">
                    <label>Timeline</label>
                    <div v-if="project.start_date">
                        {{ formatDate(project.start_date) }} - {{ formatDate(project.end_date) }}
                    </div>
                    <span v-else>Not scheduled</span>
                </div>
                <div class="info-card">
                    <label>Budget / Actual</label>
                    <div>${{ project.budget_amount || 0 }} / ${{ project.actual_cost || 0 }}</div>
                </div>
            </section>

            <!-- Tabs -->
            <section class="tabs-section">
                <div class="tabs">
                    <button
                        @click="switchTab('overview')"
                        :class="['tab', { active: activeTab === 'overview' }]"
                    >
                        Overview
                    </button>
                    <button
                        @click="switchTab('tasks')"
                        :class="['tab', { active: activeTab === 'tasks' }]"
                    >
                        Tasks ({{ project.tasks?.length || 0 }})
                    </button>
                    <button
                        @click="switchTab('files')"
                        :class="['tab', { active: activeTab === 'files' }]"
                    >
                        Files ({{ project.files?.length || 0 }})
                    </button>
                    <button
                        @click="switchTab('activity')"
                        :class="['tab', { active: activeTab === 'activity' }]"
                    >
                        Activity
                    </button>
                </div>

                <!-- Overview Tab -->
                <div v-if="activeTab === 'overview'" class="tab-content">
                    <div class="overview-grid">
                        <div class="overview-card">
                            <h3>Team Members</h3>
                            <div class="team-list">
                                <div v-for="memberId in project.team_members" :key="memberId" class="team-member">
                                    <div class="member-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <span>{{ getUserName(memberId) }}</span>
                                </div>
                                <button @click="showAddMember = true" class="add-member-btn">
                                    <i class="fas fa-plus"></i> Add Member
                                </button>
                            </div>
                        </div>

                        <div class="overview-card">
                            <h3>Quick Stats</h3>
                            <div class="stats-list">
                                <div class="stat-item">
                                    <label>Total Tasks:</label>
                                    <span>{{ project.tasks?.length || 0 }}</span>
                                </div>
                                <div class="stat-item">
                                    <label>Completed:</label>
                                    <span>{{ getCompletedTasksCount() }}</span>
                                </div>
                                <div class="stat-item">
                                    <label>In Progress:</label>
                                    <span>{{ getInProgressTasksCount() }}</span>
                                </div>
                                <div class="stat-item">
                                    <label>Files:</label>
                                    <span>{{ project.files?.length || 0 }}</span>
                                </div>
                            </div>
                        </div>

                        <div v-if="project.service_request" class="overview-card">
                            <h3>Linked Service Request</h3>
                            <div class="sr-info">
                                <p><strong>Request ID:</strong> {{ project.service_request.request_id }}</p>
                                <p><strong>Status:</strong> {{ project.service_request.status }}</p>
                                <Link :href="`/admin/jobs/${project.service_request.id}`" class="btn btn-secondary btn-sm">
                                    View Service Request
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tasks Tab - Wrike Style Split View -->
                <div v-if="activeTab === 'tasks'" class="tasks-tab-content">
                    <div class="tasks-split-view">
                        <!-- Left Panel: Tree Grid -->
                        <div class="tasks-tree-container" :class="{ 'shrunk': selectedTask }">
                            <div class="tasks-header-row">
                                <div class="header-cell title-col">Title</div>
                                <div class="header-cell status-col">Status</div>
                                <div class="header-cell priority-col">Priority</div>
                                <div class="header-cell assignee-col">Assignee</div>
                                <div class="header-cell date-col">Due Date</div>
                            </div>
                            
                            <div class="tasks-list-body">
                                <div v-if="!rootTasks.length" class="empty-state">
                                    No tasks yet. Click "Add Task" to get started.
                                </div>
                                <TaskTreeRow
                                    v-for="task in rootTasks"
                                    :key="task.id"
                                    :task="task"
                                    :users="users"
                                    :selected-task-id="selectedTask?.id"
                                    @select="selectTask"
                                    @update="handleTaskUpdate"
                                />
                            </div>
                        </div>

                        <!-- Right Panel: Task Details -->
                        <div v-if="selectedTask" class="task-detail-container">
                            <TaskDetailPanel 
                                :task="selectedTask"
                                :users="users"
                                @close="selectedTask = null"
                                @update="refreshProject"
                            />
                        </div>
                    </div>
                </div>

                <!-- Files Tab -->
                <div v-if="activeTab === 'files'" class="tab-content">
                    <div class="files-section">
                        <div class="upload-area">
                            <input
                                type="file"
                                ref="fileInput"
                                @change="uploadFile"
                                style="display: none;"
                            >
                            <button @click="$refs.fileInput.click()" class="btn btn-primary">
                                <i class="fas fa-upload"></i> Upload File
                            </button>
                        </div>

                        <div class="files-list">
                            <div v-for="file in project.files" :key="file.id" class="file-item">
                                <div class="file-icon">
                                    <i :class="getFileIcon(file.mime_type)"></i>
                                </div>
                                <div class="file-info">
                                    <div class="file-name">{{ file.file_name }}</div>
                                    <div class="file-meta">
                                        Uploaded by {{ file.uploader.name }} • {{ formatDate(file.created_at) }}
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a :href="`/admin/projects/files/${file.id}/download`" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-download"></i> Download
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Tab -->
                <div v-if="activeTab === 'activity'" class="tab-content">
                    <div class="activity-timeline">
                        <div v-for="activity in project.activities" :key="activity.id" class="activity-item">
                            <div class="activity-icon">
                                <i :class="getActivityIcon(activity.activity_type)"></i>
                            </div>
                            <div class="activity-content">
                                <p class="activity-description">{{ activity.description }}</p>
                                <div class="activity-meta">
                                    <span class="activity-user">{{ activity.user.name }}</span>
                                    <span class="activity-time">{{ formatDate(activity.created_at) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <!-- Add Task Modal -->
        <div v-if="showAddTask" class="modal-overlay" @click="showAddTask = false">
            <div class="modal-content" @click.stop style="max-width: 600px;">
                <div class="modal-header">
                    <h3>Add New Task</h3>
                    <button @click="showAddTask = false" class="close-btn">&times;</button>
                </div>
                <form @submit.prevent="createTask">
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Task Title *</label>
                            <input v-model="taskForm.title" type="text" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea v-model="taskForm.description" rows="3" class="form-control"></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Priority</label>
                                <select v-model="taskForm.priority" class="form-control">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Assigned To</label>
                                <select v-model="taskForm.assigned_to" class="form-control">
                                    <option :value="null">Unassigned</option>
                                    <option v-for="user in users" :key="user.id" :value="user.id">
                                        {{ user.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Due Date</label>
                            <input v-model="taskForm.due_date" type="datetime-local" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" @click="showAddTask = false" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../../Components/AdminSidebar.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'
import { format, isPast } from 'date-fns'
import TaskTreeRow from './Components/TaskTreeRow.vue'
import TaskDetailPanel from './TaskDetailPanel.vue'
import ProjectNavigationTree from './Components/ProjectNavigationTree.vue'

const props = defineProps({
    project: Object,
    users: Array,
    allProjects: {
        type: Array,
        default: () => []
    }
})

const activeTab = ref('overview')
const showAddTask = ref(false)
const showEditProject = ref(false)
const showAddMember = ref(false)
const fileInput = ref(null)
const selectedTask = ref(null)

// Keep selectedTask in sync when underlying data changes (e.g. after comment submission)
import { watch } from 'vue'
watch(() => props.project.tasks, (newTasks) => {
    if (selectedTask.value && newTasks) {
        const fresh = newTasks.find(t => t.id === selectedTask.value.id)
        if (fresh) {
            selectedTask.value = fresh
        }
    }
}, { deep: true })

const taskForm = reactive({
    title: '',
    description: '',
    priority: 'medium',
    assigned_to: null,
    due_date: ''
})

// Filter root tasks (those without parents)
const rootTasks = computed(() => {
    return props.project.tasks?.filter(t => !t.parent_task_id) || []
})

const switchTab = (tab) => {
    activeTab.value = tab
    if (tab !== 'tasks') {
        selectedTask.value = null // Close panel when leaving tab
    }
}

const selectTask = (task) => {
    if (selectedTask.value?.id === task.id) {
        selectedTask.value = null // Toggle off
    } else {
        selectedTask.value = task
    }
}

const handleTaskUpdate = (updatedFields) => {
    // For inline edits in tree row
    router.put(`/admin/tasks/${updatedFields.id}`, updatedFields, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            // Need to update local SelectedTask if it's the one being edited
            if (selectedTask.value && selectedTask.value.id === updatedFields.id) {
                // In a real app we might fetch fresh data, but here Inertia props should update automatically
                // However, selectedTask is a separate Ref, so we should sync it with new props
                const newTask = props.project.tasks.find(t => t.id === updatedFields.id)
                if (newTask) selectedTask.value = newTask
            }
        }
    })
}

const refreshProject = () => {
    // Called when TaskDetailPanel emits update
    // Just force a reload or let Inertia handle it via props update preserved state
    // Actually, Inertia reload is automatic on form success in child components?
    // Child uses router.put/post, so props will update.
    // We just need to make sure selectedTask stays in sync
    if (selectedTask.value) {
        const freshTask = props.project.tasks.find(t => t.id === selectedTask.value.id)
        if (freshTask) selectedTask.value = freshTask
    }
}

const getStatusClass = (status) => {
    const map = { 'planning': 'new', 'active': 'approved', 'on_hold': 'review', 'completed': 'available', 'cancelled': 'leave' }
    return map[status] || 'new'
}

const formatStatus = (status) => status.replace('_', ' ').toUpperCase()

const formatDate = (date) => format(new Date(date), 'MMM dd, yyyy')

const getUserName = (userId) => {
    const user = props.users?.find(u => u.id === userId)
    return user ? user.name : 'Unknown'
}

const getCompletedTasksCount = () => props.project.tasks?.filter(t => t.status === 'done').length || 0
const getInProgressTasksCount = () => props.project.tasks?.filter(t => t.status === 'in_progress').length || 0

const getActivityIcon = (type) => {
    const map = {
        'project_created': 'fas fa-plus-circle', 'task_created': 'fas fa-tasks',
        'task_completed': 'fas fa-check-circle', 'task_assigned': 'fas fa-user-plus',
        'comment_added': 'fas fa-comment', 'file_uploaded': 'fas fa-file-upload'
    }
    return map[type] || 'fas fa-circle'
}

const getFileIcon = (mimeType) => {
    if (mimeType?.includes('pdf')) return 'fas fa-file-pdf'
    if (mimeType?.includes('word')) return 'fas fa-file-word'
    if (mimeType?.includes('excel')) return 'fas fa-file-excel'
    if (mimeType?.includes('image')) return 'fas fa-file-image'
    return 'fas fa-file'
}

const createTask = () => {
    router.post(`/admin/projects/${props.project.id}/tasks`, taskForm, {
        preserveScroll: true,
        onSuccess: () => {
            showAddTask.value = false
            Object.keys(taskForm).forEach(key => {
                if (key === 'priority') taskForm[key] = 'medium'
                else taskForm[key] = key === 'assigned_to' ? null : ''
            })
        }
    })
}

const uploadFile = (event) => {
    const file = event.target.files[0]
    if (!file) return

    const formData = new FormData()
    formData.append('file', file)
    formData.append('fileable_type', 'App\\Models\\Project')
    formData.append('fileable_id', props.project.id)

    router.post('/admin/projects/files', formData, {
        preserveScroll: true
    })
}

defineOptions({
    layout: null
})
</script>

<style scoped>
@import url('../../../../css/dashboard-app.css');

/* Main Layout Fixes */
.dashboard-container {
    height: 100vh;
    overflow: hidden;
    display: flex; /* Added flex */
}

.project-tree-sidebar {
    width: 260px;
    background: #f8f9fa;
    border-right: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    overflow-y: auto;
}

.tree-header {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.tree-header h3 {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.main-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    height: 100vh;
    overflow: hidden;
    min-width: 0;
    margin-left: 0; /* Override global CSS margin for fixed sidebar */
}

/* Wrike-like Split View Styles */
.tasks-tab-content {
    flex: 1;
    overflow: hidden;
    position: relative;
    display: flex;
    flex-direction: column;
}

.tasks-split-view {
    display: flex;
    flex: 1;
    overflow: hidden;
    border-top: 1px solid #e5e7eb;
}

.tasks-tree-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    transition: flex-basis 0.3s ease;
    min-width: 0; /* allows flex shrink */
}

/* Header */
.tasks-header-row {
    display: grid;
    grid-template-columns: minmax(300px, 2fr) 120px 100px 150px 140px;
    background: #f3f4f6;
    border-bottom: 2px solid #e5e7eb;
    position: sticky;
    top: 0;
    z-index: 10;
    font-weight: 600;
    font-size: 0.85rem;
    color: #4b5563;
}

.header-cell {
    padding: 0.75rem 0.5rem;
    border-right: 1px solid #e5e7eb;
}

.tasks-list-body {
    flex: 1;
    background: white;
}

.empty-state {
    padding: 2rem;
    text-align: center;
    color: #9ca3af;
}

/* Right Panel */
.task-detail-container {
    width: 450px;
    background: white;
    box-shadow: -2px 0 5px rgba(0,0,0,0.05);
    z-index: 20;
    overflow-y: hidden; /* Inner panel handles scroll */
    transition: width 0.3s ease;
}

/* Common Styles from original file (shortened for brevity where possible, but kept necessary ones) */
.project-info-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.info-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.tabs-section {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: white;
    border-radius: 8px 8px 0 0;
    box-shadow: 0 -2px 5px rgba(0,0,0,0.05);
    overflow: hidden;
}

.tabs {
    display: flex;
    border-bottom: 2px solid #e5e7eb;
    padding: 0 1rem;
}

.tab {
    padding: 1rem 1.5rem;
    background: none;
    border: none;
    cursor: pointer;
    font-weight: 500;
    color: var(--medium-grey);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
}

.tab.active {
    color: var(--primary-blue);
    border-bottom-color: var(--primary-blue);
}

.tab-content {
    padding: 2rem;
    overflow-y: auto;
}

/* Activity & Files Styles (Recycled) */
.activity-timeline, .files-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.activity-item, .file-item {
    display: flex;
    gap: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 8px;
}

.activity-icon, .file-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Modal styles ... */
.modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex; align-items: center; justify-content: center;
}
.modal-content {
    background: white; width: 90%; max-height: 90vh; overflow-y: auto; padding: 0; border-radius: 8px;
}
.modal-header, .modal-footer {
    padding: 1rem; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;
}
.modal-body { padding: 1rem; }
.form-group { margin-bottom: 1rem; }
.form-control { width: 100%; padding: 0.5rem; border: 1px solid #ddd; }
</style>
