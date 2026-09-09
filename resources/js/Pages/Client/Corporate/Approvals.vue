<template>
    <div class="corp-page">
        <h1>Awaiting your {{ membership.position === 'verifier' ? 'verification' : 'approval' }}</h1>
        <p class="sub">
            {{ membership.organisation }} · {{ membership.position_label }}
            <span v-if="membership.can_approve_up_to">
                · limit KES {{ Number(membership.can_approve_up_to).toLocaleString() }}
            </span>
        </p>

        <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>
        <div v-if="flash.error" class="alert alert-danger">{{ flash.error }}</div>

        <div v-if="!pending.length" class="empty-card">
            <i class="fas fa-check-circle"></i>
            <p v-if="isDecider">Nothing is waiting on you.</p>
            <p v-else>Your position does not sign quotations off. You can still see the account's jobs.</p>
            <Link href="/corporate/requests" class="link">View all jobs</Link>
        </div>

        <div v-for="r in pending" :key="r.id" class="job-card">
            <div class="job-head">
                <div>
                    <strong>{{ reference(r) }}</strong>
                    <span class="muted"> · {{ r.property ? (r.property.code ? `${r.property.name} (${r.property.code})` : r.property.name) : '—' }}</span>
                </div>
                <div class="amount">KES {{ Number(r.quote_amount || 0).toLocaleString() }}</div>
            </div>
            <p class="desc">{{ r.description }}</p>
            <div class="job-foot">
                <small class="muted">
                    Raised by {{ r.raised_by_member?.display_name || r.raised_by_member?.user?.name || '—' }}
                    · {{ r.service_category?.name }}
                    <span v-if="r.urgency === 'high'" class="pill pill-red">Emergency</span>
                </small>
                <Link :href="`/corporate/requests/${r.id}/approval`" class="btn btn-primary btn-sm">Review</Link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const props = defineProps({
    pending: { type: Array, required: true },
    membership: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const isDecider = computed(() => ['verifier', 'approver'].includes(props.membership.position))

const reference = (r) => {
    const revision = Number(r.quote_revision_count || 0)
    return revision > 0 ? `${r.request_id}/R${revision}` : r.request_id
}
</script>

<style scoped>
.corp-page { padding: 1.5rem; max-width: 900px; margin: 0 auto; }
h1 { margin: 0; font-size: 1.5rem; }
.sub { margin: 0.25rem 0 1.5rem; color: #6b7280; font-size: 0.85rem; }
.muted { color: #6b7280; }
.empty-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 2.5rem 1rem; text-align: center; color: #6b7280; }
.empty-card i { font-size: 1.75rem; color: #16a34a; }
.job-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1rem 1.15rem; margin-bottom: 0.9rem; }
.job-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; }
.amount { font-weight: 700; white-space: nowrap; }
.desc { margin: 0.5rem 0 0.75rem; font-size: 0.88rem; }
.job-foot { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.pill { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.7rem; margin-left: 0.4rem; }
.pill-red { background: #fee2e2; color: #991b1b; }
.link { color: #2563eb; text-decoration: none; }
.alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; }
.alert-success { background: #f0fdf4; color: #166534; }
.alert-danger { background: #fef2f2; color: #991b1b; }
</style>
