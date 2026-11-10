<template>
    <div class="crew-picker" @keydown="onKeydown">
        <!-- Who is already picked. Removable one at a time, and with
             Backspace on an empty search, the way a tag field behaves. -->
        <div v-if="selected.length" class="crew-picker-chips" aria-label="Selected">
            <span v-for="person in selected" :key="person.id" class="crew-picker-chip">
                {{ person.user?.name || 'Unnamed' }}
                <small v-if="person.kind === 'gang_member'">gang</small>
                <button type="button" @click="toggle(person)" :aria-label="`Remove ${person.user?.name}`">
                    <i class="fas fa-times"></i>
                </button>
            </span>
            <button v-if="selected.length > 1" type="button" class="crew-picker-clear-all" @click="emit('update:modelValue', [])">
                Clear all
            </button>
        </div>

        <!-- Same search shell as Jobs and RFQ Management. The list is already
             on the page, so this filters on every keystroke with no debounce:
             those two wait for a server round-trip, this has nothing to wait for. -->
        <label class="crew-picker-search">
            <i class="fas fa-search crew-picker-search-icon"></i>
            <input
                ref="input"
                v-model="query"
                type="text"
                role="combobox"
                aria-autocomplete="list"
                :aria-expanded="true"
                :aria-controls="listId"
                :aria-activedescendant="active >= 0 ? optionId(active) : undefined"
                placeholder="Search by name, phone, ID number or technician no.…"
                autocomplete="off"
                @input="active = matches.length ? 0 : -1"
            >
            <button v-if="query" type="button" class="crew-picker-search-clear" @click="clearQuery" aria-label="Clear search" title="Clear search">
                <i class="fas fa-times"></i>
            </button>
        </label>

        <div class="crew-picker-kinds" role="group" aria-label="Show">
            <button
                v-for="k in kindTabs"
                :key="k.value"
                type="button"
                :class="{ active: kind === k.value }"
                @click="setKind(k.value)"
            >
                {{ k.label }} <span>{{ k.count }}</span>
            </button>
        </div>

        <ul :id="listId" ref="list" class="crew-picker-list" role="listbox" aria-multiselectable="true">
            <li
                v-for="(person, index) in matches"
                :id="optionId(index)"
                :key="person.id"
                role="option"
                :aria-selected="isSelected(person)"
                :class="{ 'is-active': index === active, 'is-selected': isSelected(person) }"
                @mousedown.prevent
                @mousemove="active = index"
                @click="toggle(person)"
            >
                <span class="crew-picker-check"><i v-if="isSelected(person)" class="fas fa-check"></i></span>
                <span class="crew-picker-main">
                    <span class="crew-picker-name">
                        <span><template v-for="(part, i) in highlight(person.user?.name || 'Unnamed')" :key="i"><mark v-if="part.hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                        <span v-if="person.kind === 'gang_member'" class="crew-picker-badge">Gang member</span>
                        <span v-else-if="person.specialization || person.trade" class="crew-picker-trade">
                            {{ person.specialization || person.trade }}
                        </span>
                    </span>
                    <span class="crew-picker-meta">
                        <span v-if="person.technician_id">#{{ person.technician_id }}</span>
                        <span v-if="person.user?.phone"><i class="fas fa-phone"></i> {{ person.user.phone }}</span>
                        <span v-if="person.national_id">ID {{ person.national_id }}</span>
                        <span v-else class="crew-picker-missing">No ID on file</span>
                    </span>
                </span>
            </li>

            <li v-if="!options.length" class="crew-picker-empty" role="presentation">
                <i class="fas fa-users-slash"></i>
                <strong>Everyone on the books is already on this job.</strong>
                <a href="/admin/gang-members">Add a new gang member</a>
            </li>
            <li v-else-if="!matches.length" class="crew-picker-empty" role="presentation">
                <i class="fas fa-magnifying-glass"></i>
                <strong v-if="query">Nobody matches “{{ query }}”<template v-if="kind !== 'all'"> among {{ kindLabel }}</template>.</strong>
                <strong v-else>No {{ kindLabel }} left to add.</strong>
                <span>
                    <button v-if="query" type="button" @click="clearQuery">Clear search</button>
                    <button v-if="kind !== 'all'" type="button" @click="setKind('all')">Show everyone</button>
                    <a href="/admin/gang-members">Add a new gang member</a>
                </span>
            </li>
        </ul>

        <small class="crew-picker-hint">
            ↑ ↓ to move, Enter to pick or unpick, Esc to clear the search. Pick as many as you need.
        </small>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'

