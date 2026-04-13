<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <h1>My Tools</h1>
            <div class="header-actions">
                <button class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Request
                </button>
            </div>
        </header>

        <main class="pwa-content">
            <!-- Search -->
            <div class="pwa-card" style="padding: 0.5rem; margin-bottom: 1rem;">
                <input 
                    type="text" 
                    placeholder="Search tools..." 
                    style="width: 100%; border: none; outline: none; padding: 0.5rem; font-size: 1rem;"
                >
            </div>

            <!-- Issued Tools -->
            <h2 style="font-size: 1rem; color: var(--light-text); margin-bottom: 0.5rem; padding-left: 0.5rem;">Currently Issued</h2>
            <div v-if="issuedTools.length > 0">
                <div v-for="tool in issuedTools" :key="tool.id" class="pwa-card">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <div>
                            <h3 style="margin: 0; font-size: 1.1rem;">{{ tool.name }}</h3>
                            <span style="font-size: 0.8rem; color: var(--light-text);">ID: {{ tool.serial_number || tool.id }}</span>
                        </div>
                        <div style="background: #E0E7FF; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--primary-color);">
                            <i class="fas fa-hammer"></i>
                        </div>
                    </div>
                    
                    <p style="font-size: 0.9rem; color: var(--text-color); margin-bottom: 0.5rem;">
                        Condition: <span style="font-weight: 500;">{{ formatCondition(tool.condition) }}</span>
                    </p>
                    
                    <p v-if="tool.service_request" style="font-size: 0.8rem; color: var(--light-text); margin-bottom: 1rem;">
                        <i class="fas fa-briefcase" style="margin-right: 5px;"></i> Assigned to Job #{{ tool.service_request.id }}
                    </p>

                    <button class="btn btn-outline" style="color: var(--danger-color); border-color: var(--danger-color);" @click="returnTool(tool)">
                        Return Tool
                    </button>
                </div>
            </div>
            <div v-else class="pwa-card" style="text-align: center; padding: 2rem 1rem; color: var(--light-text);">
                <p>No tools currently issued to you.</p>
            </div>

            <!-- Return History -->
            <h2 style="font-size: 1rem; color: var(--light-text); margin-bottom: 0.5rem; padding-left: 0.5rem; margin-top: 1.5rem;">Recent Returns</h2>
            <div v-if="returnHistory.length > 0">
                <div class="pwa-card" style="padding: 0;">
                    <div v-for="tool in returnHistory" :key="tool.id" class="list-item" style="padding: 1rem;">
                        <div>
                            <div style="font-weight: 600;">{{ tool.name }}</div>
                            <div style="font-size: 0.8rem; color: var(--light-text);">Returned: {{ formatDate(tool.returned_at || tool.updated_at) }}</div>
                        </div>
                        <span class="status-badge" style="background: #F3F4F6; color: var(--text-color);">Returned</span>
                    </div>
                </div>
            </div>

        </main>

        <TechnicianBottomNav current-page="tools" />
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue';

const props = defineProps({
    technician: Object,
    issuedTools: Array,
    returnHistory: Array,
});

const returnTool = (tool) => {
    const condition = prompt('Please confirm tool condition (good, fair, needs_repair, damaged):', 'good');
    if (condition && ['good', 'fair', 'needs_repair', 'damaged'].includes(condition)) {
        router.post(`/technician/tools/${tool.id}/return`, { condition }, {
            preserveScroll: true,
        });
    } else if (condition) {
        alert('Invalid condition entered.');
    }
};

const formatCondition = (condition) => {
    return condition ? condition.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'Unknown';
};

const formatDate = (dateString) => {
    if (!dateString) return '';
    return new Date(dateString).toLocaleDateString();
};

defineOptions({ layout: null });
</script>

<style>
@import url('../../../css/technician-pwa.css');
</style>
