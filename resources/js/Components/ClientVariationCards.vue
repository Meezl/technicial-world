<template>
    <div v-if="pending.length || settled.length" class="cvo">
        <!-- Anything awaiting the client leads. This is the whole point of the
             card: one decision, about the difference, not the whole job. -->
        <div v-for="vo in pending" :key="vo.id" class="cvo-card is-pending">
            <div class="cvo-head">
                <div>
                    <span class="cvo-ref">{{ vo.vo_number }}</span>
                    <span class="cvo-chip">Awaiting your approval</span>
                </div>
                <h3>{{ isDeduction(vo) ? 'A reduction to your job' : 'Additional work on your job' }}</h3>
            </div>

            <p class="cvo-scope">
                This is a change to your existing job. It does not replace anything you
                have already approved or paid — you are only being asked about the
                amount below.
            </p>

            <div class="cvo-amount" :class="isDeduction(vo) ? 'is-deduct' : 'is-add'">
                <span>{{ isDeduction(vo) ? 'Reduction' : 'Additional cost' }}</span>
                <strong>{{ isDeduction(vo) ? '−' : '+' }} {{ money(Math.abs(Number(vo.net_amount))) }}</strong>
            </div>

            <div class="cvo-reason">
                <label>Why</label>
                <p>{{ vo.reason }}</p>
                <p v-if="vo.additional_days" class="cvo-days">
                    <i class="fas fa-clock"></i>
                    Adds about {{ vo.additional_days }}
                    {{ vo.additional_days === 1 ? 'day' : 'days' }} to the programme.
                </p>
            </div>

            <ul v-if="vo.items && vo.items.length" class="cvo-items">
                <li v-for="item in vo.items" :key="item.id">
                    <span>{{ item.description }}</span>
                    <span class="cvo-item-amt">{{ money(Number(item.total_price)) }}</span>
                </li>
            </ul>

            <div class="cvo-totals">
                <div class="cvo-total-line">
                    <span>Your job as it stands</span>
                    <span>{{ money(currentValue) }}</span>
                </div>
                <div v-if="settledAmount > 0" class="cvo-total-line">
                    <span>Already paid by you</span>
                    <span>{{ money(settledAmount) }}</span>
                </div>
                <div class="cvo-total-line">
                    <span>{{ isDeduction(vo) ? 'This reduction' : 'This variation' }}</span>
                    <span :class="isDeduction(vo) ? 'is-deduct' : 'is-add'">
                        {{ isDeduction(vo) ? '−' : '+' }} {{ money(Math.abs(Number(vo.net_amount))) }}
                    </span>
                </div>
                <div class="cvo-total-line is-final">
                    <span>Job value if you approve</span>
                    <span>{{ money(projected(vo)) }}</span>
                </div>
            </div>

            <p v-if="error" class="cvo-error">{{ error }}</p>

            <div class="cvo-actions">
                <button
                    type="button"
                    class="cvo-btn cvo-btn-approve"
                    :disabled="busyId === vo.id"
                    @click="approve(vo)"
                >
                    <i class="fas fa-check"></i>
                    {{ busyId === vo.id ? 'Sending…' : 'Approve this change' }}
                </button>
                <button
                    type="button"
                    class="cvo-btn cvo-btn-decline"
                    :disabled="busyId === vo.id"
                    @click="decline(vo)"
                >
                    Decline
                </button>
            </div>

            <p class="cvo-foot">
                Approving affects only the amount shown here. Payments you have already
                made stand, and nothing you approved earlier changes.
            </p>
        </div>

        <!-- Everything already decided, kept so the client can see how the job
             reached its current figure. -->
        <div v-if="settled.length" class="cvo-history">
            <h4>Previous changes</h4>
            <div v-for="vo in settled" :key="vo.id" class="cvo-hist-row">
                <div>
                    <span class="cvo-ref">{{ vo.vo_number }}</span>
                    <span class="cvo-chip" :class="vo.status === 'approved' ? 'is-approved' : 'is-declined'">
                        {{ vo.status === 'approved' ? 'Approved' : 'Declined' }}
                    </span>
                    <p>{{ vo.reason }}</p>
                </div>
                <span
                    class="cvo-hist-amt"
                    :class="vo.status === 'approved' ? (isDeduction(vo) ? 'is-deduct' : 'is-add') : 'is-void'"
                >
                    {{ isDeduction(vo) ? '−' : '+' }} {{ money(Math.abs(Number(vo.net_amount))) }}
                </span>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * Variations as the client sees them.
 *
 * Deliberately never shows the whole quotation. A client who had settled
 * KES 72,000 and owed 7,500 was previously re-sent the entire 79,500
 * quotation to approve, and read it as being asked for the lot again.
 *
 * Internal (zero-income) variations are filtered server-side and never
 * reach this component.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    serviceRequest: { type: Object, required: true },
    ledger: { type: Object, default: null },
})

