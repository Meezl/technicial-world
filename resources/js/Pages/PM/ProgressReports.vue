<template>
    <PMLayout>
        <template #header>
            <div class="page-header-copy">
                <div>
                    <h1>Progress Validation</h1>
                    <p>Review field submissions, compare reported versus validated progress, and approve only what should flow into compensation and job decisions.</p>
                </div>
            </div>
        </template>

        <section class="reports-hero">
            <div class="hero-card hero-card-primary">
                <span class="hero-kicker">Validation Queue</span>
                <h2>{{ heroTitle }}</h2>
                <p>{{ heroMessage }}</p>

                <div class="hero-pills">
                    <span class="hero-pill">
                        <i class="fas fa-clipboard-check"></i>
                        {{ summary.pending || 0 }} pending reviews
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-images"></i>
                        {{ summary.with_photos || 0 }} reports with photos
                    </span>
                    <span class="hero-pill muted">
                        <i class="fas fa-user-edit"></i>
                        {{ summary.pm_authored || 0 }} PM-authored
                    </span>
                </div>
            </div>

            <div class="hero-card filter-card">
                <div>
                    <span class="hero-kicker">View Options</span>
                    <h3>Choose what to review</h3>
                    <p>Keep the queue focused on pending validations or widen the view to include completed decisions for reference.</p>
                </div>

                <label class="toggle-card">
                    <div class="toggle-copy">
                        <strong>Show pending only</strong>
                        <span>Hide already validated reports so the queue stays action-oriented.</span>
                    </div>
                    <input type="checkbox" v-model="pendingOnly" @change="applyFilter">
                </label>
            </div>
        </section>

        <section class="summary-grid">
            <article class="summary-card tone-orange">
                <div class="summary-topline">
                    <span class="summary-tag">Queue</span>
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <span class="summary-label">Pending Review</span>
                <strong class="summary-value">{{ summary.pending || 0 }}</strong>
                <p class="summary-note">Reports still waiting for your validated percentage and notes.</p>
            </article>

            <article class="summary-card tone-green">
                <div class="summary-topline">
                    <span class="summary-tag">Completed</span>
                    <i class="fas fa-check-circle"></i>
                </div>
                <span class="summary-label">Validated</span>
                <strong class="summary-value">{{ summary.validated || 0 }}</strong>
                <p class="summary-note">Reports already signed off and available as reference.</p>
            </article>

            <article class="summary-card tone-blue">
                <div class="summary-topline">
                    <span class="summary-tag">Evidence</span>
                    <i class="fas fa-camera"></i>
                </div>
                <span class="summary-label">With Photos</span>
                <strong class="summary-value">{{ summary.with_photos || 0 }}</strong>
                <p class="summary-note">Submissions that include site photos for visual verification.</p>
            </article>

            <article class="summary-card tone-slate">
                <div class="summary-topline">
                    <span class="summary-tag">Context</span>
                    <i class="fas fa-user-pen"></i>
                </div>
                <span class="summary-label">PM On Behalf</span>
                <strong class="summary-value">{{ summary.pm_authored || 0 }}</strong>
                <p class="summary-note">Reports submitted by a PM rather than directly by a technician.</p>
            </article>
        </section>

        <section class="panel-card queue-panel">
            <div class="section-heading">
                <div>
                    <p class="section-kicker">Reports</p>
                    <h3>Progress Review Queue</h3>
                </div>
                <span class="section-badge">{{ reports.total || reports.data?.length || 0 }} items</span>
            </div>

            <div v-if="reports.data?.length" class="report-list">
                <article v-for="report in reports.data" :key="report.id" class="report-card">
                    <div class="report-card-head">
                        <div class="report-heading">
                            <div>
                                <h3>{{ report.service_request?.job_reference || report.service_request?.request_id }}</h3>
                                <p class="report-meta">
                                    Submitted by {{ report.submitter?.name || 'Unknown' }}
                                    <span v-if="report.is_pm_authored"> • PM on behalf</span>
                                    <span> • {{ formatDate(report.report_date) }}</span>
                                </p>
                            </div>
                            <span :class="['status-badge', report.is_validated ? 'tone-green' : 'tone-orange']">
                                {{ report.is_validated ? 'Validated' : 'Pending' }}
                            </span>
                        </div>

                        <div class="metric-grid">
                            <div class="metric-chip">
                                <span>Reported</span>
                                <strong>{{ report.percent_complete }}%</strong>
                            </div>
                            <div class="metric-chip" :class="report.is_validated ? 'validated' : ''">
                                <span>Validated</span>
                                <strong>{{ report.is_validated && report.validated_percent !== null ? `${report.validated_percent}%` : 'Pending' }}</strong>
                            </div>
                        </div>
                    </div>

                    <div class="progress-compare">
                        <div class="progress-lane">
                            <div class="progress-label-row">
                                <span>Reported progress</span>
                                <strong>{{ report.percent_complete }}%</strong>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill report" :style="{ width: `${report.percent_complete || 0}%` }"></div>
                            </div>
                        </div>

                        <div class="progress-lane" v-if="report.is_validated && report.validated_percent !== null">
                            <div class="progress-label-row">
                                <span>Validated progress</span>
                                <strong class="green-text">{{ report.validated_percent }}%</strong>
                            </div>
                            <div class="progress-track">
                                <div class="progress-fill validated" :style="{ width: `${report.validated_percent || 0}%` }"></div>
                            </div>
                        </div>
                    </div>

                    <div v-if="report.notes" class="notes-block">
                        <span class="notes-label">Report notes</span>
                        <p>{{ report.notes }}</p>
                    </div>

                    <div v-if="report.photos?.length" class="photo-section">
                        <div class="photo-section-head">
                            <strong>Attached photos</strong>
                            <span>{{ report.photos.length }} file{{ report.photos.length === 1 ? '' : 's' }}</span>
                        </div>

                        <div class="photo-grid">
                            <div
                                v-for="(photo, photoIndex) in report.photos"
                                :key="photo.id"
                                class="photo-item"
                                :class="{
                                    removed: photo.removed_by_pm || validationForms[report.id]?.remove_photo_ids.includes(photo.id),
                                }"
                            >
                                <!-- Click to open the carousel: validating a
                                     report means actually looking at the
                                     photos, not squinting at 120px tiles. -->
                                <button
                                    type="button"
                                    class="photo-open"
                                    :aria-label="`Open photo ${photoIndex + 1} of ${report.photos.length}`"
                                    @click="openPhotoCarousel(report.photos, photoIndex)"
                                >
                                    <img :src="photo.url || '/storage/' + photo.file_path" :alt="photo.caption || 'Progress photo'">
                                </button>
                                <div class="photo-overlay">
                                    <span v-if="photo.caption" class="photo-caption">{{ photo.caption }}</span>
                                    <button
                                        v-if="!report.is_validated && !photo.removed_by_pm"
                                        type="button"
                                        class="photo-remove"
                                        @click="togglePhotoRemoval(report, photo.id)"
                                    >
                                        <i :class="validationForms[report.id]?.remove_photo_ids.includes(photo.id) ? 'fas fa-undo' : 'fas fa-times'"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="!report.is_validated" class="validation-form">
                        <div class="validation-head">
                            <div>
                                <span class="notes-label">Validation decision</span>
                                <p>Confirm the acceptable progress level and leave context for downstream payment or job decisions.</p>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Validated Progress %</label>
                                <input
                                    type="number"
                                    v-model.number="validationForms[report.id].validated_percent"
                                    min="0"
                                    max="100"
                                    step="1"
                                >
                            </div>
                            <div class="form-group">
                                <label>Validation Notes</label>
                                <input
                                    type="text"
                                    v-model="validationForms[report.id].validation_notes"
                                    placeholder="Optional notes for the technician and finance team..."
                                >
                            </div>
                        </div>

                        <button class="btn btn-primary btn-sm validate-button" @click="validateReport(report)" :disabled="submitting">
                            <i class="fas fa-check-circle"></i>
                            {{ submitting ? 'Validating...' : 'Validate Progress' }}
                        </button>
                    </div>

                    <div v-if="report.validation_notes" class="validation-notes">
                        <i class="fas fa-comment-alt"></i>
                        <span>{{ report.validation_notes }}</span>
                    </div>
                </article>
            </div>

            <div v-if="!reports.data?.length" class="empty-state">
                <i class="fas fa-clipboard-check"></i>
                <h3>No reports to review</h3>
                <p>Nothing matches the current filter, so your validation queue is clear for now.</p>
            </div>

            <div class="pagination" v-if="reports.last_page > 1">
                <Link
                    v-for="link in reports.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    class="btn btn-sm"
                    :class="{ 'btn-primary': link.active, 'btn-secondary': !link.active }"
                    v-html="link.label"
                    :disabled="!link.url"
                />
            </div>
        </section>

        <ImageLightbox
            :images="carouselPhotos"
            :initial-index="carouselIndex"
            hide-thumbnails
            @close="carouselIndex = null"
        />
    </PMLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PMLayout from '../../Layouts/PMLayout.vue'
