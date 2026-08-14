<?php
// includes/functions/wallet_functions.php
require_once __DIR__ . '/../../config/constants.php';

// Listo Points constants (also defined in wallet.php view; kept here for backend logic)
if (!defined('LISTO_POINTS_PER_JOB'))  define('LISTO_POINTS_PER_JOB',  5);
if (!defined('LISTO_POINTS_FOR_FREE')) define('LISTO_POINTS_FOR_FREE', 50);

class WalletManager {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Initialize new worker with free credits
     */
    public function initNewWorker($worker_id) {
        $check = $this->conn->query("SELECT user_id FROM worker_profiles WHERE user_id = $worker_id");
        
        if ($check->num_rows > 0) {
            $sql = "UPDATE worker_profiles 
                    SET free_credits = " . FREE_CREDIT_AMOUNT . ",
                        wallet_balance = COALESCE(wallet_balance, 0),
                        free_bookings_used = COALESCE(free_bookings_used, 0),
                        listo_points = COALESCE(listo_points, 0)
                    WHERE user_id = $worker_id";
        } else {
            $sql = "INSERT INTO worker_profiles (
                        user_id, service_category, free_credits, wallet_balance, 
                        free_bookings_used, listo_points, availability_status, verification_status
                    ) VALUES (
                        $worker_id, 'General', " . FREE_CREDIT_AMOUNT . ", 0, 0, 0, 'Available', 'Unverified'
                    )";
        }
        
