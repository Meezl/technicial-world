<template>
    <AppSidebar
        role-label="Technician Portal"
        role-hint="Stay on top of assigned jobs, earnings, tools, and your profile."
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
    { key: 'dashboard', href: '/technician/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Daily overview' },
    { key: 'jobs', href: '/technician/jobs', icon: 'fas fa-clipboard-list', label: 'Jobs', caption: 'Assignments and status' },
    { key: 'earnings', href: '/technician/earnings', icon: 'fas fa-money-bill-wave', label: 'Earnings', caption: 'Payments and totals' },
    { key: 'tools', href: '/technician/tools', icon: 'fas fa-tools', label: 'Tools', caption: 'Issued equipment' },
    { key: 'profile', href: '/technician/profile', icon: 'fas fa-user-circle', label: 'Profile', caption: 'Account and details' },
]
</script>
