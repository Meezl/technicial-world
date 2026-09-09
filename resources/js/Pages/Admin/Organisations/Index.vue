<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="organisations" />

        <main class="main-content">
            <header class="main-header">
                <div>
                    <h1>Corporate Accounts</h1>
                    <p class="header-sub">Property management companies, their buildings and their people.</p>
                </div>
                <button @click="openCreate" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> New Account
                </button>
            </header>

            <div v-if="successMessage" class="alert alert-success" style="margin:1rem;">{{ successMessage }}</div>
            <div v-if="errorMessage" class="alert alert-danger" style="margin:1rem;">{{ errorMessage }}</div>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Company</th>
                                    <th>Approval Workflow</th>
                                    <th>Properties</th>
                                    <th>People</th>
                                    <th>Requests</th>
                                    <th>Status</th>
                                    <th style="width:150px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="org in organisations" :key="org.id">
                                    <td>
                                        <Link :href="`/admin/organisations/${org.id}`"><strong>{{ org.name }}</strong></Link>
                                        <div><small class="muted">{{ org.billing_email || 'No billing email' }}</small></div>
                                    </td>
                                    <td><small>{{ workflows[org.approval_workflow] || org.approval_workflow }}</small></td>
                                    <td>{{ org.properties_count }}</td>
                                    <td>{{ org.members_count }}</td>
                                    <td>{{ org.service_requests_count }}</td>
                                    <td>
                                        <span :class="['status-badge', org.is_active ? 'status-completed' : 'status-failed']">
                                            {{ org.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>
                                        <Link :href="`/admin/organisations/${org.id}`" class="btn-icon" title="Open">
                                            <i class="fas fa-arrow-right"></i>
                                        </Link>
                                        <button @click="openEdit(org)" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button @click="confirmDelete(org)" class="btn-icon text-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!organisations.length">
                                    <td colspan="7" class="text-center">
                                        No corporate accounts yet. Click "New Account" to onboard a management company.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
            <div class="modal-content" style="max-width:560px;">
                <div class="modal-header">
                    <h3>{{ editing ? 'Edit Account' : 'New Corporate Account' }}</h3>
                    <button @click="showModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Company Name <span style="color:red;">*</span></label>
                        <input v-model="form.name" type="text" class="form-control" maxlength="190" required />
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>KRA PIN</label>
                            <input v-model="form.kra_pin" type="text" class="form-control" maxlength="30" />
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input v-model="form.phone" type="text" class="form-control" maxlength="40" />
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Billing Email</label>
                        <input v-model="form.billing_email" type="email" class="form-control" maxlength="190" />
                        <small class="muted">Where proforma invoices are sent when the deposit hits its threshold.</small>
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <textarea v-model="form.address" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Approval Workflow <span style="color:red;">*</span></label>
                        <select v-model="form.approval_workflow" class="form-control">
                            <option v-for="(label, key) in workflows" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <small class="muted">How many of their people must sign off on a quotation.</small>
                    </div>
                    <div class="form-group">
                        <label><input v-model="form.is_active" type="checkbox" /> Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="save" :disabled="!form.name || saving" class="btn btn-primary">
                        {{ saving ? 'Saving…' : (editing ? 'Save Changes' : 'Create Account') }}
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
    organisations: { type: Array, required: true },
    workflows: { type: Object, required: true },
})

const page = usePage()
const successMessage = computed(() => page.props.flash?.success)
const errorMessage = computed(() => page.props.flash?.error)

const showModal = ref(false)
const editing = ref(null)
const saving = ref(false)

const blank = () => ({
    name: '', kra_pin: '', billing_email: '', phone: '', address: '',
    approval_workflow: Object.keys(props.workflows)[0],
    is_active: true,
})
const form = ref(blank())

const openCreate = () => {
    editing.value = null
    form.value = blank()
    showModal.value = true
}

const openEdit = (org) => {
    editing.value = org
    form.value = {
        name: org.name,
        kra_pin: org.kra_pin || '',
        billing_email: org.billing_email || '',
        phone: org.phone || '',
        address: org.address || '',
        approval_workflow: org.approval_workflow,
        is_active: !!org.is_active,
    }
    showModal.value = true
}

const save = () => {
    saving.value = true
    const opts = { onFinish: () => { saving.value = false; showModal.value = false } }
    if (editing.value) {
        router.put(`/admin/organisations/${editing.value.id}`, form.value, opts)
    } else {
        router.post('/admin/organisations', form.value, opts)
    }
}

const confirmDelete = (org) => {
    if (org.service_requests_count > 0) {
        alert(`${org.name} has ${org.service_requests_count} request(s) on record and cannot be deleted. Edit it and untick Active instead.`)
        return
    }
    if (!confirm(`Delete ${org.name}? Its properties and people go with it. This cannot be undone.`)) return
    router.delete(`/admin/organisations/${org.id}`)
}
</script>

<style scoped>
.header-sub { margin: 0.25rem 0 0; color: #6b7280; font-size: 0.85rem; }
.muted { color: #6b7280; }
.form-row { display: flex; gap: 1rem; }
.form-row .form-group { flex: 1; }
.btn-icon { background: none; border: none; cursor: pointer; padding: 0.3rem 0.5rem; color: #6b7280; }
.btn-icon:hover { color: #2563eb; }
.btn-icon.text-danger:hover { color: #dc2626; }
</style>
