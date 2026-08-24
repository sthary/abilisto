<?php
// worker/partials/booking_card.php
// FIXED: Timer data attributes, mobile responsiveness, button state logic
// ADDED: 'pending_confirmation' type — shows "Awaiting Client Confirmation" state

if (!function_exists('renderBookingCard')):

function renderBookingCard($booking, $type) {
    // ── Defaults ──────────────────────────────────────────────────────────────
    $booking['full_name']            = $booking['full_name']            ?? 'Unknown Client';
    // Prefer the booking's own location_address (set at booking time), fall back to the user's profile address
    $locationAddress                 = !empty($booking['location_address']) 
                                         ? $booking['location_address'] 
                                         : ($booking['address'] ?? '');
    $booking['address']              = $locationAddress ?: 'Address not available';
    $booking['phone']                = $booking['phone']                ?? '';
    $booking['latitude']             = $booking['latitude']             ?? '';
    $booking['longitude']            = $booking['longitude']            ?? '';
    $booking['problem_desc']         = $booking['problem_desc']         ?? 'No description provided';
    $booking['urgency_level']        = $booking['urgency_level']        ?? 'Normal';
    $booking['payment_method']       = $booking['payment_method']       ?? 'Cash';
    $booking['payment_status']       = $booking['payment_status']       ?? 'Pending';
    $booking['calculated_fee']       = $booking['calculated_fee']       ?? 0;
    $booking['labor_materials_cost'] = $booking['labor_materials_cost'] ?? 0;
    $booking['total_final_cost']     = $booking['total_final_cost']     ?? 0;

    // ── Booking date & time display ───────────────────────────────────────────
    if (!empty($booking['booking_date'])) {
        $bookingDateDisplay = date('M d, Y', strtotime($booking['booking_date']));
        $bookingTimeDisplay = date('g:i A', strtotime($booking['booking_date']));
    } else {
        $bookingDateDisplay = 'Date not set';
        $bookingTimeDisplay = '';
    }

    // ── Quick-match flags ─────────────────────────────────────────────────────
    $isQuickMatch        = !empty($booking['is_active_quick_match'])  && (int)$booking['is_active_quick_match']  === 1;
    $isExpiredQuickMatch = !empty($booking['is_expired_quick_match']) && (int)$booking['is_expired_quick_match'] === 1;
    $broadcastId         = (int)($booking['broadcast_id'] ?? 0);

    $secondsRaw       = $booking['seconds_remaining'] ?? null;
    $secondsRemaining = ($secondsRaw !== null) ? max(0, (int)$secondsRaw) : 0;
    $timerIsLive      = $isQuickMatch && $secondsRemaining > 0;

    // ── Other flags ───────────────────────────────────────────────────────────
    $hasFinalCosts = $booking['labor_materials_cost'] > 0 && $booking['total_final_cost'] > 0;
    $isPrepaid     = $booking['payment_method'] === 'PayMongo' && $booking['payment_status'] === 'Paid';

    // ── Urgency styling ───────────────────────────────────────────────────────
    $urgencyColors = [
        'Normal'    => ['bg' => '#FEF3C7', 'text' => '#92400E', 'icon' => 'schedule'],
        'High'      => ['bg' => '#FEE2E2', 'text' => '#B91C1C', 'icon' => 'priority_high'],
        'Emergency' => ['bg' => '#DC2626', 'text' => 'white',   'icon' => 'emergency'],
    ];
    $urgency = $urgencyColors[$booking['urgency_level']] ?? $urgencyColors['Normal'];

    // ── Avatar initials ───────────────────────────────────────────────────────
    $initials = '';
    foreach (explode(' ', $booking['full_name']) as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    $initials = substr($initials, 0, 2);

    // ── Timer display values ──────────────────────────────────────────────────
    $timerBgClass   = $secondsRemaining < 60 ? 'bg-red-50'     : 'bg-yellow-50';
    $timerTextClass = $secondsRemaining < 60 ? 'text-red-600'  : 'text-yellow-700';

    if ($secondsRemaining <= 0) {
        $timerDisplay = 'EXPIRED';
    } else {
        $mins = floor($secondsRemaining / 60);
        $secs = $secondsRemaining % 60;
        $timerDisplay = sprintf('%d:%02d', $mins, $secs);
    }
?>

<div class="ambient-card p-4 sm:p-6 flex flex-col job-card-hover relative <?php echo htmlspecialchars($type); ?><?php echo $isQuickMatch ? ' quick-match' : ''; ?><?php echo ($isExpiredQuickMatch || !$timerIsLive && $isQuickMatch) ? ' qm-expired expired-quick-match' : ''; ?>"
     data-booking-id="<?php echo (int)$booking['id']; ?>"
     <?php if ($broadcastId > 0): ?>
     data-broadcast-id="<?php echo $broadcastId; ?>"
     data-expires="<?php echo strtotime($booking['broadcast_expires_at'] ?? 'now') * 1000; ?>"
     <?php endif; ?>>

    <!-- ── QUICK-MATCH BADGE & TIMER ───────────────────────────────────────── -->
    <?php if ($isQuickMatch): ?>
    <div class="absolute -top-3 left-4 sm:left-6 bg-blue-500 text-white px-3 sm:px-4 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider z-10 flex items-center gap-1">
        <span class="material-symbols-outlined" style="font-size:13px;">bolt</span>
        QUICK MATCH
    </div>

    <div class="mt-5 mb-4 px-3 py-2 rounded-xl flex justify-between items-center gap-3 <?php echo $timerBgClass; ?>">
        <span class="text-xs font-medium flex items-center gap-1.5 text-slate-600">
            <span class="material-symbols-outlined" style="font-size:15px;">hourglass_empty</span>
            <span class="hidden xs:inline">Time left:</span>
            <span class="xs:hidden">Timer:</span>
        </span>
        <span class="timer font-bold text-sm <?php echo $timerTextClass; ?><?php echo $secondsRemaining < 60 ? ' timer-urgent' : ''; ?>"
              data-broadcast="<?php echo $broadcastId; ?>"
              data-seconds="<?php echo $secondsRemaining; ?>">
            <?php echo $timerDisplay; ?>
        </span>
    </div>

    <?php elseif ($isExpiredQuickMatch): ?>
    <div class="mt-2 mb-4 px-3 py-2 bg-slate-100 rounded-xl text-center text-slate-500 text-xs">
        <span class="material-symbols-outlined" style="font-size:14px;vertical-align:middle;margin-right:4px;">schedule</span>
        Quick match expired
    </div>
    <?php endif; ?>

    <!-- ── PENDING CONFIRMATION TOP BANNER ────────────────────────────────── -->
    <?php if ($type === 'pending_confirmation'): ?>
    <div class="mb-4 flex items-center gap-2 px-3 py-2.5 bg-amber-50 border border-amber-200 rounded-xl">
        <span class="material-symbols-outlined text-amber-500 flex-shrink-0" style="font-size:18px;font-variation-settings:'FILL' 1">hourglass_top</span>
        <span class="text-xs font-bold text-amber-700">Awaiting client confirmation</span>
    </div>
    <?php endif; ?>

    <!-- ── HEADER: Urgency + Booking ID ────────────────────────────────────── -->
    <div class="flex flex-wrap justify-between items-start gap-2 mb-4 <?php echo ($isQuickMatch || $type === 'pending_confirmation') ? '' : 'mt-2'; ?>">
        <div class="flex flex-wrap gap-2">
            <span class="glass-badge px-2.5 py-1 rounded-lg text-xs font-bold"
                  style="background:<?php echo $urgency['bg']; ?>;color:<?php echo $urgency['text']; ?>;">
                <span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;margin-right:2px;"><?php echo $urgency['icon']; ?></span>
                <?php echo htmlspecialchars($booking['urgency_level']); ?>
            </span>

            <?php if ($isPrepaid): ?>
            <span class="glass-badge px-2.5 py-1 rounded-lg text-[10px] font-bold text-green-600 uppercase bg-green-50">
                <span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;margin-right:2px;">check_circle</span>
                Prepaid
            </span>
            <?php endif; ?>

            <?php if ($timerIsLive): ?>
            <span class="glass-badge px-2.5 py-1 rounded-lg text-[10px] font-bold text-blue-600 uppercase bg-blue-50">
                <span class="material-symbols-outlined" style="font-size:12px;vertical-align:middle;margin-right:2px;">bolt</span>
                Priority
            </span>
            <?php endif; ?>
        </div>

        <span class="text-[10px] font-bold text-slate-400 mt-0.5">
            #BK-<?php echo str_pad((int)$booking['id'], 4, '0', STR_PAD_LEFT); ?>
        </span>
    </div>

    <!-- ── CLIENT INFO ────────────────────────────────────────────────────── -->
    <div class="flex items-center gap-3 mb-4">
        <div class="w-11 h-11 sm:w-12 sm:h-12 flex-shrink-0 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold text-base sm:text-lg">
            <?php echo htmlspecialchars($initials); ?>
        </div>
        <div class="min-w-0">
            <h3 class="text-base sm:text-lg font-bold text-slate-900 truncate"><?php echo htmlspecialchars($booking['full_name']); ?></h3>
            <p class="text-xs text-slate-400">Client</p>
        </div>
    </div>

    <!-- ── PROBLEM, ADDRESS & BOOKING DATE ───────────────────────────────── -->
    <div class="space-y-3 mb-5">
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-slate-300 flex-shrink-0" style="font-size:20px;margin-top:1px;">build</span>
            <p class="text-sm text-slate-500 leading-snug">
                <?php echo htmlspecialchars(mb_substr($booking['problem_desc'], 0, 80)) . (mb_strlen($booking['problem_desc']) > 80 ? '…' : ''); ?>
            </p>
        </div>
        <?php if (!empty($booking['address'])): ?>
        <div class="flex items-start gap-3">
            <span class="material-symbols-outlined text-slate-300 flex-shrink-0" style="font-size:20px;margin-top:1px;">map</span>
            <p class="text-sm text-slate-500 leading-snug"><?php echo htmlspecialchars($booking['address']); ?></p>
        </div>
        <?php endif; ?>
        <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-slate-300 flex-shrink-0" style="font-size:20px;">calendar_today</span>
            <p class="text-sm text-slate-500">
                <?php echo htmlspecialchars($bookingDateDisplay); ?>
                <?php if (!empty($bookingTimeDisplay)): ?>
                <span class="text-slate-400 mx-1">·</span>
                <span><?php echo htmlspecialchars($bookingTimeDisplay); ?></span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- ── PRICE INFO ─────────────────────────────────────────────────────── -->
    <div class="flex items-center gap-4 sm:gap-6 mb-5">
        <div class="flex flex-col">
            <span class="text-[9px] sm:text-[10px] uppercase tracking-widest text-slate-400 font-bold">Mobilization</span>
            <span class="text-base sm:text-lg font-bold text-slate-900">₱<?php echo number_format($booking['calculated_fee'], 2); ?></span>
        </div>
        <?php if ($hasFinalCosts): ?>
        <div class="h-8 w-px bg-slate-200"></div>
        <div class="flex flex-col">
            <span class="text-[9px] sm:text-[10px] uppercase tracking-widest text-slate-400 font-bold">Total</span>
            <span class="text-base sm:text-lg font-bold text-green-600">₱<?php echo number_format($booking['total_final_cost'], 2); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ACTION BUTTONS ────────────────────────────────────────────────── -->
    <?php if ($type === 'pending'): ?>
    <div class="mt-auto pt-2">
        <?php if ($timerIsLive): ?>

            <button type="button"
                    class="quick-match-accept w-full px-4 py-3.5 text-white font-bold rounded-xl flex items-center justify-center gap-2 text-sm"
                    style="background:#16a34a;box-shadow:0 8px 20px -4px rgba(22,163,74,0.35);"
                    id="accept-btn-<?php echo (int)$booking['id']; ?>"
                    onclick="acceptQuickMatch(<?php echo (int)$booking['id']; ?>, <?php echo $broadcastId; ?>)">
                <span class="material-symbols-outlined" style="font-size:18px;">bolt</span>
                ACCEPT QUICK MATCH
            </button>

        <?php elseif ($isQuickMatch || $isExpiredQuickMatch): ?>
            <div class="flex gap-3">
                <button type="button"
                        class="quick-match-accept flex-1 px-4 py-3 bg-slate-300 text-slate-500 font-bold rounded-xl flex items-center justify-center gap-2 text-sm cursor-not-allowed opacity-60"
                        disabled>
                    <span class="material-symbols-outlined" style="font-size:18px;">schedule</span>
                    EXPIRED
                </button>
                <a href="../chat.php?booking_id=<?php echo (int)$booking['id']; ?>"
                   class="w-12 h-12 flex-shrink-0 rounded-xl border border-slate-200 text-slate-500 hover:border-blue-400 hover:text-blue-500 hover:bg-blue-50 transition-all flex items-center justify-center">
                    <span class="material-symbols-outlined" style="font-size:20px;">chat_bubble</span>
                </a>
            </div>

        <?php else: ?>
            <div class="flex gap-2">
                <button onclick="acceptBooking(<?php echo (int)$booking['id']; ?>)"
                        class="flex-1 min-w-0 px-4 py-3 text-white font-bold rounded-xl flex items-center justify-center gap-1.5 text-sm"
                        style="background:#16a34a;box-shadow:0 8px 20px -4px rgba(22,163,74,0.35);">
                    <span class="material-symbols-outlined" style="font-size:18px;">check_circle</span>
                    <span>Accept</span>
                </button>
                <button onclick="rejectBooking(<?php echo (int)$booking['id']; ?>)"
                        class="w-12 h-12 flex-shrink-0 rounded-xl border border-slate-200 text-slate-500 hover:border-red-400 hover:text-red-500 hover:bg-red-50 transition-all flex items-center justify-center">
                    <span class="material-symbols-outlined" style="font-size:20px;">close</span>
                </button>
                <a href="../chat.php?booking_id=<?php echo (int)$booking['id']; ?>"
                   class="w-12 h-12 flex-shrink-0 rounded-xl border border-slate-200 text-slate-500 hover:border-blue-400 hover:text-blue-500 hover:bg-blue-50 transition-all flex items-center justify-center">
                    <span class="material-symbols-outlined" style="font-size:20px;">chat_bubble</span>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <?php elseif ($type === 'active'): ?>
    <div class="grid grid-cols-3 gap-2 sm:gap-3 mt-auto pt-4 border-t border-slate-100">
        <!-- Chat -->
        <a href="../chat.php?booking_id=<?php echo (int)$booking['id']; ?>"
           class="flex items-center justify-center h-11 sm:h-12 rounded-xl border border-slate-200 text-slate-500 hover:border-blue-400 hover:text-blue-500 transition-all">
            <span class="material-symbols-outlined" style="font-size:20px;">message</span>
        </a>

        <!-- Navigate -->
        <?php if (!empty($booking['latitude']) && !empty($booking['longitude'])): ?>
        <a href="navigate.php?booking_id=<?php echo (int)$booking['id']; ?>"
           class="flex items-center justify-center h-11 sm:h-12 rounded-xl border border-slate-200 text-slate-500 hover:border-blue-400 hover:text-blue-500 transition-all">
            <span class="material-symbols-outlined" style="font-size:20px;">directions</span>
        </a>
        <?php else: ?>
        <div class="flex items-center justify-center h-11 sm:h-12 rounded-xl border border-slate-100 text-slate-300 cursor-not-allowed">
            <span class="material-symbols-outlined" style="font-size:20px;">directions_off</span>
        </div>
        <?php endif; ?>

        <!-- Complete / Receipt -->
        <?php if (!$hasFinalCosts): ?>
        <a href="complete_job.php?booking_id=<?php echo (int)$booking['id']; ?>"
           class="flex items-center justify-center h-11 sm:h-12 rounded-xl text-white"
           style="background:#16a34a;box-shadow:0 8px 20px -4px rgba(22,163,74,0.35);">
            <span class="material-symbols-outlined" style="font-size:20px;">check</span>
        </a>
        <?php else: ?>
        <a href="generate_receipt.php?booking_id=<?php echo (int)$booking['id']; ?>"
           class="flex items-center justify-center h-11 sm:h-12 rounded-xl bg-cyan-500 text-white hover:bg-cyan-600 transition-all">
            <span class="material-symbols-outlined" style="font-size:20px;">receipt</span>
        </a>
        <?php endif; ?>
    </div>

    <?php elseif ($type === 'pending_confirmation'): ?>
    <!-- ── PENDING CONFIRMATION ACTIONS ──────────────────────────────────── -->
    <div class="mt-auto pt-4 border-t border-slate-100 space-y-3">
        <!-- Status pill -->
        <div class="flex items-center justify-center gap-2 py-3 px-4 rounded-xl bg-amber-50 border border-amber-200">
            <span class="material-symbols-outlined text-amber-500" style="font-size:18px;font-variation-settings:'FILL' 1;animation:pulse 2s infinite;">hourglass_top</span>
            <span class="text-sm font-bold text-amber-700">Waiting for client to confirm</span>
        </div>
        <!-- View receipt link -->
        <a href="generate_receipt.php?booking_id=<?php echo (int)$booking['id']; ?>"
           class="flex items-center justify-center gap-2 h-11 rounded-xl border border-slate-200 text-slate-500 hover:border-cyan-400 hover:text-cyan-600 hover:bg-cyan-50 transition-all text-sm font-semibold">
            <span class="material-symbols-outlined" style="font-size:18px;">receipt</span>
            View Receipt
        </a>
        <!-- Chat -->
        <a href="../chat.php?booking_id=<?php echo (int)$booking['id']; ?>"
           class="flex items-center justify-center gap-2 h-11 rounded-xl border border-slate-200 text-slate-500 hover:border-blue-400 hover:text-blue-500 hover:bg-blue-50 transition-all text-sm font-semibold">
            <span class="material-symbols-outlined" style="font-size:18px;">message</span>
            Message Client
        </a>
    </div>

    <?php elseif ($type === 'history'): ?>
    <!-- ── HISTORY: view the completed receipt ─────────────────────────────── -->
    <div class="mt-auto pt-4 border-t border-slate-100">
        <a href="generate_receipt.php?booking_id=<?php echo (int)$booking['id']; ?>"
           class="flex items-center justify-center gap-2 h-11 rounded-xl border border-slate-200 text-slate-500 hover:border-cyan-400 hover:text-cyan-600 hover:bg-cyan-50 transition-all text-sm font-semibold">
            <span class="material-symbols-outlined" style="font-size:18px;">receipt_long</span>
            View Receipt
        </a>
    </div>

    <?php endif; ?>

</div>

<?php
}

endif;
?>