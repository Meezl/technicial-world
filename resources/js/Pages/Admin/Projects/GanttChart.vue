<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="projects" />

        <main class="main-content">
            <header class="main-header">
                <div>
                    <h1>{{ project.name }} - Gantt Chart</h1>
                    <p class="sub-text">Timeline view with task dependencies</p>
                </div>
                <div class="header-actions">
                    <select v-model="viewMode" @change="changeViewMode" class="view-mode-select">
                        <option value="Day">Day</option>
                        <option value="Week">Week</option>
                        <option value="Month">Month</option>
                        <option value="Quarter">Quarter</option>
                    </select>
                    <Link :href="`/admin/projects/${project.id}/kanban`" class="btn btn-secondary">
                        <i class="fas fa-columns"></i> Kanban View
                    </Link>
                </div>
            </header>

            <section class="gantt-section">
                <div v-if="tasks.length === 0" class="no-tasks">
                    <i class="fas fa-chart-bar" style="font-size: 3rem; color: var(--medium-grey);"></i>
                    <p>No tasks to display in Gantt chart</p>
                    <Link :href="`/admin/projects/${project.id}`" class="btn btn-primary">
                        Add Tasks
                    </Link>
                </div>
                <div v-else ref="ganttContainer" class="gantt-container"></div>
            </section>

            <!-- Task Details Panel -->
            <div v-if="selectedTask" class="task-details-panel">
                <div class="panel-header">
                    <h3>{{ selectedTask.name }}</h3>
                    <button @click="selectedTask = null" class="close-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="panel-body">
                    <div class="detail-row">
                        <label>Start Date:</label>
                        <span>{{ selectedTask.start }}</span>
                    </div>
                    <div class="detail-row">
                        <label>End Date:</label>
                        <span>{{ selectedTask.end }}</span>
                    </div>
                    <div class="detail-row">
                        <label>Progress:</label>
                        <div class="progress-bar">
                            <div class="progress" :style="`width: ${selectedTask.progress}%;`"></div>
                        </div>
                        <span>{{ selectedTask.progress }}%</span>
                    </div>
                    <div class="detail-row">
                        <label>Dependencies:</label>
                        <span v-if="selectedTask.dependencies">
                            {{ selectedTask.dependencies.split(',').length }} task(s)
                        </span>
                        <span v-else>None</span>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import AdminSidebar from '../../../Components/AdminSidebar.vue'
import { Link } from '@inertiajs/vue3'
import { ref, onMounted, nextTick } from 'vue'
import Gantt from 'frappe-gantt'

const props = defineProps({
    project: Object,
    tasks: Array
})

const ganttContainer = ref(null)
const ganttInstance = ref(null)
const viewMode = ref('Week')
const selectedTask = ref(null)

onMounted(() => {
    if (props.tasks.length > 0) {
        nextTick(() => {
            initializeGantt()
        })
    }
})

const initializeGantt = () => {
    if (!ganttContainer.value) return

    try {
        ganttInstance.value = new Gantt(ganttContainer.value, props.tasks, {
            view_mode: viewMode.value,
            header_height: 50,
            column_width: 30,
            step: 24,
            bar_height: 30,
            bar_corner_radius: 3,
            arrow_curve: 5,
            padding: 18,
            view_modes: ['Quarter Day', 'Half Day', 'Day', 'Week', 'Month', 'Quarter'],
            date_format: 'YYYY-MM-DD',
            language: 'en',
            popup_trigger: 'click',
            on_click: (task) => {
                selectedTask.value = task
            },
            on_date_change: (task, start, end) => {
                console.log('Task dates changed:', task, start, end)
                // You can make an API call here to update task dates
            },
            on_progress_change: (task, progress) => {
                console.log('Task progress changed:', task, progress)
                // You can make an API call here to update task progress
            },
            on_view_change: (mode) => {
                console.log('View mode changed:', mode)
            },
            custom_popup_html: function(task) {
                return `
                    <div class="gantt-popup">
                        <div class="popup-title">${task.name}</div>
                        <div class="popup-content">
                            <p><strong>Start:</strong> ${task.start}</p>
                            <p><strong>End:</strong> ${task.end}</p>
                            <p><strong>Progress:</strong> ${task.progress}%</p>
                        </div>
                    </div>
                `
            }
        })
    } catch (error) {
        console.error('Error initializing Gantt chart:', error)
    }
}

const changeViewMode = () => {
    if (ganttInstance.value) {
        ganttInstance.value.change_view_mode(viewMode.value)
    }
}

defineOptions({
    layout: null
})
</script>

<style scoped>

.gantt-section {
    background: white;
    border-radius: 8px;
    padding: 2rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    min-height: 500px;
}

.gantt-container {
    width: 100%;
    overflow-x: auto;
}

.view-mode-select {
    padding: 0.5rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    background: white;
    cursor: pointer;
    font-size: 0.9rem;
}

.view-mode-select:focus {
    outline: none;
    border-color: var(--primary-blue);
}

.no-tasks {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    padding: 4rem 2rem;
    color: var(--medium-grey);
}

.task-details-panel {
    position: fixed;
    right: 0;
    top: 0;
    bottom: 0;
    width: 350px;
    background: white;
    box-shadow: -2px 0 8px rgba(0, 0, 0, 0.1);
    z-index: 100;
    display: flex;
    flex-direction: column;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.panel-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.close-btn {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--medium-grey);
    font-size: 1.2rem;
}

.close-btn:hover {
    color: var(--dark-grey);
}

.panel-body {
    padding: 1.5rem;
    overflow-y: auto;
}

.detail-row {
    margin-bottom: 1.5rem;
}

.detail-row label {
    display: block;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--dark-grey);
}

.detail-row span {
    color: var(--medium-grey);
}

.progress-bar {
    height: 8px;
    background: #e5e7eb;
    border-radius: 4px;
    margin: 0.5rem 0;
}

.progress {
    height: 100%;
    background: var(--primary-blue);
    border-radius: 4px;
    transition: width 0.3s;
}

/* Custom Gantt Styles */
:deep(.gantt .bar) {
    cursor: pointer;
}

:deep(.gantt .bar-wrapper:hover .bar) {
    fill: #2563eb;
}

:deep(.gantt .bar-progress) {
    fill: #3b82f6;
}

:deep(.gantt .bar-label) {
    fill: white;
    font-weight: 500;
}

:deep(.gantt .grid-header) {
    fill: #f8f9fa;
}

:deep(.gantt .grid-row) {
    fill: white;
}

:deep(.gantt .grid-row:nth-child(even)) {
    fill: #f8f9fa;
}

:deep(.gantt .today-highlight) {
    fill: #fff3cd;
    opacity: 0.3;
}

/* Priority colors */
:deep(.gantt .bar.priority-low) {
    fill: #10b981;
}

:deep(.gantt .bar.priority-medium) {
    fill: #3b82f6;
}

:deep(.gantt .bar.priority-high) {
    fill: #f59e0b;
}

:deep(.gantt .bar.priority-urgent) {
    fill: #ef4444;
}

/* Custom Popup Styles */
:deep(.gantt-popup) {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    padding: 1rem;
    min-width: 200px;
}

:deep(.popup-title) {
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: 0.75rem;
    color: var(--dark-grey);
}

:deep(.popup-content) {
    font-size: 0.9rem;
    color: var(--medium-grey);
}

:deep(.popup-content p) {
    margin: 0.5rem 0;
}

:deep(.popup-content strong) {
    color: var(--dark-grey);
}
</style>
