<template>
    <div class="bg-white rounded-lg shadow-md p-6 border-l-4" :class="borderColor">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <p class="text-sm font-medium text-gray-600 uppercase tracking-wide">{{ title }}</p>
                <p class="mt-2 text-3xl font-bold" :class="textColor">{{ value }}</p>
                <p v-if="subtitle" class="mt-1 text-sm text-gray-500">{{ subtitle }}</p>
            </div>
            <div class="flex-shrink-0">
                <div class="w-12 h-12 rounded-full flex items-center justify-center" :class="bgColor">
                    <i :class="[icon, iconColor, 'text-xl']"></i>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: String,
    value: [String, Number],
    subtitle: String,
    icon: String,
    color: {
        type: String,
        default: 'blue'
    }
});

const colorClasses = {
    blue: {
        border: 'border-blue-500',
        text: 'text-blue-600',
        bg: 'bg-blue-100',
        icon: 'text-blue-600'
    },
    green: {
        border: 'border-green-500',
        text: 'text-green-600',
        bg: 'bg-green-100',
        icon: 'text-green-600'
    },
    orange: {
        border: 'border-orange-500',
        text: 'text-orange-600',
        bg: 'bg-orange-100',
        icon: 'text-orange-600'
    },
    yellow: {
        border: 'border-yellow-500',
        text: 'text-yellow-600',
        bg: 'bg-yellow-100',
        icon: 'text-yellow-600'
    },
    red: {
        border: 'border-red-500',
        text: 'text-red-600',
        bg: 'bg-red-100',
        icon: 'text-red-600'
    },
    purple: {
        border: 'border-purple-500',
        text: 'text-purple-600',
        bg: 'bg-purple-100',
        icon: 'text-purple-600'
    }
};

const borderColor = computed(() => colorClasses[props.color]?.border || colorClasses.blue.border);
const textColor = computed(() => colorClasses[props.color]?.text || colorClasses.blue.text);
const bgColor = computed(() => colorClasses[props.color]?.bg || colorClasses.blue.bg);
const iconColor = computed(() => colorClasses[props.color]?.icon || colorClasses.blue.icon);
</script>
