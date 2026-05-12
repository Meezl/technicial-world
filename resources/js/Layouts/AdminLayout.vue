<template>
    <div class="dashboard-container">
        <AppSidebar
            role-label="Administrator"
            role-hint="Monitor operations, performance, and financial activity from one control center."
            :current-page="currentPage"
            :items="navItems"
        />

        <main class="main-content">
            <!-- Header Slot -->
            <header class="main-header" v-if="$slots.header">
                <slot name="header" />
            </header>

            <!-- Content Slot -->
            <slot />
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppSidebar from '../Components/AppSidebar.vue'

const page = usePage()

const navItems = [
    { key: 'dashboard', href: '/admin/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Overall activity' },
    { key: 'projects', href: '/admin/projects/dashboard', icon: 'fas fa-project-diagram', label: 'Projects', caption: 'Delivery and schedules' },
    { key: 'rfq', href: '/admin/rfq', icon: 'fas fa-file-alt', label: 'RFQ Management', caption: 'Quotes and approvals' },
    { key: 'technicians', href: '/admin/technicians', icon: 'fas fa-hard-hat', label: 'Technicians', caption: 'Field teams and profiles' },
    { key: 'technician-performance', href: '/admin/technician-performance', icon: 'fas fa-chart-line', label: 'Technician Performance', caption: 'Ratings and analytics' },
    { key: 'pm-performance', href: '/admin/pm-performance', icon: 'fas fa-chart-bar', label: 'PM Performance', caption: 'Manager delivery metrics' },
    { key: 'users', href: '/admin/users', icon: 'fas fa-users', label: 'Users', caption: 'Accounts and permissions' },
    { key: 'jobs', href: '/admin/jobs', icon: 'fas fa-tasks', label: 'Jobs', caption: 'Execution monitoring' },
    { key: 'tools', href: '/admin/tools', icon: 'fas fa-tools', label: 'Tools', caption: 'Assets and inventory' },
    { key: 'payments', href: '/admin/payments', icon: 'fas fa-credit-card', label: 'Payments', caption: 'Collections and payouts' },
    { key: 'requisitions', href: '/admin/requisitions', icon: 'fas fa-boxes', label: 'Requisitions', caption: 'Procurement workflow' },
]

const currentPage = computed(() => {
    const url = page.url || ''

    if (url.startsWith('/admin/projects')) return 'projects'
    if (url.startsWith('/admin/rfq')) return 'rfq'
    if (url.startsWith('/admin/technician-performance')) return 'technician-performance'
    if (url.startsWith('/admin/pm-performance')) return 'pm-performance'
    if (url.startsWith('/admin/technicians')) return 'technicians'
    if (url.startsWith('/admin/users')) return 'users'
    if (url.startsWith('/admin/jobs')) return 'jobs'
    if (url.startsWith('/admin/tools')) return 'tools'
    if (url.startsWith('/admin/payments')) return 'payments'
    if (url.startsWith('/admin/requisitions')) return 'requisitions'
    return 'dashboard'
})
</script>

<style>
@import url('../../css/dashboard-app.css');
</style>