        return $this->conn->query($sql);
    }
    
    /**
     * Get worker wallet info
     */
    public function getWorkerWallet($worker_id) {
        $sql = "SELECT wallet_balance, free_credits, free_bookings_used,
                       COALESCE(listo_points, 0) AS listo_points
                FROM worker_profiles WHERE user_id = $worker_id";
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        $this->initNewWorker($worker_id);
        $result = $this->conn->query($sql);
        return $result->fetch_assoc();
    }

    /**
     * Award Listo Points for a completed booking.
     * Called automatically from booking_actions.php 'complete' case.
     *
     * Rules:
     *   • +5 Listo Points per completed booking
     *   • Every time points reach a multiple of 50, award ₱30 free_credits
     *   • Points are NOT reset — they keep accumulating (milestones are based on integer division)
     *
     * @param  int  $worker_id
     * @param  int  $booking_id  Used as reference in transaction log
     * @return array  ['success'=>bool, 'message'=>string, 'milestone'=>bool, 'new_points'=>int]
     */
    public function awardListoPoints($worker_id, $booking_id) {
        $this->conn->begin_transaction();

        try {
            $worker_id  = intval($worker_id);
            $booking_id = intval($booking_id);

            // Fetch current points
            $res = $this->conn->query("SELECT COALESCE(listo_points,0) AS listo_points,
                                              free_credits
                                       FROM worker_profiles WHERE user_id = $worker_id FOR UPDATE");
            if (!$res) throw new Exception("Failed to fetch worker points: " . $this->conn->error);

            $row          = $res->fetch_assoc();
            $old_points   = intval($row['listo_points']);
            $free_credits = floatval($row['free_credits']);
            $new_points   = $old_points + LISTO_POINTS_PER_JOB;

            // Check milestone crossing: did we cross a new multiple of LISTO_POINTS_FOR_FREE?
            $old_milestones = intdiv($old_points, LISTO_POINTS_FOR_FREE);
            $new_milestones = intdiv($new_points, LISTO_POINTS_FOR_FREE);
            $milestone_hit  = ($new_milestones > $old_milestones);

            // Update listo_points (and optionally free_credits)
            if ($milestone_hit) {
                $reward_amount    = ADMIN_FEE_PER_BOOKING; // ₱30
                $new_free_credits = $free_credits + $reward_amount;
                $update_sql = "UPDATE worker_profiles
                               SET listo_points  = $new_points,
                                   free_credits  = $new_free_credits
                               WHERE user_id = $worker_id";
            } else {
                $update_sql = "UPDATE worker_profiles
                               SET listo_points = $new_points
                               WHERE user_id = $worker_id";
            }

            if (!$this->conn->query($update_sql)) {
                throw new Exception("Failed to update listo_points: " . $this->conn->error);
            }

            // Log the points award as a wallet_transaction for history display
            $desc = $this->conn->real_escape_string("Earned " . LISTO_POINTS_PER_JOB . " Listo Points for completing booking #$booking_id");
            $log_sql = "INSERT INTO wallet_transactions
                        (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at)
                        VALUES ($worker_id, 'worker', 'credit', " . LISTO_POINTS_PER_JOB . ", $booking_id, 'listo_points', '$desc', $new_points, NOW())";
            if (!$this->conn->query($log_sql)) {
                throw new Exception("Failed to log points transaction: " . $this->conn->error);
            }

            // If milestone hit, also log the free credit reward
            if ($milestone_hit) {
                $reward_amount = ADMIN_FEE_PER_BOOKING;
                $reward_desc   = $this->conn->real_escape_string("🎉 Listo Reward: Free booking credit awarded at $new_points pts");
                $reward_sql    = "INSERT INTO wallet_transactions
                                  (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at)
                                  VALUES ($worker_id, 'worker', 'credit', $reward_amount, $booking_id, 'listo_reward', '$reward_desc', $new_free_credits, NOW())";
                if (!$this->conn->query($reward_sql)) {
                    throw new Exception("Failed to log reward transaction: " . $this->conn->error);
                }
            }

            $this->conn->commit();

            return [
                'success'     => true,
                'message'     => $milestone_hit
                    ? "🎉 You earned a FREE booking credit! ($new_points Listo Points)"
                    : "+" . LISTO_POINTS_PER_JOB . " Listo Points earned! ($new_points total)",
                'milestone'   => $milestone_hit,
                'new_points'  => $new_points,
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("awardListoPoints failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'milestone' => false, 'new_points' => 0];
        }
    }
    
    /**
     * Process GCash payment - HOLD in admin wallet (escrow)
     */
    public function holdEscrowPayment($booking_id, $worker_id, $amount) {
        $this->conn->begin_transaction();
        
        try {
            $booking_id = intval($booking_id);
            $worker_id  = intval($worker_id);
            $amount     = floatval($amount);
            
            if ($booking_id <= 0 || $worker_id <= 0 || $amount <= 0) {
                throw new Exception("Invalid parameters: booking_id=$booking_id, worker_id=$worker_id, amount=$amount");
            }
            
            error_log("holdEscrowPayment: Starting with booking_id=$booking_id, worker_id=$worker_id, amount=$amount");
            
            $admin_check = $this->conn->query("SELECT * FROM admin_wallet WHERE id = 1");
            if (!$admin_check) throw new Exception("Failed to check admin wallet: " . $this->conn->error);
            
            if ($admin_check->num_rows == 0) {
                $create_admin = "INSERT INTO admin_wallet (id, balance, total_earned, total_withdrawn) VALUES (1, 0, 0, 0)";
                if (!$this->conn->query($create_admin)) throw new Exception("Failed to create admin wallet: " . $this->conn->error);
            }
            
            $admin_res     = $this->conn->query("SELECT balance FROM admin_wallet WHERE id = 1");
            if (!$admin_res) throw new Exception("Failed to get admin balance: " . $this->conn->error);
            $admin_row     = $admin_res->fetch_assoc();
            $admin_balance = floatval($admin_row['balance']);
            
            $new_admin_balance = $admin_balance + $amount;
            $update_admin      = "UPDATE admin_wallet SET balance = $new_admin_balance WHERE id = 1";
            if (!$this->conn->query($update_admin)) throw new Exception("Failed to update admin wallet: " . $this->conn->error);
            
            $description = $this->conn->real_escape_string("Escrow hold for booking #$booking_id");
            $tx_sql = "INSERT INTO wallet_transactions 
                      (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at) 
                      VALUES (1, 'admin', 'credit', $amount, $booking_id, 'booking', '$description', $new_admin_balance, NOW())";
            if (!$this->conn->query($tx_sql)) throw new Exception("Failed to record transaction: " . $this->conn->error);
            
            $update_booking = "UPDATE bookings SET is_escrow = 1, payment_status = 'Paid' WHERE id = $booking_id";
            if (!$this->conn->query($update_booking)) throw new Exception("Failed to update booking: " . $this->conn->error);
            
            $this->conn->commit();
            error_log("✅ Escrow hold successful for booking #$booking_id");
            return ['success' => true, 'message' => 'Payment held in escrow'];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("❌ Escrow hold failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Release escrow payment to worker (when they accept)
     */
    public function releaseEscrowPayment($booking_id, $worker_id, $amount) {
        $this->conn->begin_transaction();
        
        try {
            $booking_id = intval($booking_id);
            $worker_id  = intval($worker_id);
            $amount     = floatval($amount);
            
            if ($booking_id <= 0 || $worker_id <= 0 || $amount <= 0) throw new Exception("Invalid parameters");
            
            $booking_check = $this->conn->query("SELECT is_escrow, fee_deducted FROM bookings WHERE id = $booking_id");
            if (!$booking_check) throw new Exception("Failed to check booking: " . $this->conn->error);
            $booking = $booking_check->fetch_assoc();
            if (!$booking['is_escrow']) throw new Exception("Payment not in escrow");
            
            $worker        = $this->getWorkerWallet($worker_id);
            $admin         = $this->getAdminWallet();
            $worker_balance = floatval($worker['wallet_balance']);
            $admin_balance  = floatval($admin['balance']);
            
            $new_admin_balance = $admin_balance - $amount;
            $update_admin = "UPDATE admin_wallet SET balance = $new_admin_balance WHERE id = 1";
            if (!$this->conn->query($update_admin)) throw new Exception("Failed to update admin wallet: " . $this->conn->error);
            
            $new_worker_balance = $worker_balance + $amount;
            $update_worker = "UPDATE worker_profiles SET wallet_balance = $new_worker_balance WHERE user_id = $worker_id";
            if (!$this->conn->query($update_worker)) throw new Exception("Failed to update worker wallet: " . $this->conn->error);
            
            $worker_desc = $this->conn->real_escape_string("Payment released from escrow for booking #$booking_id");
            $admin_desc  = $this->conn->real_escape_string("Escrow released for booking #$booking_id");
            
            $worker_tx = $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'credit', 'amount' => $amount,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $worker_desc, 'balance_after' => $new_worker_balance
            ]);
            if (!$worker_tx) throw new Exception("Failed to record worker transaction: " . $this->conn->error);
            
            $admin_tx = $this->addTransaction([
                'user_id' => 1, 'user_type' => 'admin', 'type' => 'debit', 'amount' => $amount,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $admin_desc, 'balance_after' => $new_admin_balance
            ]);
            if (!$admin_tx) throw new Exception("Failed to record admin transaction: " . $this->conn->error);
            
            $update_booking = "UPDATE bookings SET is_escrow = 0, escrow_released_at = NOW() WHERE id = $booking_id";
            if (!$this->conn->query($update_booking)) throw new Exception("Failed to update booking: " . $this->conn->error);
            
            if (!$booking['fee_deducted']) {
                $fee_result = $this->deductAdminFee($booking_id, $worker_id);
                if (!$fee_result['success']) throw new Exception("Fee deduction failed: " . $fee_result['message']);
            }
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Payment released to worker'];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Escrow release failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Refund escrow payment (when worker rejects)
     */
    public function refundEscrowPayment($booking_id, $client_id, $amount) {
        $this->conn->begin_transaction();
        
        try {
            $booking_id = intval($booking_id);
            $client_id  = intval($client_id);
            $amount     = floatval($amount);
            
            if ($booking_id <= 0 || $client_id <= 0 || $amount <= 0) throw new Exception("Invalid parameters");
            
            $admin         = $this->getAdminWallet();
            $admin_balance = floatval($admin['balance']);
            $new_admin_balance = $admin_balance - $amount;
            
            $update_admin = "UPDATE admin_wallet SET balance = $new_admin_balance WHERE id = 1";
            if (!$this->conn->query($update_admin)) throw new Exception("Failed to update admin wallet: " . $this->conn->error);
            
            $description = $this->conn->real_escape_string("Refunded to client for booking #$booking_id");
            $admin_tx = $this->addTransaction([
                'user_id' => 1, 'user_type' => 'admin', 'type' => 'refund', 'amount' => $amount,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $description, 'balance_after' => $new_admin_balance
            ]);
            if (!$admin_tx) throw new Exception("Failed to record admin transaction: " . $this->conn->error);
            
            $update_booking = "UPDATE bookings SET is_escrow = 0, payment_status = 'Refunded' WHERE id = $booking_id";
            if (!$this->conn->query($update_booking)) throw new Exception("Failed to update booking: " . $this->conn->error);
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Payment refunded'];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Escrow refund failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Deduct admin fee from worker wallet (₱30)
     */
    public function deductAdminFee($booking_id, $worker_id) {
        $this->conn->begin_transaction();
        
        try {
            $booking_id = intval($booking_id);
            $worker_id  = intval($worker_id);
            $worker     = $this->getWorkerWallet($worker_id);
            $fee        = ADMIN_FEE_PER_BOOKING;
            
            $booking_check = $this->conn->query("SELECT payment_method FROM bookings WHERE id = $booking_id");
            if (!$booking_check) throw new Exception("Failed to get booking details: " . $this->conn->error);
            $booking = $booking_check->fetch_assoc();
            
            $description       = "Admin fee for booking #$booking_id";
            $new_balance       = floatval($worker['wallet_balance']);
            $new_free_credits  = floatval($worker['free_credits']);
            $new_free_bookings = intval($worker['free_bookings_used']);
            
            if ($booking['payment_method'] == 'Cash' && $worker['free_credits'] >= $fee) {
                $new_free_credits  = $worker['free_credits'] - $fee;
                $new_free_bookings = $worker['free_bookings_used'] + 1;
                $description      .= " (used free credit)";
                $update_worker = "UPDATE worker_profiles 
                                   SET free_credits = $new_free_credits,
                                       free_bookings_used = $new_free_bookings
                                   WHERE user_id = $worker_id";
                if (!$this->conn->query($update_worker)) throw new Exception("Failed to update worker free credits: " . $this->conn->error);
            } else {
                if ($worker['wallet_balance'] < $fee) throw new Exception(ERR_INSUFFICIENT_FUNDS);
                $new_balance   = $worker['wallet_balance'] - $fee;
                $update_worker = "UPDATE worker_profiles SET wallet_balance = $new_balance WHERE user_id = $worker_id";
                if (!$this->conn->query($update_worker)) throw new Exception("Failed to update worker wallet: " . $this->conn->error);
            }
            
            $escaped_description = $this->conn->real_escape_string($description);
            $worker_tx = $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'fee', 'amount' => $fee,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $escaped_description, 'balance_after' => $new_balance
            ]);
            if (!$worker_tx) throw new Exception("Failed to record worker transaction: " . $this->conn->error);
            
            $admin              = $this->getAdminWallet();
            $new_admin_balance  = floatval($admin['balance']) + $fee;
            $new_total_earned   = floatval($admin['total_earned']) + $fee;
            $update_admin = "UPDATE admin_wallet SET balance = $new_admin_balance, total_earned = $new_total_earned WHERE id = 1";
            if (!$this->conn->query($update_admin)) throw new Exception("Failed to update admin wallet: " . $this->conn->error);
            
            $admin_desc = $this->conn->real_escape_string("Admin fee from booking #$booking_id");
            $admin_tx = $this->addTransaction([
                'user_id' => 1, 'user_type' => 'admin', 'type' => 'fee', 'amount' => $fee,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $admin_desc, 'balance_after' => $new_admin_balance
            ]);
            if (!$admin_tx) throw new Exception("Failed to record admin transaction: " . $this->conn->error);
            
            $update_booking = "UPDATE bookings SET fee_deducted = 1 WHERE id = $booking_id";
            if (!$this->conn->query($update_booking)) throw new Exception("Failed to update booking fee status: " . $this->conn->error);
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Fee deducted'];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Fee deduction failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Check if worker can accept booking
     */
    public function canAcceptBooking($worker_id, $payment_method) {
        $worker = $this->getWorkerWallet($worker_id);
        $fee    = ADMIN_FEE_PER_BOOKING;
        return ($worker['wallet_balance'] >= $fee || $worker['free_credits'] >= $fee);
    }
    
    /**
     * Process worker top-up
     */
    public function processTopUp($worker_id, $amount, $payment_method, $reference = null) {
        $this->conn->begin_transaction();
        
        try {
            $worker_id = intval($worker_id);
            $amount    = floatval($amount);
            if ($worker_id <= 0 || $amount <= 0) throw new Exception("Invalid parameters");
            
            $escaped_ref    = $reference ? $this->conn->real_escape_string($reference) : null;
            $insert_topup   = "INSERT INTO top_ups 
                (worker_id, amount, payment_method, reference_number, status, completed_at) 
                VALUES ($worker_id, $amount, '$payment_method', " . 
                ($escaped_ref ? "'$escaped_ref'" : "NULL") . ", 'completed', NOW())";
            if (!$this->conn->query($insert_topup)) throw new Exception("Failed to create top-up record: " . $this->conn->error);
            
            $top_up_id   = $this->conn->insert_id;
            $worker      = $this->getWorkerWallet($worker_id);
            $new_balance = floatval($worker['wallet_balance']) + $amount;
            
            $update_wallet = "UPDATE worker_profiles SET wallet_balance = $new_balance WHERE user_id = $worker_id";
            if (!$this->conn->query($update_wallet)) throw new Exception("Failed to update wallet: " . $this->conn->error);
            
            $description = $this->conn->real_escape_string("Wallet Top-up");
            $tx_result = $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'credit', 'amount' => $amount,
                'ref_id' => $top_up_id, 'ref_type' => 'topup', 'description' => $description, 'balance_after' => $new_balance
            ]);
            if (!$tx_result) throw new Exception("Failed to record transaction: " . $this->conn->error);
            
            $this->conn->commit();
            return ['success' => true, 'message' => SUCC_TOPUP];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Top-up failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Process withdrawal request
     */
    public function requestWithdrawal($worker_id, $amount, $gcash_number) {
        $worker = $this->getWorkerWallet($worker_id);
        if ($amount < MIN_WITHDRAWAL) return ['success' => false, 'message' => ERR_MIN_WITHDRAWAL];
        if ($worker['wallet_balance'] < $amount) return ['success' => false, 'message' => ERR_INSUFFICIENT_FUNDS];
        
        $this->conn->begin_transaction();
        
        try {
            $worker_id     = intval($worker_id);
            $amount        = floatval($amount);
            $new_balance   = floatval($worker['wallet_balance']) - $amount;
            $update_wallet = "UPDATE worker_profiles SET wallet_balance = $new_balance WHERE user_id = $worker_id";
            if (!$this->conn->query($update_wallet)) throw new Exception("Failed to update wallet: " . $this->conn->error);
            
            $escaped_gcash    = $this->conn->real_escape_string($gcash_number);
            $insert_withdrawal = "INSERT INTO withdrawals 
                (worker_id, amount, gcash_number, status, request_date) 
                VALUES ($worker_id, $amount, '$escaped_gcash', 'Pending', NOW())";
            if (!$this->conn->query($insert_withdrawal)) throw new Exception("Failed to create withdrawal record: " . $this->conn->error);
            
            $withdrawal_id = $this->conn->insert_id;
            $description   = $this->conn->real_escape_string("Withdrawal request to GCash $gcash_number");
            $tx_result     = $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'withdrawal', 'amount' => $amount,
                'ref_id' => $withdrawal_id, 'ref_type' => 'withdrawal', 'description' => $description, 'balance_after' => $new_balance
            ]);
            if (!$tx_result) throw new Exception("Failed to record transaction: " . $this->conn->error);
            
            $this->conn->commit();
            return ['success' => true, 'message' => SUCC_WITHDRAWAL];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Withdrawal failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Process final payment commission (4% of total_final_cost)
     * Called after cash confirmation or after successful GCash/Xendit final payment.
     * Deducts 4% from worker wallet_balance and credits admin wallet.
     *
     * @param  int    $booking_id
     * @param  int    $worker_id
     * @param  float  $total_final_cost   The full job cost (labor + materials + mobilization)
     * @param  string $payment_method     'Cash' or 'GCash'
     * @return array  ['success'=>bool, 'message'=>string, 'commission'=>float]
     */
    public function processFinalPaymentCommission($booking_id, $worker_id, $total_final_cost, $payment_method = 'Cash') {
        $this->conn->begin_transaction();

        try {
            $booking_id       = intval($booking_id);
            $worker_id        = intval($worker_id);
            $total_final_cost = floatval($total_final_cost);

            if ($booking_id <= 0 || $worker_id <= 0 || $total_final_cost <= 0) {
                throw new Exception("Invalid parameters: booking=$booking_id, worker=$worker_id, cost=$total_final_cost");
            }

            // Prevent double-charging: check if commission already processed
            $check = $this->conn->query(
                "SELECT final_commission_deducted FROM bookings WHERE id = $booking_id"
            );
            if (!$check) throw new Exception("Failed to check booking: " . $this->conn->error);
            $brow = $check->fetch_assoc();
            if ($brow && !empty($brow['final_commission_deducted'])) {
                return ['success' => true, 'message' => 'Commission already processed', 'commission' => 0];
            }

            // Calculate commission — see config/constants.php (ADMIN_COMMISSION_PERCENT)
            $commission = round($total_final_cost * (ADMIN_COMMISSION_PERCENT / 100), 2);

            // Fetch worker wallet
            $worker = $this->getWorkerWallet($worker_id);
            if (floatval($worker['wallet_balance']) < $commission) {
                throw new Exception("Worker wallet insufficient for commission. Balance: {$worker['wallet_balance']}, Commission: $commission");
            }

            $new_worker_balance = floatval($worker['wallet_balance']) - $commission;

            // Deduct from worker
            $upd_worker = "UPDATE worker_profiles 
                           SET wallet_balance = $new_worker_balance 
                           WHERE user_id = $worker_id";
            if (!$this->conn->query($upd_worker)) {
                throw new Exception("Failed to deduct worker commission: " . $this->conn->error);
            }

            // Log worker debit
            $w_desc = $this->conn->real_escape_string(
                "4% commission on final payment (₱" . number_format($total_final_cost, 2) . ") for booking #$booking_id via $payment_method"
            );
            $this->addTransaction([
                'user_id'      => $worker_id,
                'user_type'    => 'worker',
                'type'         => 'fee',
                'amount'       => $commission,
                'ref_id'       => $booking_id,
                'ref_type'     => 'final_commission',
                'description'  => $w_desc,
                'balance_after'=> $new_worker_balance,
            ]);

            // Credit admin wallet
            $admin = $this->getAdminWallet();
            $new_admin_balance = floatval($admin['balance']) + $commission;
            $new_total_earned  = floatval($admin['total_earned']) + $commission;

            $upd_admin = "UPDATE admin_wallet 
                          SET balance = $new_admin_balance, 
                              total_earned = $new_total_earned 
                          WHERE id = 1";
            if (!$this->conn->query($upd_admin)) {
                throw new Exception("Failed to credit admin wallet: " . $this->conn->error);
            }

            // Log admin credit
            $a_desc = $this->conn->real_escape_string(
                "4% commission from booking #$booking_id final payment ($payment_method) — worker #$worker_id"
            );
            $this->addTransaction([
                'user_id'      => 1,
                'user_type'    => 'admin',
                'type'         => 'fee',
                'amount'       => $commission,
                'ref_id'       => $booking_id,
                'ref_type'     => 'final_commission',
                'description'  => $a_desc,
                'balance_after'=> $new_admin_balance,
            ]);

            // Mark commission as processed on the booking
            $mark = "UPDATE bookings SET final_commission_deducted = 1 WHERE id = $booking_id";
            if (!$this->conn->query($mark)) {
                throw new Exception("Failed to mark commission on booking: " . $this->conn->error);
            }

            $this->conn->commit();
            error_log("✅ Final payment commission processed: ₱$commission from worker #$worker_id for booking #$booking_id");

            return ['success' => true, 'message' => "Commission of ₱$commission processed.", 'commission' => $commission];

        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("❌ processFinalPaymentCommission failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'commission' => 0];
        }
    }

    /**
     * Get admin wallet (create if not exists)
     */
    private function getAdminWallet() {
        $result = $this->conn->query("SELECT * FROM admin_wallet WHERE id = 1");
        if ($result->num_rows == 0) {
            $this->conn->query("INSERT INTO admin_wallet (id, balance, total_earned, total_withdrawn) VALUES (1, 0, 0, 0)");
            return ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0];
        }
        return $result->fetch_assoc();
    }
    
    /**
     * Add transaction record
     */
    private function addTransaction($data) {
        $user_id      = intval($data['user_id']);
        $user_type    = $this->conn->real_escape_string($data['user_type']);
        $type         = $this->conn->real_escape_string($data['type']);
        $amount       = floatval($data['amount']);
        $ref_id       = intval($data['ref_id']);
        $ref_type     = $this->conn->real_escape_string($data['ref_type']);
        $description  = isset($data['description']) ? $this->conn->real_escape_string($data['description']) : '';
        $balance_after = floatval($data['balance_after']);
        
        $sql = "INSERT INTO wallet_transactions 
                (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at) 
                VALUES ($user_id, '$user_type', '$type', $amount, $ref_id, '$ref_type', '$description', $balance_after, NOW())";
        
        $result = $this->conn->query($sql);
        if (!$result) {
            error_log("Failed to add transaction: " . $this->conn->error);
            error_log("SQL: " . $sql);
        }
        return $result;
    }
    
    /**
     * Get worker transaction history
     */
    public function getTransactionHistory($worker_id, $limit = 50) {
        $worker_id = intval($worker_id);
        $limit     = intval($limit);
        $sql = "SELECT wt.*, b.status as booking_status 
                FROM wallet_transactions wt
                LEFT JOIN bookings b ON wt.reference_id = b.id AND wt.reference_type = 'booking'
                WHERE wt.user_id = $worker_id AND wt.user_type = 'worker'
                ORDER BY wt.created_at DESC
                LIMIT $limit";
        return $this->conn->query($sql);
    }
    
    /**
     * Get pending escrow amounts
     */
    public function getPendingEscrow() {
        $sql = "SELECT COUNT(*) as count, SUM(calculated_fee) as total 
                FROM bookings 
                WHERE payment_method = 'Xendit' 
                AND payment_status = 'Paid' 
                AND is_escrow = 1
                AND status = 'Pending'";
        return $this->conn->query($sql)->fetch_assoc();
    }
}
?>