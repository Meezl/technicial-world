<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="rfq" />
        <main class="main-content">
            <header class="main-header">
                <div>
                    <Link href="/admin/rfq" class="back"><i class="fas fa-arrow-left"></i> RFQ Management</Link>
                    <h1>{{ request.request_id }}</h1>
                    <p class="sub">
                        {{ request.organisation?.name }}
                        <span v-if="request.property"> · {{ request.property.name }}</span>
                        <span v-if="request.rate_schedule"> · priced from {{ request.rate_schedule.name }} v{{ request.rate_schedule.version }}</span>
                    </p>
                </div>
                <button class="btn btn-primary" :disabled="busy" @click="autoPopulate">
                    <i class="fas fa-bolt"></i> {{ busy ? 'Pricing…' : 'Auto-populate rates' }}
                </button>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.warning" class="alert alert-warning" style="margin:1rem;">{{ flash.warning }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <h3 style="margin-top:0;">Lines</h3>
                    <p class="note">
                        Rates fill in from the client's schedule. Dates and locations here become the access
                        schedule the client and their security team are given.
                    </p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Item</th><th>Unit</th><th class="num">Qty</th><th class="num">Rate</th>
                                    <th class="num">Total</th><th>Where</th><th>Dates</th><th>Technician</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in request.items" :key="l.id" :class="!l.is_priced && 'unpriced'">
                                    <td>
                                        {{ l.description }}
                                        <span v-if="l.kind === 'ancillary'" class="pill pill-blue">Ancillary</span>
                                        <span v-if="!l.is_priced" class="pill pill-amber">Not priced</span>
                                        <div v-if="l.urgency === 'high'"><small class="bad">Emergency</small></div>
                                    </td>
                                    <td><small>{{ l.unit_label }}</small></td>
                                    <td class="num">{{ Number(l.quantity) }}</td>
                                    <td class="num">{{ money(l.composite_rate) }}</td>
                                    <td class="num"><strong>{{ money(l.line_total) }}</strong></td>
                                    <td><small>{{ l.location_detail || '—' }}</small></td>
                                    <td><small>{{ dates(l) }}</small></td>
                                    <td>
                                        <small v-if="l.assigned_technician">
                                            {{ l.assigned_technician.user?.name }}
                                            <span :class="['pill', l.released_to_technician_at ? 'pill-green' : 'pill-grey']">
                                                {{ l.released_to_technician_at ? 'open' : 'closed' }}
                                            </span>
                                        </small>
                                        <small v-else class="muted">—</small>
                                    </td>
                                    <td>
                                        <button class="link" @click="openEdit(l)">Edit</button>
                                        <button class="link" @click="openAssign(l)">Assign</button>
                                        <button class="link bad" @click="removeLine(l)">Remove</button>
                                    </td>
                                </tr>
                                <tr v-if="!request.items.length"><td colspan="9" class="text-center">
                                    The client has not composed this request from the catalogue.
                                </td></tr>
                            </tbody>
                        </table>
                    </div>

                    <table class="totals">
                        <tr><td>Subtotal (excl. VAT)</td><td class="num">{{ money(totals.subtotal_ex_vat) }}</td></tr>
                        <tr><td>VAT @ {{ totals.vat_rate }}%</td><td class="num">{{ money(totals.vat_amount) }}</td></tr>
                        <tr class="grand"><td>Total</td><td class="num">{{ money(totals.total_inc_vat) }}</td></tr>
                    </table>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <h3 style="margin-top:0;">Add an ancillary cost</h3>
                    <p class="note">Permits, night-shift allowances — anything the catalogue cannot know. VAT re-adjusts.</p>
                    <div class="form-row">
                        <div class="form-group" style="flex:2;"><label>Description</label>
                            <input v-model="anc.description" type="text" class="form-control" maxlength="255" /></div>
                        <div class="form-group"><label>Unit</label>
                            <select v-model="anc.unit" class="form-control">
                                <option v-for="(l, k) in units" :key="k" :value="k">{{ l }}</option>
                            </select></div>
                        <div class="form-group"><label>Qty</label>
                            <input v-model.number="anc.quantity" type="number" step="0.01" min="0.01" class="form-control" /></div>
                        <div class="form-group"><label>Unit price</label>
                            <input v-model.number="anc.unit_price" type="number" step="0.01" min="0" class="form-control" /></div>
                    </div>
                    <button class="btn btn-primary" :disabled="busy || !anc.description || !anc.unit_price" @click="addAncillary">Add</button>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <h3 style="margin-top:0;">What each audience sees</h3>
                    <p class="note">
                        One quotation, four readings. The requester sees no rates unless you open them; security
                        never sees money at all; a technician sees only the lines opened to them.
                    </p>
                    <div class="toggle">
                        <label>
                            <input type="checkbox" :checked="request.prices_visible_to_requester" @change="togglePrices($event.target.checked)" />
                            Show rates to the requester
                        </label>
                    </div>
                    <div class="proj-grid">
                        <div class="proj">
                            <h4>Requester</h4>
                            <p class="muted small">{{ projections.client.shows_money ? 'Rates visible' : 'Rates hidden' }} · {{ projections.client.lines.length }} line(s)</p>
                        </div>
                        <div class="proj">
                            <h4>Security</h4>
                            <p class="muted small">Items, locations and dates. Never money.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card full-width" :class="request.quote_signed_at ? 'signed' : ''">
                    <h3 style="margin-top:0;">Signature</h3>
                    <p v-if="request.quote_signed_at" class="ok">
                        <i class="fas fa-check-circle"></i>
                        Signed by {{ request.quote_signed_by }} on {{ new Date(request.quote_signed_at).toLocaleString() }}.
                    </p>
                    <template v-else>
                        <p class="note">Sign before posting to the client's verifier or approver. Every line must be priced first.</p>
                        <div class="row">
                            <input type="file" accept=".png,.jpg,.jpeg" @change="e => signature = e.target.files[0]" />
                            <button class="btn btn-primary" :disabled="busy" @click="sign">Sign quotation</button>
                        </div>
                    </template>
                </div>
            </section>
        </main>

        <div v-if="editLine" class="modal-overlay" @click.self="editLine = null">
            <div class="modal-content" style="max-width:520px;">
                <div class="modal-header"><h3>{{ editLine.description }}</h3><button @click="editLine = null" class="close-btn">&times;</button></div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group"><label>Quantity</label>
                            <input v-model.number="lineForm.quantity" type="number" step="0.01" min="0.01" class="form-control" /></div>
                        <div class="form-group"><label>Urgency</label>
                            <select v-model="lineForm.urgency" class="form-control">
                                <option value="low">Low</option><option value="medium">Medium</option><option value="high">Emergency</option>
                            </select></div>
                    </div>
                    <div class="form-group"><label>Where exactly</label>
                        <input v-model="lineForm.location_detail" type="text" class="form-control" maxlength="255"
                               placeholder="14th floor gents toilets, cubicle 1" /></div>
                    <div class="form-row">
                        <div class="form-group"><label>Start</label>
                            <input v-model="lineForm.planned_start" type="date" class="form-control" /></div>
                        <div class="form-group"><label>End</label>
                            <input v-model="lineForm.planned_end" type="date" class="form-control" /></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="editLine = null" class="btn btn-secondary">Cancel</button>
                    <button @click="saveLine" :disabled="busy" class="btn btn-primary">Save</button>
                </div>
            </div>
        </div>

        <div v-if="assignLine" class="modal-overlay" @click.self="assignLine = null">
            <div class="modal-content" style="max-width:460px;">
                <div class="modal-header"><h3>Assign to a technician</h3><button @click="assignLine = null" class="close-btn">&times;</button></div>
                <div class="modal-body">
                    <p class="note">{{ assignLine.description }}</p>
                    <div class="form-group"><label>Technician</label>
                        <select v-model="assignForm.technician_id" class="form-control">
                            <option :value="null" disabled>Select…</option>
                            <option v-for="t in technicians" :key="t.id" :value="t.id">{{ t.user?.name }}</option>
                        </select></div>
                    <div class="form-group"><label>
                        <input v-model="assignForm.release" type="checkbox" /> Open this line to them now
                    </label>
                    <small class="hint">Leave unticked to assign without showing it yet.</small></div>
                </div>
                <div class="modal-footer">
                    <button @click="assignLine = null" class="btn btn-secondary">Cancel</button>
                    <button @click="saveAssign" :disabled="busy || !assignForm.technician_id" class="btn btn-primary">Save</button>
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
    request: { type: Object, required: true },
    totals: { type: Object, required: true },
    units: { type: Object, required: true },
    components: { type: Object, required: true },
    technicians: { type: Array, required: true },
    projections: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const busy = ref(false)