const busyId = ref(null)
const error = ref('')

const all = computed(() => props.serviceRequest.variation_orders || [])
const pending = computed(() => all.value.filter((v) => v.status === 'pending_client'))
const settled = computed(() => all.value.filter((v) => ['approved', 'declined'].includes(v.status)))

// What the job is worth today — approved variations only, which is exactly
// what the ledger's running total already accounts for.
const currentValue = computed(() =>
    props.ledger ? Number(props.ledger.contract_value) : Number(props.serviceRequest.quote_amount || 0)
)

const settledAmount = computed(() =>
    (props.serviceRequest.payment_requests || [])
        .filter((p) => p.status === 'paid')
        .reduce((sum, p) => sum + Number(p.amount || 0), 0)
)

const isDeduction = (vo) => Number(vo.net_amount) < 0
const projected = (vo) => currentValue.value + Number(vo.net_amount)

const money = (value) =>
    'KSH ' + Number(value || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/**
 * Parsed defensively for the same reason the quote approval is: when Laravel
 * returns an HTML error page (expired session, 500) a bare response.json()
 * throws and the client sees a meaningless failure.
 */
const post = async (url, body = {}) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    })

    let data = {}
    const raw = await response.text()
    try {
        data = raw ? JSON.parse(raw) : {}
    } catch (_e) {
        // Non-JSON body — Laravel error page or a 419.
    }

    if (!response.ok) {
        if (response.status === 419) {
            throw new Error('Your session has expired. Please refresh the page and try again.')
        }
        throw new Error(data?.error || data?.message || `Server returned HTTP ${response.status}.`)
    }

    return data
}

const approve = async (vo) => {
    const verb = isDeduction(vo) ? 'reduction' : 'additional cost'
    const amount = money(Math.abs(Number(vo.net_amount)))
    if (!confirm(`Approve ${vo.vo_number}? This accepts the ${verb} of ${amount}.`)) return

    busyId.value = vo.id
    error.value = ''
    try {
        await post(`/client/variations/${vo.id}/approve`)
        router.reload({ preserveScroll: true })
    } catch (e) {
        error.value = e.message
    } finally {
        busyId.value = null
    }
}

const decline = async (vo) => {
    const reason = prompt(`Decline ${vo.vo_number}? Let us know why (optional):`)
    // prompt() returns null on cancel, '' when submitted empty.
    if (reason === null) return

    busyId.value = vo.id
    error.value = ''
    try {
        await post(`/client/variations/${vo.id}/decline`, { reason })
        router.reload({ preserveScroll: true })
    } catch (e) {
        error.value = e.message
    } finally {
        busyId.value = null
    }
}
</script>

<style scoped>
.cvo { display: flex; flex-direction: column; gap: 1rem; }

