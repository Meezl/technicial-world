<template>
    <div class="photo-uploader">
        <!-- Two inputs on purpose:
             - the gallery picker takes `multiple` and opens the photo library
             - the camera shortcut sets `capture` and opens the rear camera
             iOS Safari ignores `multiple` when `capture` is present, so a
             single combined input would silently cap the technician at one
             photo per tap. Both feed the same queue below. -->
        <input
            ref="galleryInput"
            type="file"
            :accept="accept"
            multiple
            class="photo-uploader-hidden"
            @change="onPick"
        />
        <input
            ref="cameraInput"
            type="file"
            :accept="accept"
            capture="environment"
            class="photo-uploader-hidden"
            @change="onPick"
        />

        <div class="photo-uploader-actions">
            <!-- Self-contained styling on purpose: the .btn vocabulary differs
                 per area (the technician PWA has no .btn-secondary, and its
                 .btn is width:100%, which stacks these), and this component is
                 shared across technician, client and admin screens. -->
            <button
                type="button"
                class="photo-uploader-btn"
                :disabled="disabled || isFull"
                @click="cameraInput.click()"
            >
                <i class="fas fa-camera"></i> Take photo
            </button>
            <button
                type="button"
                class="photo-uploader-btn"
                :disabled="disabled || isFull"
                @click="galleryInput.click()"
            >
                <i class="fas fa-images"></i> Choose from gallery
            </button>
            <span class="photo-uploader-count" :class="{ 'is-full': isFull }">
                {{ entries.length }} of {{ max }}
            </span>
        </div>

        <p v-if="hint" class="photo-uploader-hint">{{ hint }}</p>

        <!-- Rejections are shown rather than swallowed. The previous version
             silently truncated to the limit, so a technician who picked 9
             photos got 6 uploaded and no explanation. -->
        <p v-if="notice" class="photo-uploader-notice">
            <i class="fas fa-circle-info"></i> {{ notice }}
        </p>

        <div v-if="entries.length" class="photo-uploader-grid">
            <div
                v-for="entry in entries"
                :key="entry.id"
                class="photo-uploader-tile"
                :class="{ 'is-working': entry.status === 'working' }"
            >
                <img :src="entry.previewUrl" :alt="entry.file.name" />

                <div v-if="entry.status === 'working'" class="photo-uploader-tile-overlay">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>

                <button
                    type="button"
                    class="photo-uploader-remove"
                    :disabled="disabled"
                    :aria-label="`Remove ${entry.file.name}`"
                    @click="remove(entry.id)"
                >
                    <i class="fas fa-times"></i>
                </button>

                <span class="photo-uploader-size">{{ formatBytes(entry.file.size) }}</span>
            </div>
        </div>

        <p v-if="entries.length && savedBytes > 0" class="photo-uploader-savings">
            <i class="fas fa-feather"></i>
            Resized for upload: {{ formatBytes(originalBytes) }} → {{ formatBytes(currentBytes) }}
        </p>
    </div>
</template>

<script setup>
/**
 * Multi-photo picker for evidence and progress reports.
 *
 * Owns the selected files in a reactive queue instead of reading
 * `input.files` at submit time. A file input's FileList is *replaced* on
 * every pick, so reading it late meant "pick 3, then pick 2 more" uploaded
 * only the last 2 — the first three vanished with no error.
 *
 * Photos are compressed as they are added (not at submit) so the byte
 * counts shown to the user are the real upload size, and pressing Submit
 * on a slow site doesn't stall on CPU work before the request starts.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { compressImage } from '@/composables/useImageCompression.js'

const props = defineProps({
    /** v-model: array of File objects, ready to append to FormData. */
    modelValue: { type: Array, default: () => [] },
    max: { type: Number, default: 6 },
    /** image/* keeps HEIC and other camera output pickable on iOS. */
    accept: { type: String, default: 'image/*' },
    hint: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    compress: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'busy'])

const galleryInput = ref(null)
const cameraInput = ref(null)
const entries = ref([])   // { id, file, originalSize, previewUrl, status }
const notice = ref('')

let nextId = 0

const isFull = computed(() => entries.value.length >= props.max)
const busy = computed(() => entries.value.some(e => e.status === 'working'))
const originalBytes = computed(() => entries.value.reduce((n, e) => n + e.originalSize, 0))
const currentBytes = computed(() => entries.value.reduce((n, e) => n + e.file.size, 0))
const savedBytes = computed(() => originalBytes.value - currentBytes.value)

watch(busy, value => emit('busy', value))

