import { watch, onMounted, nextTick } from 'vue'

/**
 * Auto-save a reactive form to localStorage so users can leave and resume.
 *
 * Level 1 draft mechanic: text/number/select fields survive tab close and
 * navigation on the same browser. File inputs are intentionally NOT saved
 * (File objects don't serialize and browsers can't rehydrate a file picker
 * from JSON) — the user still has to re-attach files after resuming.
 *
 * @param {string} key         Unique namespace, e.g. 'client-new-request'
 * @param {Array<{ref, path}>} bindings — reactive refs to save/restore.
 *   Each entry is { ref: Vue ref, path: string (dot-path inside ref.value or empty) }.
 * @param {object} options
 * @param {string[]} [options.exclude=[]] — top-level field names inside each ref to skip.
 * @param {number}   [options.debounceMs=400]
 * @returns {{ clear: () => void, hasDraft: () => boolean }}
 */
export function useFormAutosave(key, bindings, options = {}) {
    const storageKey = `formdraft:${key}`
    const { exclude = [], debounceMs = 400 } = options

    // Restore on mount (nextTick so parent v-models are wired first)
    onMounted(async () => {
        await nextTick()
        try {
            const raw = localStorage.getItem(storageKey)
            if (!raw) return
            const saved = JSON.parse(raw)
            bindings.forEach((binding, idx) => {
                const slot = saved[`b${idx}`]
                if (slot === undefined) return
                if (isPlainObject(slot) && isPlainObject(binding.ref.value)) {
                    Object.keys(slot).forEach((k) => {
                        if (exclude.includes(k)) return
                        binding.ref.value[k] = slot[k]
                    })
                } else {
                    binding.ref.value = slot
                }
            })
        } catch (_e) {
            // Corrupt draft — throw it away silently.
            localStorage.removeItem(storageKey)
        }
    })

    // Save on any change, debounced
    let timer = null
    bindings.forEach((binding, idx) => {
        watch(
            binding.ref,
            () => {
                clearTimeout(timer)
                timer = setTimeout(() => write(bindings, storageKey, exclude), debounceMs)
            },
            { deep: true }
        )
    })

    return {
        clear: () => {
            try { localStorage.removeItem(storageKey) } catch (_e) { /* no-op */ }
        },
        hasDraft: () => {
            try { return localStorage.getItem(storageKey) !== null } catch (_e) { return false }
        },
    }
}

function write(bindings, storageKey, exclude) {
    try {
        const payload = {}
        bindings.forEach((binding, idx) => {
            const val = binding.ref.value
            if (isPlainObject(val)) {
                const clean = {}
                Object.keys(val).forEach((k) => {
                    if (exclude.includes(k)) return
                    const v = val[k]
                    // Skip File / Blob / FileList — can't be serialized.
                    if (v instanceof File || v instanceof Blob) return
                    if (typeof FileList !== 'undefined' && v instanceof FileList) return
                    if (Array.isArray(v) && v.some((x) => x instanceof File || x instanceof Blob)) return
                    clean[k] = v
                })
                payload[`b${idx}`] = clean
            } else {
                payload[`b${idx}`] = val
            }
        })
        localStorage.setItem(storageKey, JSON.stringify(payload))
    } catch (_e) {
        // Quota exceeded / private mode / SSR — no-op.
    }
}

function isPlainObject(v) {
    return v !== null && typeof v === 'object' && v.constructor === Object
}