import ImageLightbox from '../../Components/ImageLightbox.vue'

const props = defineProps({
    reports: { type: Object, default: () => ({ data: [] }) },
    summary: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
})

const pendingOnly = ref(props.filters.pending_only !== false)
const submitting = ref(false)
const validationForms = reactive({})

props.reports.data?.forEach((report) => {
    validationForms[report.id] = {
        validated_percent: report.percent_complete,
        validation_notes: report.validation_notes || '',
        remove_photo_ids: [],
    }
})

const heroTitle = computed(() => {
    if (Number(props.summary.pending || 0) > 0) return 'Pending progress reports are ready for your review.'
    return 'Your progress validation queue is currently clear.'
})

const heroMessage = computed(() => {
    if (Number(props.summary.pending || 0) > 0) {
        return 'Clear validations promptly so compensation, milestones, and job updates stay aligned with real field progress.'
    }

    return 'You can switch off the pending-only view any time to revisit previously validated reports.'
})

function applyFilter() {
    router.get('/pm/progress-reports', {
        pending_only: pendingOnly.value,
    }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

// Photo carousel, shared by every report card on the page.
const carouselPhotos = ref([])
const carouselIndex = ref(null)

function openPhotoCarousel(photos, index) {
    carouselPhotos.value = (photos || []).map(photo => ({
        src: photo.url || photo.file_path,
        caption: photo.caption,
        filename: photo.original_filename,
    }))
    carouselIndex.value = index
}

function togglePhotoRemoval(report, photoId) {
    const form = validationForms[report.id]
    const index = form.remove_photo_ids.indexOf(photoId)

    if (index > -1) form.remove_photo_ids.splice(index, 1)
    else form.remove_photo_ids.push(photoId)
}

function validateReport(report) {
    submitting.value = true
    router.post(`/pm/progress-reports/${report.id}/validate`, validationForms[report.id], {
        onSuccess: () => {
            submitting.value = false
        },
        onError: () => {
            submitting.value = false
        },
    })
}

function formatDate(value) {
    if (!value) return 'No date'

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value))
}