async function onPick(event) {
    const picked = Array.from(event.target?.files || [])
    // Reset immediately so re-picking the same file (or re-shooting the same
    // scene) still fires a change event.
    event.target.value = ''
    if (!picked.length) return

    notice.value = ''
    const messages = []
    const room = props.max - entries.value.length

    const fresh = picked.filter(file => {
        if (isDuplicate(file)) {
            messages.push(`${file.name} was already added`)
            return false
        }
        return true
    })

    const accepted = fresh.slice(0, room)
    const rejected = fresh.length - accepted.length
    if (rejected > 0) {
        messages.push(`${rejected} photo${rejected > 1 ? 's' : ''} not added — limit is ${props.max}. Remove one to make room.`)
    }
    notice.value = messages.join('. ')

    for (const file of accepted) {
        const entry = {
            id: nextId++,
            file,
            // Snapshot the picked file's identity. `file` is swapped for the
            // compressed version below, which carries a fresh lastModified —
            // comparing against it would let the same photo in twice.
            originalName: file.name,
            originalSize: file.size,
            originalLastModified: file.lastModified,
            previewUrl: URL.createObjectURL(file),
            status: props.compress ? 'working' : 'ready',
        }
        entries.value.push(entry)
    }
    sync()

    if (!props.compress) return

    // Compress in parallel; each tile clears its spinner as it finishes.
    await Promise.all(entries.value
        .filter(e => e.status === 'working')
        .map(async entry => {
            entry.file = await compressImage(entry.file)
            entry.status = 'ready'
        }))
    sync()
}

function isDuplicate(file) {
    return entries.value.some(e =>
        e.originalName === file.name &&
        e.originalSize === file.size &&
        e.originalLastModified === file.lastModified
    )
}

function remove(id) {
    const index = entries.value.findIndex(e => e.id === id)
    if (index === -1) return
    URL.revokeObjectURL(entries.value[index].previewUrl)
    entries.value.splice(index, 1)
    notice.value = ''
    sync()
}

/** Clear the queue — call from the parent after a successful upload. */
function reset() {
    entries.value.forEach(e => URL.revokeObjectURL(e.previewUrl))
    entries.value = []
    notice.value = ''
    sync()
}

function sync() {
    emit('update:modelValue', entries.value.map(e => e.file))
}

function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

onBeforeUnmount(() => {
    entries.value.forEach(e => URL.revokeObjectURL(e.previewUrl))
})

defineExpose({ reset })
</script>

<style scoped>
.photo-uploader-hidden {
    display: none;
}

.photo-uploader-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .5rem;
}

.photo-uploader-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    /* 44px min height — the standard one-handed tap target for a technician
       holding a phone on site. */
    min-height: 44px;
    padding: .5rem .9rem;
    border: 1px solid var(--border-color, #d1d5db);
    border-radius: 8px;
    background: #fff;
    color: var(--text-color, #111827);
    font-size: .875rem;
    font-weight: 500;
    font-family: inherit;
    cursor: pointer;
    transition: background-color .15s, border-color .15s;
}

.photo-uploader-btn:hover:not(:disabled) {
    background: #f9fafb;
    border-color: var(--primary-color, #053272);
}

.photo-uploader-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.photo-uploader-count {
    font-size: .85rem;
    font-weight: 600;
    color: #6b7280;
}

.photo-uploader-count.is-full {
    color: #b45309;
}

.photo-uploader-hint,
.photo-uploader-notice,
.photo-uploader-savings {
    margin: .5rem 0 0;
    font-size: .82rem;
    line-height: 1.4;
}

.photo-uploader-hint {
    color: #6b7280;
}

.photo-uploader-notice {
    color: #b45309;
}

.photo-uploader-savings {
    color: var(--success-color, #10b981);
}

.photo-uploader-grid {
    display: grid;
    /* 3 across on a phone, more on wider screens, without a media query. */
    grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
    gap: .5rem;
    margin-top: .75rem;
}

.photo-uploader-tile {
    position: relative;
    aspect-ratio: 1;
    border-radius: 10px;
    overflow: hidden;
    background: #f3f4f6;
}

.photo-uploader-tile img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.photo-uploader-tile.is-working img {
    opacity: .45;
}

.photo-uploader-tile-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--primary-color, #053272);
    font-size: 1.1rem;
}

.photo-uploader-remove {
    position: absolute;
    top: 4px;
    right: 4px;
    /* 28px + the surrounding padding keeps this thumb-tappable on a phone
       without covering the photo it belongs to. */
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 50%;
    background: rgba(17, 24, 39, .72);
    color: #fff;
    font-size: .75rem;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

.photo-uploader-remove:disabled {
    opacity: .5;
    cursor: not-allowed;
}

.photo-uploader-size {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 2px 5px;
    background: linear-gradient(transparent, rgba(17, 24, 39, .75));
    color: #fff;
    font-size: .68rem;
    text-align: right;
}
</style>
