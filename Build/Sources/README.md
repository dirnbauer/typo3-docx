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
| `docx-icu-format.js` | Tiny ICU MessageFormat resolver (plural + `{var}`) for XLIFF 2 labels |

TYPO3 ES modules (not in the Vite bundle):

| File | Role |
| --- | --- |
| `Resources/Public/JavaScript/docx-editor-toolbar.js` | Docheader save/save-as, keyboard shortcut |
| `Resources/Public/JavaScript/docx-editor-notify.js` | Notification API for save feedback |

## Vite patches: paragraph-style dropdown

Two build-time text patches tune the eigenpal style-select. Both scan **all**
chunks under `node_modules/@eigenpal/docx-editor-react/dist/` by content
pattern — they no longer care which chunk filename holds the needle, so a
straight upstream bump usually just works. `npm run test:build` is the gate.

| Plugin | What it does |
| --- | --- |
| `heading4-fallback.js` | Appends `Heading4` to eigenpal's built-in fallback style array (upstream stops at Heading 3). |
| `style-dropdown-headings.js` | Forces the dropdown to ignore the document's own styles and show exactly **Normal + Heading 1–4** (filtered from the fallback array). Without it, a Word file surfaces arbitrary names like "List Paragraph". |

### Upgrading `@eigenpal/docx-editor-react`

1. Bump the version in `package.json` and `rm package-lock.json && npm install`.
2. `npm run test:build` — both plugins assert their needles still match somewhere
   in `dist/*.mjs`.
3. If `heading4-fallback` fails: the fallback-array tail shape changed. Compare
   `dist/chunk-*.mjs` against `HEADING3_FALLBACK_TAIL` and update it. If upstream
   ships Heading 4 natively (`styles.heading4` already present), DROP this
   plugin and its test entirely.
4. If `style-dropdown-headings` fails: upstream refactored the dropdown logic
   again. **Don't change existing entries in `SHAPES`** (old fallback paths stay
   useful). **Add a new entry** with a unique `id` (e.g. `'1.7.x'`), the new
   needle (the smallest substring that uniquely identifies the new option-source
   expression), and a `transform` that rewrites it to use `FILTER_BODY`. If
   upstream adds a prop to filter the dropdown, drop this plugin and configure
   via `<DocxEditor>` instead.
5. `npm run build` and confirm the dropdown still shows Normal + H1–H4 in the
   running backend (and headings still apply).

To allow more styles in the dropdown, widen the regex in `FILTER_BODY` (e.g.
add `Title|Subtitle`). For strictly the four headings, drop `Normal|` — but
then there is no in-dropdown way back to body text.

### Known shapes

| eigenpal | shape id | dropdown option source |
| --- | --- | --- |
| `1.2.x` | `'1.2.x'` | `!o\|\|o.length===0?vo:o.filter(u=>u.type==="paragraph")` |
| `1.6.x` | `'1.6.x'` | `resolveParagraphStyleOptions(o);return u.length===0?Co:u.map(` |

## CSS

TYPO3 theme overrides live in `Resources/Public/Css/`:

- `Editor.tokens.css` — eigenpal → TYPO3 token mapping
- `Editor.base.css` — module layout, menus, heading toolbar
- `Editor.toolbar.css` — compact formatting bar (multi-line wrap)
- `Editor.css` — imports the three partials

`Editor.css` is loaded **after** the Vite CSS bundle so TYPO3 tokens win in the cascade.
