<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="rates" />
        <main class="main-content">
            <header class="main-header">
                <div>
                    <h1>Rate Schedules</h1>
                    <p class="sub">The negotiated catalogue quotations are priced from.</p>
                </div>
                <button class="btn btn-primary btn-sm" @click="showNew = true"><i class="fas fa-plus"></i> New Schedule</button>
            </header>

            <div v-if="flash.success" class="alert alert-success" style="margin:1rem;">{{ flash.success }}</div>
            <div v-if="flash.error" class="alert alert-danger" style="margin:1rem;">{{ flash.error }}</div>

            <section class="main-panel">
                <div class="panel-card table-card full-width">
                    <p class="note">
                        A schedule is a version. Activating one supersedes the schedule it replaces rather than
                        overwriting it, so quotations raised against the old rates keep meaning what they meant.
                    </p>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead><tr><th>Schedule</th><th>Client</th><th class="num">Version</th><th class="num">Items</th><th>Status</th><th>Effective</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="s in schedules" :key="s.id">
                                    <td><Link :href="`/admin/rates/${s.id}`"><strong>{{ s.name }}</strong></Link></td>
                                    <td>{{ s.organisation?.name || 'House list' }}</td>
                                    <td class="num">v{{ s.version }}</td>
                                    <td class="num">{{ s.items_count }}</td>
                                    <td><span :class="['pill', pill(s.status)]">{{ statuses[s.status] || s.status }}</span></td>
                                    <td><small>{{ s.effective_from ? new Date(s.effective_from).toLocaleDateString() : '—' }}</small></td>
                                    <td><Link :href="`/admin/rates/${s.id}`" class="link">Open</Link></td>
                                </tr>
                                <tr v-if="!schedules.length"><td colspan="7" class="text-center">No schedules yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>

        <div v-if="showNew" class="modal-overlay" @click.self="showNew = false">
            <div class="modal-content" style="max-width:520px;">
                <div class="modal-header"><h3>New Rate Schedule</h3><button @click="showNew = false" class="close-btn">&times;</button></div>
                <div class="modal-body">
                    <div class="form-group"><label>Name <span class="req">*</span></label>
                        <input v-model="form.name" type="text" class="form-control" maxlength="190" /></div>
                    <div class="form-group">
                        <label>Client</label>
                        <select v-model="form.client_organisation_id" class="form-control">
                            <option :value="null">House list (any client without their own)</option>
                            <option v-for="o in organisations" :key="o.id" :value="o.id">{{ o.name }}</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Notes</label>
                        <textarea v-model="form.notes" class="form-control" rows="2" maxlength="1000"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button @click="showNew = false" class="btn btn-secondary">Cancel</button>
                    <button @click="create" :disabled="!form.name || busy" class="btn btn-primary">Create Draft</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

defineProps({
    schedules: { type: Array, required: true },
    organisations: { type: Array, required: true },
    statuses: { type: Object, required: true },
})

const page = usePage()
const flash = computed(() => page.props.flash || {})
const showNew = ref(false)
const busy = ref(false)
const form = reactive({ name: '', client_organisation_id: null, notes: '' })

const pill = (s) => ({ active: 'pill-green', draft: 'pill-amber', superseded: 'pill-grey' }[s] || 'pill-grey')
const create = () => {
    busy.value = true
    router.post('/admin/rates', { ...form }, { onFinish: () => { busy.value = false; showNew.value = false } })
}
</script>

<style scoped>
.sub { margin: .25rem 0 0; color: #6b7280; font-size: .85rem; }
.note { color: #6b7280; font-size: .8rem; margin: 0 0 1rem; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.link { color: #2563eb; text-decoration: none; }
.req { color: #dc2626; }
.pill { display: inline-block; padding: .12rem .55rem; border-radius: 999px; font-size: .72rem; }
.pill-green { background: #dcfce7; color: #166534; }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-grey { background: #f3f4f6; color: #4b5563; }
</style>
