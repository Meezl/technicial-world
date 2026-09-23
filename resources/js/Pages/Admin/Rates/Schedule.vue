<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="rates" />
        <main class="main-content">
            <header class="main-header">
                <div>
                    <Link href="/admin/rates" class="back"><i class="fas fa-arrow-left"></i> Rate Schedules</Link>
                    <h1>{{ schedule.name }} <span class="ver">v{{ schedule.version }}</span></h1>
                    <p class="sub">
                        {{ schedule.organisation?.name || 'House list' }} ·
                        <span :class="['pill', pill(schedule.status)]">{{ schedule.status }}</span>
                    </p>
                </div>
                <div class="head-actions">
                    <button v-if="schedule.status === 'draft'" class="btn btn-primary btn-sm" :disabled="busy" @click="activate">
                        Activate
                    </button>
                    <button v-if="schedule.status === 'active'" class="btn btn-secondary btn-sm" :disabled="busy" @click="draftNext">
                        Draft next version
                    </button>
                </div>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.warning" class="alert alert-warning" style="margin:1rem;">{{ flash.warning }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <section v-if="skipped.length" class="main-panel">
                <div class="panel-card full-width warn-card">
                    <h3 style="margin-top:0;">Rows that could not be imported</h3>
                    <ul class="skips"><li v-for="(s, i) in skipped" :key="i">Row {{ s.row }} — {{ s.reason }}</li></ul>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <h3 style="margin-top:0;">Load from a spreadsheet</h3>
                    <p class="note">
                        CSV with a header row. Columns are matched by name, so whatever the list is already
                        kept in will do: <code>code, description, unit, material, labour, transport,
                        consumable, overhead, margin</code>. Rows with an existing code are updated.
                    </p>
                    <div class="row">
                        <input type="file" accept=".csv,text/csv" @change="e => importFile = e.target.files[0]" />
                        <button class="btn btn-primary" :disabled="busy || !importFile" @click="doImport">Import</button>
                    </div>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="panel-head">
                        <h3>Items <small class="muted">({{ items.total }})</small></h3>
                        <div class="row">
                            <input v-model="search" type="search" placeholder="Search the catalogue…"
                                   class="form-control" style="width:260px;" @keyup.enter="doSearch" />
                            <button class="btn btn-secondary btn-sm" @click="doSearch">Search</button>
                            <button class="btn btn-primary btn-sm" @click="openNew">Add item</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Code</th><th>Description</th><th>Unit</th>
                                    <th v-for="(label, col) in components" :key="col" class="num">{{ label }}</th>
                                    <th class="num">Rate</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in items.data" :key="item.id" :class="!item.is_active && 'inactive'">
                                    <td><small>{{ item.code || '—' }}</small></td>
                                    <td>{{ item.description }}<div v-if="item.category"><small class="muted">{{ item.category }}</small></div></td>
                                    <td><small>{{ units[item.unit] || item.unit }}</small></td>
                                    <td v-for="(label, col) in components" :key="col" class="num">{{ money(item[col]) }}</td>
                                    <td class="num"><strong>{{ money(item.composite_rate) }}</strong></td>
                                    <td><button class="link" @click="openEdit(item)">Edit</button></td>
                                </tr>
                                <tr v-if="!items.data.length"><td :colspan="5 + Object.keys(components).length" class="text-center">
                                    No items. Import the schedule or add one by hand.
                                </td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <div v-if="showItem" class="modal-overlay" @click.self="showItem = false">
            <div class="modal-content" style="max-width:620px;">
                <div class="modal-header">
                    <h3>{{ editing ? 'Edit item' : 'Add item' }}</h3>
                    <button @click="showItem = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group" style="flex:2;"><label>Description <span class="req">*</span></label>
                            <input v-model="itemForm.description" type="text" class="form-control" maxlength="255" /></div>
                        <div class="form-group"><label>Code</label>
                            <input v-model="itemForm.code" type="text" class="form-control" maxlength="60" /></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Unit</label>
                            <select v-model="itemForm.unit" class="form-control">
                                <option v-for="(l, k) in units" :key="k" :value="k">{{ l }}</option>
                            </select>
                        </div>
                        <div class="form-group"><label>Category</label>
                            <input v-model="itemForm.category" type="text" class="form-control" maxlength="80" /></div>
                    </div>
                    <div class="form-group">
                        <label>Also findable by</label>
                        <input v-model="itemForm.search_terms" type="text" class="form-control" maxlength="500"
                               placeholder="toilet, wc, close couple" />
                        <small class="hint">What a caretaker would type. They say "toilet"; the catalogue says "WC pan".</small>
                    </div>

                    <h4>Rate components</h4>
                    <div class="comp-grid">
                        <div v-for="(label, col) in components" :key="col" class="form-group">
                            <label>{{ label }}</label>
                            <input v-model.number="itemForm[col]" type="number" step="0.01" min="0" class="form-control" />
                        </div>
                    </div>
                    <p class="composite">Composite rate: <strong>{{ money(compositePreview) }}</strong> per {{ units[itemForm.unit] }}</p>

                    <div v-if="editing" class="form-group">
                        <label>Reason for the change</label>
                        <input v-model="itemForm.reason" type="text" class="form-control" maxlength="255"
                               placeholder="Shop price of granito up" />
                        <small class="hint">Kept with the revision, so "why is this rate what it is" has an answer.</small>
                    </div>
                    <div v-if="editing" class="form-group">
                        <label><input v-model="itemForm.is_active" type="checkbox" /> Active (offered to clients)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showItem = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveItem" :disabled="busy || !itemForm.description" class="btn btn-primary">Save</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    schedule: { type: Object, required: true },
    items: { type: Object, required: true },
    filters: { type: Object, required: true },
    units: { type: Object, required: true },
    components: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const skipped = computed(() => page.props.importSkipped || [])
