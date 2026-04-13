<template>
    <AdminLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">PM Performance Analytics</h1>
                    <p class="mt-1 text-sm text-gray-500">{{ performance.manager.name }}</p>
                </div>
                <Link href="/admin/pm-performance" class="btn btn-secondary">
                    <i class="fas fa-arrow-left mr-2"></i>Back to List
                </Link>
            </div>
        </template>

        <div class="p-6" v-if="performance && performance.manager">
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-500">
                    <p class="text-sm text-gray-600">Total Managed Revenue</p>
                    <p class="text-3xl font-bold text-green-600">{{ formatCurrency(performance.financial_metrics.total_revenue) }}</p>
                    <p class="text-sm text-gray-500 mt-1">Avg: {{ formatCurrency(performance.financial_metrics.avg_project_revenue) }}/project</p>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-blue-500">
                    <p class="text-sm text-gray-600">Active Projects</p>
                    <p class="text-3xl font-bold text-blue-600">{{ performance.project_metrics.active_projects }}</p>
                    <p class="text-sm text-gray-500 mt-1">{{ performance.project_metrics.total_projects }} total</p>
                </div>
                <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-purple-500">
                    <p class="text-sm text-gray-600">Efficiency Score</p>
                    <p class="text-3xl font-bold text-purple-600">{{ performance.project_metrics.completion_rate }}%</p>
                    <p class="text-sm text-gray-500 mt-1">Completion Rate</p>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold mb-4">Project Status Distribution</h3>
                    <ProjectStatusChart :projectMetrics="performance.project_metrics" />
                </div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold mb-4">Revenue Timeline (Last 6 Months)</h3>
                    <RevenueTimelineChart :timeSeries="performance.time_series" />
                </div>
            </div>

            <!-- Recent Projects Table -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold mb-4">Recent Projects</h3>
                <ProjectsTable :projects="performance.recent_projects" />
            </div>
        </div>

        <!-- Loading/Error State -->
        <div v-else class="p-6">
            <div class="bg-white rounded-lg shadow-md p-12 text-center">
                <div class="text-gray-400 mb-4">
                    <i class="fas fa-spinner fa-spin text-4xl"></i>
                </div>
                <p class="text-gray-600">Loading PM performance data...</p>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';
import ProjectStatusChart from '@/Components/PMPerformance/ProjectStatusChart.vue';
import RevenueTimelineChart from '@/Components/PMPerformance/RevenueTimelineChart.vue';
import ProjectsTable from '@/Components/PMPerformance/ProjectsTable.vue';

const props = defineProps({
    performance: Object
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'KES',
    }).format(amount || 0);
};
</script>

<style scoped>
.btn {
    @apply px-4 py-2 rounded-lg font-semibold transition-colors;
}

.btn-secondary {
    @apply bg-gray-200 text-gray-700 hover:bg-gray-300;
}
</style>
