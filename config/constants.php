<?php
// config/constants.php
// Central configuration for all payment settings

// Prevent multiple definitions
if (!defined('ADMIN_FEE_PER_BOOKING')) {
    // Fee Configuration
    define('ADMIN_FEE_PER_BOOKING', 30); // ₱30 per booking
    define('FREE_CREDIT_AMOUNT', 100); // ₱100 free for new workers
    define('FREE_BOOKINGS_LIMIT', 3); // First 3 bookings covered by free credits

    // Platform commission on job completion (labor fee only, not materials)
    // Single source of truth — was previously duplicated as separate hardcoded
    // 4.00/0.04 literals in worker/complete_job.php (PHP and embedded JS) and
    // includes/functions/wallet_manager.php.
    define('ADMIN_COMMISSION_PERCENT', 4.00); // 4%

    // Urgency Fees (as array - can't use define for array in older PHP, so use variable)
    $URGENCY_FEES = [
        'Normal' => 0,
        'High' => 50,
        'Emergency' => 100
    ];
    define('URGENCY_FEES', serialize($URGENCY_FEES)); // Store as serialized if needed

    // GCash Discount
    define('GCASH_DISCOUNT_PERCENT', 10); // 10% discount for GCash payments

    // Wallet Limits
    define('MIN_WITHDRAWAL', 100);
    define('MIN_TOPUP', 50);
    define('MAX_TOPUP', 5000);

    // Distance Calculation
    define('BASE_FEE', 50);
    define('PER_KM_FEE', 10);

    // Booking Statuses
    define('BOOKING_STATUS_PENDING', 'Pending');
    define('BOOKING_STATUS_ACCEPTED', 'Accepted');
    define('BOOKING_STATUS_DECLINED', 'Declined');
    define('BOOKING_STATUS_COMPLETED', 'Completed');
    define('BOOKING_STATUS_CANCELLED', 'Cancelled');

    // Payment Statuses
    define('PAYMENT_PENDING', 'Pending');
    define('PAYMENT_PAID', 'Paid');
    define('PAYMENT_REFUNDED', 'Refunded');
    define('PAYMENT_FAILED', 'Failed');

    // Transaction Types
    define('TX_CREDIT', 'credit');
    define('TX_DEBIT', 'debit');
    define('TX_FEE', 'fee');
    define('TX_REFUND', 'refund');
    define('TX_WITHDRAWAL', 'withdrawal');

    // Success/Error Messages
    define('ERR_INSUFFICIENT_FUNDS', 'Insufficient funds. Please top up your wallet.');
    define('ERR_MIN_WITHDRAWAL', 'Minimum withdrawal amount is ₱' . MIN_WITHDRAWAL);
    define('ERR_MIN_TOPUP', 'Minimum top-up amount is ₱' . MIN_TOPUP);
    define('SUCC_WITHDRAWAL', 'Withdrawal request submitted successfully!');
    define('SUCC_TOPUP', 'Wallet topped up successfully!');
}