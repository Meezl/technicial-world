<template>
    <div class="rf-panel">
        <div class="rf-head">
            <div>
                <strong>Refunds</strong>
                <span v-if="refunds.length" class="rf-count">{{ refunds.length }}</span>
            </div>
            <button type="button" class="rf-btn rf-btn-primary" @click="showForm = !showForm">
                <i class="fas" :class="showForm ? 'fa-times' : 'fa-plus'"></i>
                {{ showForm ? 'Cancel' : 'Raise refund' }}
            </button>
        </div>

        <!-- A job in credit means the client has paid for more than the job is
             now worth. It is easy to miss, so it leads. -->
        <div v-if="credit > 0.01" class="rf-credit">
            <div>
                <strong>This client is owed {{ money(credit) }}</strong>
                <p>
                    They have paid more than the job is now worth — usually after work
                    was removed from scope. Raise a refund, or a credit note if it is
                    being carried against this job.
                </p>
            </div>
            <button type="button" class="rf-btn rf-btn-primary" @click="prefillCredit">
                Refund {{ money(credit) }}
            </button>
        </div>

        <form v-if="showForm" class="rf-form" @submit.prevent="submit">
            <div class="rf-field-row">
                <div class="rf-field">
                    <label>Amount <span class="rf-req">*</span></label>
                    <input v-model.number="form.amount" type="number" step="0.01" min="0.01" required />
                </div>
                <div class="rf-field">
                    <label>Why</label>
                    <select v-model="form.category">
                        <option value="overpayment">Overpayment</option>
                        <option value="cancelled_attendance">Attendance cancelled</option>
                        <option value="waived_after_payment">Fee waived after payment</option>
                        <option value="scope_reduction">Scope reduced</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="rf-field">
                    <label>How</label>
                    <select v-model="form.method">
                        <option value="mpesa">M-Pesa</option>
                        <option value="bank">Bank transfer</option>
                        <option value="cash">Cash</option>
                        <option value="credit_note">Credit note — held against this job</option>
                    </select>
                </div>
            </div>

            <p v-if="form.method === 'credit_note'" class="rf-hint">
                A credit note moves no money. The client is owed it against this job,
                and it still reduces what they are treated as having paid.
            </p>

            <div class="rf-field">
                <label>Reason <span class="rf-req">*</span></label>
                <textarea v-model="form.reason" rows="2" required
                          placeholder="What happened, in words someone will understand in six months"></textarea>
            </div>

            <div v-if="form.errors && Object.keys(form.errors).length" class="rf-errors">
                <p v-for="(msg, key) in form.errors" :key="key">{{ msg }}</p>
            </div>

            <div class="rf-form-actions">
                <button type="submit" class="rf-btn rf-btn-primary" :disabled="form.processing">
                    Raise for approval
                </button>
            </div>
        </form>

        <p v-if="!refunds.length" class="rf-empty">
            Nothing owed back on this job.
        </p>

        <div v-for="r in refunds" :key="r.id" class="rf-card">
            <div class="rf-card-head">
                <div>
                    <span class="rf-ref">{{ r.refund_ref }}</span>
                    <span class="rf-chip" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</span>
                    <span class="rf-chip rf-chip-muted">{{ methodLabel(r.method) }}</span>
                </div>
                <span class="rf-amount">{{ money(Number(r.amount)) }}</span>
            </div>

            <p class="rf-reason">{{ r.reason }}</p>

            <div class="rf-meta">
                <span>{{ categoryLabel(r.category) }}</span>
                <span v-if="r.requester">Raised by {{ r.requester.name }}</span>
                <span v-if="r.approver && r.status !== 'rejected'">Approved by {{ r.approver.name }}</span>
                <span v-if="r.settlement_reference">Ref {{ r.settlement_reference }}</span>
            </div>

            <p v-if="r.rejection_reason" class="rf-rejected">{{ r.rejection_reason }}</p>

            <div class="rf-actions">
                <template v-if="r.status === 'pending_approval'">
                    <button type="button" class="rf-btn rf-btn-primary" @click="approve(r)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button type="button" class="rf-btn rf-btn-ghost" @click="reject(r)">Reject</button>
                    <span class="rf-note">Approving accepts we owe this.</span>
                </template>

                <template v-else-if="r.status === 'approved' && r.method !== 'credit_note'">
                    <button type="button" class="rf-btn rf-btn-primary" @click="settle(r)">
                        <i class="fas fa-money-bill-transfer"></i> Mark as paid out
                    </button>
                    <span class="rf-note">Send the money first, then record the reference here.</span>
                </template>

                <span v-else-if="r.status === 'approved'" class="rf-note">
                    Held as a credit against this job.
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * Refunds on a job, for admin and PM.
 *
 * Raising is open to both; approving is admin-only and refused by the server
 * for anyone else, so the buttons stay simple and the rule lives in one place.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({
    job: { type: Object, required: true },
    billing: { type: Object, default: null },
})

const showForm = ref(false)

const refunds = computed(() => props.job.refunds || [])
const credit = computed(() => Number(props.billing?.credit_balance ?? 0))

const form = useForm({
    amount: null,
    reason: '',
    category: 'other',
    method: 'mpesa',
})

