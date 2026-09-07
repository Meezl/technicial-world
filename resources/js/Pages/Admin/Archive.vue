<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="archive" />

        <main class="main-content">
            <header class="page-head">
                <div>
                    <span class="page-kicker">Archive</span>
                    <h1>Finished work</h1>
                    <p>
                        Completed and cancelled requests, out of RFQ Management but not out of reach.
                        Nothing here is deleted — every payment, report and variation stays attached.
                    </p>
                </div>
                <div class="archive-stats">
                    <div class="archive-stat">
                        <span class="archive-stat-value">{{ stats.total }}</span>
                        <span class="archive-stat-label">Archived</span>
                    </div>
                    <div class="archive-stat">
                        <span class="archive-stat-value">{{ stats.completed }}</span>
                        <span class="archive-stat-label">Completed</span>
                    </div>
                    <div class="archive-stat">
                        <span class="archive-stat-value">{{ stats.cancelled }}</span>
                        <span class="archive-stat-label">Cancelled</span>
                    </div>
                </div>
            </header>

            <div class="archive-layout">
                <!-- Folders. Year then month, because that is how the office
                     already refers to past work. -->
                <aside class="archive-folders">
                    <h3>Folders</h3>

                    <button
                        type="button"
                        :class="['folder-row', 'folder-all', { active: !filters.year }]"
                        @click="applyFilter({ year: null, month: null })"
                    >
                        <i class="fas fa-inbox"></i>
                        <span>All years</span>
                        <span class="folder-count">{{ stats.total }}</span>
                    </button>

                    <div v-for="folder in folders" :key="folder.year" class="folder-group">
                        <button
                            type="button"
                            :class="['folder-row', { active: String(filters.year) === folder.year && !filters.month }]"
                            @click="toggleYear(folder.year)"
                        >
                            <i :class="['fas', openYears.includes(folder.year) ? 'fa-folder-open' : 'fa-folder']"></i>
                            <span>{{ folder.year }}</span>
                            <span class="folder-count">{{ folder.total }}</span>
                        </button>

                        <div v-if="openYears.includes(folder.year)" class="folder-months">
                            <button
                                v-for="month in folder.months"
                                :key="month.month"
                                type="button"
                                :class="['folder-row', 'folder-month', {
                                    active: String(filters.year) === folder.year && String(filters.month) === month.month
                                }]"
                                @click="applyFilter({ year: folder.year, month: month.month })"
                            >
                                <span>{{ month.label }}</span>
                                <span class="folder-count">{{ month.total }}</span>
                            </button>
                        </div>
                    </div>

                    <p v-if="!folders.length" class="folder-empty">Nothing archived yet.</p>
                </aside>

                <section class="archive-main">
                    <div class="archive-controls">
                        <div class="archive-search">
                            <i class="fas fa-search"></i>
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Search by reference, client, description or location…"
                            >
                        </div>

                        <select :value="filters.outcome || 'all'" @change="applyFilter({ outcome: $event.target.value })">
                            <option value="all">All outcomes</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>

                        <select :value="filters.client_id || ''" @change="applyFilter({ client_id: $event.target.value || null })">
                            <option value="">All clients</option>
                            <option v-for="client in clients" :key="client.id" :value="client.id">
                                {{ client.name }}
                            </option>
                        </select>

                        <button v-if="hasFilters" type="button" class="btn btn-sm btn-secondary" @click="clearFilters">
                            Clear
                        </button>
                    </div>

                    <p class="archive-context" v-if="folderLabel">
                        Showing <strong>{{ folderLabel }}</strong>
                    </p>

                    <div v-if="requests.data.length" class="archive-list">
                        <article v-for="request in requests.data" :key="request.id" class="archive-card">
                            <div class="archive-card-head">
                                <div>
                                    <Link :href="`/admin/jobs/${request.id}`" class="archive-ref">
                                        {{ request.request_id }}
                                    </Link>
                                    <span :class="['outcome-badge', request.outcome]">
                                        {{ request.outcome === 'cancelled' ? 'Cancelled' : 'Completed' }}
                                    </span>
                                </div>
                                <time class="archive-date">{{ formatDate(request.archived_at) }}</time>
                            </div>

                            <p class="archive-description">{{ request.description }}</p>

                            <div class="archive-meta">
                                <span><i class="fas fa-user"></i> {{ request.client?.name || 'Unknown client' }}</span>
                                <span v-if="request.category"><i class="fas fa-layer-group"></i> {{ request.category }}</span>
                                <span v-if="request.location"><i class="fas fa-map-marker-alt"></i> {{ request.location }}</span>
                                <span v-if="request.technician"><i class="fas fa-hard-hat"></i> {{ request.technician }}</span>
                                <span v-if="request.quote_amount">
                                    <i class="fas fa-file-invoice"></i> KSH {{ formatCurrency(request.final_amount || request.quote_amount) }}
                                </span>
                            </div>

                            <p v-if="request.outcome === 'cancelled' && request.rejection_reason" class="archive-reason">
                                {{ request.rejection_reason }}
                            </p>

                            <div class="archive-card-actions">
                                <Link :href="`/admin/jobs/${request.id}`" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-eye"></i> Open
                                </Link>
                                <!-- Only cancellations. Undoing a delivered job
                                     touches billing, technician payments and the
                                     client's record, and that is not a status flip. -->
                                <button
                                    v-if="request.outcome === 'cancelled'"
                                    type="button"
                                    class="btn btn-sm btn-primary"
                                    @click="openReopen(request)"
                                >
                                    <i class="fas fa-rotate-left"></i> Reopen
                                </button>
                            </div>
                        </article>
                    </div>

                    <div v-else class="archive-empty">
                        <i class="fas fa-box-open"></i>
                        <p>Nothing matches that.</p>
                        <button v-if="hasFilters" type="button" class="btn btn-sm btn-secondary" @click="clearFilters">
                            Clear filters
                        </button>
                    </div>

                    <div v-if="requests.links && requests.last_page > 1" class="archive-pagination">
                        <Link
                            v-for="link in requests.links"
                            :key="link.label"
                            :href="link.url || ''"
                            :class="['page-link', { active: link.active, disabled: !link.url }]"
                            preserve-scroll
                            v-html="link.label"
                        />
                    </div>
                </section>
            </div>
        </main>

        <div v-if="reopening" class="modal-overlay">
            <div class="modal-content" @click.stop>
                <div class="modal-header">
                    <h3>Reopen {{ reopening.request_id }}</h3>
                    <button @click="reopening = null" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="reopen-help">
                        This returns the request to the stage it was at when it was cancelled,
                        and it reappears in RFQ Management.
                    </p>
                    <div class="form-group">
                        <label>Why is it being reopened? *</label>
                        <textarea v-model="reopenReason" rows="3" class="form-control"
                            placeholder="e.g. Client confirmed the works are going ahead after all."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="reopening = null" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="submitReopen"
                        class="btn btn-primary"
                        :disabled="reopenReason.trim().length < 10 || reopenSaving"
                    >
                        {{ reopenSaving ? 'Reopening…' : 'Reopen request' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import AdminSidebar from '../../Components/AdminSidebar.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import debounce from 'lodash/debounce'

const props = defineProps({
    requests: { type: Object, required: true },
    folders: { type: Array, default: () => [] },
    clients: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({ total: 0, completed: 0, cancelled: 0 }) },
    filters: { type: Object, default: () => ({}) },
})

const search = ref(props.filters.search || '')

// Years open by default when one is already selected, so a filtered page does
// not present a collapsed tree that hides the folder being shown.
const openYears = ref(props.filters.year ? [String(props.filters.year)] : [])

const hasFilters = computed(() =>
    Boolean(props.filters.search || props.filters.year ||
        (props.filters.outcome && props.filters.outcome !== 'all') || props.filters.client_id)
)

const folderLabel = computed(() => {
    if (!props.filters.year) return null
    if (!props.filters.month) return props.filters.year

    const year = props.folders.find(f => f.year === String(props.filters.year))
    const month = year?.months.find(m => m.month === String(props.filters.month))
    return month ? `${month.label} ${props.filters.year}` : String(props.filters.year)
})

const applyFilter = (changes) => {
    router.get('/admin/archive', {
        search: search.value || undefined,
        outcome: props.filters.outcome !== 'all' ? props.filters.outcome : undefined,
        year: props.filters.year || undefined,
        month: props.filters.month || undefined,
        client_id: props.filters.client_id || undefined,
        ...changes,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

const toggleYear = (year) => {
    // Opening a year both expands it and filters to it: two separate gestures
    // for one intention would be a click the office does not need.
    if (openYears.value.includes(year)) {
        openYears.value = openYears.value.filter(y => y !== year)
    } else {
        openYears.value = [...openYears.value, year]
    }
    applyFilter({ year, month: null })
}

const clearFilters = () => {
    search.value = ''
    router.get('/admin/archive', {}, { preserveState: true, replace: true })
}

watch(search, debounce((value) => {
    applyFilter({ search: value || undefined })
}, 350))

const reopening = ref(null)
const reopenReason = ref('')
const reopenSaving = ref(false)

const openReopen = (request) => {
    reopening.value = request
    reopenReason.value = ''
}

const submitReopen = () => {
    if (reopenReason.value.trim().length < 10 || reopenSaving.value) return
    reopenSaving.value = true

    router.post(`/admin/archive/${reopening.value.id}/reopen`, { reason: reopenReason.value }, {
        preserveScroll: true,
        onSuccess: () => { reopening.value = null },
        onFinish: () => { reopenSaving.value = false },
    })
}

const formatDate = (value) => {
    if (!value) return '—'
    const d = new Date(value)
    return Number.isNaN(d.getTime())
        ? '—'
        : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

const formatCurrency = (value) =>
    Number(value || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<style scoped>
.page-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1.5rem;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}
.page-kicker {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748B;
}
.page-head h1 { margin: 0.25rem 0 0.4rem; font-size: 1.6rem; color: #0F172A; }
.page-head p { margin: 0; max-width: 56ch; color: #64748B; font-size: 0.9rem; line-height: 1.55; }

.archive-stats { display: flex; gap: 0.75rem; }
.archive-stat {
    min-width: 96px;
    padding: 0.75rem 1rem;
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    text-align: center;
}
.archive-stat-value { display: block; font-size: 1.4rem; font-weight: 700; color: #0F172A; }
.archive-stat-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748B;
}

.archive-layout { display: grid; grid-template-columns: 240px 1fr; gap: 1.5rem; align-items: start; }
@media (max-width: 900px) { .archive-layout { grid-template-columns: 1fr; } }

.archive-folders {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 1rem;
    position: sticky;
    top: 1rem;
}
.archive-folders h3 {
    margin: 0 0 0.6rem;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748B;
}
.folder-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.45rem 0.55rem;
    border: none;
    border-radius: 8px;
    background: transparent;
    font: inherit;
    font-size: 0.87rem;
    color: #334155;
    cursor: pointer;
    text-align: left;
}
.folder-row:hover { background: #F1F5F9; }
.folder-row.active { background: #EFF6FF; color: #1D4ED8; font-weight: 600; }
.folder-row i { width: 16px; color: #94A3B8; }
.folder-row.active i { color: #2563EB; }
.folder-count { margin-left: auto; font-size: 0.75rem; color: #94A3B8; }
.folder-all { margin-bottom: 0.35rem; }
.folder-months { padding-left: 1.5rem; }
.folder-month { font-size: 0.83rem; }
.folder-empty { margin: 0.5rem 0 0; font-size: 0.83rem; color: #94A3B8; }

.archive-controls { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 1rem; }
.archive-search { position: relative; flex: 1; min-width: 240px; }
.archive-search i { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #94A3B8; font-size: 0.85rem; }
.archive-search input {
    width: 100%;
    box-sizing: border-box;
    padding: 0.55rem 0.75rem 0.55rem 2.1rem;
    border: 1px solid #CBD5E1;
    border-radius: 10px;
    font: inherit;
    font-size: 0.88rem;
}
.archive-controls select {
    padding: 0.55rem 0.75rem;
    border: 1px solid #CBD5E1;
    border-radius: 10px;
    font: inherit;
    font-size: 0.88rem;
    background: #fff;
}
.archive-context { margin: 0 0 0.85rem; font-size: 0.85rem; color: #64748B; }

.archive-list { display: flex; flex-direction: column; gap: 0.75rem; }
.archive-card {
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 1rem 1.1rem;
}
.archive-card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
.archive-ref { font-weight: 700; color: #0F172A; text-decoration: none; }
.archive-ref:hover { text-decoration: underline; }
.archive-date { font-size: 0.8rem; color: #94A3B8; white-space: nowrap; }

.outcome-badge {
    display: inline-block;
    margin-left: 0.5rem;
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.outcome-badge.completed { background: #DCFCE7; color: #166534; }
.outcome-badge.cancelled { background: #FEE2E2; color: #991B1B; }

.archive-description { margin: 0.5rem 0 0.6rem; color: #334155; font-size: 0.9rem; line-height: 1.5; }
.archive-meta { display: flex; flex-wrap: wrap; gap: 0.4rem 1rem; font-size: 0.8rem; color: #64748B; }
.archive-meta i { margin-right: 0.3rem; color: #94A3B8; }
.archive-reason {
    margin: 0.7rem 0 0;
    padding: 0.55rem 0.7rem;
    background: #FEF2F2;
    border-left: 3px solid #FCA5A5;
    border-radius: 6px;
    font-size: 0.83rem;
    color: #991B1B;
}
.archive-card-actions { display: flex; gap: 0.5rem; margin-top: 0.85rem; }

.archive-empty {
    padding: 3rem 1rem;
    text-align: center;
    color: #94A3B8;
    background: #fff;
    border: 1px dashed #CBD5E1;
    border-radius: 12px;
}
.archive-empty i { font-size: 2rem; margin-bottom: 0.6rem; display: block; }
.archive-empty p { margin: 0 0 0.75rem; }

.archive-pagination { display: flex; gap: 0.3rem; flex-wrap: wrap; margin-top: 1rem; }
.page-link {
    padding: 0.35rem 0.7rem;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    font-size: 0.85rem;
    color: #334155;
    text-decoration: none;
    background: #fff;
}
.page-link.active { background: #2563EB; border-color: #2563EB; color: #fff; }
.page-link.disabled { opacity: 0.45; pointer-events: none; }

.reopen-help { margin: 0 0 0.9rem; font-size: 0.86rem; color: #64748B; line-height: 1.5; }
</style>
