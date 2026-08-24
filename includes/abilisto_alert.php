<?php
// includes/abilisto_alert.php
// Uniform, Abilisto-branded replacement for the native browser alert() popup
// (the one that shows "abilisto.site says"). Self-contained: no dependency
// on Font Awesome / Tailwind / any font being loaded on the host page, so
// it's safe to include on any page (auth pages, admin pages, app pages).
//
// Usage:
//   abilistoAlert('Message here');                 // auto-detects type from emoji
//   abilistoAlert('Message here', 'success');       // 'success' | 'warning' | 'error' | 'info'
//   abilistoAlert('Message here').then(() => { ... }); // run code AFTER user dismisses
//
// window.alert(...) is also overridden to route through this modal, so any
// call site that doesn't need to sequence code after dismissal needs no
// changes at all. Call sites that redirect/reload after the message must
// use abilistoAlert(...).then(...) explicitly instead of bare alert(...),
// since the custom modal (unlike native alert) does not block script
// execution.
if (!defined('ABILISTO_ALERT_INCLUDED')):
define('ABILISTO_ALERT_INCLUDED', true);
?>
<div class="abilisto-alert-overlay" id="abilistoAlertOverlay">
    <div class="abilisto-alert-box" id="abilistoAlertBox" role="alertdialog" aria-modal="true" aria-labelledby="abilistoAlertTitle">
        <div class="abilisto-alert-icon" id="abilistoAlertIcon"></div>
        <div class="abilisto-alert-title" id="abilistoAlertTitle">Abilisto</div>
        <div class="abilisto-alert-message" id="abilistoAlertMessage"></div>
        <button type="button" class="abilisto-alert-ok" id="abilistoAlertOk">OK</button>
    </div>
</div>
<style>
    .abilisto-alert-overlay {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.5);
        backdrop-filter: blur(2px);
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
        opacity: 0; visibility: hidden;
        transition: opacity 0.2s ease, visibility 0.2s ease;
        z-index: 100000;
    }
    .abilisto-alert-overlay.open { opacity: 1; visibility: visible; }
    .abilisto-alert-box {
        background: #fff;
        color: #0f172a;
        width: 100%; max-width: 360px;
        border-radius: 24px;
        padding: 28px 24px 20px;
        text-align: center;
        box-shadow: 0 24px 48px -12px rgba(0,0,0,0.25);
        font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        transform: scale(0.92) translateY(8px);
        opacity: 0;
        transition: transform 0.25s cubic-bezier(.34,1.56,.64,1), opacity 0.2s ease;
    }
    .abilisto-alert-overlay.open .abilisto-alert-box { transform: scale(1) translateY(0); opacity: 1; }
    .dark .abilisto-alert-box, html.dark .abilisto-alert-box {
        background: #1e293b;
        color: #f1f5f9;
    }
    .abilisto-alert-icon {
        width: 56px; height: 56px;
        margin: 0 auto 14px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: #eff6ff;
        color: #146af5;
    }
    .dark .abilisto-alert-icon, html.dark .abilisto-alert-icon { background: rgba(20,106,245,0.15); }
    .abilisto-alert-icon.success { background: #ecfdf5; color: #059669; }
    .dark .abilisto-alert-icon.success, html.dark .abilisto-alert-icon.success { background: rgba(5,150,105,0.15); }
    .abilisto-alert-icon.warning { background: #fffbeb; color: #d97706; }
    .dark .abilisto-alert-icon.warning, html.dark .abilisto-alert-icon.warning { background: rgba(217,119,6,0.15); }
    .abilisto-alert-icon.error { background: #fef2f2; color: #dc2626; }
    .dark .abilisto-alert-icon.error, html.dark .abilisto-alert-icon.error { background: rgba(220,38,38,0.15); }
    .abilisto-alert-icon svg { width: 28px; height: 28px; }
    .abilisto-alert-title {
        font-size: 1.05rem; font-weight: 800; letter-spacing: -0.02em;
        margin-bottom: 8px;
        color: #0f172a;
    }
    .dark .abilisto-alert-title, html.dark .abilisto-alert-title { color: #f1f5f9; }
    .abilisto-alert-title span { color: #146af5; }
    .abilisto-alert-message {
        font-size: 0.9rem; line-height: 1.55;
        color: #475569;
        margin-bottom: 20px;
        white-space: normal;
        word-break: break-word;
    }
    .dark .abilisto-alert-message, html.dark .abilisto-alert-message { color: #94a3b8; }
    .abilisto-alert-ok {
        width: 100%;
        background: #146af5;
        color: #fff;
        border: none;
        padding: 12px 20px;
        border-radius: 999px;
        font-size: 0.9rem; font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        transition: background 0.15s, transform 0.1s;
    }
    .abilisto-alert-ok:hover { background: #0f57d1; }
    .abilisto-alert-ok:active { transform: scale(0.97); }
</style>
<script>
(function () {
    var ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };

    function detectType(message) {
        var m = String(message);
        if (/^(✅|✔️?)/.test(m)) return 'success';
        if (/^(🚫|❌|DATABASE ERROR|Error)/i.test(m)) return 'error';
        if (/^(⚠️?)/.test(m)) return 'warning';
        return 'info';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    var activeResolve = null;

    window.abilistoAlert = function (message, type) {
        return new Promise(function (resolve) {
            var overlay = document.getElementById('abilistoAlertOverlay');
            var box     = document.getElementById('abilistoAlertBox');
            var msgEl   = document.getElementById('abilistoAlertMessage');
            var iconEl  = document.getElementById('abilistoAlertIcon');
            var okBtn   = document.getElementById('abilistoAlertOk');
            if (!overlay) { resolve(); return; }

            // If a previous alert is still open (rare, but possible with
            // rapid successive calls), resolve it immediately so we don't
            // strand a dangling promise before showing the new one.
            if (activeResolve) { var prev = activeResolve; activeResolve = null; prev(); }

            var resolvedType = type || detectType(message);
            iconEl.className = 'abilisto-alert-icon ' + resolvedType;
            iconEl.innerHTML = ICONS[resolvedType] || ICONS.info;
            msgEl.innerHTML = escapeHtml(String(message)).replace(/\n/g, '<br>');

            overlay.classList.add('open');
            activeResolve = resolve;

            function close() {
                overlay.classList.remove('open');
                okBtn.removeEventListener('click', onOk);
                overlay.removeEventListener('click', onOverlay);
                document.removeEventListener('keydown', onKey);
                activeResolve = null;
                resolve();
            }
            function onOk() { close(); }
            function onOverlay(e) { if (e.target === overlay) close(); }
            function onKey(e) { if (e.key === 'Enter' || e.key === 'Escape') close(); }

            okBtn.addEventListener('click', onOk);
            overlay.addEventListener('click', onOverlay);
            document.addEventListener('keydown', onKey);
            setTimeout(function () { okBtn.focus(); }, 50);
        });
    };

    // Drop-in replacement for every existing bare alert(...) call site that
    // doesn't need to sequence code after dismissal (the vast majority).
    window.alert = function (message) { window.abilistoAlert(message); };
})();
</script>
<?php endif; ?>
