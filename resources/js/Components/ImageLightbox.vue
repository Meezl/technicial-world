<template>
    <!-- Thumbnail grid -->
    <div v-if="!hideThumbnails" class="lightbox-thumbnails">
        <div
            v-for="(img, idx) in normalizedImages"
            :key="idx"
            class="lightbox-thumb"
            @click="open(idx)"
            :title="img.caption || `Image ${idx + 1}`"
        >
            <img
                :src="img.src"
                :alt="img.caption || `Image ${idx + 1}`"
                @error="onThumbError($event, img)"
            />
            <span v-if="img.caption" class="lightbox-thumb-caption">{{ img.caption }}</span>
        </div>
    </div>

    <!-- Fullscreen overlay -->
    <Teleport to="body">
        <Transition name="lightbox-fade">
            <div
                v-if="openIndex !== null"
                class="lightbox-overlay"
                @click.self="close"
                @keydown.esc="close"
                @keydown.left="prev"
                @keydown.right="next"
                tabindex="0"
                ref="overlayRef"
            >
                <button class="lightbox-close" @click="close" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
                <button
                    v-if="normalizedImages.length > 1"
                    class="lightbox-nav lightbox-nav-prev"
                    @click.stop="prev"
                    aria-label="Previous"
                >
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button
                    v-if="normalizedImages.length > 1"
                    class="lightbox-nav lightbox-nav-next"
                    @click.stop="next"
                    aria-label="Next"
                >
                    <i class="fas fa-chevron-right"></i>
                </button>

                <div class="lightbox-stage" @click.stop>
                    <img
                        :src="currentImage.src"
                        :alt="currentImage.caption || ''"
                        class="lightbox-img"
                    />
                    <div class="lightbox-meta">
                        <div v-if="currentImage.caption" class="lightbox-caption">{{ currentImage.caption }}</div>
                        <div class="lightbox-position">{{ openIndex + 1 }} of {{ normalizedImages.length }}</div>
                        <a
                            :href="currentImage.downloadUrl || currentImage.src"
                            :download="currentImage.filename || ''"
                            target="_blank"
                            class="lightbox-download"
                        >
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps({
    /**
     * Array of image objects. Each can be either:
     *   - A string URL
     *   - { src, caption, filename, downloadUrl }
     * Storage paths starting with 'storage/' or relative paths are
     * automatically prefixed with '/storage/' when they don't already
     * include a host.
     */
    images: { type: Array, required: true },
    hideThumbnails: { type: Boolean, default: false },
    /** Pass an index to open programmatically; null closes. */
    initialIndex: { type: Number, default: null },
})

const emit = defineEmits(['close'])

const openIndex = ref(props.initialIndex)
const overlayRef = ref(null)

watch(() => props.initialIndex, (v) => {
    openIndex.value = v
})

const normalizedImages = computed(() =>
    (props.images || []).map((raw) => {
        if (typeof raw === 'string') {
            return { src: resolveSrc(raw), caption: null, filename: null, downloadUrl: null }
        }
        return {
            src: resolveSrc(raw.src || raw.path || raw.file_path || raw.url || ''),
            caption: raw.caption || raw.label || raw.name || null,
            filename: raw.filename || raw.name || null,
            downloadUrl: raw.downloadUrl || null,
        }
    }).filter(img => img.src)
)

function resolveSrc(input) {
    if (!input) return ''
    if (input.startsWith('http://') || input.startsWith('https://') || input.startsWith('/')) {
        return input
    }
    // Storage-relative path (e.g. "progress-photos/abc.jpg")
    return '/storage/' + input.replace(/^\/+/, '')
}

const currentImage = computed(() =>
    openIndex.value !== null ? normalizedImages.value[openIndex.value] : { src: '', caption: '' }
)

const open = async (idx) => {
    openIndex.value = idx
    await nextTick()
    overlayRef.value?.focus()
}

const close = () => {
    openIndex.value = null
    emit('close')
}

const next = () => {
    if (openIndex.value === null) return
    openIndex.value = (openIndex.value + 1) % normalizedImages.value.length
}

const prev = () => {
    if (openIndex.value === null) return
    openIndex.value = openIndex.value === 0
        ? normalizedImages.value.length - 1
        : openIndex.value - 1
}

const onThumbError = (e, img) => {
    e.target.style.opacity = '0.35'
    e.target.title = 'Image not loading: ' + img.src
}

defineExpose({ open, close })
</script>

<style scoped>
.lightbox-thumbnails {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 0.6rem;
}
.lightbox-thumb {
    position: relative;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border-radius: 6px;
    border: 1px solid #e5e7eb;
    cursor: zoom-in;
    background: #f3f4f6;
    transition: transform 0.15s, box-shadow 0.15s;
}
.lightbox-thumb:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}
.lightbox-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.lightbox-thumb-caption {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(transparent, rgba(0, 0, 0, 0.65));
    color: white;
    font-size: 0.75rem;
    padding: 1rem 0.5rem 0.4rem;
    text-align: center;
}
</style>

<style>
/* Overlay styles must be global (Teleport target) */
.lightbox-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.85);
    z-index: 99998;
    display: flex;
    align-items: center;
    justify-content: center;
    outline: none;
}
.lightbox-close,
.lightbox-nav {
    position: absolute;
    background: rgba(255, 255, 255, 0.12);
    border: none;
    color: white;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s;
}
.lightbox-close:hover,
.lightbox-nav:hover {
    background: rgba(255, 255, 255, 0.25);
}
.lightbox-close { top: 1rem; right: 1rem; }
.lightbox-nav-prev { left: 1.25rem; }
.lightbox-nav-next { right: 1.25rem; }
.lightbox-stage {
    max-width: 92vw;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}
.lightbox-img {
    max-width: 92vw;
    max-height: 80vh;
    object-fit: contain;
    border-radius: 4px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}
.lightbox-meta {
    display: flex;
    gap: 1rem;
    align-items: center;
    color: white;
    font-size: 0.9rem;
    background: rgba(0, 0, 0, 0.4);
    padding: 0.5rem 1rem;
    border-radius: 6px;
}
.lightbox-caption { font-weight: 500; }
.lightbox-position { opacity: 0.7; font-size: 0.85rem; }
.lightbox-download {
    color: #93c5fd;
    text-decoration: none;
}
.lightbox-download:hover { color: white; }

.lightbox-fade-enter-active, .lightbox-fade-leave-active {
    transition: opacity 0.2s ease;
}
.lightbox-fade-enter-from, .lightbox-fade-leave-to { opacity: 0; }
</style>
