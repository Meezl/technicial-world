<template>
    <nav class="client-pwa-nav">
        <Link v-for="item in items" :key="item.key"
              :href="item.href"
              :class="['nav-link', { active: currentPage === item.key }]">
            <span class="nav-icon-wrap"><i :class="item.icon"></i></span>
            <span>{{ item.label }}</span>
        </Link>
    </nav>
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

defineProps({
    currentPage: {
        type: String,
        required: true,
    },
})

const page = usePage()
const membership = computed(() => page.props.corporate?.membership || null)

const retail = [
    { key: 'dashboard', href: '/client/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard' },
    { key: 'new-request', href: '/client/new-request', icon: 'fas fa-plus-circle', label: 'Request' },
    { key: 'payments', href: '/client/payments', icon: 'fas fa-file-invoice-dollar', label: 'Payments' },
    { key: 'support', href: '/client/support', icon: 'fas fa-headset', label: 'Support' },
    { key: 'profile', href: '/client/profile', icon: 'fas fa-user-circle', label: 'Profile' },
]

/**
 * Five at most — this is a phone.
 *
 * A caretaker is the person most likely to be on one, standing in the cubicle
 * they are reporting, so raising a job keeps its place. The rest is trimmed to
 * whatever that position actually does.
 */
const items = computed(() => {
    const m = membership.value

    if (!m) return retail

    const nav = [
        { key: 'dashboard', href: '/client/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard' },
        { key: 'corporate-requests', href: '/corporate/requests', icon: 'fas fa-clipboard-list', label: 'Jobs' },
    ]

    if (m.can_raise) {
        nav.push({ key: 'corporate-new', href: '/corporate/requests/new', icon: 'fas fa-plus-circle', label: 'Raise' })
    }

    if (m.can_decide) {
        nav.push({ key: 'corporate-approvals', href: '/corporate/approvals', icon: 'fas fa-check-double', label: 'Approve' })
    }

    if (m.sees_whole_account && nav.length < 4) {
        nav.push({ key: 'corporate-billing', href: '/corporate/billing', icon: 'fas fa-file-invoice-dollar', label: 'Billing' })
    }

    nav.push({ key: 'profile', href: '/client/profile', icon: 'fas fa-user-circle', label: 'Profile' })

    return nav.slice(0, 5)
})
</script>

<style>
@import url('../../css/client-pwa.css');
</style>
