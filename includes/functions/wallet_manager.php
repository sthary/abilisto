<?php
// includes/functions/wallet_manager.php
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
     * Start a transaction unless the connection is already inside one
     * (e.g. a caller like api/booking_actions.php already opened one around
     * several WalletManager calls). Returns whether THIS call owns it, so
     * only the owner commits/rolls back — avoids PDO's "already an active
     * transaction" error from nested beginTransaction() calls.
     */
    private function beginTx() {
        if ($this->conn->inTransaction()) {
            return false;
        }
        $this->conn->beginTransaction();
        return true;
    }

    private function commitTx($owns) {
        if ($owns) $this->conn->commit();
    }

    private function rollbackTx($owns) {
        if ($owns) $this->conn->rollBack();
    }

    /**
     * Guarantee admin_wallet's single row (id=1) exists, race-safely.
     * ON CONFLICT DO NOTHING means two concurrent first-ever callers never
     * collide (unlike the old SELECT-then-INSERT pattern this replaces).
     */
    private function ensureAdminWalletExists() {
        $this->conn->exec("INSERT INTO admin_wallet (id, balance, total_earned, total_withdrawn)
                            VALUES (1, 0, 0, 0) ON CONFLICT (id) DO NOTHING");
    }

    /**
     * Initialize new worker with free credits
     */
    public function initNewWorker($worker_id) {
        $check = $this->conn->prepare("SELECT user_id FROM worker_profiles WHERE user_id = ?");
        $check->execute([$worker_id]);

        if ($check->fetch()) {
            $stmt = $this->conn->prepare("UPDATE worker_profiles
                    SET free_credits = ?,
                        wallet_balance = COALESCE(wallet_balance, 0),
                        free_bookings_used = COALESCE(free_bookings_used, 0),
                        listo_points = COALESCE(listo_points, 0)
                    WHERE user_id = ?");
            return $stmt->execute([FREE_CREDIT_AMOUNT, $worker_id]);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO worker_profiles (
                        user_id, service_category, free_credits, wallet_balance,
                        free_bookings_used, listo_points, availability_status, verification_status
                    ) VALUES (
                        ?, 'General', ?, 0, 0, 0, 'Available', 'Unverified'
                    )");
            return $stmt->execute([$worker_id, FREE_CREDIT_AMOUNT]);
        }
    }

    /**
     * Get worker wallet info
     */
    public function getWorkerWallet($worker_id) {
        $sql = "SELECT wallet_balance, free_credits, free_bookings_used,
                       COALESCE(listo_points, 0) AS listo_points
                FROM worker_profiles WHERE user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$worker_id]);
        $row = $stmt->fetch();

        if ($row) {
            return $row;
        }

        $this->initNewWorker($worker_id);
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$worker_id]);
        return $stmt->fetch();
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
        $owns_tx = $this->beginTx();

        try {
            $worker_id  = intval($worker_id);
            $booking_id = intval($booking_id);

            // Fetch current points
            $res = $this->conn->prepare("SELECT COALESCE(listo_points,0) AS listo_points,
                                              free_credits
                                       FROM worker_profiles WHERE user_id = ? FOR UPDATE");
            $res->execute([$worker_id]);

            $row          = $res->fetch();
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
                $update = $this->conn->prepare("UPDATE worker_profiles
                               SET listo_points  = ?,
                                   free_credits  = ?
                               WHERE user_id = ?");
                $update->execute([$new_points, $new_free_credits, $worker_id]);
            } else {
                $update = $this->conn->prepare("UPDATE worker_profiles
                               SET listo_points = ?
                               WHERE user_id = ?");
                $update->execute([$new_points, $worker_id]);
            }

            // Log the points award as a wallet_transaction for history display
            $desc = "Earned " . LISTO_POINTS_PER_JOB . " Listo Points for completing booking #$booking_id";
            $log = $this->conn->prepare("INSERT INTO wallet_transactions
                        (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at)
                        VALUES (?, 'worker', 'credit', ?, ?, 'listo_points', ?, ?, NOW())");
            $log->execute([$worker_id, LISTO_POINTS_PER_JOB, $booking_id, $desc, $new_points]);

            // If milestone hit, also log the free credit reward
            if ($milestone_hit) {
                $reward_amount = ADMIN_FEE_PER_BOOKING;
                $reward_desc   = "🎉 Listo Reward: Free booking credit awarded at $new_points pts";
                $reward = $this->conn->prepare("INSERT INTO wallet_transactions
                                  (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at)
                                  VALUES (?, 'worker', 'credit', ?, ?, 'listo_reward', ?, ?, NOW())");
                $reward->execute([$worker_id, $reward_amount, $booking_id, $reward_desc, $new_free_credits]);
            }

            $this->commitTx($owns_tx);

            return [
                'success'     => true,
                'message'     => $milestone_hit
                    ? "🎉 You earned a FREE booking credit! ($new_points Listo Points)"
                    : "+" . LISTO_POINTS_PER_JOB . " Listo Points earned! ($new_points total)",
                'milestone'   => $milestone_hit,
                'new_points'  => $new_points,
            ];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("awardListoPoints failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'milestone' => false, 'new_points' => 0];
        }
    }

    /**
     * Process GCash/PayMongo payment - HOLD in admin wallet (escrow).
     *
     * Idempotent: the UPDATE ... WHERE is_escrow = FALSE clause atomically
     * claims the booking for escrow-hold. If another caller already claimed
     * it (webhook vs. browser redirect landing page both call this for the
     * same payment — that's normal, not a bug), zero rows are affected and
     * we return success without moving any money a second time. This is
     * what makes it safe for BOTH the webhook and the redirect page to call
     * this unconditionally, instead of relying on every caller remembering
     * to check first.
     */
    public function holdEscrowPayment($booking_id, $worker_id, $amount) {
        $owns_tx = $this->beginTx();

        try {
            $booking_id = intval($booking_id);
            $worker_id  = intval($worker_id);
            $amount     = floatval($amount);

            if ($booking_id <= 0 || $worker_id <= 0 || $amount <= 0) {
                throw new Exception("Invalid parameters: booking_id=$booking_id, worker_id=$worker_id, amount=$amount");
            }

            $claim = $this->conn->prepare("UPDATE bookings
                          SET is_escrow = TRUE, payment_status = 'Paid'
                          WHERE id = ? AND is_escrow = FALSE");
            $claim->execute([$booking_id]);

            if ($claim->rowCount() === 0) {
                error_log("holdEscrowPayment: booking #$booking_id already escrowed — no-op");
                $this->commitTx($owns_tx);
                return ['success' => true, 'message' => 'Payment already held in escrow (no-op)'];
            }

            $this->ensureAdminWalletExists();

            $update_admin = $this->conn->prepare("UPDATE admin_wallet SET balance = balance + ? WHERE id = 1 RETURNING balance");
            $update_admin->execute([$amount]);
            $new_admin_balance = floatval($update_admin->fetchColumn());

            $description = "Escrow hold for booking #$booking_id";
            $tx = $this->conn->prepare("INSERT INTO wallet_transactions
                      (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at)
                      VALUES (1, 'admin', 'credit', ?, ?, 'booking', ?, ?, NOW())");
            $tx->execute([$amount, $booking_id, $description, $new_admin_balance]);

            $this->commitTx($owns_tx);
            error_log("✅ Escrow hold successful for booking #$booking_id");
            return ['success' => true, 'message' => 'Payment held in escrow'];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("❌ Escrow hold failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Release escrow payment to worker (when they accept).
     * Idempotent via the same atomic-claim pattern as holdEscrowPayment.
     */
    public function releaseEscrowPayment($booking_id, $worker_id, $amount) {
        $owns_tx = $this->beginTx();

        try {
            $booking_id = intval($booking_id);
            $worker_id  = intval($worker_id);
            $amount     = floatval($amount);

            if ($booking_id <= 0 || $worker_id <= 0 || $amount <= 0) throw new Exception("Invalid parameters");

            // Atomically claim the release (is_escrow TRUE -> FALSE). Zero rows
            // affected means it's either not in escrow or already released —
            // either way, no-op rather than erroring, so double-calls are safe.
            $claim = $this->conn->prepare("UPDATE bookings
                          SET is_escrow = FALSE, escrow_released_at = NOW()
                          WHERE id = ? AND is_escrow = TRUE");
            $claim->execute([$booking_id]);

            if ($claim->rowCount() === 0) {
                error_log("releaseEscrowPayment: booking #$booking_id not in escrow — no-op");
                $this->commitTx($owns_tx);
                return ['success' => true, 'message' => 'Payment not in escrow (no-op)'];
            }

            $this->ensureAdminWalletExists();

            $upd_admin = $this->conn->prepare("UPDATE admin_wallet SET balance = balance - ? WHERE id = 1 RETURNING balance");
            $upd_admin->execute([$amount]);
            $new_admin_balance = floatval($upd_admin->fetchColumn());

            $upd_worker = $this->conn->prepare("UPDATE worker_profiles SET wallet_balance = wallet_balance + ? WHERE user_id = ? RETURNING wallet_balance");
            $upd_worker->execute([$amount, $worker_id]);
            $new_worker_balance = floatval($upd_worker->fetchColumn());

            $worker_desc = "Payment released from escrow for booking #$booking_id";
            $admin_desc  = "Escrow released for booking #$booking_id";

            $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'credit', 'amount' => $amount,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $worker_desc, 'balance_after' => $new_worker_balance
            ]);

            $this->addTransaction([
                'user_id' => 1, 'user_type' => 'admin', 'type' => 'debit', 'amount' => $amount,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $admin_desc, 'balance_after' => $new_admin_balance
            ]);

            $booking_check = $this->conn->prepare("SELECT fee_deducted FROM bookings WHERE id = ?");
            $booking_check->execute([$booking_id]);
            $booking = $booking_check->fetch();

            if (!$booking['fee_deducted']) {
                $fee_result = $this->deductAdminFee($booking_id, $worker_id);
                if (!$fee_result['success']) throw new Exception("Fee deduction failed: " . $fee_result['message']);
            }

            $this->commitTx($owns_tx);
            return ['success' => true, 'message' => 'Payment released to worker'];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("Escrow release failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Refund escrow payment (when worker rejects). Idempotent via the same
     * atomic-claim pattern.
     */
    public function refundEscrowPayment($booking_id, $client_id, $amount) {
        $owns_tx = $this->beginTx();

        try {
            $booking_id = intval($booking_id);
            $client_id  = intval($client_id);
            $amount     = floatval($amount);

            if ($booking_id <= 0 || $client_id <= 0 || $amount <= 0) throw new Exception("Invalid parameters");

            $claim = $this->conn->prepare("UPDATE bookings
                          SET is_escrow = FALSE, payment_status = 'Refunded'
                          WHERE id = ? AND is_escrow = TRUE");
            $claim->execute([$booking_id]);

            if ($claim->rowCount() === 0) {
                error_log("refundEscrowPayment: booking #$booking_id not in escrow — no-op");
                $this->commitTx($owns_tx);
                return ['success' => true, 'message' => 'Payment not in escrow (no-op)'];
            }

            $this->ensureAdminWalletExists();

            $upd_admin = $this->conn->prepare("UPDATE admin_wallet SET balance = balance - ? WHERE id = 1 RETURNING balance");
            $upd_admin->execute([$amount]);
            $new_admin_balance = floatval($upd_admin->fetchColumn());

            $description = "Refunded to client for booking #$booking_id";
            $this->addTransaction([
                'user_id' => 1, 'user_type' => 'admin', 'type' => 'refund', 'amount' => $amount,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $description, 'balance_after' => $new_admin_balance
            ]);

            $this->commitTx($owns_tx);
            return ['success' => true, 'message' => 'Payment refunded'];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("Escrow refund failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Deduct admin fee from worker wallet (₱30). Each branch is a single
     * atomic conditional UPDATE (no separate read-then-write race window).
     */
    public function deductAdminFee($booking_id, $worker_id) {
        $owns_tx = $this->beginTx();

        try {
            $booking_id = intval($booking_id);
            $worker_id  = intval($worker_id);
            $fee        = ADMIN_FEE_PER_BOOKING;

            $booking_check = $this->conn->prepare("SELECT payment_method FROM bookings WHERE id = ?");
            $booking_check->execute([$booking_id]);
            $booking = $booking_check->fetch();

            $description  = "Admin fee for booking #$booking_id";
            $new_balance  = null;

            if ($booking['payment_method'] == 'Cash') {
                // Try free credits first, atomically (only succeeds if enough is available).
                $upd_credits = $this->conn->prepare("UPDATE worker_profiles
                                   SET free_credits = free_credits - ?,
                                       free_bookings_used = free_bookings_used + 1
                                   WHERE user_id = ? AND free_credits >= ?
                                   RETURNING free_credits, wallet_balance");
                $upd_credits->execute([$fee, $worker_id, $fee]);
                $credit_row = $upd_credits->fetch();

                if ($credit_row) {
                    $description .= " (used free credit)";
                    $new_balance  = floatval($credit_row['wallet_balance']);
                }
            }

            if ($new_balance === null) {
                // Either not Cash, or free credits were insufficient — fall back to wallet_balance.
                $upd_wallet = $this->conn->prepare("UPDATE worker_profiles
                                  SET wallet_balance = wallet_balance - ?
                                  WHERE user_id = ? AND wallet_balance >= ?
                                  RETURNING wallet_balance");
                $upd_wallet->execute([$fee, $worker_id, $fee]);
                $wallet_row = $upd_wallet->fetch();

                if (!$wallet_row) throw new Exception(ERR_INSUFFICIENT_FUNDS);
                $new_balance = floatval($wallet_row['wallet_balance']);
            }

            $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'fee', 'amount' => $fee,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $description, 'balance_after' => $new_balance
            ]);

            $this->ensureAdminWalletExists();

            $upd_admin = $this->conn->prepare("UPDATE admin_wallet
                          SET balance = balance + ?, total_earned = total_earned + ?
                          WHERE id = 1 RETURNING balance");
            $upd_admin->execute([$fee, $fee]);
            $new_admin_balance = floatval($upd_admin->fetchColumn());

            $admin_desc = "Admin fee from booking #$booking_id";
            $this->addTransaction([
                'user_id' => 1, 'user_type' => 'admin', 'type' => 'fee', 'amount' => $fee,
                'ref_id' => $booking_id, 'ref_type' => 'booking', 'description' => $admin_desc, 'balance_after' => $new_admin_balance
            ]);

            $update_booking = $this->conn->prepare("UPDATE bookings SET fee_deducted = TRUE WHERE id = ?");
            $update_booking->execute([$booking_id]);

            $this->commitTx($owns_tx);
            return ['success' => true, 'message' => 'Fee deducted'];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
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
     * Process a worker top-up. $topup_id MUST be the id of the 'pending'
     * top_ups row created before redirecting to the payment gateway — this
     * function atomically flips it to 'completed', which is what makes it
     * idempotent (WHERE status = 'pending' guard). Callers must already have
     * verified the payment with the gateway before calling this — this
     * function trusts its caller completely, it does not re-verify.
     */
    public function processTopUp($worker_id, $amount, $topup_id, $reference = null) {
        $owns_tx = $this->beginTx();

        try {
            $worker_id = intval($worker_id);
            $amount    = floatval($amount);
            $topup_id  = intval($topup_id);
            if ($worker_id <= 0 || $amount <= 0 || $topup_id <= 0) throw new Exception("Invalid parameters");

            $claim = $this->conn->prepare("UPDATE top_ups
                SET status = 'completed', completed_at = NOW(), reference_number = ?
                WHERE id = ? AND worker_id = ? AND status = 'pending'");
            $claim->execute([$reference, $topup_id, $worker_id]);

            if ($claim->rowCount() === 0) {
                error_log("processTopUp: top_up #$topup_id already completed or not found — no-op");
                $this->commitTx($owns_tx);
                return ['success' => true, 'message' => 'Top-up already processed (no-op)'];
            }

            $upd_wallet = $this->conn->prepare("UPDATE worker_profiles
                              SET wallet_balance = wallet_balance + ?
                              WHERE user_id = ? RETURNING wallet_balance");
            $upd_wallet->execute([$amount, $worker_id]);
            $new_balance = floatval($upd_wallet->fetchColumn());

            $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'credit', 'amount' => $amount,
                'ref_id' => $topup_id, 'ref_type' => 'topup', 'description' => 'Wallet Top-up', 'balance_after' => $new_balance
            ]);

            $this->commitTx($owns_tx);
            return ['success' => true, 'message' => SUCC_TOPUP];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("Top-up failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process withdrawal request. Balance check + debit happen in one
     * atomic conditional UPDATE — no stale read used for the write.
     */
    public function requestWithdrawal($worker_id, $amount, $gcash_number) {
        if ($amount < MIN_WITHDRAWAL) return ['success' => false, 'message' => ERR_MIN_WITHDRAWAL];

        $owns_tx = $this->beginTx();

        try {
            $worker_id = intval($worker_id);
            $amount    = floatval($amount);

            $upd_wallet = $this->conn->prepare("UPDATE worker_profiles
                              SET wallet_balance = wallet_balance - ?
                              WHERE user_id = ? AND wallet_balance >= ?
                              RETURNING wallet_balance");
            $upd_wallet->execute([$amount, $worker_id, $amount]);
            $row = $upd_wallet->fetch();

            if (!$row) throw new Exception(ERR_INSUFFICIENT_FUNDS);
            $new_balance = floatval($row['wallet_balance']);

            $insert_withdrawal = $this->conn->prepare("INSERT INTO withdrawals
                (worker_id, amount, gcash_number, status, request_date)
                VALUES (?, ?, ?, 'Pending', NOW())");
            $insert_withdrawal->execute([$worker_id, $amount, $gcash_number]);

            $withdrawal_id = $this->conn->lastInsertId('withdrawals_id_seq');
            $description   = "Withdrawal request to GCash $gcash_number";
            $this->addTransaction([
                'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'withdrawal', 'amount' => $amount,
                'ref_id' => $withdrawal_id, 'ref_type' => 'withdrawal', 'description' => $description, 'balance_after' => $new_balance
            ]);

            $this->commitTx($owns_tx);
            return ['success' => true, 'message' => SUCC_WITHDRAWAL];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("Withdrawal failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Credit the worker for an ONLINE (GCash/PayMongo) final payment —
     * releases any escrowed mobilization fee and/or credits the
     * labor+materials portion just paid. Cash final payments never call
     * this: the worker already holds the physical cash, there's nothing to
     * credit — only the commission (processFinalPaymentCommission) applies.
     *
     * Idempotent via bookings.wallet_credited, claimed atomically — safe to
     * call from both the webhook and the browser-redirect landing page for
     * the same payment; whichever runs first wins, the other no-ops. This
     * is independent from (and safe to interleave with) the commission
     * claim in processFinalPaymentCommission, which callers should invoke
     * right after this.
     */
    public function creditOnlineFinalPayment($booking_id, $worker_id, $mobilization_release_amount, $credit_amount) {
        $owns_tx = $this->beginTx();

        try {
            $booking_id = intval($booking_id);
            $worker_id  = intval($worker_id);
            $mobilization_release_amount = floatval($mobilization_release_amount);
            $credit_amount = floatval($credit_amount);

            $claim = $this->conn->prepare("UPDATE bookings
                          SET wallet_credited = TRUE
                          WHERE id = ? AND wallet_credited = FALSE");
            $claim->execute([$booking_id]);

            if ($claim->rowCount() === 0) {
                error_log("creditOnlineFinalPayment: booking #$booking_id already credited — no-op");
                $this->commitTx($owns_tx);
                return ['success' => true, 'message' => 'Already credited (no-op)'];
            }

            if ($mobilization_release_amount > 0) {
                $release = $this->releaseEscrowPayment($booking_id, $worker_id, $mobilization_release_amount);
                if (!$release['success']) throw new Exception("Escrow release failed: " . $release['message']);
            }

            if ($credit_amount > 0) {
                $upd = $this->conn->prepare("UPDATE worker_profiles
                           SET wallet_balance = wallet_balance + ?
                           WHERE user_id = ? RETURNING wallet_balance");
                $upd->execute([$credit_amount, $worker_id]);
                $new_balance = floatval($upd->fetchColumn());

                $this->addTransaction([
                    'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'credit', 'amount' => $credit_amount,
                    'ref_id' => $booking_id, 'ref_type' => 'final_payment',
                    'description' => "Online final payment received for booking #$booking_id",
                    'balance_after' => $new_balance,
                ]);
            }

            $this->commitTx($owns_tx);
            return ['success' => true, 'message' => 'Credited'];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("❌ creditOnlineFinalPayment failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Process final payment commission (4% of labor fee, stored as
     * admin_fee_amount by complete_job.php). Deducts from worker
     * wallet_balance and credits admin wallet. Runs exactly once per
     * booking (guarded by final_commission_deducted) regardless of whether
     * the final payment was Cash or GCash/PayMongo — which makes this the
     * single right place to also apply the GreenLoop voucher top-up (see
     * below): every booking passes through here exactly once at final
     * settlement, whether or not it ever touched escrow.
     *
     * @param  int    $booking_id
     * @param  int    $worker_id
     * @param  float  $total_final_cost   The full job cost (labor + materials + mobilization)
     * @param  string $payment_method     'Cash' or 'GCash'/'PayMongo'
     * @return array  ['success'=>bool, 'message'=>string, 'commission'=>float]
     */
    public function processFinalPaymentCommission($booking_id, $worker_id, $total_final_cost, $payment_method = 'Cash') {
        $owns_tx = $this->beginTx();

        try {
            $booking_id       = intval($booking_id);
            $worker_id        = intval($worker_id);
            $total_final_cost = floatval($total_final_cost);

            if ($booking_id <= 0 || $worker_id <= 0 || $total_final_cost <= 0) {
                throw new Exception("Invalid parameters: booking=$booking_id, worker=$worker_id, cost=$total_final_cost");
            }

            // Prevent double-charging: atomically claim this booking for
            // commission processing (this single UPDATE is both the guard
            // and the "already processed" check — no separate read needed).
            $claim = $this->conn->prepare("UPDATE bookings
                          SET final_commission_deducted = TRUE
                          WHERE id = ? AND final_commission_deducted = FALSE");
            $claim->execute([$booking_id]);

            if ($claim->rowCount() === 0) {
                return ['success' => true, 'message' => 'Commission already processed', 'commission' => 0];
            }

            $check = $this->conn->prepare("SELECT admin_fee_amount, voucher_discount FROM bookings WHERE id = ?");
            $check->execute([$booking_id]);
            $brow = $check->fetch();

            // Commission — use the amount complete_job.php already computed and stored
            // (4% of labor fee ONLY, not materials or mobilization). Recomputing
            // 4% of $total_final_cost here would tax materials and any bundled
            // mobilization fee too, diverging from what was shown to the worker
            // at job-completion time and overcharging them.
            $commission = floatval($brow['admin_fee_amount'] ?? 0);
            if ($commission <= 0) {
                // Fallback for bookings that somehow never got admin_fee_amount stored
                $commission = round($total_final_cost * (ADMIN_COMMISSION_PERCENT / 100), 2);
            }

            $upd_worker = $this->conn->prepare("UPDATE worker_profiles
                           SET wallet_balance = wallet_balance - ?
                           WHERE user_id = ? AND wallet_balance >= ?
                           RETURNING wallet_balance");
            $upd_worker->execute([$commission, $worker_id, $commission]);
            $worker_row = $upd_worker->fetch();

            if (!$worker_row) {
                throw new Exception("Worker wallet insufficient for commission ($commission).");
            }
            $new_worker_balance = floatval($worker_row['wallet_balance']);

            // Log worker debit
            $w_desc = "4% commission on final payment (₱" . number_format($total_final_cost, 2) . ") for booking #$booking_id via $payment_method";
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
            $this->ensureAdminWalletExists();
            $upd_admin = $this->conn->prepare("UPDATE admin_wallet
                          SET balance = balance + ?, total_earned = total_earned + ?
                          WHERE id = 1 RETURNING balance");
            $upd_admin->execute([$commission, $commission]);
            $new_admin_balance = floatval($upd_admin->fetchColumn());

            // Log admin credit
            $a_desc = "4% commission from booking #$booking_id final payment ($payment_method) — worker #$worker_id";
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

            // ── GreenLoop voucher discount: platform absorbs it ──────────────
            // If this booking's mobilization fee was discounted by a redeemed
            // GreenLoop voucher, the worker still deserves the FULL (pre-
            // discount) mobilization amount — the discount is a marketing
            // cost, not something the worker should eat. Top the worker up by
            // voucher_discount here (the one place every booking passes
            // through exactly once at final settlement, Cash or online) and
            // fund it out of admin_wallet, logged as its own line so it's
            // visible on the Finance dashboard rather than silently blended
            // into other transactions.
            $voucher_discount = round(floatval($brow['voucher_discount'] ?? 0), 2);
            if ($voucher_discount > 0) {
                $upd_worker_v = $this->conn->prepare("UPDATE worker_profiles
                                    SET wallet_balance = wallet_balance + ?
                                    WHERE user_id = ? RETURNING wallet_balance");
                $upd_worker_v->execute([$voucher_discount, $worker_id]);
                $new_worker_balance_v = floatval($upd_worker_v->fetchColumn());

                $upd_admin_v = $this->conn->prepare("UPDATE admin_wallet
                                   SET balance = balance - ?
                                   WHERE id = 1 RETURNING balance");
                $upd_admin_v->execute([$voucher_discount]);
                $new_admin_balance_v = floatval($upd_admin_v->fetchColumn());

                $v_worker_desc = "Platform-covered GreenLoop voucher discount for booking #$booking_id";
                $this->addTransaction([
                    'user_id' => $worker_id, 'user_type' => 'worker', 'type' => 'credit', 'amount' => $voucher_discount,
                    'ref_id' => $booking_id, 'ref_type' => 'voucher_subsidy', 'description' => $v_worker_desc,
                    'balance_after' => $new_worker_balance_v
                ]);

                $v_admin_desc = "Platform-covered voucher discount for booking #$booking_id — worker #$worker_id";
                $this->addTransaction([
                    'user_id' => 1, 'user_type' => 'admin', 'type' => 'debit', 'amount' => $voucher_discount,
                    'ref_id' => $booking_id, 'ref_type' => 'voucher_subsidy', 'description' => $v_admin_desc,
                    'balance_after' => $new_admin_balance_v
                ]);

                error_log("✅ Voucher discount ₱$voucher_discount absorbed by platform for booking #$booking_id");
            }

            $this->commitTx($owns_tx);
            error_log("✅ Final payment commission processed: ₱$commission from worker #$worker_id for booking #$booking_id");

            return ['success' => true, 'message' => "Commission of ₱$commission processed.", 'commission' => $commission];

        } catch (Exception $e) {
            $this->rollbackTx($owns_tx);
            error_log("❌ processFinalPaymentCommission failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'commission' => 0];
        }
    }

    /**
     * Get admin wallet (create if not exists)
     */
    private function getAdminWallet() {
        $this->ensureAdminWalletExists();
        $stmt = $this->conn->prepare("SELECT * FROM admin_wallet WHERE id = 1");
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Add transaction record
     */
    private function addTransaction($data) {
        $user_id       = intval($data['user_id']);
        $user_type     = $data['user_type'];
        $type          = $data['type'];
        $amount        = floatval($data['amount']);
        $ref_id        = intval($data['ref_id']);
        $ref_type      = $data['ref_type'];
        $description   = $data['description'] ?? '';
        $balance_after = floatval($data['balance_after']);

        $sql = "INSERT INTO wallet_transactions
                (user_id, user_type, transaction_type, amount, reference_id, reference_type, description, balance_after, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        try {
            $stmt->execute([$user_id, $user_type, $type, $amount, $ref_id, $ref_type, $description, $balance_after]);
            return true;
        } catch (PDOException $e) {
            error_log("Failed to add transaction: " . $e->getMessage());
            error_log("SQL: " . $sql);
            return false;
        }
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
                WHERE wt.user_id = ? AND wt.user_type = 'worker'
                ORDER BY wt.created_at DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindValue(1, $worker_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Get pending escrow amounts
     */
    public function getPendingEscrow() {
        $sql = "SELECT COUNT(*) as count, SUM(calculated_fee) as total
                FROM bookings
                WHERE payment_method = 'PayMongo'
                AND payment_status = 'Paid'
                AND is_escrow = TRUE
                AND status = 'Pending'";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute();
        return $stmt->fetch();
    }
}
