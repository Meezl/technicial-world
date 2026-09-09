<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="organisations" />

        <main class="main-content">
            <header class="main-header">
                <div>
                    <Link :href="`/admin/organisations/${organisation.id}`" class="back-link">
                        <i class="fas fa-arrow-left"></i> {{ organisation.name }}
                    </Link>
                    <h1>Standing Float</h1>
                </div>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <!-- No float yet -->
            <section v-if="!account" class="main-panel">
                <div class="panel-card full-width">
                    <h3 style="margin-top:0;">Book their deposit</h3>
                    <p class="note">
                        A management company leaves a lump sum with us so small emergencies can be dealt
                        with without waiting for a down-payment to clear. Corporate work cannot be staffed
                        until this is on record.
                    </p>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Deposit received <span class="req">*</span></label>
                            <input v-model.number="open.amount" type="number" min="1" step="0.01" class="form-control" />
                        </div>
                        <div class="form-group">
                            <label>Agreed float (ceiling) <span class="req">*</span></label>
                            <input v-model.number="open.ceiling_amount" type="number" min="1" step="0.01" class="form-control" />
                            <small class="hint">Settlements top back up to this and no further.</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Threshold type</label>
                            <select v-model="open.threshold_type" class="form-control">
                                <option v-for="(label, key) in thresholdTypes" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Top-up threshold <span class="req">*</span></label>
                            <input v-model.number="open.threshold_value" type="number" min="0" step="0.01" class="form-control" />
                            <small v-if="errors.threshold_value" class="err">{{ errors.threshold_value }}</small>
                            <small v-else class="hint">Below this, work stops until they top up.</small>
                        </div>
                        <div class="form-group">
                            <label>Date received</label>
                            <input v-model="open.occurred_on" type="date" class="form-control" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Reference</label>
                        <input v-model="open.reference" type="text" maxlength="80" class="form-control" placeholder="Cheque or transfer reference" />
                    </div>
                    <button class="btn btn-primary" :disabled="busy || !open.amount" @click="bookFloat">Book Float</button>
                </div>
            </section>

            <template v-else>
                <!-- Where the float stands -->
                <section class="main-panel">
                    <div class="panel-card full-width" :class="summary.below_threshold ? 'is-blocked' : 'is-ok'">
                        <div class="tiles">
                            <div class="tile">
                                <small>Available for new work</small>
                                <div class="big" :class="summary.below_threshold ? 'bad' : 'good'">
                                    {{ account.currency }} {{ money(summary.available) }}
                                </div>
                                <small class="hint">balance less what is already committed</small>
                            </div>
                            <div class="tile"><small>Cash balance</small><div class="mid">{{ money(summary.balance) }}</div></div>
                            <div class="tile"><small>Committed to approved jobs</small><div class="mid">{{ money(summary.committed) }}</div></div>
                            <div class="tile">
                                <small>Threshold</small>
                                <div class="mid">{{ money(summary.threshold) }}</div>
                                <small v-if="summary.has_override" class="warn">
                                    Override — agreed figure is {{ money(summary.base_threshold) }}
                                </small>
                            </div>
                            <div class="tile"><small>Agreed float</small><div class="mid">{{ money(summary.ceiling) }}</div></div>
                        </div>

                        <p v-if="summary.below_threshold" class="banner bad-banner">
                            <i class="fas fa-exclamation-triangle"></i>
                            Below threshold. Requests are still accepted but corporate work cannot be staffed
                            until the client tops up — or an override is applied.
                        </p>
                        <p v-else class="banner ok-banner">
                            <i class="fas fa-check-circle"></i>
                            {{ account.currency }} {{ money(summary.headroom) }} of headroom above the threshold.
                        </p>
                    </div>
                </section>

                <!-- Actions -->
                <section class="main-panel">
                    <div class="panel-card full-width">
                        <div class="tabs">
                            <button v-for="t in tabs" :key="t.key" :class="['tab', tab === t.key && 'tab-on']" @click="tab = t.key">
                                {{ t.label }}
                            </button>
                        </div>

                        <div v-if="tab === 'topup'" class="pane">
                            <p class="note">A further deposit paid in outside the invoice cycle.</p>
                            <div class="form-row">
                                <div class="form-group"><label>Amount</label><input v-model.number="topUp.amount" type="number" min="1" step="0.01" class="form-control" /></div>
                                <div class="form-group"><label>Reference</label><input v-model="topUp.reference" type="text" maxlength="80" class="form-control" /></div>
                            </div>
                            <div class="form-group"><label>Note</label><input v-model="topUp.note" type="text" maxlength="500" class="form-control" /></div>
                            <button class="btn btn-primary" :disabled="busy || !topUp.amount" @click="post('top-up', topUp)">Record Deposit</button>
                        </div>

                        <div v-if="tab === 'terms'" class="pane">
                            <p class="note">Changes what is agreed. Does not move any money.</p>
                            <div class="form-row">
                                <div class="form-group"><label>Agreed float</label><input v-model.number="terms.ceiling_amount" type="number" min="1" step="0.01" class="form-control" /></div>
                                <div class="form-group">
                                    <label>Threshold type</label>
                                    <select v-model="terms.threshold_type" class="form-control">
                                        <option v-for="(label, key) in thresholdTypes" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Threshold</label>
                                    <input v-model.number="terms.threshold_value" type="number" min="0" step="0.01" class="form-control" />
                                    <small v-if="errors.threshold_value" class="err">{{ errors.threshold_value }}</small>
                                </div>
                            </div>
                            <label><input v-model="terms.is_active" type="checkbox" /> Float is active</label>
                            <div style="margin-top:1rem;">
                                <button class="btn btn-primary" :disabled="busy" @click="put(terms)">Save Terms</button>
                            </div>
                        </div>

                        <div v-if="tab === 'override'" class="pane">
                            <p class="note">
                                Lets work start below the threshold when something genuinely cannot wait.
                                Stored separately from the agreed figure, with a reason, so the exception
                                cannot become permanent by accident.
                            </p>
                            <div v-if="summary.has_override" class="live-override">
                                <strong>Override live</strong> — threshold lowered to {{ money(summary.threshold) }}.
                                <span v-if="account.override_expires_at">Expires {{ formatDate(account.override_expires_at) }}.</span>
                                <span v-else>No expiry set.</span>
                                <p class="quote">“{{ account.override_reason }}”</p>
                                <button class="btn btn-secondary btn-sm" :disabled="busy" @click="clearOverride">Lift Override</button>
                            </div>
                            <template v-else>
                                <div class="form-row">
                                    <div class="form-group"><label>Lower threshold to</label><input v-model.number="override.override_threshold_value" type="number" min="0" step="0.01" class="form-control" /></div>
                                    <div class="form-group"><label>Expires</label><input v-model="override.expires_at" type="datetime-local" class="form-control" /></div>
                                </div>
                                <div class="form-group">
                                    <label>Reason <span class="req">*</span></label>
                                    <textarea v-model="override.reason" rows="2" maxlength="500" class="form-control" placeholder="Why this cannot wait for a top-up."></textarea>
                                    <small v-if="errors.reason" class="err">{{ errors.reason }}</small>
                                </div>
                                <button class="btn btn-primary" :disabled="busy" @click="post('override', override)">Apply Override</button>
                            </template>
                        </div>

                        <div v-if="tab === 'adjust'" class="pane">
                            <p class="note">
                                A correction. Entries are never edited — a mistake is fixed with an offsetting
                                entry that says why, so the statement stays defensible.
                            </p>
                            <div class="form-row">
                                <div class="form-group"><label>Amount (negative to deduct)</label><input v-model.number="adjust.amount" type="number" step="0.01" class="form-control" /></div>
                            </div>
                            <div class="form-group">
                                <label>Reason <span class="req">*</span></label>
                                <textarea v-model="adjust.reason" rows="2" maxlength="500" class="form-control"></textarea>
                                <small v-if="errors.reason" class="err">{{ errors.reason }}</small>
                            </div>
                            <button class="btn btn-primary" :disabled="busy || !adjust.amount" @click="post('adjust', adjust)">Post Adjustment</button>
                        </div>
                    </div>
                </section>

                <!-- Statement -->
                <section class="main-panel">
                    <div class="panel-card table-card full-width">
                        <h3 style="margin-top:0;">Statement</h3>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th><th>Movement</th><th>Job</th>
                                        <th class="num">Amount</th><th class="num">Balance</th><th class="num">Committed</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="e in entries.data" :key="e.id">
                                        <td>{{ formatDate(e.occurred_on, true) }}</td>
                                        <td>{{ labels[e.entry_type] || e.entry_type }}</td>
                                        <td><small>{{ e.service_request?.request_id || '—' }}</small></td>
                                        <td class="num" :class="Number(e.amount) < 0 ? 'bad' : 'good'">{{ money(e.amount) }}</td>
                                        <td class="num">{{ money(e.balance_after) }}</td>
                                        <td class="num">{{ money(e.committed_after) }}</td>
                                        <td><small>{{ e.note || '—' }}</small></td>
                                    </tr>
                                    <tr v-if="!entries.data.length"><td colspan="7" class="text-center">No movements yet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </template>
        </main>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    organisation: { type: Object, required: true },
    account: { type: Object, default: null },
    summary: { type: Object, default: null },
    entries: { type: Object, default: null },
    thresholdTypes: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const errors = computed(() => page.props.errors || {})
