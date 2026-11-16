// Opens the side panel when the toolbar icon is clicked, and fetches order files for the
// content script (the content script cannot fetch cross-origin files itself).
chrome.runtime.onInstalled.addListener(() => {
  chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
});

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg?.type === 'whoami') { reply({ tabId: sender.tab?.id ?? null }); return false; }
  if (msg?.type !== 'fetchFile') return false;
  (async () => {
    try {
      const res = await fetch(msg.url);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const blob = await res.blob();
      const buf = new Uint8Array(await blob.arrayBuffer());
      let bin = '';
      for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode(...buf.subarray(i, i + 0x8000));
      const name = decodeURIComponent(new URL(msg.url).pathname.split('/').pop() || 'file');
      reply({ ok: true, name, mime: blob.type || 'application/octet-stream', b64: btoa(bin) });
    } catch (e) {
      reply({ ok: false, error: String(e.message || e) });
    }
  })();
  return true; // async reply
});
