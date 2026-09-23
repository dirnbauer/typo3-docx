/**
 * Printing without the commercial package: the pages the engine painted, one
 * sheet per page, at the document's own page size.
 *
 * The engine paints only the pages near the viewport. preparePrint() shows the
 * document at 100 % in a viewport as tall as the document, waits until every
 * page is painted, and copies the pages into a print-only container next to
 * the backend's own markup. Named @page rules carry each section's paper size
 * (A4, Letter, landscape…) with no margin — the margins are part of the page.
 * The print stylesheet (Editor.base.css) hides everything else while the
 * document is printing; the returned cleanup restores zoom and scroll.
 */

export const PRINTING_CLASS = 'webcon-docx-printing';
export const PRINT_CONTAINER_CLASS = 'webcon-docx-print';
const PAGE_NAME_PREFIX = 'webcon-docx-page-';
const MM_PER_PX = 25.4 / 96;
const MATERIALIZE_TIMEOUT_MS = 4000;

/**
 * Paper sizes of the pages, deduplicated, and each page's index into them.
 *
 * @param {ReadonlyArray<{box: {width: number, height: number}}>} geometry page boxes in CSS px at 100 %
 * @returns {{sizes: Array<{width: number, height: number}>, pageSize: number[]}} sizes in millimetres
 */
export function paperSizes(geometry) {
  const sizes = [];
  const pageSize = geometry.map((page) => {
    const width = Math.round(page.box.width * MM_PER_PX * 10) / 10;
    const height = Math.round(page.box.height * MM_PER_PX * 10) / 10;
    let index = sizes.findIndex((size) => Math.abs(size.width - width) < 0.5 && Math.abs(size.height - height) < 0.5);
    if (index === -1) {
      index = sizes.push({ width, height }) - 1;
    }
    return index;
  });
  return { sizes, pageSize };
}

/** The @page rules for the paper sizes. */
export function pageRules(sizes) {
  return sizes
    .map((size, index) => `@page ${PAGE_NAME_PREFIX}${index} { size: ${size.width}mm ${size.height}mm; margin: 0; }`)
    .join('\n');
}

/** Two frames — or 100 ms where a hidden tab gets none. */
function nextFrame() {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, 100);
    requestAnimationFrame(() => requestAnimationFrame(() => {
      clearTimeout(timer);
      resolve();
    }));
  });
}

/** Whether the engine painted the page's content (a page it has not reached yet is an empty box). */
function isPainted(page) {
  return page.querySelector('.docx-page-content')?.childElementCount > 0;
}

function paintedPages(root) {
  return [...root.querySelectorAll('.docx-pages > .docx-page')];
}

/**
 * Copies a painted page for printing. Canvas content does not survive cloneNode(), so it
 * is drawn into the copy.
 */
function copyPage(page, name) {
  const copy = page.cloneNode(true);
  copy.style.position = 'relative';
  copy.style.left = '';
  copy.style.top = '';
  copy.style.page = name;
  copy.removeAttribute('id');
  const sources = page.querySelectorAll('canvas');
  copy.querySelectorAll('canvas').forEach((canvas, index) => {
    const source = sources[index];
    if (source && source.width > 0 && source.height > 0) {
      canvas.getContext('2d')?.drawImage(source, 0, 0);
    }
  });
  return copy;
}

/**
 * @param {object} editor the @docx-editor.dev/core editor instance
 * @param {HTMLElement} root the element the editor is rendered in
 * @returns {Promise<() => void>} undoes everything preparePrint() changed
 */
export async function preparePrint(editor, root) {
  const document = root.ownerDocument;
  const viewport = root.querySelector('.docx-editor__scroll-container') ?? root;
  const zoomMode = editor.getZoomMode?.();
  const scrollTop = viewport.scrollTop;
  const viewportStyle = viewport.getAttribute('style');
  const geometry = editor.getPageGeometry?.() ?? [];

  const deadline = Date.now() + MATERIALIZE_TIMEOUT_MS;
  editor.setZoom?.(1);
  // Until the pages are drawn at 100 %.
  const fullWidth = geometry[0]?.box.width ?? 0;
  while (fullWidth > 0 && Math.abs((paintedPages(root)[0]?.offsetWidth ?? fullWidth) - fullWidth) > 1 && Date.now() < deadline) {
    await nextFrame();
  }
  // As tall as the document, so the engine paints every page at once. The editor's
  // surface clips it; nothing visible changes but the zoom.
  const last = geometry[geometry.length - 1];
  const documentHeight = last ? last.box.y + last.box.height : 0;
  viewport.style.height = `${Math.ceil(Math.max(viewport.scrollHeight, documentHeight + 200))}px`;
  viewport.style.flex = 'none';
  viewport.dispatchEvent(new Event('scroll'));

  let pages = paintedPages(root);
  while (pages.length > 0 && !pages.every(isPainted) && Date.now() < deadline) {
    await nextFrame();
    pages = paintedPages(root);
  }
  // A page still empty (a very long document, a slow font): visit it.
  for (const [index, page] of pages.entries()) {
    if (!isPainted(page)) {
      editor.scrollToPage?.(index + 1);
      for (let frame = 0; frame < 30 && !isPainted(paintedPages(root)[index] ?? page); frame += 1) {
        await nextFrame();
      }
    }
  }
  pages = paintedPages(root);

  const { sizes, pageSize } = paperSizes(geometry.length === pages.length ? geometry : pages.map((page) => ({
    box: { width: page.offsetWidth, height: page.offsetHeight },
  })));
  const style = document.createElement('style');
  style.textContent = pageRules(sizes);
  const container = document.createElement('div');
  container.className = `docx-editor ${PRINT_CONTAINER_CLASS}`;
  container.setAttribute('aria-hidden', 'true');
  const surface = document.createElement('div');
  surface.className = 'docx-paginated-surface';
  const sheets = document.createElement('div');
  sheets.className = 'docx-pages';
  pages.forEach((page, index) => sheets.append(copyPage(page, `${PAGE_NAME_PREFIX}${pageSize[index] ?? 0}`)));
  surface.append(sheets);
  container.append(surface);
  document.head.append(style);
  document.body.append(container);
  document.documentElement.classList.add(PRINTING_CLASS);

  let restored = false;
  return () => {
    if (restored) {
      return;
    }
    restored = true;
    document.documentElement.classList.remove(PRINTING_CLASS);
    container.remove();
    style.remove();
    if (viewportStyle === null) {
      viewport.removeAttribute('style');
    } else {
      viewport.setAttribute('style', viewportStyle);
    }
    if (zoomMode) {
      editor.setZoomMode?.(zoomMode);
    }
    setTimeout(() => {
      viewport.scrollTop = scrollTop;
    }, 50);
  };
}

/**
 * Prints the document: prepares the pages, opens the browser's print dialog and cleans up
 * once it closes.
 *
 * @param {object} editor
 * @param {HTMLElement} root
 */
export async function printDocument(editor, root) {
  const view = root.ownerDocument.defaultView;
  const restore = await preparePrint(editor, root);
  let fallback = 0;
  const done = () => {
    view.removeEventListener('afterprint', done);
    view.clearTimeout(fallback);
    restore();
  };
  view.addEventListener('afterprint', done);
  // Browsers without afterprint get their editor back after a while.
  fallback = view.setTimeout(done, 10 * 60 * 1000);
  view.print();
}
