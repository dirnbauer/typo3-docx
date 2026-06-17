# Frontend sources (`Build/Sources/`)

Lit glue, React mount adapter, and API helpers for the Vite bundle consumed by TYPO3.

## Rebuild after changes

```bash
npm run build
```

Commit `Resources/Public/Vite/docx-editor.js` and `manifest.json` when shipping frontend changes. End users do not need Node.js — the pre-built bundle is included in the extension.

## Layout

| File | Role |
| --- | --- |
| `docx-editor.js` | Vite entry (fonts guard, eigenpal CSS, Lit element) |
| `typo3-docx-editor.js` | Lit custom element; exposes `save()` / `saveAsToFolder()` |
| `docx-editor-mount.jsx` | React adapter around `@eigenpal/docx-editor-react` |
| `use-typo3-docx-editor-options.jsx` | TYPO3-specific editor wiring hook |
| `docx-labels.js` | Reads translated labels from `#docx-editor-app` dataset |
| `docx-editor-api.js` | Backend AJAX calls |
| `docx-heading-toolbar.jsx` | H1–H4 quick-style buttons (`toolbarExtra`) |

TYPO3 ES modules (not in the Vite bundle):

| File | Role |
| --- | --- |
| `Resources/Public/JavaScript/docx-editor-toolbar.js` | Docheader save/save-as, keyboard shortcut |
| `Resources/Public/JavaScript/docx-editor-notify.js` | Notification API for save feedback |

## Vite patches: paragraph-style dropdown

Two build-time text patches tune the eigenpal `Co` style-select. Both target the
same chunk (`chunk-SW2JOSQG` in `@eigenpal/docx-editor-react@1.2.1`) and are
guarded by `npm run test:build`, which fails if the chunk name or a needle drifts.

| Plugin | What it does |
| --- | --- |
| `heading4-fallback.js` | Appends `Heading4` to eigenpal's built-in `vo` style list (upstream stops at Heading 3). |
| `style-dropdown-headings.js` | Forces the dropdown to ignore the document's own styles and show exactly **Normal + Heading 1–4** (from `vo`). Without it, a Word file surfaces arbitrary names like "List Paragraph". |

After upgrading `@eigenpal/docx-editor-react`:

1. Run `npm run test:build` — it fails if the chunk name or a needle changed.
2. Update `EIGENPAL_FALLBACK_STYLES_CHUNK` / `HEADING3_FALLBACK_TAIL` (heading4) or
   `STYLE_DROPDOWN_OPTION_SOURCE` (dropdown) to match the new build.
3. If upstream adds Heading 4 natively, drop `heading4-fallback`. If upstream adds
   a prop to filter the style dropdown, drop `style-dropdown-headings` and configure
   it via `<DocxEditor>` instead. Delete the matching test(s) too.

To allow more styles in the dropdown, widen the regex in
`STYLE_DROPDOWN_HEADINGS_SOURCE` (e.g. add `Title|Subtitle`); for strictly the four
headings, drop `Normal|` — but then there is no in-dropdown way back to body text.

## CSS

TYPO3 theme overrides live in `Resources/Public/Css/`:

- `Editor.tokens.css` — eigenpal → TYPO3 token mapping
- `Editor.base.css` — module layout, menus, heading toolbar
- `Editor.toolbar.css` — compact formatting bar (multi-line wrap)
- `Editor.css` — imports the three partials

`Editor.css` is loaded **after** the Vite CSS bundle so TYPO3 tokens win in the cascade.
