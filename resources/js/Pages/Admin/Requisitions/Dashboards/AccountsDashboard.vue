<template>
    <div class="flex flex-col gap-6">
        <div class="bg-white p-4 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-green-800">Payments Approval</h3>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-green-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-green-700 uppercase">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-green-700 uppercase">Supplier</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-green-700 uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-green-700 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="item in payableItems" :key="item.id">
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium">{{ item.name }}</div>
                                <div class="text-xs text-gray-500">{{ item.requisition.project?.name }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm">{{ item.supplier_name }}</td>
                            <td class="px-6 py-4 text-sm font-bold">
                                {{ item.currency }} {{ item.price }} 
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <button @click="pay(item)" class="bg-green-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-green-700">
                                    Approve Payment &rarr;
                                </button>
                            </td>
                        </tr>
                        <tr v-if="payableItems.length === 0">
                            <td colspan="4" class="px-6 py-10 text-center text-gray-500">
                                <i class="fas fa-check-circle text-2xl mb-2 text-green-500"></i><br>
                                All payments cleared.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    requisitions: Array,
    projects: Array,
});

const payableItems = computed(() => {
    const items = [];
    props.requisitions.forEach(req => {
        req.items.filter(i => i.status === 'awaiting_payment').forEach(i => {
           i.requisition = req;
           items.push(i); 
        });
    });
    return items;
});

const pay = (item) => {
    if(!confirm(`Confirm payment of ${item.currency} ${item.price} to ${item.supplier_name}?`)) return;
    router.post(route('admin.requisitions.items.update', item.id), {
        action: 'pay'
    }, { preserveScroll: true });
};
</script>
