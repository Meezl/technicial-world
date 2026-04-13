<template>
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2 class="logo">TECHNICIAN WORLD</h2>
        </div>
        <nav class="sidebar-nav">
            <Link href="/client/dashboard" :class="['nav-item', { active: currentPage === 'dashboard' }]">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
            </Link>
            <Link href="/client/new-request" :class="['nav-item', { active: currentPage === 'new-request' }]">
                <i class="fas fa-plus-circle"></i><span>New Request</span>
            </Link>
            <Link href="/client/payments" :class="['nav-item', { active: currentPage === 'payments' }]">
                <i class="fas fa-file-invoice-dollar"></i><span>Payments</span>
            </Link>
            <Link href="/client/support" :class="['nav-item', { active: currentPage === 'support' }]">
                <i class="fas fa-headset"></i><span>Support</span>
            </Link>
            <Link href="/client/profile" :class="['nav-item', { active: currentPage === 'profile' }]">
                <i class="fas fa-user-circle"></i><span>Profile</span>
            </Link>
        </nav>
        <div class="sidebar-footer">
            <Link href="/logout" class="nav-item" method="post" as="button">
                <i class="fas fa-sign-out-alt"></i><span>Log Out</span>
            </Link>
        </div>
    </aside>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    currentPage: {
        type: String,
        default: 'dashboard'
    }
})
</script>

<style>
@import url('../../css/dashboard-app.css');
</style>