const props = defineProps({
    // Everyone who can still be added. Already filtered by the page.
    options: { type: Array, default: () => [] },
    // Selected technician ids.
    modelValue: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const listId = `crew-picker-${Math.random().toString(36).slice(2, 8)}`
const optionId = (i) => `${listId}-opt-${i}`

const input = ref(null)
const list = ref(null)
const query = ref('')
const kind = ref('all')
const active = ref(0)

onMounted(() => nextTick(() => input.value?.focus()))

const selectedIds = computed(() => new Set(props.modelValue.map(String)))
const isSelected = (person) => selectedIds.value.has(String(person.id))

// Kept in the order they were picked, so the chips read the way ops chose them.
const selected = computed(() => props.modelValue
    .map(id => props.options.find(o => String(o.id) === String(id)))
    .filter(Boolean))

const toggle = (person) => {
    const id = person.id
    const next = isSelected(person)
        ? props.modelValue.filter(v => String(v) !== String(id))
        : [...props.modelValue, id]
    emit('update:modelValue', next)
}

/* ---- matching ----------------------------------------------------------
 * Every word typed has to appear somewhere in the person's record —
 * case-insensitive, partial — so "jo 0722" finds John on 0722 123 456.
 * Phone numbers are compared as digits only, and a leading 0 and a leading
 * 254 are treated as the same number, because ops type both.
 */
const digits = (v) => String(v || '').replace(/\D/g, '')
const localPhone = (v) => {
    const d = digits(v)
    if (d.startsWith('254')) return d.slice(3)
    if (d.startsWith('0')) return d.slice(1)
    return d
}

const haystack = (p) => [
    p.user?.name, p.user?.email, p.technician_id, p.national_id,
    p.specialization, p.trade, p.location,
].filter(Boolean).map(v => String(v).toLowerCase())

const tokenMatches = (p, token) => {
    if (haystack(p).some(field => field.includes(token))) return true
    const t = digits(token)
    if (t.length < 3 || t.length !== token.replace(/[\s+\-()]/g, '').length) return false
    const phone = digits(p.user?.phone)
    return phone.includes(t) || localPhone(p.user?.phone).includes(localPhone(t))
}

const tokens = computed(() => query.value.trim().toLowerCase().split(/\s+/).filter(Boolean))

const ofKind = computed(() => props.options.filter(p =>
    kind.value === 'all'
    || (kind.value === 'gang' ? p.kind === 'gang_member' : p.kind !== 'gang_member')))

const matches = computed(() => {
    if (!tokens.value.length) return ofKind.value
    const first = tokens.value[0]
    return ofKind.value
        .filter(p => tokens.value.every(t => tokenMatches(p, t)))
        // Names that start with what was typed come first; otherwise the
        // page's own order (best rated first) is kept.
        .map((p, i) => ({ p, i, rank: (p.user?.name || '').toLowerCase().split(/\s+/).some(w => w.startsWith(first)) ? 0 : 1 }))
        .sort((a, b) => a.rank - b.rank || a.i - b.i)
        .map(x => x.p)
})

const gangCount = computed(() => props.options.filter(p => p.kind === 'gang_member').length)
const kindTabs = computed(() => [
    { value: 'all', label: 'Everyone', count: props.options.length },
    { value: 'gang', label: 'Gang members', count: gangCount.value },
    { value: 'tech', label: 'Technicians', count: props.options.length - gangCount.value },
])
const kindLabel = computed(() => ({ all: 'people', gang: 'gang members', tech: 'technicians' })[kind.value])

const setKind = (value) => {
    kind.value = value
    active.value = 0
    input.value?.focus()
}

const clearQuery = () => {
    query.value = ''
    active.value = 0
    input.value?.focus()
}

const highlight = (text) => {
    const terms = tokens.value.filter(t => t.length)
    if (!terms.length) return [{ text, hit: false }]
    const escaped = terms.map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    return text.split(new RegExp(`(${escaped.join('|')})`, 'gi'))
        .filter(Boolean)
        .map(part => ({ text: part, hit: terms.includes(part.toLowerCase()) }))
}

/* ---- keyboard ---------------------------------------------------------- */
const scrollActiveIntoView = () => nextTick(() => {
    list.value?.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' })
})

const onKeydown = (e) => {
    const n = matches.value.length
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        if (n) active.value = (active.value + 1) % n
        scrollActiveIntoView()
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        if (n) active.value = (active.value - 1 + n) % n
        scrollActiveIntoView()
    } else if (e.key === 'Enter') {
        if (e.target !== input.value) return
        e.preventDefault()
        if (n && active.value >= 0) toggle(matches.value[active.value])
    } else if (e.key === 'Escape') {
        if (query.value) {
            e.preventDefault()
            e.stopPropagation()
            clearQuery()
        }
    } else if (e.key === 'Backspace' && e.target === input.value && !query.value && props.modelValue.length) {
        emit('update:modelValue', props.modelValue.slice(0, -1))
    }
}

// The list can shrink under the cursor (somebody added, filter changed).
watch(matches, (m) => {
    if (active.value >= m.length) active.value = m.length ? m.length - 1 : -1
    if (active.value < 0 && m.length) active.value = 0
})

defineExpose({ focus: () => input.value?.focus() })
</script>

<style scoped>
.crew-picker { display: flex; flex-direction: column; gap: 0.5rem; }

.crew-picker-chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.crew-picker-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 3px 4px 3px 10px; border-radius: 999px;
    background: #e0e7ff; color: #3730a3; font-size: 0.82rem; font-weight: 600;
    white-space: nowrap; line-height: 1.3;
}
.crew-picker-chip small { font-weight: 500; opacity: 0.75; }
.crew-picker-chip button {
    border: 0; background: rgba(55, 48, 163, 0.12); color: inherit; cursor: pointer;
    width: 20px; height: 20px; min-width: 0; padding: 0; margin: 0; flex-shrink: 0; line-height: 1;
    border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.7rem; box-shadow: none;
}
.crew-picker-chip button:hover { background: rgba(55, 48, 163, 0.25); }
.crew-picker-clear-all { border: 0; background: none; padding: 0; margin: 0; box-shadow: none; min-width: 0; color: #64748b; font-size: 0.8rem; cursor: pointer; text-decoration: underline; }

/* Matches .jobs-search-shell / .search-shell on Jobs and RFQ Management. */
.crew-picker-search {
    display: flex; align-items: center; gap: 0.6rem;
    border: 1px solid #cbd5e1; border-radius: 999px; padding: 0 0.5rem 0 1rem;
    background: #fff; min-height: 42px; margin: 0;
}
.crew-picker-search:focus-within { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
.crew-picker-search-icon { color: #94a3b8; flex-shrink: 0; }
.crew-picker-search input {
    flex: 1; min-width: 0; border: 0; outline: 0; background: transparent;
    font-size: 0.92rem; color: #0f172a; padding: 0.55rem 0;
}
.crew-picker-search input::placeholder { color: #94a3b8; }
.crew-picker-search-clear {
    border: 0; background: rgba(148, 163, 184, 0.2); color: #475569; cursor: pointer;
    width: 26px; height: 26px; min-width: 0; padding: 0; margin: 0; line-height: 1;
    border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0; box-shadow: none;
}
.crew-picker-search-clear:hover { background: rgba(148, 163, 184, 0.35); color: #0f172a; }

.crew-picker-kinds { display: flex; gap: 6px; flex-wrap: wrap; }
.crew-picker-kinds button {
    border: 1px solid #e2e8f0; background: #f8fafc; color: #475569; border-radius: 999px;
    padding: 3px 10px; font-size: 0.78rem; cursor: pointer;
}
.crew-picker-kinds button span { color: #94a3b8; margin-left: 2px; }
.crew-picker-kinds button.active { background: #0f172a; border-color: #0f172a; color: #fff; }
.crew-picker-kinds button.active span { color: #cbd5e1; }

.crew-picker-list {
    list-style: none; margin: 0; padding: 4px; max-height: 280px; overflow-y: auto;
    border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;
}
.crew-picker-list li[role="option"] {
    display: flex; gap: 10px; align-items: flex-start; padding: 8px 10px; border-radius: 8px; cursor: pointer;
}
.crew-picker-list li.is-active { background: #f1f5f9; }
.crew-picker-list li.is-selected { background: #eef2ff; }
.crew-picker-list li.is-selected.is-active { background: #e0e7ff; }
.crew-picker-check {
    width: 18px; height: 18px; flex-shrink: 0; margin-top: 2px; border-radius: 4px;
    border: 1.5px solid #cbd5e1; display: inline-flex; align-items: center; justify-content: center;
    font-size: 0.65rem; color: #fff; background: #fff;
}
li.is-selected .crew-picker-check { background: #4f46e5; border-color: #4f46e5; }
.crew-picker-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.crew-picker-name { font-weight: 600; color: #0f172a; font-size: 0.9rem; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
.crew-picker-name mark { background: #fef08a; color: inherit; padding: 0; border-radius: 2px; }
.crew-picker-badge { font-size: 0.68rem; font-weight: 600; padding: 1px 7px; border-radius: 999px; background: #fef3c7; color: #92400e; }
.crew-picker-trade { font-size: 0.75rem; font-weight: 500; color: #64748b; }
.crew-picker-meta { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 0.76rem; color: #64748b; }
.crew-picker-meta i { font-size: 0.65rem; }
.crew-picker-missing { color: #b45309; }

.crew-picker-empty {
    display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center;
    padding: 1.25rem 1rem; color: #64748b; font-size: 0.85rem;
}
.crew-picker-empty i { font-size: 1.2rem; color: #94a3b8; }
.crew-picker-empty strong { color: #334155; font-weight: 600; }
.crew-picker-empty span { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; }
.crew-picker-empty button, .crew-picker-empty a {
    border: 0; background: none; color: #2563eb; cursor: pointer; font-size: 0.82rem; padding: 0; text-decoration: underline;
}
.crew-picker-hint { font-size: 0.74rem; color: #94a3b8; }
</style>
