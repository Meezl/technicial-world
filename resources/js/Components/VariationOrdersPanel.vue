<template>
    <div class="vo-panel">
        <div class="vo-head">
            <div>
                <strong>Variations</strong>
                <span class="vo-count">{{ variations.length }}</span>
            </div>
            <button type="button" class="vo-btn vo-btn-primary" @click="showForm = !showForm">
                <i class="fas" :class="showForm ? 'fa-times' : 'fa-plus'"></i>
                {{ showForm ? 'Cancel' : 'Raise variation' }}
            </button>
        </div>

        <!-- Ledger: original quote, every variation, running total. The
             history the client and the team both need to see. -->
        <div v-if="ledger" class="vo-ledger">
            <div v-for="entry in ledger.entries" :key="entry.ref" class="vo-ledger-row">
                <div class="vo-ledger-label">
                    <span class="vo-ref">{{ entry.ref }}</span>
                    <span class="vo-desc">{{ entry.label }}</span>
                    <span v-if="entry.type === 'variation' && !entry.counts" class="vo-chip vo-chip-muted">
                        {{ statusLabel(entry.status) }} — not counted yet
                    </span>
                </div>
                <div class="vo-ledger-amounts">
                    <span class="vo-delta" :class="amountClass(entry)">
                        {{ entry.type === 'quote' ? '' : (entry.amount < 0 ? '−' : '+') }}
                        {{ money(Math.abs(entry.amount)) }}
                    </span>
                    <span class="vo-running">{{ money(entry.running) }}</span>
                </div>
            </div>
            <div class="vo-ledger-row vo-ledger-total">
                <div class="vo-ledger-label"><strong>Contract value</strong></div>
                <div class="vo-ledger-amounts"><span class="vo-running">{{ money(ledger.contract_value) }}</span></div>
            </div>
        </div>

        <!-- Raise form. Deltas are signed: a negative rate is a deduction,
             which is why there is no separate "credit" mode. -->
        <form v-if="showForm" class="vo-form" @submit.prevent="submit">
            <div class="vo-field">
                <label>Type</label>
                <select v-model="form.origin">
                    <option value="tw">We are raising it</option>
                    <option value="client">Client asked for it</option>
                    <option value="zero_income">Internal only — no client charge</option>
                </select>
                <p v-if="form.origin === 'zero_income'" class="vo-hint">
                    Internal variations are never emailed to the client and must carry no amount.
                    Use one to justify a technician fee change with no client-side cause.
                </p>
            </div>

            <div class="vo-field">
                <label>Reason <span class="vo-req">*</span></label>
                <textarea v-model="form.reason" rows="2" placeholder="What changed, and why" required></textarea>
                <p class="vo-hint">The client sees this wording on the card.</p>
            </div>

            <div v-if="form.origin !== 'zero_income'" class="vo-items">
                <div class="vo-items-head">
                    <label>Lines</label>
                    <button type="button" class="vo-btn vo-btn-ghost" @click="addItem">
                        <i class="fas fa-plus"></i> Add line
                    </button>
                </div>

                <div v-for="(item, i) in form.items" :key="i" class="vo-item">
                    <select v-model="item.category">
                        <option value="material">Material</option>
                        <option value="labor">Labour</option>
                        <option value="transport">Transport</option>
                    </select>
                    <input v-model="item.description" type="text" placeholder="Description" />
                    <input v-model.number="item.quantity" type="number" step="0.01" placeholder="Qty" />
                    <input v-model.number="item.unit_price" type="number" step="0.01" placeholder="Rate" />
                    <span class="vo-item-total">{{ money(lineTotal(item)) }}</span>
                    <button type="button" class="vo-item-remove" title="Remove line" @click="form.items.splice(i, 1)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>

                <p class="vo-hint">
                    Enter a negative rate to deduct. A deduction lowers the contract value
                    and raises no invoice.
                </p>
            </div>

            <div class="vo-field vo-field-inline">
                <div>
                    <label>Extra days</label>
                    <input v-model.number="form.additional_days" type="number" min="0" max="365" placeholder="0" />
                </div>
                <div v-if="form.origin !== 'zero_income'">
                    <label>Deposit top-up</label>
                    <input v-model.number="form.deposit" type="number" step="0.01" min="0" placeholder="Optional" />
                    <p class="vo-hint">Leave blank to bill the whole amount when the client approves.</p>
                </div>
            </div>

            <div v-if="form.origin !== 'zero_income'" class="vo-net" :class="netClass">
                <span>Net {{ net < 0 ? 'deduction' : 'addition' }}</span>
                <strong>{{ net < 0 ? '−' : '+' }} {{ money(Math.abs(net)) }}</strong>
            </div>

            <div v-if="form.errors && Object.keys(form.errors).length" class="vo-errors">
                <p v-for="(msg, key) in form.errors" :key="key">{{ msg }}</p>
            </div>

            <div class="vo-form-actions">
                <button type="submit" class="vo-btn vo-btn-ghost" :disabled="form.processing" @click="sendNow = false">
                    Save as draft
                </button>
                <button
                    v-if="form.origin !== 'zero_income'"
                    type="submit"
                    class="vo-btn vo-btn-primary"
                    :disabled="form.processing"
                    @click="sendNow = true"
                >
                    <i class="fas fa-paper-plane"></i> Save &amp; send to client
                </button>
            </div>
        </form>

        <p v-if="!variations.length" class="vo-empty">
            No variations on this job yet. Raise one instead of re-quoting when the
            scope changes — the client is then asked about the difference only.
        </p>

        <div v-for="vo in variations" :key="vo.id" class="vo-card">
            <div class="vo-card-head">
                <div>
                    <span class="vo-ref">{{ vo.vo_number }}</span>
                    <span class="vo-chip" :class="statusClass(vo.status)">{{ statusLabel(vo.status) }}</span>
                    <span v-if="vo.origin === 'zero_income'" class="vo-chip vo-chip-internal">
                        <i class="fas fa-lock"></i> Internal
                    </span>
                    <span v-else-if="vo.origin === 'client'" class="vo-chip vo-chip-muted">Client requested</span>
                </div>
                <!-- An internal variation has no client figure by definition,
                     so "+ KSH 0.00" would be noise pretending to be money. -->
                <span v-if="vo.origin === 'zero_income'" class="vo-card-amount vo-card-amount-none">
                    No client charge
                </span>
                <span v-else class="vo-card-amount" :class="Number(vo.net_amount) < 0 ? 'is-deduct' : 'is-add'">
                    {{ Number(vo.net_amount) < 0 ? '−' : '+' }} {{ money(Math.abs(Number(vo.net_amount))) }}
                </span>
            </div>

            <p class="vo-card-reason">{{ vo.reason }}</p>

            <div class="vo-card-meta">
                <span v-if="vo.creator">Raised by {{ vo.creator.name }}</span>
                <span v-if="vo.additional_days">+{{ vo.additional_days }} day{{ vo.additional_days === 1 ? '' : 's' }}</span>
                <span v-if="vo.approver">Approved by {{ vo.approver.name }}</span>
            </div>

            <ul v-if="vo.items && vo.items.length" class="vo-card-items">
                <li v-for="item in vo.items" :key="item.id">
                    <span>{{ item.description }}</span>
                    <span class="vo-card-item-amt">{{ money(Number(item.total_price)) }}</span>
                </li>
            </ul>

            <!-- Fee changes this variation authorised: the other half of the
                 click-through the product owner asked for. -->
            <div v-if="vo.compensation_amendments && vo.compensation_amendments.length" class="vo-fees">
                <strong>Technician fees</strong>
                <div v-for="fee in vo.compensation_amendments" :key="fee.id" class="vo-fee">
                    <span>{{ fee.technician?.user?.name || 'Technician' }}</span>
                    <span>
                        {{ money(Number(fee.original_amount)) }} → {{ money(Number(fee.proposed_amount)) }}
                        <em class="vo-chip vo-chip-muted">{{ fee.status }}</em>
                    </span>
                </div>
            </div>

            <div class="vo-card-actions">
                <button
                    v-if="canSend(vo)"
                    type="button"
                    class="vo-btn vo-btn-primary"
                    @click="send(vo)"
                >
                    <i class="fas fa-paper-plane"></i>
                    {{ vo.status === 'pending_client' ? 'Re-send' : 'Send to client' }}
                </button>
                <button
                    v-if="vo.origin === 'zero_income' && vo.status !== 'approved'"
                    type="button"
                    class="vo-btn vo-btn-primary"
                    @click="approveInternal(vo)"
                >
                    <i class="fas fa-check"></i> Approve internally
                </button>
                <button
                    v-if="vo.status !== 'approved' && vo.status !== 'void'"
                    type="button"
                    class="vo-btn vo-btn-ghost"
                    @click="voidVo(vo)"
                >
                    Withdraw
                </button>
                <span v-if="vo.status === 'approved'" class="vo-locked">
                    <i class="fas fa-lock"></i>
                    Approved variations are final — correct with an offsetting variation
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * Variations on a job, for admin and PM.
 *
 * Deliberately shows the ledger first: original quote, every variation, and
 * the value after each. Re-quoting a job destroyed that history, which is
 * how a client who owed 7,500 ended up being sent a 79,500 quotation to
 * approve.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({
    job: { type: Object, required: true },
    ledger: { type: Object, default: null },
})

