<?php
// ============================================================
// greenloop_db.php — Database helper for GreenLoop
// Include this in every GreenLoop PHP file
// ============================================================
// This assumes your existing db connection is already available.
// If your project has a central db_connect.php / config.php, replace
// the block below with:  require_once '../config.php';
// and remove the $pdo setup below.
// ============================================================

// DB credentials live in .env now — reuse the central connection instead of
// redefining it (this pulls in both $conn and $pdo from ../db_connect.php).
if (!isset($pdo)) {
    require_once __DIR__ . '/../db_connect.php';
}

// ── Green Coin Helpers ────────────────────────────────────────

/**
 * Get or create a user's Green Coin wallet row.
 */
function gc_get_wallet(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT * FROM green_coins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare("INSERT INTO green_coins (user_id, balance, total_earned, total_spent) VALUES (?, 0, 0, 0)")
            ->execute([$user_id]);
        return ['user_id' => $user_id, 'balance' => 0, 'total_earned' => 0, 'total_spent' => 0];
    }
    return $row;
}

/**
 * Award Green Coins to a user after a scrap report is verified.
 */
function gc_award(PDO $pdo, int $user_id, float $amount, string $ref_type, int $ref_id, string $desc): bool {
    $pdo->beginTransaction();
    try {
        gc_get_wallet($pdo, $user_id); // ensure wallet exists
        $pdo->prepare("UPDATE green_coins SET balance = balance + ?, total_earned = total_earned + ? WHERE user_id = ?")
            ->execute([$amount, $amount, $user_id]);
        $stmt = $pdo->prepare("SELECT balance FROM green_coins WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $new_balance = $stmt->fetchColumn();
        $pdo->prepare("INSERT INTO green_coin_transactions (user_id, transaction_type, amount, balance_after, reference_type, reference_id, description) VALUES (?, 'earn', ?, ?, ?, ?, ?)")
            ->execute([$user_id, $amount, $new_balance, $ref_type, $ref_id, $desc]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Spend Green Coins for a reward redemption.
 */
function gc_spend(PDO $pdo, int $user_id, float $amount, int $redemption_id, string $desc): bool {
    gc_get_wallet($pdo, $user_id);
    $stmt = $pdo->prepare("SELECT balance FROM green_coins WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $balance = (float)$stmt->fetchColumn();
    if ($balance < $amount) return false;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE green_coins SET balance = balance - ?, total_spent = total_spent + ? WHERE user_id = ?")
            ->execute([$amount, $amount, $user_id]);
        $stmt = $pdo->prepare("SELECT balance FROM green_coins WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $new_balance = $stmt->fetchColumn();
        $pdo->prepare("INSERT INTO green_coin_transactions (user_id, transaction_type, amount, balance_after, reference_type, reference_id, description) VALUES (?, 'spend', ?, ?, 'reward_redemption', ?, ?)")
            ->execute([$user_id, $amount, $new_balance, $redemption_id, $desc]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Effective status of a greenloop_redemptions row: passes through
 * used/cancelled as stored, but reports 'expired' for a row still marked
 * 'active' in the DB whose expires_at has passed (expiry is computed on
 * read, never written back).
 */
function gc_voucher_status(array $redemption): string {
    if ($redemption['status'] === 'active'
        && !empty($redemption['expires_at'])
        && strtotime($redemption['expires_at']) < time()) {
        return 'expired';
    }
    return $redemption['status'];
}