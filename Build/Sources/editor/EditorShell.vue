<script setup>
import { computed, defineComponent, h, provide } from 'vue';
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
 * not run) but with File › Print (see print.js), the formatting toolbar with the curated style picker and the
 * H1–H4 buttons, rulers, navigation and the packaged popups. Mirrors what
 * the packaged <DocxEditor> renders; composition is what allows the
 * overrides.
 *
 * Only the Apache-2.0 packages are used. Everything that belongs to the
 * commercial @docx-editor.dev/pro package is left out of the chrome, not just
 * disabled: the Suggesting editing mode (with it the whole editing-mode
 * switch), the Comments & Changes toggle, the reviewers list, tracked-change
 * navigation, accept/reject and markup views, and "Add a comment" in the
 * context menu. Review keeps the rows the open-source engine drives
 * (paragraph marks, forms protection). The engine still shows tracked
 * changes in their final state and keeps them and comments on save.
 * Build/Tests/chrome.test.js holds the chrome to that.
 */
const props = defineProps({
  document: { type: Uint8Array, default: undefined },
  locale: { type: String, default: 'en' },
  readOnly: { type: Boolean, default: false },
  contentControls: { type: String, default: 'default' },
  labels: { type: Object, required: true },
});

const emit = defineEmits(['ready', 'change', 'save', 'print', 'font-error']);

// Fonts come from @docx-editor.dev/fonts, bundled into Resources/Public/Vite
// and fetched same-origin only when a document names the family.
const fonts = packagedFonts();

const translation = useTranslation();
const t = (key, params) => translation.t(key, params);
provide(PageNumberTranslationContext, (key) => t(key));

const showContentControls = computed(() => props.contentControls === 'show');

// The preset context menu's "Add a comment…" row (review.comments) needs the
// commercial review module: replaced by a row that renders nothing.
const NoCommentRow = defineComponent({ name: 'WebconNoCommentRow', setup: () => () => null });
NoCommentRow.docxRow = 'review.comments';

const popups = computed(() => ({
  hyperlink: (popupProps) => h(DocxEditorHyperLink, popupProps),
  contentControl: (popupProps) =>
    showContentControls.value
      ? h(DocxEditorContentControl, { ...popupProps, preset: false }, {
          default: () => [h(DocxEditorContentControl.Header), h(DocxEditorContentControl.Fields)],
        })
      : h(DocxEditorContentControl, popupProps),
  equation: () => h(DocxEditorEquation),
  contextMenu: (popupProps) => h(DocxEditorContextMenu, { ...popupProps, t }, { default: () => [h(NoCommentRow)] }),
}));

const tableInteractionLabel = (key) => t(key);

const requestSave = () => emit('save');
const ignoreOpen = () => {};

// File › Print: the engine has no print slot (upstream prints through the commercial
// package), so the row is the host's: Material Symbols "print", the catalogue's own label
// and shortcut. Escape closes the menu before the pages are prepared.
const PRINT_ICON = [
  'M640-640v-120H320v120h-80v-200h480v200h-80Zm-480 80h640-640Zm560 100q17 0 28.5-11.5T760-500q0-17-11.5-28.5T720-540q-17 0-28.5 11.5T680-500q0 17 11.5 28.5T720-460Zm-80 260v-160H320v160h320Zm80 80H240v-160H80v-240q0-51 35-85.5t85-34.5h560q51 0 85.5 34.5T880-520v240H720v160Zm80-240v-160q0-17-11.5-28.5T760-560H200q-17 0-28.5 11.5T160-520v160h80v-80h480v80h80Z',
];
const printIcon = () =>
  h('svg', { viewBox: '0 -960 960 960', width: 18, height: 18, 'aria-hidden': 'true', focusable: 'false' },
    PRINT_ICON.map((d) => h('path', { d, fill: 'currentColor' })));
const requestPrint = () => {
  document.activeElement?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  emit('print');
};

const MenuFile = DocxEditorMenu.File;
const MenuGeneric = DocxEditorMenu.Menu;
const MenuItem = DocxEditorMenu.Item;
const MenuSave = DocxEditorMenu.Save;
const MenuSeparator = DocxEditorMenu.Separator;
const MenuPageSetup = DocxEditorMenu.PageSetup;
const MenuRow = DocxEditorMenu.Row;
const ToolbarComments = DocxEditorToolbar.Comments;
const ToolbarEditingMode = DocxEditorToolbar.EditingMode;
const ToolbarReviewers = DocxEditorToolbar.Reviewers;
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
            <MenuRow
              row-slot="file.print"
              :icon="printIcon()"
              :shortcut="t('toolbar.printShortcut')"
              :select-handler="requestPrint"
            >{{ t('toolbar.print') }}</MenuRow>
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
          <ToolbarReviewers hidden />
          <ToolbarEditingMode hidden />
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
