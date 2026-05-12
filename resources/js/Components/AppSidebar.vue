<template>
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="sidebar-brand-mark">TW</div>
                <div class="sidebar-brand-copy">
                    <h2 class="logo">TECHNICIAN WORLD</h2>
                    <span class="sidebar-subtitle">{{ roleLabel }}</span>
                </div>
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
</template>

<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

const props = defineProps({
    roleLabel: { type: String, required: true },
    roleHint: { type: String, default: '' },
    currentPage: { type: String, required: true },
    items: { type: Array, default: () => [] },
})

const page = usePage()

const firstName = computed(() => {
    const name = page.props.auth?.user?.name || ''
    return name.split(' ')[0] || ''
})

function isItemActive(item) {
    return item.key === props.currentPage
        || item.children?.some((child) => child.key === props.currentPage)
}
</script>

<style>
@import url('../../css/dashboard-app.css');
</style>
