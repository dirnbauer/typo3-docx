function routes() {
  return globalThis.TYPO3?.settings?.ajaxUrls ?? {};
}

async function requestJson(url, options = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
      ...(options.headers ?? {}),
    },
    ...options,
  });
  let data = {};
  try {
    data = await response.json();
  } catch {
    data = { ok: false, error: 'Invalid server response.' };
  }
  if (!response.ok && data.ok !== false) {
    data.ok = false;
    data.error = data.error || response.statusText;
  }
  data.httpStatus = response.status;
  return data;
}

export async function loadDocument(fileIdentifier) {
  const url = routes().docx_editor_document_load;
  if (!url) {
    throw new Error('docx_editor_document_load route is not registered.');
  }
  const requestUrl = new URL(url, window.location.origin);
  requestUrl.searchParams.set('file', fileIdentifier);
  const data = await requestJson(requestUrl.toString());
  if (!data.ok) {
    throw new Error(data.error || 'Load failed.');
  }
  return data;
}

export async function saveDocument(fileIdentifier, revision, arrayBuffer) {
  const url = routes().docx_editor_document_save;
  if (!url) {
    throw new Error('docx_editor_document_save route is not registered.');
  }
  const bytes = new Uint8Array(arrayBuffer);
  let binary = '';
  const chunkSize = 0x8000;
  for (let i = 0; i < bytes.length; i += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
  }
  const data = await requestJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      file: fileIdentifier,
      revision,
      data: btoa(binary),
    }),
  });
  if (!data.ok) {
    const error = new Error(data.error || 'Save failed.');
    error.httpStatus = data.httpStatus;
    throw error;
  }
  return data;
}

export async function joinSession(fileIdentifier) {
  const url = routes().docx_editor_collab_join;
  return requestJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ file: fileIdentifier }),
  });
}

export async function heartbeatSession(fileIdentifier, sessionUid) {
  const url = routes().docx_editor_collab_heartbeat;
  return requestJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ file: fileIdentifier, sessionUid }),
  });
}

export async function leaveSession(fileIdentifier, sessionUid) {
  const url = routes().docx_editor_collab_leave;
  if (!url || !sessionUid) {
    return { ok: true };
  }
  return requestJson(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ file: fileIdentifier, sessionUid }),
  });
}

export async function fetchRevision(fileIdentifier) {
  const url = routes().docx_editor_collab_revision;
  const requestUrl = new URL(url, window.location.origin);
  requestUrl.searchParams.set('file', fileIdentifier);
  return requestJson(requestUrl.toString());
}

export function decodeBase64ToArrayBuffer(base64) {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}
