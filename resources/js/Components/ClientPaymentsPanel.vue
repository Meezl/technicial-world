<template>
    <div class="cp">
        <!-- Figures -->
        <div class="cp-figs">
            <div><span>Job value</span><strong>{{ money(billing?.contract_value) }}</strong></div>
            <div><span>Paid</span><strong class="ok">{{ money(billing?.settled) }}</strong></div>
            <div :class="{ warn: awaiting > 0.01 }">
                <span>Waiting for client</span><strong>{{ money(awaiting) }}</strong>
            </div>
            <div><span>Not billed yet</span><strong>{{ money(notBilled) }}</strong></div>
            <div v-if="depositRequired > 0" :class="{ warn: !depositPaid }">
                <span>Deposit</span>
                <strong>{{ money(depositRequired) }} · {{ depositPaid ? 'paid' : 'not fully paid' }}</strong>
            </div>
        </div>

        <!-- What needs doing -->
        <div v-if="reopened.length" class="cp-banner warn">
            <i class="fas fa-undo"></i>
            <div>
                <strong>{{ reopened.length === 1 ? 'A bill was reopened' : `${reopened.length} bills were reopened` }} after an M-Pesa payment was reversed.</strong>
                <p>Send {{ reopened.length === 1 ? 'it' : 'them' }} to the client so they know {{ money(reopened.reduce((s, b) => s + b.amount, 0)) }} is still due.</p>
            </div>
        </div>
        <div v-else-if="awaiting > 0.01" class="cp-banner">
            <i class="fas fa-hourglass-half"></i>
            <div><strong>{{ money(awaiting) }} is waiting for the client.</strong>
                <p>Send a reminder from the bill below if they have not seen it.</p></div>
        </div>
        <div v-else-if="notBilled > 0.01 && !blocker" class="cp-banner">
            <i class="fas fa-file-invoice"></i>
            <div><strong>Nothing is waiting for payment, and {{ money(notBilled) }} has not been billed yet.</strong>
                <p>Request a payment when the client should pay the next part.</p></div>
        </div>

        <!-- Bills -->
        <div class="cp-head">
            <strong>Bills <span class="cp-count">{{ visibleBills.length }}</span></strong>
            <div class="cp-head-actions">
                <label v-if="cancelledCount" class="cp-toggle">
                    <input type="checkbox" v-model="showCancelled" /> Show cancelled ({{ cancelledCount }})
                </label>
                <button v-if="!blocker" type="button" class="cp-btn cp-btn-primary" @click="toggleForm">
                    <i class="fas" :class="showForm ? 'fa-times' : 'fa-paper-plane'"></i>
                    {{ showForm ? 'Cancel' : 'Request payment' }}
                </button>
            </div>
        </div>

        <p v-if="blocker" class="cp-muted cp-small cp-blocker"><i class="fas fa-info-circle"></i> {{ blocker }}</p>

        <!-- Request form -->
        <form v-if="showForm" class="cp-form" @submit.prevent="submit">
            <div class="cp-quick">
                <span class="cp-muted cp-small">Quick fill:</span>
                <button v-if="depositOutstanding > 0.01 && !depositBillWaiting" type="button" @click="fill(depositOutstanding, true)">Deposit still owed · {{ money(depositOutstanding) }}</button>
                <button v-if="notBilled > 0.01" type="button" @click="fill(notBilled)">Full balance · {{ money(notBilled) }}</button>
                <button v-if="notBilled > 0.01" type="button" @click="fill(Math.round(notBilled / 2))">Half · {{ money(Math.round(notBilled / 2)) }}</button>
            </div>
            <div class="cp-row">
                <label class="cp-field">
                    <span>Amount (KES) <em>*</em></span>
                    <input v-model.number="form.amount" type="number" min="1" step="0.01" :max="notBilled || undefined" required />
                    <small class="cp-muted">Up to {{ money(notBilled) }} can still be billed on this job.</small>
                </label>
                <label class="cp-field cp-grow">
                    <span>Note to the client</span>
                    <input v-model="form.notes" type="text" maxlength="500" placeholder="e.g. Balance on completion of works" />
                </label>
            </div>
            <label v-if="!job.down_payment_requested" class="cp-check">
                <input type="checkbox" v-model="form.is_down_payment" /> This is the deposit
            </label>
            <p v-if="formError" class="cp-error">{{ formError }}</p>
            <div class="cp-form-foot">
                <span class="cp-muted cp-small">The client gets it by email and in their portal, and is reminded every 12 hours until it is paid.</span>
                <button type="submit" class="cp-btn cp-btn-primary" :disabled="submitting || !(form.amount > 0)">
                    {{ submitting ? 'Sending…' : `Send ${money(form.amount || 0)} request` }}
                </button>
            </div>
        </form>

        <div v-if="!visibleBills.length" class="cp-empty">
            <i class="fas fa-receipt"></i>
            <span>No bills have been raised to the client on this job yet.</span>
        </div>
        <ul v-else class="cp-bills">
            <li v-for="b in visibleBills" :key="b.id" :class="['cp-bill', 'is-' + b.status]">
                <div class="cp-bill-main">
                    <div class="cp-bill-top">
                        <span :class="['cp-kind', kindClass(b.label)]">{{ b.label }}</span>
                        <code>{{ b.code }}</code>
                        <span v-if="b.reopened" class="cp-tag warn">Reopened</span>
                    </div>
                    <div class="cp-muted cp-small">
                        <template v-if="b.status === 'paid'">
                            Paid {{ date(b.paid_at) }}<template v-if="b.method"> · {{ methodLabel(b.method) }}</template><template v-if="b.receipt"> · {{ b.receipt }}</template>
                        </template>
                        <template v-else-if="b.status === 'pending'">
                            Raised {{ date(b.raised_at) }}<template v-if="b.requested_by"> by {{ b.requested_by }}</template><template v-if="b.last_sent_at"> · last sent {{ date(b.last_sent_at) }}</template>
                        </template>
                        <template v-else>Cancelled · raised {{ date(b.raised_at) }}</template>
                    </div>
                </div>
                <div class="cp-bill-amt">
                    <strong>{{ money(b.amount) }}</strong>
                    <span :class="['cp-status', b.status]">{{ statusLabel(b.status) }}</span>
                </div>
                <div class="cp-bill-act">
                    <button v-if="b.status === 'pending'" type="button" class="cp-btn cp-btn-ghost" :disabled="sending === b.id" @click="resend(b)">
                        <i class="fas fa-paper-plane"></i> {{ sending === b.id ? 'Sending…' : 'Send to client' }}
                    </button>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps({
    job: { type: Object, required: true },
    bills: { type: Array, default: () => [] },
    billing: { type: Object, default: null },
    gating: { type: Object, default: null },
    blocker: { type: String, default: null },
})

