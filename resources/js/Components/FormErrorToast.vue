<template>
    <Transition name="toast-slide">
        <div
            v-if="state.visible"
            :class="['form-error-toast', `toast-${state.type}`]"
            role="alert"
            @click="dismissToast"
        >
            <i :class="iconClass"></i>
            <span class="toast-message">{{ state.message }}</span>
            <button @click.stop="dismissToast" class="toast-close" aria-label="Close">&times;</button>
        </div>
    </Transition>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useToastListener, dismissToast } from '../composables/useFormErrors.js'

const state = ref({ visible: false, message: '', type: 'error' })
useToastListener((s) => { state.value = s })

const iconClass = computed(() => ({
    error: 'fas fa-exclamation-circle',
    success: 'fas fa-check-circle',
    info: 'fas fa-info-circle',
}[state.value.type] || 'fas fa-info-circle'))
</script>

<style scoped>
.form-error-toast {
    position: fixed;
    top: 1.25rem;
    right: 1.25rem;
    z-index: 9999;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    border-radius: 8px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
    font-size: 0.92rem;
    font-weight: 500;
    max-width: 420px;
    min-width: 280px;
    cursor: pointer;
}
.toast-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.toast-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.toast-info { background: #dbeafe; color: #1e3a8a; border: 1px solid #93c5fd; }
.toast-message { flex: 1; }
.toast-close {
    background: none;
    border: none;
    font-size: 1.4rem;
    line-height: 1;
    color: currentColor;
    opacity: 0.7;
    cursor: pointer;
    padding: 0 0.25rem;
}
.toast-close:hover { opacity: 1; }
.toast-slide-enter-active, .toast-slide-leave-active { transition: all 0.3s ease; }
.toast-slide-enter-from { transform: translateX(120%); opacity: 0; }
.toast-slide-leave-to { transform: translateX(120%); opacity: 0; }
</style>

<style>
/* Global — applied to any input flagged by useFormErrors */
.form-error-shake {
    animation: form-error-shake-anim 0.6s cubic-bezier(.36,.07,.19,.97) both;
    border-color: #dc2626 !important;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.18) !important;
}
@keyframes form-error-shake-anim {
    10%, 90% { transform: translateX(-1px); }
    20%, 80% { transform: translateX(2px); }
    30%, 50%, 70% { transform: translateX(-4px); }
    40%, 60% { transform: translateX(4px); }
}
[aria-invalid="true"] {
    border-color: #dc2626 !important;
}
</style>
