<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="tickets" />

        <main class="main-content">
            <header class="main-header">
                <h1>Tickets</h1>
                <div v-if="counts.emergency_open > 0" class="emergency-alert">
                    🚨 {{ counts.emergency_open }} open emergency ticket{{ counts.emergency_open > 1 ? 's' : '' }}
                </div>
            </header>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="filter-bar">
                        <div class="filter-chips">
                            <button
                                v-for="opt in statusOptions"
                                :key="opt.value"
                                @click="applyStatus(opt.value)"
                                :class="['filter-chip', { active: activeStatus === opt.value }]"
                            >
                                {{ opt.label }}
                                <span class="chip-count">{{ counts[opt.value === '' ? 'all' : opt.value] ?? 0 }}</span>
                            </button>
                        </div>
                        <div class="filter-extras">
                            <select v-model="urgency" @change="apply" class="form-control">
                                <option value="">All urgencies</option>
                                <option value="emergency">🚨 Emergency</option>
                                <option value="urgent">⚠ Urgent</option>
                                <option value="normal">Normal</option>
                            </select>
                            <select v-model="category" @change="apply" class="form-control">
                                <option value="">All categories</option>
                                <option value="electrical">Electrical</option>
                                <option value="plumbing">Plumbing</option>
                                <option value="other">Other</option>
                            </select>
                            <input
                                v-model="searchTerm"
                                @keyup.enter="apply"
                                type="text"
                                placeholder="Search ref, name, email, subject…"
                                class="form-control"
                            />
                            <button @click="apply" class="btn btn-primary btn-sm">Filter</button>
                            <button v-if="anyFilter" @click="clear" class="btn btn-secondary btn-sm">Clear</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Filer</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Urgency</th>
                                    <th>Status</th>
                                    <th>Filed</th>
                                    <th style="width:80px;">View</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="t in tickets.data" :key="t.id" :class="rowClass(t)">
                                    <td><code>{{ t.ticket_ref }}</code></td>
                                    <td>
                                        <div>{{ t.filer_name }}</div>
                                        <small>{{ t.filer_email }}</small>
                                    </td>
                                    <td>{{ t.subject }}</td>
                                    <td>{{ titleCase(t.category) }}</td>
                                    <td>
                                        <span :class="['urgency-badge', `urg-${t.urgency}`]">
                                            {{ urgencyLabel(t.urgency) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span :class="['status-badge', statusClass(t.status)]">
                                            {{ titleCase(t.status.replace('_', ' ')) }}
                                        </span>
                                    </td>
                                    <td>
                                        {{ formatDate(t.created_at) }}
                                        <small style="display:block;color:#6b7280;">{{ formatTime(t.created_at) }}</small>
                                    </td>
                                    <td>
                                        <Link :href="`/admin/tickets/${t.id}`" class="btn-icon">
                                            <i class="fas fa-eye"></i>
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!tickets.data.length">
                                    <td colspan="8" class="text-center">No tickets matching the filter.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-container" v-if="tickets.links?.length > 3">
                        <div class="pagination">
                            <Component
                                :is="link.url ? Link : 'span'"
                                v-for="(link, idx) in tickets.links"
                                :key="idx"
                                :href="link.url"
                                v-html="link.label"
                                :class="['page-link', { active: link.active, disabled: !link.url }]"
                                preserve-scroll
                            />
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    tickets: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    counts: { type: Object, default: () => ({}) },
})

const statusOptions = [
    { value: '', label: 'All' },
    { value: 'open', label: 'Open' },
    { value: 'in_progress', label: 'In Progress' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
]

const activeStatus = ref(props.filters?.status || '')
const urgency = ref(props.filters?.urgency || '')
const category = ref(props.filters?.category || '')
const searchTerm = ref(props.filters?.search || '')

const anyFilter = computed(() => activeStatus.value || urgency.value || category.value || searchTerm.value)

const applyStatus = (s) => {
    activeStatus.value = s
    apply()
}
const apply = () => router.get('/admin/tickets', {
    status: activeStatus.value || undefined,
    urgency: urgency.value || undefined,
    category: category.value || undefined,
    search: searchTerm.value || undefined,
}, { preserveState: true, replace: true })

const clear = () => {
    activeStatus.value = ''
    urgency.value = ''
    category.value = ''
    searchTerm.value = ''
    router.get('/admin/tickets', {}, { preserveState: true, replace: true })
}

const titleCase = (s) => s ? s.replace(/\b\w/g, c => c.toUpperCase()) : ''
const urgencyLabel = (u) => ({ emergency: '🚨 Emergency', urgent: '⚠ Urgent', normal: 'Normal' }[u] || u)
const statusClass = (s) => ({
    open: 'status-pending',
    in_progress: 'status-info',
    resolved: 'status-completed',
    closed: 'status-failed',
}[s] || 'status-pending')
const rowClass = (t) => t.urgency === 'emergency' && t.status !== 'closed' ? 'row-emergency' : ''
const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short' }) : '—'
const formatTime = (d) => d ? new Date(d).toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit' }) : ''
</script>

<style scoped>
.emergency-alert {
    background: #fee2e2;
    border: 1px solid #fca5a5;
    color: #991b1b;
    padding: 0.5rem 0.9rem;
    border-radius: 999px;
    font-weight: 600;
    font-size: 0.85rem;
}
.filter-bar { padding: 0.85rem 1rem; border-bottom: 1px solid #e5e7eb; background: #f9fafb; display: flex; flex-direction: column; gap: 0.75rem; }
.filter-chips { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.filter-chip { padding: 0.35rem 0.85rem; border: 1px solid #d1d5db; background: white; border-radius: 999px; cursor: pointer; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 0.4rem; }
.filter-chip.active { background: #2563eb; color: white; border-color: #2563eb; }
.chip-count { background: rgba(0,0,0,0.08); padding: 0.1rem 0.4rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
.filter-chip.active .chip-count { background: rgba(255,255,255,0.25); }
.filter-extras { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; }
.filter-extras input, .filter-extras select { min-width: 180px; flex: 1; }
.urgency-badge { padding: 0.2rem 0.55rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
.urg-emergency { background: #fee2e2; color: #991b1b; }
.urg-urgent { background: #fef3c7; color: #92400e; }
.urg-normal { background: #f3f4f6; color: #374151; }
.row-emergency { background: #fef2f2; }
.btn-icon { background: none; border: none; cursor: pointer; padding: 0.3rem 0.5rem; color: #6b7280; text-decoration: none; }
.btn-icon:hover { color: #2563eb; }
</style>
