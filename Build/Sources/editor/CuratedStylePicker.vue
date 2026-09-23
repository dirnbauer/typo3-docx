<script setup>
import { computed } from 'vue';
import { DocxEditorToolbar, useParagraphStyle } from '@docx-editor.dev/vue';
import { curatedStyleOptions } from './curated-styles.js';

/**
 * The toolbar's style picker, curated to Normal + Heading 1–4 with the
 * TYPO3 labels. Replaces the `styles.style` slot of the packaged toolbar.
 */
defineOptions({ name: 'WebconCuratedStylePicker', docxSlot: 'styles.style' });

const props = defineProps({
  labels: { type: Object, required: true },
});

const StylePicker = DocxEditorToolbar.StylePicker;
const StylePickerTrigger = DocxEditorToolbar.StylePicker.Trigger;
const StylePickerContent = DocxEditorToolbar.StylePicker.Content;
const StylePickerItem = DocxEditorToolbar.StylePicker.Item;

const style = useParagraphStyle();

const options = computed(() =>
  curatedStyleOptions(style.options.value.map((option) => ({ ...option, type: 'paragraph' }))).map((curated) => ({
    ...curated,
    preview: style.options.value.find((option) => option.styleId === curated.styleId)?.preview ?? null,
  })),
);

const current = computed(() => {
  const styleId = style.value.value;
  if (styleId === null) {
    return '—';
  }
  const curated = options.value.find((option) => option.styleId === styleId);
  if (curated) {
    return props.labels[curated.role];
  }
  return style.options.value.find((option) => option.styleId === styleId)?.name ?? styleId;
});

/**
 * The entry hints at the style it applies — size, weight, slant — within
 * picker bounds. Family and colour stay the backend's: document fonts are
 * registered privately to the page canvas, and a document colour can vanish
 * on the dark scheme.
 */
function previewStyle(preview) {
  if (!preview) {
    return undefined;
  }
  return {
    ...(preview.fontSizePt ? { fontSize: `${Math.min(Math.max(preview.fontSizePt, 11), 18)}px` } : {}),
    ...(preview.bold ? { fontWeight: 600 } : {}),
    ...(preview.italic ? { fontStyle: 'italic' } : {}),
  };
}
</script>

<template>
  <StylePicker class="webcon-docx-style-picker">
    <StylePickerTrigger>
      <span>{{ current }}</span>
    </StylePickerTrigger>
    <StylePickerContent>
      <StylePickerItem v-for="option in options" :key="option.styleId" :value="option.styleId">
        <span :style="previewStyle(option.preview)">{{ labels[option.role] }}</span>
      </StylePickerItem>
    </StylePickerContent>
  </StylePicker>
</template>
