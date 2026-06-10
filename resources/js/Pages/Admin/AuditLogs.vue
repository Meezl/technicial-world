<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="audit-logs" />

        <main class="main-content audit-page">
            <section class="audit-hero">
                <div class="hero-copy">
                    <span class="hero-kicker">Audit Logs</span>
                    <h1>Review operational history without digging through noise</h1>
                    <p>Track system activity, user actions, and entity changes from one cleaner audit workspace.</p>

                    <div class="hero-pills">
                        <span class="hero-pill">
                            <i class="fas fa-clipboard-list"></i>
                            {{ logs.total || 0 }} total events
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-calendar-day"></i>
                            {{ todayCount }} today on this page
                        </span>
                        <span class="hero-pill muted">
                            <i class="fas fa-search"></i>
                            {{ displayedLogs.length }} in current view
                        </span>
                    </div>
                </div>

                <div class="hero-action-card">
                    <div class="hero-action-copy">
                        <span class="section-kicker">Traceability</span>
                        <h3>{{ displayedLogs.length }} event{{ displayedLogs.length === 1 ? '' : 's' }} visible</h3>
                        <p>{{ heroSummary }}</p>
                    </div>

                    <div class="hero-action-grid">
                        <div class="hero-action-tile">
                            <span>Action filter</span>
                            <strong>{{ selectedAction ? formatAction(selectedAction) : 'All actions' }}</strong>
                        </div>
                        <div class="hero-action-tile">
                            <span>Page range</span>
                            <strong>{{ logs.from || 0 }}-{{ logs.to || 0 }}</strong>
                        </div>
                    </div>

                    <div class="hero-action-note">
                        <span>Server pagination</span>
                        <strong>{{ logs.total || 0 }} total records</strong>
                    </div>
                </div>
            </section>

            <section class="stats-grid">
                <article class="stat-card tone-blue">
                    <div class="stat-topline">
                        <span class="stat-tag">Volume</span>
                        <span class="stat-icon"><i class="fas fa-clipboard-list"></i></span>
                    </div>
                    <h4>Total Events</h4>
                    <p class="stat-value">{{ logs.total || 0 }}</p>
                    <span class="stat-footnote">All audit records available through pagination.</span>
                </article>

                <article class="stat-card tone-green">
                    <div class="stat-topline">
                        <span class="stat-tag">Today</span>
                        <span class="stat-icon"><i class="fas fa-calendar-day"></i></span>
                    </div>
                    <h4>Today's Events</h4>
                    <p class="stat-value">{{ todayCount }}</p>
                    <span class="stat-footnote">Entries recorded today on the current server page.</span>
                </article>

                <article class="stat-card tone-amber">
                    <div class="stat-topline">
                        <span class="stat-tag">System</span>
                        <span class="stat-icon"><i class="fas fa-server"></i></span>
                    </div>
                    <h4>System Events</h4>
                    <p class="stat-value">{{ systemCount }}</p>
                    <span class="stat-footnote">Entries without a named user attached.</span>
                </article>

                <article class="stat-card tone-slate">
                    <div class="stat-topline">
                        <span class="stat-tag">Changes</span>
                        <span class="stat-icon"><i class="fas fa-code-branch"></i></span>
                    </div>
                    <h4>With Diffs</h4>
                    <p class="stat-value">{{ detailCount }}</p>
                    <span class="stat-footnote">Events that include old or new value snapshots.</span>
                </article>
            </section>

            <section class="filter-section">
                <div class="panel-card filter-shell">
                    <div class="filter-header">
                        <div>
                            <span class="section-kicker">Filters</span>
                            <h3>Find the right event faster</h3>
                            <p>Filter by action on the server, then use local search to narrow the current page by user, entity, IP, or action.</p>
                        </div>
                    </div>

                    <div class="filter-grid">
                        <label class="filter-field">
                            <span>Action</span>
                            <select v-model="selectedAction" @change="applyFilters" class="filter-input">
                                <option value="">All Actions</option>
                                <option v-for="action in actionTypes" :key="action" :value="action">
                                    {{ formatAction(action) }}
                                </option>
                            </select>
                        </label>

                        <label class="filter-field filter-field-wide">
                            <span>Search This Page</span>
                            <div class="search-shell">
                                <i class="fas fa-search"></i>
                                <input
                                    v-model="searchQuery"
                                    type="text"
                                    placeholder="Search by user, email, entity, IP, action, or ID..."
                                >
                            </div>
                        </label>
                    </div>

                    <div v-if="activeFilterChips.length" class="filter-chip-row">
                        <span v-for="chip in activeFilterChips" :key="chip" class="filter-chip">{{ chip }}</span>
                        <button @click="clearFilters" class="clear-chip-btn">Clear filters</button>
                    </div>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card logs-shell">
                    <div class="logs-header">
                        <div>
                            <span class="section-kicker">Event Log</span>
                            <h3>Audit trail</h3>
                            <p>{{ pageSummary }}</p>
                        </div>
                        <div class="header-summary-chips">
                            <span class="summary-chip">Visible: {{ displayedLogs.length }}</span>
                            <span class="summary-chip muted">With details: {{ detailCount }}</span>
                        </div>
                    </div>

                    <div v-if="displayedLogs.length" class="logs-table desktop-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Entity</th>
                                    <th>IP Address</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="log in displayedLogs" :key="log.id">
                                    <td>
                                        <div class="log-cell">
                                            <strong>{{ formatDateTime(log.created_at) }}</strong>
                                            <span class="cell-subtext">{{ getRelativeTime(log.created_at) }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">{{ getInitials(log.user?.name) }}</div>
                                            <div class="user-details">
                                                <strong>{{ log.user?.name || 'System' }}</strong>
                                                <span class="cell-subtext">{{ log.user?.email || 'Automated event' }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span :class="['status-badge', getActionClass(log.action)]">
                                            {{ formatAction(log.action) }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="log-cell">
                                            <strong>{{ formatEntityType(log.auditable_type) || 'Unknown entity' }}</strong>
                                            <span class="cell-subtext">#{{ log.auditable_id || 'N/A' }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <code class="ip-address">{{ log.ip_address || 'N/A' }}</code>
                                    </td>
                                    <td>
                                        <button
                                            v-if="hasDetails(log)"
                                            @click="viewDetails(log)"
                                            class="btn btn-sm btn-info"
                                        >
                                            <i class="fas fa-eye"></i>
                                            View
                                        </button>
                                        <span v-else class="text-muted">No details</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="displayedLogs.length" class="mobile-log-list">
                        <article v-for="log in displayedLogs" :key="log.id" class="mobile-log-card">
                            <div class="mobile-log-top">
                                <div>
                                    <h4>{{ formatAction(log.action) }}</h4>
                                    <p>{{ formatDateTime(log.created_at) }}</p>
                                </div>
                                <span :class="['status-badge', getActionClass(log.action)]">
                                    {{ formatEntityType(log.auditable_type) || 'Entity' }}
                                </span>
                            </div>

                            <div class="mobile-log-grid">
                                <div>
                                    <span>User</span>
                                    <strong>{{ log.user?.name || 'System' }}</strong>
                                </div>
                                <div>
                                    <span>Entity ID</span>
                                    <strong>#{{ log.auditable_id || 'N/A' }}</strong>
                                </div>
                                <div>
                                    <span>IP</span>
                                    <strong>{{ log.ip_address || 'N/A' }}</strong>
                                </div>
                                <div>
                                    <span>When</span>
                                    <strong>{{ getRelativeTime(log.created_at) }}</strong>
                                </div>
                            </div>

                            <button
                                v-if="hasDetails(log)"
                                @click="viewDetails(log)"
                                class="btn btn-sm btn-info mobile-view-btn"
                            >
                                <i class="fas fa-eye"></i>
                                View Details
                            </button>
                        </article>
                    </div>

                    <div v-if="!displayedLogs.length" class="no-data">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No audit logs found</p>
                        <span>Try clearing one or more filters to widen the current view.</span>
                    </div>

                    <div class="pagination-shell" v-if="logs.links && logs.links.length > 3">
                        <div class="pagination-info">
                            Showing {{ logs.from || 0 }} to {{ logs.to || 0 }} of {{ logs.total || 0 }} results
                        </div>
                        <div class="pagination">
                            <component
                                :is="link.url ? Link : 'span'"
                                v-for="(link, index) in logs.links"
                                :key="`${link.label}-${index}`"
                                :href="link.url || undefined"
                                v-html="link.label"
                                class="page-link"
                                :class="{ active: link.active, disabled: !link.url }"
                                preserve-scroll
                            />
                        </div>
                    </div>
                </div>
            </section>

            <div v-if="showDetailModal" class="modal-overlay">
                <div class="modal-content" @click.stop>
                    <div class="modal-header">
                        <div>
                            <span class="section-kicker">Audit Detail</span>
                            <h3>{{ formatAction(selectedLog?.action) }}</h3>
                        </div>
                        <button @click="showDetailModal = false" class="close-btn">&times;</button>
                    </div>

                    <div class="modal-body" v-if="selectedLog">
                        <div class="modal-meta-grid">
                            <div class="modal-meta-card">
                                <span>Timestamp</span>
                                <strong>{{ formatDateTime(selectedLog.created_at) }}</strong>
                            </div>
                            <div class="modal-meta-card">
                                <span>User</span>
                                <strong>{{ selectedLog.user?.name || 'System' }}</strong>
                            </div>
                            <div class="modal-meta-card">
                                <span>Entity</span>
                                <strong>{{ formatEntityType(selectedLog.auditable_type) || 'Unknown' }} #{{ selectedLog.auditable_id || 'N/A' }}</strong>
                            </div>
                            <div class="modal-meta-card">
                                <span>IP Address</span>
                                <strong>{{ selectedLog.ip_address || 'N/A' }}</strong>
                            </div>
                        </div>

                        <div class="detail-grid">
                            <div class="detail-section" v-if="selectedLog.old_values">
                                <h4>Previous Values</h4>
                                <pre class="json-display">{{ JSON.stringify(selectedLog.old_values, null, 2) }}</pre>
                            </div>
                            <div class="detail-section" v-if="selectedLog.new_values">
                                <h4>New Values</h4>
                                <pre class="json-display">{{ JSON.stringify(selectedLog.new_values, null, 2) }}</pre>
                            </div>
                        </div>

                        <div class="detail-section" v-if="selectedLog.user_agent">
                            <h4>User Agent</h4>
                            <p class="user-agent-text">{{ selectedLog.user_agent }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'

const props = defineProps({
    logs: { type: Object, default: () => ({ data: [], links: [], total: 0, from: 0, to: 0 }) },
    filters: { type: Object, default: () => ({}) },
})

const selectedAction = ref(props.filters.action || '')
const searchQuery = ref('')
const showDetailModal = ref(false)
const selectedLog = ref(null)

const actionTypes = [
    'created', 'updated', 'deleted', 'state_changed',
    'login', 'logout', 'payment', 'approval',
    'assignment', 'reassignment', 'suspension'
]

const displayedLogs = computed(() => {
    const entries = props.logs.data || []
    const query = searchQuery.value.trim().toLowerCase()

    if (!query) return entries

    return entries.filter((log) => {
        const haystack = [
            log.user?.name,
            log.user?.email,
            log.action,
            formatEntityType(log.auditable_type),
            log.auditable_id,
            log.ip_address,
        ].filter(Boolean).join(' ').toLowerCase()

        return haystack.includes(query)
    })
})

const todayCount = computed(() => {
    const today = new Date().toDateString()
    return (props.logs.data || []).filter(log => new Date(log.created_at).toDateString() === today).length
})

const systemCount = computed(() => (props.logs.data || []).filter(log => !log.user).length)
const detailCount = computed(() => (props.logs.data || []).filter(log => hasDetails(log)).length)

const activeFilterChips = computed(() => {
    const chips = []
    if (selectedAction.value) chips.push(`Action: ${formatAction(selectedAction.value)}`)
    if (searchQuery.value) chips.push(`Search: ${searchQuery.value}`)
    return chips
})

const heroSummary = computed(() => {
    if (!displayedLogs.value.length) {
        return 'No events match the current filter combination on this page.'
    }

    if (systemCount.value > 0) {
        return `${systemCount.value} system-generated event${systemCount.value === 1 ? '' : 's'} appear in the current page results.`
    }

    return 'This view is currently dominated by user-triggered operational events.'
})

const pageSummary = computed(() => {
    if (!displayedLogs.value.length) {
        return 'No logs match the current view.'
    }

    return `Showing ${displayedLogs.value.length} visible rows from server results ${props.logs.from || 0}-${props.logs.to || 0}.`
})

const applyFilters = () => {
    router.get('/admin/audit-logs', {
        action: selectedAction.value || undefined,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const clearFilters = () => {
    searchQuery.value = ''

    if (selectedAction.value) {
        selectedAction.value = ''
        applyFilters()
    }
}

const formatDateTime = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleString('en-KE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

const getRelativeTime = (date) => {
    if (!date) return 'Unknown time'

    const timestamp = new Date(date).getTime()
    const diffInMinutes = Math.floor((Date.now() - timestamp) / 60000)

    if (diffInMinutes < 1) return 'Just now'
    if (diffInMinutes < 60) return `${diffInMinutes} minute${diffInMinutes === 1 ? '' : 's'} ago`

    const diffInHours = Math.floor(diffInMinutes / 60)
    if (diffInHours < 24) return `${diffInHours} hour${diffInHours === 1 ? '' : 's'} ago`

    const diffInDays = Math.floor(diffInHours / 24)
    if (diffInDays === 1) return '1 day ago'
    return `${diffInDays} days ago`
}

const formatAction = (action) => {
    if (!action) return ''
    return action.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

const formatEntityType = (type) => {
    if (!type) return ''
    return type.split('\\').pop()
}

const getActionClass = (action) => {
    const map = {
        created: 'approved',
        updated: 'review',
        deleted: 'rejected',
        state_changed: 'review',
        login: 'approved',
        logout: 'pending',
        payment: 'approved',
        approval: 'approved',
        assignment: 'review',
        reassignment: 'review',
        suspension: 'rejected',
    }
    return map[action] || 'pending'
}

const getInitials = (name) => {
    if (!name) return 'SY'
    return name.split(' ').map(part => part[0]).join('').toUpperCase().slice(0, 2)
}

const hasDetails = (log) => Boolean(log?.old_values || log?.new_values)

const viewDetails = (log) => {
    selectedLog.value = log
    showDetailModal.value = true
}

defineOptions({ layout: null })
</script>

<style>

.audit-page {
    background:
        radial-gradient(circle at top right, rgba(14, 165, 233, 0.1), transparent 24rem),
        linear-gradient(180deg, #f8fbfd 0%, #f3f6f8 100%);
}

.audit-hero,
.stats-grid,
.hero-action-grid,
.filter-grid,
.detail-grid,
.modal-meta-grid,
.mobile-log-grid {
    display: grid;
    gap: 1rem;
}

.audit-hero {
    grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.hero-copy,
.hero-action-card,
.stat-card,
.filter-shell,
.logs-shell,
.mobile-log-card {
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 45px rgba(15, 23, 42, 0.05);
}

.hero-copy {
    padding: 2rem;
    border-radius: 26px;
    background:
        linear-gradient(135deg, rgba(0, 51, 75, 0.97), rgba(7, 89, 133, 0.92)),
        #00334b;
    color: #f8fafc;
}

.hero-kicker,
.section-kicker {
    display: inline-flex;
    align-items: center;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.16em;
}

.hero-kicker {
    color: rgba(226, 232, 240, 0.82);
}

.section-kicker {
    color: #0f6c8f;
}

.hero-copy h1 {
    margin: 0.85rem 0 0;
    color: #ffffff;
    font-size: clamp(2rem, 3vw, 2.5rem);
}

.hero-copy p {
    margin: 0.9rem 0 0;
    max-width: 42rem;
    color: rgba(226, 232, 240, 0.88);
    line-height: 1.6;
}

.hero-pills,
.filter-chip-row,
.header-summary-chips,
.pagination-shell,
.pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
}

.hero-pills {
    margin-top: 1.5rem;
}

.hero-pill,
.summary-chip,
.filter-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 0.9rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
}

.hero-pill {
    background: rgba(255, 255, 255, 0.12);
    color: #f8fafc;
}

.hero-pill.muted {
    background: rgba(15, 23, 42, 0.24);
}

.hero-action-card,
.filter-shell,
.logs-shell {
    padding: 1.4rem;
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.94);
}

.hero-action-copy h3,
.filter-header h3,
.logs-header h3,
.modal-header h3 {
    margin: 0.35rem 0 0;
    color: #0f172a;
}

.hero-action-copy p,
.filter-header p,
.logs-header p {
    margin: 0.45rem 0 0;
    color: #64748b;
    line-height: 1.55;
}

.hero-action-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin: 1rem 0 1.1rem;
}

.hero-action-tile,
.hero-action-note,
.modal-meta-card,
.mobile-log-grid > div {
    padding: 1rem;
    border-radius: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
}

.hero-action-tile span,
.hero-action-note span,
.filter-field span,
.cell-subtext,
.pagination-info,
.modal-meta-card span,
.mobile-log-grid span,
.no-data span {
    color: #64748b;
    font-size: 0.8rem;
}

.hero-action-tile strong,
.hero-action-note strong,
.modal-meta-card strong,
.mobile-log-grid strong {
    display: block;
    margin-top: 0.35rem;
    color: #0f172a;
}

.stats-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 1.5rem;
}

.stat-card {
    padding: 1.35rem;
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.94);
}

.tone-blue {
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.98), #ffffff);
}

.tone-green {
    background: linear-gradient(180deg, rgba(240, 253, 244, 0.98), #ffffff);
}

.tone-amber {
    background: linear-gradient(180deg, rgba(255, 251, 235, 0.99), #ffffff);
}

.tone-slate {
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.99), #ffffff);
}

.stat-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.9rem;
}

.stat-tag {
    display: inline-flex;
    padding: 0.35rem 0.65rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.8);
    color: #475569;
    font-size: 0.74rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.stat-icon {
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
}

.tone-green .stat-icon {
    background: linear-gradient(135deg, #0f766e, #22c55e);
}

.tone-amber .stat-icon {
    background: linear-gradient(135deg, #c2410c, #f59e0b);
}

.tone-slate .stat-icon {
    background: linear-gradient(135deg, #475569, #94a3b8);
}

.stat-card h4 {
    margin: 0;
    color: #475569;
    font-size: 0.92rem;
}

.stat-value {
    margin: 0.45rem 0 0;
    font-size: 1.8rem;
    font-weight: 800;
    color: #0f172a;
}

.stat-footnote {
    display: block;
    margin-top: 0.55rem;
    color: #64748b;
    font-size: 0.84rem;
    line-height: 1.45;
}

.filter-section {
    margin-bottom: 1.5rem;
}

.filter-grid {
    grid-template-columns: 0.9fr 1.5fr;
    margin-top: 1rem;
}

.filter-field {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    color: #334155;
    font-weight: 700;
}

.filter-field-wide {
    min-width: 0;
}

.filter-input,
.search-shell {
    width: 100%;
    box-sizing: border-box;
    padding: 0.82rem 0.95rem;
    border: 1px solid #d7dee7;
    border-radius: 14px;
    background: #f8fafc;
    color: #0f172a;
    font: inherit;
}

.search-shell {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
}

.search-shell input {
    width: 100%;
    border: none;
    background: transparent;
    outline: none;
    font: inherit;
    color: #0f172a;
}

.filter-input:focus,
.search-shell:focus-within {
    outline: none;
    border-color: rgba(14, 116, 144, 0.45);
    box-shadow: 0 0 0 4px rgba(14, 116, 144, 0.12);
    background: #ffffff;
}

.filter-chip-row {
    margin-top: 1rem;
}

.filter-chip {
    background: #e0f2fe;
    color: #0f6c8f;
}

.clear-chip-btn {
    border: none;
    background: transparent;
    color: #0f6c8f;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
}

.logs-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.summary-chip {
    background: #eff6ff;
    color: #0f6c8f;
}

.summary-chip.muted {
    background: #fff7ed;
    color: #c2410c;
}

.logs-table {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: #ffffff;
}

.logs-table table {
    width: 100%;
    border-collapse: collapse;
}

.logs-table th,
.logs-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: top;
}

.logs-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #475569;
    font-size: 0.82rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.logs-table tbody tr:hover {
    background: #f8fbfd;
}

.log-cell,
.user-details {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
}

.log-cell strong,
.user-details strong {
    color: #0f172a;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.user-avatar {
    width: 2.7rem;
    height: 2.7rem;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #0f6c8f, #38bdf8);
    color: #ffffff;
    font-size: 0.85rem;
    font-weight: 800;
    flex-shrink: 0;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.38rem 0.72rem;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    white-space: nowrap;
}

.status-badge.approved {
    background: #dcfce7;
    color: #166534;
}

.status-badge.review {
    background: #fef3c7;
    color: #92400e;
}

.status-badge.rejected {
    background: #fee2e2;
    color: #991b1b;
}

.status-badge.pending {
    background: #e0f2fe;
    color: #0f6c8f;
}

.ip-address {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.55rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #334155;
    font-size: 0.82rem;
    font-family: monospace;
}

.text-muted {
    color: #94a3b8;
}

.mobile-log-list {
    display: none;
    gap: 1rem;
}

.mobile-log-card {
    padding: 1rem;
    border-radius: 20px;
    background: #ffffff;
}

.mobile-log-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.mobile-log-top h4 {
    margin: 0;
    color: #0f172a;
}

.mobile-log-top p {
    margin: 0.35rem 0 0;
    color: #64748b;
}

.mobile-log-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-top: 1rem;
}

.mobile-view-btn {
    margin-top: 1rem;
}

.no-data {
    text-align: center;
    padding: 3rem 1rem;
    color: #94a3b8;
    border: 1px dashed #cbd5e1;
    border-radius: 18px;
    background: #f8fafc;
}

.no-data i {
    display: block;
    margin-bottom: 1rem;
    font-size: 3rem;
    opacity: 0.5;
}

.pagination-shell {
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding-top: 1rem;
}

.pagination {
    align-items: center;
}

.page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 2.5rem;
    padding: 0.6rem 0.8rem;
    border-radius: 12px;
    border: 1px solid #d7dee7;
    background: #ffffff;
    color: #334155;
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 700;
}

.page-link.active {
    border-color: #0f6c8f;
    background: #0f6c8f;
    color: #ffffff;
}

.page-link.disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.48);
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 1rem;
}

.modal-content {
    width: min(100%, 920px);
    max-height: calc(100vh - 2rem);
    overflow-y: auto;
    border-radius: 24px;
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.24);
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.2);
}

