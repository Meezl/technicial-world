<template>
    <div class="bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden">
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 px-6 py-4">
            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                <i class="fas fa-lightbulb"></i>
                Best Match Technicians
            </h3>
            <p class="text-blue-100 text-sm mt-1">Based on performance analysis</p>
        </div>

        <div class="p-4">
            <!-- Filters -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Specialization</label>
                <select
                    v-model="selectedSpecialization"
                    @change="loadRecommendations"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                >
                    <option value="">All Specializations</option>
                    <option v-for="spec in specializations" :key="spec" :value="spec">{{ spec }}</option>
                </select>
            </div>

            <!-- Loading State -->
            <div v-if="loading" class="text-center py-8">
                <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
                <p class="text-sm text-gray-500 mt-2">Loading recommendations...</p>
            </div>

            <!-- Recommendations List -->
            <div v-else-if="recommendations.length > 0" class="space-y-3">
                <div
                    v-for="(tech, index) in recommendations"
                    :key="tech.id"
                    class="border border-gray-200 rounded-lg p-4 hover:border-blue-500 hover:shadow-md transition-all cursor-pointer"
                    :class="{ 'border-blue-500 shadow-md': selectedTechnician?.id === tech.id }"
                    @click="selectTechnician(tech)"
                >
                    <!-- Rank Badge -->
                    <div class="absolute -top-2 -left-2 w-8 h-8 rounded-full flex items-center justify-center font-bold text-white text-sm shadow-lg"
                         :class="getRankClass(index)">
                        {{ index + 1 }}
                    </div>

                    <div class="flex items-start gap-3 relative">
                        <!-- Avatar -->
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-sm flex-shrink-0">
                            {{ getInitials(tech.name) }}
                        </div>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h4 class="text-sm font-semibold text-gray-900 truncate">{{ tech.name }}</h4>
                                <span v-if="tech.is_top_rated" class="px-2 py-0.5 bg-yellow-100 text-yellow-800 text-xs rounded-full flex-shrink-0">
                                    <i class="fas fa-star"></i>
                                </span>
                            </div>

                            <p class="text-xs text-gray-500 mb-2">{{ tech.specialization }}</p>

                            <!-- Match Score -->
                            <div class="mb-2">
                                <div class="flex items-center justify-between text-xs mb-1">
                                    <span class="text-gray-600 font-medium">Match Score</span>
                                    <span class="font-bold text-blue-600">{{ tech.match_score }}/100</span>
                                </div>
                                <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-blue-500 to-purple-600 rounded-full transition-all" :style="{ width: tech.match_score + '%' }"></div>
                                </div>
                            </div>

                            <!-- Stats -->
                            <div class="grid grid-cols-3 gap-2 text-xs">
                                <div class="text-center">
                                    <div class="flex items-center justify-center gap-1 text-yellow-500">
                                        <i class="fas fa-star"></i>
                                        <span class="font-semibold">{{ tech.average_rating.toFixed(1) }}</span>
                                    </div>
                                    <div class="text-gray-500 text-xs">Rating</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold text-gray-900">{{ tech.total_jobs }}</div>
                                    <div class="text-gray-500 text-xs">Jobs</div>
                                </div>
                                <div class="text-center">
                                    <div class="font-semibold" :class="tech.ongoing_jobs > 3 ? 'text-orange-600' : 'text-green-600'">
                                        {{ tech.ongoing_jobs }}
                                    </div>
                                    <div class="text-gray-500 text-xs">Ongoing</div>
                                </div>
                            </div>

                            <!-- Availability -->
                            <div class="mt-2 flex items-center gap-1">
                                <div class="w-2 h-2 rounded-full" :class="tech.availability === 'available' ? 'bg-green-500' : 'bg-red-500'"></div>
                                <span class="text-xs capitalize" :class="tech.availability === 'available' ? 'text-green-600' : 'text-red-600'">
                                    {{ tech.availability }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-8 text-gray-500">
                <i class="fas fa-user-slash text-4xl mb-2"></i>
                <p class="text-sm">No recommendations available</p>
            </div>

            <!-- Action Button -->
            <button
                v-if="selectedTechnician && onSelect"
                @click="handleSelect"
                class="w-full mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold"
            >
                <i class="fas fa-check mr-2"></i>
                Assign {{ selectedTechnician.name }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    specializations: {
        type: Array,
        default: () => []
    },
    limit: {
        type: Number,
        default: 3
    },
    onSelect: {
        type: Function,
        default: null
    }
});

const recommendations = ref([]);
const selectedSpecialization = ref('');
const selectedTechnician = ref(null);
const loading = ref(false);

const loadRecommendations = async () => {
    loading.value = true;
    try {
        const params = {
            limit: props.limit
        };
        if (selectedSpecialization.value) {
            params.specialization = selectedSpecialization.value;
        }

        const response = await axios.get('/admin/api/technician-recommendations', { params });
        recommendations.value = response.data.data;
    } catch (error) {
        console.error('Failed to load recommendations:', error);
    } finally {
        loading.value = false;
    }
};

const selectTechnician = (tech) => {
    selectedTechnician.value = tech;
};

const handleSelect = () => {
    if (props.onSelect && selectedTechnician.value) {
        props.onSelect(selectedTechnician.value);
    }
};

const getInitials = (name) => {
    return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
};

const getRankClass = (index) => {
    const classes = [
        'bg-gradient-to-br from-yellow-400 to-yellow-600', // 1st - Gold
        'bg-gradient-to-br from-gray-300 to-gray-500',     // 2nd - Silver
        'bg-gradient-to-br from-orange-400 to-orange-600'  // 3rd - Bronze
    ];
    return classes[index] || 'bg-gradient-to-br from-blue-400 to-blue-600';
};

onMounted(() => {
    loadRecommendations();
});
</script>
