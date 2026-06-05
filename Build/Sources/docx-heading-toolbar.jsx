import { useCallback, useMemo } from 'react';
import { applyStyle } from '@eigenpal/docx-editor-core/prosemirror/commands';

export const TYPO3_HEADING_STYLE_IDS = ['Heading1', 'Heading2', 'Heading3', 'Heading4'];

/**
 * @param {import('react').RefObject<import('@eigenpal/docx-editor-react').DocxEditorRef | null>} editorRef
 * @param {string} styleId
 */
export function applyParagraphStyleId(editorRef, styleId) {
  const view = editorRef.current?.getEditorRef?.()?.getView?.();
  if (!view) {
    return false;
  }
  const command = applyStyle(styleId);
  const applied = command(view.state, view.dispatch);
  if (applied) {
    view.focus();
  }
  return applied;
}

/**
 * Quick-access heading buttons for TYPO3 (H1–H4 block styles).
 *
 * @param {object} props
 * @param {import('react').RefObject<import('@eigenpal/docx-editor-react').DocxEditorRef | null>} props.editorRef
 * @param {string | null} [props.activeStyleId]
 * @param {boolean} [props.disabled]
 * @param {Record<string, string>} [props.labels]
 */
export function DocxHeadingToolbar({ editorRef, activeStyleId = null, disabled = false, labels = {} }) {
  const items = useMemo(
    () =>
      TYPO3_HEADING_STYLE_IDS.map((styleId, index) => ({
        styleId,
        level: index + 1,
        label: labels[`heading${index + 1}`] ?? `H${index + 1}`,
        title: labels[`heading${index + 1}Title`] ?? labels[`heading${index + 1}`] ?? `Heading ${index + 1}`,
      })),
    [labels],
  );

  const apply = useCallback(
    (styleId) => {
      if (disabled) {
        return;
      }
      applyParagraphStyleId(editorRef, styleId);
    },
    [disabled, editorRef],
  );

  return (
    <div
      className="docx-heading-toolbar"
      role="toolbar"
      aria-label={labels.group ?? 'Headings'}
      data-testid="typo3-heading-toolbar"
    >
      {items.map(({ styleId, level, label, title }) => {
        const isActive = activeStyleId === styleId;
        return (
          <button
            key={styleId}
            type="button"
            className={`docx-heading-toolbar__btn docx-heading-toolbar__btn--h${level}${isActive ? ' docx-heading-toolbar__btn--active' : ''}`}
            disabled={disabled}
            title={title}
            aria-label={title}
            aria-pressed={isActive}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => apply(styleId)}
          >
            {label}
          </button>
        );
      })}
    </div>
  );
}
