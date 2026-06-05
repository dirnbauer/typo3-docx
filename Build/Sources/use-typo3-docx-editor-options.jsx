import { useEffect, useMemo, useState } from 'react';
import { buildDocxEditorI18n } from './docx-editor-i18n.js';
import { DocxHeadingToolbar } from './docx-heading-toolbar.jsx';

/**
 * TYPO3-specific DocxEditor wiring: i18n, heading toolbar, selection state, save API.
 */
export function useTypo3DocxEditorOptions({
  editorApi,
  editorRef,
  editorLocale,
  headingLabels,
  canWrite,
  loading,
  fileName,
  exportCurrentBuffer,
  persistBuffer,
}) {
  const [activeStyleId, setActiveStyleId] = useState(null);

  const editorI18n = useMemo(
    () => buildDocxEditorI18n(editorLocale, headingLabels),
    [editorLocale, headingLabels],
  );

  const headingToolbar = useMemo(
    () =>
      canWrite ? (
        <DocxHeadingToolbar
          editorRef={editorRef}
          activeStyleId={activeStyleId}
          disabled={loading}
          labels={headingLabels}
        />
      ) : null,
    [activeStyleId, canWrite, editorRef, headingLabels, loading],
  );

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

  const onSelectionChange = (state) => {
    setActiveStyleId(state?.styleId ?? null);
  };

  return {
    editorI18n,
    headingToolbar,
    onSelectionChange,
  };
}
