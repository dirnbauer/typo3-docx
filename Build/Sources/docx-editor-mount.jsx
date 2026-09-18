import { createRoot } from 'react-dom/client';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { DocxEditor } from '@eigenpal/docx-editor-react';
import {
  decodeBase64ToArrayBuffer,
  fetchRevision,
  loadDocument,
  saveDocument,
  saveDocumentAs,
} from './docx-editor-api.js';
import { buildDocxEditorI18n } from './docx-editor-i18n.js';
import { DocxHeadingToolbar } from './docx-heading-toolbar.jsx';

const REVISION_POLL_INTERVAL = 5000;

/**
 * React adapter for eigenpal/docx-editor. Mounted by <typo3-docx-editor> only.
 *
 * Callbacks: onApi({save, saveAs}) once the document is loaded, onSaved(),
 * onError(message), onConflict() when another editor stored a newer revision.
 */
function DocxEditorHost({
  fileIdentifier,
  fileName,
  canWrite,
  initialRevision,
  editorLocale,
  loadingLabel,
  headingLabels,
  onApi,
  onSaved,
  onError,
  onConflict,
}) {
  const [buffer, setBuffer] = useState(null);
  const [revision, setRevision] = useState(initialRevision);
  const [activeStyleId, setActiveStyleId] = useState(null);
  const editorRef = useRef(null);
  const savingRef = useRef(false);

  useEffect(() => {
    loadDocument(fileIdentifier)
      .then((payload) => {
        setBuffer(decodeBase64ToArrayBuffer(payload.data));
        setRevision(payload.revision);
      })
      .catch((error) => onError(error.message));
  }, [fileIdentifier, onError]);

  useEffect(() => {
    const timer = window.setInterval(async () => {
      try {
        const state = await fetchRevision(fileIdentifier);
        if (state.revision > revision) {
          onConflict();
        }
      } catch {
        // ignore polling errors
      }
    }, REVISION_POLL_INTERVAL);
    return () => window.clearInterval(timer);
  }, [fileIdentifier, onConflict, revision]);

  /** Serialises one save at a time and maps the outcome to the callbacks. */
  const persist = useCallback(
    async (request) => {
      if (!canWrite || savingRef.current) {
        return null;
      }
      savingRef.current = true;
      try {
        const result = await request();
        onSaved();
        return result;
      } catch (error) {
        onError(error.message);
        if (error.httpStatus === 409) {
          onConflict();
        }
        throw error;
      } finally {
        savingRef.current = false;
      }
    },
    [canWrite, onConflict, onError, onSaved],
  );

  const currentBuffer = useCallback(
    async () => (await editorRef.current?.save()) ?? buffer,
    [buffer],
  );

  const save = useCallback(
    (arrayBuffer) =>
      persist(async () => {
        const result = await saveDocument(fileIdentifier, revision, arrayBuffer);
        setRevision(result.revision);
        return result;
      }),
    [fileIdentifier, persist, revision],
  );

  useEffect(() => {
    if (!buffer) {
      return;
    }
    onApi({
      save: async () => save(await currentBuffer()),
      saveAs: async (folderIdentifier, targetFileName) =>
        persist(async () => saveDocumentAs(folderIdentifier, targetFileName || fileName, await currentBuffer())),
    });
  }, [buffer, currentBuffer, fileName, onApi, persist, save]);

  const i18n = useMemo(() => buildDocxEditorI18n(editorLocale, headingLabels), [editorLocale, headingLabels]);

  if (!buffer) {
    return <div className="docx-editor-loading">{loadingLabel}</div>;
  }

  return (
    <DocxEditor
      ref={editorRef}
      documentBuffer={buffer}
      documentName={fileName}
      mode={canWrite ? 'editing' : 'viewing'}
      readOnly={!canWrite}
      i18n={i18n}
      toolbarExtra={
        canWrite ? (
          <DocxHeadingToolbar editorRef={editorRef} activeStyleId={activeStyleId} labels={headingLabels} />
        ) : null
      }
      onSave={canWrite ? save : undefined}
      onSelectionChange={(state) => setActiveStyleId(state?.styleId ?? null)}
      onError={(error) => onError(error.message)}
    />
  );
}

/**
 * @param {HTMLElement} host - React mount node
 * @param {object} options - DocxEditorHost props
 * @returns {() => void} unmount
 */
export function mountDocxEditor(host, options) {
  const root = createRoot(host);
  root.render(<DocxEditorHost {...options} />);
  return () => root.unmount();
}
