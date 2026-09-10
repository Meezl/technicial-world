<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="organisations" />

        <main class="main-content">
            <header class="main-header">
                <div>
                    <Link href="/admin/organisations" class="back-link"><i class="fas fa-arrow-left"></i> Corporate Accounts</Link>
                    <h1>{{ organisation.name }}</h1>
                    <p class="header-sub">
                        {{ workflows[organisation.approval_workflow] }}
                        <span v-if="organisation.kra_pin"> · PIN {{ organisation.kra_pin }}</span>
                        <span v-if="!organisation.is_active" class="status-badge status-failed" style="margin-left:.5rem;">Inactive</span>
                    </p>
                </div>
            </header>

            <div v-if="successMessage" class="alert alert-success" style="margin:1rem;">{{ successMessage }}</div>
            <div v-if="errorMessage" class="alert alert-danger" style="margin:1rem;">{{ errorMessage }}</div>

            <!-- Readiness: what the account still needs before it can take work -->
            <section class="main-panel">
                <div class="panel-card full-width" :class="readiness.ready ? 'ready-ok' : 'ready-warn'">
                    <h3 style="margin-top:0;">
                        <i :class="readiness.ready ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle'"></i>
                        {{ readiness.ready ? 'Ready to take work' : 'Setup incomplete' }}
                    </h3>
                    <ul class="readiness-list">
                        <li v-for="(passed, key) in readiness.checks" :key="key">
                            <i :class="passed ? 'fas fa-check text-ok' : 'fas fa-times text-bad'"></i>
                            {{ readinessLabels[key] || key }}
                        </li>
                    </ul>
                </div>
            </section>

            <!-- Standing float: the money that unlocks their work -->
            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="panel-head">
                        <h3>Standing Float</h3>
                        <Link :href="`/admin/organisations/${organisation.id}/deposit`" class="btn btn-primary btn-sm">
                            <i class="fas fa-wallet"></i> Manage Float
                        </Link>
                    </div>
                    <p class="panel-note">
                        Corporate work is unlocked by how much of their deposit is left, not by whether an
                        individual job has been paid for. Book their float and set the top-up threshold here.
                    </p>
                </div>
            </section>

            <!-- Properties -->
            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="panel-head">
                        <h3>Properties <small class="muted">({{ organisation.properties.length }})</small></h3>
                        <button @click="openPropertyCreate" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus"></i> Add Property
                        </button>
                    </div>
                    <p class="panel-note">
                        The buildings, branches and stations this company looks after. A requester picks one
                        from this list when raising a job, and it is stamped on every quotation and invoice
                        that follows.
                    </p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Code</th>
                                    <th>Owner / Landlord</th>
                                    <th>Owner PIN</th>
                                    <th>Requests</th>
                                    <th>Status</th>
                                    <th style="width:110px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="prop in organisation.properties" :key="prop.id">
                                    <td><strong>{{ prop.name }}</strong><div v-if="prop.address"><small class="muted">{{ prop.address }}</small></div></td>
                                    <td><small>{{ prop.code || '—' }}</small></td>
                                    <td><small>{{ prop.owner_name || '—' }}</small></td>
                                    <td><small>{{ prop.owner_kra_pin || '—' }}</small></td>
                                    <td>{{ prop.service_requests_count }}</td>
                                    <td>
                                        <span :class="['status-badge', prop.is_active ? 'status-completed' : 'status-failed']">
                                            {{ prop.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>
                                        <button @click="openPropertyEdit(prop)" class="btn-icon" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button @click="deleteProperty(prop)" class="btn-icon text-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!organisation.properties.length">
                                    <td colspan="7" class="text-center">No properties yet. Add the buildings this company manages.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- People -->
            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="panel-head">
                        <h3>People <small class="muted">({{ organisation.members.length }})</small></h3>
                        <button @click="openMemberCreate" class="btn btn-primary btn-sm" :disabled="!assignableUsers.length">
                            <i class="fas fa-plus"></i> Add Person
                        </button>
                    </div>
                    <p class="panel-note">
                        Requesters raise jobs. Verifiers and approvers sign quotations off. Accounts handles
                        payment proofs and tax certificates. A person belongs to one company only —
                        create their login on the Users screen first, then attach it here.
                    </p>
                    <p v-if="!assignableUsers.length" class="panel-note warn">
                        No unattached client accounts available. Create one on the Users screen first.
                    </p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Position</th>
                                    <th>Name on Documents</th>
                                    <th>Approval Limit</th>
                                    <th>Status</th>
                                    <th style="width:110px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="m in organisation.members" :key="m.id">
                                    <td><strong>{{ m.user?.name }}</strong><div><small class="muted">{{ m.user?.email }}</small></div></td>
                                    <td><span class="status-badge">{{ positions[m.position] || m.position }}</span></td>
                                    <td><small>{{ m.display_name || '—' }}</small></td>
                                    <td><small>{{ m.can_approve_up_to ? `KES ${money(m.can_approve_up_to)}` : 'No limit' }}</small></td>
                                    <td>
                                        <span :class="['status-badge', m.is_active ? 'status-completed' : 'status-failed']">
                                            {{ m.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>
                                        <button @click="openMemberEdit(m)" class="btn-icon" title="Edit"><i class="fas fa-edit"></i></button>
                                        <button @click="deleteMember(m)" class="btn-icon text-danger" title="Remove"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!organisation.members.length">
                                    <td colspan="6" class="text-center">Nobody attached yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <!-- Property modal -->
        <div v-if="showPropertyModal" class="modal-overlay" @click.self="showPropertyModal = false">
            <div class="modal-content" style="max-width:560px;">
                <div class="modal-header">
                    <h3>{{ editingProperty ? 'Edit Property' : 'Add Property' }}</h3>
                    <button @click="showPropertyModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group" style="flex:2;">
                            <label>Name <span style="color:red;">*</span></label>
                            <input v-model="propertyForm.name" type="text" class="form-control" maxlength="190" />
                        </div>
                        <div class="form-group">
                            <label>Code</label>
                            <input v-model="propertyForm.code" type="text" class="form-control" maxlength="40" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea v-model="propertyForm.address" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Owner / Landlord</label>
                            <input v-model="propertyForm.owner_name" type="text" class="form-control" maxlength="190" />
                        </div>
                        <div class="form-group">
                            <label>Owner KRA PIN</label>
                            <input v-model="propertyForm.owner_kra_pin" type="text" class="form-control" maxlength="30" />
                            <small class="muted">The landlord pays, not the manager — this defaults onto the LPO.</small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Sort Order</label>
                            <input v-model.number="propertyForm.sort_order" type="number" min="0" max="9999" class="form-control" />
                        </div>
                        <div class="form-group" style="align-self:end;">
                            <label><input v-model="propertyForm.is_active" type="checkbox" /> Active (appears in the picker)</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showPropertyModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveProperty" :disabled="!propertyForm.name || saving" class="btn btn-primary">
                        {{ saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Member modal -->
        <div v-if="showMemberModal" class="modal-overlay" @click.self="showMemberModal = false">
            <div class="modal-content" style="max-width:520px;">
                <div class="modal-header">
                    <h3>{{ editingMember ? 'Edit Person' : 'Add Person' }}</h3>
                    <button @click="showMemberModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div v-if="!editingMember" class="form-group">
                        <label>Client Account <span style="color:red;">*</span></label>
                        <select v-model="memberForm.user_id" class="form-control">
                            <option :value="null" disabled>Select an account…</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.name }} — {{ u.email }}</option>
                        </select>
                    </div>
                    <div v-else class="form-group">
                        <label>Client Account</label>
                        <input :value="`${editingMember.user?.name} — ${editingMember.user?.email}`" class="form-control" disabled />
                    </div>
                    <div class="form-group">
                        <label>Position <span style="color:red;">*</span></label>
                        <select v-model="memberForm.position" class="form-control">
                            <option v-for="(label, key) in positions" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Name on Documents</label>
                        <input v-model="memberForm.display_name" type="text" class="form-control" maxlength="190" />
                        <small class="muted">Their nickname or the name the company uses on paperwork. Falls back to the account name.</small>
                    </div>
                    <div class="form-group">
                        <label>Approval Limit (KES)</label>
                        <input v-model="memberForm.can_approve_up_to" type="number" min="0" step="0.01" class="form-control" placeholder="Leave blank for no limit" />
                    </div>
                    <div class="form-group">
                        <label><input v-model="memberForm.is_active" type="checkbox" /> Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showMemberModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="saveMember" :disabled="saving || (!editingMember && !memberForm.user_id)" class="btn btn-primary">
                        {{ saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    organisation: { type: Object, required: true },
    workflows: { type: Object, required: true },
    positions: { type: Object, required: true },
    assignableUsers: { type: Array, required: true },
    readiness: { type: Object, required: true },
})

const page = usePage()
const successMessage = computed(() => page.props.flash?.success)
const errorMessage = computed(() => page.props.flash?.error)

const readinessLabels = {
    has_property: 'At least one active property',
    has_requester: 'Someone who can raise requests',
    has_verifier: 'A verifier (required by their two-stage workflow)',
    has_approver: 'An approver who can sign quotations off',
}

const saving = ref(false)
const base = `/admin/organisations/${props.organisation.id}`

// Always two decimals on money.
const money = (v) => Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// ---- Properties ----
const showPropertyModal = ref(false)
const editingProperty = ref(null)
const blankProperty = () => ({
    name: '', code: '', address: '', owner_name: '', owner_kra_pin: '',
    sort_order: 0, is_active: true,
})
const propertyForm = ref(blankProperty())

const openPropertyCreate = () => {
    editingProperty.value = null
    propertyForm.value = blankProperty()
    showPropertyModal.value = true
}
const openPropertyEdit = (prop) => {
    editingProperty.value = prop
    propertyForm.value = {
        name: prop.name,
        code: prop.code || '',
        address: prop.address || '',
        owner_name: prop.owner_name || '',
        owner_kra_pin: prop.owner_kra_pin || '',
        sort_order: prop.sort_order ?? 0,
        is_active: !!prop.is_active,
    }
    showPropertyModal.value = true
}
const saveProperty = () => {
    saving.value = true
    const opts = { onFinish: () => { saving.value = false; showPropertyModal.value = false } }
    if (editingProperty.value) {
        router.put(`${base}/properties/${editingProperty.value.id}`, propertyForm.value, opts)
    } else {
        router.post(`${base}/properties`, propertyForm.value, opts)
    }
}
const deleteProperty = (prop) => {
    if (prop.service_requests_count > 0) {
        alert(`${prop.name} has ${prop.service_requests_count} request(s) against it and cannot be deleted. Edit it and untick Active instead.`)
        return
    }
    if (!confirm(`Delete ${prop.name}?`)) return
    router.delete(`${base}/properties/${prop.id}`)
}

// ---- Members ----
const showMemberModal = ref(false)
const editingMember = ref(null)
const blankMember = () => ({
    user_id: null, position: Object.keys(props.positions)[0],
    display_name: '', can_approve_up_to: '', is_active: true,
})
const memberForm = ref(blankMember())

const openMemberCreate = () => {
    editingMember.value = null
    memberForm.value = blankMember()
    showMemberModal.value = true
}
const openMemberEdit = (m) => {
    editingMember.value = m
    memberForm.value = {
        user_id: m.user_id,
        position: m.position,
        display_name: m.display_name || '',
        can_approve_up_to: m.can_approve_up_to ?? '',
        is_active: !!m.is_active,
    }
    showMemberModal.value = true
}
const saveMember = () => {
    saving.value = true
    // An empty box means "no ceiling", not zero — a zero limit would block
    // every approval this person attempted.
    const payload = {
        ...memberForm.value,
        can_approve_up_to: memberForm.value.can_approve_up_to === '' ? null : memberForm.value.can_approve_up_to,
    }
    const opts = { onFinish: () => { saving.value = false; showMemberModal.value = false } }
    if (editingMember.value) {
        router.put(`${base}/members/${editingMember.value.id}`, payload, opts)
    } else {
        router.post(`${base}/members`, payload, opts)
    }
}
const deleteMember = (m) => {
    if (!confirm(`Remove ${m.user?.name} from ${props.organisation.name}?`)) return
    router.delete(`${base}/members/${m.id}`)
}
</script>

<style scoped>
.back-link { font-size: 0.8rem; color: #6b7280; text-decoration: none; }
.back-link:hover { color: #2563eb; }
.header-sub { margin: 0.25rem 0 0; color: #6b7280; font-size: 0.85rem; }
.muted { color: #6b7280; }
.panel-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
.panel-head h3 { margin: 0; }
.panel-note { color: #6b7280; font-size: 0.82rem; margin: 0.5rem 0 1rem; }
.panel-note.warn { color: #b45309; }
.readiness-list { list-style: none; padding: 0; margin: 0.5rem 0 0; display: flex; flex-wrap: wrap; gap: 1.25rem; }
.readiness-list li { font-size: 0.85rem; }
.text-ok { color: #16a34a; }
.text-bad { color: #dc2626; }
.ready-warn { border-left: 4px solid #f59e0b; }
.ready-ok { border-left: 4px solid #16a34a; }
.form-row { display: flex; gap: 1rem; }
.form-row .form-group { flex: 1; }
.btn-icon { background: none; border: none; cursor: pointer; padding: 0.3rem 0.5rem; color: #6b7280; }
.btn-icon:hover { color: #2563eb; }
.btn-icon.text-danger:hover { color: #dc2626; }
</style>
