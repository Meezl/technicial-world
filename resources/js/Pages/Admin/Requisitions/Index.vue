<template>
    <AdminLayout>
        <template #header>
            <h1>{{ dashboardTitle }}</h1>
            <div class="header-actions">
                <!-- Optional Header Actions -->
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto">
                <!-- Role-Based Dashboard Loader -->
                <component 
                    :is="currentDashboard" 
                    :requisitions="requisitions" 
                    :projects="projects" 
                />
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

import ForemanDashboard from './Dashboards/ForemanDashboard.vue';
import OfficeDashboard from './Dashboards/OfficeDashboard.vue';
import ProcurementDashboard from './Dashboards/ProcurementDashboard.vue';
import AccountsDashboard from './Dashboards/AccountsDashboard.vue';
import AdminDashboard from './Dashboards/OfficeDashboard.vue'; // Reuse OfficeDashboard for Admin for now

const props = defineProps({
    requisitions: Array,
    projects: Array,
});

const page = usePage();
const userRole = computed(() => page.props.auth.user.role);

const currentDashboard = computed(() => {
    switch (userRole.value) {
        case 'foreman': return ForemanDashboard;
        case 'office': return OfficeDashboard;
        case 'procurement': return ProcurementDashboard;
        case 'accounts': return AccountsDashboard;
        case 'admin': return AdminDashboard;
        default: return OfficeDashboard;
    }
});

const dashboardTitle = computed(() => {
    switch (userRole.value) {
        case 'foreman': return 'Site Requisitions';
        case 'office': return 'Requisition Review';
        case 'procurement': return 'Procurement Queue';
        case 'accounts': return 'Payments Approval';
        case 'admin': return 'Requisition Management - Admin View';
        default: return 'Requisition Management';
    }
});
</script>
