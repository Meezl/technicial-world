<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="reports" />
        <ReportsAnalyticsPanel
            :report="report"
            :filters="filters"
            route-path="/admin/reports"
            context-label="Executive Revenue Snapshot"
        />
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import ReportsAnalyticsPanel from '../../Components/Reports/ReportsAnalyticsPanel.vue'

defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

defineOptions({ layout: null })
</script>

<style>
</style>
