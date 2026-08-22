// worker/js/booking-actions.js
// Handles all booking actions via API
// FIXED: Unified timer system, reliable quick match, no duplicate handlers

// ============================================
// REGULAR BOOKING FUNCTIONS
// ============================================

// Accept booking
function acceptBooking(bookingId) {
    console.log('Accepting booking:', bookingId);

    const formData = new URLSearchParams();
    formData.append('action', 'check_eligibility');
    formData.append('booking_id', bookingId);

    fetch('../api/booking_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async response => {
        const text = await response.text();
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Invalid JSON response from server'); }
    })
    .then(data => {
        if (data.success && data.can_accept) {
            let feeSource = data.free_credits >= data.required_fee ? 'free credits' : 'wallet';
            if (confirm(`Accept this booking? This will deduct ₱${data.required_fee} from your ${feeSource}.`)) {
                processAccept(bookingId);
            }
        } else if (data.is_restricted) {
            // Handle restricted worker case
            showRestrictedModal(data);
        } else {
            // Handle insufficient funds case
            showInsufficientFundsModal(data);
        }
    })
    .catch(error => {
        console.error('Eligibility check error:', error);
        showNotification('error', 'Failed to check eligibility. Please try again.');
    });
}

// Add this new function
function showRestrictedModal(data) {
    const existingModal = document.getElementById('fundsModal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'fundsModal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); display: flex; align-items: center;
        justify-content: center; z-index: 9999; padding: 16px; box-sizing: border-box;
    `;
    modal.innerHTML = `
        <div style="background: white; padding: 28px; border-radius: 20px; max-width: 400px; width: 100%; font-family: 'Plus Jakarta Sans', sans-serif;">
            <h3 style="margin-top: 0; color: #dc2626; font-size: 18px;">⚠️ Account Restricted</h3>
            <p>${data.message}</p>
            <div style="background: #fef2f2; padding: 15px; border-radius: 12px; margin: 16px 0;">
                <p style="margin:4px 0; color: #dc2626;"><strong>Action Required:</strong> Contact support to resolve this issue.</p>
            </div>
            <div style="display: flex; gap: 10px;">
                <button onclick="document.getElementById('fundsModal').remove()" style="flex: 1; background: #64748b; color: white; padding: 12px 16px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600;">Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function processAccept(bookingId) {
    showLoading('Accepting booking...');

    const formData = new URLSearchParams();
    formData.append('action', 'accept');
    formData.append('booking_id', bookingId);

    fetch('../api/booking_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async response => {
        const text = await response.text();
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Invalid JSON response from server'); }
    })
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('success', data.message);
            if (data.new_balance !== undefined) {
                updateWalletDisplay(data.new_balance, data.free_credits);
            }
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('error', data.message);
            if (data.need_topup) {
                if (confirm('Insufficient funds. Would you like to top up your wallet now?')) {
                    window.location.href = 'wallet.php';
                }
            }
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('error', 'Network error. Please try again.');
        console.error('Accept error:', error);
    });
}

// Reject booking
function rejectBooking(bookingId) {
    if (!confirm('Are you sure you want to decline this booking?')) return;

    showLoading('Processing...');

    const formData = new URLSearchParams();
    formData.append('action', 'reject');
    formData.append('booking_id', bookingId);

    fetch('../api/booking_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async response => {
        const text = await response.text();
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Invalid JSON response'); }
    })
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('info', data.message);
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('error', data.message);
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('error', 'Failed to process request');
        console.error('Reject error:', error);
    });
}

// Complete booking
function completeBooking(bookingId) {
    const card = document.querySelector(`[data-booking-id="${bookingId}"]`);
    const paymentBadge = card?.querySelector('.payment-badge');

    if (paymentBadge && paymentBadge.textContent.includes('PayMongo')) {
        alert('For GCash bookings, please submit proof of completion first.');
        return;
    }

    if (!confirm('Have you completed this job? The client will be notified to leave a review.')) return;

    showLoading('Completing job...');

    const formData = new URLSearchParams();
    formData.append('action', 'complete');
    formData.append('booking_id', bookingId);

    fetch('../api/booking_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData.toString()
    })
    .then(async response => {
        const text = await response.text();
        try { return JSON.parse(text); }
        catch (e) { throw new Error('Invalid JSON response'); }
    })
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('success', data.message);
            const jobsEl = document.getElementById('jobs-completed');
            if (jobsEl) jobsEl.textContent = parseInt(jobsEl.textContent) + 1;
            setTimeout(() => location.reload(), 2000);
        } else {
            showNotification('error', data.message);
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('error', 'Failed to complete job');
        console.error('Complete error:', error);
    });
}