const busy = ref(false)
const tab = ref('topup')

const tabs = [
    { key: 'topup', label: 'Record Deposit' },
    { key: 'terms', label: 'Terms' },
    { key: 'override', label: 'Override' },
    { key: 'adjust', label: 'Adjustment' },
]

const labels = {
    booking: 'Deposit received',
    commitment: 'Committed to an approved job',
    commitment_release: 'Commitment released',
    consumption: 'Job closed',
    settlement_topup: 'Payment received',
    tax_certificate_topup: 'Tax certificates validated',
    adjustment: 'Adjustment',
}

const base = `/admin/organisations/${props.organisation.id}/deposit`

const open = reactive({
    amount: null, ceiling_amount: null,
    threshold_type: Object.keys(props.thresholdTypes)[0],
    threshold_value: null, reference: '', occurred_on: '',
})
const topUp = reactive({ amount: null, reference: '', note: '' })
const terms = reactive({
    ceiling_amount: Number(props.account?.ceiling_amount || 0),
    threshold_type: props.account?.threshold_type || 'absolute',
    threshold_value: Number(props.account?.threshold_value || 0),
    is_active: props.account ? !!props.account.is_active : true,
})
const override = reactive({ override_threshold_value: null, reason: '', expires_at: '' })
const adjust = reactive({ amount: null, reason: '' })

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const formatDate = (d, dateOnly = false) => {
    if (!d) return ''
    const dt = new Date(d)
    return dateOnly ? dt.toLocaleDateString() : dt.toLocaleString()
}

