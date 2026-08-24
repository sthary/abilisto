<?php
// includes/abilisto_alert.php
// Uniform, Abilisto-branded replacements for native browser alert()/confirm()
// popups (the ones that show "abilisto.site says"). Self-contained: no
// dependency on Font Awesome / Tailwind / any font being loaded on the host
// page, so it's safe to include on any page (auth pages, admin pages, app
// pages) at any directory depth — the logo is referenced by absolute path.
//
// Usage:
//   abilistoAlert('Message here');                 // auto-detects type from emoji
//   abilistoAlert('Message here', 'success');       // 'success' | 'warning' | 'error' | 'info'
//   abilistoAlert('Message here').then(() => { ... }); // run code AFTER user dismisses
//
//   abilistoConfirm('Cancel this booking?').then(ok => { if (ok) { ... } });
//   abilistoConfirm('Delete this?', {danger: true, confirmText: 'Delete'});
//
// window.alert(...) is also overridden to route through abilistoAlert, so
// any call site that doesn't need to sequence code after dismissal needs no
// changes at all. Call sites that redirect/reload after the message must
// use abilistoAlert(...).then(...) explicitly instead of bare alert(...),
// since the custom modal (unlike native alert) does not block script
// execution. window.confirm() is NOT overridden globally (its synchronous
// return value can't be emulated by an async modal) — call sites using
// "return confirm(...)" in an onclick/onsubmit attribute must be converted
// to call abilistoConfirm(...) explicitly and handle the result themselves.
if (!defined('ABILISTO_ALERT_INCLUDED')):
define('ABILISTO_ALERT_INCLUDED', true);
?>
<div class="abilisto-alert-overlay" id="abilistoAlertOverlay">
    <div class="abilisto-alert-box" id="abilistoAlertBox" role="alertdialog" aria-modal="true" aria-labelledby="abilistoAlertTitle">
        <div class="abilisto-alert-brand">
            <img src="/1.png" alt="" class="abilisto-alert-logo">
            <span class="abilisto-alert-wordmark">Abi<span>listo</span></span>
        </div>
        <div class="abilisto-alert-icon" id="abilistoAlertIcon"></div>
        <div class="abilisto-alert-message" id="abilistoAlertMessage"></div>
        <div class="abilisto-alert-actions" id="abilistoAlertActions">
            <button type="button" class="abilisto-alert-btn abilisto-alert-btn-secondary" id="abilistoAlertCancel">Cancel</button>
            <button type="button" class="abilisto-alert-btn abilisto-alert-btn-primary" id="abilistoAlertOk">OK</button>
        </div>
    </div>
