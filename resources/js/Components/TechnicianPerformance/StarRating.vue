<template>
    <div class="flex items-center gap-1">
        <i
            v-for="star in 5"
            :key="star"
            class="fas fa-star text-sm"
            :class="star <= numericRating ? 'text-yellow-400' : 'text-gray-300'"
        ></i>
        <span class="ml-1 text-sm font-semibold text-gray-700">{{ numericRating.toFixed(1) }}</span>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    rating: {
        type: [Number, String],
        default: 0
    }
});

const numericRating = computed(() => {
    const rating = parseFloat(props.rating);
    return isNaN(rating) ? 0 : rating;
});
</script>
