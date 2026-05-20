<template>
    <Head title="About Us - Technician World" />
    <div>
        <header class="main-header header-light">
            <nav class="navbar">
                <Link href="/" class="logo logo-dark">TECHNICIAN WORLD</Link>
                <ul class="nav-links">
                    <li><Link href="/about" class="link-dark active">About Us</Link></li>
                    <li><Link href="/services" class="link-dark">Our Services</Link></li>
                    <li><Link href="https://shop.technicianworld.co.ke" target="_blank">E-Commerce</Link></li>
                    <li><Link href="/contact" class="link-dark">Contact Us</Link></li>
                </ul>
                <Link v-if="!$page.props.auth.user" href="/login" class="cta-button">Sign Up / Log in</Link>
                <Link v-else href="/dashboard" class="cta-button">Dashboard</Link>
            </nav>
        </header>

        <main>
            <section class="subpage-header abt-header">
                <h1>About Technician World</h1>
                <p>Where every construction challenge meets a smart solution.</p>
            </section>

            <section class="page-content">
                <div class="about-content">
                    <div class="about-images">
                        <img src="https://images.unsplash.com/photo-1521791136064-7986c2920216?q=80&w=2940&auto=format&fit=crop" alt="Team collaborating on a project">
                        <img src="https://bbraun.webpinn.com/assets/img/vg.jpeg" alt="">
                    </div>
                    <div class="about-text">
                        <h3>Who We Are</h3>
                        <p>Technician World is a technology-driven platform transforming the way construction and
                            repair services are delivered in Kenya.</p>
                        <h3>Our Mission</h3>
                        <p>To make construction and repair services fast, transparent, and professionally managed
                            for everyone.</p>
                        <h3>Our Vision</h3>
                        <p>A future where every client can confidently plan and complete projects with trusted
                            technicians and clear information.</p>
                        <h3>What We Believe</h3>
                        <p>Small jobs need quick action. Complex projects need expert assessment. We match the
                            solution to the situation.</p>
                    </div>
                </div>
            </section>

            <section class="features-section">
                <div class="features-content">
                    <div class="features-title">
                        <h2>The Technician World Difference</h2>
                    </div>
                    <div class="features-grid">
                        <div class="feature-item">
                            <h4>Vetted & Qualified Professionals</h4>

                        </div>
                        <div class="feature-item">
                            <h4>Seamless Technology</h4>
                            <p>The platform is a unified service and operations management system that streamlines the entire workflow from start to finish. Clients submit detailed requests through a guided web form, while technicians manage their jobs, tools, and reporting through a dedicated mobile app.</p>
                        </div>
                        <div class="feature-item">
                            <h4>Complete Transparency</h4>
                            <p>We provide real-time visibility over all operations. Clients can track their project's progress through their dashboard, from quotation to completion. Our quotations are detailed and itemized, and payments are handled in clear, pre-defined milestones for full financial clarity.</p>
                        </div>
                    </div>
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

defineOptions({
    layout: null
})
</script>

<style>
@import url('../../css/frontend-app.css');
</style>
