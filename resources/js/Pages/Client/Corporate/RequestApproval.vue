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
            <div class="amount-box" v-if="request.quote_amount">
                <small>Quotation</small>
                <div class="amount">KES {{ money(request.quote_amount) }}</div>
            </div>
        </header>

        <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>
        <div v-if="flash.error" class="alert alert-danger">{{ flash.error }}</div>

        <section class="card">
            <h3>The job</h3>
            <p class="desc">{{ request.description }}</p>
            <dl class="facts">
                <div><dt>Where</dt><dd>{{ request.location || '—' }}</dd></div>
                <div><dt>Urgency</dt><dd>{{ urgencyLabel }}</dd></div>
                <div><dt>Materials</dt><dd>KES {{ money(materialsTotal) }}</dd></div>
                <div><dt>Labour</dt><dd>KES {{ money(request.quote_labor_cost) }}</dd></div>
                <div><dt>Transport</dt><dd>KES {{ money(request.quote_transport_cost) }}</dd></div>
            </dl>
            <p v-if="request.quote_notes" class="notes">{{ request.quote_notes }}</p>
        </section>

        <!-- Chain: approved green, declined red, still-open amber, superseded muted.
             Declined and superseded steps stay visible on purpose — the paper
             trail is what the brief asks for. -->
        <section class="card">
            <h3>Approval trail</h3>
            <ul class="trail">
                <li v-for="step in history" :key="step.id" :class="['trail-item', `is-${step.status}`]">
                    <span class="dot"></span>
                    <div>
                        <strong>{{ step.stage === 'verify' ? 'Verification' : 'Approval' }}</strong>
                        <span class="tag">{{ statusLabel(step.status) }}</span>
                        <span v-if="step.quote_revision > 0" class="muted"> · revision {{ step.quote_revision }}</span>
                        <div v-if="step.decided_by" class="muted small">
                            {{ step.member?.display_name || step.decided_by?.name }} · {{ formatDate(step.decided_at) }}
                        </div>
                        <div v-if="step.comments" class="comment">“{{ step.comments }}”</div>
                        <div v-if="step.lpo_number" class="muted small">LPO {{ step.lpo_number }} · signed by {{ step.signatory_name }}</div>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="currentStage && canDecide" class="card decide">
            <h3>{{ isFinal ? 'Approve this quotation' : 'Verify this request' }}</h3>
            <p class="hint" v-if="isFinal">
                Approving commits {{ membership?.name_on_documents || 'you' }} to this figure and releases the job
                for scheduling. No deposit is due — this account runs against its standing float.
            </p>
            <p class="hint" v-else>
                Verifying confirms the work is needed. It then goes to your approver for final sign-off.
            </p>

            <template v-if="isFinal">
                <div class="field">
                    <label>LPO number <span class="req">*</span></label>
                    <input v-model="form.lpo_number" type="text" maxlength="60" />
                    <small v-if="errors.lpo_number" class="err">{{ errors.lpo_number }}</small>
                </div>
                <div class="field">
                    <label>LPO copy <span class="req">*</span></label>
                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" @change="e => form.lpo_document = e.target.files[0]" />
                    <small v-if="errors.lpo_document" class="err">{{ errors.lpo_document }}</small>
                </div>
                <div class="field">
                    <label>Landlord / property owner KRA PIN <span class="req">*</span></label>
                    <input v-model="form.payer_kra_pin" type="text" maxlength="30" />
                    <small v-if="errors.payer_kra_pin" class="err">{{ errors.payer_kra_pin }}</small>
                    <small v-else class="hint">
                        Defaulted from {{ request.property?.name }}. Payment comes from the landlord, not the
                        management company — change it if this building has changed hands.
                    </small>
                </div>
                <div class="field">
                    <label>Signed by <span class="req">*</span></label>
                    <select v-model="form.signatory_name">
                        <option value="" disabled>Select an authorised signatory…</option>
                        <option v-for="s in signatories" :key="s.id" :value="s.name">{{ s.name }}</option>
                    </select>
                    <small v-if="errors.signatory_name" class="err">{{ errors.signatory_name }}</small>
                </div>
            </template>

            <div class="field">
                <label>Comments {{ decliningNow ? '' : '(optional)' }}</label>
                <textarea v-model="form.comments" rows="3" maxlength="1000"
                          :placeholder="decliningNow ? 'Tell Technician World what needs to change.' : 'Anything the office should know.'"></textarea>
                <small v-if="errors.comments" class="err">{{ errors.comments }}</small>
            </div>

            <div class="actions">
                <button class="btn btn-danger" :disabled="busy" @click="decline">Decline &amp; return</button>
                <button class="btn btn-primary" :disabled="busy" @click="approve">
                    {{ busy ? 'Sending…' : (isFinal ? 'Approve' : 'Verify') }}
                </button>
            </div>
        </section>

        <section v-else-if="currentStage" class="card muted-card">
            <p>{{ refuseReason || 'This step is not yours to decide.' }}</p>
        </section>

        <section v-else-if="request.rfq_status === 'rejected'" class="card muted-card">
            <p>Returned to Technician World. A revised quotation will come back under a new reference.</p>
        </section>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    request: { type: Object, required: true },
    quoteReference: { type: String, required: true },
    history: { type: Array, required: true },
    currentStage: { type: Object, default: null },
    canDecide: { type: Boolean, default: false },
    refuseReason: { type: String, default: null },
    defaultPayerPin: { type: String, default: null },
    signatories: { type: Array, default: () => [] },
    membership: { type: Object, default: null },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const errors = computed(() => page.props.errors || {})
