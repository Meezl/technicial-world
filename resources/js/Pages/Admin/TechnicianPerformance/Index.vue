<template>
    <AdminLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Technician Performance Dashboard</h1>
                    <p class="mt-1 text-sm text-gray-500">Track and analyze technician performance metrics</p>
                </div>
            </div>
        </template>

        <div class="p-6">
            <!-- Summary Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Technicians</p>
                            <p class="text-3xl font-bold text-gray-900">{{ technicians.length }}</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Top Rated</p>
                            <p class="text-3xl font-bold text-yellow-600">{{ topRatedCount }}</p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-star text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Avg Rating</p>
                            <p class="text-3xl font-bold text-green-600">{{ averageRating.toFixed(1) }}</p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-chart-line text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Jobs</p>
                            <p class="text-3xl font-bold text-purple-600">{{ totalJobs }}</p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-briefcase text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Technicians List -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">All Technicians</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Technician</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match Score</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rating</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jobs</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completion Rate</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ongoing</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="tech in technicians" :key="tech.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold">
                                                {{ getInitials(tech.name) }}
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="flex items-center gap-2">
                                                <div class="text-sm font-medium text-gray-900">{{ tech.name }}</div>
                                                <span v-if="tech.is_top_rated" class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full">
                                                    <i class="fas fa-star"></i>
                                                </span>
                                            </div>
                                            <div class="text-sm text-gray-500">{{ tech.specialization }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="text-sm font-bold text-blue-600">{{ tech.match_score }}</div>
                                        <div class="ml-2 w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-blue-600 rounded-full" :style="{ width: tech.match_score + '%' }"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <i class="fas fa-star text-yellow-400 text-sm mr-1"></i>
                                        <span class="text-sm font-semibold text-gray-900">{{ tech.average_rating.toFixed(1) }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ tech.total_jobs }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="getCompletionRateClass(tech.completion_rate)">
                                        {{ tech.completion_rate }}%
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ tech.ongoing_jobs }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold" :class="tech.outstanding_balance > 0 ? 'text-orange-600' : 'text-green-600'">
                                    {{ formatCurrency(tech.outstanding_balance) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <Link :href="`/admin/technician-performance/${tech.id}`" class="text-blue-600 hover:text-blue-900">
                                        View Details
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    technicians: Array
});

const topRatedCount = computed(() => {
    return props.technicians.filter(t => t.is_top_rated).length;
});

const averageRating = computed(() => {
    if (props.technicians.length === 0) return 0;
    const sum = props.technicians.reduce((acc, t) => acc + t.average_rating, 0);
    return sum / props.technicians.length;
});

const totalJobs = computed(() => {
    return props.technicians.reduce((acc, t) => acc + t.total_jobs, 0);
});

const getInitials = (name) => {
    return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'KES',
    }).format(amount || 0);
};

const getCompletionRateClass = (rate) => {
    if (rate >= 80) return 'bg-green-100 text-green-800';
    if (rate >= 60) return 'bg-yellow-100 text-yellow-800';
    return 'bg-red-100 text-red-800';
};
</script>