// ============================================
// QUICK MATCH FUNCTIONS
// ============================================

/**
 * Accept a quick match booking.
 * Uses absolute path to avoid broken relative paths.
 */
function acceptQuickMatch(bookingId, broadcastId) {
    console.log('Accepting quick match:', { bookingId, broadcastId });

    if (!bookingId || !broadcastId) {
        showNotification('error', 'Missing booking information');
        return;
    }

    // Guard: check if timer is expired in the DOM
    const card = document.querySelector(`[data-booking-id="${bookingId}"]`);
    if (card && card.classList.contains('qm-expired')) {
        showNotification('error', 'This quick match has already expired.');
        return;
    }

    const confirmMsg = 'Accept this quick match job? You will be connected to the client immediately.';
    if (!confirm(confirmMsg)) return;

    const btn = document.getElementById(`accept-btn-${bookingId}`) ||
                document.querySelector(`[data-booking-id="${bookingId}"] .quick-match-accept`);

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 1s linear infinite;display:inline-block">refresh</span> Accepting...';
    }

    fetch('/worker/api/accept_quick_match.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            booking_id: parseInt(bookingId),
            broadcast_id: parseInt(broadcastId)
        })
    })
    .then(async response => {
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const text = await response.text();
            console.error('Non-JSON response:', text.substring(0, 500));
            throw new Error('Server returned non-JSON. Check if accept_quick_match.php exists.');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification('success', 'Quick match accepted! Redirecting to chat...');
            setTimeout(() => {
                window.location.href = `/chat.php?booking_id=${bookingId}`;
            }, 1500);
        } else {
            showNotification('error', data.message || 'Failed to accept quick match');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<span class="material-symbols-outlined">bolt</span> ACCEPT QUICK MATCH';
            }
        }
    })
    .catch(error => {
        console.error('Quick match accept error:', error);
        showNotification('error', 'Network error: ' + error.message);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined">bolt</span> ACCEPT QUICK MATCH';
        }
    });
}

// ============================================
// UNIFIED QUICK MATCH TIMER SYSTEM
// ============================================

/**
 * THE SINGLE SOURCE OF TRUTH for all quick match timers.
 * 
 * Strategy:
 * - Each quick match card has data-seconds on its .timer element.
 * - We track seconds remaining in a JS Map, keyed by broadcastId.
 * - One global setInterval drives all timers (no per-card intervals).
 * - When expired: disable button, mark card, update display.
 */
