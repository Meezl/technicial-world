<template>
    <div class="space-y-4">
        <div v-if="feedback.length > 0" class="space-y-4 max-h-96 overflow-y-auto">
            <div v-for="item in feedback" :key="item.id" class="border rounded-lg p-4 hover:bg-gray-50 transition-colors">
                <div class="flex items-start justify-between mb-2">
                    <div class="flex items-center gap-2">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-semibold">
                            {{ getInitials(item.client_name) }}
                        </div>
                        <div>
                            <p class="font-semibold text-gray-900">{{ item.client_name }}</p>
                            <p class="text-xs text-gray-500">{{ formatDate(item.created_at) }}</p>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <StarRating :rating="item.rating" />
                    </div>
                </div>
                <p v-if="item.comment" class="text-gray-700 text-sm mt-2">{{ item.comment }}</p>
                <p v-else class="text-gray-400 text-sm italic mt-2">No comment provided</p>
            </div>
        </div>
        <div v-else class="text-center py-8 text-gray-500">
            <i class="fas fa-comments text-4xl mb-2"></i>
            <p>No feedback yet</p>
        </div>
    </div>
</template>

<script setup>
import StarRating from './StarRating.vue';

const props = defineProps({
    feedback: Array
});

const getInitials = (name) => {
    if (!name) return '?';
    return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
};

const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
};
</script>
