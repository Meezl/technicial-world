<template>
    <div class="corp-page">
        <header class="head">
            <div>
                <h1>Variation Cards</h1>
                <p class="sub">
                    {{ membership.organisation }} · {{ membership.position_label }} —
                    additional scope on jobs already under way.
                </p>
            </div>
            <button v-if="membership.can_raise && openJobs.length" class="btn btn-primary btn-sm" @click="showRaise = true">
                <i class="fas fa-plus"></i> Raise a card
            </button>
        </header>

        <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>
        <div v-if="flash.error" class="alert alert-danger">{{ flash.error }}</div>

        <p class="explainer">
            A card asks your manager to agree the extra work is needed. Approving it does not commit
            to a price — Technician World quotes the scope afterwards, and that quotation comes back
            for approval in the usual way.
        </p>

        <div v-if="!cards.length" class="empty-card">
            <p>No variation cards yet.</p>
            <p v-if="membership.can_raise" class="muted small">
                Raise one when work you have already asked for turns out to need more than was quoted.
            </p>
        </div>

        <article v-for="c in cards" :key="c.id" :class="['card', `is-${c.status}`]">
            <div class="card-head">
                <div>
                    <strong>{{ c.card_number }}</strong>
                    <span class="muted"> · {{ c.service_request?.request_id }}</span>
                    <span v-if="c.service_request?.property" class="muted">
                        · {{ c.service_request.property.code
                            ? `${c.service_request.property.name} (${c.service_request.property.code})`
                            : c.service_request.property.name }}
                    </span>
                </div>
                <span :class="['pill', pillClass(c.status)]">{{ statusLabels[c.status] || c.status }}</span>
            </div>

            <div class="body">
                <div class="block">
                    <h4>Additional scope</h4>
                    <p>{{ c.scope_description }}</p>
                </div>
                <div class="block">
                    <h4>Why it is needed</h4>
                    <p>{{ c.justification }}</p>
                </div>
            </div>

            <div class="meta">
                Raised by {{ c.raised_by_member?.display_name || c.raised_by_member?.user?.name || '—' }}
                <span v-if="c.decided_at">
                    · decided by {{ c.decided_by_member?.display_name || '—' }} on {{ date(c.decided_at) }}
                </span>
            </div>

            <p v-if="c.decision_comments" class="comment">“{{ c.decision_comments }}”</p>

            <p v-if="c.variation_order" class="quoted">
                Quoted as <strong>{{ c.variation_order.vo_number }}</strong>
                — {{ money(c.variation_order.net_amount) }}
                <span class="muted">({{ c.variation_order.status }})</span>
            </p>

            <div v-if="membership.can_decide && c.status === 'pending'" class="decide">
                <textarea v-model="comments[c.id]" rows="2" maxlength="1000"
                          placeholder="Comments — required if you are declining."></textarea>
                <div class="actions">
                    <button class="btn btn-danger btn-sm" :disabled="busy" @click="decide(c, 'decline')">Decline</button>
                    <button class="btn btn-primary btn-sm" :disabled="busy" @click="decide(c, 'approve')">Approve</button>
                </div>
            </div>
        </article>

        <div v-if="showRaise" class="overlay" @click.self="showRaise = false">
            <div class="modal">
                <h3>Raise a variation card</h3>
                <div class="field">
                    <label>Which job <span class="req">*</span></label>
                    <select v-model="form.service_request_id">
                        <option :value="null" disabled>Select the job this adds to…</option>
                        <option v-for="j in openJobs" :key="j.id" :value="j.id">
                            {{ j.request_id }} — {{ j.property?.name }} — {{ truncate(j.description) }}
                        </option>
                    </select>
                </div>
                <div class="field">
                    <label>Additional scope <span class="req">*</span></label>
                    <textarea v-model="form.scope_description" rows="3" maxlength="2000"
                              placeholder="What else needs doing."></textarea>
                    <small v-if="errors.scope_description" class="err">{{ errors.scope_description }}</small>
                </div>
                <div class="field">
                    <label>Why it is needed <span class="req">*</span></label>
                    <textarea v-model="form.justification" rows="3" maxlength="2000"
                              placeholder="Why it cannot be left, or why it has to happen now."></textarea>
                    <small v-if="errors.justification" class="err">{{ errors.justification }}</small>
                </div>
                <div class="actions">
                    <button class="btn btn-secondary" @click="showRaise = false">Cancel</button>
                    <button class="btn btn-primary" :disabled="busy || !raiseReady" @click="raise">
                        {{ busy ? 'Sending…' : 'Send to my manager' }}
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
    cards: { type: Array, required: true },
    membership: { type: Object, required: true },
    openJobs: { type: Array, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const errors = computed(() => page.props.errors || {})
const busy = ref(false)
const showRaise = ref(false)
const comments = reactive({})

const statusLabels = {
    pending: 'Awaiting your manager',
    approved: 'Approved — with TW to price',
    declined: 'Declined',
    quoted: 'Quoted',
}
const pillClass = (s) => ({
    pending: 'pill-amber', approved: 'pill-blue', declined: 'pill-red', quoted: 'pill-green',
}[s] || 'pill-grey')

const money = (v) => 'KES ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const date = (d) => d ? new Date(d).toLocaleDateString() : '—'
const truncate = (t) => (t || '').length > 40 ? (t || '').slice(0, 40) + '…' : (t || '')

const form = reactive({ service_request_id: null, scope_description: '', justification: '' })
const raiseReady = computed(() =>
    form.service_request_id && form.scope_description.trim().length >= 10 && form.justification.trim().length >= 10)

const raise = () => {
    busy.value = true
    router.post('/corporate/variation-cards', { ...form }, {
        onFinish: () => { busy.value = false; showRaise.value = false },
    })
}

const decide = (card, decision) => {
    // Mirrors the server rule rather than letting them find out by round-trip:
    // a decline the requester cannot act on is no use to anybody.
    if (decision === 'decline' && !(comments[card.id] || '').trim()) {
        alert('Please say why — the requester needs to know what to do instead.')
        return
    }
    busy.value = true
    router.post(`/corporate/variation-cards/${card.id}/decide`,
        { decision, comments: comments[card.id] || null },
        { onFinish: () => { busy.value = false } })
}
</script>

<style scoped>
.corp-page { padding: 1.5rem; max-width: 900px; margin: 0 auto; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; }
h1 { margin: 0; font-size: 1.5rem; }
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.explainer { color: #6b7280; font-size: .82rem; margin: 1rem 0 1.25rem; }
.empty-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 2.5rem 1rem; text-align: center; color: #6b7280; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.1rem; margin-bottom: .9rem; border-left-width: 4px; }
.is-pending { border-left-color: #f59e0b; }
.is-approved { border-left-color: #2563eb; }
.is-declined { border-left-color: #dc2626; }
.is-quoted { border-left-color: #16a34a; }
.card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
.body { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin: .8rem 0; }
.block h4 { margin: 0 0 .25rem; font-size: .75rem; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; }
.block p { margin: 0; font-size: .87rem; }
.meta { font-size: .78rem; color: #6b7280; }
.comment { margin: .6rem 0 0; padding: .5rem .7rem; background: #f9fafb; border-radius: 6px; font-style: italic; font-size: .85rem; }
.quoted { margin: .6rem 0 0; font-size: .85rem; }
.decide { margin-top: .9rem; padding-top: .9rem; border-top: 1px solid #f3f4f6; }
.decide textarea { width: 100%; padding: .5rem .65rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; font-size: .85rem; }
.actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: .6rem; }
.muted { color: #6b7280; }
.small { font-size: .8rem; }
.err { color: #dc2626; font-size: .75rem; }
.req { color: #dc2626; }
.pill { display: inline-block; padding: .12rem .6rem; border-radius: 999px; font-size: .72rem; white-space: nowrap; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-blue { background: #dbeafe; color: #1e40af; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-red { background: #fee2e2; color: #991b1b; }
.pill-grey { background: #f3f4f6; color: #4b5563; }
.alert { padding: .75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .85rem; }
.alert-success { background: #f0fdf4; color: #166534; }
.alert-danger { background: #fef2f2; color: #991b1b; }
.overlay { position: fixed; inset: 0; background: rgba(17,24,39,.5); display: flex; align-items: center; justify-content: center; padding: 1rem; z-index: 50; }
.modal { background: #fff; border-radius: 12px; padding: 1.25rem; width: 100%; max-width: 560px; max-height: 90vh; overflow: auto; }
.field { display: flex; flex-direction: column; margin-bottom: .9rem; }
.field label { font-size: .8rem; font-weight: 600; margin-bottom: .3rem; }
.field select, .field textarea { padding: .5rem .65rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; font-size: .86rem; }
.btn-danger { background: #dc2626; color: #fff; border: none; padding: .4rem .9rem; border-radius: 6px; cursor: pointer; }
</style>