(function initTimerSystem() {
    // Map<broadcastId, secondsRemaining>
    const timerState = new Map();

    function getCards() {
        return document.querySelectorAll('.ambient-card[data-broadcast-id]');
    }

    function initTimers() {
        // Stop any previously running interval
        if (window._quickMatchInterval) {
            clearInterval(window._quickMatchInterval);
            window._quickMatchInterval = null;
        }

        timerState.clear();

        getCards().forEach(card => {
            const broadcastId = card.dataset.broadcastId;
            const timerEl = card.querySelector('.timer[data-broadcast]');
            if (!timerEl) return;

            // Use data-seconds as the authoritative source, never the display text
            let secs = parseInt(timerEl.dataset.seconds, 10);
            if (isNaN(secs) || secs < 0) secs = 0;

            timerState.set(broadcastId, secs);

            // Apply initial state
            if (secs <= 0) {
                expireCard(card, broadcastId, timerEl);
            } else {
                renderTimer(timerEl, secs);
            }
        });

        if (timerState.size === 0) return;

        // One interval for all cards
        window._quickMatchInterval = setInterval(() => {
            let allExpired = true;

            getCards().forEach(card => {
                const broadcastId = card.dataset.broadcastId;
                if (!timerState.has(broadcastId)) return;

                let secs = timerState.get(broadcastId);
                if (secs <= 0) return; // already expired

                secs--;
                timerState.set(broadcastId, secs);

                const timerEl = card.querySelector('.timer[data-broadcast]');
                if (!timerEl) return;

                if (secs <= 0) {
                    expireCard(card, broadcastId, timerEl);
                } else {
                    allExpired = false;
                    renderTimer(timerEl, secs);

                    // Switch to urgent style under 60s
                    const timerRow = timerEl.closest('.flex');
                    if (secs < 60) {
                        timerEl.classList.remove('text-yellow-700');
                        timerEl.classList.add('text-red-600', 'timer-urgent');
                        if (timerRow) {
                            timerRow.classList.remove('bg-yellow-50');
                            timerRow.classList.add('bg-red-50');
                        }
                    }
                }
            });

            // Stop interval when all are expired
            if (allExpired) {
                clearInterval(window._quickMatchInterval);
                window._quickMatchInterval = null;
            }
        }, 1000);

        console.log(`✅ Quick match timer started for ${timerState.size} card(s)`);
    }

    function renderTimer(timerEl, secs) {
        const mins = Math.floor(secs / 60);
        const s = secs % 60;
        timerEl.textContent = `${mins}:${s.toString().padStart(2, '0')}`;
        timerEl.dataset.seconds = secs;
    }

    function expireCard(card, broadcastId, timerEl) {
        // Update timer display
        timerEl.textContent = 'EXPIRED';
        timerEl.className = timerEl.className
            .replace(/text-yellow-700|text-red-600/g, '')
            .trim() + ' text-slate-400';
        const timerRow = timerEl.closest('.flex');
        if (timerRow) {
            timerRow.classList.remove('bg-yellow-50', 'bg-red-50');
            timerRow.classList.add('bg-slate-100');
        }

        // Disable accept button
        const btn = card.querySelector('.quick-match-accept');
        if (btn && !btn.disabled) {
            btn.disabled = true;
            btn.removeAttribute('onclick');
            btn.innerHTML = '<span class="material-symbols-outlined">schedule</span> EXPIRED';
            btn.classList.remove('btn-gradient');
            btn.classList.add('bg-slate-300', 'cursor-not-allowed', 'opacity-60');
        }

        // Mark card as expired
        card.classList.add('qm-expired', 'expired-quick-match');
        timerState.set(broadcastId, 0);

        console.log(`⏰ Quick match broadcast ${broadcastId} expired`);
    }

    // Expose for manual re-init (e.g. after AJAX content load)
    window.initQuickMatchTimers = initTimers;
    window.refreshQuickMatchTimers = initTimers;

    // ── BROADCAST CANCELLATION POLLING ──────────────────────────────
    // Runs every 10s alongside the timer tick.
    // Calls api/check_broadcasts.php with all visible broadcast booking IDs.
    // If any come back as 'cancelled', shows a banner and fades the card out.

    let _pollInterval = null;

    function getVisibleBookingIds() {
        const ids = [];
        getCards().forEach(card => {
            const bid = card.dataset.bookingId;
            if (bid && !card.classList.contains('qm-expired') && !card.classList.contains('qm-cancelled')) {
                ids.push(parseInt(bid));
            }
        });
        return ids;
    }

    function cancelCard(card, broadcastId) {
        // Mark so we don't process it twice
        card.classList.add('qm-cancelled');

        // Remove from timer tracking
        timerState.delete(broadcastId);

        // Disable all buttons
        card.querySelectorAll('button').forEach(btn => {
            btn.disabled = true;
            btn.classList.add('opacity-50', 'cursor-not-allowed');
        });

        // Show cancelled banner inside the card
        const banner = document.createElement('div');
        banner.style.cssText = `
            display: flex; align-items: center; gap: 8px;
            background: #fef2f2; border: 1px solid #fecaca;
            color: #dc2626; font-weight: 700; font-size: 13px;
            padding: 10px 14px; border-radius: 12px; margin-bottom: 12px;
        `;
        banner.innerHTML = `
            <span class="material-symbols-outlined" style="font-size:18px;">cancel</span>
            This quick match was cancelled by the client.
        `;
        card.prepend(banner);

        // Fade and remove after 4 seconds
        setTimeout(() => {
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            card.style.opacity    = '0';
            card.style.transform  = 'scale(0.95)';
            setTimeout(() => {
                card.remove();
                // If no active cards remain, stop both intervals
                if (getCards().length === 0) {
                    clearInterval(_pollInterval);
                    clearInterval(window._quickMatchInterval);
                    _pollInterval = null;
                    window._quickMatchInterval = null;
                    // Hide the pending section if empty
                    const section = document.getElementById('pending-requests-section');
                    if (section && section.querySelectorAll('.ambient-card').length === 0) {
                        section.style.transition = 'opacity 0.4s ease';
                        section.style.opacity    = '0';
                        setTimeout(() => section.remove(), 450);
                    }
                }
            }, 650);
        }, 4000);

        console.log(`🚫 Quick match broadcast ${broadcastId} was cancelled by client`);
    }

    async function pollCancellations() {
        const bookingIds = getVisibleBookingIds();
        if (bookingIds.length === 0) {
            clearInterval(_pollInterval);
            _pollInterval = null;
            return;
        }

        try {
            const res = await fetch('/abilisto/api/check_broadcasts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ booking_ids: bookingIds })
            });
            if (!res.ok) return;

            const data = await res.json();
            const statuses = data.statuses || {};

            // Match broadcast IDs back to cards via booking ID
            getCards().forEach(card => {
                const bid         = parseInt(card.dataset.bookingId);
                const broadcastId = card.dataset.broadcastId;
                const status      = statuses[bid];

                if (status === 'cancelled' && !card.classList.contains('qm-cancelled')) {
                    cancelCard(card, broadcastId);
                }
            });

        } catch (err) {
            // Silent fail — never crash the dashboard over a poll error
            console.warn('Broadcast cancellation poll failed:', err.message);
        }
    }

    function startPolling() {
        if (_pollInterval) return; // already running
        const ids = getVisibleBookingIds();
        if (ids.length === 0) return;
        _pollInterval = setInterval(pollCancellations, 10000);
        console.log(`✅ Broadcast cancellation polling started for ${ids.length} card(s)`);
    }

    // Hook polling start into initTimers so it restarts on re-init too
    const _originalInitTimers = initTimers;
    window.initQuickMatchTimers = function() {
        _originalInitTimers();
        clearInterval(_pollInterval);
        _pollInterval = null;
        startPolling();
    };
    window.refreshQuickMatchTimers = window.initQuickMatchTimers;

    // ── END CANCELLATION POLLING ─────────────────────────────────────

    // Boot on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(initTimers, 100));
    } else {
        setTimeout(initTimers, 100);
    }
})();

