import { createRoot } from 'react-dom/client';
import { useCallback, useEffect, useRef, useState } from 'react';
import { DocxEditor } from '@eigenpal/docx-editor-react';
import '@eigenpal/docx-editor-react/styles.css';
import {
  decodeBase64ToArrayBuffer,
  fetchRevision,
  loadDocument,
  saveDocument,
} from './docx-editor-api.js';

/**
 * React adapter for eigenpal/docx-editor. Mounted by the Lit glue element only.
 */
function DocxEditorHost({
  fileIdentifier,
  fileName,
  canWrite,
  initialRevision,
  loadingLabel = 'Loading document…',
  onStatus,
  onRemoteRevision,
}) {
  const [buffer, setBuffer] = useState(null);
  const [revision, setRevision] = useState(initialRevision);
  const [loading, setLoading] = useState(true);
  const savingRef = useRef(false);

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

  const handleSave = useCallback(
    async (arrayBuffer) => {
      if (!canWrite || savingRef.current) {
        return;
      }
      savingRef.current = true;
      onStatus?.('saving');
      try {
        const result = await saveDocument(fileIdentifier, revision, arrayBuffer);
        setRevision(result.revision);
        onStatus?.('saved');
        onRemoteRevision?.(result.revision, result.contentHash);
      } catch (error) {
        onStatus?.('error', error.message);
        if (error.httpStatus === 409) {
          onRemoteRevision?.(revision, null, true);
        }
      } finally {
        savingRef.current = false;
      }
    },
    [canWrite, fileIdentifier, onRemoteRevision, onStatus, revision],
  );

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

  return (
    <DocxEditor
      documentBuffer={buffer}
      documentName={fileName}
      mode={canWrite ? 'editing' : 'viewing'}
      readOnly={!canWrite}
      onSave={canWrite ? handleSave : undefined}
      onError={(error) => onStatus?.('error', error.message)}
    />
  );
}

const roots = new WeakMap();

export function mountDocxEditor(host, options) {
  if (!host) {
    return () => {};
  }
  const root = createRoot(host);
  roots.set(host, root);
  root.render(<DocxEditorHost {...options} />);
  return () => {
    root.unmount();
    roots.delete(host);
  };
}

export function reloadDocxEditor(host, options) {
  mountDocxEditor(host, options);
}
