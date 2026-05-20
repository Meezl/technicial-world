<template>
    <Head title="E-Commerce - Technician World" />
    <div>
        <header class="main-header header-light">
            <nav class="navbar">
                <Link href="/" class="logo logo-dark">TECHNICIAN WORLD</Link>
                <ul class="nav-links">
                    <li><Link href="/about" class="link-dark">About Us</Link></li>
                    <li><Link href="/services" class="link-dark">Our Services</Link></li>
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
            <section class="subpage-header ecomm-header">
                <h1>Integrated Hardware Store</h1>
                <p>An online hardware store offering construction materials and tools with live pricing and smooth online purchasing.</p>
            </section>

            <section class="page-content light-bg-alt">
                <div class="section-header">
                    <h2>Featured Products</h2>
                </div>
                <div class="product-grid">
                    <div class="product-card">
                        <img src="https://m.media-amazon.com/images/I/71GhQ-RE6PL.jpg" alt="Tools">
                        <h4>Power Drill Set</h4>
                        <p class="product-price">KES 8,500</p>
                        <button class="cta-button cta-dark">Add to Cart</button>
                    </div>
                    <div class="product-card">
                        <img src="https://www.ebuild.ke/wp-content/uploads/2023/07/Nguvu.jpg" alt="Cement">
                        <h4>Cement Bag (50kg)</h4>
                        <p class="product-price">KES 750</p>
                        <button class="cta-button cta-dark">Add to Cart</button>
                    </div>
                    <div class="product-card">
                        <img src="https://www.malleableiron-pipefitting.com/data/watermark/20210225/6037218a52cf0_.webp" alt="Pipes">
                        <h4>Plumbing Fittings</h4>
                        <p class="product-price">From KES 150</p>
                        <button class="cta-button cta-dark">View Options</button>
                    </div>
                    <div class="product-card">
                        <img src="https://www.muthokinju.co.ke/wp-content/uploads/2024/03/Duracoat-Superfast.jpg" alt="Paint">
                        <h4>Premium Paint (20L)</h4>
                        <p class="product-price">KES 4,200</p>
                        <button class="cta-button cta-dark">Add to Cart</button>
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
