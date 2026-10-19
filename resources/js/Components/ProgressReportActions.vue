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

        <!-- The common case, and the one the office had no control for: a
             photo that shows nothing, or a note that reads badly to the
             client. Neither is a disagreement about the figure, so neither
             should cost a lead an overrule or lose the report. -->
        <button type="button" class="pra-btn pra-btn-ghost" @click="toggleEdit">
            <i class="fas fa-pen"></i> {{ editing ? 'Cancel edit' : 'Edit notes & photos' }}
        </button>

        <button type="button" class="pra-btn pra-btn-danger" @click="remove">
            <i class="fas fa-trash"></i> Remove
        </button>

        <div v-if="editing" class="pra-edit">
            <p v-if="report.released_to_client_at" class="pra-warn">
                <i class="fas fa-triangle-exclamation"></i>
                The client has already been sent this report. Anything you change here, they will not
                have seen — tell them if it matters.
            </p>

            <label class="pra-field">
                <span>What the client reads</span>
                <textarea v-model="form.client_visible_notes" rows="3"></textarea>
            </label>

            <label class="pra-field">
                <span>Office note <em>(not shown to the client)</em></span>
                <textarea v-model="form.validation_notes" rows="2"></textarea>
            </label>

            <div v-if="livePhotos.length" class="pra-photos">
                <span class="pra-field-label">Photographs — untick any that should not go out</span>
                <label v-for="photo in livePhotos" :key="photo.id" class="pra-photo">
                    <input type="checkbox" :value="photo.id" v-model="keptPhotoIds">
                    <img :src="photoUrl(photo)" alt="">
                </label>
            </div>

            <label class="pra-field">
                <span>Add photographs</span>
                <input type="file" multiple accept="image/*" @change="onFiles">
            </label>

            <div class="pra-edit-actions">
                <button type="button" class="pra-btn pra-btn-primary" :disabled="saving" @click="save">
                    {{ saving ? 'Saving…' : 'Save changes' }}
                </button>
                <button type="button" class="pra-btn pra-btn-ghost" @click="toggleEdit">Cancel</button>
            </div>
        </div>

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
import { computed, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    report: { type: Object, required: true },
    // Only an admin may overrule someone who was on site.
    isAdmin: { type: Boolean, default: false },
})

const canOverrideLead = computed(
    () => props.isAdmin && !!props.report.approved_by_lead_at
)

const editing = ref(false)
const saving = ref(false)
const addedPhotos = ref([])
const form = reactive({ client_visible_notes: '', validation_notes: '' })

// Photos the office has already pulled are left out rather than shown ticked
// off — they are gone from the client's copy and this is not where they come
// back.
const livePhotos = computed(() => (props.report.photos || []).filter((p) => !p.removed_by_pm))
const keptPhotoIds = ref([])

const photoUrl = (photo) => photo.url || `/storage/${photo.file_path}`

const toggleEdit = () => {
    editing.value = !editing.value
    if (!editing.value) return

    form.client_visible_notes = props.report.client_visible_notes ?? props.report.notes ?? ''
    form.validation_notes = props.report.validation_notes ?? ''
    keptPhotoIds.value = livePhotos.value.map((p) => p.id)
    addedPhotos.value = []
}

const onFiles = (event) => {
    addedPhotos.value = Array.from(event.target.files || [])
}

const save = () => {
    saving.value = true

    // Unticked means taken out: the inverse of what is still kept.
    const removed = livePhotos.value
        .map((p) => p.id)
        .filter((id) => !keptPhotoIds.value.includes(id))

    router.post(
        route('progress-reports.amend', props.report.id),
        {
            client_visible_notes: form.client_visible_notes,
            validation_notes: form.validation_notes,
            remove_photo_ids: removed,
            photos: addedPhotos.value,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { editing.value = false },
            onFinish: () => { saving.value = false },
        }
    )
}

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

.pra-btn-primary { background: #1D4ED8; color: #fff; border-color: #1D4ED8; }
.pra-btn-primary:disabled { opacity: .6; cursor: default; }

.pra-edit {
    width: 100%; margin-top: .6rem; padding: .8rem; border: 1px solid #E5E7EB;
    border-radius: 8px; background: #F9FAFB; display: grid; gap: .7rem;
}
.pra-warn {
    margin: 0; font-size: .74rem; line-height: 1.5; color: #92400E;
    background: #FEF3C7; border-radius: 6px; padding: .5rem .6rem;
}
.pra-field { display: grid; gap: .25rem; font-size: .76rem; font-weight: 600; color: #374151; }
.pra-field em { font-weight: 400; opacity: .75; }
.pra-field textarea {
    font: inherit; font-weight: 400; padding: .45rem .55rem;
    border: 1px solid #D1D5DB; border-radius: 6px; resize: vertical;
}
.pra-field-label { font-size: .76rem; font-weight: 600; color: #374151; }
.pra-photos { display: grid; gap: .4rem; }
.pra-photo { display: inline-flex; align-items: center; gap: .35rem; margin-right: .5rem; }
.pra-photo img { width: 56px; height: 42px; object-fit: cover; border-radius: 4px; }
.pra-edit-actions { display: flex; gap: .5rem; }

.pra-flag {
    font-size: .74rem; color: #92400E; background: #FEF3C7;
    padding: .2rem .5rem; border-radius: 4px;
}
.pra-flag em { font-style: normal; opacity: .85; }
</style>
