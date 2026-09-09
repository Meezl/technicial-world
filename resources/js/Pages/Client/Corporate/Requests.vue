<template>
    <div class="corp-page">
        <header class="corp-header">
            <div>
                <h1>{{ membership.organisation.name }}</h1>
                <p class="sub">
                    {{ membership.position_label }}
                    <span v-if="!membership.can_reassign"> · you see the jobs you raised</span>
                    <span v-else> · you see every job on this account</span>
                </p>
            </div>
            <Link v-if="membership.can_raise" href="/corporate/requests/new" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Raise a Job
            </Link>
        </header>

        <div v-if="flash.success" class="alert alert-success">{{ flash.success }}</div>
        <div v-if="flash.error" class="alert alert-danger">{{ flash.error }}</div>

        <div class="filters">
            <label>Property</label>
            <select :value="filters.property || ''" @change="filterByProperty($event.target.value)">
                <option value="">All properties</option>
                <option v-for="p in properties" :key="p.id" :value="p.id">
                    {{ p.code ? `${p.name} (${p.code})` : p.name }}
                </option>
            </select>
        </div>

        <div class="table-wrap">
            <table class="corp-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Property</th>
                        <th>Description</th>
                        <th>Raised by</th>
                        <th>Quotation</th>
                        <th>Where it is</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in requests.data" :key="r.id">
                        <td><strong>{{ reference(r) }}</strong></td>
                        <td>{{ r.property ? (r.property.code ? `${r.property.name} (${r.property.code})` : r.property.name) : '—' }}</td>
                        <td class="desc">{{ r.description }}</td>
                        <td>{{ r.raised_by_member?.display_name || r.raised_by_member?.user?.name || '—' }}</td>
                        <td>{{ r.quote_amount ? `KES ${Number(r.quote_amount).toLocaleString()}` : '—' }}</td>
                        <td><span :class="['pill', stageClass(r)]">{{ stageLabel(r) }}</span></td>
                        <td>
                            <Link :href="`/corporate/requests/${r.id}/approval`" class="link">Open</Link>
                        </td>
                    </tr>
                    <tr v-if="!requests.data.length">
                        <td colspan="7" class="empty">
                            Nothing here yet.
                            <span v-if="membership.can_raise">Use “Raise a Job” to send your first request.</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    requests: { type: Object, required: true },
    membership: { type: Object, required: true },
    properties: { type: Array, required: true },
    filters: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})

const filterByProperty = (value) => {
    router.get('/corporate/requests', value ? { property: value } : {}, {
        preserveState: true,
        replace: true,
    })
}

// Mirrors ServiceRequest::getQuoteReferenceAttribute so the list, the
// approval screen and the invoice all name a revision the same way.
const reference = (r) => {
    const revision = Number(r.quote_revision_count || 0)
    return revision > 0 ? `${r.request_id}/R${revision}` : r.request_id
}

// The live chain is the one attached to the figures currently on offer.
const currentStage = (r) => {
    const revision = Number(r.quote_revision_count || 0)
    return (r.corporate_approvals || [])
        .filter(a => a.status === 'pending' && Number(a.quote_revision) === revision)
        .sort((a, b) => a.sequence - b.sequence)[0]
}

const stageLabel = (r) => {
    if (r.rfq_status === 'rejected') return 'Returned to TW'
    if (r.rfq_status === 'approved') return 'Approved'
    const stage = currentStage(r)
    if (stage) return stage.stage === 'verify' ? 'Awaiting verification' : 'Awaiting approval'
    if (r.rfq_status === 'pending') return 'Awaiting quotation'
    return 'In progress'
}

const stageClass = (r) => {
    if (r.rfq_status === 'rejected') return 'pill-red'
    if (r.rfq_status === 'approved') return 'pill-green'
    return currentStage(r) ? 'pill-amber' : 'pill-grey'
}
</script>

<style scoped>
.corp-page { padding: 1.5rem; max-width: 1200px; margin: 0 auto; }
.corp-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
.corp-header h1 { margin: 0; font-size: 1.5rem; }
.sub { margin: 0.25rem 0 0; color: #6b7280; font-size: 0.85rem; }
.filters { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1rem; font-size: 0.85rem; }
.filters select { padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 6px; }
.table-wrap { overflow-x: auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; }
.corp-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
.corp-table th { text-align: left; padding: 0.75rem 1rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb; color: #6b7280; font-weight: 600; }
.corp-table td { padding: 0.75rem 1rem; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.desc { max-width: 320px; }
.empty { text-align: center; color: #6b7280; padding: 2rem 1rem; }
.link { color: #2563eb; text-decoration: none; }
.pill { display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; white-space: nowrap; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-red { background: #fee2e2; color: #991b1b; }
.pill-grey { background: #f3f4f6; color: #4b5563; }
.alert { padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.85rem; }
.alert-success { background: #f0fdf4; color: #166534; }
.alert-danger { background: #fef2f2; color: #991b1b; }
</style>
