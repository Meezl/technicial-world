<template>
    <div class="overflow-x-auto">
        <table v-if="projects.length > 0" class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Progress</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Budget</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Revenue</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Start Date</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="project in projects" :key="project.id" class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ project.name }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="getStatusClass(project.status)">
                            {{ project.status }}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <span class="text-sm mr-2">{{ project.progress_percentage }}%</span>
                            <div class="w-16 h-2 bg-gray-200 rounded-full">
                                <div class="h-full bg-blue-500 rounded-full" :style="{ width: project.progress_percentage + '%' }"></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm">{{ formatCurrency(project.budget_amount) }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-green-600">{{ formatCurrency(project.revenue_generated) }}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDate(project.start_date) }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="text-center py-8 text-gray-500">
            <i class="fas fa-folder-open text-4xl mb-2"></i>
            <p>No projects found</p>
        </div>
    </div>
</template>

<script setup>
const props = defineProps({
    projects: Array
});

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'KES',
    }).format(amount || 0);
};

const formatDate = (date) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
};

const getStatusClass = (status) => {
    const classes = {
        'active': 'bg-blue-100 text-blue-800',
        'completed': 'bg-green-100 text-green-800',
        'planning': 'bg-yellow-100 text-yellow-800',
        'on_hold': 'bg-red-100 text-red-800'
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};
</script>
