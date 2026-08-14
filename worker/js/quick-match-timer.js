// worker/js/quick-match-timer.js

// Make sure this runs after DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Quick Match Timer initialized');
    initializeTimers();
});

function initializeTimers() {
    // Find all cards with timers
    document.querySelectorAll('.job-card[data-expires]').forEach(card => {
        const bookingId = card.dataset.bookingId;
        const expiresAt = card.dataset.expires;
        
        if (bookingId && expiresAt && expiresAt !== '') {
            console.log(`Starting timer for booking #${bookingId}, expires: ${expiresAt}`);
            startQuickMatchTimer(bookingId, expiresAt);
        }
    });
}

let quickMatchTimers = {};

function startQuickMatchTimer(bookingId, expiresAt) {
    // Clear existing timer if any
    if (quickMatchTimers[bookingId]) {
        clearInterval(quickMatchTimers[bookingId]);
    }
    
    const expiryTime = new Date(expiresAt).getTime();
    const card = document.querySelector(`[data-booking-id="${bookingId}"]`);
    const timerElement = card?.querySelector('.timer');
    
    if (!timerElement) {
        console.warn(`Timer element not found for booking #${bookingId}`);
        return;
    }
    
    // Initial update
    updateTimerDisplay(bookingId, expiryTime);
    
    quickMatchTimers[bookingId] = setInterval(function() {
        updateTimerDisplay(bookingId, expiryTime);
    }, 1000);
}

function updateTimerDisplay(bookingId, expiryTime) {
    const now = new Date().getTime();
    const distance = expiryTime - now;
    const card = document.querySelector(`[data-booking-id="${bookingId}"]`);
    const timerElement = card?.querySelector('.timer');
    
    if (!timerElement) {
        if (quickMatchTimers[bookingId]) {
            clearInterval(quickMatchTimers[bookingId]);
        }
        return;
    }
    
    if (distance < 0) {
        // Timer expired
        clearInterval(quickMatchTimers[bookingId]);
        timerElement.innerHTML = "EXPIRED";
        timerElement.style.color = "#6c757d";
        
        // Mark card as expired
        if (card) {
            card.style.opacity = "0.6";
            card.classList.add("expired");
            
            // Disable accept button
            const acceptBtn = card.querySelector('.btn-accept');
            if (acceptBtn) {
                acceptBtn.disabled = true;
                acceptBtn.style.background = "#ccc";
                acceptBtn.style.cursor = "not-allowed";
                acceptBtn.title = "This quick match job has expired";
            }
            
            // Update timer badge color
            const timerBadge = card.querySelector('.quick-match-timer');
            if (timerBadge) {
                timerBadge.style.background = "#6c757d";
            }
        }
        
    } else {
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);
        timerElement.innerHTML = 
            (minutes < 10 ? '0' + minutes : minutes) + ':' + 
            (seconds < 10 ? '0' + seconds : seconds);
        
        // Warning color when less than 1 minute
        if (distance < 60000) {
            timerElement.style.color = "#dc3545";
            timerElement.style.animation = "pulse 1s infinite";
        } else {
            timerElement.style.color = "";
            timerElement.style.animation = "";
        }
    }
}

// Enhanced accept function - ADDED broadcastId parameter
window.acceptBooking = function(bookingId, isQuickMatch = false, broadcastId = 0) {
    if (isQuickMatch) {
        // Check if still available
        fetch(`../api/check_broadcast_status.php?booking_id=${bookingId}`)
            .then(res => res.json())
            .then(data => {
                if (data.available) {
                    processAccept(bookingId, true, broadcastId);
                } else {
                    let message = 'This quick match job is no longer available.';
                    if (data.reason === 'already_taken') {
                        message = 'This job has already been accepted by another worker.';
                    } else if (data.reason === 'expired') {
                        message = 'This quick match job has expired.';
                    }
                    alert(message);
                    location.reload();
                }
            })
            .catch(err => {
                console.error('Error checking availability:', err);
                // Fallback to process it anyway and let the backend transaction handle safety
                processAccept(bookingId, true, broadcastId);
            });
    } else {
        // Regular booking
        if (typeof originalAcceptBooking === 'function') {
            originalAcceptBooking(bookingId);
        } else {
            processAccept(bookingId, false, 0);
        }
    }
};

// UPDATED: Added broadcastId and separated the API endpoints
function processAccept(bookingId, isQuickMatch, broadcastId = 0) {
    if (typeof showLoading === 'function') showLoading('Accepting booking...');
    
    if (isQuickMatch) {
        // QUICK MATCH ROUTE: Hit the dedicated JSON endpoint
        fetch('../api/accept_quick_match.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                booking_id: bookingId,
                broadcast_id: broadcastId
            })
        })
        .then(res => res.json())
        .then(data => {
            if (typeof hideLoading === 'function') hideLoading();
            
            if (data.success) {
                if (typeof showNotification === 'function') {
                    showNotification('success', 'Quick Match Accepted!');
                } else {
                    alert('Success: Quick Match Accepted!');
                }
                
                setTimeout(() => {
                    window.location.href = `../chat.php?booking_id=${bookingId}`;
                }, 1500);
            } else {
                if (typeof showNotification === 'function') {
                    showNotification('error', data.message || 'Error accepting job');
                } else {
                    alert('Error: ' + (data.message || 'Error accepting job'));
                }
                setTimeout(() => location.reload(), 1500);
            }
        })
        .catch(error => {
            if (typeof hideLoading === 'function') hideLoading();
            console.error('Accept error:', error);
            alert('Network error. Please try again.');
        });

    } else {
        // REGULAR BOOKING ROUTE: Use form data
        const formData = new URLSearchParams();
        formData.append('action', 'accept');
        formData.append('booking_id', bookingId);
        formData.append('is_quick_match', '0');
        
        fetch('../api/booking_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: formData.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (typeof hideLoading === 'function') hideLoading();
            
            if (data.success) {
                if (typeof showNotification === 'function') {
                    showNotification('success', data.message);
                } else {
                    alert('Success: ' + data.message);
                }
                
                setTimeout(() => {
                    window.location.href = `../chat.php?booking_id=${bookingId}`;
                }, 1500);
            } else {
                if (typeof showNotification === 'function') {
                    showNotification('error', data.message);
                } else {
                    alert('Error: ' + data.message);
                }
            }
        })
        .catch(error => {
            if (typeof hideLoading === 'function') hideLoading();
            console.error('Accept error:', error);
            alert('Network error. Please try again.');
        });
    }
}

// Add CSS if not exists
if (!document.getElementById('quick-match-styles')) {
    const style = document.createElement('style');
    style.id = 'quick-match-styles';
    style.textContent = `
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.6; }
            100% { opacity: 1; }
        }
        
        .quick-match-card {
            position: relative;
            animation: slideIn 0.3s ease;
        }
        
        .quick-match-card .timer {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            min-width: 45px;
            display: inline-block;
        }
        
        .quick-match-card.expired {
            opacity: 0.6;
            filter: grayscale(50%);
        }
        
        .quick-match-card.expired .quick-match-timer {
            background: #6c757d !important;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(style);
}

// Re-initialize timers when new content is loaded (for AJAX)
if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                initializeTimers();
            }
        });
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}