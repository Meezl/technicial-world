<template>
    <div class="dashboard-container">
        <PMSidebar current-page="reports-client" />
        <RevenueBreakdownPanel
            :report="report"
            :filters="filters"
            route-path="/pm/reports/client-revenue"
            export-pdf-path="/pm/reports/client-revenue/export/pdf"
            export-excel-path="/pm/reports/client-revenue/export/excel"
            context-label="Project Client Ranking"
            variant="client"
        />
    </div>
</template>

<script setup>
import PMSidebar from '../../Components/PMSidebar.vue'
import RevenueBreakdownPanel from '../../Components/Reports/RevenueBreakdownPanel.vue'

defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

defineOptions({ layout: null })
</script>

<style>
</style>
