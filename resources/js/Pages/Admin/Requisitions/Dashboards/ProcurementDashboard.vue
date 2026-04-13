<template>
    <div class="flex flex-col gap-6">
        
        <!-- Procurement Queue (Approved Items) -->
        <div class="bg-white p-4 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-blue-800">1. Procurement Queue (Need Quotes)</h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-blue-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-blue-700 uppercase">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-blue-700 uppercase">Qty</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-blue-700 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="item in approvedItems" :key="item.id">
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium">{{ item.name }}</div>
                                <div class="text-xs text-gray-500">{{ item.requisition.project?.name }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm">{{ item.quantity }} {{ item.unit }}</td>
                            <td class="px-6 py-4 text-sm">
                                <button @click="openProcureModal(item)" class="bg-blue-600 text-white px-3 py-1 rounded text-xs">
                                    Process Order
                                </button>
                            </td>
                        </tr>
                         <tr v-if="approvedItems.length === 0">
                            <td colspan="3" class="px-6 py-4 text-center text-gray-500 text-sm">No items waiting for quotes.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Dispatch Queue (Paid Items) -->
        <div class="bg-white p-4 rounded-lg shadow">
            <h3 class="font-bold text-lg mb-4 text-purple-800">2. Dispatch Queue (Ready to Ship)</h3>
             <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-purple-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase">Item</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase">Supplier</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-purple-700 uppercase">Action</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                         <tr v-for="item in dispatchItems" :key="item.id">
                             <td class="px-6 py-4">
                                <div class="text-sm font-medium">{{ item.name }}</div>
                                <div class="text-xs text-gray-500">{{ item.quantity }} {{ item.unit }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm">{{ item.supplier_name }}</td>
                             <td class="px-6 py-4 text-sm">
                                <span class="px-2 py-1 rounded text-xs font-bold" :class="item.status === 'paid' ? 'bg-green-100 text-green-800' : 'bg-purple-100 text-purple-800'">
                                    {{ item.status === 'paid' ? 'Paid - Ready' : 'In Transit' }}
                                </span>
                             </td>
                             <td class="px-6 py-4 text-sm">
                                 <button v-if="item.status === 'paid'" @click="openTransitModal(item)" class="bg-purple-600 text-white px-3 py-1 rounded text-xs mr-2">
                                    Start Transit
                                </button>
                                <button v-if="item.status === 'in_transit'" @click="updateStatus(item, 'deliver')" class="bg-green-600 text-white px-3 py-1 rounded text-xs">
                                    Mark Delivered
                                </button>
                             </td>
                         </tr>
                         <tr v-if="dispatchItems.length === 0">
                            <td colspan="4" class="px-6 py-4 text-center text-gray-500 text-sm">No items in dispatch queue.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Procure Modal -->
        <div v-if="showModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl w-[500px] max-h-[90vh] overflow-y-auto">
                <h3 class="text-lg font-bold mb-4">Procure Item: {{ selectedItem?.name }}</h3>
                <form @submit.prevent="submitProcure" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Supplier Name *</label>
                        <input v-model="procureForm.supplier_name" type="text" required
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Price (Total) *</label>
                        <input v-model="procureForm.price" type="number" step="0.01" required
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                     <div>
                        <label class="block text-sm font-medium text-gray-700">Currency</label>
                        <select v-model="procureForm.currency" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option>USD</option>
                            <option>EUR</option>
                            <option>KES</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Upload Quotation (PDF, Images)</label>
                        <input type="file" @change="handleFileUpload" accept=".pdf,.jpg,.jpeg,.png"
                               class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                        <p class="mt-1 text-xs text-gray-500">Optional: Upload supplier quotation</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Quotation Notes</label>
                        <textarea v-model="procureForm.quotation_notes" rows="3"
                                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                  placeholder="Additional notes about the quotation or procurement"></textarea>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="showModal = false" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            Submit Order
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Transit Modal -->
        <div v-if="showTransitModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-xl w-96">
                <h3 class="text-lg font-bold mb-4">Start Transit: {{ selectedItem?.name }}</h3>
                <form @submit.prevent="submitTransit" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Tracking Number</label>
                        <input v-model="transitForm.tracking_number" type="text"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-purple-500 focus:ring-purple-500"
                               placeholder="e.g. DHL123456789">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Expected Delivery Date</label>
                        <input v-model="transitForm.expected_delivery_date" type="date"
                               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-purple-500 focus:ring-purple-500">
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="showTransitModal = false" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                            Start Transit
                        </button>
                    </div>
                </form>
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

const getItemsByStatus = (statuses) => {
    const items = [];
    props.requisitions.forEach(req => {
        req.items.filter(i => statuses.includes(i.status)).forEach(i => {
           i.requisition = req;
           items.push(i); 
        });
    });
    return items;
};

const approvedItems = computed(() => getItemsByStatus(['approved']));
const dispatchItems = computed(() => getItemsByStatus(['paid', 'in_transit'])); // Procurement sees paid (ready to ship) and moving items

// Procurement Modal Logic
const showModal = ref(false);
const showTransitModal = ref(false);
const selectedItem = ref(null);
const procureForm = useForm({
    action: 'procure',
    supplier_name: '',
    price: '',
    currency: 'USD',
    quotation_file: null,
    quotation_notes: ''
});

const transitForm = useForm({
    action: 'transit',
    tracking_number: '',
    expected_delivery_date: ''
});

const openProcureModal = (item) => {
    selectedItem.value = item;
    procureForm.reset();
    procureForm.supplier_name = '';
    procureForm.price = '';
    procureForm.quotation_notes = '';
    procureForm.quotation_file = null;
    showModal.value = true;
};

const handleFileUpload = (event) => {
    procureForm.quotation_file = event.target.files[0];
};

const submitProcure = () => {
    procureForm.post(route('admin.requisitions.items.update', selectedItem.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showModal.value = false;
            procureForm.reset();
        }
    });
};

const openTransitModal = (item) => {
    selectedItem.value = item;
    transitForm.reset();
    transitForm.tracking_number = '';
    transitForm.expected_delivery_date = '';
    showTransitModal.value = true;
};

const submitTransit = () => {
    transitForm.post(route('admin.requisitions.items.update', selectedItem.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            showTransitModal.value = false;
            transitForm.reset();
        }
    });
};

const updateStatus = (item, action) => {
    if(!confirm('Confirm action?')) return;
    router.post(route('admin.requisitions.items.update', item.id), {
        action: action
    }, { preserveScroll: true });
};
</script>
