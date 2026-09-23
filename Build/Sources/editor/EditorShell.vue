<script setup>
import { computed, h, provide } from 'vue';
import {
  DocxEditorContent,
  DocxEditorContentControl,
  DocxEditorContextMenu,
  DocxEditorEquation,
  DocxEditorFontNotice,
  DocxEditorHeaderFooterChrome,
  DocxEditorHorizontalRuler,
  DocxEditorHyperLink,
  DocxEditorLoading,
  DocxEditorMenu,
  DocxEditorNavigation,
  DocxEditorNotesChrome,
  DocxEditorPageNumber,
  DocxEditorRoot,
  DocxEditorToolbar,
  DocxEditorVerticalRuler,
  DocxEditorViewport,
  PageNumberTranslationContext,
  useTranslation,
} from '@docx-editor.dev/vue';
import { packagedFonts } from '@docx-editor.dev/fonts';
import ContentControlMode from './ContentControlMode.js';
import CuratedStylePicker from './CuratedStylePicker.vue';
import HeadingButtons from './HeadingButtons.vue';
import { engineLocale } from './i18n.js';

/**
 * The composed editor: File/Format/Insert/Review menus without File › Open
 * and without the export converters (they need services this extension does
 * not run), the formatting toolbar with the curated style picker and the
 * H1–H4 buttons, rulers, navigation and the packaged popups. Mirrors what
 * the packaged <DocxEditor> renders; composition is what allows the
 * overrides.
 *
 * Review keeps the rows the open-source engine drives (paragraph marks,
 * forms protection); tracked-change navigation, accept/reject, markup views
 * and comments need the commercial @docx-editor.dev/pro review module, which
 * this extension does not ship. Such content in a document is kept on save.
 */
const props = defineProps({
  document: { type: Uint8Array, default: undefined },
  locale: { type: String, default: 'en' },
  readOnly: { type: Boolean, default: false },
  contentControls: { type: String, default: 'default' },
  labels: { type: Object, required: true },
});

const emit = defineEmits(['ready', 'change', 'save', 'font-error']);

// Fonts come from @docx-editor.dev/fonts, bundled into Resources/Public/Vite
// and fetched same-origin only when a document names the family.
const fonts = packagedFonts();

const translation = useTranslation();
const t = (key, params) => translation.t(key, params);
provide(PageNumberTranslationContext, (key) => t(key));

const showContentControls = computed(() => props.contentControls === 'show');

const popups = computed(() => ({
  hyperlink: (popupProps) => h(DocxEditorHyperLink, popupProps),
  contentControl: (popupProps) =>
    showContentControls.value
      ? h(DocxEditorContentControl, { ...popupProps, preset: false }, {
          default: () => [h(DocxEditorContentControl.Header), h(DocxEditorContentControl.Fields)],
        })
      : h(DocxEditorContentControl, popupProps),
  equation: () => h(DocxEditorEquation),
  contextMenu: (popupProps) => h(DocxEditorContextMenu, { ...popupProps, t }),
}));

const tableInteractionLabel = (key) => t(key);

const requestSave = () => emit('save');
const ignoreOpen = () => {};

const MenuFile = DocxEditorMenu.File;
const MenuGeneric = DocxEditorMenu.Menu;
const MenuItem = DocxEditorMenu.Item;
const MenuSave = DocxEditorMenu.Save;
const MenuSeparator = DocxEditorMenu.Separator;
const MenuPageSetup = DocxEditorMenu.PageSetup;
const ToolbarComments = DocxEditorToolbar.Comments;
const ToolbarEditingMode = DocxEditorToolbar.EditingMode;
const ToolbarContentControlRemove = DocxEditorToolbar.ContentControlRemove;
</script>

<template>
  <DocxEditorRoot
    :document="document"
    :fonts="fonts"
    :locale="engineLocale(locale)"
    :mode="readOnly ? 'view' : 'edit'"
    :popups="popups"
    :translate="t"
    :table-interaction-label="tableInteractionLabel"
    @ready="emit('ready', $event)"
    @change="emit('change', $event)"
    @font-error="emit('font-error', $event)"
  >
    <div class="docx-editor webcon-docx-editor" :data-read-only="readOnly ? '' : undefined">
      <div class="webcon-docx-editor__chrome">
        <DocxEditorMenu
          class="webcon-docx-editor__menu"
          :save-handler="readOnly ? undefined : requestSave"
          :open-handler="ignoreOpen"
          :report-issue="false"
        >
          <MenuFile :preset="false">
            <MenuSave v-if="!readOnly" />
            <MenuSeparator v-if="!readOnly" />
            <MenuPageSetup />
          </MenuFile>
          <MenuGeneric id="review" :preset="false">
            <MenuItem slot="review.paragraphMarks" />
            <MenuSeparator />
            <MenuItem slot="review.protectDocument" />
          </MenuGeneric>
        </DocxEditorMenu>
        <DocxEditorToolbar class="webcon-docx-editor__toolbar" :on-save="readOnly ? undefined : requestSave">
          <CuratedStylePicker :labels="labels" />
          <ToolbarComments hidden />
          <ToolbarEditingMode v-if="readOnly" hidden />
          <ToolbarContentControlRemove v-if="showContentControls" hidden />
          <HeadingButtons v-if="!readOnly" :labels="labels" />
        </DocxEditorToolbar>
      </div>
      <div class="webcon-docx-editor__ruler">
        <DocxEditorHorizontalRuler />
      </div>
      <DocxEditorFontNotice />
      <div class="webcon-docx-editor__workspace">
        <DocxEditorNavigation />
        <DocxEditorViewport class="webcon-docx-editor__viewport">
          <DocxEditorHeaderFooterChrome />
          <DocxEditorNotesChrome />
          <DocxEditorContent />
          <div class="webcon-docx-editor__vertical-ruler" aria-hidden="true">
            <DocxEditorVerticalRuler />
          </div>
        </DocxEditorViewport>
        <DocxEditorPageNumber />
        <DocxEditorLoading overlay />
      </div>
      <ContentControlMode :show-all="showContentControls" />
    </div>
  </DocxEditorRoot>
</template>
