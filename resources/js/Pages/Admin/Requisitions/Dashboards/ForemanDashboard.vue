<template>
    <div class="flex flex-col gap-6">
        <!-- Action Bar -->
        <div class="bg-white p-4 rounded-lg shadow flex justify-between items-center">
            <h3 class="font-bold text-lg">My Requests</h3>
            <button @click="showCreateModal = true" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                + New Requisition
            </button>
        </div>

        <!-- Acknowledgment Section (High Priority) -->
        <div v-if="itemsToAcknowledge.length" class="bg-green-50 border border-green-200 p-4 rounded-lg shadow">
            <h4 class="font-bold text-green-800 mb-3 flex items-center gap-2">
                <i class="fas fa-truck-loading"></i> Arrived Items - Require Acknowledgment
            </h4>
            <div class="space-y-3">
                <div v-for="item in itemsToAcknowledge" :key="item.id" class="bg-white p-3 rounded border border-green-100">
                    <div class="flex justify-between items-start mb-2">
                        <div>
                            <div class="font-bold">{{ item.name }}</div>
                            <div class="text-sm text-gray-600">{{ item.quantity }} {{ item.unit }} - {{ item.requisition.project?.name }}</div>
                            <div v-if="item.tracking_number" class="text-xs text-gray-500 mt-1">
                                Tracking: {{ item.tracking_number }}
                            </div>
                        </div>
                        <button @click="openAcknowledgeModal(item)" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
                            Confirm Receipt
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Acknowledge Modal -->
        <Modal :show="showAcknowledgeModal" @close="showAcknowledgeModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Acknowledge Delivery</h2>

                <div v-if="selectedItem" class="space-y-4">
                    <div class="bg-gray-50 p-3 rounded">
                        <div class="font-semibold">{{ selectedItem.name }}</div>
                        <div class="text-sm text-gray-600">Quantity: {{ selectedItem.quantity }} {{ selectedItem.unit }}</div>
                    </div>

                    <div>
                        <InputLabel value="Delivery Condition / Notes" />
                        <textarea v-model="acknowledgeForm.delivery_condition_notes" rows="3"
                                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-green-500 focus:ring-green-500"
                                  placeholder="e.g., All items received in good condition, minor packaging damage on 2 boxes"></textarea>
                        <p class="mt-1 text-xs text-gray-500">Document the condition of received items</p>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <SecondaryButton @click="showAcknowledgeModal = false">Cancel</SecondaryButton>
                        <PrimaryButton @click="submitAcknowledge" :disabled="acknowledgeForm.processing">
                            Confirm & Close Item
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>

        <!-- Recent Requisitions List -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="p-4 border-b">
                <h4 class="font-bold">Recent Requisitions</h4>
            </div>
            <div class="divide-y">
                <div v-for="req in myRequisitions" :key="req.id" class="p-4">
                    <div class="flex justify-between mb-2">
                        <span class="font-semibold">#{{ req.id }} - {{ req.project?.name }}</span>
                        <span :class="getStatusClass(req.status)" class="px-2 py-0.5 rounded text-xs uppercase font-bold">
                            {{ req.status }}
                        </span>
                    </div>
                    <div class="text-sm text-gray-500 mb-2">{{ req.description || 'No description' }}</div>
                    
                    <!-- Item Summary -->
                    <div class="bg-gray-50 p-2 rounded text-sm space-y-1">
                        <div v-for="item in req.items" :key="item.id" class="flex justify-between">
                            <span>{{ item.name }} ({{ item.quantity }} {{ item.unit }})</span>
                            <span :class="getItemStatusClass(item.status)">{{ formatStatus(item.status) }}</span>
                        </div>
                    </div>
                </div>
                <div v-if="!myRequisitions.length" class="p-8 text-center text-gray-500">
                    No requisitions found.
                </div>
            </div>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" @close="showCreateModal = false">
            <div class="p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">Create Material Requisition</h2>
                
                <div class="space-y-4">
                    <div>
                        <InputLabel value="Project" />
                        <select v-model="form.project_id" class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option v-for="project in projects" :key="project.id" :value="project.id">
                                {{ project.name }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <InputLabel value="Description / Site Location" />
                        <TextInput v-model="form.description" type="text" class="w-full" placeholder="e.g. Block B Layout piping" />
                    </div>

                    <div class="border-t pt-4">
                        <h4 class="font-medium mb-2">Items</h4>
                        <div v-for="(item, index) in form.items" :key="index" class="flex gap-2 mb-2 items-start">
                            <TextInput v-model="item.name" placeholder="Item Name" class="flex-1" />
                            <TextInput v-model="item.quantity" type="number" placeholder="Qty" class="w-20" />
                            <TextInput v-model="item.unit" placeholder="Unit" class="w-20" />
                            <button @click="removeItem(index)" class="text-red-500 mt-2 px-2">&times;</button>
                        </div>
                        <button @click="addItem" class="text-blue-600 text-sm hover:underline">+ Add Item</button>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <SecondaryButton @click="showCreateModal = false">Cancel</SecondaryButton>
                        <PrimaryButton @click="submit" :disabled="form.processing">Submit Request</PrimaryButton>
                    </div>
                </div>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    requisitions: Array,
    projects: Array,
});

const showCreateModal = ref(false);
const showAcknowledgeModal = ref(false);
const selectedItem = ref(null);

const form = useForm({
    project_id: props.projects[0]?.id || '',
    description: '',
    items: [{ name: '', quantity: '', unit: '' }]
});

const acknowledgeForm = useForm({
    delivery_condition_notes: ''
});

const addItem = () => form.items.push({ name: '', quantity: '', unit: '' });
const removeItem = (i) => form.items.splice(i, 1);

const submit = () => {
    form.post(route('admin.requisitions.store'), {
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
            form.items = [{ name: '', quantity: '', unit: '' }];
        }
    });
};

const myRequisitions = computed(() => {
    // In a real app the controller filters this, but checking strictly:
    return props.requisitions; 
});

const itemsToAcknowledge = computed(() => {
    const items = [];
    props.requisitions.forEach(req => {
        req.items.forEach(item => {
            if (item.status === 'delivered') {
                item.requisition = req; // attach parent for context
                items.push(item);
            }
        });
    });
    return items;
});

const openAcknowledgeModal = (item) => {
    selectedItem.value = item;
    acknowledgeForm.delivery_condition_notes = '';
    showAcknowledgeModal.value = true;
};

const submitAcknowledge = () => {
    acknowledgeForm.post(route('admin.requisitions.items.acknowledge', selectedItem.value.id), {
        onSuccess: () => {
            showAcknowledgeModal.value = false;
            acknowledgeForm.reset();
        }
    });
};

const getStatusClass = (status) => {
    const map = {
        pending: 'bg-yellow-100 text-yellow-800',
        active: 'bg-blue-100 text-blue-800',
        closed: 'bg-gray-100 text-gray-800',
        approved: 'bg-green-100 text-green-800',
    };
    return map[status] || 'bg-gray-100';
};

const getItemStatusClass = (status) => {
    const map = {
        requested: 'text-yellow-600',
        approved: 'text-blue-600',
        rejected: 'text-red-600 line-through',
        closed: 'text-green-600',
        delivered: 'text-purple-600 font-bold',
    };
    return map[status] || 'text-gray-500';
};

const formatStatus = (s) => s.replace('_', ' ');
</script>
