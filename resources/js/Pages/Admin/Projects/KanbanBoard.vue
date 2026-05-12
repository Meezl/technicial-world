<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="projects" />

        <main class="main-content">
            <header class="main-header">
                <div>
                    <h1>{{ project.name }} - Kanban Board</h1>
                    <p class="sub-text">Drag and drop tasks to update their status</p>
                </div>
                <div class="header-actions">
                    <button @click="showAddTask = true" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Task
                    </button>
                </div>
            </header>

            <div class="kanban-board">
                <div
                    v-for="column in kanbanBoard.columns"
                    :key="column"
                    class="kanban-column"
                    @drop="onDrop($event, column)"
                    @dragover.prevent
                    @dragenter.prevent
                >
                    <div class="column-header">
                        <h3>{{ formatColumnName(column) }}</h3>
                        <span class="task-count">{{ tasksByColumn[column]?.length || 0 }}</span>
                    </div>

                    <div class="column-body">
                        <div
                            v-for="(task, index) in tasksByColumn[column]"
                            :key="task.id"
                            class="task-card"
                            draggable="true"
                            @dragstart="onDragStart($event, task)"
                            @click="viewTask(task)"
                        >
                            <div class="task-header">
                                <span :class="['priority-badge', task.priority]">
                                    {{ task.priority.toUpperCase() }}
                                </span>
                                <span v-if="task.subtasks && task.subtasks.length" class="subtask-badge">
                                    <i class="fas fa-sitemap"></i> {{ task.subtasks.length }}
                                </span>
                            </div>

                            <h4 class="task-title">{{ task.title }}</h4>

                            <p v-if="task.description" class="task-description">
                                {{ truncateText(task.description, 80) }}
                            </p>

                            <div class="task-footer">
                                <div v-if="task.assigned_user" class="task-assignee">
                                    <i class="fas fa-user"></i>
                                    <span>{{ task.assigned_user.name }}</span>
                                </div>
                                <div v-if="task.due_date" class="task-due-date" :class="{'overdue': isOverdue(task)}">
                                    <i class="fas fa-calendar"></i>
                                    <span>{{ formatDueDate(task.due_date) }}</span>
                                </div>
                            </div>

                            <div v-if="task.progress_percentage > 0" class="task-progress">
                                <div class="progress-bar">
                                    <div class="progress" :style="`width: ${task.progress_percentage}%;`"></div>
                                </div>
                            </div>
                        </div>

                        <button @click="quickAddTask(column)" class="add-task-btn">
                            <i class="fas fa-plus"></i> Add Task
                        </button>
                    </div>
                </div>
            </div>
        </main>

        <!-- Add Task Modal -->
        <div v-if="showAddTask" class="modal-overlay" @click="showAddTask = false">
            <div class="modal-content" @click.stop style="max-width: 600px;">
                <div class="modal-header">
                    <h3>Create New Task</h3>
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
                                <label>Status</label>
                                <select v-model="taskForm.status" class="form-control">
                                    <option value="todo">To Do</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="review">Review</option>
                                    <option value="done">Done</option>
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
import { ref, reactive } from 'vue'
import { format, isPast } from 'date-fns'

const props = defineProps({
    project: Object,
    kanbanBoard: Object,
    tasksByColumn: Object
})

const showAddTask = ref(false)
const draggedTask = ref(null)
const taskForm = reactive({
    title: '',
    description: '',
    priority: 'medium',
    status: 'todo',
    due_date: ''
})

