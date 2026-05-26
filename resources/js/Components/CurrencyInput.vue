<template>
    <input
        type="text"
        inputmode="decimal"
        class="form-control"
        :class="$attrs.class"
        :value="display"
        :placeholder="placeholder"
        :required="required"
        :disabled="disabled"
        @input="onInput"
        @blur="onBlur"
        @focus="onFocus"
    />
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    placeholder: { type: String, default: '0.00' },
    required: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    /** Decimal precision shown on blur. 2 = currency-style. */
    decimals: { type: Number, default: 2 },
})

const emit = defineEmits(['update:modelValue'])

const isFocused = ref(false)
const display = ref('')

function formatForBlur(value) {
    if (value === null || value === '' || value === undefined) return ''
    const n = Number(value)
    if (Number.isNaN(n)) return ''
    return n.toLocaleString('en-KE', {
        minimumFractionDigits: props.decimals,
        maximumFractionDigits: props.decimals,
    })
}

function formatForFocus(value) {
    if (value === null || value === '' || value === undefined) return ''
    const n = Number(value)
    if (Number.isNaN(n)) return ''
    // Keep commas while editing so users see structure as they type, but
    // strip trailing forced zeros.
    return n.toLocaleString('en-KE', {
        maximumFractionDigits: props.decimals,
    })
}

function syncFromModel(value) {
    if (isFocused.value) {
        display.value = formatForFocus(value)
    } else {
        display.value = formatForBlur(value)
    }
}

watch(() => props.modelValue, syncFromModel, { immediate: true })

function parse(raw) {
    if (raw === null || raw === undefined) return null
    const cleaned = String(raw).replace(/[^0-9.]/g, '')
    if (cleaned === '' || cleaned === '.') return null
    const n = Number(cleaned)
    return Number.isNaN(n) ? null : n
}

function onInput(event) {
    const raw = event.target.value
    // Strip non-numeric except decimal
    const cleaned = raw.replace(/[^0-9.]/g, '')
    // Only allow one decimal point
    const parts = cleaned.split('.')
    const normalized = parts.length > 1
        ? `${parts[0]}.${parts.slice(1).join('').slice(0, props.decimals)}`
        : parts[0]

    const numeric = parse(normalized)

    // Reformat with thousands separators while preserving any trailing decimal-in-progress
    const [intPart, decPart] = normalized.split('.')
    const intFormatted = intPart === '' ? '' : Number(intPart).toLocaleString('en-KE')
    display.value = decPart !== undefined ? `${intFormatted}.${decPart}` : intFormatted

    emit('update:modelValue', numeric)
}

function onFocus() {
    isFocused.value = true
    display.value = formatForFocus(props.modelValue)
}

function onBlur() {
    isFocused.value = false
    display.value = formatForBlur(props.modelValue)
}
</script>
