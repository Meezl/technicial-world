<template>
    <Head title="Our Services - Technician World" />
    <div>
        <PublicNav variant="light" current-page="services" />

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
                    <div v-for="service in services" :key="service.id" class="service-card" :id="service.id">
                        <img
                            :src="service.image"
                            :alt="service.title"
                            loading="lazy"
                            @error="(e) => { e.target.src = service.fallback }"
                        />
                        <div class="service-card-content">
                            <h3>{{ service.title }}</h3>
                            <p>{{ service.description }}</p>
                            <Link href="/contact" class="learn-more">REQUEST A QUOTE →</Link>
                        </div>
                    </div>
                </div>
            </section>

            <section class="page-content light-bg-alt">
                <div class="about-content">
                    <div class="about-text">
                        <h2>Design and Build Services</h2>
                        <p>For larger, more complex projects, we offer specialized Design &amp; Build consultations. This service provides clients with a single point of contact and can lead to significant cost savings. Our process involves educating the client and working collaboratively from concept to completion.</p>
                        <p>You can express interest and book an appointment with our management team for further discussions through a dedicated page.</p>
                        <br>
                        <Link href="/contact" class="cta-button cta-dark" style="margin-top: 1rem;">BOOK A CONSULTATION</Link>
                    </div>
                    <div class="about-images">
                        <img
                            src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?q=80&w=1200&auto=format&fit=crop"
                            alt="Architectural plans and tools on a desk"
                            loading="lazy"
                        />
                        <img
                            src="https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?q=80&w=1200&auto=format&fit=crop"
                            alt="Modern construction project overview"
                            loading="lazy"
                        />
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

defineOptions({
    layout: null,
})

// Unsplash provides direct image URLs with auto-format & sizing.
// Each card has a fallback to a more generic image in case the primary
// URL changes upstream — keeps the page from showing broken thumbnails.
const services = [
    {
        id: 'electrical',
        title: 'Electrical Services',
        description: 'From new installations to repairs and safety checks, our certified electricians ensure your property is powered safely and efficiently.',
        image: 'https://images.unsplash.com/photo-1621905251918-48416bd8575a?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'plumbing',
        title: 'Plumbing & Fitting',
        description: 'We handle everything from leaky faucets to complete piping systems for new constructions, ensuring reliable water flow and drainage.',
        image: 'https://images.unsplash.com/photo-1606613558321-a37d8d6b3d3e?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1607400201515-c2c41c07d307?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'painting',
        title: 'Painting & Decorating',
        description: 'Our professional painters provide high-quality interior and exterior painting services to give your space a fresh, new look.',
        image: 'https://images.unsplash.com/photo-1562259949-e8e7689d7828?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1589939705384-5185137a7f0f?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'tiling',
        title: 'Tiling & Flooring',
        description: 'Expert installation of ceramic tiles, hardwood, and modern flooring systems for both residential and commercial projects.',
        image: 'https://images.unsplash.com/photo-1581858726788-75bc0f6a952d?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1615873968403-89e068629265?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'carpentry',
        title: 'Carpentry & Woodwork',
        description: 'Custom cabinetry, furniture, doors and on-site joinery — built by skilled carpenters who treat your space like their own.',
        image: 'https://images.unsplash.com/photo-1504148455328-c376907d081c?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1530124566582-a618bc2615dc?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'masonry',
        title: 'Masonry & Construction',
        description: 'Brickwork, blockwork, plastering and stone finishes for new builds, extensions and structural repair work.',
        image: 'https://images.unsplash.com/photo-1504307651254-35680f356dfd?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'hvac',
        title: 'HVAC Services',
        description: 'Air-conditioning installation, ventilation upgrades and routine maintenance to keep your environment comfortable year-round.',
        image: 'https://images.unsplash.com/photo-1631545806609-d5f0ee99fa57?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1614076392323-7f00ccbb7b81?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'roofing',
        title: 'Roofing Services',
        description: 'Roof installation, repairs and waterproofing using iron sheets, tiles and modern roofing systems.',
        image: 'https://images.unsplash.com/photo-1605152276897-4f618f831968?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1632944968416-2293f59f95e8?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'aluminium-glass',
        title: 'Aluminium & Glass',
        description: 'Custom aluminium fabrication for windows, doors and partitions, plus glass installation for shopfronts and modern interiors.',
        image: 'https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1517055575968-f31fb7fac7ee?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'welding',
        title: 'Welding & Metal Works',
        description: 'Gates, grilles, balconies and custom metal fabrication — precision welding for structural and decorative work.',
        image: 'https://images.unsplash.com/photo-1517841905240-472988babdf9?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1535732820275-9ffd998cac22?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'landscaping',
        title: 'Landscaping & Gardening',
        description: 'Garden design, paving, irrigation and ongoing maintenance to transform outdoor spaces into a year-round asset.',
        image: 'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1558904541-efa843a96f01?q=80&w=1200&auto=format&fit=crop',
    },
    {
        id: 'general-maintenance',
        title: 'General Maintenance',
        description: 'One reliable team for the small jobs that never quite fit a single trade — repairs, fittings and routine upkeep.',
        image: 'https://images.unsplash.com/photo-1581244277943-fe4a9c777189?q=80&w=1200&auto=format&fit=crop',
        fallback: 'https://images.unsplash.com/photo-1581094794329-c8112a89af12?q=80&w=1200&auto=format&fit=crop',
    },
]
</script>

<style>
</style>
