<template>
    <div class="dashboard-container client-pwa-shell">
        <ClientSidebar current-page="support" />
        <ClientBottomNav current-page="support" />

        <main class="main-content">
            <header class="main-header">
                <h1>Help & Support</h1>
            </header>

            <section class="panel-section">
                <div class="support-grid">
                    <div class="panel-card">
                        <div class="card-header">
                            <h3><i class="fab fa-whatsapp"></i> Instant Support</h3>
                        </div>
                        <p>For urgent matters, chat directly with our support team on WhatsApp for the fastest response.</p>
                        <a href="https://wa.me/254700000001" target="_blank" class="btn btn-whatsapp">
                            <i class="fab fa-whatsapp"></i> Chat on WhatsApp
                        </a>
                    </div>
                    <div class="panel-card">
                        <div class="card-header">
                            <h3><i class="fas fa-ticket-alt"></i> Submit a Ticket</h3>
                        </div>
                        <p>For non-urgent inquiries, submit a support ticket and our team will get back to you via email.</p>
                        <button @click="showTicketForm = !showTicketForm" class="btn btn-secondary">
                            {{ showTicketForm ? 'Cancel' : 'Create a Ticket' }}
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="showTicketForm" class="panel-section">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Submit Support Ticket</h3>
                    </div>
                    <form @submit.prevent="submitTicket" class="support-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="subject">Subject</label>
                                <input type="text" id="subject" v-model="ticketForm.subject" required>
                            </div>
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <select id="priority" v-model="ticketForm.priority" required>
                                    <option value="">Select Priority</option>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label for="description">Description</label>
                            <textarea id="description" v-model="ticketForm.description" rows="5" required></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" :disabled="submitting">
                                {{ submitting ? 'Submitting...' : 'Submit Ticket' }}
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="panel-section">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Contact Information</h3>
                    </div>
                    <div class="contact-grid">
                        <div class="contact-item">
                            <i class="fas fa-phone"></i>
                            <div>
                                <h4>Phone</h4>
                                <p>0117 962 395</p>
                            </div>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <h4>Email</h4>
                                <p>support@technicianworld.com</p>
                            </div>
                        </div>
                        <div class="contact-item">
                            <i class="fas fa-clock"></i>
                            <div>
                                <h4>Support Hours</h4>
                                <p>Mon - Fri: 8:00 AM - 6:00 PM<br>Emergency: 24/7</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import ClientSidebar from '../../Components/ClientSidebar.vue'
import ClientBottomNav from '../../Components/ClientBottomNav.vue'
import { ref, reactive } from 'vue'

const showTicketForm = ref(false)
const submitting = ref(false)

const ticketForm = reactive({
    subject: '',
    priority: '',
    description: ''
})

const submitTicket = async () => {
    submitting.value = true

    try {
        // Here you would typically submit to a backend endpoint
        console.log('Ticket submitted:', ticketForm)
        alert('Support ticket submitted successfully! We will get back to you via email.')

        // Reset form
        ticketForm.subject = ''
        ticketForm.priority = ''
        ticketForm.description = ''
        showTicketForm.value = false
    } catch (error) {
        console.error('Ticket submission error:', error)
        alert('There was an error submitting your ticket. Please try again.')
    } finally {
        submitting.value = false
    }
}

defineOptions({
    layout: null
})
</script>

<style>

.support-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
}

.btn-whatsapp {
    background-color: #25d366;
    color: white;
    border: 2px solid #25d366;
}

.btn-whatsapp:hover {
    background-color: #128c7e;
    border-color: #128c7e;
}

.support-form .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 1rem;
}

.contact-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 2rem;
}

.contact-item {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.contact-item i {
    font-size: 2rem;
    color: var(--primary-blue);
    width: 60px;
    text-align: center;
}

.contact-item h4 {
    margin: 0 0 0.5rem 0;
    color: var(--dark-grey);
}

.contact-item p {
    margin: 0;
    color: var(--medium-grey);
    line-height: 1.4;
}

/* ─────────── Mobile (≤1023px) ─────────── */
@media (max-width: 1023.98px) {
    .client-pwa-shell .main-content > .main-header {
        padding: 0.25rem 0 0.75rem;
    }

    .client-pwa-shell .main-content > .main-header h1 {
        font-size: 1.35rem;
        margin: 0;
    }

    .client-pwa-shell .panel-section {
        margin-bottom: 1rem;
    }

    .support-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .support-grid .panel-card,
    .panel-card.full-width {
        padding: 1.1rem;
        border-radius: 18px;
    }

    .support-grid .panel-card .card-header h3,
    .panel-card.full-width .card-header h3 {
        font-size: 1.02rem;
    }

    .support-grid .panel-card p {
        font-size: 0.92rem;
        line-height: 1.5;
    }

    .support-grid .btn,
    .support-grid .btn-whatsapp {
        width: 100%;
        justify-content: center;
    }

    .support-form .form-row {
        grid-template-columns: 1fr;
        gap: 0.85rem;
        margin-bottom: 0.85rem;
    }

    .support-form .form-actions {
        display: flex;
    }

    .support-form .form-actions .btn {
        width: 100%;
        justify-content: center;
    }

    .contact-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    .contact-item {
        gap: 0.85rem;
        padding: 0.85rem 1rem;
        background: #f8fafc;
        border-radius: 14px;
    }

    .contact-item i {
        font-size: 1.4rem;
        width: 40px;
    }

    .contact-item h4 {
        font-size: 0.95rem;
        margin: 0 0 0.2rem 0;
    }

    .contact-item p {
        font-size: 0.88rem;
    }
}
</style>