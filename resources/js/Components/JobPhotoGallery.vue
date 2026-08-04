<template>
    <div v-if="normalized.length" class="job-photo-gallery">
        <div v-if="title || $slots.actions" class="job-photo-gallery-head">
            <strong v-if="title">{{ title }}</strong>
            <span class="job-photo-gallery-count">
                {{ normalized.length }} photo{{ normalized.length === 1 ? '' : 's' }}
            </span>
            <slot name="actions" />
        </div>

        <div class="job-photo-gallery-grid">
            <button
                v-for="(photo, index) in normalized"
                :key="photo.key"
                type="button"
                class="job-photo-thumb"
                :class="{ 'is-removed': photo.removed }"
                :aria-label="`Open photo ${index + 1} of ${normalized.length}`"
                @click="lightbox?.open(index)"
            >
                <img :src="photo.src" :alt="photo.caption || `Photo ${index + 1}`" loading="lazy" />

                <span v-if="photo.badge" class="job-photo-badge">{{ photo.badge }}</span>
                <span v-if="photo.caption" class="job-photo-caption">{{ photo.caption }}</span>

                <!-- Affordance that the grid opens a swipeable carousel
                     rather than a single image. -->
                <span class="job-photo-zoom"><i class="fas fa-expand"></i></span>
            </button>
        </div>

        <!-- hideThumbnails: the grid above IS the thumbnail strip; the
             lightbox is only the fullscreen carousel. -->
        <ImageLightbox ref="lightbox" :images="normalized" hide-thumbnails />
    </div>
</template>

<script setup>
/**
 * Shared photo strip for a job. Used on the technician, client, admin and PM
 * screens so a photo looks and behaves the same to everyone who opens it —
 * tap a thumbnail, then swipe or arrow through the whole set.
 *
 * Accepts the JobPhoto shape straight from the server (file_path/url/caption)
 * as well as plain URL strings.
 */
import { computed, ref } from 'vue'
import ImageLightbox from './ImageLightbox.vue'

const props = defineProps({
    photos: { type: Array, default: () => [] },
    title: { type: String, default: '' },
    /** Label removed-from-approval photos, for the ops-side screens. */
    showRemovedBadge: { type: Boolean, default: false },
})

const lightbox = ref(null)

const normalized = computed(() =>
    (props.photos || []).map((photo, index) => {
        if (typeof photo === 'string') {
            return { key: index, src: photo, caption: null, removed: false, badge: null }
        }

        const removed = Boolean(photo.removed_by_pm)

        return {
            key: photo.id ?? index,
            // `url` is appended by the JobPhoto model; file_path is the raw
            // column, kept as a fallback for callers that select columns.
            src: photo.url || photo.file_path || photo.src || '',
            caption: photo.caption || null,
            filename: photo.original_filename || null,
            removed,
            badge: props.showRemovedBadge && removed ? 'Removed from approval' : null,
        }
    }).filter(photo => photo.src)
)
</script>

<style scoped>
.job-photo-gallery-head {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-bottom: .5rem;
}

.job-photo-gallery-head strong {
    font-size: .9rem;
}

.job-photo-gallery-count {
    font-size: .78rem;
    color: #6b7280;
}

.job-photo-gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
    gap: .5rem;
}

.job-photo-thumb {
    position: relative;
    aspect-ratio: 1;
    padding: 0;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    background: #f3f4f6;
    cursor: zoom-in;
    transition: transform .15s, box-shadow .15s;
}

.job-photo-thumb:hover {
    transform: scale(1.02);
    box-shadow: 0 4px 12px rgba(0, 0, 0, .12);
}

.job-photo-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.job-photo-thumb.is-removed img {
    opacity: .4;
    filter: grayscale(1);
}

.job-photo-badge {
    position: absolute;
    top: 4px;
    left: 4px;
    right: 4px;
    padding: 2px 5px;
    border-radius: 4px;
    background: rgba(180, 83, 9, .9);
    color: #fff;
    font-size: .62rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .02em;
}

.job-photo-caption {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    padding: .9rem .35rem .25rem;
    background: linear-gradient(transparent, rgba(17, 24, 39, .8));
    color: #fff;
    font-size: .68rem;
    text-align: left;
    /* One line only — the full caption is shown in the carousel. */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.job-photo-zoom {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: rgba(17, 24, 39, .55);
    color: #fff;
    font-size: .6rem;
    opacity: 0;
    transition: opacity .15s;
}

.job-photo-thumb:hover .job-photo-zoom {
    opacity: 1;
}

/* Touch devices have no hover, so the affordance stays visible there. */
@media (hover: none) {
    .job-photo-zoom { opacity: 1; }
}
</style>