const fmt = new Intl.NumberFormat('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const money = (v) => 'KES ' + fmt.format(Number(v || 0))
const date = (iso) => iso ? new Date(iso).toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'
const statusLabel = (s) => ({ pending: 'Waiting', paid: 'Paid', cancelled: 'Cancelled' })[s] || s
const methodLabel = (m) => ({ mpesa: 'M-Pesa', cheque: 'Cheque', cash: 'Cash', bank_deposit: 'Bank deposit' })[m] || m
const kindClass = (l) => l === 'Deposit' ? 'k-dep' : l === 'Balance' ? 'k-bal' : l === 'Attendance fee' ? 'k-fee' : l === 'Variation' ? 'k-var' : 'k-ms'

const awaiting = computed(() => Number(props.billing?.awaiting_payment || 0))
const notBilled = computed(() => Math.max(0, Number(props.billing?.billable_remaining || 0)))
const depositRequired = computed(() => Number(props.gating?.deposit_required || 0))
const depositPaid = computed(() => Number(props.gating?.deposit_paid || 0) + 0.001 >= depositRequired.value)
const depositOutstanding = computed(() => Math.max(0, depositRequired.value - Number(props.gating?.deposit_paid || 0)))
const depositBillWaiting = computed(() => props.bills.some(b => b.status === 'pending' && b.label === 'Deposit'))

const showCancelled = ref(false)
const cancelledCount = computed(() => props.bills.filter(b => b.status === 'cancelled').length)
const visibleBills = computed(() => props.bills.filter(b => showCancelled.value || b.status !== 'cancelled'))
const reopened = computed(() => props.bills.filter(b => b.reopened))

// ---- Resend ----
const sending = ref(null)
const resend = (b) => {
    if (!confirm(`Send ${b.code} (${money(b.amount)}) to the client again?`)) return
    sending.value = b.id
    router.post(`/admin/payments/${b.id}/resend`, {}, {
        preserveScroll: true,
        onFinish: () => { sending.value = null },
    })
}

// ---- Request payment ----
const showForm = ref(false)
const submitting = ref(false)
const formError = ref('')
const form = reactive({ amount: null, notes: '', is_down_payment: false })

const toggleForm = () => {
    showForm.value = !showForm.value
    formError.value = ''
    if (showForm.value && !form.amount) {
        if (depositOutstanding.value > 0.01 && !depositBillWaiting.value) fill(depositOutstanding.value, true)
        else if (notBilled.value > 0.01) fill(notBilled.value)
    }
}
const fill = (amount, isDeposit = false) => {
    form.amount = Math.round(Math.min(amount, notBilled.value || amount) * 100) / 100
    form.is_down_payment = isDeposit && !props.job.down_payment_requested
    if (isDeposit && !form.notes) form.notes = 'Deposit to start the work'
}

const submit = async () => {
    formError.value = ''
    submitting.value = true
    try {
        await axios.post(`/admin/rfq/${props.job.id}/request-payment`, {
            amount: form.amount,
            notes: form.notes || null,
            is_down_payment: form.is_down_payment,
        })
        showForm.value = false
        form.amount = null
        form.notes = ''
        form.is_down_payment = false
        router.reload({ only: ['clientBills', 'billingSummary', 'gating', 'job'], preserveScroll: true })
    } catch (e) {
        formError.value = e.response?.data?.error || e.response?.data?.message || 'The payment request could not be sent.'
    } finally {
        submitting.value = false
    }
}
</script>

<style scoped>
.cp { --navy:#0f2a44; --muted:#64748b; --line:#e5e7eb; --soft:#f8fafc; --green:#15803d; --amber:#b45309; display:flex; flex-direction:column; gap:.85rem; }
.cp-muted { color: var(--muted); }
.cp-small { font-size: .8rem; }
.cp-figs { display:grid; grid-template-columns: repeat(auto-fit, minmax(115px, 1fr)); gap:.5rem; }
.cp-figs > div { display:flex; flex-direction:column; gap:.15rem; padding:.65rem .8rem; background: var(--soft); border:1px solid var(--line); border-radius:10px; }
.cp-figs span { font-size:.72rem; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color: var(--muted); }
.cp-figs strong { font-size:1rem; font-variant-numeric: tabular-nums; }
.cp-figs strong.ok { color: var(--green); }
.cp-figs .warn { background:#fffbeb; border-color:#fcd34d; }
.cp-figs .warn strong { color: var(--amber); }
.cp-banner { display:flex; gap:.7rem; align-items:flex-start; padding:.75rem .9rem; border-radius:10px; background:#eff6ff; border:1px solid #bfdbfe; font-size:.88rem; }
.cp-banner.warn { background:#fffbeb; border-color:#fcd34d; }
.cp-banner i { margin-top:.2rem; color:#2563eb; }
.cp-banner.warn i { color: var(--amber); }
.cp-banner p { margin:.15rem 0 0; color:#475569; font-size:.82rem; }
.cp-head { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; }
.cp-head-actions { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
.cp-count { font-size:.72rem; background:#f1f5f9; color: var(--muted); padding:.05rem .45rem; border-radius:999px; margin-left:.25rem; }
.cp-toggle { font-size:.8rem; color: var(--muted); display:flex; gap:.35rem; align-items:center; cursor:pointer; }
.cp-blocker { margin:0; }
.cp-btn { display:inline-flex; align-items:center; gap:.4rem; border-radius:8px; padding:.45rem .85rem; font-size:.82rem; font-weight:600; border:1px solid transparent; cursor:pointer; white-space:nowrap; }
.cp-btn:disabled { opacity:.55; cursor:not-allowed; }
.cp-btn-primary { background: var(--navy); color:#fff; }
.cp-btn-ghost { background:#fff; border-color: var(--line); color: var(--navy); }
.cp-btn-ghost:hover:not(:disabled) { background: var(--soft); }
.cp-form { border:1px solid #c7d2fe; background:#f8faff; border-radius:10px; padding:.85rem; display:flex; flex-direction:column; gap:.7rem; }
.cp-quick { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.cp-quick button { border:1px solid #c7d2fe; background:#fff; border-radius:999px; padding:.25rem .7rem; font-size:.78rem; cursor:pointer; color: var(--navy); }
.cp-quick button:hover { background:#eef2ff; }
.cp-row { display:flex; gap:.75rem; flex-wrap:wrap; }
.cp-field { display:flex; flex-direction:column; gap:.25rem; font-size:.8rem; font-weight:600; min-width: 180px; }
.cp-field em { color:#dc2626; font-style:normal; }
.cp-field small { font-weight:400; }
.cp-grow { flex:1; }
.cp-field input { padding:.5rem .6rem; border:1px solid var(--line); border-radius:8px; font: inherit; font-weight:400; font-size:.88rem; }
.cp-check { display:flex; gap:.4rem; align-items:center; font-size:.85rem; }
.cp-error { margin:0; color:#b91c1c; font-size:.85rem; }
.cp-form-foot { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; }
.cp-empty { display:flex; gap:.5rem; align-items:center; padding:1rem; color: var(--muted); border:1px dashed var(--line); border-radius:10px; font-size:.88rem; }
.cp-bills { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; border:1px solid var(--line); border-radius:10px; overflow:hidden; }
.cp-bill { display:grid; grid-template-columns: 1fr auto auto; gap:.9rem; align-items:center; padding:.7rem .9rem; border-bottom:1px solid #f1f5f9; }
.cp-bill:last-child { border-bottom:0; }
.cp-bill.is-pending { background:#fffdf5; }
.cp-bill.is-cancelled { opacity:.6; }
.cp-bill-top { display:flex; gap:.45rem; align-items:center; flex-wrap:wrap; margin-bottom:.15rem; }
.cp-bill-top code { font-size:.78rem; color:#334155; background:none; }
.cp-kind { font-size:.7rem; font-weight:700; padding:.1rem .5rem; border-radius:999px; }
.k-dep { background:#e0e7ff; color:#3730a3; }
.k-bal { background:#f1f5f9; color:#334155; }
.k-fee { background:#fce7f3; color:#9d174d; }
.k-var { background:#ede9fe; color:#5b21b6; }
.k-ms { background:#e0f2fe; color:#075985; }
.cp-tag { font-size:.65rem; font-weight:700; text-transform:uppercase; padding:.08rem .4rem; border-radius:4px; }
.cp-tag.warn { background:#fef3c7; color:#92400e; }
.cp-bill-amt { display:flex; flex-direction:column; align-items:flex-end; gap:.15rem; font-variant-numeric: tabular-nums; }
.cp-status { font-size:.7rem; font-weight:700; padding:.08rem .5rem; border-radius:999px; }
.cp-status.paid { background:#ecfdf3; color: var(--green); }
.cp-status.pending { background:#fef3c7; color:#92400e; }
.cp-status.cancelled { background:#f1f5f9; color: var(--muted); }
.cp-bill-act { min-width: 0; }
@media (max-width: 640px) {
    .cp-bill { grid-template-columns: 1fr auto; }
    .cp-bill-act { grid-column: 1 / -1; }
    .cp-bill-act .cp-btn { width:100%; justify-content:center; }
}
</style>
