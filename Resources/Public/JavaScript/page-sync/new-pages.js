import Notification from '@typo3/backend/notification.js';
import labels from '~labels/docx_editor.pagesync';
import { previewImport } from './api.js';
import { review } from './review.js';

/**
 * "Import Word file as subpages": upload a document, choose how it is split
 * into pages, review the plan, create the pages. Lists the new pages with a
 * link to each afterwards and refreshes the page tree.
 */

const form = document.getElementById('docx-page-sync-new');
const result = document.querySelector('[data-page-sync-result]');

function renderResult(results) {
  const list = document.createElement('ul');
  list.className = 'page-sync-new__pages';
  for (const item of results) {
    if (!item.page) {
      continue;
    }
    const entry = document.createElement('li');
    const link = document.createElement('a');
    link.href = item.pageUrl;
    link.textContent = labels.get('ui.new.openPage', [item.pageTitle || String(item.page)]);
    entry.append(link);
    list.append(entry);
  }
  const heading = document.createElement('h2');
  heading.className = 'h4';
  heading.textContent = labels.get('ui.new.created');
  result.replaceChildren(heading, list);
}

async function submit(event) {
  event.preventDefault();
  const input = form.querySelector('input[type="file"]');
  const file = input.files?.[0];
  if (!file) {
    input.reportValidity();
    return;
  }
  const limit = Number(form.dataset.maxUpload || 25) * 1024 * 1024;
  if (!/\.docx$/i.test(file.name) || file.size > limit) {
    Notification.error(
      labels.get('ui.error.preview'),
      file.size > limit ? labels.get('error.fileTooLarge', [Number(form.dataset.maxUpload || 25)]) : labels.get('error.notADocx'),
    );
    return;
  }
  const button = form.querySelector('button[type="submit"]');
  button.disabled = true;
  form.setAttribute('aria-busy', 'true');
  try {
    const preview = await previewImport({
      mode: 'newPage',
      parent: Number(form.dataset.parent),
      split: new FormData(form).get('split') || 'none',
    }, new Uint8Array(await file.arrayBuffer()));
    const outcome = await review(preview, { title: labels.get('ui.review.titleNew') });
    if (outcome.applied) {
      renderResult(outcome.response.results ?? []);
      form.reset();
      top.document.dispatchEvent(new CustomEvent('typo3:pagetree:refresh'));
    }
  } catch (error) {
    Notification.error(labels.get('ui.error.preview'), error?.message || String(error));
  } finally {
    button.disabled = false;
    form.removeAttribute('aria-busy');
  }
}

form?.addEventListener('submit', submit);
