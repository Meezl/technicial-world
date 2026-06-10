<template>
    <div class="dashboard-container">
        <PMSidebar current-page="reports-rfq" />
        <RevenueBreakdownPanel
            :report="report"
            :filters="filters"
            route-path="/pm/reports/rfq-revenue"
            export-pdf-path="/pm/reports/rfq-revenue/export/pdf"
            export-excel-path="/pm/reports/rfq-revenue/export/excel"
            context-label="Project Revenue Drilldown"
            variant="rfq"
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
