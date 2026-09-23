<script setup>
import { computed } from 'vue';
import { LocaleProvider } from '@docx-editor.dev/vue';
import EditorShell from './EditorShell.vue';
import { editorCatalog } from './i18n.js';

/**
 * Root of the Vue tree: selects the chrome catalogue (TYPO3 backend language)
 * and renders the composed editor. Mounted by mount.js only.
 */
const props = defineProps({
  document: { type: Uint8Array, default: undefined },
  locale: { type: String, default: 'en' },
  readOnly: { type: Boolean, default: false },
  contentControls: { type: String, default: 'default' },
  labels: { type: Object, required: true },
});

const emit = defineEmits(['ready', 'change', 'save', 'font-error']);

const catalog = computed(() => editorCatalog(props.locale));
</script>

<template>
  <LocaleProvider :i18n="catalog">
    <EditorShell
      :document="document"
      :locale="locale"
      :read-only="readOnly"
      :content-controls="contentControls"
      :labels="labels"
      @ready="emit('ready', $event)"
      @change="emit('change', $event)"
      @save="emit('save')"
      @font-error="emit('font-error', $event)"
    />
  </LocaleProvider>
</template>
