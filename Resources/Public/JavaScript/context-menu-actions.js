/**
 * Context menu callbacks of the page round trip (PageSyncItemProvider):
 * open the URL the item carries in the content frame.
 */
export default {
  openInContent(table, uid, dataset) {
    const url = dataset.url;
    if (!url) {
      return;
    }
    const backend = (top ?? window).TYPO3?.Backend;
    if (backend?.ContentContainer) {
      backend.ContentContainer.setUrl(url);
    } else {
      window.location.href = url;
    }
  },
};
