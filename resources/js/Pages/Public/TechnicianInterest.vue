<template>
    <GuestLayout>
        <div class="interest-page">
            <div class="interest-container">
                <div class="interest-header">
                    <h1>Join Our Technician Network</h1>
                    <p>Are you a skilled technician in the building and construction industry? Join Technician World and get access to quality job opportunities across Kenya.</p>
                </div>

                <div v-if="$page.props.flash?.success" class="success-banner">
                    <i class="fas fa-check-circle"></i>
                    {{ $page.props.flash.success }}
                </div>

                <div v-if="$page.props.flash?.error" class="error-banner">
                    <i class="fas fa-exclamation-circle"></i>
                    {{ $page.props.flash.error }}
                </div>

                <form @submit.prevent="submit" class="interest-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Full Name *</label>
                            <input id="name" type="text" v-model="form.name" required placeholder="Enter your full name">
                            <span v-if="form.errors?.name" class="error-text">{{ form.errors.name }}</span>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address *</label>
                            <input id="email" type="email" v-model="form.email" required placeholder="your.email@example.com">
                            <span v-if="form.errors?.email" class="error-text">{{ form.errors.email }}</span>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone Number *</label>
                            <input id="phone" type="tel" v-model="form.phone" required placeholder="+254 7XX XXX XXX">
                            <span v-if="form.errors?.phone" class="error-text">{{ form.errors.phone }}</span>
                        </div>
                        <div class="form-group">
                            <label for="trade">Trade / Specialization *</label>
                            <select id="trade" v-model="form.trade" required>
                                <option value="">Select your trade...</option>
                                <option v-for="(label, key) in trades" :key="key" :value="key">{{ label }}</option>
                            </select>
                            <span v-if="form.errors?.trade" class="error-text">{{ form.errors.trade }}</span>
                        </div>
                        <div class="form-group">
                            <label for="location">Location</label>
                            <input id="location" type="text" v-model="form.location" placeholder="City/town you're based in">
                        </div>
                    </div>
                    <div class="form-group full">
                        <label for="experience">Experience & Qualifications</label>
                        <textarea id="experience" v-model="form.experience" rows="4" placeholder="Tell us about your experience, certifications, and areas of expertise..."></textarea>
                    </div>

                    <div class="benefits-section">
                        <h3>Why Join Technician World?</h3>
                        <div class="benefits-grid">
                            <div class="benefit">
                                <i class="fas fa-briefcase"></i>
                                <span>Access to quality jobs</span>
                            </div>
                            <div class="benefit">
                                <i class="fas fa-money-bill-wave"></i>
                                <span>Fair, transparent compensation</span>
                            </div>
                            <div class="benefit">
                                <i class="fas fa-chart-line"></i>
                                <span>Grow your professional profile</span>
                            </div>
                            <div class="benefit">
                                <i class="fas fa-shield-alt"></i>
                                <span>Verified & trusted platform</span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" :disabled="submitting">
                        <i class="fas fa-paper-plane" v-if="!submitting"></i>
                        <i class="fas fa-spinner fa-spin" v-else></i>
                        {{ submitting ? 'Submitting...' : 'Submit Your Interest' }}
                    </button>
                </form>
            </div>
        </div>
    </GuestLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import GuestLayout from '../../Layouts/GuestLayout.vue';

const props = defineProps({
    trades: { type: Object, default: () => ({}) },
});

const form = ref({
    name: '',
    email: '',
    phone: '',
    trade: '',
    experience: '',
    location: '',
    errors: {},
});

const submitting = ref(false);

const submit = () => {
    submitting.value = true;
    router.post('/join-as-technician', {
        name: form.value.name,
        email: form.value.email,
        phone: form.value.phone,
        trade: form.value.trade,
        experience: form.value.experience,
        location: form.value.location,
    }, {
        onSuccess: () => {
            submitting.value = false;
            form.value = { name: '', email: '', phone: '', trade: '', experience: '', location: '', errors: {} };
        },
        onError: (errors) => {
            submitting.value = false;
            form.value.errors = errors;
        },
    });
};
</script>

<style scoped>
.interest-page {
    min-height: 100vh;
    background: linear-gradient(135deg, #053272 0%, #0a4d8c 50%, #1a73e8 100%);
    padding: 2rem;
    display: flex;
    justify-content: center;
    align-items: flex-start;
}

.interest-container {
    max-width: 700px;
    width: 100%;
    background: white;
    border-radius: 16px;
    padding: 3rem;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    margin-top: 2rem;
}

.interest-header { text-align: center; margin-bottom: 2rem; }
.interest-header h1 { font-size: 2rem; color: #053272; margin-bottom: 0.75rem; }
.interest-header p { color: #6B7280; font-size: 1.05rem; line-height: 1.6; }

.success-banner {
    background: #DCFCE7; color: #166534; padding: 1rem; border-radius: 8px;
    margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-weight: 500;
}
.error-banner {
    background: #FEE2E2; color: #991B1B; padding: 1rem; border-radius: 8px;
    margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; font-weight: 500;
}

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.25rem; }
.form-group { display: flex; flex-direction: column; }
.form-group.full { margin-bottom: 1.5rem; }
.form-group label { font-weight: 600; font-size: 0.9rem; margin-bottom: 0.4rem; color: #374151; }
.form-group input, .form-group select, .form-group textarea {
    padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem;
    transition: border-color 0.2s; font-family: inherit;
}
.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    outline: none; border-color: #053272; box-shadow: 0 0 0 3px rgba(5, 50, 114, 0.1);
}
.error-text { color: #DC2626; font-size: 0.8rem; margin-top: 0.25rem; }

.benefits-section { margin: 2rem 0; }
.benefits-section h3 { font-size: 1.1rem; color: #053272; margin-bottom: 1rem; }
.benefits-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.benefit {
    display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; background: #eff6ff;
    border-radius: 8px; font-size: 0.9rem; color: #1e40af; font-weight: 500;
}
.benefit i { font-size: 1.1rem; color: #053272; }

.submit-btn {
    width: 100%; padding: 1rem; background: #053272; color: white; border: none;
    border-radius: 8px; font-size: 1.05rem; font-weight: 600; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    transition: background 0.2s; font-family: inherit;
}
.submit-btn:hover:not(:disabled) { background: #042454; }
.submit-btn:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 640px) {
    .interest-container { padding: 1.5rem; margin-top: 1rem; }
    .form-grid { grid-template-columns: 1fr; }
    .benefits-grid { grid-template-columns: 1fr; }
    .interest-header h1 { font-size: 1.5rem; }
}
</style>
