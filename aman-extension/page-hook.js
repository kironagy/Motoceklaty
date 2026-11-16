// Runs in the page's own world (not the extension's) so it can see the portal's alert()/confirm()
// messages, e.g. "the customer was rejected before". It only reports them; the dialog still shows.
(() => {
  if (window.__amanHook) return;
  window.__amanHook = true;
  const fire = (kind, msg) => window.postMessage({ __amanDialog: true, kind, msg: String(msg ?? ''), at: Date.now() }, '*');
  const alert0 = window.alert, confirm0 = window.confirm;
  window.alert = function (m) { fire('alert', m); return alert0.apply(this, arguments); };
  window.confirm = function (m) { fire('confirm', m); return confirm0.apply(this, arguments); };

  // Clicks asked for by the extension run here, in the page's own world: Chrome does not let an
  // extension follow "javascript:__doPostBack(...)" links (Aman's "تأكيد صورة البطاقة").
  window.addEventListener('message', (e) => {
    if (e.source !== window || !e.data?.__amanClick) return;
    const el = document.querySelector('[data-aman-click="' + e.data.token + '"]');
    if (!el) return;
    el.removeAttribute('data-aman-click');
    const href = el.tagName === 'A' ? (el.getAttribute('href') || '') : '';
    if (/^javascript:/i.test(href)) {
      // Run Aman's "javascript:WebForm_DoPostBackWithOptions(...)" directly in the page.
      try { new Function(decodeURIComponent(href.slice(11)))(); return; } catch (err) { /* fall back to a click */ }
    }
    el.click();
  });

  // Marks ASP.NET async postbacks (UpdatePanel) so the extension waits while dependent lists reload.
  setInterval(() => {
    let busy = false;
    try { busy = !!window.Sys?.WebForms?.PageRequestManager?.getInstance()?.get_isInAsyncPostBack(); } catch { /* not an ASP.NET AJAX page */ }
    if (window.__amanFakeBusy) busy = true;
    const v = busy ? '1' : '0';
    if (document.documentElement.dataset.amanBusy !== v) document.documentElement.dataset.amanBusy = v;
  }, 150);
})();
