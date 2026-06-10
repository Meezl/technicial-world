<template>
    <div class="dashboard-container">
        <PMSidebar current-page="reports" />

        <ReportsAnalyticsPanel
            :report="report"
            :filters="filters"
            route-path="/pm/reports"
            context-label="Project Revenue Snapshot"
        />
    </div>
</template>

<script setup>
import PMSidebar from '../../Components/PMSidebar.vue'
import ReportsAnalyticsPanel from '../../Components/Reports/ReportsAnalyticsPanel.vue'

defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

defineOptions({ layout: null })
</script>

<style>
</style>
