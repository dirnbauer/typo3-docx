import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import labels from '~labels/docx_editor.pagesync';
import { applyImport, discardImport } from './api.js';

/**
 * The review of an import: what importing the Word document would create,
 * change, translate and delete, element by element and field by field, in a
 * modal. The editor can leave elements out, pick another content type for a
 * new element, decide conflicts field by field and confirm deletions; nothing
 * is written before "Import".
 *
 * review(preview) resolves to {applied: true, response} after a successful
 * import, or {applied: false} when the editor cancelled (the stored preview is
 * discarded) or the import was refused.
 */

const WRITES = new Set(['create', 'update', 'conflict', 'translate']);
const BADGES = {
  create: 'badge-success',
  update: 'badge-info',
  conflict: 'badge-warning',
  delete: 'badge-danger',
  translate: 'badge-notice',
};

let sequence = 0;

function uniqueId(prefix) {
  sequence += 1;
  return `page-sync-${prefix}-${sequence}`;
}

/** A DOM element; `props` are set as properties, everything else as attributes. */
function h(tag, attributes = {}, children = [], props = {}) {
  const node = document.createElement(tag);
  for (const [name, value] of Object.entries(attributes)) {
    if (value !== null && value !== undefined && value !== false) {
      node.setAttribute(name, value === true ? '' : String(value));
    }
  }
  Object.assign(node, props);
  node.append(...[children].flat().filter((child) => child !== null && child !== undefined && child !== false && child !== ''));
  return node;
}

function text(tag, value, attributes = {}) {
  return h(tag, attributes, [String(value ?? '')]);
}

function label(key, args) {
  return labels.get(key, args);
}

function percent(value) {
  return `${Math.round(Math.min(1, Math.max(0, Number(value) || 0)) * 100)} %`;
}

/** Whether an entry or one of its collection items would write something. */
function writes(entry) {
  return WRITES.has(entry.action) || entry.action === 'delete' || (entry.children ?? []).some(writes);
}

class ReviewState {
  excluded = new Set();

  types = new Map();

  resolutions = {};

  confirmed = new Set();

  applyMoves = true;

  toDecisions() {
    return {
      excluded: [...this.excluded],
      types: Object.fromEntries(this.types),
      resolutions: this.resolutions,
      confirmedDeletions: [...this.confirmed],
      conflictDefault: 'typo3',
      applyMoves: this.applyMoves,
    };
  }
}

function renderMessages(messages, severity = 'info') {
  if (!messages?.length) {
    return null;
  }
  return h('div', { class: `callout callout-${severity}`, role: 'status' }, [
    h('div', { class: 'callout-content' }, [
      h('div', { class: 'callout-body' }, messages.map((message) => text('p', message.text))),
    ]),
  ]);
}

function renderCounts(counts) {
  const items = Object.entries(counts ?? {})
    .filter(([action, count]) => count > 0 && action !== 'unchanged')
    .map(([action, count]) => h('span', { class: `badge ${BADGES[action] ?? 'badge-default'}` }, [`${count} × ${label(`plan.action.${action}`)}`]));
  if (items.length === 0) {
    return null;
  }
  return h('p', { class: 'page-sync-review__counts' }, items);
}

