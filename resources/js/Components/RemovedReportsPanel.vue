<template>
    <div v-if="removed.length" class="rrp">
        <button type="button" class="rrp-toggle" @click="open = !open">
            <i class="fas" :class="open ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
            {{ removed.length }} removed report{{ removed.length === 1 ? '' : 's' }}
        </button>

        <div v-if="open" class="rrp-list">
            <p class="rrp-note">
                These are out of circulation and count towards nothing. They are kept
                so the record stays complete, and can be put back.
            </p>

            <div v-for="r in removed" :key="r.id" class="rrp-item">
                <div>
                    <strong>{{ r.percent_complete }}%</strong>
                    <span class="rrp-who">
                        {{ r.technician?.user?.name || 'Unknown technician' }}
                        · {{ formatDate(r.report_date) }}
                    </span>
                    <p class="rrp-reason">
                        Removed by {{ r.deleted_by_user?.name || r.deleted_by?.name || 'someone' }}
                        — {{ r.deletion_reason }}
                    </p>
                </div>
                <button type="button" class="rrp-btn" @click="restore(r)">
                    <i class="fas fa-rotate-left"></i> Restore
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * Reports the office has taken out.
 *
 * Collapsed by default — the point of removing a duplicate is that it stops
 * cluttering the job, and a permanently expanded list of them would undo
 * that. Restoring asks why, the same as removing did, so the record carries
 * both halves of the decision.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    job: { type: Object, required: true },
})

const open = ref(false)
const removed = computed(() => props.job.removed_progress_reports || [])

const formatDate = (d) =>
    d ? new Date(d).toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: 'numeric' }) : ''

const restore = (report) => {
    const reason = prompt(
        'Restore this report?\n\nIt will count towards progress again.\n\nWhy is it being restored?'
    )
    if (!reason || !reason.trim()) return

    router.post(
        route('progress-reports.restore', report.id),
        { reason },
        { preserveScroll: true }
    )
}
</script>

<style scoped>
.rrp { margin-top: 1rem; }

.rrp-toggle {
    display: inline-flex; align-items: center; gap: .45rem;
    background: none; border: 0; padding: .25rem 0;
    font-size: .8rem; font-weight: 600; color: #6B7280;
    cursor: pointer; font-family: inherit;
}
.rrp-toggle:hover { color: #374151; }

.rrp-list {
    margin-top: .5rem; padding: .75rem;
    border: 1px dashed #D1D5DB; border-radius: 8px; background: #F9FAFB;
}
.rrp-note { margin: 0 0 .6rem; font-size: .76rem; color: #6B7280; line-height: 1.5; }

.rrp-item {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;
    padding: .5rem 0; border-top: 1px solid #E5E7EB;
}
.rrp-item:first-of-type { border-top: 0; }
.rrp-item strong { font-size: .85rem; color: #374151; }
.rrp-who { font-size: .76rem; color: #6B7280; margin-left: .4rem; }
.rrp-reason { margin: .2rem 0 0; font-size: .76rem; color: #6B7280; }

.rrp-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    border: 1px solid #D1D5DB; background: #fff; border-radius: 6px;
    padding: .3rem .65rem; font-size: .75rem; font-weight: 600;
    color: #374151; cursor: pointer; font-family: inherit; white-space: nowrap;
}
.rrp-btn:hover { background: #F3F4F6; }
</style>
