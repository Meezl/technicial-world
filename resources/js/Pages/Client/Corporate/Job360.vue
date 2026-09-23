<template>
    <div class="corp-page">
        <Link href="/corporate/requests" class="back"><i class="fas fa-arrow-left"></i> Back to jobs</Link>

        <header class="head">
            <div>
                <h1>{{ quoteReference }}</h1>
                <p class="sub">
                    {{ request.property?.name }}<span v-if="request.property?.code"> ({{ request.property.code }})</span>
                    · {{ request.service_category?.name }}
                    · raised by {{ request.raised_by_member?.display_name || request.raised_by_member?.user?.name || '—' }}
                </p>
            </div>
            <span :class="['pill', 'pill-lg', statusClass]">{{ statusLabel }}</span>
        </header>

        <div class="grid">
            <!-- The work -->
            <section class="card">
                <h3>The work</h3>
                <p class="desc">{{ request.description }}</p>
                <dl class="facts">
                    <div><dt>Where</dt><dd>{{ request.location || '—' }}</dd></div>
                    <div><dt>Quotation</dt><dd>{{ money(request.quote_amount) }}</dd></div>
                    <div><dt>Approved</dt><dd>{{ money(request.approved_quote_amount) }}</dd></div>
                    <div><dt>Completed</dt><dd>{{ date(request.completed_date) }}</dd></div>
                </dl>
            </section>

            <!-- Approvals -->
            <section class="card">
                <h3>Approval trail</h3>
                <ul class="trail">
                    <li v-for="a in request.corporate_approvals || []" :key="a.id" :class="`is-${a.status}`">
                        <span class="dot"></span>
                        <div>
                            <strong>{{ a.stage === 'verify' ? 'Verification' : 'Approval' }}</strong>
                            <span class="tag">{{ a.status }}</span>
                            <div v-if="a.decided_by" class="muted small">
                                {{ a.member?.display_name || a.decided_by?.name }} · {{ dateTime(a.decided_at) }}
                            </div>
                            <div v-if="a.comments" class="quote">“{{ a.comments }}”</div>
                            <div v-if="a.lpo_number" class="muted small">LPO {{ a.lpo_number }} · {{ a.signatory_name }}</div>
                        </div>
                    </li>
                    <li v-if="!(request.corporate_approvals || []).length" class="muted small">No approvals recorded.</li>
                </ul>
            </section>

            <!-- Variations -->
            <section class="card">
                <h3>Variations</h3>
                <table v-if="(request.variation_orders || []).length">
                    <thead><tr><th>Reference</th><th>Reason</th><th class="num">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        <tr v-for="v in request.variation_orders" :key="v.id">
                            <td>{{ v.vo_number }}</td>
                            <td><small>{{ v.reason }}</small></td>
                            <td class="num">{{ money(v.net_amount) }}</td>
                            <td><span :class="['pill', v.status === 'approved' ? 'pill-green' : v.status === 'declined' ? 'pill-red' : 'pill-amber']">{{ v.status }}</span></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="muted small">None raised.</p>
            </section>

            <!-- Reports -->
            <section class="card">
                <h3>Progress reports</h3>
                <ul class="plain">
                    <li v-for="r in request.progress_reports || []" :key="r.id">
                        <strong>{{ date(r.report_date) }}</strong> — {{ r.percent_complete }}%
                        <div v-if="r.client_visible_notes" class="muted small">{{ r.client_visible_notes }}</div>
                    </li>
                    <li v-if="!(request.progress_reports || []).length" class="muted small">No validated reports yet.</li>
                </ul>
            </section>

            <!-- Money -->
            <section class="card wide">
                <h3>Invoice &amp; payment</h3>
                <template v-if="invoice">
                    <dl class="facts">
                        <div><dt>Invoice</dt><dd>{{ invoice.invoice_number }}</dd></div>
                        <div><dt>Status</dt><dd>{{ invoice.status }}</dd></div>
                        <div><dt>Total</dt><dd>{{ money(invoice.total_inc_vat) }}</dd></div>
                        <div><dt>Net payable</dt><dd>{{ money(invoice.net_expected) }}</dd></div>
                        <div><dt>Received</dt><dd>{{ money(invoice.paid_amount) }}</dd></div>
                        <div><dt>Certified</dt><dd>{{ money(invoice.certified_amount) }}</dd></div>
                        <div><dt>Outstanding</dt><dd :class="outstanding > 0 ? 'bad' : 'good'">{{ money(outstanding) }}</dd></div>
                        <div><dt>eTIMS</dt><dd>{{ invoice.etims_receipt_number || '—' }}</dd></div>
                    </dl>

                    <h4>Lines</h4>
                    <table>
                        <thead><tr><th>Reference</th><th>Description</th><th>Requested by</th><th>Approved by</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            <tr v-for="l in invoice.lines || []" :key="l.id">
                                <td>{{ l.reference || '—' }}</td>
                                <td><small>{{ l.description }}</small></td>
                                <td><small>{{ l.requested_by || '—' }}</small></td>
                                <td><small>{{ l.approved_by || '—' }}</small></td>
                                <td class="num">{{ money(l.amount_ex_vat) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4>Payments received</h4>
                    <ul class="plain">
                        <li v-for="a in invoice.allocations || []" :key="a.id">
                            {{ money(a.amount) }} — {{ a.settlement?.reference || a.settlement?.method }}
                            <span :class="['pill', a.settlement?.status === 'validated' ? 'pill-green' : 'pill-amber']">
                                {{ a.settlement?.status }}
                            </span>
                        </li>
                        <li v-if="!(invoice.allocations || []).length" class="muted small">Nothing received yet.</li>
                    </ul>

                    <h4>Withholding certificates</h4>
                    <ul class="plain">
                        <li v-for="c in invoice.tax_certificates || []" :key="c.id">
                            {{ c.type?.toUpperCase() }} {{ money(c.amount) }} — {{ c.certificate_number || 'no number' }}
                            <span :class="['pill', c.status === 'validated' ? 'pill-green' : 'pill-amber']">{{ c.status }}</span>
                        </li>
                        <li v-if="!(invoice.tax_certificates || []).length" class="muted small">
                            None yet — the job stays open until these are in.
                        </li>
                    </ul>
                </template>
                <p v-else class="muted small">
                    Not yet invoiced. An invoice is raised when the job closes and sent when the deposit
                    reaches its top-up threshold.
                </p>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    request: { type: Object, required: true },
    quoteReference: { type: String, required: true },
    invoice: { type: Object, default: null },
})

