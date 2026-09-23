<script setup>
import { computed } from 'vue';
import { useParagraphStyle } from '@docx-editor.dev/vue';
import { curatedStyleOptions } from './curated-styles.js';

/** H1–H4 quick buttons, appended to the formatting toolbar. */
defineOptions({ name: 'WebconHeadingButtons' });

defineProps({
  labels: { type: Object, required: true },
});

const style = useParagraphStyle();

const headings = computed(() =>
  curatedStyleOptions(style.options.value.map((option) => ({ ...option, type: 'paragraph' }))).filter(
    (option) => option.role !== 'normal',
  ),
);
</script>

<template>
  <div class="webcon-docx-headings" role="group" :aria-label="labels.headingsGroup">
    <button
      v-for="heading in headings"
      :key="heading.role"
      type="button"
      :class="['docx-toolbar__button', 'webcon-docx-headings__button', `webcon-docx-headings__button--${heading.role}`]"
      :data-active="style.value.value === heading.styleId ? '' : undefined"
      :aria-pressed="style.value.value === heading.styleId"
      :title="labels[heading.role]"
      :aria-label="labels[heading.role]"
      :disabled="!style.isEnabled.value"
      @mousedown.prevent
      @click="style.setValue(heading.styleId)"
    >
      {{ labels[`${heading.role}Short`] }}
    </button>
  </div>
</template>