const busy = ref(false)
const decliningNow = ref(false)

const isFinal = computed(() => props.currentStage?.stage === 'approve')

const form = reactive({
    lpo_number: '',
    lpo_document: null,
    payer_kra_pin: props.defaultPayerPin || '',
    signatory_name: props.membership?.name_on_documents || '',
    comments: '',
})

const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const materialsTotal = computed(() => {
    const m = props.request.quote_materials
    if (!Array.isArray(m)) return 0
    return m.reduce((sum, row) => sum + Number(row.quantity || 0) * Number(row.unit_price || 0), 0)
})

const urgencyLabel = computed(() => ({ low: 'Low', medium: 'Medium', high: 'Emergency' }[props.request.urgency] || props.request.urgency))

const statusLabel = (s) => ({
    pending: 'Waiting',
    approved: 'Approved',
    declined: 'Declined',
    superseded: 'Superseded by a revision',
}[s] || s)

const formatDate = (d) => d ? new Date(d).toLocaleString() : ''

// The revision the page was rendered against. The server refuses a decision
// made on figures that have since been replaced.
const seenRevision = computed(() => Number(props.request.quote_revision_count || 0))

const approve = () => {
    busy.value = true
    decliningNow.value = false
    router.post(`/corporate/requests/${props.request.id}/approve`, {
        ...form,
        seen_revision: seenRevision.value,
    }, { forceFormData: true, onFinish: () => { busy.value = false } })
}

const decline = () => {
    decliningNow.value = true
    if (!form.comments || form.comments.trim().length < 5) {
        alert('Please say why you are declining — the office needs something to act on.')
        return
    }
    busy.value = true
    router.post(`/corporate/requests/${props.request.id}/decline`, {
        comments: form.comments,
        seen_revision: seenRevision.value,
    }, { onFinish: () => { busy.value = false } })
}
</script>

<style scoped>
.corp-page { padding: 1.5rem; max-width: 820px; margin: 0 auto; }
.back { font-size: 0.8rem; color: #6b7280; text-decoration: none; }
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin: 0.5rem 0 1.25rem; }
h1 { margin: 0; font-size: 1.4rem; }
.sub { margin: 0.25rem 0 0; color: #6b7280; font-size: 0.85rem; }
.amount-box { text-align: right; white-space: nowrap; }
.amount-box small { color: #6b7280; font-size: 0.75rem; }
.amount { font-size: 1.25rem; font-weight: 700; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.15rem; margin-bottom: 1rem; }
.card h3 { margin: 0 0 0.75rem; font-size: 0.95rem; }
.desc { margin: 0 0 0.9rem; font-size: 0.88rem; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin: 0; }
.facts dt { color: #6b7280; font-size: 0.75rem; }
.facts dd { margin: 0.1rem 0 0; font-size: 0.88rem; font-weight: 600; }
.notes { margin: 0.9rem 0 0; padding-top: 0.75rem; border-top: 1px solid #f3f4f6; font-size: 0.85rem; color: #4b5563; }
.trail { list-style: none; padding: 0; margin: 0; }
.trail-item { display: flex; gap: 0.75rem; padding: 0.6rem 0; border-bottom: 1px solid #f3f4f6; font-size: 0.85rem; }
.trail-item:last-child { border-bottom: none; }
.dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 0.4rem; flex: none; background: #d1d5db; }
.is-approved .dot { background: #16a34a; }
.is-declined .dot { background: #dc2626; }
.is-pending .dot { background: #f59e0b; }
.is-declined strong, .is-declined .tag { color: #b91c1c; }
.is-approved strong, .is-approved .tag { color: #15803d; }
.is-superseded { opacity: 0.6; }
.tag { font-size: 0.72rem; margin-left: 0.4rem; text-transform: uppercase; letter-spacing: 0.03em; }
.comment { margin-top: 0.3rem; padding: 0.4rem 0.6rem; background: #f9fafb; border-radius: 6px; font-style: italic; }
.muted { color: #6b7280; }
.small { font-size: 0.78rem; }
.muted-card { color: #6b7280; font-size: 0.88rem; }
.decide { border-color: #bfdbfe; }
.field { margin-bottom: 0.9rem; display: flex; flex-direction: column; }
.field label { font-size: 0.8rem; font-weight: 600; margin-bottom: 0.3rem; }
.field input[type=text], .field select, .field textarea {
    padding: 0.5rem 0.65rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; font-size: 0.86rem;
}
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem; }
.err { color: #dc2626; font-size: 0.75rem; margin-top: 0.25rem; }
.actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1rem; }
.btn-danger { background: #dc2626; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; }
.alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; }
.alert-success { background: #f0fdf4; color: #166534; }
.alert-danger { background: #fef2f2; color: #991b1b; }
</style>