defineOptions({ layout: null })
</script>

<style scoped>
.page-header-copy p {
    margin: 0.45rem 0 0;
    color: #64748b;
    max-width: 60ch;
}

.reports-hero,
.summary-grid {
    display: grid;
    gap: 1rem;
    margin-bottom: 1.35rem;
}

.reports-hero {
    grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
}

.hero-card,
.panel-card,
.summary-card {
    border-radius: 28px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
}

.hero-card {
    padding: 1.5rem 1.65rem;
}

.hero-card-primary {
    background:
        radial-gradient(circle at top right, rgba(56, 189, 248, 0.18), transparent 35%),
        linear-gradient(135deg, #ffffff, #eff6ff);
}

.hero-kicker,
.summary-tag,
.section-kicker,
.notes-label {
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #0284c7;
}

.hero-card h2,
.filter-card h3,
.section-heading h3,
.report-heading h3 {
    margin: 0.55rem 0 0;
    color: #0f172a;
}

.hero-card p,
.filter-card p,
.summary-note,
.report-meta,
.notes-block p,
.validation-head p,
.validation-notes,
.empty-state p {
    color: #64748b;
}

.hero-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.3rem;
}

.hero-pill,
.section-badge,
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.72rem 0.95rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 700;
    background: rgba(226, 232, 240, 0.8);
    color: #0f172a;
}

.hero-pill.muted,
.section-badge {
    background: #f8fafc;
    color: #475569;
}

.filter-card {
    display: grid;
    gap: 1rem;
    background: #ffffff;
}

.toggle-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.05rem;
    border-radius: 20px;
    background: #f8fafc;
    border: 1px solid rgba(226, 232, 240, 0.9);
    cursor: pointer;
}

.toggle-copy {
    display: grid;
    gap: 0.25rem;
}

.toggle-copy strong {
    color: #0f172a;
}

.toggle-copy span {
    color: #64748b;
    font-size: 0.9rem;
    line-height: 1.5;
}

.summary-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.summary-card {
    padding: 1.25rem;
    background: #ffffff;
}

.summary-topline,
.section-heading,
.report-card-head,
.report-heading,
.validation-head,
.photo-section-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.8rem;
}

.summary-topline i {
    color: #0f172a;
}

.summary-label {
    display: block;
    margin-top: 0.95rem;
    color: #475569;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.summary-value {
    display: block;
    margin-top: 0.3rem;
    color: #0f172a;
    font-size: 1.55rem;
}

.summary-note {
    margin: 0.7rem 0 0;
    line-height: 1.55;
}

