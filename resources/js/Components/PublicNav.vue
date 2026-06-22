<template>
    <header :class="['main-header', { 'header-light': variant === 'light' }]">
        <nav class="navbar">
            <Link href="/" :class="['logo', { 'logo-dark': variant === 'light' }]">TECHNICIAN WORLD</Link>

            <button
                type="button"
                class="nav-toggle"
                :class="{ active: isOpen, 'is-light': variant === 'light' }"
                :aria-expanded="isOpen ? 'true' : 'false'"
                aria-controls="public-nav-links"
                aria-label="Toggle menu"
                @click="toggle"
            >
                <span></span>
                <span></span>
                <span></span>
            </button>

            <ul
                id="public-nav-links"
                class="nav-links"
                :class="{ open: isOpen }"
            >
                <li>
                    <Link
                        href="/about"
                        :class="[linkClass, { active: currentPage === 'about' }]"
                        @click="close"
                    >
                        About Us
                    </Link>
                </li>
                <li>
                    <Link
                        href="/services"
                        :class="[linkClass, { active: currentPage === 'services' }]"
                        @click="close"
                    >
                        Our Services
                    </Link>
                </li>
                <li>
                    <a
                        href="https://shop.technicianworld.co.ke"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="linkClass"
                        @click="close"
                    >
                        E-Commerce
                    </a>
                </li>
                <li>
                    <Link
                        href="/contact"
                        :class="[linkClass, { active: currentPage === 'contact' }]"
                        @click="close"
                    >
                        Contact Us
                    </Link>
                </li>
                <li>
                    <Link
                        href="/open-ticket"
                        :class="['nav-ticket-link', { active: currentPage === 'open-ticket' }]"
                        @click="close"
                    >
                        Open a Ticket
                    </Link>
                </li>
            </ul>

            <Link
                v-if="!$page.props.auth?.user"
                href="/login"
                :class="['cta-button', { 'cta-button-2': variant === 'dark' }]"
            >
                Sign Up / Log in
            </Link>
            <Link
                v-else
                href="/dashboard"
                :class="['cta-button', { 'cta-button-2': variant === 'dark' }]"
            >
                Dashboard
            </Link>
        </nav>
    </header>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    currentPage: {
        type: String,
        default: 'home',
    },
    variant: {
        type: String,
        default: 'light', // 'light' for subpages, 'dark' for the homepage hero
        validator: (v) => ['light', 'dark'].includes(v),
    },
})

const isOpen = ref(false)

const linkClass = computed(() => (props.variant === 'light' ? 'link-dark' : ''))

function toggle() {
    isOpen.value = !isOpen.value
}

function close() {
    isOpen.value = false
}

function handleKeydown(event) {
    if (event.key === 'Escape' && isOpen.value) close()
}

watch(isOpen, (open) => {
    if (typeof document === 'undefined') return
    document.body.classList.toggle('public-nav-locked', open)
})

onMounted(() => {
    window.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown)
    if (typeof document !== 'undefined') {
        document.body.classList.remove('public-nav-locked')
    }
})
</script>


<style>
@import url('../../css/frontend-app.css');

/* "Open a Ticket" nav entry — brand-blue pill so it stands out as a
   call-to-action without breaking the menu rhythm. */
.nav-ticket-link {
    background: #053272;
    color: #ffffff !important;
    padding: 0.45rem 1rem;
    border-radius: 999px;
    font-weight: 600;
    transition: background 0.15s ease, transform 0.15s ease;
    text-decoration: none;
    white-space: nowrap;
}
.nav-ticket-link:hover {
    background: #042659;
    transform: translateY(-1px);
}
.nav-ticket-link.active {
    background: #042659;
}
</style>
