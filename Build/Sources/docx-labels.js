/**
 * Read translated UI labels from the TYPO3 Fluid shell (#docx-editor-app).
 */

const HEADING_LABEL_DATASET_MAP = {
  group: 'labelHeadingGroup',
  heading1: 'labelHeading1',
  heading2: 'labelHeading2',
  heading3: 'labelHeading3',
  heading4: 'labelHeading4',
  heading1Title: 'labelHeading1Title',
  heading2Title: 'labelHeading2Title',
  heading3Title: 'labelHeading3Title',
  heading4Title: 'labelHeading4Title',
};

/**
 * @param {HTMLElement | null | undefined} app
 * @returns {Record<string, string>}
 */
export function readAppLabels(app) {
  if (!app?.dataset) {
    return {};
  }
  return { ...app.dataset };
}

/**
 * @param {HTMLElement | null | undefined} app
 * @returns {Record<string, string | undefined>}
 */
export function readHeadingLabels(app) {
  const raw = app?.dataset?.headingLabels;
  if (raw) {
    try {
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === 'object') {
        return parsed;
      }
    } catch {
      // Fall back to legacy per-key dataset attributes.
    }
  }

  const dataset = app?.dataset ?? {};
  return Object.fromEntries(
    Object.entries(HEADING_LABEL_DATASET_MAP).map(([key, datasetKey]) => [
      key,
      dataset[datasetKey],
    ]),
  );
}