.tone-orange {
    background: linear-gradient(180deg, #fff7ed, #fed7aa);
}

.tone-green {
    background: linear-gradient(180deg, #ecfdf5, #dcfce7);
}

.tone-blue {
    background: linear-gradient(180deg, #eff6ff, #dbeafe);
}

.tone-slate {
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.queue-panel {
    padding: 1.4rem;
    background: #ffffff;
}

.report-list {
    display: grid;
    gap: 1rem;
    margin-top: 1rem;
}

.report-card {
    padding: 1.2rem;
    border-radius: 24px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
}

.report-meta {
    margin: 0.35rem 0 0;
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.metric-chip {
    display: grid;
    gap: 0.2rem;
    padding: 0.8rem 0.9rem;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.88);
}

.metric-chip.validated {
    background: #ecfdf5;
}

.metric-chip span {
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.metric-chip strong {
    color: #0f172a;
    font-size: 1rem;
}

.status-badge.tone-green {
    background: rgba(34, 197, 94, 0.14);
    color: #15803d;
}

.status-badge.tone-orange {
    background: rgba(249, 115, 22, 0.14);
    color: #c2410c;
}

.progress-compare {
    display: grid;
    gap: 0.85rem;
    margin-top: 1rem;
}

.progress-lane {
    display: grid;
    gap: 0.35rem;
}

.progress-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.progress-label-row span {
    color: #475569;
    font-weight: 700;
}

.progress-track {
    position: relative;
    width: 100%;
    height: 0.62rem;
    border-radius: 999px;
    overflow: hidden;
    background: #e2e8f0;
}

.progress-fill {
    height: 100%;
    border-radius: inherit;
}

.progress-fill.report {
    background: linear-gradient(90deg, #38bdf8, #2563eb);
}

.progress-fill.validated {
    background: linear-gradient(90deg, #22c55e, #16a34a);
}

.green-text {
    color: #15803d;
}

.notes-block {
    margin-top: 1rem;
    padding: 0.95rem 1rem;
    border-radius: 18px;
    background: #f8fafc;
}

.notes-block p {
    margin: 0.45rem 0 0;
    line-height: 1.6;
}

.photo-section {
    margin-top: 1rem;
}

.photo-section-head {
    margin-bottom: 0.8rem;
}

.photo-section-head strong {
    color: #0f172a;
}

.photo-section-head span {
    color: #64748b;
    font-size: 0.85rem;
}

.photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 0.85rem;
}

.photo-item {
    position: relative;
    min-height: 150px;
    border-radius: 18px;
    overflow: hidden;
    border: 2px solid rgba(226, 232, 240, 0.95);
    background: #e2e8f0;
}

.photo-item.removed {
    opacity: 0.45;
    border-color: #ef4444;
}

/* The thumbnail became a button (it opens the carousel) — strip the default
   button chrome and let it fill the tile. */
.photo-open {
    display: block;
    width: 100%;
    height: 100%;
    padding: 0;
    border: none;
    background: none;
    cursor: zoom-in;
}

.photo-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.photo-overlay {
    position: absolute;
    inset: auto 0 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.55rem;
    background: linear-gradient(180deg, transparent, rgba(15, 23, 42, 0.8));
}

.photo-caption {
    color: #ffffff;
    font-size: 0.74rem;
    line-height: 1.35;
}

.photo-remove {
    width: 2rem;
    height: 2rem;
    border: 0;
    border-radius: 999px;
    background: rgba(239, 68, 68, 0.92);
    color: #ffffff;
    display: grid;
    place-items: center;
    cursor: pointer;
}

.validation-form {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 20px;
    background: linear-gradient(180deg, #eff6ff, #dbeafe);
}

.validation-head p {
    margin: 0.4rem 0 0;
    max-width: 56ch;
}

.validate-button {
    margin-top: 1rem;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.validation-notes {
    margin-top: 0.9rem;
    display: flex;
    gap: 0.55rem;
    align-items: flex-start;
    padding: 0.85rem 0.95rem;
    border-radius: 16px;
    background: #f8fafc;
    line-height: 1.5;
}

.empty-state {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
    text-align: center;
    padding: 3rem 1rem 1rem;
}

.empty-state i {
    font-size: 2.1rem;
    color: #0284c7;
}

.empty-state h3 {
    margin: 0;
    color: #0f172a;
}

.pagination {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.55rem;
    margin-top: 1.5rem;
}

@media (max-width: 1180px) {
    .reports-hero,
    .summary-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 820px) {
    .metric-grid,
    .photo-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 640px) {
    .metric-grid,
    .photo-grid,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .hero-card,
    .queue-panel,
    .report-card {
        padding: 1rem;
    }

    .toggle-card,
    .report-heading,
    .report-card-head,
    .validation-head {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
