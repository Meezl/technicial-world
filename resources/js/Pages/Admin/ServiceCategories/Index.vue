<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="service-categories" />

        <main class="main-content">
            <header class="main-header">
                <h1>Service Categories</h1>
                <button @click="openCreate" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> New Category
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
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Active</th>
                                    <th>In Use</th>
                                    <th style="width:140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="cat in categories" :key="cat.id">
                                    <td><strong>{{ cat.name }}</strong></td>
                                    <td><small>{{ cat.description || '—' }}</small></td>
                                    <td>
                                        <span :class="['status-badge', cat.is_active ? 'status-completed' : 'status-failed']">
                                            {{ cat.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>{{ cat.service_requests_count }} request(s)</td>
                                    <td>
                                        <button @click="openEdit(cat)" class="btn-icon" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button @click="confirmDelete(cat)" class="btn-icon text-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!categories.length">
                                    <td colspan="5" class="text-center">No categories yet. Click "New Category" to create one.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <!-- Create/Edit Modal -->
        <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
            <div class="modal-content" style="max-width:480px;">
                <div class="modal-header">
                    <h3>{{ editing ? 'Edit Category' : 'New Category' }}</h3>
                    <button @click="showModal = false" class="close-btn">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Name <span style="color:red;">*</span></label>
                        <input v-model="form.name" type="text" class="form-control" maxlength="120" required />
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea v-model="form.description" class="form-control" maxlength="500" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label>Icon (Font Awesome class, optional)</label>
                        <input v-model="form.icon" type="text" class="form-control" placeholder="fas fa-bolt" />
                    </div>
                    <div class="form-group">
                        <label>
                            <input v-model="form.is_active" type="checkbox" /> Active (visible to clients)
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button @click="showModal = false" class="btn btn-secondary">Cancel</button>
                    <button @click="save" :disabled="!form.name || saving" class="btn btn-primary">
                        {{ saving ? 'Saving…' : (editing ? 'Save Changes' : 'Create Category') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

defineProps({
    categories: { type: Array, required: true },
})

const page = usePage()
const successMessage = computed(() => page.props.flash?.success)
const errorMessage = computed(() => page.props.flash?.error)

const showModal = ref(false)
const editing = ref(null)
const saving = ref(false)
const form = ref({ name: '', description: '', icon: '', is_active: true })

const openCreate = () => {
    editing.value = null
    form.value = { name: '', description: '', icon: '', is_active: true }
    showModal.value = true
}
const openEdit = (cat) => {
    editing.value = cat
    form.value = {
        name: cat.name,
        description: cat.description || '',
        icon: cat.icon || '',
        is_active: !!cat.is_active,
    }
    showModal.value = true
}

const save = () => {
    saving.value = true
    const opts = {
        onFinish: () => {
            saving.value = false
            showModal.value = false
        },
    }
    if (editing.value) {
        router.put(`/admin/service-categories/${editing.value.id}`, form.value, opts)
    } else {
        router.post('/admin/service-categories', form.value, opts)
    }
}

const confirmDelete = (cat) => {
    if (cat.service_requests_count > 0) {
        if (!confirm(`${cat.name} is linked to ${cat.service_requests_count} service request(s). Deleting will fail — would you like to deactivate it instead?`)) return
        router.put(`/admin/service-categories/${cat.id}`, { ...cat, is_active: false })
        return
    }
    if (!confirm(`Delete ${cat.name}? This cannot be undone.`)) return
    router.delete(`/admin/service-categories/${cat.id}`)
}
</script>

<style scoped>
.btn-icon { background: none; border: none; cursor: pointer; padding: 0.3rem 0.5rem; color: #6b7280; }
.btn-icon:hover { color: #2563eb; }
.btn-icon.text-danger:hover { color: #dc2626; }
</style>
