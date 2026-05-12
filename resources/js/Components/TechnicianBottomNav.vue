<template>
    <nav class="pwa-nav">
        <Link href="/technician/dashboard" :class="['nav-link', { active: currentPage === 'dashboard' }]">
            <span class="nav-icon-wrap"><i class="fas fa-th-large"></i></span>
            <span>Dashboard</span>
        </Link>
        <Link href="/technician/jobs" :class="['nav-link', { active: currentPage === 'jobs' }]">
            <span class="nav-icon-wrap"><i class="fas fa-clipboard-list"></i></span>
            <span>Jobs</span>
        </Link>
        <Link href="/technician/earnings" :class="['nav-link', { active: currentPage === 'earnings' }]">
            <span class="nav-icon-wrap"><i class="fas fa-money-bill-wave"></i></span>
            <span>Earnings</span>
        </Link>
        <Link href="/technician/tools" :class="['nav-link', { active: currentPage === 'tools' }]">
            <span class="nav-icon-wrap"><i class="fas fa-tools"></i></span>
            <span>Tools</span>
        </Link>
        <Link href="/technician/profile" :class="['nav-link', { active: currentPage === 'profile' }]">
            <span class="nav-icon-wrap"><i class="fas fa-user-circle"></i></span>
            <span>Profile</span>
        </Link>
    </nav>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    currentPage: {
        type: String,
        required: true
    }
});
</script>

<style>
@import url('../../css/technician-pwa.css');
</style>
