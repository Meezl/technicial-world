<template>
    <div class="jd-panel">
        <div class="jd-head">
            <div>
                <strong>Documents</strong>
                <span v-if="documents.length" class="jd-count">{{ documents.length }}</span>
            </div>
            <button type="button" class="jd-btn jd-btn-primary" @click="showForm = !showForm">
                <i class="fas" :class="showForm ? 'fa-times' : 'fa-plus'"></i>
                {{ showForm ? 'Cancel' : 'Add document' }}
            </button>
        </div>

        <form v-if="showForm" class="jd-form" @submit.prevent="submit">
            <div class="jd-field">
                <label>File <span class="jd-req">*</span></label>
                <input ref="fileInput" type="file" required @input="onFile" />
                <p class="jd-hint">PDF, Office documents or images, up to 20&nbsp;MB.</p>
            </div>

            <div class="jd-field-row">
                <div class="jd-field">
                    <label>Title <span class="jd-req">*</span></label>
                    <input v-model="form.title" type="text" maxlength="200" required
                           placeholder="e.g. Installation spec — rev B" />
                </div>
                <div class="jd-field">
                    <label>Kind</label>
                    <select v-model="form.kind">
                        <option v-for="(label, value) in uploadableKinds" :key="value" :value="value">
                            {{ label }}
                        </option>
                    </select>
                </div>
            </div>

            <div class="jd-field">
                <label>Notes</label>
                <textarea v-model="form.notes" rows="2" maxlength="2000"
                          placeholder="Context for whoever opens this in six months (optional)"></textarea>
            </div>

            <label class="jd-check">
                <input v-model="form.is_client_visible" type="checkbox" />
                <span>Share with the client</span>
            </label>

            <!-- The whole point of the dedicated kinds: say plainly, before the
                 upload, whether the crew on site will see it. -->
            <p v-if="form.is_client_visible && reachesTechnician(form.kind)" class="jd-reach">
                <i class="fas fa-hard-hat"></i>
                The technician on site will see this too.
            </p>
            <p v-else-if="form.is_client_visible" class="jd-hint">
                Shared with the client only — a {{ (documentKinds[form.kind] || form.kind) }} is not shown to the technician.
            </p>
            <p v-else class="jd-hint">
                Internal only. Nobody outside the office sees it until you share it.
            </p>

            <div v-if="form.errors && Object.keys(form.errors).length" class="jd-errors">
                <p v-for="(msg, key) in form.errors" :key="key">{{ msg }}</p>
            </div>

            <div class="jd-form-actions">
                <button type="submit" class="jd-btn jd-btn-primary" :disabled="form.processing">
                    {{ form.processing ? 'Uploading…' : 'Upload' }}
                </button>
            </div>
        </form>

        <p v-if="!documents.length" class="jd-empty">
            No documents held against this job yet.
        </p>

        <div v-for="doc in documents" :key="doc.id" class="jd-card">
            <div class="jd-card-head">
                <div class="jd-card-title">
                    <a :href="`/storage/${doc.path}`" target="_blank" rel="noopener">
                        <i class="fas fa-file-alt"></i> {{ doc.title || doc.original_name }}
                    </a>
                    <div class="jd-chips">
                        <span class="jd-chip jd-chip-muted">{{ kindLabel(doc.kind) }}</span>
                        <span v-if="doc.is_client_visible" class="jd-chip jd-chip-shared">Shared with client</span>
                        <span v-else class="jd-chip jd-chip-internal">Internal</span>
                        <span v-if="doc.is_client_visible && reachesTechnician(doc.kind)" class="jd-chip jd-chip-tech">
                            <i class="fas fa-hard-hat"></i> Technician
                        </span>
                    </div>
                </div>
            </div>

            <p v-if="doc.notes" class="jd-notes">{{ doc.notes }}</p>

            <div class="jd-meta">
                <span v-if="doc.uploader">Added by {{ doc.uploader.name }}</span>
                <span v-if="doc.size_bytes">{{ fileSize(doc.size_bytes) }}</span>
                <span v-if="doc.created_at">{{ formatDate(doc.created_at) }}</span>
            </div>

            <div class="jd-actions">
                <button type="button" class="jd-btn jd-btn-ghost" @click="toggleVisibility(doc)">
                    <i class="fas" :class="doc.is_client_visible ? 'fa-eye-slash' : 'fa-share'"></i>
                    {{ doc.is_client_visible ? 'Make internal' : 'Share with client' }}
                </button>
                <button type="button" class="jd-btn jd-btn-danger" @click="remove(doc)">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
