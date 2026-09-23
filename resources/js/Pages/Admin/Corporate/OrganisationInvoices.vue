<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="corporate-invoices" />
        <main class="main-content">
            <header class="main-header">
                <div>
                    <Link href="/admin/corporate-invoices" class="back"><i class="fas fa-arrow-left"></i> Corporate Invoicing</Link>
                    <h1>{{ organisation.name }}</h1>
                </div>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <section class="main-panel">
                <div class="panel-card full-width" :class="shouldDispatch ? 'is-alert' : ''">
                    <div class="tiles">
                        <div class="tile"><small>In-tray</small><div class="big">{{ held.length }}</div></div>
                        <div class="tile"><small>Held value</small><div class="mid">{{ money(heldTotal) }}</div></div>
                        <div class="tile" v-if="summary"><small>Float available</small>
                            <div class="mid" :class="summary.below_threshold && 'bad'">{{ money(summary.available) }}</div></div>
                        <div class="tile" v-if="summary"><small>Threshold</small><div class="mid">{{ money(summary.threshold) }}</div></div>
                    </div>
                    <p v-if="shouldDispatch" class="banner">
                        <i class="fas fa-exclamation-triangle"></i>
                        The float has reached its threshold. Dispatch the in-tray, then raise the hard-copy
                        tax invoices and attach the eTIMS receipts.
                    </p>
                    <div class="dispatch" v-if="held.length">
                        <select v-model="mode"><option v-for="(l,k) in modes" :key="k" :value="k">{{ l }}</option></select>
                        <button class="btn btn-primary" :disabled="busy" @click="dispatchNow">
                            {{ busy ? 'Sending…' : `Dispatch ${held.length} invoice(s)` }}
                        </button>
                    </div>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <h3 style="margin-top:0;">In-tray</h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Invoice</th><th>Request</th><th>Property</th><th class="num">Total</th><th class="num">Net payable</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="i in held" :key="i.id">
                                    <td><strong>{{ i.invoice_number }}</strong></td>
                                    <td>{{ i.service_request?.request_id || '—' }}</td>
                                    <td>{{ i.property_name || '—' }}</td>
                                    <td class="num">{{ money(i.total_inc_vat) }}</td>
                                    <td class="num">{{ money(i.net_expected) }}</td>
                                    <td><Link :href="`/admin/corporate-invoices/${i.id}`" class="link">Open</Link></td>
                                </tr>
                                <tr v-if="!held.length"><td colspan="6" class="text-center">In-tray is empty.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <h3 style="margin-top:0;">Dispatched batches</h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Reference</th><th>Sent</th><th>Mode</th><th class="num">Invoices</th><th class="num">Total</th><th class="num">Net expected</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="b in batches" :key="b.id">
                                    <td><strong>{{ b.reference }}</strong></td>
                                    <td>{{ date(b.dispatched_at) }}</td>
                                    <td><small>{{ modes[b.output_mode] || b.output_mode }}</small></td>
                                    <td class="num">{{ b.invoices_count }}</td>
                                    <td class="num">{{ money(b.total_inc_vat) }}</td>
                                    <td class="num">{{ money(b.net_expected) }}</td>
                                    <td><a :href="`/admin/corporate-batches/${b.id}/pdf`" class="link">PDF</a></td>
                                </tr>
                                <tr v-if="!batches.length"><td colspan="7" class="text-center">Nothing dispatched yet.</td></tr>
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
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    organisation: { type: Object, required: true },
    summary: { type: Object, default: null },
    shouldDispatch: { type: Boolean, default: false },
    held: { type: Array, required: true },
    batches: { type: Array, required: true },
    open: { type: Array, required: true },
    modes: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const busy = ref(false)
const mode = ref(Object.keys(props.modes)[0])

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const date = (d) => d ? new Date(d).toLocaleDateString() : '—'
const heldTotal = computed(() => props.held.reduce((s, i) => s + Number(i.total_inc_vat || 0), 0))

const dispatchNow = () => {
    busy.value = true
    router.post(`/admin/corporate-invoices/organisation/${props.organisation.id}/dispatch`, { mode: mode.value },
        { onFinish: () => { busy.value = false } })
}
</script>

<style scoped>
.back { font-size: .8rem; color: #6b7280; text-decoration: none; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; }
.tile small { color: #6b7280; font-size: .72rem; display: block; }
.big { font-size: 1.5rem; font-weight: 700; }
.mid { font-size: 1.1rem; font-weight: 600; }
.bad { color: #b91c1c; }
.is-alert { border-left: 4px solid #f59e0b; }
.banner { margin: 1rem 0 0; padding: .7rem .9rem; border-radius: 8px; background: #fffbeb; color: #92400e; font-size: .82rem; }
.dispatch { display: flex; gap: .6rem; align-items: center; margin-top: 1rem; }
.dispatch select { padding: .45rem .6rem; border: 1px solid #d1d5db; border-radius: 6px; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.link { color: #2563eb; text-decoration: none; }
</style>
