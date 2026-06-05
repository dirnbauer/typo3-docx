/**
 * TYPO3 backend CSP blocks fonts.googleapis.com / fonts.gstatic.com.
 * eigenpal/docx-editor loads Office fonts via Google Fonts; skip those requests
 * and rely on system / DOCX-embedded fonts instead.
 */
(function installExternalFontCspGuard() {
  if (typeof document === 'undefined') {
    return;
  }

  const blockedHost = (hostname) =>
    hostname === 'fonts.googleapis.com' || hostname === 'fonts.gstatic.com';

  const isBlockedStylesheetUrl = (url) => {
    if (!url) {
      return false;
    }
    try {
      return blockedHost(new URL(url, window.location.href).hostname);
    } catch {
      return false;
    }
  };

  const shouldBlockLink = (node) => {
    if (!node || node.tagName !== 'LINK') {
      return false;
    }
    const rel = (node.rel || '').toLowerCase();
    if (!rel.includes('stylesheet')) {
      return false;
    }
    return isBlockedStylesheetUrl(node.href || node.getAttribute('href') || '');
  };

  const patchParent = (parent) => {
    if (!parent || parent.__docxEditorFontGuard) {
      return;
    }
    parent.__docxEditorFontGuard = true;

    const nativeAppend = parent.appendChild.bind(parent);
    parent.appendChild = function appendChild(child) {
      if (shouldBlockLink(child)) {
        return child;
      }
      return nativeAppend(child);
    };

    const nativeInsertBefore = parent.insertBefore.bind(parent);
    parent.insertBefore = function insertBefore(child, reference) {
      if (shouldBlockLink(child)) {
        return child;
      }
      return nativeInsertBefore(child, reference);
    };
  };

  patchParent(document.head);
  patchParent(document.documentElement);
})();
