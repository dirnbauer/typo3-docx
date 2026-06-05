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

## Vite patch: Heading 4 fallback styles

`@eigenpal/docx-editor-react@1.2.1` ships a hard-coded fallback style list without Heading 4. The plugin in `Build/vite/plugins/heading4-fallback.js` patches chunk `chunk-SW2JOSQG` at build time.

After upgrading `@eigenpal/docx-editor-react`:

1. Run `npm run test:build` — it fails if the chunk name or needle changed.
2. Update `EIGENPAL_FALLBACK_STYLES_CHUNK` and/or `HEADING3_FALLBACK_TAIL` in the plugin.
3. If upstream adds Heading 4 natively, remove the plugin and delete the test.

## CSS

TYPO3 theme overrides live in `Resources/Public/Css/`:

- `Editor.tokens.css` — eigenpal → TYPO3 token mapping
- `Editor.base.css` — module layout, menus, heading toolbar
- `Editor.toolbar.css` — compact formatting bar (multi-line wrap)
- `Editor.css` — imports the three partials

`Editor.css` is loaded **after** the Vite CSS bundle so TYPO3 tokens win in the cascade.
