<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()

const whatsappUrl = 'https://wa.me/254117962395'

const isTechnicianSurface = computed(() => (page.url || '').startsWith('/technician'))
</script>

<template>
    <a
        :class="['whatsapp-float', { 'whatsapp-float-technician': isTechnicianSurface }]"
        :href="whatsappUrl"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Chat with us on WhatsApp"
        title="Chat with us on WhatsApp"
    >
        <span class="whatsapp-float-icon" aria-hidden="true">
            <i class="fab fa-whatsapp"></i>
        </span>
        <span class="whatsapp-float-label">WhatsApp Us</span>
    </a>
</template>

<style scoped>
.whatsapp-float {
    position: fixed;
    right: 1.25rem;
    bottom: calc(env(safe-area-inset-bottom, 0px) + 1.25rem);
    z-index: 80;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.9rem 1.05rem;
    border-radius: 999px;
    background: linear-gradient(135deg, #25d366 0%, #128c4a 100%);
    color: #ffffff;
    text-decoration: none;
    box-shadow: 0 18px 38px rgba(18, 140, 74, 0.28);
    transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
}

.whatsapp-float:hover {
    transform: translateY(-2px);
    box-shadow: 0 22px 42px rgba(18, 140, 74, 0.32);
}

.whatsapp-float:focus-visible {
    outline: 3px solid rgba(37, 211, 102, 0.28);
    outline-offset: 3px;
}

.whatsapp-float-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.6rem;
    height: 2.6rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.18);
    font-size: 1.4rem;
}

.whatsapp-float-label {
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    white-space: nowrap;
}

.whatsapp-float-technician {
    bottom: calc(env(safe-area-inset-bottom, 0px) + 6.5rem);
}

@media (max-width: 640px) {
    .whatsapp-float {
        right: 1rem;
        bottom: calc(env(safe-area-inset-bottom, 0px) + 1rem);
        gap: 0.55rem;
        padding: 0.78rem;
    }

    .whatsapp-float-label {
        display: none;
    }

    .whatsapp-float-technician {
        bottom: calc(env(safe-area-inset-bottom, 0px) + 6rem);
    }
}
</style>
