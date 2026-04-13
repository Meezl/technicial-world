<template>
    <Head title="Technician World - Professional Service Platform" />
    <div>
        <header class="main-header">
            <nav class="navbar">
                <Link href="/" class="logo">TECHNICIAN WORLD</Link>
                <ul class="nav-links">
                    <li><Link href="/about">About Us</Link></li>
                    <li><Link href="/services">Our Services</Link></li>
                    <li><Link href="/ecommerce">E-Commerce</Link></li>
                    <li><Link href="/contact">Contact Us</Link></li>
                </ul>
                <Link v-if="canLogin && !$page.props.auth.user" href="/login" class="cta-button-2">Sign Up / Log in</Link>
                <Link v-else-if="$page.props.auth.user" href="/dashboard" class="cta-button-2">Dashboard</Link>
            </nav>
        </header>

        <main>
            <!-- Updated Clean Hero Section -->
            <section class="hero-section">
                <div class="hero-content">
                    <h1>Professional Technical Services</h1>
                    <p class="subtitle">Connect with qualified technicians across Kenya. Quality work, reliable service, transparent pricing.</p>
                    <div class="hero-cta">
                        <Link href="/contact" class="btn-primary">Get a Quote</Link>
                        <a href="#about" class="btn-secondary">Learn More</a>
                    </div>
                </div>
            </section>

            <section id="about" class="about-section">
                <div class="about-content">
                    <div class="about-images">
                        <img src="https://bbraun.webpinn.com/assets/img/pl.jpeg" alt="Technician working on electrical wiring">
                        <img src="https://bbraun.webpinn.com/assets/img/ph.jpeg" alt="">
                    </div>
                    <div class="about-text">
                        <h2>A Unified Service & Operations Management Platform</h2>
                        <p>Technician World is a locally owned firm serving private and public customers across Kenya. The platform connects clients with qualified technicians while streamlining quotation, booking, and project tracking processes. We combine the flexibility and personalized service of a small firm with the resources and expertise of a large company to provide a seamless and professional solution for all our customers.</p>
                        <Link href="/about" class="learn-more">LEARN MORE ABOUT US →</Link>
                    </div>
                </div>
            </section>

            <section class="metrics-section">
                <h3>Adding Value Through Our Collaborative Method</h3>
                <div class="metrics-grid">
                    <div class="metric-item">
                        <span class="metric-number">100+</span>
                        <span class="metric-label">Vetted Technicians Onboarded</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-number">24-hr</span>
                        <span class="metric-label">Urgent Request Response Time</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-number">1,500+</span>
                        <span class="metric-label">Projects Completed</span>
                    </div>
                    <div class="metric-item">
                        <span class="metric-number">98%</span>
                        <span class="metric-label">Client Satisfaction Rating</span>
                    </div>
                </div>
            </section>

            <section id="features" class="features-section">
                <div class="features-content">
                    <div class="features-title">
                        <h2>We Don't Just Connect You — We Build Trust</h2>
                    </div>
                    <div class="features-grid">
                        <div class="feature-item">
                            <h4>Seamless RFQ Process</h4>
                            <p>Submit detailed project info through our guided web form for accurate quotes.</p>
                        </div>
                        <div class="feature-item">
                            <h4>Skilled In-House Teams</h4>
                            <p>All technicians are vetted, verified, and categorized based on skill level and specialization.</p>
                        </div>
                        <div class="feature-item">
                            <h4>Real-Time Project Tracking</h4>
                            <p>Clients can monitor service progress and get status updates through their dashboard.</p>
                        </div>
                        <div class="feature-item">
                            <h4>On-Time & On-Budget</h4>
                            <p>Our detailed quotations and progress monitoring ensure transparency and accountability.</p>
                        </div>
                        <div class="feature-item">
                            <h4>Transparent at Every Step</h4>
                            <p>From quotation to payment processing, all activities are logged and visible.</p>
                        </div>
                        <div class="feature-item">
                            <h4>All Types of Construction</h4>
                            <p>We handle a wide range of services, including electrical, plumbing, painting, and more.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section id="services-preview" class="services-projects-section">
                <div class="services-column">
                    <h3>Our Services</h3>
                    <ul class="services-list">
                        <li><Link href="/services#electrical"><span>Electrical Services</span></Link></li>
                        <li><Link href="/services#plumbing"><span>Plumbing & Fitting</span></Link></li>
                        <li><Link href="/services#painting"><span>Painting & Decorating</span></Link></li>
                        <li><Link href="/services#tiling"><span>Tiling & Flooring</span></Link></li>
                        <li><a href="#"><span>Design & Build Consultation</span></a></li>
                        <li><a href="#"><span>E-Commerce for Materials</span></a></li>
                    </ul>
                </div>
                <div class="projects-column">
                    <h3>Built to the Highest Standards — Every Time & Project.</h3>
                    <div class="project-card large">
                        <img src="https://images.unsplash.com/photo-1512917774080-9991f1c4c750?q=80&w=2940&auto=format&fit=crop" alt="">
                        <div class="project-info">
                            <h4>Modern Residential Home</h4>
                            <span>Nairobi, Kenya</span>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer id="contact" class="main-footer">
            <div class="footer-content">
                <h2 class="footer-title">TECHNICIAN WORLD</h2>
                <p>Ready to start your next project? Contact us for a detailed quote.</p>
                <br>
                <Link href="/contact" class="cta-button">REQUEST A QUOTE</Link>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 Technician World. All Rights Reserved.</p>
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

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
})

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../css/frontend-app.css');
</style>