/**
 * Documents a job accumulates over its life, for admin and PM.
 *
 * A document is internal until it is deliberately shared. Sharing a client
 * upload or an ops-drawn spec also puts it in front of the technician on site
 * — the office's commercial paperwork (quotations, approvals, case analyses)
 * never reaches the crew, whatever its visibility. That rule lives on the
 * server (ServiceRequestDocument::scopeTechnicianVisible); the badge and the
 * form note here only mirror it, driven by technicianVisibleKinds.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

const props = defineProps({
    job: { type: Object, required: true },
    documentKinds: { type: Object, default: () => ({}) },
    technicianVisibleKinds: { type: Array, default: () => [] },
})

const showForm = ref(false)
const fileInput = ref(null)

const documents = computed(() => props.job.documents || [])

const form = useForm({
    file: null,
    title: '',
    // Ops uploads are most often a spec or drawing for the crew — the kind
    // this form was built around — so it leads.
    kind: 'spec',
    notes: '',
    is_client_visible: false,
})

const reachesTechnician = (kind) => props.technicianVisibleKinds.includes(kind)

const kindLabel = (kind) => props.documentKinds[kind] || kind

// Kinds ops may upload. "Client upload" is the client's own channel for
// sending a file in — not something the office files on their behalf — and
// "Photo" belongs to the job's photo evidence, uploaded elsewhere. Neither is
// offered in this form, though existing documents of those kinds still list
// with their label above.
const HIDDEN_UPLOAD_KINDS = ['client_upload', 'photo']
const uploadableKinds = computed(() =>
    Object.fromEntries(
        Object.entries(props.documentKinds).filter(([value]) => !HIDDEN_UPLOAD_KINDS.includes(value)),
    ),
)

const onFile = (e) => {
    form.file = e.target.files[0] || null
}

const fileSize = (bytes) => {
    if (!bytes) return ''
    const kb = bytes / 1024
    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`
}

const formatDate = (value) =>
    new Date(value).toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: 'numeric' })

const submit = () => {
    form.post(route('jobs.documents.store', props.job.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset()
            if (fileInput.value) fileInput.value.value = ''
            showForm.value = false
        },
    })
}

const toggleVisibility = (doc) => {
    router.post(
        route('jobs.documents.visibility', [props.job.id, doc.id]),
        { is_client_visible: !doc.is_client_visible },
        { preserveScroll: true },
    )
}

const remove = (doc) => {
    if (!confirm(`Delete "${doc.title || doc.original_name}"? This cannot be undone.`)) return
    router.delete(route('jobs.documents.destroy', [props.job.id, doc.id]), { preserveScroll: true })
}
</script>

<style scoped>
.jd-panel { display: flex; flex-direction: column; gap: 1rem; }

.jd-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.jd-head strong { font-size: 1rem; color: #111827; }
.jd-count {
    display: inline-block; margin-left: .5rem; padding: .05rem .45rem;
    border-radius: 999px; background: #E5E7EB; color: #374151; font-size: .72rem;
}

.jd-form {
    border: 1px solid #E5E7EB; border-radius: 8px; padding: 1rem;
    background: #F9FAFB; display: flex; flex-direction: column; gap: .8rem;
}
.jd-field-row { display: flex; gap: .75rem; flex-wrap: wrap; }
.jd-field-row .jd-field { flex: 1 1 12rem; }
.jd-field { display: flex; flex-direction: column; gap: .3rem; }
.jd-field label { font-size: .75rem; font-weight: 600; color: #374151; text-transform: uppercase; letter-spacing: .03em; }
.jd-req { color: #DC2626; }
.jd-form input[type="text"], .jd-form select, .jd-form textarea, .jd-form input[type="file"] {
    border: 1px solid #D1D5DB; border-radius: 6px; padding: .45rem .6rem;
    font-size: .85rem; font-family: inherit; background: #fff; width: 100%;
}
.jd-form textarea { resize: vertical; }
.jd-hint { margin: 0; font-size: .76rem; color: #6B7280; line-height: 1.45; }

.jd-check { display: flex; align-items: center; gap: .5rem; font-size: .85rem; color: #374151; cursor: pointer; }
.jd-check input { width: auto; }

.jd-reach {
    margin: 0; display: flex; align-items: center; gap: .4rem;
    padding: .5rem .7rem; border-radius: 6px; font-size: .78rem;
    background: #E8F0FB; border: 1px solid #C7D8EE; color: #16325c;
}

.jd-errors { background: #FEE2E2; border-radius: 6px; padding: .6rem .85rem; }
.jd-errors p { margin: 0 0 .25rem; color: #991B1B; font-size: .8rem; }
.jd-errors p:last-child { margin-bottom: 0; }
.jd-form-actions { display: flex; justify-content: flex-end; }

.jd-empty { margin: 0; font-size: .82rem; color: #6B7280; }

.jd-card { border: 1px solid #E5E7EB; border-radius: 8px; padding: .85rem; background: #fff; }
.jd-card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; flex-wrap: wrap; }
.jd-card-title { display: flex; flex-direction: column; gap: .4rem; }
.jd-card-title > a { font-size: .9rem; font-weight: 600; color: #053272; text-decoration: none; }
.jd-card-title > a:hover { text-decoration: underline; }
.jd-chips { display: flex; gap: .4rem; flex-wrap: wrap; }
.jd-notes { margin: .5rem 0 .35rem; font-size: .82rem; color: #374151; }
.jd-meta { display: flex; gap: .85rem; flex-wrap: wrap; font-size: .74rem; color: #6B7280; margin-top: .35rem; }

.jd-chip {
    display: inline-flex; align-items: center; gap: .25rem; padding: .08rem .45rem;
    border-radius: 999px; font-size: .68rem; font-weight: 600;
}
.jd-chip-muted { background: #F3F4F6; color: #6B7280; }
.jd-chip-shared { background: #DBEAFE; color: #1E40AF; }
.jd-chip-internal { background: #F3F4F6; color: #6B7280; }
.jd-chip-tech { background: #DCFCE7; color: #166534; }

.jd-actions { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-top: .75rem; }

.jd-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    border-radius: 6px; padding: .4rem .8rem; font-size: .8rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; font-family: inherit;
}
.jd-btn:disabled { opacity: .55; cursor: not-allowed; }
.jd-btn-primary { background: #053272; color: #fff; }
.jd-btn-primary:hover:not(:disabled) { background: #04255a; }
.jd-btn-ghost { background: #fff; color: #374151; border-color: #D1D5DB; }
.jd-btn-ghost:hover:not(:disabled) { background: #F9FAFB; }
.jd-btn-danger { background: #fff; color: #B91C1C; border-color: #FCA5A5; }
.jd-btn-danger:hover:not(:disabled) { background: #FEF2F2; }
</style>
