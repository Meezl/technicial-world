<template>
    <div class="ticket-page">
        <header class="ticket-header">
            <Link href="/" class="logo-link">TECHNICIAN WORLD</Link>
            <Link href="/" class="back-link"><i class="fas fa-arrow-left"></i> Back to home</Link>
        </header>

        <main class="ticket-main">
            <div class="ticket-card">
                <h1>Open a Ticket</h1>
                <p class="subtitle">
                    Need help right now? Tell us what's going on and our team will respond — usually within an hour
                    for emergencies.
                </p>

                <div v-if="success" class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>{{ success }}</span>
                </div>

                <form @submit.prevent="submit" class="ticket-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Your name <span class="req">*</span></label>
                            <input v-model="form.filer_name" type="text" name="filer_name" maxlength="120" required />
                            <span v-if="errors.filer_name" class="field-error">{{ errors.filer_name }}</span>
                        </div>
                        <div class="form-group">
                            <label>Email <span class="req">*</span></label>
                            <input v-model="form.filer_email" type="email" name="filer_email" maxlength="150" required />
                            <span v-if="errors.filer_email" class="field-error">{{ errors.filer_email }}</span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Phone</label>
                            <input v-model="form.filer_phone" type="tel" name="filer_phone" maxlength="30" placeholder="+254..." />
                            <span v-if="errors.filer_phone" class="field-error">{{ errors.filer_phone }}</span>
                        </div>
                        <div class="form-group">
                            <label>Location</label>
                            <input v-model="form.location" type="text" name="location" maxlength="200" placeholder="Neighbourhood / Estate / Building" />
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Category <span class="req">*</span></label>
                            <select v-model="form.category" name="category" required>
                                <option value="electrical">Electrical</option>
                                <option value="plumbing">Plumbing</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Urgency <span class="req">*</span></label>
                            <select v-model="form.urgency" name="urgency" required>
                                <option value="emergency">🚨 Emergency (within 1–2 hours)</option>
                                <option value="urgent">⚠ Urgent (within today)</option>
                                <option value="normal">Normal (within one working day)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Subject <span class="req">*</span></label>
                        <input v-model="form.subject" type="text" name="subject" maxlength="200" required placeholder="e.g. Burst pipe in kitchen" />
                        <span v-if="errors.subject" class="field-error">{{ errors.subject }}</span>
                    </div>

                    <div class="form-group">
                        <label>What's happening? <span class="req">*</span></label>
                        <textarea v-model="form.description" name="description" rows="6" maxlength="5000" required minlength="10"
                            placeholder="Describe the issue, what you've tried, and anything else our team should know."></textarea>
                        <span v-if="errors.description" class="field-error">{{ errors.description }}</span>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="submit-btn" :disabled="submitting">
                            {{ submitting ? 'Submitting…' : 'Submit Ticket' }}
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const page = usePage()
const success = computed(() => page.props.flash?.success)

const form = reactive({
    filer_name: '',
    filer_email: '',
    filer_phone: '',
    category: 'electrical',
    urgency: 'normal',
    location: '',
    subject: '',
    description: '',
})

const errors = ref({})
const submitting = ref(false)

const submit = () => {
    submitting.value = true
    errors.value = {}
    router.post('/open-ticket', form, {
        onSuccess: () => {
            // Reset only if no user (otherwise we redirect)
            if (!page.props.auth?.user) {
                Object.assign(form, {
                    filer_name: '', filer_email: '', filer_phone: '',
                    category: 'electrical', urgency: 'normal',
                    location: '', subject: '', description: '',
                })
            }
        },
        onError: (errs) => { errors.value = errs },
        onFinish: () => { submitting.value = false },
    })
}

defineOptions({ layout: null })
</script>

<style scoped>
.ticket-page { min-height: 100vh; background: #f3f4f6; }
.ticket-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; background: white; border-bottom: 1px solid #e5e7eb; }
.logo-link { font-weight: 800; font-size: 1.1rem; color: #053272; text-decoration: none; letter-spacing: 0.05em; }
.back-link { color: #6b7280; text-decoration: none; font-size: 0.9rem; }
.back-link:hover { color: #053272; }

.ticket-main { max-width: 760px; margin: 2rem auto; padding: 0 1rem; }
.ticket-card { background: white; border-radius: 10px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); padding: 2rem; }
.ticket-card h1 { margin: 0 0 0.4rem; color: #053272; }
.subtitle { color: #6b7280; margin-bottom: 1.5rem; }

.alert { padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; }
.alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #6ee7b7; }

.ticket-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 640px) { .ticket-form .form-row { grid-template-columns: 1fr; } }

.form-group { margin-bottom: 1rem; }
.form-group label { display: block; font-weight: 600; font-size: 0.9rem; color: #374151; margin-bottom: 0.35rem; }
.req { color: #dc2626; }
.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.6rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.95rem;
    font-family: inherit;
    background: white;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #053272;
    box-shadow: 0 0 0 3px rgba(5, 50, 114, 0.15);
}
.field-error { display: block; color: #dc2626; font-size: 0.8rem; margin-top: 0.25rem; }

.form-actions { margin-top: 1.5rem; }
.submit-btn {
    background: #053272;
    color: white;
    border: 0;
    padding: 0.85rem 2rem;
    border-radius: 8px;
    font-weight: 700;
    cursor: pointer;
    font-size: 1rem;
    width: 100%;
    transition: background 0.15s;
}
.submit-btn:hover:not(:disabled) { background: #042659; }
.submit-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
