import { applyStyle } from '@eigenpal/docx-editor-core/prosemirror/commands';
import labels from '~labels/docx_editor.messages';

const HEADING_LEVELS = [1, 2, 3, 4];

/**
 * @param {import('react').RefObject<import('@eigenpal/docx-editor-react').DocxEditorRef | null>} editorRef
 * @param {string} styleId
 */
function applyParagraphStyleId(editorRef, styleId) {
  const view = editorRef.current?.getEditorRef?.()?.getView?.();
  if (!view) {
    return;
  }
  if (applyStyle(styleId)(view.state, view.dispatch)) {
    view.focus();
  }
}

/**
 * Quick-access H1–H4 buttons rendered into eigenpal's formatting bar.
 *
 * @param {object} props
 * @param {import('react').RefObject<import('@eigenpal/docx-editor-react').DocxEditorRef | null>} props.editorRef
 * @param {string | null} [props.activeStyleId]
 */
export function DocxHeadingToolbar({ editorRef, activeStyleId = null }) {
  return (
    <div
      className="docx-heading-toolbar"
      role="toolbar"
      aria-label={labels.get('editor.headings.group')}
      data-testid="typo3-heading-toolbar"
    >
      {HEADING_LEVELS.map((level) => {
        const styleId = `Heading${level}`;
        const isActive = activeStyleId === styleId;
        const title = labels.get(`editor.headings.h${level}.title`);
        return (
          <button
            key={styleId}
            type="button"
            className={`docx-heading-toolbar__btn docx-heading-toolbar__btn--h${level}${isActive ? ' docx-heading-toolbar__btn--active' : ''}`}
            title={title}
            aria-label={title}
            aria-pressed={isActive}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => applyParagraphStyleId(editorRef, styleId)}
          >
            {labels.get(`editor.headings.h${level}`)}
          </button>
        );
      })}
    </div>
  );
}
