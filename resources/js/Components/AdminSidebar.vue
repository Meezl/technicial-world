<template>
    <AppSidebar
        role-label="Administrator"
        role-hint="Monitor operations, payments, people, and reporting from one control center."
        :current-page="currentPage"
        :items="navItems"
    />
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppSidebar from './AppSidebar.vue'

defineProps({
    currentPage: {
        type: String,
        default: 'dashboard',
    },
})

const page = usePage()
const corporateEnabled = computed(() => !!page.props.corporate?.enabled)

const baseItems = [
    { key: 'dashboard', href: '/admin/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Overall activity' },
    { key: 'projects', href: '/admin/projects/dashboard', icon: 'fas fa-project-diagram', label: 'Projects', caption: 'Timelines and delivery' },
    { key: 'rfq', href: '/admin/rfq', icon: 'fas fa-file-alt', label: 'RFQ Management', caption: 'Quotes and approvals' },
    { key: 'archive', href: '/admin/archive', icon: 'fas fa-box-archive', label: 'Archive', caption: 'Completed and cancelled' },
    { key: 'technicians', href: '/admin/technicians', icon: 'fas fa-hard-hat', label: 'Technicians', caption: 'Field teams and reports' },
    { key: 'gang-members', href: '/admin/gang-members', icon: 'fas fa-people-group', label: 'Gang Members', caption: 'On site, not tradesmen' },
    { key: 'users', href: '/admin/users', icon: 'fas fa-users', label: 'Users', caption: 'Accounts and roles' },
    { key: 'jobs', href: '/admin/jobs', icon: 'fas fa-tasks', label: 'Jobs', caption: 'Live execution status' },
    { key: 'progress-reports', href: '/admin/progress-reports', icon: 'fas fa-clipboard-check', label: 'Progress Reports', caption: 'Validate, sign off and release' },
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

// The Property Management & Corporate module ships in phases and its screens
// reach main before the journey they set up exists. The routes 404 while the
// module is off; this keeps the entry out of the menu to match, so nobody
// clicks their way into a dead end.
//
// One group rather than four loose entries, placed directly under RFQ
// Management. The first version slotted four items between Users and Jobs —
// the middle of a twenty-item list — and on UAT they sat just above wherever
// the sidebar happened to be scrolled, which read as the module not having
// been deployed at all. A labelled group near the top is visible without
// scrolling and says what the four screens have in common.
const corporateGroup = {
    key: 'property-management',
    href: '/admin/organisations',
    icon: 'fas fa-building',
    label: 'Property Management',
    caption: 'Corporate accounts, billing and rates',
    // Child keys match the current-page values the screens already pass, so
    // the group highlights whichever of them is open.
    children: [
        { key: 'organisations', href: '/admin/organisations', label: 'Corporate Accounts', caption: 'Companies, properties and people' },
        { key: 'corporate-invoices', href: '/admin/corporate-invoices', label: 'Invoicing', caption: 'In-trays, dispatch and eTIMS' },
        { key: 'corporate-settlements', href: '/admin/corporate-settlements', label: 'Payments', caption: 'Confirm payments and tax certificates' },
        { key: 'rates', href: '/admin/rates', label: 'Rate Schedules', caption: 'The catalogue quotations price from' },
    ],
}

const navItems = computed(() => {
    if (!corporateEnabled.value) return baseItems

    const items = [...baseItems]
    const at = items.findIndex(i => i.key === 'rfq')
    items.splice(at === -1 ? 1 : at + 1, 0, corporateGroup)
    return items
})
</script>
