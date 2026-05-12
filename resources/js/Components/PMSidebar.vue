<template>
    <AppSidebar
        role-label="Project Manager"
        role-hint="Coordinate RFQs, jobs, validations, technicians, and reports from one workspace."
        :current-page="currentPage"
        :items="navItems"
    />
</template>

<script setup>
import AppSidebar from './AppSidebar.vue'

defineProps({
    currentPage: {
        type: String,
        default: 'dashboard',
    },
})

const navItems = [
    { key: 'dashboard', href: '/pm/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Live PM overview' },
    { key: 'rfqs', href: '/pm/rfqs', icon: 'fas fa-file-alt', label: 'RFQs', caption: 'Quote coordination' },
    { key: 'jobs', href: '/pm/jobs', icon: 'fas fa-tasks', label: 'Jobs', caption: 'Execution and progress' },
    { key: 'progress-reports', href: '/pm/progress-reports', icon: 'fas fa-chart-line', label: 'Progress Reports', caption: 'Validation queue' },
    { key: 'payment-sheets', href: '/pm/payment-sheets', icon: 'fas fa-file-invoice-dollar', label: 'Payment Sheets', caption: 'Compensation review' },
    { key: 'technicians', href: '/pm/technicians', icon: 'fas fa-hard-hat', label: 'Technicians', caption: 'Field resource view' },
    { key: 'messages', href: '/pm/messages', icon: 'fas fa-comments', label: 'Messages', caption: 'Communication threads' },
    {
        key: 'reports',
        href: '/pm/reports',
        icon: 'fas fa-chart-bar',
        label: 'Reports',
        caption: 'Revenue analytics',
        children: [
            { key: 'reports-rfq', href: '/pm/reports/rfq-revenue', label: 'RFQ Revenue', caption: 'Per RFQ collections' },
            { key: 'reports-client', href: '/pm/reports/client-revenue', label: 'Client Revenue', caption: 'Value and volume ranking' },
        ],
    },
]
</script>
