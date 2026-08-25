<?php
// Lets includes/navbar.php know it's safe to render the Abi trigger
// buttons (they call window.abiToggle, which only this file defines).
// This must run before navbar.php's own PHP executes on pages where
// navbar.php is included first — see the include order note there.
define('ABI_WIDGET_INCLUDED', true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
  <title>Abi Widget</title>
  <style>
    div[data-orbit-widget="true"] * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    /* ========== ABI NAV ORB — the small animated logo used for the
       trigger button embedded in the desktop nav (beside "More") and
       the mobile hamburger sidebar (above "Profile"). Same animation
       as before, just sized for an inline nav icon instead of a large
       floating drag handle. ========== */
    .abi-nav-orb {
      position: relative;
      display: inline-block;
      width: 16px;
      height: 16px;
      flex-shrink: 0;
    }
    .abi-nav-orb-ball {
      position: absolute;
      inset: 0;
      border-radius: 50%;
      background: linear-gradient(145deg, #5a4ed0, #8d3dd7);
      border: 1px solid rgba(255, 255, 255, 0.3);
      animation: orbit-ballPulse 10s ease-in-out infinite;
    }
    .abi-nav-orb-ring {
      position: absolute;
      width: 24px;
      height: 24px;
      top: 50%;
      left: 50%;
      margin-left: -12px;
      margin-top: -12px;
      border-radius: 50%;
      border: 1.5px solid #8d6de8;
      animation: orbit-ringPulse 10s ease-in-out infinite;
      background: transparent;
      pointer-events: none;
    }
    .abi-nav-orb-scaler {
      position: absolute;
      width: 24px;
      height: 24px;
      top: 50%;
      left: 50%;
      margin-left: -12px;
      margin-top: -12px;
      animation: orbit-ringPulse 10s ease-in-out infinite;
      pointer-events: none;
    }
    .abi-nav-orb-spinner {
      position: absolute;
      inset: 0;
      animation: orbit-spin 8s linear infinite;
      pointer-events: none;
    }
    .abi-nav-orb-dot {
      position: absolute;
      width: 3px;
      height: 3px;
      background: linear-gradient(145deg, #ffffff, #d0aeff);
      border: 1px solid #a14ef0;
      border-radius: 50%;
      top: -1.5px;
      left: 50%;
      transform: translateX(-50%);
      pointer-events: none;
    }

    @keyframes orbit-ballPulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.22); }
    }
    @keyframes orbit-ringPulse {
      0%, 100% { transform: scale(1); border-color: #8d6de8; }
      50% { transform: scale(1.20); border-color: #c493ff; }
    }
    @keyframes orbit-spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    /* ========== BACKDROP ========== */
    div[data-orbit-widget="true"] .orbit-overlay {
      position: fixed !important;
      inset: 0 !important;
      background: rgba(15,23,42,0.45) !important;
      opacity: 0 !important;
      visibility: hidden !important;
      transition: opacity 0.3s ease, visibility 0.3s ease !important;
      z-index: 999997 !important;
    }
    div[data-orbit-widget="true"] .orbit-overlay.open {
      opacity: 1 !important;
      visibility: visible !important;
    }

    /* ========== CHAT WINDOW — full-screen sheet on mobile, a docked
       side panel on desktop (always right-anchored, sliding in from
       the right edge — see applyLayout() below). top:0 + height:100%
       (not bottom:0 + vh) because position:fixed elements anchored via
       `bottom` can end up pinned behind the mobile keyboard on iOS
       Safari — top+height is the side that stays correct. JS
       additionally syncs height/top to the visualViewport on keyboard
       open/close since vh/dvh units alone don't reliably shrink for
       the keyboard on every mobile browser. ========== */
    div[data-orbit-widget="true"] .orbit-window {
      position: fixed !important;
      left: 0 !important;
      right: 0 !important;
      top: 0 !important;
      bottom: auto !important;
      width: 100% !important;
      height: 100% !important;
      height: 100dvh !important;
      background: #d9ecff !important;
      border: none !important;
      display: flex !important;
      flex-direction: column !important;
      overflow: hidden !important;
      pointer-events: none !important;
      visibility: hidden !important;
      transition: transform 0.35s cubic-bezier(.4,0,.2,1), visibility 0.35s !important;
      z-index: 999998 !important;
      color: #162b3a !important;
      box-shadow: 0 -12px 30px -8px rgba(80,104,176,0.5) !important;
      transform: translateY(100%) !important;
    }

    div[data-orbit-widget="true"] .orbit-window.open {
      pointer-events: auto !important;
      transform: translateY(0) !important;
      visibility: visible !important;
    }

    /* ---- header with Beta badge + close (x) button on RIGHT side ---- */
    div[data-orbit-widget="true"] .orbit-window .o-header {
      padding: 14px 16px 10px !important;
      display: flex !important;
      align-items: center !important;
      gap: 10px !important;
      border-bottom: 1px solid #a38ef0 !important;
      flex-shrink: 0 !important;
      background: rgba(255, 255, 255, 0.3) !important;
      position: relative !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-header-orb {
      position: relative !important;
      width: 26px !important;
      height: 26px !important;
      flex-shrink: 0 !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-header-ball {
      position: absolute !important;
      inset: 0 !important;
      border-radius: 50% !important;
      background: linear-gradient(145deg, #5a4ed0, #8d3dd7) !important;
      border: 1px solid rgba(255,255,255,0.3) !important;
      animation: orbit-ballPulse 10s ease-in-out infinite !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-header-ring {
      position: absolute !important;
      width: 38px !important;
      height: 38px !important;
      top: 50% !important;
      left: 50% !important;
      margin-left: -19px !important;
      margin-top: -19px !important;
      border-radius: 50% !important;
      border: 2px solid #8d6de8 !important;
      animation: orbit-headerRingPulse 10s ease-in-out infinite !important;
      background: transparent !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-header-scaler {
      position: absolute !important;
      width: 38px !important;
      height: 38px !important;
      top: 50% !important;
      left: 50% !important;
      margin-left: -19px !important;
      margin-top: -19px !important;
      animation: orbit-headerRingPulse 10s ease-in-out infinite !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-header-spinner {
      position: absolute !important;
      inset: 0 !important;
      animation: orbit-spin 8s linear infinite !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-header-dot {
      position: absolute !important;
      width: 5px !important;
      height: 5px !important;
      background: linear-gradient(145deg, #ffffff, #d0aeff) !important;
      border: 1px solid #a855f7 !important;
      border-radius: 50% !important;
      top: -2.5px !important;
      left: 50% !important;
      transform: translateX(-50%) !important;
    }

    @keyframes orbit-headerRingPulse {
      0%, 100% { transform: scale(1); border-color: #8d6de8; }
      50% { transform: scale(1.18); border-color: #c493ff; }
    }

    /* Title area: Abi + Beta badge (flex to take space) */
    .orbit-title-container {
      display: flex !important;
      align-items: baseline !important;
      gap: 6px !important;
      flex: 1 !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-header h4 {
      font-size: 0.95rem !important;
      font-weight: 600 !important;
      color: #2d1b55 !important;
      margin: 0 !important;
      padding: 0 !important;
      background: none !important;
      line-height: normal !important;
      text-transform: none !important;
      letter-spacing: normal !important;
    }

    /* Beta badge (pill) */
    .beta-badge {
      background: linear-gradient(135deg, #8b5cf6, #c084fc) !important;
      color: white !important;
      font-size: 0.65rem !important;
      font-weight: 600 !important;
      padding: 2px 8px !important;
      border-radius: 20px !important;
      letter-spacing: 0.3px !important;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
      display: inline-block !important;
      line-height: 1.2 !important;
    }

    /* Close button (X) on RIGHT side */
    .orbit-close-widget {
      width: 28px !important;
      height: 28px !important;
      border-radius: 50% !important;
      background: rgba(100, 70, 150, 0.2) !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      cursor: pointer !important;
      transition: all 0.2s ease !important;
      flex-shrink: 0 !important;
      margin-left: auto !important;
      color: #4c3792 !important;
      font-size: 18px !important;
      font-weight: 500 !important;
      border: none !important;
    }

    .orbit-close-widget:hover {
      background: rgba(100, 70, 150, 0.4) !important;
      transform: scale(1.05) !important;
    }

    /* temporary floating note (appears only when modal opens, disappears after 5 sec) */
    .orbit-temp-note {
      position: fixed !important;
      bottom: 90px !important;
      right: 30px !important;
      background: #1e1a2f !important;
      color: #f0eaff !important;
      padding: 8px 16px !important;
      border-radius: 40px !important;
      font-size: 0.8rem !important;
      font-family: 'Plus Jakarta Sans', system-ui, sans-serif !important;
      z-index: 1000001 !important;
      box-shadow: 0 8px 20px rgba(0,0,0,0.2) !important;
      backdrop-filter: blur(4px) !important;
      background: rgba(30, 26, 47, 0.92) !important;
      border-left: 3px solid #b77cff !important;
      pointer-events: none !important;
      white-space: nowrap !important;
      animation: orbit-fadeSlide 0.25s ease-out !important;
    }

    @keyframes orbit-fadeSlide {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* ===== MESSAGES AREA ===== */
    div[data-orbit-widget="true"] .orbit-window .o-messages {
      flex: 1 !important;
      overflow-y: auto !important;
      overflow-x: hidden !important;
      padding: 16px !important;
      display: flex !important;
      flex-direction: column !important;
      gap: 12px !important;
      background: #e3f0ff !important;
      min-height: 0 !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-messages::-webkit-scrollbar { width: 4px !important; }
    div[data-orbit-widget="true"] .orbit-window .o-messages::-webkit-scrollbar-thumb {
      background: #7f6ad6 !important;
      border-radius: 20px !important;
    }

    div[data-orbit-widget="true"] .orbit-window .msg {
      max-width: 85% !important;
      width: auto !important;
      height: auto !important;
      min-height: 0 !important;
      max-height: none !important;
      padding: 10px 14px !important;
      border-radius: 18px !important;
      font-size: 0.85rem !important;
      line-height: 1.5 !important;
      animation: orbit-msgSlide 0.2s ease !important;
      word-wrap: break-word !important;
      overflow-wrap: break-word !important;
      word-break: break-word !important;
      white-space: pre-wrap !important;
      margin: 0 !important;
      background: none !important;
      box-shadow: none !important;
      text-shadow: none !important;
      flex-shrink: 0 !important;
    }

    @keyframes orbit-msgSlide {
      from { opacity: 0; transform: translateY(5px); }
      to { opacity: 1; transform: translateY(0); }
    }

    div[data-orbit-widget="true"] .orbit-window .msg.incoming {
      align-self: flex-start !important;
      background: #ffffff !important;
      border: 1px solid #8f7be0 !important;
      border-bottom-left-radius: 4px !important;
      color: #1e1a3b !important;
    }

    div[data-orbit-widget="true"] .orbit-window .msg.outgoing {
      align-self: flex-end !important;
      background: linear-gradient(145deg, #5a4ed0, #8d3dd7) !important;
      border: 1px solid #b38eff !important;
      border-bottom-right-radius: 4px !important;
      color: white !important;
    }

    div[data-orbit-widget="true"] .orbit-window .thinking-indicator {
      display: flex !important;
      gap: 4px !important;
      padding: 8px 14px !important;
      align-self: flex-start !important;
      background: #ffffff !important;
      border: 1px solid #8f7be0 !important;
      border-radius: 18px !important;
      border-bottom-left-radius: 4px !important;
      margin: 0 !important;
    }

    div[data-orbit-widget="true"] .orbit-window .thinking-indicator span {
      width: 6px !important;
      height: 6px !important;
      background: #7c5fcf !important;
      border-radius: 50% !important;
      animation: orbit-bounce 1.2s infinite !important;
      display: inline-block !important;
      position: static !important;
      margin: 0 !important;
    }

    div[data-orbit-widget="true"] .orbit-window .thinking-indicator span:nth-child(2) { animation-delay: 0.2s !important; }
    div[data-orbit-widget="true"] .orbit-window .thinking-indicator span:nth-child(3) { animation-delay: 0.4s !important; }

    @keyframes orbit-bounce {
      0%,100% { transform: translateY(0); opacity: 0.5; }
      50% { transform: translateY(-5px); opacity: 1; }
    }

    /* ---- input area ---- */
    div[data-orbit-widget="true"] .orbit-window .o-input-bar {
      padding: 12px 16px 16px !important;
      display: flex !important;
      gap: 10px !important;
      border-top: 1px solid #9b89e0 !important;
      flex-shrink: 0 !important;
      background: #d1e6ff !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-input-bar input {
      flex: 1 !important;
      background: #ffffff !important;
      border: 1px solid #8b75d9 !important;
      border-radius: 30px !important;
      padding: 10px 16px !important;
      outline: none !important;
      color: #1b1b3a !important;
      font-size: 0.85rem !important;
      font-family: inherit !important;
      margin: 0 !important;
      height: auto !important;
      line-height: normal !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-input-bar input::placeholder {
      color: #7062b0 !important;
      opacity: 1 !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-send {
      width: 38px !important;
      height: 38px !important;
      border-radius: 50% !important;
      background: linear-gradient(145deg, #5a4ed0, #8d3dd7) !important;
      border: 1.5px solid #c7a6ff !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      cursor: pointer !important;
      transition: opacity 0.2s !important;
      padding: 0 !important;
      margin: 0 !important;
      box-shadow: none !important;
    }

    div[data-orbit-widget="true"] .orbit-window .o-send:hover { opacity: 0.85 !important; }

    div[data-orbit-widget="true"] .orbit-window .o-send svg {
      width: 16px !important;
      height: 16px !important;
      fill: white !important;
      display: block !important;
      position: static !important;
    }
  </style>
</head>
<body>

  <div data-orbit-widget="true" id="orbitRoot">
    <!-- BACKDROP -->
    <div class="orbit-overlay" id="orbitOverlay"></div>

    <!-- CHAT WINDOW -->
    <div class="orbit-window" id="win">
      <div class="o-header">
        <div class="o-header-orb">
          <div class="o-header-ball"></div>
          <div class="o-header-ring"></div>
          <div class="o-header-scaler">
            <div class="o-header-spinner" id="headerSpinner">
              <div class="o-header-dot"></div>
            </div>
          </div>
        </div>
        <div class="orbit-title-container">
          <h4>Abi</h4>
          <span class="beta-badge">Beta</span>
        </div>
        <!-- (x) button on RIGHT side of modal -->
        <div class="orbit-close-widget" id="orbitCloseBtn" title="Remove Abi widget (reappears after refresh)">✕</div>
      </div>

      <div class="o-messages" id="msgs">
        <div class="msg incoming">✨ Hi, I'm Abi, your virtual assistant. How can I help you today?</div>
      </div>

      <div class="o-input-bar">
        <input type="text" id="inp" placeholder="Message..." autocomplete="off">
        <div class="o-send" id="sendBtn">
          <svg viewBox="0 0 24 24">
            <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/>
          </svg>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const root    = document.getElementById('orbitRoot');
      const win     = document.getElementById('win');
      const overlay = document.getElementById('orbitOverlay');
      const inp     = document.getElementById('inp');
      const sendBtn = document.getElementById('sendBtn');
      const msgs    = document.getElementById('msgs');
      const closeWidgetBtn = document.getElementById('orbitCloseBtn');

      if (!root || !win || !overlay || !inp || !sendBtn || !msgs || !closeWidgetBtn) return;

      /* ── SHEET OPEN/CLOSE — also locks body scroll while open so a
         focused input's keyboard doesn't drag the whole page up with it
         (the classic mobile-Safari/Chrome fixed-element-plus-focus bug). */
      let scrollY = 0;

      /* Keep the sheet's actual pixel height/offset synced to the visual
         viewport (the visible area excluding an open keyboard) rather than
         trusting vh/dvh alone — dvh doesn't shrink for the keyboard on
         every mobile browser, and a `bottom:0` fixed element can end up
         pinned behind the keyboard on iOS Safari specifically. This is
         what keeps the header and input bar actually stuck to the visible
         top/bottom instead of scrolling away with the page. */
      function syncViewportHeight() {
        if (!window.visualViewport) return;
        const vv = window.visualViewport;
        win.style.setProperty('height', vv.height + 'px', 'important');
        win.style.setProperty('top', vv.offsetTop + 'px', 'important');
      }
      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncViewportHeight);
        window.visualViewport.addEventListener('scroll', syncViewportHeight);
      }

      function isDesktop() {
        return window.matchMedia('(min-width: 769px)').matches;
      }

      /* ── LAYOUT: full-screen bottom sheet on mobile, a 30%-wide side
         panel on desktop. The trigger now lives in a fixed spot (nav bar
         on desktop, hamburger sidebar on mobile) instead of a draggable
         floating ball, so the panel always docks to the same edge —
         right — and always slides in right-to-left. */
      function applyLayout(closed) {
        if (isDesktop()) {
          win.style.setProperty('top', '0', 'important');
          win.style.setProperty('bottom', 'auto', 'important');
          win.style.setProperty('height', '100%', 'important');
          win.style.setProperty('width', '30%', 'important');
          win.style.setProperty('min-width', '340px', 'important');
          win.style.setProperty('max-width', '480px', 'important');
          win.style.setProperty('border-radius', '0', 'important');
          win.style.setProperty('left', 'auto', 'important');
          win.style.setProperty('right', '0', 'important');
          win.style.setProperty('transform', closed ? 'translateX(100%)' : 'translateX(0)', 'important');
        } else {
          win.style.setProperty('left', '0', 'important');
          win.style.setProperty('right', '0', 'important');
          win.style.setProperty('bottom', 'auto', 'important');
          win.style.setProperty('width', '100%', 'important');
          win.style.setProperty('min-width', '0', 'important');
          win.style.setProperty('max-width', 'none', 'important');
          win.style.setProperty('border-radius', '0', 'important');
          win.style.setProperty('transform', closed ? 'translateY(100%)' : 'translateY(0)', 'important');
          syncViewportHeight();
        }
      }

      function openSheet() {
        win.classList.add('open');
        overlay.classList.add('open');
        win.style.setProperty('visibility', 'visible', 'important');
        win.style.setProperty('pointer-events', 'auto', 'important');
        applyLayout(false);
        scrollY = window.scrollY;
        document.body.style.position = 'fixed';
        document.body.style.top = -scrollY + 'px';
        document.body.style.width = '100%';
      }
      function closeSheet() {
        win.classList.remove('open');
        overlay.classList.remove('open');
        applyLayout(true);
        win.style.setProperty('visibility', 'hidden', 'important');
        win.style.setProperty('pointer-events', 'none', 'important');
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        window.scrollTo(0, scrollY);
      }
      overlay.addEventListener('click', closeSheet);
      window.addEventListener('resize', () => { if (win.classList.contains('open')) applyLayout(false); });

      /* ── GLOBAL TOGGLE — called by the Abi trigger buttons that live in
         includes/navbar.php (desktop nav beside "More", mobile hamburger
         sidebar above "Profile"). Exposed globally since those buttons
         are wired from navbar.php's own script, which may run before or
         after this one depending on include order; by only *calling*
         window.abiToggle at click time (long after both scripts have
         run) the order never matters. ── */
      window.abiToggle = function () {
        if (win.classList.contains('open')) {
          closeSheet();
        } else {
          openSheet();
          showTempNote();
          inp.focus();
        }
      };

      /* ── CLOSE BUTTON (X) — removes the Abi widget from the page
         entirely (reappears after relogin/refresh), and disables the
         external nav trigger buttons to match since they're the only
         way to reopen it. ── */
      function removeOrbitWidget() {
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        if (root && root.remove) {
          root.remove();
        } else if (root && root.parentNode) {
          root.parentNode.removeChild(root);
        }
        document.querySelectorAll('.abi-nav-toggler').forEach(function (btn) {
          btn.style.opacity = '0.4';
          btn.style.pointerEvents = 'none';
          btn.setAttribute('aria-disabled', 'true');
        });
        window.abiToggle = function () {};
      }
      closeWidgetBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        removeOrbitWidget();
      });

      /* ── TEMPORARY NOTE (appears only when modal is opened, disappears after 5 seconds) ── */
      let activeNote = null;
      function showTempNote() {
        // Remove any existing note first to avoid stacking
        if (activeNote && activeNote.remove) {
          activeNote.remove();
          activeNote = null;
        }
        const note = document.createElement('div');
        note.className = 'orbit-temp-note';
        note.textContent = '⚠️ Abi makes mistakes — please verify important info';
        document.body.appendChild(note);
        activeNote = note;
        setTimeout(() => {
          if (note && note.remove) {
            note.remove();
            if (activeNote === note) activeNote = null;
          }
        }, 5000);
      }

      /* ── MESSAGES ───────────────────────────────────────────── */
      // Conversation memory — previously every message was sent in
      // isolation with no memory of what was said earlier in the same
      // chat. Kept client-side only (not persisted), same pattern
      // GreenLoop AI's chat already uses.
      let chatHistory = [];

      function appendMessage(text, type) {
        const el = document.createElement('div');
        if (type === 'thinking') {
          el.classList.add('thinking-indicator');
          el.innerHTML = '<span></span><span></span><span></span>';
        } else {
          el.classList.add('msg', type);
          el.textContent = text;
        }
        msgs.appendChild(el);
        msgs.scrollTop = msgs.scrollHeight;
        return el;
      }

      async function sendMessage() {
        const text = inp.value.trim();
        if (!text) return;
        appendMessage(text, 'outgoing');
        inp.value = '';
        const thinking = appendMessage('', 'thinking');

        try {
          let reply = '';
          try {
            const response = await fetch('/client/gemini_chat.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ message: text, history: chatHistory })
            });
            if (!response.ok) throw new Error();
            const data = await response.json();
            reply = data.reply || 'Got it.';
            chatHistory.push({ role: 'user', text: text });
            chatHistory.push({ role: 'model', text: reply });
            // Keep only the last 20 turns so the request stays small.
            if (chatHistory.length > 20) chatHistory = chatHistory.slice(-20);
          } catch (e) {
            const mock = ['Darker violet, light chat.', 'Cyan background.', 'Abi resized.', 'I hear you.', 'Tell me more.', 'Constant spin.'];
            reply = mock[Math.floor(Math.random() * mock.length)];
          }
          thinking.remove();
          appendMessage(reply, 'incoming');
        } catch (err) {
          thinking.remove();
          appendMessage('Abi unreachable.', 'incoming');
        }
      }

      sendBtn.addEventListener('click', sendMessage);
      inp.addEventListener('keypress', (e) => { if (e.key === 'Enter') sendMessage(); });

    })();
  </script>
</body>
</html>
