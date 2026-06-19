<template>
    <nav class="client-pwa-nav">
        <Link href="/client/dashboard" :class="['nav-link', { active: currentPage === 'dashboard' }]">
            <span class="nav-icon-wrap"><i class="fas fa-tachometer-alt"></i></span>
            <span>Dashboard</span>
        </Link>
        <Link href="/client/new-request" :class="['nav-link', { active: currentPage === 'new-request' }]">
            <span class="nav-icon-wrap"><i class="fas fa-plus-circle"></i></span>
            <span>Request</span>
        </Link>
        <Link href="/client/payments" :class="['nav-link', { active: currentPage === 'payments' }]">
            <span class="nav-icon-wrap"><i class="fas fa-file-invoice-dollar"></i></span>
            <span>Payments</span>
        </Link>
        <Link href="/client/support" :class="['nav-link', { active: currentPage === 'support' }]">
            <span class="nav-icon-wrap"><i class="fas fa-headset"></i></span>
            <span>Support</span>
        </Link>
        <Link href="/client/profile" :class="['nav-link', { active: currentPage === 'profile' }]">
            <span class="nav-icon-wrap"><i class="fas fa-user-circle"></i></span>
            <span>Profile</span>
        </Link>
    </nav>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    currentPage: {
        type: String,
        required: true,
    },
})
</script>

<style>
@import url('../../css/client-pwa.css');
</style>
