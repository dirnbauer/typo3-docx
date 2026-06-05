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

/**
 * React adapter for eigenpal/docx-editor. Mounted by the Lit glue element only.
 */
function DocxEditorHost({
  fileIdentifier,
  fileName,
  canWrite,
  initialRevision,
  loadingLabel = 'Loading document…',
  editorLocale = 'en',
  headingLabels = {},
  onStatus,
  onRemoteRevision,
  editorApi,
}) {
  const [buffer, setBuffer] = useState(null);
  const [revision, setRevision] = useState(initialRevision);
  const [loading, setLoading] = useState(true);
  const [activeStyleId, setActiveStyleId] = useState(null);
  const savingRef = useRef(false);
  const editorRef = useRef(null);
  const editorI18n = useMemo(
    () => buildDocxEditorI18n(editorLocale, headingLabels),
    [editorLocale, headingLabels],
  );

  const reload = useCallback(async () => {
    setLoading(true);
    const payload = await loadDocument(fileIdentifier);
    setBuffer(decodeBase64ToArrayBuffer(payload.data));
    setRevision(payload.revision);
    setLoading(false);
    onStatus?.('ready');
  }, [fileIdentifier, onStatus]);

  useEffect(() => {
    reload().catch((error) => {
      setLoading(false);
      onStatus?.('error', error.message);
    });
  }, [reload, onStatus]);

  const persistBuffer = useCallback(
    async (arrayBuffer, options = {}) => {
      if (!canWrite || savingRef.current || !arrayBuffer) {
        return null;
      }
      savingRef.current = true;
      onStatus?.('saving');
      try {
        if (options.saveAsFolder) {
          const result = await saveDocumentAs(
            options.saveAsFolder,
            options.saveAsFileName || fileName,
            arrayBuffer,
          );
          onStatus?.('saved');
          return result;
        }

        const result = await saveDocument(fileIdentifier, revision, arrayBuffer);
        setRevision(result.revision);
        onStatus?.('saved');
        onRemoteRevision?.(result.revision, result.contentHash);
        return result;
      } catch (error) {
        onStatus?.('error', error.message);
        if (error.httpStatus === 409) {
          onRemoteRevision?.(revision, null, true);
        }
        throw error;
      } finally {
        savingRef.current = false;
      }
    },
    [canWrite, fileIdentifier, fileName, onRemoteRevision, onStatus, revision],
  );

  const handleSave = useCallback(
    async (arrayBuffer) => {
      await persistBuffer(arrayBuffer);
    },
    [persistBuffer],
  );

  const exportCurrentBuffer = useCallback(async () => {
    const fromEditor = await editorRef.current?.save();
    if (fromEditor) {
      return fromEditor;
    }
    return buffer;
  }, [buffer]);

  useEffect(() => {
    if (!editorApi) {
      return undefined;
    }
    editorApi.save = async () => {
      const arrayBuffer = await exportCurrentBuffer();
      await persistBuffer(arrayBuffer);
    };
    editorApi.saveAs = async (folderIdentifier, targetFileName) => {
      const arrayBuffer = await exportCurrentBuffer();
      return persistBuffer(arrayBuffer, {
        saveAsFolder: folderIdentifier,
        saveAsFileName: targetFileName,
      });
    };
    editorApi.getFileName = () => fileName;
    return undefined;
  }, [editorApi, exportCurrentBuffer, fileName, persistBuffer]);

  useEffect(() => {
    const interval = window.setInterval(async () => {
      try {
        const state = await fetchRevision(fileIdentifier);
        if (!state?.ok) {
          return;
        }
        if (state.revision > revision) {
          onRemoteRevision?.(state.revision, state.contentHash, true);
        }
      } catch {
        // ignore polling errors
      }
    }, 5000);
    return () => window.clearInterval(interval);
  }, [fileIdentifier, onRemoteRevision, revision]);

  if (loading || !buffer) {
    return <div className="docx-editor-loading">{loadingLabel}</div>;
  }

  const headingToolbar =
    canWrite ? (
      <DocxHeadingToolbar
        editorRef={editorRef}
        activeStyleId={activeStyleId}
        disabled={loading}
        labels={headingLabels}
      />
    ) : null;

  return (
    <DocxEditor
      ref={editorRef}
      documentBuffer={buffer}
      documentName={fileName}
      mode={canWrite ? 'editing' : 'viewing'}
      readOnly={!canWrite}
      i18n={editorI18n}
      toolbarExtra={headingToolbar}
      onSave={canWrite ? handleSave : undefined}
      onSelectionChange={(state) => setActiveStyleId(state?.styleId ?? null)}
      onError={(error) => onStatus?.('error', error.message)}
    />
  );
}

const roots = new WeakMap();

/**
 * @param {HTMLElement} host - React mount node
 * @param {object} options - Editor options
 * @param {HTMLElement} [apiTarget] - Element that exposes docxEditorApi (toolbar / Lit element)
 */
export function mountDocxEditor(host, options, apiTarget = host) {
  if (!host) {
    return () => {};
  }
  const editorApi = {
    save: async () => {},
    saveAs: async () => null,
    getFileName: () => options.fileName ?? 'document.docx',
  };
  const root = createRoot(host);
  roots.set(host, root);
  root.render(<DocxEditorHost {...options} editorApi={editorApi} />);
  apiTarget.docxEditorApi = editorApi;
  return () => {
    root.unmount();
    roots.delete(host);
    delete apiTarget.docxEditorApi;
  };
}
