<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="reports-client" />
        <RevenueBreakdownPanel
            :report="report"
            :filters="filters"
            route-path="/admin/reports/client-revenue"
            export-pdf-path="/admin/reports/client-revenue/export/pdf"
            export-excel-path="/admin/reports/client-revenue/export/excel"
            context-label="Executive Client Ranking"
            variant="client"
        />
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import RevenueBreakdownPanel from '../../Components/Reports/RevenueBreakdownPanel.vue'

defineProps({
    report: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

defineOptions({ layout: null })
</script>

<style>
@import url('../../../css/dashboard-app.css');
</style>
