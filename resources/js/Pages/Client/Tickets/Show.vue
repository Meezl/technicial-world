<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="tickets" />

        <main class="main-content">
            <header class="main-header">
                <Link href="/client/tickets" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back
                </Link>
                <h1>{{ ticket.ticket_ref }}</h1>
                <span :class="['status-badge', statusClass(ticket.status)]" style="margin-left:auto;">
                    {{ titleCase(ticket.status.replace('_', ' ')) }}
                </span>
            </header>

            <section class="main-panel">
                <div class="panel-card" style="padding:1.25rem;">
                    <h2 style="margin-top:0;">{{ ticket.subject }}</h2>

                    <table class="data-table" style="margin-bottom:1.25rem;">
                        <tbody>
                            <tr><th style="width:140px;">Category</th><td>{{ titleCase(ticket.category) }}</td></tr>
                            <tr><th>Urgency</th><td>{{ urgencyLabel(ticket.urgency) }}</td></tr>
                            <tr v-if="ticket.location"><th>Location</th><td>{{ ticket.location }}</td></tr>
                            <tr><th>Filed</th><td>{{ formatDate(ticket.created_at) }}</td></tr>
                            <tr v-if="ticket.resolved_at"><th>Resolved</th><td>{{ formatDate(ticket.resolved_at) }}</td></tr>
                            <tr v-if="ticket.closed_at"><th>Closed</th><td>{{ formatDate(ticket.closed_at) }}</td></tr>
                        </tbody>
                    </table>

                    <h3>Description</h3>
                    <div class="info-box">{{ ticket.description }}</div>

                    <div v-if="ticket.resolution_summary" style="margin-top:1.25rem;">
                        <h3>Resolution</h3>
                        <div class="info-box info-box-success">{{ ticket.resolution_summary }}</div>
                    </div>

                    <h3 style="margin-top:1.5rem;">Status Timeline</h3>
                    <div class="timeline-list">
                        <div v-for="log in ticket.status_logs" :key="log.id" class="timeline-row">
                            <div class="timeline-dot" :class="statusClass(log.to_status)"></div>
                            <div class="timeline-body">
                                <strong>
                                    {{ log.from_status ? titleCase(log.from_status.replace('_', ' ')) + ' → ' : '' }}
                                    {{ titleCase(log.to_status.replace('_', ' ')) }}
                                </strong>
                                <div class="timeline-meta">{{ formatDate(log.created_at) }} · {{ log.changed_by?.name || 'System' }}</div>
                                <p v-if="log.note" class="timeline-note">{{ log.note }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import ClientSidebar from '../../../Components/ClientSidebar.vue'

defineProps({ ticket: { type: Object, required: true } })

const titleCase = (s) => s ? s.replace(/\b\w/g, c => c.toUpperCase()) : ''
const urgencyLabel = (u) => ({ emergency: '🚨 Emergency', urgent: '⚠ Urgent', normal: 'Normal' }[u] || u)
const statusClass = (s) => ({
    open: 'status-pending',
    in_progress: 'status-info',
    resolved: 'status-completed',
    closed: 'status-failed',
}[s] || 'status-pending')
const formatDate = (d) => d ? new Date(d).toLocaleString('en-KE') : '—'
</script>

<style scoped>
.info-box { background: #f9fafb; border-left: 3px solid #2563eb; padding: 0.9rem 1.1rem; border-radius: 6px; white-space: pre-wrap; }
.info-box-success { border-left-color: #059669; background: #ecfdf5; }
.timeline-list { display: flex; flex-direction: column; gap: 0.85rem; margin-top: 0.5rem; }
.timeline-row { display: grid; grid-template-columns: 24px 1fr; gap: 0.75rem; align-items: flex-start; padding: 0.6rem; background: #fafafa; border-radius: 6px; }
.timeline-dot { width: 14px; height: 14px; border-radius: 50%; margin-top: 4px; background: #9ca3af; }
.timeline-dot.status-pending { background: #f59e0b; }
.timeline-dot.status-info { background: #2563eb; }
.timeline-dot.status-completed { background: #059669; }
.timeline-dot.status-failed { background: #6b7280; }
.timeline-meta { color: #6b7280; font-size: 0.85rem; }
.timeline-note { margin: 0.5rem 0 0; padding: 0.5rem 0.7rem; background: white; border: 1px solid #e5e7eb; border-radius: 4px; white-space: pre-wrap; }
</style>