const signature = ref(null)
const editLine = ref(null)
const assignLine = ref(null)

const anc = reactive({ description: '', unit: 'lot', quantity: 1, unit_price: null })
const lineForm = reactive({ quantity: 1, urgency: 'medium', location_detail: '', planned_start: '', planned_end: '' })
const assignForm = reactive({ technician_id: null, release: true })

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const dates = (l) => {
    if (!l.planned_start && !l.planned_end) return '—'
    const f = (d) => d ? new Date(d).toLocaleDateString() : '?'
    return `${f(l.planned_start)} – ${f(l.planned_end)}`
}

const base = `/admin/compose/${props.request.id}`
const done = { onFinish: () => { busy.value = false } }

const autoPopulate = () => { busy.value = true; router.post(`${base}/auto-populate`, {}, done) }
const addAncillary = () => {
    busy.value = true
    router.post(`${base}/ancillary`, { ...anc }, {
        onFinish: () => { busy.value = false; anc.description = ''; anc.unit_price = null },
    })
}
const togglePrices = (visible) => { busy.value = true; router.post(`${base}/prices`, { visible }, done) }
const sign = () => { busy.value = true; router.post(`${base}/sign`, { signature: signature.value }, { forceFormData: true, ...done }) }
const removeLine = (l) => { if (confirm(`Remove ${l.description}?`)) { busy.value = true; router.delete(`${base}/items/${l.id}`, done) } }

