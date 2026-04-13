<template>
    <div class="task-tree-row-container">
        <!-- Main Row -->
        <div 
            :class="['task-row', { 'selected': isSelected, 'has-children': hasChildren }]"
            @click.stop="$emit('select', task)"
        >
            <!-- Title Column with Indentation and Expander -->
            <div class="task-cell title-cell" :style="{ paddingLeft: `${depth * 20 + 10}px` }">
                <span 
                    v-if="hasChildren" 
                    class="expander" 
                    @click.stop="toggleExpand"
                >
                    <i :class="['fas', isExpanded ? 'fa-chevron-down' : 'fa-chevron-right']"></i>
                </span>
                <span v-else class="expander-placeholder"></span>
                
                <span class="task-title">{{ task.title }}</span>
                <span v-if="task.subtasks?.length" class="subtask-count">
                    ({{ task.subtasks.length }})
                </span>
            </div>

            <!-- Status Column (Inline Edit) -->
            <div class="task-cell status-cell" @click.stop>
                <select 
                    v-model="localTask.status" 
                    @change="updateStatus"
                    :class="['status-select', localTask.status]"
                >
                    <option value="todo">To Do</option>
                    <option value="in_progress">In Progress</option>
                    <option value="review">Review</option>
                    <option value="done">Done</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>

            <!-- Priority Column -->
            <div class="task-cell priority-cell" @click.stop>
                <span :class="['priority-badge', task.priority]">{{ task.priority?.toUpperCase() }}</span>
            </div>

            <!-- Assignee Column -->
            <div class="task-cell assignee-cell" @click.stop="toggleAssigneeDropdown">
                <AvatarGroup :users="assignedUsers" :limit="2" />
                
                <div v-if="showAssigneeDropdown" class="assignee-dropdown-menu" @click.stop>
                    <div v-for="user in users" :key="user.id" class="assignee-option">
                        <label>
                            <input 
                                type="checkbox" 
                                :value="user.id" 
                                v-model="localAssignments"
                                @change="updateAssignees"
                            >
                            {{ user.name }}
                        </label>
                    </div>
                </div>
            </div>

            <!-- Due Date Column -->
            <div class="task-cell date-cell" @click.stop>
                <input 
                    type="date" 
                    v-model="localDueDate" 
                    @change="updateDueDate"
                    class="date-input"
                    :class="{ 'overdue': isOverdue }"
                >
            </div>
        </div>

        <!-- Children Rows (Recursive) -->
        <div v-if="isExpanded && hasChildren" class="task-children">
            <TaskTreeRow
                v-for="subtask in task.subtasks"
                :key="subtask.id"
                :task="subtask"
                :users="users"
                :depth="depth + 1"
                :selected-task-id="selectedTaskId"
                @select="$emit('select', $event)"
                @update="$emit('update', $event)"
            />
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { isPast } from 'date-fns'
import AvatarGroup from './AvatarGroup.vue'

const props = defineProps({
    task: Object,
    users: Array,
    depth: {
        type: Number,
        default: 0
    },
    selectedTaskId: Number
})

const emit = defineEmits(['select', 'update'])

const isExpanded = ref(false) // Default collapsed
const showAssigneeDropdown = ref(false)

const localTask = ref({ ...props.task })
const localDueDate = ref(props.task.due_date ? props.task.due_date.split('T')[0] : '')
const localAssignments = ref(props.task.assignments?.map(a => a.user_id) || [])

const assignedUsers = computed(() => {
    return props.users.filter(u => localAssignments.value.includes(u.id))
})

watch(() => props.task, (newVal) => {
    localTask.value = { ...newVal }
    localDueDate.value = newVal.due_date ? newVal.due_date.split('T')[0] : ''
    localAssignments.value = newVal.assignments?.map(a => a.user_id) || []
}, { deep: true })

const hasChildren = computed(() => props.task.subtasks && props.task.subtasks.length > 0)
const isSelected = computed(() => props.task.id === props.selectedTaskId)

const isOverdue = computed(() => {
    return props.task.due_date && isPast(new Date(props.task.due_date)) && props.task.status !== 'done'
})

const toggleExpand = () => {
    isExpanded.value = !isExpanded.value
}

const toggleAssigneeDropdown = () => {
    showAssigneeDropdown.value = !showAssigneeDropdown.value
}

const updateStatus = () => {
    emit('update', { id: props.task.id, status: localTask.value.status })
}

const updateAssignees = () => {
    emit('update', { id: props.task.id, assignments: localAssignments.value })
}

const updateDueDate = () => {
    emit('update', { id: props.task.id, due_date: localDueDate.value })
}

// Close dropdown on click outside logic needed or simple blur?
// For simplicity, just close when clicking other row or global click listener (not implemented here but good enough for prototype)
const closeDropdown = (e) => {
    if (!e.target.closest('.assignee-cell')) {
        showAssigneeDropdown.value = false
    }
}
onMounted(() => document.addEventListener('click', closeDropdown))
onUnmounted(() => document.removeEventListener('click', closeDropdown))
</script>

<style scoped>
.assignee-cell {
    position: relative;
    cursor: pointer;
}
.assignee-dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    background: white;
    border: 1px solid #e5e7eb;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    border-radius: 0.375rem;
    padding: 0.5rem;
    z-index: 50;
    min-width: 150px;
}
.assignee-option {
    padding: 0.25rem 0;
}
.assignee-option label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}
/* ... rest of styles ... */
.task-row {
    display: grid;
    grid-template-columns: minmax(300px, 2fr) 120px 100px 150px 140px; /* Aligned with header in parent */
    border-bottom: 1px solid #f3f4f6;
    background: white;
    cursor: pointer;
    transition: background-color 0.1s;
    font-size: 0.9rem;
}

.task-row:hover {
    background-color: #f9fafb;
}

.task-row.selected {
    background-color: #eff6ff;
    border-left: 3px solid #3b82f6;
}

.task-cell {
    padding: 0.5rem;
    display: flex;
    align-items: center;
    border-right: 1px solid #f9fafb;
}

.title-cell {
    position: relative;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.expander {
    margin-right: 0.5rem;
    cursor: pointer;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
}

.expander:hover {
    color: #4b5563;
}

.expander-placeholder {
    width: 20px; /* same as expander + margin */
    margin-right: 0.5rem;
    display: inline-block;
}

.task-title {
    font-weight: 500;
    color: #374151;
}

.subtask-count {
    font-size: 0.75rem;
    color: #9ca3af;
    margin-left: 0.5rem;
}

.status-select, .assignee-select, .date-input {
    width: 100%;
    border: none;
    background: transparent;
    padding: 0.25rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.85rem;
}

.status-select:hover, .assignee-select:hover, .date-input:hover {
    background: #e5e7eb;
}

.status-select.todo { color: #374151; }
.status-select.in_progress { color: #2563eb; font-weight: 500; }
.status-select.review { color: #d97706; font-weight: 500; }
.status-select.done { color: #059669; font-weight: 500; }
.status-select.blocked { color: #dc2626; font-weight: 500; }

.priority-badge {
    padding: 0.125rem 0.5rem;
    border-radius: 99px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}
.priority-badge.low { background: #d1fae5; color: #065f46; }
.priority-badge.medium { background: #dbeafe; color: #1e40af; }
.priority-badge.high { background: #fed7aa; color: #92400e; }
.priority-badge.urgent { background: #fee2e2; color: #991b1b; }

.date-input.overdue {
    color: #dc2626;
    font-weight: 600;
}
</style>