/** The content type of a new element: the proposals as a select, and who chose. */
function renderTypeChoice(entry, state) {
  const match = entry.match;
  if (!match || !match.proposals?.length) {
    return null;
  }
  const id = uniqueId('type');
  const select = h('select', { class: 'form-select form-select-sm', id }, match.proposals.map((proposal) => h(
    'option',
    { value: proposal.type },
    [label('ui.review.typeOption', [proposal.label || proposal.type, percent(proposal.score)])],
    { selected: proposal.type === entry.type },
  )));
  select.addEventListener('change', () => {
    if (select.value === entry.type) {
      state.types.delete(entry.id);
    } else {
      state.types.set(entry.id, select.value);
    }
  });

  let decidedBy;
  if (match.decidedBy === 'jev') {
    decidedBy = label('ui.review.decidedByJev', [percent(match.confidence)]);
  } else {
    decidedBy = label('ui.review.decidedByStructure');
  }
  const chosen = match.proposals.find((proposal) => proposal.type === entry.type);
  const notes = [text('span', decidedBy)];
  if (match.needsReview) {
    notes.push(text('strong', label('ui.review.needsReview'), { class: 'text-warning' }));
  }
  if (match.jev?.fallbackReason && match.decidedBy !== 'jev' && match.jev.fallbackReason !== '') {
    notes.push(text('span', label('ui.review.jevUnavailable', [match.jev.fallbackReason]), { class: 'text-body-secondary' }));
  }

  const details = h('details', { class: 'page-sync-review__why' }, [
    text('summary', label('ui.review.why')),
    text('p', match.structure),
    chosen?.lost?.length ? text('p', label('ui.review.lost', [chosen.lost.join(', ')])) : null,
    chosen?.missingRequired?.length ? text('p', label('ui.review.missingRequired', [chosen.missingRequired.join(', ')])) : null,
  ]);

  return h('div', { class: 'page-sync-review__type' }, [
    text('label', label('ui.review.contentType'), { for: id, class: 'form-label' }),
    select,
    h('p', { class: 'page-sync-review__decided form-text' }, notes),
    details,
  ]);
}

function renderConflict(entry, change, state) {
  const name = uniqueId('conflict');
  const choice = (value, title, preview) => {
    const id = uniqueId('choice');
    const input = h('input', { class: 'form-check-input', type: 'radio', name, id, value }, [], { checked: value === 'typo3' });
    input.addEventListener('change', () => {
      if (!input.checked) {
        return;
      }
      state.resolutions[entry.id] = { ...(state.resolutions[entry.id] ?? {}), [change.field]: value };
    });
    return h('div', { class: 'form-check' }, [
      input,
      h('label', { class: 'form-check-label', for: id }, [
        text('span', title),
        text('span', preview || label('ui.review.empty'), { class: 'page-sync-review__preview' }),
      ]),
    ]);
  };
  return h('fieldset', { class: 'page-sync-review__conflict' }, [
    text('legend', label('ui.review.conflictLegend', [change.label])),
    choice('typo3', label('ui.review.keepTypo3'), change.typo3),
    choice('word', label('ui.review.takeWord'), change.word),
  ]);
}

function renderFields(entry, state) {
  const changes = (entry.fields ?? []).filter((change) => change.status !== 'unchanged');
  if (changes.length === 0) {
    return null;
  }
  return h('ul', { class: 'page-sync-review__fields list-unstyled' }, changes.map((change) => {
    if (change.status === 'conflict') {
      return h('li', {}, [renderConflict(entry, change, state)]);
    }
    const parts = [text('strong', change.label), ': ', text('span', change.statusLabel)];
    if ((change.status === 'changed' || change.status === 'new') && change.word) {
      parts.push(text('span', change.word, { class: 'page-sync-review__preview' }));
    }
    if (change.lossy) {
      parts.push(text('span', label('ui.review.lossy'), { class: 'page-sync-review__lossy text-warning' }));
    }
    return h('li', {}, parts);
  }));
}

function renderDeleteToggle(entry, state, title) {
  const id = uniqueId('delete');
  const input = h('input', { class: 'form-check-input', type: 'checkbox', id }, [], { checked: false });
  input.addEventListener('change', () => {
    if (input.checked) {
      state.confirmed.add(entry.id);
    } else {
      state.confirmed.delete(entry.id);
    }
  });
  return h('div', { class: 'form-check' }, [
    input,
    text('label', label('ui.review.confirmDelete', [title]), { class: 'form-check-label', for: id }),
  ]);
}

