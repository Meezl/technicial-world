<template>
    <div class="search-select" :class="{ open, 'align-right': alignRight }" @keydown="onKeydown">
        <div class="search-select-shell" @mousedown="onShellMousedown">
            <i class="fas fa-search search-select-icon"></i>
            <input
                ref="input"
                v-model="query"
                type="text"
                role="combobox"
                autocomplete="off"
                aria-autocomplete="list"
                :aria-expanded="open"
                :aria-controls="listId"
                :aria-activedescendant="open && active >= 0 ? `${listId}-${active}` : undefined"
                :placeholder="selected ? selected.label : placeholder"
                :class="{ 'has-value': selected && !open }"
                @focus="openList"
                @blur="closeList"
                @input="onInput"
            >
            <button
                v-if="selected || query"
                type="button"
                class="search-select-clear"
                aria-label="Clear"
                title="Clear"
                @mousedown.prevent
                @click="clear"
            >
                <i class="fas fa-times"></i>
            </button>
        </div>

        <ul v-if="open" :id="listId" ref="list" class="search-select-list" role="listbox">
            <li
                v-for="(opt, index) in visible"
                :id="`${listId}-${index}`"
                :key="opt.value"
                role="option"
                :aria-selected="String(opt.value) === String(modelValue)"
                :class="{ 'is-active': index === active, 'is-selected': String(opt.value) === String(modelValue) }"
                @mousedown.prevent
                @mousemove="active = index"
                @click="pick(opt)"
            >
                <span class="search-select-label">
                    <template v-for="(part, i) in highlight(opt.label)" :key="i"><mark v-if="part.hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
                </span>
                <span v-if="opt.sublabel" class="search-select-sub">{{ opt.sublabel }}</span>
            </li>
            <li v-if="!visible.length" class="search-select-empty" role="presentation">
                <template v-if="query">Nothing matches “{{ query }}”.</template>
                <template v-else>Nothing to choose from.</template>
            </li>
            <li v-else-if="matches.length > visible.length" class="search-select-more" role="presentation">
                Showing {{ visible.length }} of {{ matches.length }} — keep typing to narrow it down.
            </li>
        </ul>
    </div>
</template>

<script setup>
/**
 * A single-choice dropdown you can type into. Matching follows the Jobs and
 * RFQ Management searches: partial and case-insensitive, every word typed has
 * to appear somewhere in the option (label, sublabel or keywords).
 */
import { ref, computed, nextTick } from 'vue'

const props = defineProps({
    // [{ value, label, sublabel?, keywords? }]
    options: { type: Array, default: () => [] },
    modelValue: { type: [String, Number], default: '' },
    placeholder: { type: String, default: 'Type to search…' },
    limit: { type: Number, default: 60 },
    // Open the list leftwards, for a picker at the right edge of its card.
    alignRight: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const listId = `search-select-${Math.random().toString(36).slice(2, 8)}`
const input = ref(null)
const list = ref(null)
const query = ref('')
const open = ref(false)
const active = ref(0)

const selected = computed(() =>
    props.modelValue === '' || props.modelValue == null
        ? null
        : props.options.find(o => String(o.value) === String(props.modelValue)) || null
)

const tokens = computed(() => query.value.trim().toLowerCase().split(/\s+/).filter(Boolean))

const matches = computed(() => {
    if (!tokens.value.length) return props.options
    return props.options.filter(o => {
        const hay = [o.label, o.sublabel, o.keywords].filter(Boolean).join(' ').toLowerCase()
        return tokens.value.every(t => hay.includes(t))
    })
})
const visible = computed(() => matches.value.slice(0, props.limit))

const highlight = (text) => {
    const terms = tokens.value
    if (!terms.length || !text) return [{ text, hit: false }]
    const escaped = terms.map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    return String(text).split(new RegExp(`(${escaped.join('|')})`, 'gi'))
        .filter(Boolean)
        .map(part => ({ text: part, hit: terms.includes(part.toLowerCase()) }))
}

const openList = () => {
    open.value = true
    const i = visible.value.findIndex(o => String(o.value) === String(props.modelValue))
    active.value = i >= 0 ? i : 0
    scrollActive()
}
const closeList = () => {
    open.value = false
    query.value = ''
}
const onShellMousedown = (e) => {
    if (e.target !== input.value) {
        e.preventDefault()
        input.value?.focus()
    }
}
const onInput = () => {
    open.value = true
    active.value = 0
}

const pick = (opt) => {
    emit('update:modelValue', opt.value)
    query.value = ''
    open.value = false
    input.value?.blur()
}
const clear = () => {
    query.value = ''
    if (props.modelValue !== '') emit('update:modelValue', '')
    open.value = false
    input.value?.blur()
}

const scrollActive = () => nextTick(() => list.value?.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' }))

const onKeydown = (e) => {
    const n = visible.value.length
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        if (!open.value) return openList()
        if (n) active.value = (active.value + 1) % n
        scrollActive()
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        if (n) active.value = (active.value - 1 + n) % n
        scrollActive()
    } else if (e.key === 'Enter') {
        if (!open.value) return
        e.preventDefault()
        if (n && active.value >= 0) pick(visible.value[active.value])
    } else if (e.key === 'Escape') {
        if (query.value) { query.value = ''; active.value = 0 } else { input.value?.blur() }
    }
}
</script>

<style scoped>
.search-select { position: relative; }
.search-select-shell {
    display: flex; align-items: center; gap: 0.5rem;
    border: 1px solid #cbd5e1; border-radius: 12px; padding: 0 0.4rem 0 0.8rem;
    background: #fff; min-height: 42px; cursor: text;
}
.search-select.open .search-select-shell,
.search-select-shell:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.search-select-icon { color: #94a3b8; font-size: 0.85rem; flex-shrink: 0; }
.search-select-shell input {
    flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; box-shadow: none;
    font-size: 0.9rem; color: #0f172a; padding: 0.5rem 0; margin: 0;
}
.search-select-shell input::placeholder { color: #94a3b8; }
.search-select-shell input.has-value::placeholder { color: #0f172a; font-weight: 600; }
.search-select-clear {
    border: 0; background: rgba(148, 163, 184, 0.2); color: #475569; cursor: pointer;
    width: 24px; height: 24px; min-width: 0; padding: 0; margin: 0; line-height: 1; box-shadow: none;
    border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
    font-size: 0.7rem;
}
.search-select-clear:hover { background: rgba(148, 163, 184, 0.35); color: #0f172a; }

.search-select-list {
    position: absolute; z-index: 40; left: 0; top: calc(100% + 4px);
    width: max(100%, min(340px, calc(100vw - 32px)));
    list-style: none; margin: 0; padding: 4px; max-height: 300px; overflow-y: auto;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
    box-shadow: 0 12px 32px rgba(15, 23, 42, 0.14);
}
.search-select.align-right .search-select-list { left: auto; right: 0; }
.search-select-list li[role="option"] {
    display: flex; flex-direction: column; gap: 1px; padding: 7px 10px; border-radius: 8px; cursor: pointer;
}
.search-select-list li.is-active { background: #f1f5f9; }
.search-select-list li.is-selected { background: #eef2ff; }
.search-select-label { font-size: 0.88rem; font-weight: 600; color: #0f172a; }
.search-select-label mark { background: #fef08a; color: inherit; padding: 0; border-radius: 2px; }
.search-select-sub { font-size: 0.75rem; color: #64748b; }
.search-select-empty, .search-select-more { padding: 10px; font-size: 0.8rem; color: #64748b; text-align: center; }
.search-select-more { border-top: 1px solid #f1f5f9; }
</style>