.cvo-card {
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    padding: 1.1rem;
    background: #fff;
}
.cvo-card.is-pending { border-color: #F59E0B; border-left-width: 4px; }

.cvo-head h3 { margin: .4rem 0 0; font-size: 1.05rem; color: #111827; }
.cvo-ref {
    font-family: ui-monospace, Menlo, monospace;
    font-size: .78rem; font-weight: 600; color: #053272;
}
.cvo-chip {
    display: inline-block; margin-left: .45rem; padding: .1rem .5rem;
    border-radius: 999px; font-size: .68rem; font-weight: 600;
    background: #FEF3C7; color: #92400E;
}
.cvo-chip.is-approved { background: #DCFCE7; color: #166534; }
.cvo-chip.is-declined { background: #FEE2E2; color: #991B1B; }

.cvo-scope {
    margin: .75rem 0 0; padding: .6rem .75rem;
    background: #E8F0FB; border-left: 3px solid #053272; border-radius: 6px;
    font-size: .82rem; color: #16325c; line-height: 1.5;
}

.cvo-amount {
    display: flex; flex-direction: column; align-items: center; gap: .15rem;
    margin: .9rem 0; padding: 1rem; border-radius: 8px; text-align: center;
}
.cvo-amount span { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #4B5563; }
.cvo-amount strong { font-size: 1.9rem; font-variant-numeric: tabular-nums; }
.cvo-amount.is-add { background: #E7F6EE; border: 1px solid #16A34A; }
.cvo-amount.is-add strong { color: #15803D; }
.cvo-amount.is-deduct { background: #FDECEA; border: 1px solid #DC2626; }
.cvo-amount.is-deduct strong { color: #B91C1C; }

.cvo-reason label {
    font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
    color: #6B7280; font-weight: 600;
}
.cvo-reason p { margin: .25rem 0 0; font-size: .88rem; color: #374151; line-height: 1.55; }
.cvo-days { color: #92400E !important; font-size: .8rem !important; }

.cvo-items { list-style: none; margin: .85rem 0 0; padding: .6rem .75rem; background: #F9FAFB; border-radius: 6px; }
.cvo-items li { display: flex; justify-content: space-between; gap: 1rem; font-size: .8rem; color: #4B5563; padding: .15rem 0; }
.cvo-item-amt { font-variant-numeric: tabular-nums; white-space: nowrap; }

.cvo-totals { margin-top: .9rem; padding: .75rem .85rem; background: #e8f4fd; border-radius: 8px; }
.cvo-total-line {
    display: flex; justify-content: space-between; gap: 1rem;
    font-size: .85rem; color: #374151; padding: .2rem 0;
    font-variant-numeric: tabular-nums;
}
.cvo-total-line.is-final {
    border-top: 2px solid #053272; margin-top: .4rem; padding-top: .5rem;
    font-weight: 700; font-size: 1rem; color: #053272;
}
.is-add { color: #15803D; }
.is-deduct { color: #B91C1C; }
.is-void { color: #9CA3AF; text-decoration: line-through; }

.cvo-error {
    margin: .8rem 0 0; padding: .55rem .75rem; border-radius: 6px;
    background: #FEE2E2; color: #991B1B; font-size: .82rem;
}

.cvo-actions { display: flex; gap: .6rem; margin-top: 1rem; flex-wrap: wrap; }
.cvo-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    border: 1px solid transparent; border-radius: 6px;
    padding: .7rem 1.2rem; font-size: .9rem; font-weight: 600;
    cursor: pointer; font-family: inherit; flex: 1 1 10rem;
}
.cvo-btn:disabled { opacity: .6; cursor: not-allowed; }
.cvo-btn-approve { background: #16A34A; color: #fff; }
.cvo-btn-approve:hover:not(:disabled) { background: #15803D; }
.cvo-btn-decline { background: #fff; color: #4B5563; border-color: #D1D5DB; }
.cvo-btn-decline:hover:not(:disabled) { background: #F9FAFB; }

.cvo-foot { margin: .75rem 0 0; font-size: .76rem; color: #6B7280; line-height: 1.5; }

.cvo-history { border: 1px solid #E5E7EB; border-radius: 10px; padding: .9rem 1.1rem; background: #fff; }
.cvo-history h4 { margin: 0 0 .6rem; font-size: .82rem; text-transform: uppercase; letter-spacing: .05em; color: #6B7280; }
.cvo-hist-row {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;
    padding: .5rem 0; border-top: 1px solid #F3F4F6;
}
.cvo-hist-row:first-of-type { border-top: 0; }
.cvo-hist-row p { margin: .25rem 0 0; font-size: .8rem; color: #6B7280; }
.cvo-hist-amt { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
