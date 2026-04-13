<template>
    <AdminLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Project Manager Performance Dashboard</h1>
                    <p class="mt-1 text-sm text-gray-500">Track and analyze PM output and financial impact</p>
                </div>
            </div>
        </template>

        <div class="p-6">
            <!-- Summary Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Project Managers</p>
                            <p class="text-3xl font-bold text-gray-900">{{ projectManagers.length }}</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Total Revenue</p>
                            <p class="text-3xl font-bold text-green-600">{{ formatCurrency(totalRevenue) }}</p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-dollar-sign text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Active Projects</p>
                            <p class="text-3xl font-bold text-purple-600">{{ totalActiveProjects }}</p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-project-diagram text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-600">Avg Completion Rate</p>
                            <p class="text-3xl font-bold text-yellow-600">{{ avgCompletionRate }}%</p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-check-circle text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PM List -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">All Project Managers</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Manager</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Projects</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Active</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Completion Rate</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Revenue</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Efficiency Score</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="pm in projectManagers" :key="pm.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="h-10 w-10 rounded-full bg-gradient-to-br from-purple-500 to-blue-600 flex items-center justify-center text-white font-semibold">
                                                {{ getInitials(pm.name) }}
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">{{ pm.name }}</div>
                                            <div class="text-sm text-gray-500">{{ pm.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ pm.total_projects }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    {{ pm.active_projects }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <span class="text-sm font-semibold text-gray-900 mr-2">{{ pm.completion_rate }}%</span>
                                        <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                            <div class="h-full bg-green-500 rounded-full" :style="{ width: pm.completion_rate + '%' }"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">
                                    {{ formatCurrency(pm.total_revenue) }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="getEfficiencyClass(pm.efficiency_score)">
                                        {{ pm.efficiency_score }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <Link :href="`/admin/pm-performance/${pm.id}`" class="text-blue-600 hover:text-blue-900">
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
    projectManagers: Array
});

const totalRevenue = computed(() => {
    return props.projectManagers.reduce((acc, pm) => acc + pm.total_revenue, 0);
});

const totalActiveProjects = computed(() => {
    return props.projectManagers.reduce((acc, pm) => acc + pm.active_projects, 0);
});

const avgCompletionRate = computed(() => {
    if (props.projectManagers.length === 0) return 0;
    const sum = props.projectManagers.reduce((acc, pm) => acc + pm.completion_rate, 0);
    return (sum / props.projectManagers.length).toFixed(1);
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

const getEfficiencyClass = (score) => {
    if (score >= 70) return 'bg-green-100 text-green-800';
    if (score >= 50) return 'bg-yellow-100 text-yellow-800';
    return 'bg-red-100 text-red-800';
};
</script>