const busy = ref(false)
const search = ref(props.filters.search || '')
const importFile = ref(null)
const showItem = ref(false)
const editing = ref(null)

const blank = () => {
    const f = { description: '', code: '', category: '', search_terms: '', unit: 'no', is_active: true, reason: '' }
    Object.keys(props.components).forEach(c => { f[c] = 0 })
    return f
}
const itemForm = reactive(blank())

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const pill = (s) => ({ active: 'pill-green', draft: 'pill-amber', superseded: 'pill-grey' }[s] || 'pill-grey')

// Mirrors RateItem::booted() so the figure on screen is the figure that saves.
const compositePreview = computed(() =>
    Object.keys(props.components).reduce((sum, c) => sum + Number(itemForm[c] || 0), 0))

const done = { onFinish: () => { busy.value = false } }
const base = `/admin/rates/${props.schedule.id}`

const doSearch = () => router.get(base, search.value ? { search: search.value } : {}, { preserveState: true, replace: true })
const activate = () => { busy.value = true; router.post(`${base}/activate`, {}, done) }
const draftNext = () => { busy.value = true; router.post(`${base}/next-version`, {}, done) }
const doImport = () => { busy.value = true; router.post(`${base}/import`, { file: importFile.value }, { forceFormData: true, ...done }) }

const openNew = () => { editing.value = null; Object.assign(itemForm, blank()); showItem.value = true }
const openEdit = (item) => {
    editing.value = item
    Object.assign(itemForm, blank(), {
        description: item.description, code: item.code || '', category: item.category || '',
        search_terms: item.search_terms || '', unit: item.unit, is_active: !!item.is_active, reason: '',
    })
    Object.keys(props.components).forEach(c => { itemForm[c] = Number(item[c] || 0) })
    showItem.value = true
}
const saveItem = () => {
    busy.value = true
    const opts = { onFinish: () => { busy.value = false; showItem.value = false } }
    if (editing.value) router.put(`${base}/items/${editing.value.id}`, { ...itemForm }, opts)
    else router.post(`${base}/items`, { ...itemForm }, opts)
}
</script>

<style scoped>
.back { font-size: .8rem; color: #6b7280; text-decoration: none; }
.ver { color: #6b7280; font-weight: 400; font-size: .9rem; }
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.head-actions { display: flex; gap: .5rem; }
.note { color: #6b7280; font-size: .8rem; margin: 0 0 1rem; }
.note code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; font-size: .95em; }
.hint { color: #6b7280; font-size: .72rem; }
.muted { color: #6b7280; }
.row { display: flex; gap: .6rem; align-items: center; }
.panel-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: .8rem; }
.panel-head h3 { margin: 0; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.inactive { opacity: .5; }
.link { background: none; border: none; color: #2563eb; cursor: pointer; font-size: .8rem; padding: 0; }
.warn-card { border-left: 4px solid #f59e0b; }
.skips { margin: 0; padding-left: 1.2rem; font-size: .82rem; color: #92400e; }
.form-row { display: flex; gap: 1rem; }
.form-row .form-group { flex: 1; }
.comp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .7rem; }
.composite { margin: .8rem 0 0; padding: .6rem .8rem; background: #eff6ff; border-radius: 6px; font-size: .88rem; }
.req { color: #dc2626; }
.pill { display: inline-block; padding: .12rem .55rem; border-radius: 999px; font-size: .72rem; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-grey { background: #f3f4f6; color: #4b5563; }
.alert-warning { background: #fffbeb; color: #92400e; }
</style>
