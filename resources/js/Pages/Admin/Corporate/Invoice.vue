<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="corporate-invoices" />
        <main class="main-content">
            <header class="main-header">
                <div>
                    <Link :href="`/admin/corporate-invoices/organisation/${invoice.client_organisation_id}`" class="back">
                        <i class="fas fa-arrow-left"></i> {{ invoice.organisation?.name }}
                    </Link>
                    <h1>{{ invoice.invoice_number }}</h1>
                    <p class="sub">
                        {{ invoice.kind === 'tax_invoice' ? 'Tax invoice' : 'Proforma' }} ·
                        {{ invoice.status.replace('_', ' ') }} ·
                        {{ invoice.property_name || '—' }}
                    </p>
                </div>
                <a :href="`/admin/corporate-invoices/${invoice.id}/pdf`" class="btn btn-primary btn-sm">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </a>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <dl class="facts">
                        <div><dt>Request</dt><dd>{{ invoice.service_request?.request_id || '—' }}</dd></div>
                        <div><dt>Requested by</dt><dd>{{ invoice.requester_name || '—' }}</dd></div>
                        <div><dt>Approved by</dt><dd>{{ invoice.approver_name || '—' }}</dd></div>
                        <div><dt>LPO</dt><dd>{{ invoice.lpo_number || '—' }}</dd></div>
                        <div><dt>Landlord PIN</dt><dd>{{ invoice.payer_kra_pin || '—' }}</dd></div>
                        <div><dt>Completed</dt><dd>{{ date(invoice.job_completed_on) }}</dd></div>
                    </dl>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <h3 style="margin-top:0;">Lines</h3>
                    <table class="data-table">
                        <thead><tr><th>Reference</th><th>Description</th><th>Requested by</th><th>Approved by</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            <tr v-for="l in invoice.lines || []" :key="l.id">
                                <td :class="l.kind === 'variation' && 'vo'">{{ l.reference || '—' }}</td>
                                <td>{{ l.description }}</td>
                                <td>{{ l.requested_by || '—' }}</td>
                                <td>{{ l.approved_by || '—' }}</td>
                                <td class="num">{{ money(l.amount_ex_vat) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <table class="totals">
                        <tr><td>Subtotal (excl. VAT)</td><td class="num">{{ money(invoice.subtotal_ex_vat) }}</td></tr>
                        <tr><td>VAT @ {{ pct(invoice.vat_rate) }}%</td><td class="num">{{ money(invoice.vat_amount) }}</td></tr>
                        <tr class="grand"><td>Total</td><td class="num">{{ money(invoice.total_inc_vat) }}</td></tr>
                        <tr class="wh"><td>Less WHVAT @ {{ pct(invoice.whvat_rate) }}%</td><td class="num">({{ money(invoice.whvat_amount) }})</td></tr>
                        <tr class="wh"><td>Less WHT @ {{ pct(invoice.wht_rate) }}%</td><td class="num">({{ money(invoice.wht_amount) }})</td></tr>
                        <tr class="grand"><td>Net payable</td><td class="num">{{ money(invoice.net_expected) }}</td></tr>
                        <tr><td>Received</td><td class="num">{{ money(invoice.paid_amount) }}</td></tr>
                        <tr><td>Certified</td><td class="num">{{ money(invoice.certified_amount) }}</td></tr>
                        <tr class="grand"><td>Outstanding</td><td class="num" :class="outstanding > 0 ? 'bad' : 'good'">{{ money(outstanding) }}</td></tr>
                    </table>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <h3 style="margin-top:0;">eTIMS receipt</h3>
                    <p v-if="invoice.etims_receipt_number" class="ok">
                        <i class="fas fa-check-circle"></i> {{ invoice.etims_receipt_number }} attached — this is a tax invoice.
                    </p>
                    <template v-else>
                        <p class="note">Attach the eTIMS receipt once the hard-copy tax invoice has been raised.</p>
                        <div class="row">
                            <div class="field"><label>Receipt number</label><input v-model="etims.etims_receipt_number" type="text" maxlength="60" /></div>
                            <div class="field"><label>Receipt file</label><input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => etims.etims_receipt = e.target.files[0]" /></div>
                        </div>
                        <button class="btn btn-primary" :disabled="busy || !etims.etims_receipt_number || !etims.etims_receipt" @click="attach">Attach</button>
                    </template>
                </div>
            </section>

            <section class="main-panel" v-if="Number(invoice.paid_amount) === 0 && invoice.status !== 'void'">
                <div class="panel-card full-width danger-card">
                    <h3 style="margin-top:0;">Void this invoice</h3>
                    <p class="note">Raised in error? Voiding restores the float as an adjustment carrying your reason.</p>
                    <textarea v-model="voidReason" rows="2" maxlength="500" placeholder="Why this invoice should not stand."></textarea>
                    <button class="btn btn-danger" :disabled="busy || voidReason.length < 10" @click="voidInvoice">Void invoice</button>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    invoice: { type: Object, required: true },
    issuer: { type: Object, required: true },
    outstanding: { type: Number, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const busy = ref(false)
const voidReason = ref('')
const etims = reactive({ etims_receipt_number: '', etims_receipt: null })

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const pct = (v) => String(Number(v || 0)).replace(/\.00$/, '')
const date = (d) => d ? new Date(d).toLocaleDateString() : '—'

const attach = () => {
    busy.value = true
    router.post(`/admin/corporate-invoices/${props.invoice.id}/etims`, { ...etims },
        { forceFormData: true, onFinish: () => { busy.value = false } })
}
const voidInvoice = () => {
    if (!confirm('Void this invoice and restore the float?')) return
    busy.value = true
    router.post(`/admin/corporate-invoices/${props.invoice.id}/void`, { reason: voidReason.value },
        { onFinish: () => { busy.value = false } })
}
</script>

<style scoped>
.back { font-size: .8rem; color: #6b7280; text-decoration: none; }
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.note { color: #6b7280; font-size: .8rem; }
.ok { color: #15803d; font-size: .85rem; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .8rem; margin: 0; }
.facts dt { color: #6b7280; font-size: .72rem; }
.facts dd { margin: .1rem 0 0; font-weight: 600; font-size: .87rem; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.vo { color: #1d4ed8; }
.totals { width: 340px; margin-left: auto; margin-top: 1rem; border-collapse: collapse; font-size: .85rem; }
.totals td { padding: .35rem .5rem; border-bottom: 1px solid #f3f4f6; }
.totals .grand td { font-weight: 700; border-top: 2px solid #1e3a8a; }
.totals .wh td { color: #b45309; }
.bad { color: #b91c1c; } .good { color: #15803d; }
.row { display: flex; gap: .8rem; flex-wrap: wrap; }
.field { display: flex; flex-direction: column; flex: 1; min-width: 180px; margin-bottom: .7rem; }
.field label { font-size: .78rem; font-weight: 600; margin-bottom: .25rem; }
.field input { padding: .45rem .6rem; border: 1px solid #d1d5db; border-radius: 6px; }
.danger-card { border-left: 4px solid #dc2626; }
.danger-card textarea { width: 100%; padding: .5rem; border: 1px solid #d1d5db; border-radius: 6px; margin: .5rem 0; }
.btn-danger { background: #dc2626; color: #fff; border: none; padding: .5rem 1rem; border-radius: 6px; cursor: pointer; }
</style>
