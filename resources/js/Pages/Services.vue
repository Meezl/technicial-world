<template>
    <Head title="Our Services - Technician World" />
    <div>
        <header class="main-header header-light">
            <nav class="navbar">
                <Link href="/" class="logo logo-dark">TECHNICIAN WORLD</Link>
                <ul class="nav-links">
                    <li><Link href="/about" class="link-dark">About Us</Link></li>
                    <li><Link href="/services" class="link-dark active">Our Services</Link></li>
                    <li>
                        <a
                            href="https://shop.technicianworld.co.ke"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            E-Commerce
                        </a>
                    </li>
                    <li><Link href="/contact" class="link-dark">Contact Us</Link></li>
                </ul>
                <Link v-if="!$page.props.auth.user" href="/login" class="cta-button">Sign Up / Log in</Link>
                <Link v-else href="/dashboard" class="cta-button">Dashboard</Link>
            </nav>
        </header>

        <main>
            <section class="subpage-header serv-header">
                <h1>Our Services</h1>
                <p>A comprehensive suite of technical services to meet your needs, big or small.</p>
            </section>

            <section class="page-content">
                <div class="section-header">
                    <h2>Core Service Categories</h2>
                    <p>Select from a wide range of service categories through our easy-to-use interface.</p>
                </div>
                <div class="service-grid">
                    <div class="service-card" id="electrical">
                        <img src="https://bbraun.webpinn.com/assets/img/gt.jpeg" alt="Electrical Services">
                        <div class="service-card-content">
                            <h3>Electrical Services</h3>
                            <p>From new installations to repairs and safety checks, our certified electricians ensure your property is powered safely and efficiently.</p>
                            <Link href="/contact" class="learn-more">REQUEST A QUOTE →</Link>
                        </div>
                    </div>
                    <div class="service-card" id="plumbing">
                        <img src="https://bbraun.webpinn.com/assets/img/pl.jpeg" alt="Plumbing Services">
                        <div class="service-card-content">
                            <h3>Plumbing & Fitting</h3>
                            <p>We handle everything from leaky faucets to complete piping systems for new constructions, ensuring reliable water flow and drainage.</p>
                            <Link href="/contact" class="learn-more">REQUEST A QUOTE →</Link>
                        </div>
                    </div>
                    <div class="service-card" id="painting">
                        <img src="https://bbraun.webpinn.com/assets/img/pt.jpeg" alt="Painting Services">
                        <div class="service-card-content">
                            <h3>Painting & Decorating</h3>
                            <p>Our professional painters provide high-quality interior and exterior painting services to give your space a fresh, new look.</p>
                            <Link href="/contact" class="learn-more">REQUEST A QUOTE →</Link>
                        </div>
                    </div>
                    <div class="service-card" id="tiling">
                        <img src="https://bbraun.webpinn.com/assets/img/til.jpeg" alt="Tiling Services">
                        <div class="service-card-content">
                            <h3>Tiling & Flooring</h3>
                            <p>Expert installation of various flooring types, including ceramic tiles, hardwood, and more for both residential and commercial projects.</p>
                            <Link href="/contact" class="learn-more">REQUEST A QUOTE →</Link>
                        </div>
                    </div>
                </div>
            </section>

            <section class="page-content light-bg-alt">
                <div class="about-content">
                    <div class="about-text">
                        <h2>Design and Build Services</h2>
                        <p>For larger, more complex projects, we offer specialized Design & Build consultations. This service provides clients with a single point of contact and can lead to significant cost savings. Our process involves educating the client and working collaboratively from concept to completion.</p>
                        <p>You can express interest and book an appointment with our management team for further discussions through a dedicated page.</p>
                        <br>
                        <Link href="/contact" class="cta-button cta-dark" style="margin-top: 1rem;">BOOK A CONSULTATION</Link>
                    </div>
                    <div class="about-images">
                        <img src="https://bbraun.webpinn.com/assets/img/pln.jpeg" alt="Architectural plans and tools on a desk">
                        <img src="https://bbraun.webpinn.com/assets/img/pln2.jpeg" alt="Architectural plans and tools on a desk">
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
