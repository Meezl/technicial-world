<!--
    What the report statuses mean, on the page rather than in somebody's head.

    New ops staff were reading a one-word badge with no way to answer the two
    questions that follow it: why has this technician's bar not moved, and why
    has the client heard nothing. Hover text does not carry that — it is
    invisible until you already suspect there is something to read, and useless
    on a phone. So the vocabulary is printed, with who each state is waiting on,
    what the office does next, and the words to use on the phone.

    Generated from ProgressReport::PIPELINE_STATES, so a state cannot exist
    without an explanation.
-->
<template>
    <details class="psl" :open="open">
        <summary>
            <i class="fas fa-circle-question"></i>
            What do these statuses mean?
            <span class="psl-hint">Who each one is waiting on, and what to say</span>
        </summary>

        <div v-if="!states.length" class="psl-empty">
            No status guide was sent with this page.
        </div>

        <ol v-else class="psl-list">
            <li v-for="state in states" :key="state.key" class="psl-item">
                <div class="psl-head">
                    <span :class="['status-badge', `tone-${state.tone}`]">{{ state.label }}</span>
                    <span class="psl-waiting">
                        <i class="fas fa-hourglass-half"></i>
                        Waiting on: <strong>{{ state.waiting_on }}</strong>
                    </span>
                </div>

                <p class="psl-meaning">{{ state.meaning }}</p>

                <dl class="psl-rows">
                    <div class="psl-row">
                        <dt><i class="fas fa-list-check"></i> Office does</dt>
                        <dd>{{ state.next }}</dd>
                    </div>
                    <div class="psl-row">
                        <dt><i class="fas fa-helmet-safety"></i> Tell the technician</dt>
                        <dd>{{ state.tell_technician }}</dd>
                    </div>
                    <div class="psl-row">
                        <dt><i class="fas fa-user-tie"></i> Tell the client</dt>
                        <dd>{{ state.tell_client }}</dd>
                    </div>
                </dl>
            </li>
        </ol>
    </details>
</template>

<script setup>
const props = defineProps({
    // ProgressReport::pipelineStateGuide(), passed through by the page.
    states: { type: Array, default: () => [] },
    open: { type: Boolean, default: false },
})
</script>

<style scoped>
.psl {
    margin: 0.75rem 0 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
}
.psl > summary {
    cursor: pointer;
    padding: 0.7rem 0.95rem;
    font-weight: 600;
    font-size: 0.88rem;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.psl > summary i { color: #2563eb; }
.psl-hint {
    font-weight: 400;
    font-size: 0.78rem;
    color: #64748b;
}
.psl-empty { padding: 0 0.95rem 0.8rem; font-size: 0.82rem; color: #64748b; }

.psl-list {
    list-style: none;
    margin: 0;
    padding: 0 0.95rem 0.95rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.psl-item {
    border-top: 1px solid #f1f5f9;
    padding-top: 0.8rem;
}
.psl-item:first-child { border-top: 0; padding-top: 0; }

.psl-head {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.psl-waiting { font-size: 0.78rem; color: #64748b; }
.psl-waiting strong { color: #334155; }
.psl-waiting i { margin-right: 0.25rem; }

.psl-meaning {
    margin: 0.45rem 0 0;
    font-size: 0.85rem;
    line-height: 1.55;
    color: #334155;
}

.psl-rows {
    margin: 0.55rem 0 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}
.psl-row {
    display: grid;
    grid-template-columns: 11rem 1fr;
    gap: 0.6rem;
    align-items: start;
}
.psl-row dt {
    font-size: 0.76rem;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}
.psl-row dt i { color: #94a3b8; margin-right: 0.3rem; }
.psl-row dd {
    margin: 0;
    font-size: 0.83rem;
    line-height: 1.5;
    color: #475569;
}

/* The two-column rows collapse before the text gets squeezed to a ribbon. */
@media (max-width: 640px) {
    .psl-row { grid-template-columns: 1fr; gap: 0.15rem; }
}
</style>