function renderChildren(entry, state) {
  const children = (entry.children ?? []).filter((child) => child.action !== 'unchanged' || writes(child));
  if (children.length === 0) {
    return null;
  }
  return h('ul', { class: 'page-sync-review__children list-unstyled' }, children.map((child) => h('li', {}, [
    h('span', { class: `badge ${BADGES[child.action] ?? 'badge-default'}` }, [child.actionLabel]),
    ' ',
    text('span', child.title || label('ui.review.untitled')),
    child.action === 'delete' ? renderDeleteToggle(child, state, child.title || label('ui.review.untitled')) : null,
    renderFields(child, state),
  ])));
}

function renderInclude(entry, state, title) {
  if (entry.action === 'delete') {
    return renderDeleteToggle(entry, state, title);
  }
  if (!WRITES.has(entry.action)) {
    return text('span', '–', { 'aria-hidden': 'true', class: 'text-body-secondary' });
  }
  const id = uniqueId('include');
  const input = h('input', { class: 'form-check-input', type: 'checkbox', id }, [], { checked: true });
  input.addEventListener('change', () => {
    if (input.checked) {
      state.excluded.delete(entry.id);
    } else {
      state.excluded.add(entry.id);
    }
  });
  return h('div', { class: 'form-check' }, [
    input,
    text('label', label('ui.review.include', [title]), { class: 'form-check-label visually-hidden', for: id }),
  ]);
}

function renderRow(entry, state) {
  const title = entry.title || label('ui.review.untitled');
  const record = entry.uid > 0 ? `${entry.typeLabel || entry.table} · ${entry.table}:${entry.uid}` : (entry.typeLabel || '');
  const row = h('tr', { class: writes(entry) ? '' : 'page-sync-review__unchanged' }, [
    h('td', {}, [renderInclude(entry, state, title)]),
    h('td', {}, [
      text('div', title, { class: 'page-sync-review__title' }),
      record ? text('div', record, { class: 'form-text' }) : null,
    ]),
    h('td', {}, [h('span', { class: `badge ${BADGES[entry.action] ?? 'badge-default'}` }, [entry.actionLabel])]),
    h('td', {}, [
      entry.action === 'create' ? renderTypeChoice(entry, state) : null,
      entry.action !== 'create' ? renderFields(entry, state) : null,
      renderChildren(entry, state),
      entry.messages?.length ? h('ul', { class: 'page-sync-review__messages list-unstyled form-text' }, entry.messages.map((message) => text('li', message.text))) : null,
    ]),
  ]);
  return row;
}

function renderPlan(plan, state) {
  const rows = [];
  if (plan.page && !plan.newPage) {
    rows.push(renderRow(plan.page, state));
  }
  for (const entry of plan.entries ?? []) {
    rows.push(renderRow(entry, state));
  }
  const captionId = uniqueId('caption');
  const heading = plan.newPage
    ? label('ui.review.newPage', [plan.page?.title ?? ''])
    : label('ui.review.page');
  const table = h('table', { class: 'table table-hover page-sync-review__table', 'aria-describedby': captionId }, [
    h('thead', {}, [h('tr', {}, [
      text('th', label('ui.review.column.include'), { scope: 'col' }),
      text('th', label('ui.review.column.element'), { scope: 'col' }),
      text('th', label('ui.review.column.change'), { scope: 'col' }),
      text('th', label('ui.review.column.details'), { scope: 'col' }),
    ])]),
    h('tbody', {}, rows),
  ]);

  let moves = null;
  if (plan.moves?.length) {
    const id = uniqueId('moves');
    const input = h('input', { class: 'form-check-input', type: 'checkbox', id }, [], { checked: true });
    input.addEventListener('change', () => {
      state.applyMoves = input.checked;
    });
    moves = h('div', { class: 'form-check page-sync-review__moves' }, [
      input,
      text('label', label('ui.review.moves', [plan.moves.length]), { class: 'form-check-label', for: id }),
    ]);
  }

  return h('section', { class: 'page-sync-review__plan' }, [
    text('h2', heading, { class: 'h4', id: captionId }),
    renderMessages(plan.messages, 'info'),
    renderCounts(plan.counts),
    h('div', { class: 'table-fit' }, [table]),
    moves,
  ]);
}

