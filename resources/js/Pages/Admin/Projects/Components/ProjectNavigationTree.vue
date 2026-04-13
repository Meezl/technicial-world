<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    projects: {
        type: Array,
        required: true
    },
    currentProjectId: {
        type: [String, Number],
        default: null
    },
    level: {
        type: Number,
        default: 0
    }
});

// Build tree structure from flat list
const tree = computed(() => {
    if (props.level > 0) return props.projects; // Already nested if recursive

    const map = {};
    const roots = [];
    
    // Initialize map
    props.projects.forEach(p => {
        map[p.id] = { ...p, children: [] };
    });

    // Link children to parents
    props.projects.forEach(p => {
        if (p.parent_id && map[p.parent_id]) {
            map[p.parent_id].children.push(map[p.id]);
        } else if (!p.parent_id) {
            roots.push(map[p.id]);
        }
    });

    return roots;
});

const isSelected = (id) => props.currentProjectId === id;
</script>

<template>
    <ul class="project-tree-list">
        <li v-for="project in (level === 0 ? tree : projects)" :key="project.id" class="project-tree-item">
            <Link 
                :href="`/admin/projects/${project.id}`" 
                class="project-link" 
                :class="{ 'active': isSelected(project.id) }"
                :style="{ paddingLeft: `${level * 1.5 + 1}rem` }"
            >
                <i class="fas fa-folder text-yellow-500 mr-2"></i>
                <span class="truncate">{{ project.name }}</span>
            </Link>
            
            <ProjectNavigationTree 
                v-if="project.children && project.children.length > 0" 
                :projects="project.children" 
                :currentProjectId="currentProjectId"
                :level="level + 1"
            />
        </li>
    </ul>
</template>

<style scoped>
.project-tree-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.project-tree-item {
    display: flex;
    flex-direction: column;
}

.project-link {
    display: flex;
    align-items: center;
    padding: 0.75rem 1rem;
    color: #4b5563;
    text-decoration: none;
    transition: all 0.2s;
    border-left: 3px solid transparent;
}

.project-link:hover {
    background-color: #f3f4f6;
    color: #1f2937;
}

.project-link.active {
    background-color: #eef2ff;
    color: #4f46e5;
    border-left-color: #4f46e5;
    font-weight: 500;
}

.text-yellow-500 {
    color: #f59e0b;
}

.mr-2 {
    margin-right: 0.5rem;
}

.truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
