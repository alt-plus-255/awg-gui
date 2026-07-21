<template>
  <div class="tag-list-input">
    <div class="text-caption text-grey-5 q-mb-xs">{{ label }}</div>

    <div v-if="!modelValue.length" class="text-caption text-grey-6 q-mb-sm">
      {{ emptyHint || t('common.tagListEmpty') }}
    </div>

    <div v-else class="chips-wrap q-mb-sm">
      <q-chip
        v-for="item in modelValue"
        :key="item"
        dense
        outline

        removable
        color="primary"
        class="mono-chip"
        @remove="remove(item)"
      >
        {{ item }}
      </q-chip>
    </div>

    <div class="row q-gutter-sm items-start no-wrap">
      <q-input
        v-model="draft"
        dense
        filled

        class="col"
        :placeholder="placeholder"
        :hint="hint"
        :error="!!error"
        :error-message="error"
        @keydown.enter.prevent="addFromDraft"
        @paste="onPaste"
      />
      <q-btn
        color="primary"
        icon="add"
        :label="t('common.tagAdd')"
        class="q-mt-xs"
        :disable="!draft.trim()"
        @click="addFromDraft"
      />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuasar } from 'quasar'

const props = defineProps({
  modelValue: {
    type: Array,
    default: () => []
  },
  label: {
    type: String,
    default: ''
  },
  placeholder: {
    type: String,
    default: ''
  },
  hint: {
    type: String,
    default: ''
  },
  emptyHint: {
    type: String,
    default: ''
  },
  /** (raw: string) => string | null — normalized value or null if empty */
  normalize: {
    type: Function,
    default: (raw) => {
      const v = String(raw || '').trim()
      return v || null
    }
  },
  /** (normalized: string) => string | null — error message or null if ok */
  validate: {
    type: Function,
    default: () => null
  }
})

const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()
const $q = useQuasar()
const draft = ref('')
const error = ref('')

function splitTokens (text) {
  return String(text || '')
    .split(/[\s,;]+/)
    .map(s => s.trim())
    .filter(s => s && !s.startsWith('//'))
}

function prepare (raw) {
  const normalized = props.normalize(raw)
  if (!normalized) {
    return { ok: false, reason: 'empty' }
  }
  const err = props.validate(normalized)
  if (err) {
    return { ok: false, reason: 'invalid', message: err, value: normalized }
  }
  return { ok: true, value: normalized }
}

function addMany (tokens, { notify = true } = {}) {
  const next = [...props.modelValue]
  let added = 0
  const invalid = []
  let dups = 0

  for (const token of tokens) {
    const result = prepare(token)
    if (!result.ok) {
      if (result.reason === 'invalid') invalid.push(token)
      continue
    }
    if (next.includes(result.value)) {
      dups += 1
      continue
    }
    next.push(result.value)
    added += 1
  }

  if (added) {
    emit('update:modelValue', next)
  }

  if (!notify) {
    return { added, invalid, dups }
  }

  if (invalid.length) {
    $q.notify({
      type: 'negative',
      message: t('common.tagInvalid', {
        invalid: `${invalid.slice(0, 5).join(', ')}${invalid.length > 5 ? '…' : ''}`
      })
    })
  } else if (added) {
    $q.notify({
      type: 'positive',
      message: dups
        ? t('common.tagAddedWithDups', { added, dups })
        : t('common.tagAdded', { added }),
      timeout: 2500
    })
  } else if (dups) {
    $q.notify({ type: 'info', message: t('common.tagAllExist'), timeout: 2000 })
  }

  return { added, invalid, dups }
}

function addFromDraft () {
  error.value = ''
  const raw = draft.value
  if (!raw.trim()) return

  const tokens = splitTokens(raw)
  if (tokens.length > 1) {
    addMany(tokens)
    draft.value = ''
    return
  }

  const result = prepare(raw)
  if (!result.ok) {
    if (result.reason === 'invalid') error.value = result.message
    return
  }
  if (props.modelValue.includes(result.value)) {
    error.value = t('common.tagAlreadyExists', { value: result.value })
    return
  }
  emit('update:modelValue', [...props.modelValue, result.value])
  draft.value = ''
}

function onPaste (e) {
  const text = e.clipboardData?.getData('text') || ''
  const tokens = splitTokens(text)
  if (tokens.length <= 1) return
  e.preventDefault()
  error.value = ''
  addMany(tokens)
  draft.value = ''
}

function remove (item) {
  emit('update:modelValue', props.modelValue.filter(x => x !== item))
}
</script>

<style scoped>
.chips-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.mono-chip {
  font-family: var(--theme-mono);
  font-size: 12px;
}
</style>
