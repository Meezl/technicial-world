<template>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2 class="logo">TECHNICIAN WORLD</h2>
            </div>
            <nav class="sidebar-nav">
                <Link href="/admin/dashboard" class="nav-item" :class="{ 'active': $page.url === '/admin/dashboard' }">
                    <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                </Link>
                <Link href="/admin/projects/dashboard" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/projects') }">
                    <i class="fas fa-project-diagram"></i><span>Project Management</span>
                </Link>
                <Link href="/admin/rfq" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/rfq') }">
                    <i class="fas fa-file-alt"></i><span>RFQ Management</span>
                </Link>
                <Link href="/admin/technicians" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/technicians') && !$page.url.startsWith('/admin/technician-performance') }">
                    <i class="fas fa-hard-hat"></i><span>Technicians</span>
                </Link>
                <Link href="/admin/technician-performance" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/technician-performance') }">
                    <i class="fas fa-chart-line"></i><span>Technician Performance</span>
                </Link>
                <Link href="/admin/pm-performance" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/pm-performance') }">
                    <i class="fas fa-chart-bar"></i><span>PM Performance</span>
                </Link>
                <Link href="/admin/users" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/users') }">
                    <i class="fas fa-users"></i><span>User Management</span>
                </Link>
                <Link href="/admin/jobs" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/jobs') }">
                    <i class="fas fa-tasks"></i><span>Jobs Monitoring</span>
                </Link>
                <Link href="/admin/tools" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/tools') }">
                    <i class="fas fa-tools"></i><span>Tools Management</span>
                </Link>
                <Link href="/admin/payments" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/payments') }">
                    <i class="fas fa-credit-card"></i><span>Payments</span>
                </Link>
                <Link href="/admin/requisitions" class="nav-item" :class="{ 'active': $page.url.startsWith('/admin/requisitions') }">
                    <i class="fas fa-boxes"></i><span>Requisitions</span>
                </Link>
            </nav>
            <div class="sidebar-footer">
                <a href="#" @click.prevent="handleLogout" class="nav-item">
                    <i class="fas fa-sign-out-alt"></i><span>Log Out</span>
                </a>
            </div>
        </aside>

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
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';

const handleLogout = () => {
    // Send logout request via axios, then force a hard reload
    // ensuring no CSS pollution from dashboard remains
    axios.post(route('logout')).then(() => {
        window.location.href = '/'; 
    });
};
</script>

<style>
@import url('../../css/dashboard-app.css');
</style>
