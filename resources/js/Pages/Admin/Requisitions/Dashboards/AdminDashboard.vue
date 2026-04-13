<template>
    <div class="flex flex-col gap-6">
        <!-- Admin Overview Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-yellow-50 border border-yellow-200 p-4 rounded-lg">
                <div class="text-yellow-800 text-sm font-medium">Pending Approval</div>
                <div class="text-2xl font-bold text-yellow-900">{{ stats.requested }}</div>
            </div>
            <div class="bg-blue-50 border border-blue-200 p-4 rounded-lg">
                <div class="text-blue-800 text-sm font-medium">In Procurement</div>
                <div class="text-2xl font-bold text-blue-900">{{ stats.procurement }}</div>
            </div>
            <div class="bg-purple-50 border border-purple-200 p-4 rounded-lg">
                <div class="text-purple-800 text-sm font-medium">In Transit</div>
                <div class="text-2xl font-bold text-purple-900">{{ stats.inTransit }}</div>
            </div>
            <div class="bg-green-50 border border-green-200 p-4 rounded-lg">
                <div class="text-green-800 text-sm font-medium">Completed</div>
                <div class="text-2xl font-bold text-green-900">{{ stats.closed }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="flex flex-wrap gap-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                    <select v-model="filters.project" class="w-full border-gray-300 rounded-md shadow-sm">
                        <option value="">All Projects</option>
                        <option v-for="project in projects" :key="project.id" :value="project.id">
                            {{ project.name }}
                        </option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select v-model="filters.status" class="w-full border-gray-300 rounded-md shadow-sm">
                        <option value="">All Statuses</option>
                        <option value="requested">Requested</option>
                        <option value="approved">Approved</option>
                        <option value="procured">Procured</option>
                        <option value="awaiting_payment">Awaiting Payment</option>
                        <option value="paid">Paid</option>
                        <option value="in_transit">In Transit</option>
                        <option value="delivered">Delivered</option>
                        <option value="closed">Closed</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button @click="resetFilters" class="px-4 py-2 text-sm bg-gray-200 hover:bg-gray-300 rounded-md">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>

        <!-- All Requisitions Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="p-4 border-b bg-gray-50">
                <h3 class="font-bold text-lg">All Requisitions</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Req ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Project</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Requestor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Items</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template v-for="req in filteredRequisitions" :key="req.id">
                            <tr class="hover:bg-gray-50 cursor-pointer" @click="toggleExpand(req.id)">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="font-mono text-sm">#{{ req.id }}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ req.project?.name }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ req.creator?.name }}</div>
                                    <div class="text-xs text-gray-500">{{ formatDate(req.created_at) }}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-sm text-gray-900">{{ req.items.length }} items</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span :class="getReqStatusClass(req.status)" class="px-2 py-1 text-xs font-semibold rounded uppercase">
                                        {{ req.status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <button @click.stop="toggleExpand(req.id)" class="text-blue-600 hover:text-blue-800">
                                        {{ expandedReqs.includes(req.id) ? 'Hide' : 'View' }} Details
                                    </button>
                                </td>
                            </tr>
                            <!-- Expanded Row: Item Details -->
                            <tr v-if="expandedReqs.includes(req.id)" class="bg-gray-50">
                                <td colspan="6" class="px-6 py-4">
                                    <div class="space-y-3">
                                        <div v-for="item in req.items" :key="item.id"
                                             class="bg-white p-4 rounded border border-gray-200">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <div class="font-semibold text-gray-900">{{ item.name }}</div>
                                                    <div class="text-sm text-gray-600">
                                                        Qty: {{ item.quantity }} {{ item.unit }}
                                                    </div>
                                                </div>
                                                <span :class="getItemStatusClass(item.status)"
                                                      class="px-3 py-1 text-xs font-bold rounded uppercase">
                                                    {{ formatStatus(item.status) }}
                                                </span>
                                            </div>

                                            <!-- Additional Details -->
                                            <div v-if="item.supplier_name" class="text-sm text-gray-700 mt-2">
                                                <span class="font-medium">Supplier:</span> {{ item.supplier_name }}
                                                <span v-if="item.price" class="ml-4">
                                                    <span class="font-medium">Price:</span> {{ item.currency }} {{ item.price }}
                                                </span>
                                            </div>
                                            <div v-if="item.tracking_number" class="text-sm text-gray-700 mt-1">
                                                <span class="font-medium">Tracking:</span> {{ item.tracking_number }}
                                            </div>
                                            <div v-if="item.rejection_reason" class="text-sm text-red-600 mt-2">
                                                <span class="font-medium">Rejection Reason:</span> {{ item.rejection_reason }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="filteredRequisitions.length === 0">
                            <td colspan="6" class="px-6 py-10 text-center text-gray-500">
                                No requisitions found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { format } from 'date-fns';

const props = defineProps({
    requisitions: Array,
    projects: Array,
});

const expandedReqs = ref([]);
const filters = ref({
    project: '',
    status: '',
});

const toggleExpand = (id) => {
    const index = expandedReqs.value.indexOf(id);
    if (index > -1) {
        expandedReqs.value.splice(index, 1);
    } else {
        expandedReqs.value.push(id);
    }
};

const resetFilters = () => {
    filters.value = { project: '', status: '' };
};

const stats = computed(() => {
    const allItems = props.requisitions.flatMap(req => req.items);
    return {
        requested: allItems.filter(i => i.status === 'requested').length,
        procurement: allItems.filter(i => ['approved', 'procured', 'awaiting_payment'].includes(i.status)).length,
        inTransit: allItems.filter(i => ['paid', 'in_transit', 'delivered'].includes(i.status)).length,
        closed: allItems.filter(i => i.status === 'closed').length,
    };
});

const filteredRequisitions = computed(() => {
    let result = props.requisitions;

    if (filters.value.project) {
        result = result.filter(req => req.project_id == filters.value.project);
    }

    if (filters.value.status) {
        result = result.filter(req =>
            req.items.some(item => item.status === filters.value.status)
        );
    }

    return result;
});

const getReqStatusClass = (status) => {
    const map = {
        pending: 'bg-yellow-100 text-yellow-800',
        active: 'bg-blue-100 text-blue-800',
        closed: 'bg-gray-100 text-gray-800',
    };
    return map[status] || 'bg-gray-100 text-gray-600';
};

const getItemStatusClass = (status) => {
    const map = {
        requested: 'bg-yellow-100 text-yellow-800',
        approved: 'bg-blue-100 text-blue-800',
        procured: 'bg-indigo-100 text-indigo-800',
        awaiting_payment: 'bg-orange-100 text-orange-800',
        paid: 'bg-green-100 text-green-800',
        in_transit: 'bg-purple-100 text-purple-800',
        delivered: 'bg-teal-100 text-teal-800',
        closed: 'bg-gray-100 text-gray-800',
        rejected: 'bg-red-100 text-red-800',
    };
    return map[status] || 'bg-gray-100 text-gray-600';
};

const formatStatus = (s) => s.replace(/_/g, ' ');

const formatDate = (dateString) => {
    if (!dateString) return '';
    return format(new Date(dateString), 'MMM dd, yyyy');
};
</script>
