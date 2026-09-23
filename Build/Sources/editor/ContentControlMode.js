import { defineComponent, watch } from 'vue';
import { useContentControl, useDocxEditor, useEditorState } from '@docx-editor.dev/vue';

/**
 * Shows every content control's boundary when `showAll` is set
 * (content-controls="show" on the element): page mode maps controls to TYPO3
 * records, so editors have to see where each one starts and ends.
 *
 * The engine's switch is set directly once the document is on screen (the
 * composable's handle to it can be resolved before the surface exists); the
 * composable is told as well, so the toolbar toggle shows the same state.
 */
export default defineComponent({
  name: 'WebconContentControlMode',
  props: {
    showAll: { type: Boolean, default: false },
  },
  setup(props) {
    const editor = useDocxEditor();
    const ready = useEditorState((snapshot) => !snapshot.isLoading && snapshot.isOpening !== true);
    const chrome = useContentControl();
    watch(
      [() => props.showAll, editor, ready],
      ([showAll, instance, isReady]) => {
        const controls = instance?.surface?.contentControls;
        if (!isReady || !controls) {
          return;
        }
        if (controls.showAll() !== showAll) {
          controls.setShowAll(showAll);
        }
        chrome.setShowAll(showAll);
      },
      { immediate: true, flush: 'post' },
    );
    return () => null;
  },
});
