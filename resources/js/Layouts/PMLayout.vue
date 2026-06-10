<template>
    <div class="dashboard-container">
        <PMSidebar :current-page="currentPage" />

        <main class="main-content">
            <header class="main-header" v-if="$slots.header">
                <slot name="header" />
            </header>
            <slot />
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import PMSidebar from '../Components/PMSidebar.vue'

const page = usePage()

const currentPage = computed(() => {
    const url = page.url || ''

    if (url.startsWith('/pm/rfqs')) return 'rfqs'
    if (url.startsWith('/pm/jobs')) return 'jobs'
    if (url.startsWith('/pm/progress')) return 'progress-reports'
    if (url.startsWith('/pm/payment-sheets')) return 'payment-sheets'
    if (url.startsWith('/pm/technicians')) return 'technicians'
    if (url.startsWith('/pm/messages')) return 'messages'
    if (url.startsWith('/pm/reports')) return 'reports'
    return 'dashboard'
})
</script>

<style>
</style>
