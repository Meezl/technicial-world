<template>
    <div class="dashboard-container">
        <ClientSidebar current-page="tickets" />

        <main class="main-content">
            <header class="main-header">
                <h1>My Tickets</h1>
                <Link href="/open-ticket" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Open New Ticket
                </Link>
            </header>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Urgency</th>
                                    <th>Status</th>
                                    <th>Filed</th>
                                    <th style="width:80px;">View</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="t in tickets.data" :key="t.id">
                                    <td><code>{{ t.ticket_ref }}</code></td>
                                    <td>{{ t.subject }}</td>
                                    <td>{{ titleCase(t.category) }}</td>
                                    <td>{{ urgencyLabel(t.urgency) }}</td>
                                    <td>
                                        <span :class="['status-badge', statusClass(t.status)]">
                                            {{ titleCase(t.status.replace('_', ' ')) }}
                                        </span>
                                    </td>
                                    <td>{{ formatDate(t.created_at) }}</td>
                                    <td>
                                        <Link :href="`/client/tickets/${t.id}`" class="btn-icon" title="View">
                                            <i class="fas fa-eye"></i>
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!tickets.data.length">
                                    <td colspan="7" class="text-center">
                                        You haven't opened any tickets yet.
                                        <Link href="/open-ticket">Open one →</Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import ClientSidebar from '../../../Components/ClientSidebar.vue'

defineProps({ tickets: { type: Object, required: true } })

const titleCase = (s) => s ? s.replace(/\b\w/g, c => c.toUpperCase()) : ''
const urgencyLabel = (u) => ({ emergency: '🚨 Emergency', urgent: '⚠ Urgent', normal: 'Normal' }[u] || u)
const statusClass = (s) => ({
    open: 'status-pending',
    in_progress: 'status-info',
    resolved: 'status-completed',
    closed: 'status-failed',
}[s] || 'status-pending')
const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
</script>

<style scoped>
.btn-icon { background: none; border: none; cursor: pointer; padding: 0.3rem 0.5rem; color: #6b7280; text-decoration: none; }
.btn-icon:hover { color: #2563eb; }
</style>
