/**
 * Abilisto first-time tour engine — shared by worker/dashboard.php,
 * client/worker_details.php, and client/booking.php (the latter two
 * form one continuous client tour split across pages; see AbiTour.run's
 * onSkip/onComplete below).
 *
 * Usage:
 *   <script src="../includes/tour_engine.js"></script>
 *   <script>
 *   AbiTour.run({
 *     steps: [
 *       { target: null, position: 'center', icon: 'waving_hand', iconColor: '#146af5',
 *         title: '...', body: '...' },
 *       { target: '#some-id', position: 'bottom', icon: 'bar_chart', iconColor: '#146af5',
 *         title: '...', body: '...' },
 *       // target may also be an array of selectors (e.g. separate desktop/mobile
 *       // elements for the same UI concept) — the first one that's actually
 *       // visible (offsetParent !== null) is used.
 *     ],
 *     onSkip:     function() { ... },  // "Skip tour" clicked mid-tour
 *     onComplete: function() { ... },  // last step's CTA clicked (defaults to onSkip)
 *   });
 *   </script>
 */
window.AbiTour = (function () {

    function run(config) {
        const STEPS = config.steps || [];
        if (!STEPS.length) return;
        const onSkip     = config.onSkip     || function () {};
        const onComplete = config.onComplete || onSkip;
        // Selectors for fixed, bottom-anchored chrome that eats into the
        // visible viewport (the shared mobile bottom nav by default; pages
        // with their own extra fixed bottom bar — e.g. a sticky booking CTA —
        // can add to this list so the tour knows to avoid it too).
        const bottomInsetSelectors = ['.mobile-bottom-nav'].concat(config.bottomInsetSelectors || []);

        /* ── Build DOM ── */

        const overlay = document.createElement('div');
        overlay.id = 'abi-tour-overlay';

        const svgNS = 'http://www.w3.org/2000/svg';
        const svg   = document.createElementNS(svgNS, 'svg');
        svg.id = 'abi-tour-svg-mask';
        svg.setAttribute('xmlns', svgNS);

        const defs     = document.createElementNS(svgNS, 'defs');
        const mask     = document.createElementNS(svgNS, 'mask');
        mask.id        = 'abi-cutout-mask';
        const mFill    = document.createElementNS(svgNS, 'rect');
        mFill.setAttribute('x','0'); mFill.setAttribute('y','0');
        mFill.setAttribute('width','100%'); mFill.setAttribute('height','100%');
        mFill.setAttribute('fill','white');
        const cutout   = document.createElementNS(svgNS, 'rect');
        cutout.id      = 'abi-tour-cutout';
        cutout.setAttribute('fill','black');
        cutout.setAttribute('rx','20');
        mask.appendChild(mFill); mask.appendChild(cutout);
        defs.appendChild(mask); svg.appendChild(defs);

        const backdrop = document.createElementNS(svgNS, 'rect');
        backdrop.setAttribute('x','0'); backdrop.setAttribute('y','0');
        backdrop.setAttribute('width','100%'); backdrop.setAttribute('height','100%');
        backdrop.setAttribute('fill','rgba(15,23,42,0.5)');
        backdrop.setAttribute('mask','url(#abi-cutout-mask)');
        svg.appendChild(backdrop);
        overlay.appendChild(svg);
        document.body.appendChild(overlay);

        const ring = document.createElement('div');
        ring.id = 'abi-tour-ring';
        document.body.appendChild(ring);

        const card = document.createElement('div');
        card.id = 'abi-tour-card';
        document.body.appendChild(card);

        /* ── State ── */
        let step = 0;
        let lastRect = null, lastPos = 'center';

        /* ── Helpers ── */

        // Resolve a step's target: a single selector, or an array of
        // candidate selectors (e.g. separate desktop/mobile elements for the
        // same UI concept) — first one that's actually visible wins.
        function resolveTarget(target) {
            if (!target) return null;
            const selectors = Array.isArray(target) ? target : [target];
            for (const sel of selectors) {
                const el = document.querySelector(sel);
                if (el && el.offsetParent !== null) return el;
            }
            return null;
        }

        // Fixed, bottom-anchored chrome (the shared mobile nav, and/or a
        // page-specific bar like a sticky booking CTA) permanently covers
        // part of the viewport — getBoundingClientRect()/window.innerHeight
        // know nothing about it, so every positioning calc below has to treat
        // its height as a "safe area" inset or the ring/card can end up
        // partly hidden behind it on phones. Takes the tallest match rather
        // than summing, since these bars typically stack in the same visual
        // slot (a page either has the shared nav OR its own bar, not both
        // stacked) — summing would over-reserve space if that assumption
        // ever changes, tallest is the safer default either way.
        function navInset() {
            let max = 0;
            for (const sel of bottomInsetSelectors) {
                const el = document.querySelector(sel);
                if (!el) continue;
                const cs = getComputedStyle(el);
                if (cs.display === 'none' || cs.position !== 'fixed') continue;
                if (el.offsetHeight > max) max = el.offsetHeight;
            }
            return max;
        }

        function scrollAndRect(el) {
            return new Promise(resolve => {
                el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                setTimeout(() => {
                    let rect = el.getBoundingClientRect();
                    const inset = navInset();
                    const visibleBottom = window.innerHeight - inset;
                    if (rect.bottom > visibleBottom) {
                        window.scrollBy({ top: rect.bottom - visibleBottom + 16, behavior: 'auto' });
                        rect = el.getBoundingClientRect();
                    } else if (rect.top < 0) {
                        window.scrollBy({ top: rect.top - 16, behavior: 'auto' });
                        rect = el.getBoundingClientRect();
                    }
                    resolve(rect);
                }, 500); // mobile smooth-scroll settles slower than desktop
            });
        }

        function setCutout(rect) {
            const P = 10;
            if (!rect) {
                cutout.setAttribute('width','0'); cutout.setAttribute('height','0');
                ring.style.opacity = '0';
                return;
            }
            const maxHeight = (window.innerHeight - navInset()) - (rect.top - P);
            const h = Math.max(0, Math.min(rect.height + P * 2, maxHeight));
            cutout.setAttribute('x',      rect.left   - P);
            cutout.setAttribute('y',      rect.top    - P);
            cutout.setAttribute('width',  rect.width  + P * 2);
            cutout.setAttribute('height', h);
            ring.style.cssText += `opacity:1;left:${rect.left - P}px;top:${rect.top - P}px;width:${rect.width + P*2}px;height:${h}px;`;
        }

        function placeCard(rect, pos) {
            const PAD = 14, EDGE = 12;
            const vw = window.innerWidth, vh = window.innerHeight - navInset();
            const cw = card.offsetWidth || 360, ch = card.offsetHeight || 260;
            let top, left;

            if (!rect || pos === 'center') {
                top  = Math.max(EDGE, (vh - ch) / 2);
                left = Math.max(EDGE, (vw - cw) / 2);
            } else {
                if (pos === 'bottom') { top = rect.bottom + PAD; left = rect.left + (rect.width - cw) / 2; }
                else if (pos === 'top')   { top = rect.top - ch - PAD; left = rect.left + (rect.width - cw) / 2; }
                else if (pos === 'right') { top = rect.top + (rect.height - ch) / 2; left = rect.right + PAD; }
                else                      { top = rect.top + (rect.height - ch) / 2; left = rect.left - cw - PAD; }

                left = Math.max(EDGE, Math.min(left, vw - cw - EDGE));
                top  = Math.max(EDGE, Math.min(top,  vh - ch - EDGE));
                if (pos === 'bottom' && top + ch > vh - EDGE) top = Math.max(EDGE, rect.top - ch - PAD);
                if (pos === 'top'    && top < EDGE)           top = rect.bottom + PAD;
            }
            card.style.top  = top  + 'px';
            card.style.left = left + 'px';
        }

        function renderCard(i) {
            const s = STEPS[i], total = STEPS.length, isLast = i === total - 1;
            const tint = s.iconColor + '1a';
            const dots = STEPS.map((_,d) => {
                let c = 'abi-tour-dot';
                if (d === i) c += ' abi-active';
                else if (d < i) c += ' abi-done';
                return `<span class="${c}"></span>`;
            }).join('');

            const ctaLabel = s.ctaLabel || (isLast
                ? '<span class="material-symbols-rounded" style="font-size:16px;font-variation-settings:\'FILL\' 1">rocket_launch</span> Get Started'
                : 'Next <span class="material-symbols-rounded" style="font-size:16px">arrow_forward</span>');

            card.innerHTML = `
                <div class="abi-tour-header">
                    <div class="abi-tour-icon-wrap" style="background:${tint}">
                        <span class="material-symbols-rounded" style="color:${s.iconColor}">${s.icon}</span>
                    </div>
                    <div class="abi-tour-title">${s.title}</div>
                </div>
                <div class="abi-tour-body">${s.body}</div>
                <div class="abi-tour-dots">${dots}</div>
                <div class="abi-tour-actions">
                    <button class="abi-tour-btn-next" id="abi-next">${ctaLabel}</button>
                    ${!isLast ? '<button class="abi-tour-btn-skip" id="abi-skip">Skip tour</button>' : ''}
                </div>
            `;
            document.getElementById('abi-next').addEventListener('click', next);
            const skipBtn = document.getElementById('abi-skip');
            if (skipBtn) skipBtn.addEventListener('click', () => teardown(onSkip));
        }

        /* ── Navigation ── */

        async function showStep(i) {
            const s = STEPS[i];
            if (i > 0) {
                card.classList.add('abi-reposition');
                setTimeout(() => card.classList.remove('abi-reposition'), 280);
            }
            renderCard(i);

            lastPos = s.position;

            let rect = null;
            if (s.target) {
                const el = resolveTarget(s.target);
                if (el) rect = await scrollAndRect(el);
            }

            lastRect = rect;
            setCutout(rect);
            requestAnimationFrame(() => requestAnimationFrame(() => placeCard(rect, s.position)));
        }

        let resizeTimer = null;
        function handleViewportChange() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                const s = STEPS[step];
                if (s && s.target) {
                    const el = resolveTarget(s.target);
                    if (el) lastRect = el.getBoundingClientRect();
                }
                setCutout(lastRect);
                placeCard(lastRect, lastPos);
            }, 150);
        }
        window.addEventListener('resize', handleViewportChange);
        window.addEventListener('orientationchange', handleViewportChange);

        let scrollTicking = false;
        function handleScroll() {
            if (scrollTicking) return;
            scrollTicking = true;
            requestAnimationFrame(() => {
                const s = STEPS[step];
                if (s && s.target) {
                    const el = resolveTarget(s.target);
                    if (el) lastRect = el.getBoundingClientRect();
                }
                setCutout(lastRect);
                placeCard(lastRect, lastPos);
                scrollTicking = false;
            });
        }
        window.addEventListener('scroll', handleScroll, { passive: true });

        function next() {
            step++;
            if (step >= STEPS.length) teardown(onComplete);
            else showStep(step);
        }

        function teardown(callback) {
            window.removeEventListener('resize', handleViewportChange);
            window.removeEventListener('orientationchange', handleViewportChange);
            window.removeEventListener('scroll', handleScroll);

            [overlay, card, ring].forEach(el => {
                el.style.transition = 'opacity 0.3s';
                el.style.opacity    = '0';
            });
            card.style.transform = 'scale(0.95)';
            setTimeout(() => { overlay.remove(); card.remove(); ring.remove(); }, 320);

            callback();
        }

        function launch() { setTimeout(() => showStep(0), 800); }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', launch);
        else launch();
    }

    return { run: run };
})();
