<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="tickets" />

        <main class="main-content">
            <header class="main-header">
                <Link href="/admin/tickets" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back
                </Link>
                <h1>{{ ticket.ticket_ref }}</h1>
                <span :class="['status-badge', statusClass(ticket.status)]" style="margin-left:auto;">
                    {{ titleCase(ticket.status.replace('_', ' ')) }}
                </span>
                <span :class="['urgency-badge', `urg-${ticket.urgency}`]">{{ urgencyLabel(ticket.urgency) }}</span>
            </header>

            <div v-if="success" class="alert alert-success" style="margin:1rem;">{{ success }}</div>
            <div v-if="error" class="alert alert-danger" style="margin:1rem;">{{ error }}</div>

            <section class="main-panel" style="display:grid;grid-template-columns:1.7fr 1fr;gap:1rem;padding:0 1rem 1rem;">
                <!-- Main content -->
                <div class="panel-card" style="padding:1.25rem;">
                    <h2 style="margin-top:0;">{{ ticket.subject }}</h2>

                    <table class="data-table" style="margin-bottom:1.25rem;">
                        <tbody>
                            <tr><th style="width:140px;">Filer</th><td>{{ ticket.filer_name }}<br><small>{{ ticket.filer_email }}</small></td></tr>
                            <tr v-if="ticket.filer_phone"><th>Phone</th><td><a :href="`tel:${ticket.filer_phone}`">{{ ticket.filer_phone }}</a></td></tr>
                            <tr><th>Account</th><td>{{ ticket.user_id ? `Registered (#${ticket.user_id})` : 'Guest' }}</td></tr>
                            <tr><th>Category</th><td>{{ titleCase(ticket.category) }}</td></tr>
                            <tr v-if="ticket.location"><th>Location</th><td>{{ ticket.location }}</td></tr>
                            <tr><th>Filed</th><td>{{ formatFullDate(ticket.created_at) }}</td></tr>
                        </tbody>
                    </table>

                    <h3>Description</h3>
                    <div class="info-box">{{ ticket.description }}</div>

                    <div v-if="ticket.resolution_summary" style="margin-top:1rem;">
                        <h3>Resolution Summary</h3>
                        <div class="info-box info-box-success">{{ ticket.resolution_summary }}</div>
                    </div>

                    <h3 style="margin-top:1.5rem;">Status Timeline</h3>
                    <div class="timeline-list">
                        <div v-for="log in ticket.status_logs" :key="log.id" class="timeline-row">
                            <div class="timeline-dot" :class="statusClass(log.to_status)"></div>
                            <div class="timeline-body">
                                <strong>
                                    {{ log.from_status ? titleCase(log.from_status.replace('_', ' ')) + ' → ' : 'Filed: ' }}
                                    {{ titleCase(log.to_status.replace('_', ' ')) }}
                                </strong>
                                <div class="timeline-meta">{{ formatFullDate(log.created_at) }} · {{ log.changed_by?.name || 'System' }}</div>
                                <p v-if="log.note" class="timeline-note">{{ log.note }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions sidebar -->
                <div class="panel-card" style="padding:1.25rem;height:fit-content;position:sticky;top:1rem;">
                    <h3 style="margin-top:0;">Actions</h3>

                    <div v-if="availableTransitions.length === 0" style="color:#6b7280;font-size:.9rem;">
                        No further status changes possible on a closed ticket.
                    </div>

                    <div v-for="action in availableTransitions" :key="action.to" class="action-block">
                        <button
                            @click="openTransition(action)"
                            :class="['btn', 'action-btn', action.class]"
                        >
                            <i :class="action.icon"></i> {{ action.label }}
                        </button>
                    </div>

                    <hr style="margin:1rem 0;border:0;border-top:1px solid #e5e7eb;">
                    <p style="font-size:.85rem;color:#6b7280;">
                        Communication with the filer happens by email/phone outside the system.
                        Use these buttons to record the status as you go so the filer gets a notification.
                    </p>
                </div>
            </section>

            <!-- Transition modal -->
            <div v-if="modalOpen" class="modal-overlay" @click.self="modalOpen = false">
                <div class="modal-content" style="max-width:520px;">
                    <div class="modal-header">
                        <h3>{{ currentAction?.label }}</h3>
                        <button @click="modalOpen = false" class="close-btn">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Note for the filer <small style="color:#6b7280;">(optional)</small></label>
                            <textarea v-model="form.note" rows="4" class="form-control" placeholder="What should we tell the client about this update?"></textarea>
                        </div>
                        <div v-if="currentAction?.to === 'resolved'" class="form-group">
                            <label>Resolution summary <span style="color:#dc2626;">*</span></label>
                            <textarea v-model="form.resolution_summary" rows="4" class="form-control" placeholder="What was done to resolve this ticket?" required></textarea>
                            <span v-if="errors.resolution_summary" class="field-error">{{ errors.resolution_summary }}</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button @click="modalOpen = false" class="btn btn-secondary">Cancel</button>
                        <button @click="submit" :disabled="submitting" :class="['btn', currentAction?.class]">
                            {{ submitting ? 'Saving…' : 'Confirm' }}
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { ref, computed, reactive } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({ ticket: { type: Object, required: true } })
const page = usePage()
const success = computed(() => page.props.flash?.success)
const error = computed(() => page.props.flash?.error)

const transitionMap = {
    open: [
        { to: 'in_progress', label: 'Mark In Progress', icon: 'fas fa-play', class: 'btn-primary' },
        { to: 'closed',      label: 'Close (no action)', icon: 'fas fa-times-circle', class: 'btn-secondary' },
    ],
    in_progress: [
        { to: 'resolved', label: 'Mark Resolved', icon: 'fas fa-check', class: 'btn-success' },
        { to: 'closed',   label: 'Close',         icon: 'fas fa-times-circle', class: 'btn-secondary' },
    ],
    resolved: [
        { to: 'in_progress', label: 'Reopen', icon: 'fas fa-undo', class: 'btn-primary' },
        { to: 'closed',      label: 'Close',  icon: 'fas fa-times-circle', class: 'btn-secondary' },
    ],
    closed: [
        { to: 'in_progress', label: 'Reopen', icon: 'fas fa-undo', class: 'btn-primary' },
    ],
}
const availableTransitions = computed(() => transitionMap[props.ticket.status] || [])

const modalOpen = ref(false)
const currentAction = ref(null)
const submitting = ref(false)
const form = reactive({ note: '', resolution_summary: '' })
const errors = ref({})

const openTransition = (action) => {
    currentAction.value = action
    form.note = ''
    form.resolution_summary = ''
    errors.value = {}
    modalOpen.value = true
}

const submit = () => {
    submitting.value = true
    errors.value = {}
    router.post(`/admin/tickets/${props.ticket.id}/transition`, {
        to_status: currentAction.value.to,
        note: form.note,
        resolution_summary: form.resolution_summary,
    }, {
        preserveScroll: true,
        onSuccess: () => { modalOpen.value = false },
        onError: (errs) => { errors.value = errs },
        onFinish: () => { submitting.value = false },
    })
}

const titleCase = (s) => s ? s.replace(/\b\w/g, c => c.toUpperCase()) : ''
const urgencyLabel = (u) => ({ emergency: '🚨 Emergency', urgent: '⚠ Urgent', normal: 'Normal' }[u] || u)
const statusClass = (s) => ({
    open: 'status-pending',
    in_progress: 'status-info',
    resolved: 'status-completed',
    closed: 'status-failed',
}[s] || 'status-pending')
const formatFullDate = (d) => d ? new Date(d).toLocaleString('en-KE') : '—'
</script>

<style scoped>
.info-box { background: #f9fafb; border-left: 3px solid #2563eb; padding: 0.9rem 1.1rem; border-radius: 6px; white-space: pre-wrap; }
.info-box-success { border-left-color: #059669; background: #ecfdf5; }
.action-block { margin-bottom: 0.5rem; }
.action-btn { display: block; width: 100%; padding: 0.65rem 1rem; }
.timeline-list { display: flex; flex-direction: column; gap: 0.85rem; margin-top: 0.5rem; }
.timeline-row { display: grid; grid-template-columns: 24px 1fr; gap: 0.75rem; align-items: flex-start; padding: 0.6rem; background: #fafafa; border-radius: 6px; }
.timeline-dot { width: 14px; height: 14px; border-radius: 50%; margin-top: 4px; background: #9ca3af; }
.timeline-dot.status-pending { background: #f59e0b; }
.timeline-dot.status-info { background: #2563eb; }
.timeline-dot.status-completed { background: #059669; }
.timeline-dot.status-failed { background: #6b7280; }
.timeline-meta { color: #6b7280; font-size: 0.85rem; }
.timeline-note { margin: 0.5rem 0 0; padding: 0.5rem 0.7rem; background: white; border: 1px solid #e5e7eb; border-radius: 4px; white-space: pre-wrap; }
.urgency-badge { padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin-left: 0.5rem; }
.urg-emergency { background: #fee2e2; color: #991b1b; }
.urg-urgent { background: #fef3c7; color: #92400e; }
.urg-normal { background: #f3f4f6; color: #374151; }
.field-error { color: #dc2626; font-size: 0.8rem; }
</style>
