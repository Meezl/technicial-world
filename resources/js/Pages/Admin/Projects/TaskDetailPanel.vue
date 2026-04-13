<template>
    <div class="task-detail-panel">
        <div class="panel-header">
            <div class="header-top">
                <span :class="['status-badge', task.status]">{{ formatStatus(task.status) }}</span>
                <div class="header-actions">
                    <button @click="$emit('close')" class="close-btn">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <input 
                v-model="form.title" 
                @blur="updateTask('title')"
                class="task-title-input"
                placeholder="Task Title"
            >
        </div>

        <div class="panel-body">
            <!-- Properties Grid -->
            <div class="properties-grid">
                <div class="property-item">
                    <label>Assignees</label>
                    <div class="assignee-selector" @click="toggleAssigneeDropdown">
                        <AvatarGroup :users="assignedUsers" :limit="3" />
                         <button class="add-assignee-btn" v-if="assignedUsers.length === 0">
                            <i class="fas fa-plus"></i> Add
                        </button>
                        
                        <div v-if="showAssigneeDropdown" class="assignee-dropdown-panel" @click.stop>
                             <div class="dropdown-search">
                                <input v-model="assigneeSearch" placeholder="Search..." class="search-input">
                            </div>
                            <div class="assignee-options-list">
                                <div v-for="user in filteredUsers" :key="user.id" class="assignee-option">
                                    <label>
                                        <input 
                                            type="checkbox" 
                                            :value="user.id" 
                                            v-model="form.assignments"
                                            @change="updateTask('assignments')"
                                        >
                                        {{ user.name }}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="property-item">
                    <label>Due Date</label>
                    <input 
                        type="date" 
                        v-model="form.due_date" 
                        @change="updateTask('due_date')"
                        class="prop-input"
                    >
                </div>
                <div class="property-item">
                    <label>Priority</label>
                    <select v-model="form.priority" @change="updateTask('priority')" class="prop-input">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
                <div class="property-item">
                    <label>Status</label>
                    <select v-model="form.status" @change="updateTask('status')" class="prop-input">
                        <option value="todo">To Do</option>
                        <option value="in_progress">In Progress</option>
                        <option value="review">Review</option>
                        <option value="done">Done</option>
                        <option value="blocked">Blocked</option>
                    </select>
                </div>
            </div>

            <!-- Description -->
            <div class="description-section">
                <label>Description</label>
                <textarea 
                    v-model="form.description" 
                    @blur="updateTask('description')"
                    class="description-input"
                    placeholder="Add a description..."
                    rows="4"
                ></textarea>
            </div>

            <!-- Subtasks -->
            <div class="subtasks-section">
                <div class="section-header">
                    <label>Subtasks</label>
                    <button @click="showAddSubtask = true" class="btn-text">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div v-if="task.subtasks?.length" class="subtasks-list">
                    <div v-for="subtask in task.subtasks" :key="subtask.id" class="subtask-item">
                        <input 
                            type="checkbox" 
                            :checked="subtask.status === 'done'"
                            @change="toggleSubtaskStatus(subtask)"
                        >
                        <span :class="{ 'completed': subtask.status === 'done' }">{{ subtask.title }}</span>
                    </div>
                </div>
                <!-- Inline Add Subtask -->
                <div v-if="showAddSubtask" class="add-subtask-form">
                    <input 
                        v-model="newSubtaskTitle"
                        @keyup.enter="createSubtask" 
                        placeholder="Type and hit enter..."
                        class="subtask-input"
                        ref="subtaskInput"
                    >
                </div>
            </div>
            
            <!-- Dependencies -->
            <div class="dependencies-section">
                <div class="section-header">
                    <label>Dependencies</label>
                    <button @click="showAddDependency = true" class="btn-text">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                
                <div v-if="task.dependencies?.length" class="dependency-list">
                    <div v-for="dep in task.dependencies" :key="dep.id" class="dependency-item">
                        <span class="dep-type">{{ dep.dependency_type === 'finish_to_start' ? 'Blocking' : 'Linked' }}</span>
                        <span class="dep-title">{{ dep.depends_on_task?.title }}</span>
                        <button @click="removeDependency(dep.id)" class="remove-btn"><i class="fas fa-times"></i></button>
                    </div>
                </div>

                <div v-if="showAddDependency" class="add-dependency-form">
                    <input 
                        v-model="dependencySearch" 
                        @input="searchTasks" 
                        placeholder="Search task to link..."
                        class="search-input"
                    >
                    <div v-if="searchResults.length" class="search-results">
                        <div 
                            v-for="result in searchResults" 
                            :key="result.id" 
                            class="search-result-item"
                            @click="addDependency(result)"
                        >
                            {{ result.title }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attachments -->
            <div class="attachments-section">
                <div class="section-header">
                    <label>Attachments</label>
                    <label class="btn-text upload-btn">
                        <i class="fas fa-paperclip"></i>
                        <input type="file" @change="uploadFile" hidden>
                    </label>
                </div>
                <div v-if="task.files?.length" class="file-list">
                    <div v-for="file in task.files" :key="file.id" class="file-item">
                        <i class="fas fa-file"></i>
                        <a :href="`/admin/projects/files/${file.id}/download`" target="_blank">{{ file.file_name }}</a>
                        <span class="file-size">{{ file.file_size_formatted }}</span>
                        <button @click="deleteFile(file.id)" class="remove-btn"><i class="fas fa-times"></i></button>
                    </div>
                </div>
            </div>
            
            <!-- Comments -->
            <div class="comments-section">
                <label>Comments & Activity</label>
                <TaskComments :taskId="task.id" :comments="task.comments" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, watch, nextTick, computed, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AvatarGroup from './Components/AvatarGroup.vue'
import TaskComments from './Components/TaskComments.vue'

const props = defineProps({
    task: Object,
    users: Array
})

const emit = defineEmits(['close', 'update'])

const form = reactive({
    title: '',
    description: '',
    assignments: [],
    due_date: '',
    priority: 'medium',
    status: 'todo'
})

const showAddSubtask = ref(false)
const newSubtaskTitle = ref('')
const subtaskInput = ref(null)
const showAssigneeDropdown = ref(false)
const assigneeSearch = ref('')

// Initialize form when task changes
watch(() => props.task, (newTask) => {
    if (newTask) {
        form.title = newTask.title
        form.description = newTask.description
        form.assignments = newTask.assignments?.map(a => a.user_id) || []
        // Format date to YYYY-MM-DD for input type="date"
        form.due_date = newTask.due_date ? newTask.due_date.split('T')[0] : ''
        form.priority = newTask.priority
        form.status = newTask.status
    }
}, { immediate: true })

const assignedUsers = computed(() => {
    return props.users.filter(u => form.assignments.includes(u.id))
})

const filteredUsers = computed(() => {
    if (!assigneeSearch.value) return props.users
    return props.users.filter(u => u.name.toLowerCase().includes(assigneeSearch.value.toLowerCase()))
})

watch(showAddSubtask, (val) => {
    if (val) {
        nextTick(() => subtaskInput.value?.focus())
    }
})

const toggleAssigneeDropdown = () => {
    showAssigneeDropdown.value = !showAssigneeDropdown.value
}

const formatStatus = (status) => {
    return status?.replace('_', ' ').toUpperCase() || ''
}

const updateTask = (field) => {
    // Basic optimistic UI or wait for server? 
    // For Wrike-like feel, ideally optimistic, but let's stick to standard Inertia for safety first
    router.put(`/admin/tasks/${props.task.id}`, {
        [field]: form[field]
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update')
    })
}

const createSubtask = () => {
    if (!newSubtaskTitle.value.trim()) return

    router.post(`/admin/projects/${props.task.project_id}/tasks`, {
        title: newSubtaskTitle.value,
        parent_task_id: props.task.id,
        status: 'todo'
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            newSubtaskTitle.value = ''
            // Keep adding mode open
            emit('update')
        }
    })
}

