<template>
    <nav class="pwa-nav">
        <Link href="/technician/dashboard" :class="['nav-link', { active: currentPage === 'dashboard' }]">
            <i class="fas fa-th-large"></i>
            <span>Dashboard</span>
        </Link>
        <Link href="/technician/jobs" :class="['nav-link', { active: currentPage === 'jobs' }]">
            <i class="fas fa-clipboard-list"></i>
            <span>Jobs</span>
        </Link>
        <Link href="/technician/tools" :class="['nav-link', { active: currentPage === 'tools' }]">
            <i class="fas fa-tools"></i>
            <span>Tools</span>
        </Link>
        <Link href="/technician/profile" :class="['nav-link', { active: currentPage === 'profile' }]">
            <i class="fas fa-user-circle"></i>
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