const showForm = ref(false)
const sendNow = ref(false)

const variations = computed(() => props.job.variation_orders || [])

const form = useForm({
    origin: 'tw',
    reason: '',
    additional_days: null,
    deposit: null,
    items: [{ category: 'labor', description: '', quantity: 1, unit_price: null }],
})

const lineTotal = (item) => (Number(item.quantity) || 0) * (Number(item.unit_price) || 0)
const net = computed(() => form.items.reduce((sum, i) => sum + lineTotal(i), 0))
const netClass = computed(() => (net.value < 0 ? 'is-deduct' : 'is-add'))

const addItem = () => form.items.push({ category: 'material', description: '', quantity: 1, unit_price: null })

const money = (value) =>
    'KSH ' + Number(value || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const amountClass = (entry) => {
    if (entry.type === 'quote') return ''
    return entry.amount < 0 ? 'is-deduct' : 'is-add'
}

const statusLabel = (status) =>
    ({
        draft: 'Draft',
        pending_client: 'Awaiting client',
        approved: 'Approved',
        declined: 'Declined',
        void: 'Withdrawn',
    }[status] || status)

const statusClass = (status) =>
    ({
        draft: 'vo-chip-muted',
        pending_client: 'vo-chip-pending',
        approved: 'vo-chip-approved',
        declined: 'vo-chip-declined',
        void: 'vo-chip-muted',
    }[status] || 'vo-chip-muted')

// Internal variations are never sent; approved and withdrawn ones are closed.
const canSend = (vo) =>
    vo.origin !== 'zero_income' && ['draft', 'pending_client'].includes(vo.status)

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            send_now: sendNow.value,
            // Only the lines that actually carry a figure.
            items: data.origin === 'zero_income'
                ? []
                : data.items.filter((i) => i.description && i.unit_price !== null && i.unit_price !== ''),
            billing: data.deposit ? { deposit: data.deposit } : null,
        }))
        .post(route('variations.store', props.job.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset()
                form.items = [{ category: 'labor', description: '', quantity: 1, unit_price: null }]
                showForm.value = false
            },
        })
}

