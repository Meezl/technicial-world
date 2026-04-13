<template>
    <div class="avatar-group">
        <div 
            v-for="user in users" 
            :key="user.id" 
            class="avatar" 
            :title="user.name"
        >
            <span class="initials">{{ getInitials(user.name) }}</span>
        </div>
        <div v-if="limit && remaining > 0" class="avatar more">
            +{{ remaining }}
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    users: {
        type: Array,
        default: () => []
    },
    limit: {
        type: Number,
        default: 3
    }
})

const displayUsers = computed(() => {
    return props.limit ? props.users.slice(0, props.limit) : props.users
})

const remaining = computed(() => {
    return props.users.length - (props.limit || props.users.length)
})

const getInitials = (name) => {
    return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .substring(0, 2)
}
</script>

<style scoped>
.avatar-group {
    display: flex;
    align-items: center;
}
.avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #e5e7eb;
    color: #4b5563;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.6rem;
    font-weight: 600;
    border: 2px solid white;
    margin-left: -8px;
}
.avatar:first-child {
    margin-left: 0;
}
.avatar.more {
    background: #f3f4f6;
    color: #6b7280;
}
</style>
