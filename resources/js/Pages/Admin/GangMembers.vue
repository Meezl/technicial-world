<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="gang-members" />

        <main class="main-content">
            <header class="page-head">
                <div>
                    <span class="page-kicker">Gang Members</span>
                    <h1>On site, and not tradesmen</h1>
                    <p>
                        People who work on a job without carrying a trade. They join a job's crew with
                        a description of what they will be doing on site — never a task of their own —
                        and they are not paid through this system. Their details are held for the same
                        reason a technician's are: site security checks them at the gate.
                    </p>
                </div>
                <div class="gm-stats">
                    <div class="gm-stat">
                        <span class="gm-stat-value">{{ gangMembers.length }}</span>
                        <span class="gm-stat-label">On the books</span>
                    </div>
                    <div class="gm-stat" :class="missingIdCount ? 'gm-stat-warn' : ''">
                        <span class="gm-stat-value">{{ missingIdCount }}</span>
                        <span class="gm-stat-label">No ID on file</span>
                    </div>
                </div>
            </header>

            <section class="gm-panel">
                <div class="gm-panel-head">
                    <div>
                        <span class="page-kicker">Add</span>
                        <h2>Put somebody on the books</h2>
                    </div>
                </div>

                <form class="gm-form" @submit.prevent="submit">
                    <div class="gm-field">
                        <label>Full name</label>
                        <input v-model="form.name" type="text" required maxlength="255" placeholder="As it appears on their ID">
                        <small v-if="errors.name" class="gm-error">{{ errors.name }}</small>
                    </div>

                    <div class="gm-field">
                        <label>ID number</label>
                        <input v-model="form.national_id" type="text" required maxlength="32">
                        <small class="gm-help">Site security reads this off the gate list.</small>
                        <small v-if="errors.national_id" class="gm-error">{{ errors.national_id }}</small>
                    </div>

                    <div class="gm-field">
                        <label>Phone <span class="gm-optional">(optional)</span></label>
                        <input v-model="form.phone" type="text" maxlength="20">
                    </div>

                    <div class="gm-field">
                        <label>Location</label>
                        <input v-model="form.location" type="text" required maxlength="255" placeholder="Where they are based">
                        <small v-if="errors.location" class="gm-error">{{ errors.location }}</small>
                    </div>

                    <div class="gm-field gm-field-wide">
                        <label>Passport photo <span class="gm-optional">(optional)</span></label>
                        <input type="file" accept="image/jpeg,image/png" @change="onPhoto">
                        <small class="gm-help">
                            Shown on the gate list so whoever is on the gate can match a face to the name.
                        </small>
                    </div>

                    <div class="gm-field gm-field-wide">
                        <label>Notes <span class="gm-optional">(optional)</span></label>
                        <textarea v-model="form.bio" rows="2" maxlength="1000" placeholder="Anything the office should know"></textarea>
                    </div>

                    <div class="gm-actions">
                        <!-- No email, no password, no trade certificates: a gang
                             member carries no trade and is not paid through the
                             system, so there is nothing to certify and nowhere
                             to sign in. -->
                        <p class="gm-help gm-note">
                            No login is created. They carry no trade certificates and no KRA PIN,
                            because they are not paid through this system.
                        </p>
                        <button type="submit" class="btn btn-primary" :disabled="saving || !ready">
                            <i class="fas fa-user-plus"></i>
                            {{ saving ? 'Adding…' : 'Add gang member' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="gm-panel">
                <div class="gm-panel-head">
                    <div>
                        <span class="page-kicker">On the books</span>
                        <h2>{{ gangMembers.length }} {{ gangMembers.length === 1 ? 'person' : 'people' }}</h2>
                    </div>
                    <input v-model="search" type="search" class="gm-search" placeholder="Search by name or reference…">
                </div>

                <div v-if="matching.length" class="gm-table-wrap">
                    <table class="gm-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Name</th>
                                <th>Reference</th>
                                <th>ID No.</th>
                                <th>Location</th>
                                <th>Phone</th>
                                <th>Jobs</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="member in matching" :key="member.id">
                                <td>
                                    <img v-if="member.profile_photo_path" :src="`/storage/${member.profile_photo_path}`" class="gm-avatar" alt="">
                                    <span v-else class="gm-avatar gm-avatar-empty"><i class="fas fa-user"></i></span>
                                </td>
                                <td><strong>{{ member.user?.name }}</strong></td>
                                <td>{{ member.technician_id }}</td>
                                <td>
                                    <span v-if="member.national_id">{{ member.national_id }}</span>
                                    <span v-else class="gm-missing">Not on file</span>
                                </td>
                                <td>{{ member.location }}</td>
                                <td>{{ member.user?.phone || '—' }}</td>
                                <td>{{ member.jobs_count ?? 0 }}</td>
                                <td>
                                    <span :class="['gm-pill', member.is_active ? 'gm-pill-on' : 'gm-pill-off']">
                                        {{ member.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="gm-row-actions">
                                    <button type="button" class="btn btn-sm btn-secondary" title="Edit details" @click="openEditor(member)">
                                        <i class="fas fa-pen"></i>
                                    </button>
                                    <!-- Offered only to somebody who has never
                                         been on a job. Once they have, their
                                         name is on a gate list the client was
                                         sent, and the record cannot go without
                                         taking that with it. -->
                                    <button
                                        v-if="!member.total_assignments"
                                        type="button"
                                        class="btn btn-sm btn-danger"
                                        title="Remove from the books"
                                        @click="remove(member)"
                                    >
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button
                                        v-else
                                        type="button"
                                        class="btn btn-sm btn-secondary"
                                        :title="`${member.user?.name} has been on ${member.total_assignments} job${member.total_assignments === 1 ? '' : 's'} — mark them inactive instead of deleting`"
                                        @click="openEditor(member)"
                                    >
                                        <i class="fas fa-lock"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else-if="search" class="gm-empty">Nobody matches “{{ search }}”.</p>
                <p v-else class="gm-empty">
                    Nobody on the books yet. Add somebody above and they can be put on a job's crew.
                </p>
            </section>
            <div v-if="editing" class="gm-overlay" @click.self="editing = null">
                <div class="gm-modal">
                    <div class="gm-modal-head">
                        <div>
                            <span class="page-kicker">Edit</span>
                            <h2>{{ editing.user?.name }}</h2>
                            <p class="gm-help">{{ editing.technician_id }}</p>
                        </div>
                        <button type="button" class="gm-close" @click="editing = null">&times;</button>
                    </div>

                    <form class="gm-form" @submit.prevent="saveEdit">
                        <div class="gm-field">
                            <label>Full name</label>
                            <input v-model="editForm.name" type="text" required maxlength="255">
                        </div>

                        <div class="gm-field">
                            <label>ID number</label>
                            <input v-model="editForm.national_id" type="text" required maxlength="32">
                        </div>

                        <div class="gm-field">
                            <label>Phone <span class="gm-optional">(optional)</span></label>
                            <input v-model="editForm.phone" type="text" maxlength="20">
                        </div>

                        <div class="gm-field">
                            <label>Location</label>
                            <input v-model="editForm.location" type="text" required maxlength="255">
                        </div>

                        <div class="gm-field gm-field-wide">
                            <label>Replace passport photo <span class="gm-optional">(optional)</span></label>
                            <input type="file" accept="image/*" @change="onEditPhoto">
                            <small class="gm-help">Leave empty to keep the photo on file.</small>
                        </div>

                        <div class="gm-field gm-field-wide">
                            <label>Notes <span class="gm-optional">(optional)</span></label>
                            <textarea v-model="editForm.bio" rows="2" maxlength="1000"></textarea>
                        </div>

                        <!-- The way off the books for somebody who has worked:
                             out of the crew picker, still on every gate list
                             they were ever on. -->
                        <div class="gm-field gm-field-wide">
                            <label class="gm-check">
                                <input type="checkbox" v-model="editForm.is_active">
                                <span>On the books and available for crews</span>
                            </label>
                            <small class="gm-help">
                                Unticked, they stay on every job they have already been part of but are no
                                longer offered when building a crew.
                            </small>
                        </div>

                        <div class="gm-actions">
                            <button type="button" class="btn btn-secondary" @click="editing = null">Cancel</button>
                            <button type="submit" class="btn btn-primary" :disabled="savingEdit || !editReady">
                                {{ savingEdit ? 'Saving…' : 'Save changes' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../Components/AdminSidebar.vue'

const props = defineProps({
    gangMembers: { type: Array, default: () => [] },
    missingIdCount: { type: Number, default: 0 },
})

const page = usePage()
const errors = computed(() => page.props.errors || {})

const saving = ref(false)
const search = ref('')
const photo = ref(null)

const form = reactive({
    name: '',
    national_id: '',
    phone: '',
    location: '',
    bio: '',
})

const ready = computed(() =>
    form.name.trim() && form.national_id.trim() && form.location.trim(),
)

const matching = computed(() => {
    const q = search.value.trim().toLowerCase()
    if (!q) return props.gangMembers
    return props.gangMembers.filter(m =>
        (m.user?.name || '').toLowerCase().includes(q)
        || (m.technician_id || '').toLowerCase().includes(q)
        || (m.national_id || '').toLowerCase().includes(q),
    )
})

const onPhoto = (event) => {
    photo.value = event.target.files?.[0] || null
}

// ---- editing ----
const editing = ref(null)
const savingEdit = ref(false)
const editPhoto = ref(null)

const editForm = reactive({
    name: '',
    national_id: '',
    phone: '',
    location: '',
    bio: '',
    is_active: true,
})

const editReady = computed(() =>
    editForm.name.trim() && editForm.national_id.trim() && editForm.location.trim(),
)

const openEditor = (member) => {
    editing.value = member
    editPhoto.value = null
    Object.assign(editForm, {
        name: member.user?.name || '',
        national_id: member.national_id || '',
        phone: member.user?.phone || '',
        location: member.location || '',
        bio: member.bio || '',
        is_active: Boolean(member.is_active),
    })
}

const onEditPhoto = (event) => {
    editPhoto.value = event.target.files?.[0] || null
}

const saveEdit = () => {
    if (!editReady.value || savingEdit.value) return
    savingEdit.value = true

    router.post(`/admin/gang-members/${editing.value.id}`, {
        ...editForm,
        passport_photo: editPhoto.value,
    }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { editing.value = null },
        onFinish: () => { savingEdit.value = false },
    })
}

const remove = (member) => {
    if (!confirm(
        `Remove ${member.user?.name} from the books?\n\n`
        + `They have never been on a job, so nothing else refers to them and this cannot be undone.`
    )) return

    router.delete(`/admin/gang-members/${member.id}`, { preserveScroll: true })
}

const submit = () => {
    if (!ready.value || saving.value) return
    saving.value = true

    router.post('/admin/gang-members', {
        ...form,
        passport_photo: photo.value,
    }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            Object.assign(form, { name: '', national_id: '', phone: '', location: '', bio: '' })
            photo.value = null
        },
        onFinish: () => { saving.value = false },
    })
}
</script>

<style scoped>
.gm-stats { display: flex; gap: 0.75rem; }
.gm-stat {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.6rem 0.9rem;
    display: flex;
    flex-direction: column;
    min-width: 96px;
}
.gm-stat-warn { background: #fffbeb; border-color: #fde68a; }
.gm-stat-value { font-size: 1.35rem; font-weight: 700; color: #0f172a; }
.gm-stat-label { font-size: 0.75rem; color: #64748b; }

.gm-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 1.15rem 1.25rem;
    margin-top: 1.25rem;
}
.gm-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 0.9rem;
}
.gm-panel-head h2 { margin: 0.15rem 0 0; font-size: 1.05rem; color: #0f172a; }

.gm-form {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 0.9rem;
}
.gm-field { display: flex; flex-direction: column; gap: 0.25rem; }
.gm-field-wide { grid-column: 1 / -1; }
.gm-field label { font-size: 0.82rem; font-weight: 600; color: #334155; }
.gm-field input,
.gm-field textarea {
    padding: 0.5rem 0.7rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.88rem;
}
.gm-optional { font-weight: 400; color: #94a3b8; }
.gm-help { font-size: 0.75rem; color: #64748b; }
.gm-error { font-size: 0.75rem; color: #b91c1c; }
.gm-actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}
.gm-note { max-width: 46ch; margin: 0; }

.gm-search {
    padding: 0.45rem 0.7rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.85rem;
    min-width: 220px;
}
.gm-table-wrap { overflow-x: auto; }
.gm-table { width: 100%; border-collapse: collapse; font-size: 0.87rem; }
.gm-table th {
    text-align: left;
    padding: 0.5rem 0.6rem;
    color: #64748b;
    font-size: 0.73rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    border-bottom: 1px solid #e2e8f0;
}
.gm-table td { padding: 0.55rem 0.6rem; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
.gm-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    object-fit: cover;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.gm-avatar-empty { background: #f1f5f9; color: #94a3b8; }
.gm-missing { color: #b45309; font-weight: 600; }
.gm-empty { color: #64748b; font-size: 0.88rem; margin: 0; }

.gm-row-actions { display: flex; gap: 0.35rem; }
.gm-pill {
    display: inline-block;
    padding: 0.15rem 0.5rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
}
.gm-pill-on { background: #ecfdf5; color: #065f46; }
.gm-pill-off { background: #f1f5f9; color: #64748b; }
.gm-check { display: flex; align-items: center; gap: 0.45rem; font-weight: 600; font-size: 0.82rem; color: #334155; }

.gm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    z-index: 50;
}
.gm-modal {
    background: #fff;
    border-radius: 14px;
    padding: 1.25rem;
    width: min(680px, 100%);
    max-height: 90vh;
    overflow-y: auto;
}
.gm-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 0.9rem;
}
.gm-modal-head h2 { margin: 0.15rem 0 0; font-size: 1.05rem; color: #0f172a; }
.gm-close {
    background: none;
    border: 0;
    font-size: 1.5rem;
    line-height: 1;
    color: #94a3b8;
    cursor: pointer;
}
</style>
