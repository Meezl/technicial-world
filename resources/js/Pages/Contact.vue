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
                    <h3>Send us a Message</h3>
                    <form @submit.prevent="submitForm">
                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input type="text" id="name" v-model="form.name" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" v-model="form.email" required>
                        </div>
                        <div class="form-group">
                            <label for="service">Service of Interest</label>
                            <select id="service" v-model="form.service">
                                <option value="">--Please choose an option--</option>
                                <option value="electrical">Electrical</option>
                                <option value="plumbing">Plumbing</option>
                                <option value="painting">Painting</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="message">Message / Project Description</label>
                            <textarea id="message" v-model="form.message" rows="5" required></textarea>
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
import { Head, Link } from '@inertiajs/vue3'
import PublicNav from '@/Components/PublicNav.vue'
import { reactive, ref } from 'vue'

const form = reactive({
    name: '',
    email: '',
    service: '',
    message: ''
})

const submitting = ref(false)

const submitForm = async () => {
    submitting.value = true

    try {
        // Here you would typically submit to a backend endpoint
        console.log('Form submitted:', form)
        alert('Thank you for your message! We will get back to you soon.')

        // Reset form
        form.name = ''
        form.email = ''
        form.service = ''
        form.message = ''
    } catch (error) {
        console.error('Form submission error:', error)
        alert('There was an error submitting your message. Please try again.')
    } finally {
        submitting.value = false
    }
}

defineOptions({
    layout: null
})
</script>

<style>
</style>
