import { router } from '@inertiajs/vue3'
import { onMounted, onUnmounted } from 'vue'

/**
 * Global handler that catches Inertia validation errors (422 + `errors` bag)
 * across every form on the page and:
 *   1. Plays a brief shake animation on the first error field.
 *   2. Scrolls it into view.
 *   3. Shows a toast with the first error message.
 *
 * Drop `<FormErrorToast />` somewhere in your layout and call
 * `useGlobalFormErrors()` once at app boot.
 */

const TOAST_DURATION_MS = 4500
const toastState = {
    visible: false,
    message: '',
    type: 'error', // 'error' | 'success' | 'info'
    _timeout: null,
}

const listeners = new Set()
const notify = () => listeners.forEach(fn => fn({ ...toastState }))

export function showToast(message, type = 'error', duration = TOAST_DURATION_MS) {
    if (toastState._timeout) clearTimeout(toastState._timeout)
    toastState.message = message
    toastState.type = type
    toastState.visible = true
    notify()
    toastState._timeout = setTimeout(() => {
        toastState.visible = false
        notify()
    }, duration)
}

export function dismissToast() {
    if (toastState._timeout) clearTimeout(toastState._timeout)
    toastState.visible = false
    notify()
}

export function useToastListener(callback) {
    onMounted(() => listeners.add(callback))
    onUnmounted(() => listeners.delete(callback))
}

/**
 * Find the first input/select/textarea associated with a validation error
 * key. Inertia error keys can be dot-paths (e.g. `entries.0.amount`), so
 * we try both the literal name and a CSS-escaped variant.
 */
function findFieldElement(errorKey) {
    const escaped = window.CSS?.escape?.(errorKey) || errorKey
    return (
        document.querySelector(`[name="${escaped}"]`) ||
        document.querySelector(`[name^="${escaped}["]`) || // nested arrays
        document.querySelector(`[data-error-key="${escaped}"]`)
    )
}

function highlightFirstError(errors) {
    const firstKey = Object.keys(errors || {})[0]
    if (!firstKey) return null

    const el = findFieldElement(firstKey)
    if (!el) return errors[firstKey]

    // Mark for shake animation
    el.classList.add('form-error-shake')
    el.setAttribute('aria-invalid', 'true')
    setTimeout(() => el.classList.remove('form-error-shake'), 600)

    // Scroll into view
    try {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' })
        setTimeout(() => el.focus({ preventScroll: true }), 200)
    } catch {
        el.focus()
    }

    return errors[firstKey]
}

let initialized = false
export function useGlobalFormErrors() {
    if (initialized) return
    initialized = true

    router.on('invalid', (event) => {
        // Inertia fires this for non-2xx, non-422 responses.
        // 422 errors go through the 'error' event below.
        const status = event.detail?.response?.status
        if (status >= 500) {
            showToast('Server error — please try again or contact support.', 'error')
        }
    })

    router.on('error', (event) => {
        const errors = event.detail?.errors || {}
        const errorCount = Object.keys(errors).length
        if (!errorCount) return

        // Wait for Vue to render the error messages first
        setTimeout(() => {
            const firstMessage = highlightFirstError(errors)
            const summary = errorCount === 1
                ? firstMessage
                : `${firstMessage} (and ${errorCount - 1} other${errorCount > 2 ? 's' : ''})`
            showToast(summary, 'error')
        }, 50)
    })

    router.on('success', (event) => {
        const flash = event.detail?.page?.props?.flash || {}
        if (flash.success) showToast(flash.success, 'success', 3000)
    })
}