const toggleSubtaskStatus = (subtask) => {
    const newStatus = subtask.status === 'done' ? 'todo' : 'done'
    router.put(`/admin/tasks/${subtask.id}`, {
        status: newStatus
    }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('update')
    })
}

const showAddDependency = ref(false)
const dependencySearch = ref('')
const searchResults = ref([])

const searchTasks = async () => {
    if (dependencySearch.value.length < 2) return
    const res = await axios.get('/admin/tasks/search', { params: { query: dependencySearch.value, exclude_task_id: props.task.id, project_id: props.task.project_id } })
    searchResults.value = res.data
}

const addDependency = (targetTask) => {
    router.post(`/admin/tasks/${props.task.id}/dependencies`, {
        depends_on_task_id: targetTask.id,
        dependency_type: 'finish_to_start'
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showAddDependency.value = false
            dependencySearch.value = ''
            searchResults.value = []
            emit('update')
        }
    })
}

const removeDependency = (depId) => {
    router.delete(`/admin/tasks/dependencies/${depId}`, {
        preserveScroll: true,
        onSuccess: () => emit('update')
    })
}

const uploadFile = (e) => {
    const file = e.target.files[0]
    if (!file) return

    const formData = new FormData()
    formData.append('file', file)
    formData.append('fileable_type', 'task')
    formData.append('fileable_id', props.task.id)

    router.post('/admin/projects/upload-file', formData, {
        preserveScroll: true,
        onSuccess: () => emit('update')
    })
}

const deleteFile = (fileId) => {
    if (!confirm('Delete file?')) return
    router.delete(`/admin/projects/files/${fileId}`, {
        preserveScroll: true,
        onSuccess: () => emit('update')
    })
}

const closeDropdown = (e) => {
    if (!e.target.closest('.assignee-selector')) {
        showAssigneeDropdown.value = false
    }
}
onMounted(() => document.addEventListener('click', closeDropdown))
onUnmounted(() => document.removeEventListener('click', closeDropdown))
</script>

