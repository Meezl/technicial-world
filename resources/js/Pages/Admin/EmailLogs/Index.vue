<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="email-logs" />

        <main class="main-content">
            <header class="main-header">
                <h1>Email Logs</h1>
            </header>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="filter-bar" style="padding:.75rem 1rem;border-bottom:1px solid #e5e7eb;background:#f9fafb;display:flex;gap:.5rem;flex-wrap:wrap;">
                        <input
                            v-model="searchTerm"
                            @keyup.enter="apply"
                            type="text"
                            placeholder="Search subject, recipient, mailable…"
                            class="form-control"
                            style="flex:1;min-width:240px;"
                        />
                        <input v-model="fromDate" type="date" class="form-control" />
                        <input v-model="toDate" type="date" class="form-control" />
                        <button @click="apply" class="btn btn-primary btn-sm">Filter</button>
                        <button v-if="searchTerm || fromDate || toDate" @click="clear" class="btn btn-secondary btn-sm">Clear</button>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Sent</th>
                                    <th>Subject</th>
                                    <th>Recipient</th>
                                    <th>Mailable</th>
                                    <th>Status</th>
                                    <th style="width:80px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="email in emails.data" :key="email.id">
                                    <td>
                                        <strong>{{ formatDate(email.sent_at) }}</strong>
                                        <small style="display:block;color:#6b7280;">{{ formatTime(email.sent_at) }}</small>
                                    </td>
                                    <td>{{ email.subject }}</td>
                                    <td>
                                        <span v-for="(addr, i) in email.to" :key="i" style="display:block;font-size:.85rem;">
                                            {{ addr.name || addr.address }}
                                            <small v-if="addr.name" style="color:#6b7280;">&lt;{{ addr.address }}&gt;</small>
                                        </span>
                                    </td>
                                    <td>
                                        <code style="font-size:.75rem;color:#6b7280;">{{ shortClass(email.mailable_class) }}</code>
                                    </td>
                                    <td>
                                        <span :class="['status-badge', email.status === 'sent' ? 'status-completed' : 'status-failed']">
                                            {{ email.status }}
                                        </span>
                                    </td>
                                    <td>
                                        <Link :href="`/admin/email-logs/${email.id}`" class="btn-icon" title="View">
                                            <i class="fas fa-eye"></i>
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!emails.data.length">
                                    <td colspan="6" class="text-center">No emails recorded yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-container" v-if="emails.links?.length > 3">
                        <div class="pagination">
                            <Component
                                :is="link.url ? Link : 'span'"
                                v-for="(link, idx) in emails.links"
                                :key="idx"
                                :href="link.url"
                                v-html="link.label"
                                :class="['page-link', { active: link.active, disabled: !link.url }]"
                                preserve-scroll
                            />
                        </div>
                        <div class="pagination-info">
                            Showing {{ emails.from?.toLocaleString() }}–{{ emails.to?.toLocaleString() }} of {{ emails.total?.toLocaleString() }}
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    emails: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
})

const searchTerm = ref(props.filters?.search || '')
const fromDate = ref(props.filters?.from || '')
const toDate = ref(props.filters?.to || '')

const apply = () => router.get('/admin/email-logs', {
    search: searchTerm.value || undefined,
    from: fromDate.value || undefined,
    to: toDate.value || undefined,
}, { preserveState: true, replace: true })

const clear = () => {
    searchTerm.value = ''
    fromDate.value = ''
    toDate.value = ''
    router.get('/admin/email-logs', {}, { preserveState: true, replace: true })
}

const formatDate = (s) => s ? new Date(s).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const formatTime = (s) => s ? new Date(s).toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit' }) : ''
const shortClass = (c) => (c || '—').split('\\').pop()
</script>

<style scoped>
.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    padding: 1rem;
    border-top: 1px solid var(--border-color, #e5e7eb);
}

.pagination {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
    align-items: center;
}

.page-link {
    min-width: 2.25rem;
    height: 2.25rem;
    padding: 0 0.75rem;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 6px;
    background: #fff;
    color: var(--text-color, #374151);
    text-decoration: none;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    white-space: nowrap;
}

.page-link:hover:not(.disabled):not(.active) {
    background: #f8f9fa;
    border-color: var(--primary-color, #2563eb);
    color: var(--primary-color, #2563eb);
}

.page-link.active {
    background: var(--primary-color, #2563eb);
    border-color: var(--primary-color, #2563eb);
    color: #fff;
    font-weight: 600;
}

.page-link.disabled {
    color: var(--text-muted, #9ca3af);
    background: #f8f9fa;
    cursor: default;
    opacity: 0.6;
}

/* The "..." separator shouldn't look like a button */
.page-link.disabled:not(:first-child):not(:last-child) {
    border-color: transparent;
    background: transparent;
    min-width: auto;
    padding: 0 0.25rem;
}

.pagination-info {
    color: var(--text-muted, #6b7280);
    font-size: 0.875rem;
}
</style>