</div>
<style>
    .abilisto-alert-overlay {
        position: fixed; inset: 0;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px);
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
        border-radius: 28px;
        padding: 22px 24px 24px;
        text-align: center;
        box-shadow: 0 24px 48px -12px rgba(20,106,245,0.25), 0 8px 20px -8px rgba(0,0,0,0.15);
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
    .abilisto-alert-brand {
        display: flex; align-items: center; justify-content: center; gap: 7px;
        margin-bottom: 16px;
    }
    .abilisto-alert-logo { width: 22px; height: 22px; border-radius: 7px; object-fit: cover; }
    .abilisto-alert-wordmark {
        font-size: 0.95rem; font-weight: 800; letter-spacing: -0.02em;
        color: #0f172a;
    }
    .dark .abilisto-alert-wordmark, html.dark .abilisto-alert-wordmark { color: #f1f5f9; }
    .abilisto-alert-wordmark span { color: #146af5; }
    .abilisto-alert-icon {
        width: 60px; height: 60px;
        margin: 0 auto 16px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: linear-gradient(135deg, #8b5cf6, #146af5);
        color: #fff;
        box-shadow: 0 8px 20px -6px rgba(20,106,245,0.5);
    }
    .abilisto-alert-icon.success { background: linear-gradient(135deg, #34d399, #059669); box-shadow: 0 8px 20px -6px rgba(5,150,105,0.5); }
    .abilisto-alert-icon.warning { background: linear-gradient(135deg, #fbbf24, #d97706); box-shadow: 0 8px 20px -6px rgba(217,119,6,0.5); }
    .abilisto-alert-icon.error   { background: linear-gradient(135deg, #f87171, #dc2626); box-shadow: 0 8px 20px -6px rgba(220,38,38,0.5); }
    .abilisto-alert-icon svg { width: 30px; height: 30px; }
    .abilisto-alert-message {
        font-size: 0.92rem; line-height: 1.55; font-weight: 500;
        color: #334155;
        margin-bottom: 22px;
        white-space: normal;
        word-break: break-word;
    }
    .dark .abilisto-alert-message, html.dark .abilisto-alert-message { color: #cbd5e1; }
    .abilisto-alert-actions { display: flex; gap: 10px; }
    .abilisto-alert-btn {
        flex: 1;
        border: none;
        padding: 13px 20px;
        border-radius: 999px;
        font-size: 0.9rem; font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s;
    }
    .abilisto-alert-btn-primary {
        background: linear-gradient(135deg, #8b5cf6, #146af5);
        color: #fff;
        box-shadow: 0 6px 16px -4px rgba(20,106,245,0.5);
    }
    .abilisto-alert-btn-primary:hover { opacity: 0.92; }
    .abilisto-alert-btn-primary:active { transform: scale(0.97); }
    .abilisto-alert-btn-primary.danger {
        background: linear-gradient(135deg, #f87171, #dc2626);
        box-shadow: 0 6px 16px -4px rgba(220,38,38,0.5);
    }
    .abilisto-alert-btn-secondary {
        background: #f1f5f9;
        color: #475569;
    }
    .dark .abilisto-alert-btn-secondary, html.dark .abilisto-alert-btn-secondary { background: #334155; color: #cbd5e1; }
    .abilisto-alert-btn-secondary:hover { background: #e2e8f0; }
    .dark .abilisto-alert-btn-secondary:hover, html.dark .abilisto-alert-btn-secondary:hover { background: #3f4f66; }
    .abilisto-alert-btn-secondary:active { transform: scale(0.97); }
</style>
<script>
(function () {
    var ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        info:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        question:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
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

    function show(message, opts) {
        return new Promise(function (resolve) {
            var overlay  = document.getElementById('abilistoAlertOverlay');
            var msgEl    = document.getElementById('abilistoAlertMessage');
            var iconEl   = document.getElementById('abilistoAlertIcon');
            var okBtn    = document.getElementById('abilistoAlertOk');
            var cancelBtn = document.getElementById('abilistoAlertCancel');
            if (!overlay) { resolve(opts.isConfirm ? false : undefined); return; }

            // If a previous dialog is still open (rare, but possible with
            // rapid successive calls), resolve it immediately so we don't
            // strand a dangling promise before showing the new one.
            if (activeResolve) { var prev = activeResolve; activeResolve = null; prev(); }

            var resolvedType = opts.type || detectType(message);
            iconEl.className = 'abilisto-alert-icon ' + resolvedType;
            iconEl.innerHTML = ICONS[resolvedType] || ICONS.info;
            msgEl.innerHTML = escapeHtml(String(message)).replace(/\n/g, '<br>');

            okBtn.textContent = opts.confirmText || (opts.isConfirm ? 'Confirm' : 'OK');
            okBtn.classList.toggle('danger', !!opts.danger);
            cancelBtn.style.display = opts.isConfirm ? '' : 'none';
            cancelBtn.textContent = opts.cancelText || 'Cancel';

            overlay.classList.add('open');
            activeResolve = function (val) { resolve(val); };

            function close(result) {
                overlay.classList.remove('open');
                okBtn.removeEventListener('click', onOk);
                cancelBtn.removeEventListener('click', onCancel);
                overlay.removeEventListener('click', onOverlay);
                document.removeEventListener('keydown', onKey);
                activeResolve = null;
                resolve(result);
            }
            function onOk() { close(opts.isConfirm ? true : undefined); }
            function onCancel() { close(false); }
            function onOverlay(e) { if (e.target === overlay) close(opts.isConfirm ? false : undefined); }
            function onKey(e) {
                if (e.key === 'Enter') close(opts.isConfirm ? true : undefined);
                else if (e.key === 'Escape') close(opts.isConfirm ? false : undefined);
            }

            okBtn.addEventListener('click', onOk);
            cancelBtn.addEventListener('click', onCancel);
            overlay.addEventListener('click', onOverlay);
            document.addEventListener('keydown', onKey);
            setTimeout(function () { okBtn.focus(); }, 50);
        });
    }

    window.abilistoAlert = function (message, type) {
        return show(message, { type: type, isConfirm: false });
    };

    // Returns a Promise<boolean> - true if the user confirmed, false if
    // cancelled/dismissed. opts: {danger, confirmText, cancelText, type}
    window.abilistoConfirm = function (message, opts) {
        opts = opts || {};
        return show(message, {
            type: opts.type || 'question',
            isConfirm: true,
            danger: opts.danger,
            confirmText: opts.confirmText,
            cancelText: opts.cancelText
        });
    };

    // Drop-in replacement for every existing bare alert(...) call site that
    // doesn't need to sequence code after dismissal (the vast majority).
    window.alert = function (message) { window.abilistoAlert(message); };
})();
</script>
<?php endif; ?>
