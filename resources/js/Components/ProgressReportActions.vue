<template>
    <div class="pra">
        <!-- Overruling a lead is admin-only and only offered while the lead's
             sign-off is still what stands. Once the office ratifies, changing
             the figure is ordinary validation. -->
        <button
            v-if="canOverrideLead"
            type="button"
            class="pra-btn pra-btn-ghost"
            @click="overrideLead"
        >
            <i class="fas fa-gavel"></i> Overrule lead
        </button>

        <button type="button" class="pra-btn pra-btn-danger" @click="remove">
            <i class="fas fa-trash"></i> Remove
        </button>

        <span v-if="report.lead_override_at" class="pra-flag">
            <i class="fas fa-gavel"></i>
            Lead signed {{ report.lead_approved_percent }}%, office set
            {{ report.validated_percent }}%
            <em v-if="report.lead_override_reason">— {{ report.lead_override_reason }}</em>
        </span>
    </div>
</template>

<script setup>
/**
 * Per-report controls for the office.
 *
 * Removal is housekeeping — the duplicate a technician filed by tapping
 * twice. Sending a report back to argue about what was done is a different
 * action and lives elsewhere on the card.
 */
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    report: { type: Object, required: true },
    // Only an admin may overrule someone who was on site.
    isAdmin: { type: Boolean, default: false },
})

const canOverrideLead = computed(
    () => props.isAdmin && !!props.report.approved_by_lead_at
)

const remove = () => {
    const reason = prompt(
        'Remove this report?\n\nIt stays in the record and can be restored. ' +
        'Progress will be recalculated from the remaining reports.\n\nWhy is it being removed?'
    )
    if (!reason || !reason.trim()) return

    router.delete(route('progress-reports.destroy', props.report.id), {
        data: { reason },
        preserveScroll: true,
    })
}

const overrideLead = () => {
    const current = props.report.validated_percent ?? props.report.percent_complete
    const percent = prompt(
        `The lead signed this off at ${current}%.\n\nWhat should it be?`,
        String(current)
    )
    if (percent === null) return

    const reason = prompt('Why are you overruling the lead?')
    if (!reason || !reason.trim()) return

    router.post(
        route('progress-reports.override-lead', props.report.id),
        { validated_percent: Number(percent), reason },
        { preserveScroll: true }
    )
}
</script>

<style scoped>
.pra { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-top: .6rem; }

.pra-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    border-radius: 6px; padding: .35rem .7rem; font-size: .76rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; font-family: inherit;
}
.pra-btn-ghost { background: #fff; color: #374151; border-color: #D1D5DB; }
.pra-btn-ghost:hover { background: #F9FAFB; }
.pra-btn-danger { background: #fff; color: #B91C1C; border-color: #FCA5A5; }
.pra-btn-danger:hover { background: #FEF2F2; }

.pra-flag {
    font-size: .74rem; color: #92400E; background: #FEF3C7;
    padding: .2rem .5rem; border-radius: 4px;
}
.pra-flag em { font-style: normal; opacity: .85; }
</style>
