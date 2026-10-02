<template>
    <Head title="Contact Us - Technician World" />
    <div>
        <PublicNav variant="light" current-page="contact" />

        <main>
            <section class="subpage-header cnt-header">
                <h1>Contact Us</h1>
                <p>Get in touch with our team for inquiries, support, or to book a service manually.</p>
            </section>

            <section class="page-content contact-page">
                <div class="contact-details">
                    <h3>Contact Information</h3>
                    <p>For immediate assistance or if you prefer to book a service over the phone, please use the details below. Our team is available to assist you.</p>
                    <ul>
                        <li><strong>Phone:</strong> +254 117 962 395</li>
                        <li><strong>WhatsApp:</strong> +254 117 962 395</li>
                        <li><strong>Email:</strong> support@technicianworld.co.ke</li>
                        <li><strong>Location:</strong>PCEA Flats, Jabavu Road, Nairobi, Office Suite F3
                            Nairobi, Kenya</li>
                    </ul>
                </div>
                <div class="contact-form">
                    <h3>Request a Quote</h3>
                    <p class="form-intro">
                        Tell us about the work and we will come back with a price. The more you can say
                        about where it is and what is involved, the sooner we can quote it properly.
                    </p>

                    <div v-if="success" class="form-success">
                        <strong>{{ success }}</strong>
                    </div>

                    <!-- Same questions as the ticket form, because the office
                         needs the same things to act: who you are, where the
                         work is, which trade, how soon, and what it involves.
                         The previous version asked for four of those and then
                         posted nowhere at all. -->
                    <form @submit.prevent="submitForm">
                        <div class="form-group">
                            <label for="name">Full Name <span class="req">*</span></label>
                            <input type="text" id="name" v-model="form.filer_name" maxlength="120" required>
                            <span v-if="errors.filer_name" class="field-error">{{ errors.filer_name }}</span>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address <span class="req">*</span></label>
                            <input type="email" id="email" v-model="form.filer_email" maxlength="150" required>
                            <span v-if="errors.filer_email" class="field-error">{{ errors.filer_email }}</span>
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input type="tel" id="phone" v-model="form.filer_phone" maxlength="30" placeholder="+254...">
                            <span v-if="errors.filer_phone" class="field-error">{{ errors.filer_phone }}</span>
                        </div>
                        <div class="form-group">
                            <label for="location">Location</label>
                            <input type="text" id="location" v-model="form.location" maxlength="200"
                                   placeholder="Neighbourhood / Estate / Building">
                            <span v-if="errors.location" class="field-error">{{ errors.location }}</span>
                        </div>
                        <div class="form-group">
                            <label for="service">Service of Interest <span class="req">*</span></label>
                            <select id="service" v-model="form.category" required>
                                <option value="" disabled>Choose the trade</option>
                                <option v-for="category in categories" :key="category" :value="category">
                                    {{ category }}
                                </option>
                            </select>
                            <span v-if="errors.category" class="field-error">{{ errors.category }}</span>
                        </div>
                        <div class="form-group">
                            <label for="urgency">How soon do you need it? <span class="req">*</span></label>
                            <select id="urgency" v-model="form.urgency" required>
                                <option value="emergency">🚨 Emergency (within 1–2 hours)</option>
                                <option value="urgent">⚠ Urgent (within today)</option>
                                <option value="normal">Normal (within one working day)</option>
                            </select>
                            <span v-if="errors.urgency" class="field-error">{{ errors.urgency }}</span>
                        </div>
                        <div class="form-group">
                            <label for="subject">Subject <span class="req">*</span></label>
                            <input type="text" id="subject" v-model="form.subject" maxlength="200" required
                                   placeholder="e.g. Rewire two-bedroom flat in Kilimani">
                            <span v-if="errors.subject" class="field-error">{{ errors.subject }}</span>
                        </div>
                        <div class="form-group">
                            <label for="message">Message / Project Description <span class="req">*</span></label>
                            <textarea id="message" v-model="form.description" rows="5" maxlength="5000"
                                      minlength="10" required></textarea>
                            <span v-if="errors.description" class="field-error">{{ errors.description }}</span>
                        </div>
                        <button type="submit" class="cta-button submit-btn" :disabled="submitting">
                            {{ submitting ? 'Submitting...' : 'Submit Request' }}
                        </button>
                    </form>
                </div>
            </section>
        </main>

        <footer class="main-footer">
            <div class="footer-content">
                <h2 class="footer-title">TECHNICIAN WORLD</h2>
                <p>Ready to start your next project? Contact us for a detailed quote.</p>
                <br>
                <Link href="/contact" class="cta-button">REQUEST A QUOTE</Link>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2026 Technician World. All Rights Reserved.</p>
                <ul class="footer-links">
                    <li><Link href="/about">About</Link></li>
                    <li><Link href="/services">Services</Link></li>
                    <li><a href="#">Privacy Policy</a></li>
                </ul>
            </div>
        </footer>
    </div>
</template>

<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import PublicNav from '@/Components/PublicNav.vue'
import { computed, reactive, ref } from 'vue'

const props = defineProps({
    // Active service categories, by name — see TicketController::publicCategories().
    categories: { type: Array, default: () => [] },
})

const page = usePage()
const success = computed(() => page.props.flash?.success)

// Field names match the ticket form's, because both post the same submission
// to the same validation rules.
const form = reactive({
    filer_name: '',
    filer_email: '',
    filer_phone: '',
    location: '',
    category: '',
    urgency: 'normal',
    subject: '',
    description: '',
})

const errors = ref({})
const submitting = ref(false)

/**
 * Posts the enquiry and keeps the sender's words on a failure.
 *
 * What this replaces logged the form to the browser console, said "we will get
 * back to you soon", and threw it away. Every quote request made through this
 * page was lost.
 */
const submitForm = () => {
    submitting.value = true
    errors.value = {}

    router.post('/contact', form, {
        preserveScroll: true,
        onSuccess: () => {
            Object.assign(form, {
                filer_name: '', filer_email: '', filer_phone: '', location: '',
                category: '', urgency: 'normal', subject: '', description: '',
            })
        },
        onError: (errs) => { errors.value = errs },
        onFinish: () => { submitting.value = false },
    })
}

defineOptions({
    layout: null
})
</script>

<style scoped>
.form-intro {
    margin: 0 0 1.1rem;
    color: #475569;
    line-height: 1.6;
}

/* Reuses the ticket form's required marker and error wording so the two pages
   do not teach the visitor two different conventions. */
.req { color: #dc2626; }

.field-error {
    display: block;
    margin-top: 0.3rem;
    font-size: 0.82rem;
    color: #b91c1c;
}

.form-success {
    margin: 0 0 1.1rem;
    padding: 0.85rem 1rem;
    border-radius: 8px;
    background: #dcfce7;
    border: 1px solid #86efac;
    color: #166534;
    line-height: 1.5;
}
</style>
