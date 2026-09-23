import { createApp, h, shallowReactive } from 'vue';
import DocxEditorApp from './DocxEditorApp.vue';

/**
 * Mounts the Vue editor into `host` (light DOM, so the bundled stylesheet
 * applies) and returns a handle to feed it.
 *
 * @param {HTMLElement} host
 * @param {{
 *   locale?: string,
 *   readOnly?: boolean,
 *   contentControls?: string,
 *   labels: Record<string, string>,
 *   onReady?: (editor: object) => void,
 *   onChange?: (change: {revision: number}) => void,
 *   onSave?: () => void,
 *   onPrint?: () => void,
 *   onFontError?: (error: unknown) => void,
 * }} options
 */
export function mountDocxEditor(host, options) {
  const state = shallowReactive({
    document: undefined,
    locale: options.locale ?? 'en',
    readOnly: options.readOnly ?? false,
    contentControls: options.contentControls ?? 'default',
    labels: options.labels,
  });

  const app = createApp({
    name: 'WebconDocxEditorHost',
    setup() {
      return () =>
        h(DocxEditorApp, {
          document: state.document,
          locale: state.locale,
          readOnly: state.readOnly,
          contentControls: state.contentControls,
          labels: state.labels,
          onReady: (editor) => options.onReady?.(editor),
          onChange: (change) => options.onChange?.(change),
          onSave: () => options.onSave?.(),
          onPrint: () => options.onPrint?.(),
          onFontError: (error) => options.onFontError?.(error),
        });
    },
  });
  app.mount(host);

  return {
    /** Opens new bytes; the editor instance is rebuilt and onReady fires again. */
    setDocument(bytes) {
      state.document = bytes;
    },
    /** Updates locale, readOnly, contentControls or labels. */
    update(changes) {
      Object.assign(state, changes);
    },
    unmount() {
      app.unmount();
    },
  };
}