const openEdit = (l) => {
    editLine.value = l
    Object.assign(lineForm, {
        quantity: Number(l.quantity), urgency: l.urgency, location_detail: l.location_detail || '',
        planned_start: l.planned_start ? String(l.planned_start).slice(0, 10) : '',
        planned_end: l.planned_end ? String(l.planned_end).slice(0, 10) : '',
    })
}
const saveLine = () => {
    busy.value = true
    router.put(`${base}/items/${editLine.value.id}`, { ...lineForm },
        { onFinish: () => { busy.value = false; editLine.value = null } })
}

const openAssign = (l) => {
    assignLine.value = l
    assignForm.technician_id = l.assigned_technician_id || null
    assignForm.release = !!l.released_to_technician_at
}
const saveAssign = () => {
    busy.value = true
    router.post(`${base}/items/${assignLine.value.id}/assign`, { ...assignForm },
        { onFinish: () => { busy.value = false; assignLine.value = null } })
}
</script>

<style scoped>
.back { font-size: .8rem; color: #6b7280; text-decoration: none; }
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.note { color: #6b7280; font-size: .8rem; margin: 0 0 1rem; }
.hint { color: #6b7280; font-size: .72rem; }
.muted { color: #6b7280; }
.small { font-size: .8rem; }
.bad { color: #b91c1c; }
.ok { color: #15803d; font-size: .87rem; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.unpriced { background: #fffbeb; }
.link { background: none; border: none; color: #2563eb; cursor: pointer; font-size: .78rem; padding: 0 .3rem; }
.link.bad { color: #dc2626; }
.totals { width: 320px; margin-left: auto; margin-top: 1rem; border-collapse: collapse; font-size: .87rem; }
.totals td { padding: .35rem .5rem; border-bottom: 1px solid #f3f4f6; }
.totals .grand td { font-weight: 700; border-top: 2px solid #1e3a8a; }
.row { display: flex; gap: .6rem; align-items: center; }
.form-row { display: flex; gap: 1rem; flex-wrap: wrap; }
.form-row .form-group { flex: 1; min-width: 130px; }
.toggle { margin-bottom: 1rem; font-size: .87rem; }
.proj-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
.proj { background: #f9fafb; border-radius: 8px; padding: .8rem; }
.proj h4 { margin: 0 0 .3rem; font-size: .85rem; }
.proj p { margin: 0; }
.signed { border-left: 4px solid #16a34a; }
.pill { display: inline-block; padding: .1rem .5rem; border-radius: 999px; font-size: .68rem; margin-left: .35rem; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-grey { background: #f3f4f6; color: #4b5563; }
.alert-warning { background: #fffbeb; color: #92400e; }
</style>
