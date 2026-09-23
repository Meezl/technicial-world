<template>
    <div class="corp-page narrow">
        <Link href="/corporate/requests" class="back"><i class="fas fa-arrow-left"></i> Back to jobs</Link>
        <h1>Raise a Job</h1>
        <p class="sub">{{ membership.organisation.name }} · raised by {{ membership.name_on_documents }}</p>

        <form class="card" @submit.prevent="submit">
            <div class="field">
                <label>Property <span class="req">*</span></label>
                <select v-model="form.property_id" required>
                    <option :value="null" disabled>Select the building…</option>
                    <option v-for="p in properties" :key="p.id" :value="p.id">
                        {{ p.code ? `${p.name} (${p.code})` : p.name }}
                    </option>
                </select>
                <small v-if="errors.property_id" class="err">{{ errors.property_id }}</small>
                <small v-else class="hint">This is stamped on the quotation and every invoice that follows.</small>
            </div>

            <div class="field">
                <label>Trade <span class="req">*</span></label>
                <select v-model="form.service_category_id" required>
                    <option :value="null" disabled>Select…</option>
                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <small v-if="errors.service_category_id" class="err">{{ errors.service_category_id }}</small>
            </div>

            <div class="field">
                <label>Where exactly <span class="req">*</span></label>
                <input v-model="form.location" type="text" maxlength="255" placeholder="e.g. 14th floor gents toilets, cubicle 1" required />
                <small v-if="errors.location" class="err">{{ errors.location }}</small>
                <small v-else class="hint">The more precise this is, the less time the technician spends finding it.</small>
            </div>

            <div class="field">
                <label>What is wrong <span class="req">*</span></label>
                <textarea v-model="form.description" rows="4" maxlength="1000" required
                          placeholder="Describe the fault and anything the technician should know before arriving."></textarea>
                <small v-if="errors.description" class="err">{{ errors.description }}</small>
            </div>

            <div class="field">
                <label>Urgency <span class="req">*</span></label>
                <div class="urgency">
                    <label v-for="u in urgencies" :key="u.value" :class="['chip', form.urgency === u.value && 'chip-on']">
                        <input type="radio" v-model="form.urgency" :value="u.value" />
                        {{ u.label }}
                    </label>
                </div>
            </div>

            <div class="field">
                <label>Photos or documents</label>
                <input type="file" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.heic,.heif,.webp" @change="onFiles" />
                <small class="hint">Optional. Up to 10MB each.</small>
            </div>

            <div class="actions">
                <Link href="/corporate/requests" class="btn btn-secondary">Cancel</Link>
                <button type="submit" class="btn btn-primary" :disabled="submitting || !ready">
                    {{ submitting ? 'Sending…' : 'Send to Technician World' }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

defineProps({
    membership: { type: Object, required: true },
    properties: { type: Array, required: true },
    categories: { type: Array, required: true },
})

const page = usePage()
const errors = computed(() => page.props.errors || {})
const submitting = ref(false)

const urgencies = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'Emergency' },
]

const form = reactive({
    property_id: null,
    service_category_id: null,
    location: '',
    description: '',
    urgency: 'medium',
    files: [],
})

const ready = computed(() =>
    form.property_id && form.service_category_id && form.location.trim() && form.description.trim().length >= 10
)

const onFiles = (e) => { form.files = Array.from(e.target.files || []) }

const submit = () => {
    submitting.value = true
    // forceFormData so the file inputs survive; Inertia would otherwise send
    // JSON and drop them.
    router.post('/corporate/requests', { ...form }, {
        forceFormData: true,
        onFinish: () => { submitting.value = false },
    })
}
</script>

<style scoped>
.corp-page { padding: 1.5rem; max-width: 1200px; margin: 0 auto; }
.narrow { max-width: 720px; }
.back { font-size: 0.8rem; color: #6b7280; text-decoration: none; }
h1 { margin: 0.5rem 0 0; font-size: 1.5rem; }
.sub { margin: 0.25rem 0 1.25rem; color: #6b7280; font-size: 0.85rem; }
.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.25rem; }
.field { margin-bottom: 1.1rem; display: flex; flex-direction: column; }
.field label { font-size: 0.82rem; font-weight: 600; margin-bottom: 0.35rem; }
.field input[type=text], .field select, .field textarea {
    padding: 0.55rem 0.7rem; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; font-size: 0.88rem;
}
.req { color: #dc2626; }
.hint { color: #6b7280; font-size: 0.75rem; margin-top: 0.3rem; }
.err { color: #dc2626; font-size: 0.75rem; margin-top: 0.3rem; }
.urgency { display: flex; gap: 0.5rem; }
.chip { padding: 0.4rem 0.9rem; border: 1px solid #d1d5db; border-radius: 999px; cursor: pointer; font-size: 0.82rem; font-weight: 500; }
.chip input { display: none; }
.chip-on { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; }
.actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 1.5rem; }
</style>
