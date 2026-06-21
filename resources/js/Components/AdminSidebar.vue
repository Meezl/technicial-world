<template>
    <AppSidebar
        role-label="Administrator"
        role-hint="Monitor operations, payments, people, and reporting from one control center."
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
    { key: 'dashboard', href: '/admin/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Overall activity' },
    { key: 'projects', href: '/admin/projects/dashboard', icon: 'fas fa-project-diagram', label: 'Projects', caption: 'Timelines and delivery' },
    { key: 'rfq', href: '/admin/rfq', icon: 'fas fa-file-alt', label: 'RFQ Management', caption: 'Quotes and approvals' },
    { key: 'technicians', href: '/admin/technicians', icon: 'fas fa-hard-hat', label: 'Technicians', caption: 'Field teams and reports' },
    { key: 'users', href: '/admin/users', icon: 'fas fa-users', label: 'Users', caption: 'Accounts and roles' },
    { key: 'jobs', href: '/admin/jobs', icon: 'fas fa-tasks', label: 'Jobs', caption: 'Live execution status' },
    { key: 'tools', href: '/admin/tools', icon: 'fas fa-tools', label: 'Tools', caption: 'Inventory and assets' },
    { key: 'service-categories', href: '/admin/service-categories', icon: 'fas fa-layer-group', label: 'Service Categories', caption: 'Trades and specialities' },
    { key: 'tickets', href: '/admin/tickets', icon: 'fas fa-life-ring', label: 'Tickets', caption: 'Support and emergency requests' },
    { key: 'payments', href: '/admin/payments', icon: 'fas fa-credit-card', label: 'Payments', caption: 'Collections and payouts' },
    { key: 'mpesa-transactions', href: '/admin/mpesa-transactions', icon: 'fas fa-mobile-alt', label: 'M-Pesa Transactions', caption: 'Raw callback logs' },
    { key: 'email-logs', href: '/admin/email-logs', icon: 'fas fa-envelope', label: 'Email Logs', caption: 'Outgoing email archive' },
    { key: 'payment-processing', href: '/admin/payment-processing', icon: 'fas fa-file-invoice-dollar', label: 'Pay Technicians', caption: 'Process technician payments' },
    { key: 'requisitions', href: '/admin/requisitions', icon: 'fas fa-boxes', label: 'Requisitions', caption: 'Procurement workflows' },
    {
        key: 'reports',
        href: '/admin/reports',
        icon: 'fas fa-chart-bar',
        label: 'Reports',
        caption: 'Revenue and analytics',
        children: [
            { key: 'reports-rfq', href: '/admin/reports/rfq-revenue', label: 'RFQ Revenue', caption: 'Per RFQ collections' },
            { key: 'reports-client', href: '/admin/reports/client-revenue', label: 'Client Revenue', caption: 'Value and volume ranking' },
        ],
    },
    { key: 'audit-logs', href: '/admin/audit-logs', icon: 'fas fa-clipboard-list', label: 'Audit Logs', caption: 'History and traceability' },
]
</script>
