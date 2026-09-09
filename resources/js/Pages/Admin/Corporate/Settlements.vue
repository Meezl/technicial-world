<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="corporate-settlements" />
        <main class="main-content">
            <header class="main-header">
                <div>
                    <h1>Corporate Payments</h1>
                    <p class="sub">Payments and withholding certificates awaiting your confirmation.</p>
                </div>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <h3 style="margin-top:0;">Payments</h3>
                    <p class="note">
                        Validating applies the payment to the ticked invoices and tops the float back up by
                        what actually arrived. The withheld portion follows as certificates.
                    </p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Client</th><th>Method</th><th>Reference</th><th class="num">Received</th>
                                <th class="num">Allocated</th><th>Covers</th><th>Proof</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="s in settlements" :key="s.id" :class="s.status === 'submitted' && 'row-alert'">
                                    <td>{{ s.organisation?.name }}</td>
                                    <td>{{ methods[s.method] || s.method }}</td>
                                    <td>{{ s.reference || '—' }}</td>
                                    <td class="num">{{ money(s.gross_amount) }}</td>
                                    <td class="num" :class="mismatch(s) && 'warn'">{{ money(s.allocated_total) }}</td>
                                    <td><small>{{ (s.allocations || []).map(a => a.invoice?.invoice_number).filter(Boolean).join(', ') || '—' }}</small></td>
                                    <td><a v-if="s.proof_path" :href="`/storage/${s.proof_path}`" target="_blank" class="link">View</a><span v-else>—</span></td>
                                    <td><span :class="['pill', pillClass(s.status)]">{{ s.status }}</span></td>
                                    <td>
                                        <template v-if="s.status === 'submitted'">
                                            <button class="link" @click="validateSettlement(s)">Validate</button>
                                            <button class="link bad" @click="rejectSettlement(s)">Reject</button>
                                        </template>
                                    </td>
                                </tr>
                                <tr v-if="!settlements.length"><td colspan="9" class="text-center">No payments recorded.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <h3 style="margin-top:0;">Withholding certificates</h3>
                    <p class="note">Validating tops the float up by the certificate amount and can close the job as fully paid.</p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Client</th><th>Invoice</th><th>Type</th><th>Number</th><th class="num">Amount</th><th>Document</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="c in certificates" :key="c.id" :class="c.status === 'submitted' && 'row-alert'">
                                    <td>{{ c.organisation?.name }}</td>
                                    <td>{{ c.invoice?.invoice_number || '—' }}</td>
                                    <td>{{ certificateTypes[c.type] || c.type }}</td>
                                    <td>{{ c.certificate_number || '—' }}</td>
                                    <td class="num">{{ money(c.amount) }}</td>
                                    <td><a v-if="c.document_path" :href="`/storage/${c.document_path}`" target="_blank" class="link">View</a><span v-else>—</span></td>
                                    <td><span :class="['pill', pillClass(c.status)]">{{ c.status }}</span></td>
                                    <td>
                                        <template v-if="c.status === 'submitted'">
                                            <button class="link" @click="validateCertificate(c)">Validate</button>
                                            <button class="link bad" @click="rejectCertificate(c)">Reject</button>
                                        </template>
                                    </td>
                                </tr>
                                <tr v-if="!certificates.length"><td colspan="8" class="text-center">No certificates recorded.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

defineProps({
    settlements: { type: Array, required: true },
    certificates: { type: Array, required: true },
    methods: { type: Object, required: true },
    certificateTypes: { type: Object, required: true },
    organisations: { type: Array, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const busy = ref(false)

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const pillClass = (s) => s === 'validated' ? 'pill-green' : s === 'rejected' ? 'pill-red' : 'pill-amber'
// Worth flagging rather than leaving the accountant to add up: the first
// question about any payment is whether it matches what it claims to cover.
const mismatch = (s) => Math.abs(Number(s.allocated_total || 0) - Number(s.gross_amount || 0)) > 0.01

const go = (url, payload = {}) => { busy.value = true; router.post(url, payload, { onFinish: () => { busy.value = false } }) }

const validateSettlement = (s) => confirm(`Validate ${money(s.gross_amount)} from ${s.organisation?.name}?`) && go(`/admin/corporate-settlements/${s.id}/validate`)
const rejectSettlement = (s) => { const r = prompt('Why is this payment being rejected?'); if (r && r.length >= 5) go(`/admin/corporate-settlements/${s.id}/reject`, { reason: r }) }
const validateCertificate = (c) => confirm(`Validate this ${c.type.toUpperCase()} certificate for ${money(c.amount)}?`) && go(`/admin/corporate-certificates/${c.id}/validate`)
const rejectCertificate = (c) => { const r = prompt('Why is this certificate being rejected?'); if (r && r.length >= 5) go(`/admin/corporate-certificates/${c.id}/reject`, { reason: r }) }
</script>

<style scoped>
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.note { color: #6b7280; font-size: .8rem; margin: 0 0 1rem; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.row-alert { background: #fffbeb; }
.warn { color: #b45309; font-weight: 600; }
.link { background: none; border: none; color: #2563eb; cursor: pointer; font-size: .8rem; padding: 0 .35rem; text-decoration: none; }
.link.bad { color: #dc2626; }
.pill { display: inline-block; padding: .12rem .55rem; border-radius: 999px; font-size: .72rem; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-red { background: #fee2e2; color: #991b1b; }
</style>
