<template>
    <div class="app-sidebar-root">
        <header class="mobile-topbar" :class="{ 'drawer-open': isOpen }">
            <button
                type="button"
                class="mobile-topbar-toggle"
                :aria-expanded="isOpen ? 'true' : 'false'"
                aria-controls="app-sidebar-drawer"
                aria-label="Toggle navigation"
                @click="toggleDrawer"
            >
                <i :class="isOpen ? 'fas fa-times' : 'fas fa-bars'"></i>
            </button>

            <div class="mobile-topbar-brand">
                <div class="mobile-topbar-mark">TW</div>
                <div class="mobile-topbar-copy">
                    <strong>Technician World</strong>
                    <span>{{ roleLabel }}</span>
                </div>
            </div>
        </header>

        <div
            class="sidebar-backdrop"
            :class="{ visible: isOpen }"
            @click="closeDrawer"
            aria-hidden="true"
        ></div>

        <aside
            id="app-sidebar-drawer"
            class="sidebar"
            :class="{ 'drawer-open': isOpen }"
            :aria-hidden="isOpen ? 'false' : 'true'"
        >
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <div class="sidebar-brand-mark">TW</div>
                    <div class="sidebar-brand-copy">
                        <h2 class="logo">TECHNICIAN WORLD</h2>
                        <span class="sidebar-subtitle">{{ roleLabel }}</span>
                    </div>
                    <button
                        type="button"
                        class="sidebar-close"
                        aria-label="Close navigation"
                        @click="closeDrawer"
                    >
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div v-if="firstName" class="sidebar-user-chip">
                    <span class="sidebar-user-label">Signed in</span>
                    <strong>{{ firstName }}</strong>
                </div>

                <p v-if="roleHint" class="sidebar-intro">{{ roleHint }}</p>
            </div>

            <nav class="sidebar-nav">
                <span class="nav-section-label">Workspace</span>

                <div
                    v-for="item in items"
                    :key="item.key"
                    :class="['nav-group', { expanded: item.children?.length, active: isItemActive(item) }]"
                >
                    <Link
                        :href="item.href"
                        :class="['nav-item', { active: isItemActive(item) }]"
                        @click="closeDrawer"
                    >
                        <span class="nav-item-icon">
                            <i :class="item.icon"></i>
                        </span>
                        <span class="nav-item-copy">
                            <span>{{ item.label }}</span>
                            <small v-if="item.caption" class="nav-caption">{{ item.caption }}</small>
                        </span>
                        <span class="nav-item-indicator"></span>
                    </Link>

                    <div v-if="item.children?.length" class="nav-submenu">
                        <Link
                            v-for="child in item.children"
                            :key="child.key"
                            :href="child.href"
                            :class="['nav-subitem', { active: child.key === currentPage }]"
                            @click="closeDrawer"
                        >
                            <span>{{ child.label }}</span>
                            <small v-if="child.caption">{{ child.caption }}</small>
                        </Link>
                    </div>
                </div>
            </nav>

            <div class="sidebar-footer">
                <Link href="/logout" class="nav-item nav-item-logout" method="post" as="button">
                    <span class="nav-item-icon">
                        <i class="fas fa-sign-out-alt"></i>
                    </span>
                    <span class="nav-item-copy">
                        <span>Log Out</span>
                        <small class="nav-caption">End session securely</small>
                    </span>
                    <span class="nav-item-indicator"></span>
                </Link>
            </div>
        </aside>
    </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    roleLabel: { type: String, required: true },
    roleHint: { type: String, default: '' },
    currentPage: { type: String, required: true },
    items: { type: Array, default: () => [] },
})

const page = usePage()
const isOpen = ref(false)

const firstName = computed(() => {
    const name = page.props.auth?.user?.name || ''
    return name.split(' ')[0] || ''
})

function isItemActive(item) {
    return item.key === props.currentPage
        || item.children?.some((child) => child.key === props.currentPage)
}

function openDrawer() {
    isOpen.value = true
}

function closeDrawer() {
    isOpen.value = false
}

function toggleDrawer() {
    isOpen.value = !isOpen.value
}

function handleKeydown(event) {
    if (event.key === 'Escape' && isOpen.value) {
        closeDrawer()
    }
}

watch(isOpen, (open) => {
    if (typeof document === 'undefined') return
    document.body.classList.toggle('app-sidebar-locked', open)
})

let removeNavigateListener = null

onMounted(() => {
    window.addEventListener('keydown', handleKeydown)
    removeNavigateListener = router.on('navigate', () => {
        closeDrawer()
    })
})

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown)
    if (typeof removeNavigateListener === 'function') {
        removeNavigateListener()
    }
    if (typeof document !== 'undefined') {
        document.body.classList.remove('app-sidebar-locked')
    }
})
</script>

<style>
</style>
