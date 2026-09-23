<template>
    <AppSidebar
        :role-label="roleLabel"
        :role-hint="roleHint"
        :current-page="resolvedPage"
        :items="navItems"
    />
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import AppSidebar from './AppSidebar.vue'

const props = defineProps({
    currentPage: {
        type: String,
        default: 'dashboard',
    },
})

const resolvedPage = computed(() => props.currentPage === 'statements' ? 'payments' : props.currentPage)

const page = usePage()

// Null for every retail client. Set only when this account acts for a
// management company and the module is on — see HandleInertiaRequests.
const membership = computed(() => page.props.corporate?.membership || null)

const roleLabel = computed(() => membership.value ? 'Property Management' : 'Client Portal')
const roleHint = computed(() => membership.value
    ? `${membership.value.organisation} · ${membership.value.position_label}`
    : 'Submit requests, review progress, and track payments with fewer clicks.')

const retailItems = [
    { key: 'dashboard', href: '/client/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Overview and updates' },
    { key: 'new-request', href: '/client/new-request', icon: 'fas fa-plus-circle', label: 'New Request', caption: 'Start a new job' },
    { key: 'payments', href: '/client/payments', icon: 'fas fa-file-invoice-dollar', label: 'Payments', caption: 'Statements and balances' },
    { key: 'tickets', href: '/client/tickets', icon: 'fas fa-life-ring', label: 'My Tickets', caption: 'Open and track support tickets' },
    { key: 'profile', href: '/client/profile', icon: 'fas fa-user-circle', label: 'Profile', caption: 'Personal details' },
]

/**
 * What a corporate member is offered, by position.
 *
 * Mirrors the server rules rather than listing everything: a menu item that
 * leads to a 403 is worse than no menu item. A caretaker raises work and sees
 * their own jobs; the people who sign work off get the approvals queue and the
 * money screens.
 */
const navItems = computed(() => {
    const m = membership.value

    if (!m) return retailItems

    const items = [
        { key: 'dashboard', href: '/client/dashboard', icon: 'fas fa-tachometer-alt', label: 'Dashboard', caption: 'Overview and updates' },
        { key: 'corporate-requests', href: '/corporate/requests', icon: 'fas fa-clipboard-list', label: 'Jobs', caption: m.sees_whole_account ? 'Every job on the account' : 'The jobs you raised' },
    ]

    if (m.can_raise) {
        items.push({ key: 'corporate-new', href: '/corporate/requests/new', icon: 'fas fa-plus-circle', label: 'Raise a Job', caption: 'Report something that needs fixing' })
    }

    if (m.can_decide) {
        items.push({ key: 'corporate-approvals', href: '/corporate/approvals', icon: 'fas fa-check-double', label: 'Approvals', caption: 'Quotations awaiting you' })
    }

    items.push({ key: 'corporate-variations', href: '/corporate/variation-cards', icon: 'fas fa-file-signature', label: 'Variation Cards', caption: 'Additional scope on live jobs' })

    if (m.sees_whole_account) {
        items.push({ key: 'corporate-billing', href: '/corporate/billing', icon: 'fas fa-file-invoice-dollar', label: 'Billing', caption: 'Invoices, payments and tax certificates' })
    }

    items.push(
        { key: 'tickets', href: '/client/tickets', icon: 'fas fa-life-ring', label: 'My Tickets', caption: 'Open and track support tickets' },
        { key: 'profile', href: '/client/profile', icon: 'fas fa-user-circle', label: 'Profile', caption: 'Personal details' },
    )

    return items
})
</script>
