<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="reports-rfq" />
        <RevenueBreakdownPanel
            :report="report"
            :filters="filters"
            route-path="/admin/reports/rfq-revenue"
            export-pdf-path="/admin/reports/rfq-revenue/export/pdf"
            export-excel-path="/admin/reports/rfq-revenue/export/excel"
            context-label="Executive Revenue Drilldown"
            variant="rfq"
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