<style scoped>
.task-detail-panel {
    background: white;
    height: 100%;
    display: flex;
    flex-direction: column;
    border-left: 1px solid #e5e7eb;
}

.panel-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.close-btn {
    background: none;
    border: none;
    font-size: 1.25rem;
    color: #6b7280;
    cursor: pointer;
    padding: 0.25rem;
}

.close-btn:hover {
    color: #1f2937;
}

.task-title-input {
    width: 100%;
    font-size: 1.25rem;
    font-weight: 600;
    border: 1px solid transparent;
    padding: 0.5rem;
    border-radius: 4px;
    margin: -0.5rem;
}

.task-title-input:hover, .task-title-input:focus {
    border-color: #e5e7eb;
}

.panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
}

.properties-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    margin-bottom: 2rem;
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
}

.property-item label {
    display: block;
    font-size: 0.75rem;
    color: #6b7280;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.prop-input {
    width: 100%;
    border: 1px solid transparent;
    padding: 0.25rem;
    border-radius: 4px;
    font-size: 0.9rem;
    background: transparent;
}

.prop-input:hover, .prop-input:focus {
    background: white;
    border-color: #e5e7eb;
}

.description-section {
    margin-bottom: 2rem;
}

.description-section label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
    color: #374151;
}

.description-input {
    width: 100%;
    border: 1px solid transparent;
    padding: 0.75rem;
    border-radius: 4px;
    resize: vertical;
    min-height: 100px;
}

.description-input:hover, .description-input:focus {
    border-color: #e5e7eb;
    background: #f9fafb;
}

.subtasks-section {
    margin-bottom: 2rem;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.section-header label {
    font-weight: 500;
    color: #374151;
}

.btn-text {
    background: none;
    border: none;
    color: #2563eb;
    cursor: pointer;
    font-size: 0.9rem;
}

.subtasks-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.subtask-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem;
    border-radius: 4px;
}

.subtask-item:hover {
    background: #f9fafb;
}

.subtask-item.completed {
    text-decoration: line-through;
    color: #9ca3af;
}

.add-subtask-form {
    margin-top: 0.5rem;
}

.subtask-input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
}

.comments-section-placeholder {
    margin-top: 2rem;
    border-top: 1px solid #e5e7eb;
    padding-top: 1.5rem;
}

.placeholder-text {
    color: #9ca3af;
    font-style: italic;
    font-size: 0.9rem;
    margin-top: 0.5rem;
}

.status-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-badge.todo { background: #e5e7eb; color: #374151; }
.status-badge.in_progress { background: #dbeafe; color: #1e40af; }
.status-badge.review { background: #fef3c7; color: #92400e; }
.status-badge.done { background: #d1fae5; color: #065f46; }
.status-badge.blocked { background: #fee2e2; color: #991b1b; }

.assignee-selector {
    position: relative;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.add-assignee-btn {
    background: none;
    border: 1px dashed #e5e7eb;
    border-radius: 50%;
    width: 24px; height: 24px;
    display: flex; align-items: center; justify-content: center;
    color: #6b7280; font-size: 0.7rem;
}
.assignee-dropdown-panel {
    position: absolute;
    top: 100%; left: 0;
    background: white;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    border-radius: 6px;
    padding: 0.5rem;
    z-index: 50;
    width: 200px;
    max-height: 300px;
    display: flex;
    flex-direction: column;
}
.dropdown-search {
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #f3f4f6;
    margin-bottom: 0.5rem;
}
.search-input {
    width: 100%; padding: 0.25rem; font-size: 0.85rem; border: 1px solid #e5e7eb; border-radius: 4px;
}
.assignee-options-list {
    overflow-y: auto;
}
.assignee-option {
    padding: 0.25rem 0;
}
.assignee-option label {
    display: flex; align-items: center; gap: 0.5rem; cursor: pointer; color: #374151; font-size: 0.9rem;
}

.dependencies-section, .attachments-section {
    margin-bottom: 2rem;
}
.dependency-item, .file-item {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.5rem; background: #f9fafb; border-radius: 4px; margin-bottom: 0.5rem; font-size: 0.9rem;
}
.dep-type {
    font-size: 0.75rem; color: #6b7280; margin-right: 0.5rem; text-transform: uppercase;
}
.search-results {
    border: 1px solid #e5e7eb; border-radius: 4px; max-height: 150px; overflow-y: auto; margin-top: 0.5rem;
}
.search-result-item {
    padding: 0.5rem; cursor: pointer;
}
.search-result-item:hover { background: #f3f4f6; }
.remove-btn {
    background: none; border: none; color: #ef4444; cursor: pointer; margin-left: auto;
}
.file-item a { color: #2563eb; text-decoration: none; flex: 1; margin-left: 0.5rem; }
.file-size { color: #9ca3af; font-size: 0.8rem; margin-right: 0.5rem; }
.upload-btn { cursor: pointer; }
</style>