.modal-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.close-btn {
    border: none;
    background: transparent;
    color: #64748b;
    font-size: 1.6rem;
    cursor: pointer;
}

.modal-body {
    padding: 1.25rem 1.5rem 1.5rem;
}

.modal-meta-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    margin-bottom: 1rem;
}

.detail-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.detail-section {
    margin-bottom: 1rem;
}

.detail-section h4 {
    margin: 0 0 0.65rem;
    color: #0f172a;
}

.json-display {
    margin: 0;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1rem;
    font-size: 0.85rem;
    overflow-x: auto;
    max-height: 320px;
    white-space: pre-wrap;
    word-break: break-word;
    color: #334155;
}

.user-agent-text {
    margin: 0;
    padding: 1rem;
    border-radius: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.6;
    word-break: break-word;
}

@media (max-width: 1180px) {
    .audit-hero,
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 860px) {
    .audit-hero,
    .stats-grid,
    .hero-action-grid,
    .filter-grid,
    .modal-meta-grid,
    .detail-grid,
    .mobile-log-grid {
        grid-template-columns: 1fr;
    }

    .logs-header,
    .mobile-log-top,
    .pagination-shell,
    .modal-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .desktop-table {
        display: none;
    }

    .mobile-log-list {
        display: grid;
    }
}
</style>