const formatColumnName = (column) => {
    return column.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const formatDueDate = (date) => {
    return format(new Date(date), 'MMM dd')
}

const isOverdue = (task) => {
    return task.due_date && isPast(new Date(task.due_date)) && task.status !== 'done'
}

const truncateText = (text, length) => {
    return text.length > length ? text.substring(0, length) + '...' : text
}

const onDragStart = (event, task) => {
    draggedTask.value = task
    event.dataTransfer.effectAllowed = 'move'
    event.target.classList.add('dragging')
}

const onDrop = (event, targetColumn) => {
    event.target.classList.remove('drag-over')

    if (!draggedTask.value) return

    const task = draggedTask.value

    // Calculate new order based on drop position
    const columnTasks = props.tasksByColumn[targetColumn] || []
    const newOrder = columnTasks.length

    // Update task via API
    router.post(`/admin/tasks/${task.id}/move`, {
        kanban_column: targetColumn,
        kanban_order: newOrder
    }, {
        preserveScroll: true,
        onSuccess: () => {
            draggedTask.value = null
        }
    })
}

const quickAddTask = (column) => {
    taskForm.status = column
    showAddTask.value = true
}

const createTask = () => {
    router.post(`/admin/projects/${props.project.id}/tasks`, taskForm, {
        preserveScroll: true,
        onSuccess: () => {
            showAddTask.value = false
            Object.keys(taskForm).forEach(key => {
                if (key === 'priority') taskForm[key] = 'medium'
                else if (key === 'status') taskForm[key] = 'todo'
                else taskForm[key] = ''
            })
        }
    })
}

const viewTask = (task) => {
    // Navigate to project details with task focus
    router.visit(`/admin/projects/${props.project.id}?task=${task.id}`)
}

defineOptions({
    layout: null
})
</script>

<style scoped>
@import url('../../../../css/dashboard-app.css');

.kanban-board {
    display: flex;
    gap: 1.5rem;
    overflow-x: auto;
    padding: 1rem 0;
    min-height: calc(100vh - 200px);
}

.kanban-column {
    flex: 0 0 320px;
    background: #f8f9fa;
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    max-height: calc(100vh - 220px);
}

.column-header {
    padding: 1rem;
    border-bottom: 2px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.column-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--dark-grey);
}

.task-count {
    background: var(--primary-blue);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 600;
}

.column-body {
    flex: 1;
    padding: 1rem;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.task-card {
    background: white;
    border-radius: 6px;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    cursor: grab;
    transition: transform 0.2s, box-shadow 0.2s;
}

.task-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
}

.task-card.dragging {
    opacity: 0.5;
    cursor: grabbing;
}

.task-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
}

.priority-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: 600;
}

.priority-badge.low {
    background: #d1fae5;
    color: #065f46;
}

.priority-badge.medium {
    background: #dbeafe;
    color: #1e40af;
}

.priority-badge.high {
    background: #fed7aa;
    color: #92400e;
}

.priority-badge.urgent {
    background: #fecaca;
    color: #991b1b;
}

.subtask-badge {
    color: var(--medium-grey);
    font-size: 0.85rem;
}

.task-title {
    margin: 0.5rem 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--dark-grey);
}

.task-description {
    margin: 0.5rem 0;
    font-size: 0.85rem;
    color: var(--medium-grey);
    line-height: 1.4;
}

.task-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid #e5e7eb;
    font-size: 0.8rem;
}

.task-assignee, .task-due-date {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    color: var(--medium-grey);
}

.task-due-date.overdue {
    color: #dc2626;
    font-weight: 600;
}

.task-progress {
    margin-top: 0.75rem;
}

.task-progress .progress-bar {
    height: 4px;
    background: #e5e7eb;
    border-radius: 2px;
}

.task-progress .progress {
    height: 100%;
    background: var(--primary-blue);
    border-radius: 2px;
    transition: width 0.3s;
}

.add-task-btn {
    width: 100%;
    padding: 0.75rem;
    background: transparent;
    border: 2px dashed #cbd5e0;
    border-radius: 6px;
    color: var(--medium-grey);
    cursor: pointer;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.add-task-btn:hover {
    background: white;
    border-color: var(--primary-blue);
    color: var(--primary-blue);
}

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

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: var(--dark-grey);
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
</style>