// ============================================
// UTILITY FUNCTIONS
// ============================================

function isPrepaid(bookingId) {
    const paymentBadge = document.querySelector(`[data-booking-id="${bookingId}"] .payment-badge`);
    return paymentBadge && paymentBadge.textContent.includes('Prepaid');
}

function showInsufficientFundsModal(data) {
    const existingModal = document.getElementById('fundsModal');
    if (existingModal) existingModal.remove();

    const modal = document.createElement('div');
    modal.id = 'fundsModal';
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); display: flex; align-items: center;
        justify-content: center; z-index: 9999; padding: 16px; box-sizing: border-box;
    `;
    modal.innerHTML = `
        <div style="background: white; padding: 28px; border-radius: 20px; max-width: 400px; width: 100%; font-family: 'Plus Jakarta Sans', sans-serif;">
            <h3 style="margin-top: 0; color: #dc2626; font-size: 18px;">⚠️ Insufficient Funds</h3>
            <p>You need <strong>₱${data.required_fee || 0}</strong> to accept this booking.</p>
            <div style="background: #f8fafc; padding: 15px; border-radius: 12px; margin: 16px 0;">
                <p style="margin:4px 0;"><strong>Current Balance:</strong> ₱${data.wallet_balance || 0}</p>
                <p style="margin:4px 0;"><strong>Free Credits:</strong> ₱${data.free_credits || 0}</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="topup.php" style="flex: 1; min-width: 120px; background: #22c55e; color: white; padding: 12px 16px; text-align: center; text-decoration: none; border-radius: 10px; font-weight: 600;">Top Up Now</a>
                <button onclick="document.getElementById('fundsModal').remove()" style="flex: 1; min-width: 120px; background: #64748b; color: white; padding: 12px 16px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600;">Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function showLoading(message) {
    hideLoading();
    const loading = document.createElement('div');
    loading.id = 'loadingOverlay';
    loading.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.6); display: flex; align-items: center;
        justify-content: center; z-index: 10000; flex-direction: column; color: white;
    `;
    loading.innerHTML = `
        <div style="border: 4px solid rgba(255,255,255,0.3); border-top: 4px solid white; border-radius: 50%; width: 48px; height: 48px; animation: spin 0.8s linear infinite; margin-bottom: 16px;"></div>
        <div style="font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 500;">${message}</div>
    `;
    document.body.appendChild(loading);
}

function hideLoading() {
    const loading = document.getElementById('loadingOverlay');
    if (loading) loading.remove();
}

function showNotification(type, message) {
    const existing = document.getElementById('ba-notification');
    if (existing) existing.remove();

    const notif = document.createElement('div');
    notif.id = 'ba-notification';
    const colors = {
        success: '#22c55e',
        error: '#ef4444',
        info: '#3b82f6'
    };
    notif.style.cssText = `
        position: fixed; top: 20px; right: 20px; padding: 14px 20px;
        border-radius: 14px; color: white; font-weight: 500; max-width: 340px;
        z-index: 10001; animation: baSlideIn 0.3s ease forwards;
        background: ${colors[type] || colors.info};
        font-family: 'Plus Jakarta Sans', sans-serif;
        box-shadow: 0 8px 24px rgba(0,0,0,0.15);
        display: flex; align-items: center; gap: 10px;
    `;
    notif.innerHTML = `<span style="flex:1">${message}</span><button onclick="this.parentElement.remove()" style="background:none;border:none;color:white;cursor:pointer;font-size:18px;line-height:1;padding:0;margin-left:8px;">×</button>`;
    document.body.appendChild(notif);

    setTimeout(() => { if (notif.parentElement) notif.remove(); }, 4000);
}

function updateWalletDisplay(balance, freeCredits) {
    const balanceEl = document.querySelector('.wallet-balance');
    const freeEl = document.querySelector('.free-credits');
    if (balanceEl) balanceEl.textContent = '₱' + parseFloat(balance).toFixed(2);
    if (freeEl) freeEl.textContent = '₱' + parseFloat(freeCredits).toFixed(2);
}

// ============================================
// INITIALIZATION & STYLES
// ============================================

document.addEventListener('DOMContentLoaded', function () {
    if (!document.getElementById('ba-styles')) {
        const style = document.createElement('style');
        style.id = 'ba-styles';
        style.textContent = `
            @keyframes baSlideIn {
                from { transform: translateX(110%); opacity: 0; }
                to   { transform: translateX(0);    opacity: 1; }
            }
            @keyframes spin {
                0%   { transform: rotate(0deg);   }
                100% { transform: rotate(360deg); }
            }
            .timer-urgent {
                animation: timerPulse 1s ease-in-out infinite;
            }
            @keyframes timerPulse {
                0%, 100% { opacity: 1; }
                50%       { opacity: 0.6; }
            }
            .qm-expired .quick-match-accept,
            .quick-match-accept:disabled {
                background: #94a3b8 !important;
                cursor: not-allowed !important;
                transform: none !important;
                box-shadow: none !important;
                opacity: 0.6 !important;
            }
            .expired-quick-match {
                filter: grayscale(0.2);
            }
        `;
        document.head.appendChild(style);
    }

    // NOTE: initQuickMatchTimers() is called automatically by the IIFE above.
    // Do NOT call it again here to avoid double-init.
    console.log('✅ booking-actions.js loaded');
});

// Make all functions globally available
window.acceptBooking      = acceptBooking;
window.rejectBooking      = rejectBooking;
window.completeBooking    = completeBooking;
window.acceptQuickMatch   = acceptQuickMatch;
window.updateWalletDisplay = updateWalletDisplay;
window.showNotification   = showNotification;
window.showLoading        = showLoading;
window.hideLoading        = hideLoading;