function renderReview(preview, state) {
  const plans = preview.plans ?? [];
  const container = h('div', { class: 'page-sync-review' });
  const anyWrites = plans.some((plan) => plan.hasWrites || plan.newPage);
  if (!anyWrites) {
    container.append(renderMessages([{ text: label('ui.review.nothing') }], 'info'));
  }
  plans.forEach((plan) => container.append(renderPlan(plan, state)));

  const unchanged = container.querySelectorAll('.page-sync-review__unchanged').length;
  if (unchanged > 0) {
    const id = uniqueId('unchanged');
    const toggle = h('input', { class: 'form-check-input', type: 'checkbox', role: 'switch', id }, [], { checked: false });
    toggle.addEventListener('change', () => container.classList.toggle('page-sync-review--all', toggle.checked));
    container.prepend(h('div', { class: 'form-check form-switch page-sync-review__toggle' }, [
      toggle,
      text('label', label('ui.review.showUnchanged', [unchanged]), { class: 'form-check-label', for: id }),
    ]));
  }
  const error = h('div', { class: 'callout callout-danger', role: 'alert', hidden: true }, [
    h('div', { class: 'callout-content' }, [h('div', { class: 'callout-body' }, [text('p', '')])]),
  ]);
  container.append(error);

  return { container, anyWrites, error };
}

function summarize(results) {
  const total = (key) => results.reduce((sum, result) => sum + (Array.isArray(result[key]) ? result[key].length : Object.keys(result[key] ?? {}).length), 0);
  return label('ui.applied.summary', [total('created'), total('updated'), total('deleted'), total('moved'), total('translated')]);
}

/**
 * @param {{id: string, plans: object[]}} preview the answer of the preview route
 * @param {{title?: string}} [options]
 * @returns {Promise<{applied: boolean, response?: object}>}
 */
export function review(preview, options = {}) {
  return new Promise((resolve) => {
    const state = new ReviewState();
    const { container, anyWrites, error } = renderReview(preview, state);
    let outcome = { applied: false };
    let busy = false;

    const showError = (message) => {
      error.querySelector('p').textContent = message;
      error.hidden = false;
      error.scrollIntoView({ block: 'nearest' });
    };

    const buttons = [{
      text: anyWrites ? label('ui.review.cancel') : label('ui.review.close'),
      btnClass: 'btn-default',
      name: 'cancel',
      trigger: (event, modal) => modal.hideModal(),
    }];
    if (anyWrites) {
      buttons.push({
        text: label('ui.review.apply'),
        btnClass: 'btn-primary',
        name: 'apply',
        icon: 'actions-check',
        trigger: async (event, modal) => {
          if (busy) {
            return;
          }
          busy = true;
          const button = event.currentTarget;
          button.disabled = true;
          button.setAttribute('aria-busy', 'true');
          error.hidden = true;
          try {
            const response = await applyImport(preview.id, state.toDecisions());
            outcome = { applied: true, response };
            const results = response.results ?? [];
            if (response.succeeded) {
              Notification.success(label('ui.applied.title'), summarize(results));
            } else {
              Notification.warning(label('ui.applied.partly'), results.flatMap((result) => result.errors ?? []).join('\n'));
            }
            modal.hideModal();
          } catch (failure) {
            showError(failure?.message || String(failure));
            if (failure?.httpStatus === 409 || failure?.httpStatus === 404) {
              // The preview is outdated or gone: importing it again cannot work.
              outcome = { applied: false, outdated: true };
            } else {
              button.disabled = false;
            }
          } finally {
            button.removeAttribute('aria-busy');
            busy = false;
          }
        },
      });
    }

    const modal = Modal.advanced({
      title: options.title ?? label('ui.review.title'),
      content: container,
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
      staticBackdrop: true,
      buttons,
    });
    modal.addEventListener('typo3-modal-hidden', () => {
      if (!outcome.applied) {
        discardImport(preview.id);
      }
      resolve(outcome);
    }, { once: true });
  });
}
