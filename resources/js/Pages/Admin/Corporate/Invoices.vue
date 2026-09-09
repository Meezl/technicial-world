<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="corporate-invoices" />
        <main class="main-content">
            <header class="main-header">
                <div>
                    <h1>Corporate Invoicing</h1>
                    <p class="sub">In-trays, dispatch, and what is still owed.</p>
                </div>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <h3 style="margin-top:0;">Accounts</h3>
                    <p class="note">
                        An account whose float has fallen through its threshold is flagged. Dispatching
                        sends the proformas and is the cue to raise the hard-copy tax invoices — nothing
                        else in the system will say so.
                    </p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Client</th><th class="num">In-tray</th><th class="num">Held value</th>
                                    <th class="num">Float available</th><th class="num">Threshold</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="o in organisations" :key="o.id" :class="o.should_dispatch && 'row-alert'">
                                    <td>
                                        <Link :href="`/admin/corporate-invoices/organisation/${o.id}`"><strong>{{ o.name }}</strong></Link>
                                        <span v-if="o.should_dispatch" class="pill pill-red">Threshold reached</span>
                                    </td>
                                    <td class="num">{{ o.held_count }}</td>
                                    <td class="num">{{ money(o.held_total) }}</td>
                                    <td class="num" :class="o.summary?.below_threshold && 'bad'">
                                        {{ o.summary ? money(o.summary.available) : '—' }}
                                    </td>
                                    <td class="num">{{ o.summary ? money(o.summary.threshold) : '—' }}</td>
                                    <td><Link :href="`/admin/corporate-invoices/organisation/${o.id}`" class="link">Open</Link></td>
                                </tr>
                                <tr v-if="!organisations.length"><td colspan="6" class="text-center">No corporate accounts with billing activity.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <h3 style="margin-top:0;">Outstanding invoices</h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr><th>Invoice</th><th>Client</th><th>Request</th><th class="num">Total</th>
                                    <th class="num">Received</th><th class="num">Certified</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in openInvoices" :key="i.id">
                                    <td><Link :href="`/admin/corporate-invoices/${i.id}`"><strong>{{ i.invoice_number }}</strong></Link></td>
                                    <td>{{ i.organisation?.name }}</td>
                                    <td>{{ i.service_request?.request_id || '—' }}</td>
                                    <td class="num">{{ money(i.total_inc_vat) }}</td>
                                    <td class="num">{{ money(i.paid_amount) }}</td>
                                    <td class="num">{{ money(i.certified_amount) }}</td>
                                    <td><span class="pill">{{ i.status.replace('_', ' ') }}</span></td>
                                </tr>
                                <tr v-if="!openInvoices.length"><td colspan="7" class="text-center">Nothing outstanding.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

defineProps({
    organisations: { type: Array, required: true },
    openInvoices: { type: Array, required: true },
    modes: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<style scoped>
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.note { color: #6b7280; font-size: .8rem; margin: 0 0 1rem; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.bad { color: #b91c1c; font-weight: 600; }
.row-alert { background: #fffbeb; }
.link { color: #2563eb; text-decoration: none; }
.pill { display: inline-block; padding: .12rem .55rem; border-radius: 999px; font-size: .72rem; background: #f3f4f6; color: #4b5563; }
.pill-red { background: #fee2e2; color: #991b1b; margin-left: .4rem; }
</style>
