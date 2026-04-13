<template>
    <div class="flex flex-col gap-6">
        <div class="bg-white p-4 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4">Requisition Review Queue</h3>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project / Requestor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="item in requestedItems" :key="item.id">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ item.name }}</div>
                                <div class="text-xs text-gray-500">{{ item.unit }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900">{{ item.requisition.project?.name }}</div>
                                <div class="text-xs text-gray-500">{{ item.requisition.creator?.name }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div v-if="editingId === item.id" class="flex items-center gap-2">
                                    <input v-model="editForm.quantity" type="number" class="w-20 text-sm rounded border-gray-300">
                                    <button @click="saveQuantity(item)" class="text-green-600"><i class="fas fa-check"></i></button>
                                    <button @click="editingId = null" class="text-gray-500"><i class="fas fa-times"></i></button>
                                </div>
                                <div v-else class="flex items-center gap-2">
                                    <span class="text-sm text-gray-900">{{ item.quantity }}</span>
                                    <button @click="startEdit(item)" class="text-blue-400 hover:text-blue-600 text-xs"><i class="fas fa-pencil-alt"></i></button>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                <button @click="approve(item)" class="text-green-600 hover:text-green-900 bg-green-50 px-3 py-1 rounded">Approve</button>
                                <button @click="reject(item)" class="text-red-600 hover:text-red-900 bg-red-50 px-3 py-1 rounded">Reject</button>
                            </td>
                        </tr>
                        <tr v-if="requestedItems.length === 0">
                            <td colspan="4" class="px-6 py-10 text-center text-gray-500">
                                No items pending review.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    requisitions: Array,
    projects: Array,
});

const requestedItems = computed(() => {
    const items = [];
    props.requisitions.forEach(req => {
        req.items.filter(i => i.status === 'requested').forEach(i => {
           i.requisition = req;
           items.push(i); 
        });
    });
    return items;
});

const approve = (item) => {
    if(!confirm('Approve this item?')) return;
    router.post(route('admin.requisitions.items.update', item.id), {
        action: 'approve'
    }, { preserveScroll: true });
};

const reject = (item) => {
    const reason = prompt('Reason for rejection:');
    if (reason === null) return;
    router.post(route('admin.requisitions.items.update', item.id), {
        action: 'reject',
        notes: reason
    }, { preserveScroll: true });
};

const editingId = ref(null);
const editForm = useForm({ quantity: '' });

const startEdit = (item) => {
    editingId.value = item.id;
    editForm.quantity = item.quantity;
};

const saveQuantity = (item) => {
    editForm.post(route('admin.requisitions.items.update', item.id), {
        data: { action: 'update_qty' },
        preserveScroll: true,
        onSuccess: () => editingId.value = null
    });
};
</script>