const send = (vo) => {
    if (!confirm(`Send ${vo.vo_number} to the client for approval?`)) return
    router.post(route('variations.send', vo.id), {}, { preserveScroll: true })
}

const approveInternal = (vo) => {
    if (!confirm(`Approve ${vo.vo_number}? This is internal and the client is not told.`)) return
    router.post(route('variations.approve-internal', vo.id), {}, { preserveScroll: true })
}

const voidVo = (vo) => {
    if (!confirm(`Withdraw ${vo.vo_number}?`)) return
    router.post(route('variations.void', vo.id), {}, { preserveScroll: true })
}
</script>

<style scoped>
.vo-panel { display: flex; flex-direction: column; gap: 1rem; }

.vo-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.vo-head strong { font-size: 1rem; color: #111827; }
.vo-count {
    display: inline-block; margin-left: .5rem; padding: .05rem .45rem;
    border-radius: 999px; background: #E5E7EB; color: #374151; font-size: .72rem;
}

/* ---- ledger ---- */
.vo-ledger { border: 1px solid #E5E7EB; border-radius: 8px; overflow: hidden; background: #fff; }
.vo-ledger-row {
    display: flex; justify-content: space-between; align-items: baseline; gap: 1rem;
    padding: .6rem .85rem; border-bottom: 1px solid #F3F4F6; font-size: .85rem;
}
.vo-ledger-row:last-child { border-bottom: 0; }
.vo-ledger-total { background: #F9FAFB; font-weight: 600; }
.vo-ledger-label { display: flex; flex-direction: column; gap: .15rem; min-width: 0; }
.vo-desc { color: #6B7280; font-size: .78rem; overflow: hidden; text-overflow: ellipsis; }
.vo-ledger-amounts { display: flex; gap: 1rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
.vo-delta { min-width: 7rem; text-align: right; }
.vo-running { min-width: 8rem; text-align: right; color: #111827; font-weight: 600; }
.is-add { color: #15803D; }
.is-deduct { color: #B91C1C; }

.vo-ref { font-family: ui-monospace, Menlo, monospace; font-size: .78rem; color: #053272; font-weight: 600; }

/* ---- chips ---- */
.vo-chip {
    display: inline-block; margin-left: .4rem; padding: .08rem .45rem;
    border-radius: 999px; font-size: .68rem; font-weight: 600;
}
.vo-chip-muted { background: #F3F4F6; color: #6B7280; }
.vo-chip-pending { background: #FEF3C7; color: #92400E; }
.vo-chip-approved { background: #DCFCE7; color: #166534; }
.vo-chip-declined { background: #FEE2E2; color: #991B1B; }
.vo-chip-internal { background: #EDE9FE; color: #5B21B6; }

/* ---- form ---- */
.vo-form {
    border: 1px solid #E5E7EB; border-radius: 8px; padding: 1rem;
    background: #F9FAFB; display: flex; flex-direction: column; gap: .85rem;
}
.vo-field { display: flex; flex-direction: column; gap: .3rem; }
.vo-field label { font-size: .75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: .03em; }
.vo-req { color: #DC2626; }
.vo-field-inline { flex-direction: row; gap: 1rem; flex-wrap: wrap; }
.vo-field-inline > div { display: flex; flex-direction: column; gap: .3rem; flex: 1 1 12rem; }
.vo-hint { margin: 0; font-size: .74rem; color: #6B7280; line-height: 1.45; }

.vo-form input, .vo-form select, .vo-form textarea {
    border: 1px solid #D1D5DB; border-radius: 6px; padding: .45rem .6rem;
    font-size: .85rem; font-family: inherit; background: #fff; width: 100%;
}
.vo-form textarea { resize: vertical; }

.vo-items { display: flex; flex-direction: column; gap: .5rem; }
.vo-items-head { display: flex; align-items: center; justify-content: space-between; }
.vo-item { display: grid; grid-template-columns: 6.5rem 1fr 5rem 7rem auto auto; gap: .4rem; align-items: center; }
.vo-item-total { font-size: .8rem; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.vo-item-remove { border: 0; background: none; color: #9CA3AF; cursor: pointer; padding: .25rem; }
.vo-item-remove:hover { color: #DC2626; }

@media (max-width: 640px) {
    .vo-item { grid-template-columns: 1fr 1fr; }
    .vo-item-total { text-align: left; }
}

.vo-net {
    display: flex; justify-content: space-between; align-items: center;
    padding: .6rem .85rem; border-radius: 6px; font-size: .9rem;
}
.vo-net.is-add { background: #DCFCE7; color: #166534; }
.vo-net.is-deduct { background: #FEE2E2; color: #991B1B; }
.vo-net strong { font-size: 1.1rem; font-variant-numeric: tabular-nums; }

.vo-errors { background: #FEE2E2; border-radius: 6px; padding: .6rem .85rem; }
.vo-errors p { margin: 0 0 .25rem; color: #991B1B; font-size: .8rem; }
.vo-errors p:last-child { margin-bottom: 0; }

.vo-form-actions { display: flex; gap: .5rem; flex-wrap: wrap; justify-content: flex-end; }

/* ---- cards ---- */
.vo-empty { margin: 0; font-size: .82rem; color: #6B7280; line-height: 1.5; }

.vo-card { border: 1px solid #E5E7EB; border-radius: 8px; padding: .85rem; background: #fff; }
.vo-card + .vo-card { margin-top: -.4rem; }
.vo-card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap; }
.vo-card-amount { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
.vo-card-amount-none { font-weight: 600; font-size: .78rem; color: #5B21B6; }
.vo-card-reason { margin: .5rem 0 .35rem; font-size: .85rem; color: #374151; }
.vo-card-meta { display: flex; gap: .85rem; flex-wrap: wrap; font-size: .74rem; color: #6B7280; }

.vo-card-items { list-style: none; margin: .6rem 0 0; padding: .5rem .6rem; background: #F9FAFB; border-radius: 6px; }
.vo-card-items li { display: flex; justify-content: space-between; gap: 1rem; font-size: .78rem; color: #4B5563; padding: .12rem 0; }
.vo-card-item-amt { font-variant-numeric: tabular-nums; white-space: nowrap; }

.vo-fees { margin-top: .6rem; padding: .5rem .6rem; background: #F5F3FF; border-radius: 6px; }
.vo-fees strong { font-size: .74rem; text-transform: uppercase; letter-spacing: .04em; color: #5B21B6; }
.vo-fee { display: flex; justify-content: space-between; gap: 1rem; font-size: .78rem; color: #4B5563; padding: .15rem 0; }

.vo-card-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-top: .75rem; }
.vo-locked { font-size: .74rem; color: #6B7280; }

/* ---- buttons ---- */
.vo-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    border-radius: 6px; padding: .4rem .8rem; font-size: .8rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; font-family: inherit;
}
.vo-btn:disabled { opacity: .55; cursor: not-allowed; }
.vo-btn-primary { background: #053272; color: #fff; }
.vo-btn-primary:hover:not(:disabled) { background: #04255a; }
.vo-btn-ghost { background: #fff; color: #374151; border-color: #D1D5DB; }
.vo-btn-ghost:hover:not(:disabled) { background: #F9FAFB; }
</style>
