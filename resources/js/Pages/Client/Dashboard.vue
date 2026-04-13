<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="dashboard" />

        <main class="main-content">
            <header class="main-header">
                <h1>Welcome Back, {{ $page.props.auth.user?.name || 'Client' }}!</h1>
                <div class="header-actions">
                    <Link href="/client/new-request" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Submit a New Service Request
                    </Link>
                </div>
            </header>

            <section class="panel-section">
                <div class="card-header">
                    <h3>Active Projects Status</h3>
                </div>
                <div class="kpi-grid">
                    <div v-if="activeRequests.length > 0">
                        <div v-for="request in activeRequests" :key="request.id" class="kpi-card">
                            <h4>{{ request.service_category?.name || 'Service Request' }}</h4>
                            <p class="kpi-value">
                                <span :class="['status', getStatusClass(request.status)]">
                                    {{ formatStatus(request.status) }}
                                </span>
                            </p>
                            <span class="kpi-subtext">
                                Technician: {{ request.technician?.user?.name || 'Not assigned' }}
                            </span>
                        </div>
                    </div>
                    <div v-else class="kpi-card">
                        <h4>No Active Projects</h4>
                        <p class="kpi-value">
                            <span class="status review">Ready to Start</span>
                        </p>
                        <span class="kpi-subtext">Submit a new service request to begin</span>
                    </div>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Recent Quotation Requests (RFQ)</h3>
                        <Link href="/client/new-request">Submit New Request</Link>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Date Submitted</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="serviceRequests.length === 0">
                                <td colspan="4" style="text-align: center; color: var(--medium-grey);">
                                    No service requests yet. Submit your first request to get started.
                                </td>
                            </tr>
                            <tr v-for="request in serviceRequests" :key="request.id">
                                <td>{{ request.service_category?.name || 'Service Request' }}</td>
                                <td>{{ formatDate(request.created_at) }}</td>
                                <td>
                                    <span :class="['status', getStatusClass(request.status)]">
                                        {{ formatStatus(request.status) }}
                                    </span>
                                </td>
                                <td>
                                    <Link
                                        :href="`/client/request-status/${request.id}`"
                                        class="btn btn-secondary btn-sm"
                                    >
                                        View Details
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="card-footer">
                        <p>After a job is completed, you will be prompted to provide feedback to help us maintain quality standards.</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import ClientSidebar from '../../Components/ClientSidebar.vue'

const props = defineProps({
    serviceRequests: {
        type: Array,
        default: () => []
    }
})

const activeRequests = computed(() => {
    return props.serviceRequests.filter(request =>
        !['completed', 'cancelled'].includes(request.status)
    )
})

const completedRequests = computed(() => {
    return props.serviceRequests.filter(request =>
        request.status === 'completed'
    )
})

const formatStatus = (status) => {
    return status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const getStatusClass = (status) => {
    switch (status) {
        case 'pending':
        case 'in_progress':
            return 'review'
        case 'assigned':
        case 'scheduled':
            return 'approved'
        case 'completed':
            return 'completed'
        default:
            return 'review'
    }
}

const formatDate = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleDateString()
}

const provideFeedback = (requestId) => {
    // Handle feedback modal or redirect
    console.log('Provide feedback for request:', requestId)
}

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../../css/dashboard-app.css');
</style>