const money = (v) =>
    'KSH ' + Number(v || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const statusLabel = (s) =>
    ({ pending_approval: 'Awaiting approval', approved: 'Approved', settled: 'Paid out', rejected: 'Rejected' }[s] || s)

const statusClass = (s) =>
    ({ pending_approval: 'rf-chip-pending', approved: 'rf-chip-approved', settled: 'rf-chip-settled', rejected: 'rf-chip-rejected' }[s] || 'rf-chip-muted')

const methodLabel = (m) =>
    ({ mpesa: 'M-Pesa', bank: 'Bank', cash: 'Cash', credit_note: 'Credit note' }[m] || m)

const categoryLabel = (c) =>
    ({
        overpayment: 'Overpayment',
        cancelled_attendance: 'Attendance cancelled',
        waived_after_payment: 'Fee waived after payment',
        scope_reduction: 'Scope reduced',
        other: 'Other',
    }[c] || c)

const prefillCredit = () => {
    form.amount = credit.value
    form.category = 'scope_reduction'
    showForm.value = true
}

const submit = () => {
    form.post(route('refunds.store', props.job.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            showForm.value = false
        },
    })
}

const approve = (r) => {
    if (!confirm(`Approve ${r.refund_ref} for ${money(Number(r.amount))}? This accepts that we owe it.`)) return
    router.post(route('refunds.approve', r.id), {}, { preserveScroll: true })
}

const reject = (r) => {
    const reason = prompt(`Reject ${r.refund_ref}? Reason (optional):`)
    if (reason === null) return
    router.post(route('refunds.reject', r.id), { reason }, { preserveScroll: true })
}

const settle = (r) => {
    const reference = prompt(
        `Record ${r.refund_ref} as paid out.\n\nEnter the reference the money went out under (M-Pesa code, bank reference):`
    )
    if (!reference) return
    router.post(route('refunds.settle', r.id), { settlement_reference: reference }, { preserveScroll: true })
}
</script>

<style scoped>
.rf-panel { display: flex; flex-direction: column; gap: 1rem; }

.rf-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.rf-head strong { font-size: 1rem; color: #111827; }
.rf-count {
    display: inline-block; margin-left: .5rem; padding: .05rem .45rem;
    border-radius: 999px; background: #E5E7EB; color: #374151; font-size: .72rem;
}

.rf-credit {
    display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap;
    padding: .85rem 1rem; border-radius: 8px;
    background: #FEF3C7; border: 1px solid #F59E0B;
}
.rf-credit strong { color: #92400E; font-size: .95rem; }
.rf-credit p { margin: .25rem 0 0; font-size: .8rem; color: #92400E; line-height: 1.5; max-width: 46ch; }

.rf-form {
    border: 1px solid #E5E7EB; border-radius: 8px; padding: 1rem;
    background: #F9FAFB; display: flex; flex-direction: column; gap: .8rem;
}
.rf-field-row { display: flex; gap: .75rem; flex-wrap: wrap; }
.rf-field-row .rf-field { flex: 1 1 10rem; }
.rf-field { display: flex; flex-direction: column; gap: .3rem; }
.rf-field label { font-size: .75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: .03em; }
.rf-req { color: #DC2626; }
.rf-form input, .rf-form select, .rf-form textarea {
    border: 1px solid #D1D5DB; border-radius: 6px; padding: .45rem .6rem;
    font-size: .85rem; font-family: inherit; background: #fff; width: 100%;
}
.rf-form textarea { resize: vertical; }
.rf-hint { margin: 0; font-size: .76rem; color: #6B7280; line-height: 1.45; }
.rf-errors { background: #FEE2E2; border-radius: 6px; padding: .6rem .85rem; }
.rf-errors p { margin: 0 0 .25rem; color: #991B1B; font-size: .8rem; }
.rf-errors p:last-child { margin-bottom: 0; }
.rf-form-actions { display: flex; justify-content: flex-end; }

.rf-empty { margin: 0; font-size: .82rem; color: #6B7280; }

.rf-card { border: 1px solid #E5E7EB; border-radius: 8px; padding: .85rem; background: #fff; }
.rf-card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap; }
.rf-ref { font-family: ui-monospace, Menlo, monospace; font-size: .78rem; font-weight: 600; color: #053272; }
.rf-amount { font-weight: 700; font-variant-numeric: tabular-nums; color: #B91C1C; white-space: nowrap; }
.rf-reason { margin: .5rem 0 .35rem; font-size: .85rem; color: #374151; }
.rf-meta { display: flex; gap: .85rem; flex-wrap: wrap; font-size: .74rem; color: #6B7280; }
.rf-rejected { margin: .5rem 0 0; font-size: .78rem; color: #991B1B; }

.rf-chip {
    display: inline-block; margin-left: .4rem; padding: .08rem .45rem;
    border-radius: 999px; font-size: .68rem; font-weight: 600;
}
.rf-chip-muted { background: #F3F4F6; color: #6B7280; }
.rf-chip-pending { background: #FEF3C7; color: #92400E; }
.rf-chip-approved { background: #DBEAFE; color: #1E40AF; }
.rf-chip-settled { background: #DCFCE7; color: #166534; }
.rf-chip-rejected { background: #FEE2E2; color: #991B1B; }

.rf-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-top: .75rem; }
.rf-note { font-size: .74rem; color: #6B7280; }

.rf-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    border-radius: 6px; padding: .4rem .8rem; font-size: .8rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; font-family: inherit;
}
.rf-btn:disabled { opacity: .55; cursor: not-allowed; }
.rf-btn-primary { background: #053272; color: #fff; }
.rf-btn-primary:hover:not(:disabled) { background: #04255a; }
.rf-btn-ghost { background: #fff; color: #374151; border-color: #D1D5DB; }
.rf-btn-ghost:hover:not(:disabled) { background: #F9FAFB; }
</style>