const done = { onFinish: () => { busy.value = false } }
const bookFloat = () => { busy.value = true; router.post(base, { ...open }, done) }
const post = (path, payload) => { busy.value = true; router.post(`${base}/${path}`, { ...payload }, done) }
const put = (payload) => { busy.value = true; router.put(base, { ...payload }, done) }
const clearOverride = () => { busy.value = true; router.delete(`${base}/override`, done) }
</script>

<style scoped>
.back-link { font-size: 0.8rem; color: #6b7280; text-decoration: none; }
.note { color: #6b7280; font-size: 0.82rem; margin: 0 0 1rem; }
.hint { color: #6b7280; font-size: 0.72rem; }
.err { color: #dc2626; font-size: 0.72rem; }
.warn { color: #b45309; font-size: 0.72rem; }
.req { color: #dc2626; }
.form-row { display: flex; gap: 1rem; flex-wrap: wrap; }
.form-row .form-group { flex: 1; min-width: 170px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 1rem; }
.tile small { color: #6b7280; font-size: 0.72rem; display: block; }
.big { font-size: 1.5rem; font-weight: 700; }
.mid { font-size: 1.1rem; font-weight: 600; }
.good { color: #15803d; }
.bad { color: #b91c1c; }
.is-blocked { border-left: 4px solid #dc2626; }
.is-ok { border-left: 4px solid #16a34a; }
.banner { margin: 1.25rem 0 0; padding: 0.7rem 0.9rem; border-radius: 8px; font-size: 0.82rem; }
.bad-banner { background: #fef2f2; color: #991b1b; }
.ok-banner { background: #f0fdf4; color: #166534; }
.tabs { display: flex; gap: 0.4rem; border-bottom: 1px solid #e5e7eb; margin-bottom: 1rem; }
.tab { background: none; border: none; padding: 0.5rem 0.9rem; cursor: pointer; font-size: 0.85rem; color: #6b7280; border-bottom: 2px solid transparent; }
.tab-on { color: #2563eb; border-bottom-color: #2563eb; font-weight: 600; }
.live-override { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 0.9rem; font-size: 0.85rem; }
.quote { font-style: italic; color: #6b7280; margin: 0.5rem 0; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
</style>
