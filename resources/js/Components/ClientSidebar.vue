<template>
    <AppSidebar
        role-label="Client Portal"
        role-hint="Submit requests, review progress, and track payments with fewer clicks."
        :current-page="resolvedPage"
        :items="navItems"
    />
</template>

<script setup>
import AppSidebar from './AppSidebar.vue'

const props = defineProps({
    currentPage: {
        type: String,
        default: 'dashboard',
    },
})

const resolvedPage = props.currentPage === 'statements' ? 'payments' : props.currentPage

const navItems = [
    { key: 'dashboard', href: '/client/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Overview and updates' },
    { key: 'new-request', href: '/client/new-request', icon: 'fas fa-plus-circle', label: 'New Request', caption: 'Start a new job' },
    { key: 'payments', href: '/client/payments', icon: 'fas fa-file-invoice-dollar', label: 'Payments', caption: 'Statements and balances' },
    { key: 'support', href: '/client/support', icon: 'fas fa-headset', label: 'Support', caption: 'Need help fast?' },
    { key: 'profile', href: '/client/profile', icon: 'fas fa-user-circle', label: 'Profile', caption: 'Personal details' },
]
</script>
