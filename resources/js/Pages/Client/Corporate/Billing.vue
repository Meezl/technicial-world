<template>
    <div class="corp-page">
        <header class="head">
            <div>
                <h1>Billing</h1>
                <p class="sub">{{ membership.organisation }} · {{ membership.position_label }}</p>
            </div>
        </header>

        <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>
        <div v-if="flash.error" class="alert alert-danger">{{ flash.error }}</div>

        <section class="card">
            <div class="card-head">
                <h3>Invoices</h3>
                <button v-if="openInvoices.length" class="btn btn-primary btn-sm" @click="showPay = true">
                    Post a payment
                </button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Invoice</th><th>Request</th><th>Property</th>
                            <th class="num">Total</th><th class="num">Net payable</th>
                            <th class="num">Outstanding</th><th>Status</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="inv in invoices" :key="inv.id">
                            <td><strong>{{ inv.invoice_number }}</strong></td>
                            <td>{{ inv.service_request?.request_id || '—' }}</td>
                            <td>{{ inv.property_name || '—' }}</td>
                            <td class="num">{{ money(inv.total_inc_vat) }}</td>
                            <td class="num">{{ money(inv.net_expected) }}</td>
                            <td class="num">{{ money(outstanding(inv)) }}</td>
                            <td><span :class="['pill', statusClass(inv.status)]">{{ statusLabel(inv.status) }}</span></td>
                            <td>
                                <button v-if="isOpen(inv)" class="link" @click="openCertificate(inv)">Add certificate</button>
                            </td>
                        </tr>
                        <tr v-if="!invoices.length"><td colspan="8" class="empty">No invoices yet.</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="note">
                Withholding tax you pay to KRA on our behalf is not a shortfall — attach the
                certificate and the job closes as fully paid.
            </p>
        </section>

        <section class="card">
            <h3>Payments posted</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Covers</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="s in settlements" :key="s.id">
                            <td>{{ date(s.paid_on) }}</td>
                            <td>{{ methods[s.method] || s.method }}</td>
                            <td>{{ s.reference || '—' }}</td>
                            <td class="num">{{ money(s.gross_amount) }}</td>
                            <td><small>{{ (s.allocations || []).map(a => a.invoice?.invoice_number).filter(Boolean).join(', ') || '—' }}</small></td>
                            <td>
                                <span :class="['pill', s.status === 'validated' ? 'pill-green' : s.status === 'rejected' ? 'pill-red' : 'pill-amber']">
                                    {{ s.status }}
                                </span>
                                <div v-if="s.rejection_reason"><small class="bad">{{ s.rejection_reason }}</small></div>
                            </td>
                        </tr>
                        <tr v-if="!settlements.length"><td colspan="6" class="empty">Nothing posted yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h3>Withholding certificates</h3>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Invoice</th><th>Type</th><th>Number</th><th class="num">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="c in certificates" :key="c.id">
                            <td>{{ c.invoice?.invoice_number || '—' }}</td>
                            <td>{{ certificateTypes[c.type] || c.type }}</td>
                            <td>{{ c.certificate_number || '—' }}</td>
                            <td class="num">{{ money(c.amount) }}</td>
                            <td>
                                <span :class="['pill', c.status === 'validated' ? 'pill-green' : c.status === 'rejected' ? 'pill-red' : 'pill-amber']">
                                    {{ c.status }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!certificates.length"><td colspan="5" class="empty">None submitted yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Post a payment against ticked invoices -->
        <div v-if="showPay" class="overlay" @click.self="showPay = false">
            <div class="modal">
                <h3>Post a payment</h3>
                <p class="note">
                    Tick what this payment covers and enter the amount applied to each. Where you have
                    withheld tax, enter the net you actually transferred — the withholding is settled
                    by the certificates.
                </p>

                <div class="alloc">
                    <label v-for="inv in openInvoices" :key="inv.id" class="alloc-row">
                        <input type="checkbox" :checked="ticked(inv.id)" @change="toggle(inv)" />
                        <span class="alloc-name">
                            {{ inv.invoice_number }}
                            <small class="muted">{{ inv.service_request?.request_id }}</small>
                        </span>
                        <span class="muted">net {{ money(inv.net_expected) }}</span>
                        <input type="number" step="0.01" min="0" class="alloc-amt"
                               :value="pay.allocations[inv.id] ?? ''" :disabled="!ticked(inv.id)"
                               @input="e => pay.allocations[inv.id] = e.target.value" />
                    </label>
                </div>

                <div class="tot" :class="over && 'bad'">
                    Allocated {{ money(allocatedTotal) }} of {{ money(pay.gross_amount || 0) }}
                    <span v-if="over"> — more than the payment</span>
                </div>

                <div class="row">
                    <div class="field"><label>Method</label>
                        <select v-model="pay.method"><option v-for="(l,k) in methods" :key="k" :value="k">{{ l }}</option></select>
                    </div>
                    <div class="field"><label>Amount transferred</label>
                        <input v-model.number="pay.gross_amount" type="number" step="0.01" min="0" /></div>
                    <div class="field"><label>Reference</label><input v-model="pay.reference" type="text" maxlength="80" /></div>
                    <div class="field"><label>Date paid</label><input v-model="pay.paid_on" type="date" /></div>
                </div>
                <div class="field"><label>Proof of payment <span class="req">*</span></label>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => pay.proof = e.target.files[0]" /></div>
                <div class="field"><label>Remittance statement (optional)</label>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png,.xls,.xlsx,.csv" @change="e => pay.statement = e.target.files[0]" /></div>

                <div class="actions">
                    <button class="btn btn-secondary" @click="showPay = false">Cancel</button>
                    <button class="btn btn-primary" :disabled="busy || !payReady" @click="submitPayment">
                        {{ busy ? 'Posting…' : 'Post payment' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Attach a certificate -->
        <div v-if="showCert" class="overlay" @click.self="showCert = false">
            <div class="modal">
                <h3>Withholding certificate</h3>
                <p class="note">For {{ certInvoice?.invoice_number }}.</p>
                <div class="row">
                    <div class="field"><label>Type</label>
                        <select v-model="cert.type" @change="prefillCertAmount">
                            <option v-for="(l,k) in certificateTypes" :key="k" :value="k">{{ l }}</option>
                        </select>
                    </div>
                    <div class="field"><label>Amount</label><input v-model.number="cert.amount" type="number" step="0.01" min="0" /></div>
                    <div class="field"><label>Certificate no.</label><input v-model="cert.certificate_number" type="text" maxlength="80" /></div>
                    <div class="field"><label>Date</label><input v-model="cert.certificate_date" type="date" /></div>
                </div>
                <div class="field"><label>Certificate <span class="req">*</span></label>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => cert.document = e.target.files[0]" /></div>
                <div class="actions">
                    <button class="btn btn-secondary" @click="showCert = false">Cancel</button>
                    <button class="btn btn-primary" :disabled="busy || !cert.document || !cert.amount" @click="submitCertificate">
                        {{ busy ? 'Sending…' : 'Submit' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    membership: { type: Object, required: true },
    invoices: { type: Array, required: true },
    settlements: { type: Array, required: true },
    certificates: { type: Array, required: true },
    methods: { type: Object, required: true },
    certificateTypes: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const busy = ref(false)
const showPay = ref(false)
const showCert = ref(false)
const certInvoice = ref(null)

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const date = (d) => d ? new Date(d).toLocaleDateString() : '—'

const isOpen = (inv) => ['dispatched', 'part_settled'].includes(inv.status)
const openInvoices = computed(() => props.invoices.filter(isOpen))
const outstanding = (inv) =>
    Number(inv.total_inc_vat || 0) - Number(inv.paid_amount || 0) - Number(inv.certified_amount || 0)

const statusLabel = (s) => ({
    dispatched: 'Awaiting payment',
    part_settled: 'Part settled',
    settled: 'Settled',
    void: 'Cancelled',
}[s] || s)
const statusClass = (s) => ({
    dispatched: 'pill-amber', part_settled: 'pill-blue', settled: 'pill-green', void: 'pill-grey',
}[s] || 'pill-grey')

const pay = reactive({
    method: Object.keys(props.methods)[0], gross_amount: null, reference: '',
    paid_on: new Date().toISOString().slice(0, 10),
    proof: null, statement: null, allocations: {},
})

const ticked = (id) => Object.prototype.hasOwnProperty.call(pay.allocations, id)
const toggle = (inv) => {
    if (ticked(inv.id)) delete pay.allocations[inv.id]
    // Defaulted to the net rather than the face value: that is what leaves
    // their bank when tax has been withheld.
    else pay.allocations[inv.id] = Number(inv.net_expected || 0)
}
const allocatedTotal = computed(() =>
    Object.values(pay.allocations).reduce((sum, v) => sum + Number(v || 0), 0))
const over = computed(() => allocatedTotal.value > Number(pay.gross_amount || 0) + 0.01)
const payReady = computed(() =>
    pay.proof && pay.gross_amount > 0 && Object.keys(pay.allocations).length > 0 && !over.value)

const submitPayment = () => {
    busy.value = true
    router.post('/corporate/billing/settlements', { ...pay }, {
        forceFormData: true,
        onFinish: () => { busy.value = false; showPay.value = false },
    })
}

const cert = reactive({
    invoice_id: null, type: Object.keys(props.certificateTypes)[0],
    amount: null, certificate_number: '', certificate_date: '', document: null,
})

const openCertificate = (inv) => {
    certInvoice.value = inv
    cert.invoice_id = inv.id
    cert.type = 'whvat'
    prefillCertAmount()
    showCert.value = true
}
// The invoice already knows what was withheld, so the client is not asked to
// work it out again.
const prefillCertAmount = () => {
    const inv = certInvoice.value
    if (!inv) return
    cert.amount = Number(cert.type === 'wht' ? inv.wht_amount : inv.whvat_amount) || null
}

const submitCertificate = () => {
    busy.value = true
    router.post('/corporate/billing/certificates', { ...cert }, {
        forceFormData: true,
        onFinish: () => { busy.value = false; showCert.value = false },
    })
}
</script>

<style scoped>
.corp-page { padding: 1.5rem; max-width: 1150px; margin: 0 auto; }
h1 { margin: 0; font-size: 1.5rem; }
.sub { margin: .25rem 0 1.5rem; color: #6b7280; font-size: .85rem; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.15rem; margin-bottom: 1rem; }
.card h3 { margin: 0 0 .8rem; font-size: .95rem; }
.card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: .8rem; }
.card-head h3 { margin: 0; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: .84rem; }
th { text-align: left; padding: .5rem .6rem; background: #f9fafb; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
td { padding: .55rem .6rem; border-bottom: 1px solid #f3f4f6; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.empty { text-align: center; color: #6b7280; padding: 1.5rem; }
.note { color: #6b7280; font-size: .78rem; margin: .8rem 0 0; }
.muted { color: #6b7280; }
.bad { color: #b91c1c; }
.link { background: none; border: none; color: #2563eb; cursor: pointer; font-size: .8rem; padding: 0; }
.pill { display: inline-block; padding: .12rem .55rem; border-radius: 999px; font-size: .72rem; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-red { background: #fee2e2; color: #991b1b; }
.pill-grey { background: #f3f4f6; color: #4b5563; }
.alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .85rem; }
.alert-success { background: #f0fdf4; color: #166534; }
.alert-danger { background: #fef2f2; color: #991b1b; }
.overlay { position: fixed; inset: 0; background: rgba(17,24,39,.5); display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 50; }
.modal { background: #fff; border-radius: 12px; padding: 1.25rem; width: 100%; max-width: 640px; max-height: 90vh; overflow: auto; }
.alloc { border: 1px solid #e5e7eb; border-radius: 8px; max-height: 200px; overflow: auto; margin-bottom: .6rem; }
.alloc-row { display: flex; align-items: center; gap: .6rem; padding: .45rem .6rem; border-bottom: 1px solid #f3f4f6; font-size: .82rem; }
.alloc-name { flex: 1; }
.alloc-amt { width: 120px; padding: .3rem .4rem; border: 1px solid #d1d5db; border-radius: 5px; text-align: right; }
.tot { font-size: .82rem; color: #6b7280; margin-bottom: .8rem; }
.tot.bad { color: #b91c1c; font-weight: 600; }
.row { display: flex; gap: .8rem; flex-wrap: wrap; }
.field { display: flex; flex-direction: column; flex: 1; min-width: 140px; margin-bottom: .7rem; }
.field label { font-size: .78rem; font-weight: 600; margin-bottom: .25rem; }
.field input, .field select { padding: .45rem .6rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; font-size: .85rem; }
.req { color: #dc2626; }
.actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1rem; }
</style>