const money = (v) => 'KES ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const date = (d) => d ? new Date(d).toLocaleDateString() : '—'
const dateTime = (d) => d ? new Date(d).toLocaleString() : '—'

const outstanding = computed(() => props.invoice
    ? Number(props.invoice.total_inc_vat || 0) - Number(props.invoice.paid_amount || 0) - Number(props.invoice.certified_amount || 0)
    : 0)

const statusLabel = computed(() => {
    if (props.invoice?.status === 'settled') return 'Closed & fully paid'
    if (props.request.status === 'closed') return 'Closed'
    if (props.request.rfq_status === 'rejected') return 'Returned to TW'
    return props.request.status?.replace(/_/g, ' ') || '—'
})
const statusClass = computed(() =>
    props.invoice?.status === 'settled' ? 'pill-green'
        : props.request.status === 'closed' ? 'pill-blue' : 'pill-amber')
</script>

<style scoped>
.corp-page { padding: 1.5rem; max-width: 1150px; margin: 0 auto; }
.back { font-size: .8rem; color: #6b7280; text-decoration: none; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin: .5rem 0 1.25rem; }
h1 { margin: 0; font-size: 1.4rem; }
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1rem; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.1rem; }
.card.wide { grid-column: 1 / -1; }
.card h3 { margin: 0 0 .7rem; font-size: .95rem; }
.card h4 { margin: 1rem 0 .4rem; font-size: .82rem; color: #6b7280; }
.desc { margin: 0 0 .8rem; font-size: .87rem; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .7rem; margin: 0; }
.facts dt { color: #6b7280; font-size: .72rem; }
.facts dd { margin: .1rem 0 0; font-size: .87rem; font-weight: 600; }
table { width: 100%; border-collapse: collapse; font-size: .82rem; }
th { text-align: left; padding: .4rem .5rem; color: #6b7280; font-weight: 600; border-bottom: 1px solid #e5e7eb; }
td { padding: .45rem .5rem; border-bottom: 1px solid #f3f4f6; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.plain { list-style: none; padding: 0; margin: 0; font-size: .84rem; }
.plain li { padding: .35rem 0; border-bottom: 1px solid #f3f4f6; }
.trail { list-style: none; padding: 0; margin: 0; }
.trail li { display: flex; gap: .7rem; padding: .5rem 0; border-bottom: 1px solid #f3f4f6; font-size: .84rem; }
.dot { width: 9px; height: 9px; border-radius: 50%; margin-top: .38rem; flex: none; background: #d1d5db; }
.is-approved .dot { background: #16a34a; }
.is-declined .dot { background: #dc2626; }
.is-pending .dot { background: #f59e0b; }
.is-superseded { opacity: .6; }
.tag { font-size: .7rem; margin-left: .4rem; text-transform: uppercase; color: #6b7280; }
.quote { font-style: italic; color: #4b5563; margin-top: .25rem; }
.muted { color: #6b7280; }
.small { font-size: .78rem; }
.bad { color: #b91c1c; }
.good { color: #15803d; }
.pill { display: inline-block; padding: .12rem .55rem; border-radius: 999px; font-size: .72rem; }
.pill-lg { padding: .3rem .8rem; font-size: .8rem; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-red { background: #fee2e2; color: #991b1b; }
</style>
