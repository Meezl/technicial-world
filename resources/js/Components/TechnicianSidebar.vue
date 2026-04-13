<template>
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2 class="logo">TECHNICIAN WORLD</h2>
        </div>
        <nav class="sidebar-nav">
            <Link href="/technician/dashboard" :class="['nav-item', { active: currentPage === 'dashboard' }]">
                <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
            </Link>
            <!-- Add more technician specific links here as they become available -->
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
