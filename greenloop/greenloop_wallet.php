<?php
// ============================================================
// greenloop_wallet.php — Client's Green Coin Wallet & Rewards
// ============================================================
require_once __DIR__ . '/greenloop_db.php';
session_start();

$client_id = (int)($_SESSION['user_id'] ?? 0);
if (!$client_id) { header('Location: ../auth/login.php'); exit; }
$client_name = htmlspecialchars($_SESSION['full_name'] ?? 'there');

// Handle reward redemption (POST)
$redeem_msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['redeem_reward_id'])) {
    $reward_id = (int)$_POST['redeem_reward_id'];
    $stmt = $pdo->prepare("SELECT * FROM greenloop_rewards WHERE id = ? AND is_active = TRUE");
    $stmt->execute([$reward_id]);
    $reward = $stmt->fetch();

    if (!$reward) {
        $redeem_msg = ['type' => 'error', 'text' => 'Reward not found.'];
    } else {
        $wallet = gc_get_wallet($pdo, $client_id);
        if ((float)$wallet['balance'] < (float)$reward['green_coins_cost']) {
            $redeem_msg = ['type' => 'error', 'text' => "Not enough Green Coins. You need {$reward['green_coins_cost']} but have {$wallet['balance']}."];
        } else {
            $code = strtoupper('GL' . substr(md5(uniqid()), 0, 6));
            $expires = date('Y-m-d H:i:s', strtotime("+{$reward['valid_days']} days"));
            $stmt = $pdo->prepare("INSERT INTO greenloop_redemptions (user_id, reward_id, green_coins_spent, promo_code, expires_at) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$client_id, $reward_id, $reward['green_coins_cost'], $code, $expires]);
            $redemption_id = $pdo->lastInsertId();
            gc_spend($pdo, $client_id, $reward['green_coins_cost'], $redemption_id, "Redeemed: {$reward['reward_name']}");
            try {
                $pdo->prepare("INSERT INTO notifications (user_id, message, link, is_read, created_at) VALUES (?, ?, 'greenloop/greenloop_wallet.php', 0, NOW())")
                    ->execute([$client_id, "🎁 You redeemed \"{$reward['reward_name']}\"! Your promo code is: {$code}. Valid until " . date('M d, Y', strtotime($expires)) . "."]);
            } catch (Exception $e) {}
            $redeem_msg = ['type' => 'success', 'text' => "Reward redeemed! Your promo code: <strong>{$code}</strong> (valid {$reward['valid_days']} days)"];
        }
    }
}

// Data
$wallet = gc_get_wallet($pdo, $client_id);

$stmt = $pdo->prepare("SELECT * FROM green_coin_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$client_id]);
$transactions = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM greenloop_rewards WHERE is_active = TRUE ORDER BY green_coins_cost ASC");
$rewards = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT r.*, i.item_name as catalog_name FROM greenloop_reports r LEFT JOIN greenloop_accepted_items i ON r.item_id = i.id WHERE r.client_id = ? ORDER BY r.created_at DESC LIMIT 10");
$stmt->execute([$client_id]);
$reports = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT rd.*, rw.reward_name, rw.description FROM greenloop_redemptions rd JOIN greenloop_rewards rw ON rd.reward_id = rw.id WHERE rd.user_id = ? ORDER BY rd.created_at DESC LIMIT 5");
$stmt->execute([$client_id]);
$redemptions = $stmt->fetchAll();

$status_colors = [
    'pending'   => '#f59e0b',
    'scheduled' => '#3b82f6',
    'collected' => '#8b5cf6',
    'verified'  => '#2dff7a',
    'rejected'  => '#ff4b4b',
    'cancelled' => '#4b5563',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Green Coins Wallet — GreenLoop</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:ital,wght@0,400;0,700;1,400&family=Outfit:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
/* ═══ RESET ═══ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
button { font-family: inherit; cursor: pointer; border: none; }
input, select, textarea { font-family: inherit; }

/* ═══ DESIGN TOKENS (mirrored from greenloop_report) ═══ */
:root {
  --c-void:     #080c09;
  --c-base:     #0d1410;
  --c-raise:    #131b15;
  --c-lift:     #182019;
  --c-float:    #1f2b21;
  --c-edge:     #243027;

  --c-green:    #2dff7a;
  --c-green-2:  #1cd464;
  --c-green-3:  #0f8c41;
  --c-green-4:  #083d1d;
  --c-green-5:  #041f0f;

  --c-gold:     #ffcc2d;
  --c-gold-2:   #c49a00;
  --c-gold-3:   #5c4800;
  --c-red:      #ff4b4b;
  --c-red-2:    #8c0f0f;

  --c-text:     #d8edd9;
  --c-text-2:   #7a9c7e;
  --c-text-3:   #3c5240;

  --c-border:   #1a2a1c;
  --c-border-2: #253529;
  --c-border-3: #304538;

  --f-head: 'Outfit', sans-serif;
  --f-mono: 'Space Mono', monospace;
  --f-body: 'Outfit', sans-serif;

  --r-sm:  8px;
  --r-md:  14px;
  --r-lg:  20px;
  --r-xl:  28px;
  --r-max: 999px;

  --t-fast: 120ms ease;
  --t-mid:  240ms ease;
  --t-slow: 400ms cubic-bezier(0.34, 1.56, 0.64, 1);
}

/* ═══ GLOBAL ═══ */
html { scroll-behavior: smooth; }

body {
  font-family: var(--f-body);
  background: var(--c-void);
  color: var(--c-text);
  min-height: 100vh;
  overflow-x: hidden;
  -webkit-font-smoothing: antialiased;
  padding-bottom: 60px;
}

/* Atmospheric background */
body::before {
  content: '';
  position: fixed;
  inset: 0;
  background:
    radial-gradient(ellipse 60% 40% at 20% 10%, rgba(45,255,122,0.04) 0%, transparent 70%),
    radial-gradient(ellipse 40% 60% at 80% 80%, rgba(45,255,122,0.03) 0%, transparent 70%),
    repeating-linear-gradient(0deg, transparent, transparent 60px, rgba(45,255,122,0.012) 60px, rgba(45,255,122,0.012) 61px),
    repeating-linear-gradient(90deg, transparent, transparent 60px, rgba(45,255,122,0.008) 60px, rgba(45,255,122,0.008) 61px);
  pointer-events: none;
  z-index: 0;
}

/* ═══ LAYOUT ═══ */
.app-shell {
  max-width: 480px;
  margin: 0 auto;
  min-height: 100vh;
  position: relative;
  z-index: 1;
  border-left: 1px solid var(--c-border);
  border-right: 1px solid var(--c-border);
}

/* ═══ NAV ═══ */
.nav {
  position: sticky; top: 0; z-index: 200;
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px;
  background: rgba(8,12,9,0.88);
  backdrop-filter: blur(20px) saturate(1.6);
  border-bottom: 1px solid var(--c-border-2);
}
.nav-logo {
  display: flex; align-items: center; gap: 8px; flex: 1;
}
.nav-wordmark {
  font-family: var(--f-head);
  font-weight: 800;
  font-size: 16px;
  letter-spacing: -0.5px;
  color: var(--c-text);
}
.nav-wordmark em { font-style: normal; color: var(--c-green); }

.btn-back {
  display: flex; align-items: center; justify-content: center;
  width: 32px; height: 32px;
  background: var(--c-lift);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-sm);
  color: var(--c-text-2);
  font-size: 16px;
  text-decoration: none;
  transition: var(--t-fast);
  flex-shrink: 0;
}
.btn-back:hover { color: var(--c-green); border-color: var(--c-green-3); }

/* ═══ HERO ═══ */
.hero {
  padding: 28px 20px 20px;
}
.hero-tag {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 10px;
  font-family: var(--f-mono);
  font-weight: 700;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--c-green-2);
  border: 1px solid var(--c-green-4);
  border-radius: var(--r-max);
  padding: 4px 12px;
  margin-bottom: 16px;
}
.hero-tag::before {
  content: '';
  width: 5px; height: 5px; border-radius: 50%;
  background: var(--c-green);
  animation: blink 2s step-end infinite;
}
@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }

.hero-title {
  font-family: var(--f-head);
  font-weight: 900;
  font-size: 34px;
  line-height: 1.05;
  letter-spacing: -1px;
  margin-bottom: 8px;
}
.hero-title .hl {
  color: var(--c-green);
  position: relative;
}
.hero-title .hl::after {
  content: '';
  position: absolute;
  bottom: 2px; left: 0; right: 0;
  height: 2px;
  background: var(--c-green);
  opacity: 0.3;
  border-radius: 2px;
}
.hero-body {
  font-size: 13px;
  color: var(--c-text-2);
  line-height: 1.7;
}

/* ═══ BALANCE CARD ═══ */
.balance-card {
  margin: 0 16px 24px;
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-xl);
  overflow: hidden;
  position: relative;
}
.balance-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--c-green), transparent);
}
.balance-inner {
  padding: 28px 24px 20px;
  text-align: center;
}
.balance-label {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 2.5px; text-transform: uppercase;
  color: var(--c-text-3); margin-bottom: 10px;
}
.balance-amount {
  font-family: var(--f-head);
  font-size: 64px; font-weight: 900;
  letter-spacing: -3px; line-height: 1;
  color: var(--c-green);
  margin-bottom: 4px;
  text-shadow: 0 0 40px rgba(45,255,122,0.3);
}
.balance-unit {
  font-family: var(--f-mono);
  font-size: 11px; font-weight: 700;
  letter-spacing: 2px; text-transform: uppercase;
  color: var(--c-text-3);
  margin-bottom: 24px;
}

.balance-stats {
  display: grid;
  grid-template-columns: 1fr 1px 1fr;
  gap: 0;
  border-top: 1px solid var(--c-border);
}
.stat-divider {
  background: var(--c-border);
  margin: 12px 0;
}
.stat-box {
  padding: 16px 12px;
  text-align: center;
}
.stat-val {
  font-family: var(--f-head);
  font-size: 22px; font-weight: 900;
  letter-spacing: -0.5px;
  margin-bottom: 3px;
}
.stat-val.earn { color: var(--c-green); }
.stat-val.spent { color: var(--c-gold); }
.stat-lbl {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 1.5px; text-transform: uppercase;
  color: var(--c-text-3);
}

/* ═══ SCAN CTA ═══ */
.scan-cta {
  margin: 0 16px 24px;
  display: block;
  text-decoration: none;
  background: var(--c-green);
  color: var(--c-void);
  border-radius: var(--r-md);
  padding: 15px 20px;
  font-family: var(--f-head);
  font-size: 14px; font-weight: 900;
  letter-spacing: 0.3px;
  text-align: center;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  transition: var(--t-slow);
  position: relative; overflow: hidden;
}
.scan-cta::after { content: ''; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,0.15), transparent 60%); }
.scan-cta:hover { transform: translateY(-2px); box-shadow: 0 12px 36px rgba(45,255,122,0.35); }

/* ═══ TOAST ═══ */
.toast-bar {
  margin: 0 16px 20px;
  padding: 14px 16px;
  border-radius: var(--r-md);
  font-size: 13px; font-weight: 600;
  border: 1px solid;
  font-family: var(--f-body);
}
.toast-bar.success {
  background: rgba(45,255,122,0.08);
  border-color: var(--c-green-3);
  color: var(--c-green-2);
}
.toast-bar.error {
  background: rgba(255,75,75,0.08);
  border-color: var(--c-red-2);
  color: var(--c-red);
}

/* ═══ SECTION ═══ */
.section { padding: 0 16px; margin-bottom: 28px; }
.section-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: var(--f-mono);
  font-size: 9px;
  letter-spacing: 2.5px;
  text-transform: uppercase;
  color: var(--c-text-3);
  font-weight: 700;
  margin-bottom: 12px;
}
.section-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--c-border-2);
}

/* ═══ REWARDS ═══ */
.reward-card {
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-lg);
  padding: 16px;
  display: flex; align-items: center; gap: 14px;
  margin-bottom: 8px;
  transition: border-color var(--t-fast);
}
.reward-card:hover { border-color: var(--c-border-3); }

.reward-icon-box {
  width: 44px; height: 44px;
  background: var(--c-green-5);
  border: 1px solid var(--c-green-4);
  border-radius: var(--r-md);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.reward-info { flex: 1; min-width: 0; }
.reward-name {
  font-family: var(--f-head);
  font-size: 14px; font-weight: 700;
  color: var(--c-text); margin-bottom: 3px;
}
.reward-desc { font-size: 12px; color: var(--c-text-2); line-height: 1.5; }
.reward-cost {
  display: inline-flex; align-items: center; gap: 4px;
  font-family: var(--f-mono);
  font-size: 10px; font-weight: 700;
  color: var(--c-gold);
  background: rgba(255,204,45,0.08);
  border: 1px solid var(--c-gold-3);
  border-radius: var(--r-max);
  padding: 3px 10px;
  margin-top: 6px;
}

.btn-redeem {
  background: var(--c-green);
  color: var(--c-void);
  border-radius: var(--r-md);
  padding: 10px 16px;
  font-family: var(--f-head);
  font-size: 12px; font-weight: 800;
  letter-spacing: 0.3px;
  transition: var(--t-slow);
  white-space: nowrap; flex-shrink: 0;
}
.btn-redeem:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(45,255,122,0.3); }
.btn-redeem:disabled {
  background: var(--c-edge);
  color: var(--c-text-3);
  cursor: not-allowed;
}

/* ═══ REDEMPTION CARDS ═══ */
.redemption-card {
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-left: 3px solid var(--c-gold);
  border-radius: var(--r-lg);
  padding: 16px 18px;
  margin-bottom: 8px;
}
.redemption-code {
  font-family: var(--f-mono);
  font-size: 22px; font-weight: 700;
  letter-spacing: 3px;
  color: var(--c-gold);
  margin-bottom: 4px;
}
.redemption-name { font-size: 13px; color: var(--c-text-2); margin-bottom: 6px; }
.redemption-meta { font-size: 11px; color: var(--c-text-3); font-family: var(--f-mono); }

.status-pill {
  display: inline-block;
  padding: 3px 10px;
  border-radius: var(--r-max);
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 1px; text-transform: uppercase;
}
.status-pill.active { background: rgba(45,255,122,0.1); color: var(--c-green); border: 1px solid var(--c-green-4); }
.status-pill.used   { background: var(--c-edge); color: var(--c-text-3); border: 1px solid var(--c-border-2); }
.status-pill.expired{ background: rgba(255,75,75,0.08); color: var(--c-red); border: 1px solid var(--c-red-2); }

/* ═══ REPORTS ═══ */
.report-item {
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-lg);
  padding: 14px 16px;
  margin-bottom: 8px;
  display: flex; align-items: center; gap: 12px;
  transition: border-color var(--t-fast);
}
.report-item:hover { border-color: var(--c-border-3); }

.report-icon {
  width: 40px; height: 40px;
  background: var(--c-green-5);
  border: 1px solid var(--c-green-4);
  border-radius: var(--r-md);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.report-info { flex: 1; min-width: 0; }
.report-name {
  font-size: 13px; font-weight: 700;
  color: var(--c-text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  margin-bottom: 3px;
}
.report-meta {
  font-family: var(--f-mono);
  font-size: 10px; color: var(--c-text-3);
}
.report-right { text-align: right; flex-shrink: 0; }
.report-status-badge {
  font-family: var(--f-mono);
  font-size: 9px; font-weight: 700;
  letter-spacing: 1px; text-transform: uppercase;
  border-radius: var(--r-max);
  padding: 3px 10px;
  color: var(--c-void);
  display: inline-block; margin-bottom: 4px;
}
.report-coins {
  font-family: var(--f-mono);
  font-size: 10px; font-weight: 700;
  color: var(--c-gold);
  white-space: nowrap;
}
.report-coins.earned { color: var(--c-green); }

/* ═══ TRANSACTIONS ═══ */
.tx-item {
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-md);
  padding: 12px 16px;
  margin-bottom: 6px;
  display: flex; align-items: center; gap: 12px;
}
.tx-dot {
  width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.tx-dot.earn  { background: var(--c-green); box-shadow: 0 0 6px var(--c-green); }
.tx-dot.spend { background: var(--c-red);   box-shadow: 0 0 6px var(--c-red); }
.tx-desc { flex: 1; font-size: 12px; color: var(--c-text-2); line-height: 1.4; }
.tx-amount {
  font-family: var(--f-mono);
  font-size: 13px; font-weight: 700;
  white-space: nowrap; text-align: right;
}
.tx-amount.earn  { color: var(--c-green); }
.tx-amount.spend { color: var(--c-red); }
.tx-date {
  font-family: var(--f-mono);
  font-size: 10px; color: var(--c-text-3);
  margin-top: 2px;
}

/* ═══ EMPTY STATE ═══ */
.empty-state {
  text-align: center;
  padding: 36px 20px;
  background: var(--c-raise);
  border: 1px solid var(--c-border-2);
  border-radius: var(--r-lg);
}
.empty-state .e-icon { font-size: 36px; margin-bottom: 12px; }
.empty-state p {
  font-size: 13px; color: var(--c-text-3);
  line-height: 1.6; font-family: var(--f-mono);
}
</style>
</head>
<body>

<div class="app-shell">

  <!-- ── NAV ── -->
  <nav class="nav">
    <a href="greenloop_report.php" class="btn-back">←</a>
    <div class="nav-logo">
      <img src="/2.png" alt="GreenLoop Logo" style="width:30px;height:30px;object-fit:contain;border-radius:8px;">
      <div class="nav-wordmark">Green<em>Loop</em> · Wallet</div>
    </div>
  </nav>

  <!-- ── HERO ── -->
  <div class="hero">
    <div class="hero-tag">🟡 Green Coins</div>
    <h1 class="hero-title">Your <span class="hl">Wallet</span></h1>
    <p class="hero-body">Earn coins by recycling scrap, redeem them for real rewards and booking discounts.</p>
  </div>

  <!-- ── BALANCE CARD ── -->
  <div class="balance-card">
    <div class="balance-inner">
      <div class="balance-label">Current Balance</div>
      <div class="balance-amount"><?= number_format($wallet['balance'], 0) ?></div>
      <div class="balance-unit">Green Coins</div>
    </div>
    <div class="balance-stats">
      <div class="stat-box">
        <div class="stat-val earn"><?= number_format($wallet['total_earned'], 0) ?></div>
        <div class="stat-lbl">Total Earned</div>
      </div>
      <div class="stat-divider"></div>
      <div class="stat-box">
        <div class="stat-val spent"><?= number_format($wallet['total_spent'], 0) ?></div>
        <div class="stat-lbl">Total Spent</div>
      </div>
    </div>
  </div>

  <!-- ── SCAN CTA ── -->
  <a href="greenloop_report.php" class="scan-cta">♻️ Report Scraps → Earn More Coins</a>

  <!-- ── TOAST ── -->
  <?php if ($redeem_msg): ?>
  <div class="toast-bar <?= $redeem_msg['type'] ?>">
    <?= $redeem_msg['type'] === 'success' ? '✅ ' : '⚠️ ' ?><?= $redeem_msg['text'] ?>
  </div>
  <?php endif; ?>

  <!-- ── REDEEM REWARDS ── -->
  <div class="section">
    <div class="section-label">🎁 Redeem Rewards</div>
    <?php foreach ($rewards as $rw):
      $can_afford = (float)$wallet['balance'] >= (float)$rw['green_coins_cost'];
      $icons = ['booking_discount' => '🏷️', 'free_booking' => '🎟️', 'cash_credit' => '💸', 'other' => '🎁'];
      $icon = $icons[$rw['reward_type']] ?? '🎁';
    ?>
    <div class="reward-card">
      <div class="reward-icon-box"><?= $icon ?></div>
      <div class="reward-info">
        <div class="reward-name"><?= htmlspecialchars($rw['reward_name']) ?></div>
        <div class="reward-desc"><?= htmlspecialchars($rw['description'] ?? '') ?></div>
        <div class="reward-cost">🟡 <?= number_format($rw['green_coins_cost'], 0) ?> coins</div>
      </div>
      <form method="POST" style="flex-shrink:0">
        <input type="hidden" name="redeem_reward_id" value="<?= $rw['id'] ?>">
        <button type="submit" class="btn-redeem" <?= $can_afford ? '' : 'disabled' ?>>
          <?= $can_afford ? 'Redeem' : 'Need more' ?>
        </button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ── ACTIVE REDEMPTIONS ── -->
  <?php if ($redemptions): ?>
  <div class="section">
    <div class="section-label">🎟️ My Active Rewards</div>
    <?php foreach ($redemptions as $rd): $rd_status = gc_voucher_status($rd); ?>
    <div class="redemption-card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <div class="redemption-code"><?= htmlspecialchars($rd['promo_code'] ?? '—') ?></div>
        <span class="status-pill <?= $rd_status ?>"><?= ucfirst($rd_status) ?></span>
      </div>
      <div class="redemption-name"><?= htmlspecialchars($rd['reward_name']) ?></div>
      <div class="redemption-meta">
        <?= number_format($rd['green_coins_spent'], 0) ?> coins spent ·
        <?php if ($rd_status === 'used' && $rd['used_at']): ?>
          Used <?= date('M d, Y', strtotime($rd['used_at'])) ?>
        <?php else: ?>
          Expires <?= $rd['expires_at'] ? date('M d, Y', strtotime($rd['expires_at'])) : 'N/A' ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── MY REPORTS ── -->
  <div class="section">
    <div class="section-label">📋 My Scrap Reports</div>
    <?php if (!$reports): ?>
      <div class="empty-state">
        <div class="e-icon">♻️</div>
        <p>No reports yet.<br>Submit your first scrap to start earning!</p>
      </div>
    <?php else: foreach ($reports as $r):
      $item_label = $r['catalog_name'] ?? $r['item_name_custom'] ?? 'Custom Item';
      $status_color = $status_colors[$r['status']] ?? '#4b5563';
    ?>
    <div class="report-item">
      <div class="report-icon">♻️</div>
      <div class="report-info">
        <div class="report-name"><?= htmlspecialchars($item_label) ?></div>
        <div class="report-meta">
          <?= htmlspecialchars($r['quantity']) ?> <?= htmlspecialchars($r['unit']) ?> ·
          <?= date('M d, Y', strtotime($r['created_at'])) ?>
        </div>
      </div>
      <div class="report-right">
        <div class="report-status-badge" style="background:<?= $status_color ?>">
          <?= ucfirst($r['status']) ?>
        </div>
        <div class="report-coins <?= $r['actual_green_coins_awarded'] > 0 ? 'earned' : '' ?>">
          <?php if ($r['actual_green_coins_awarded'] > 0): ?>
            ✅ <?= number_format($r['actual_green_coins_awarded'], 0) ?> earned
          <?php else: ?>
            ~<?= number_format($r['estimated_green_coins'], 0) ?> pending
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- ── TRANSACTION HISTORY ── -->
  <div class="section">
    <div class="section-label">📜 Coin History</div>
    <?php if (!$transactions): ?>
      <div class="empty-state">
        <div class="e-icon">🪙</div>
        <p>No transactions yet.</p>
      </div>
    <?php else: foreach ($transactions as $tx):
      $is_earn = in_array($tx['transaction_type'], ['earn', 'refund']);
    ?>
    <div class="tx-item">
      <div class="tx-dot <?= $is_earn ? 'earn' : 'spend' ?>"></div>
      <div class="tx-desc"><?= htmlspecialchars($tx['description'] ?? $tx['transaction_type']) ?></div>
      <div>
        <div class="tx-amount <?= $is_earn ? 'earn' : 'spend' ?>">
          <?= $is_earn ? '+' : '-' ?><?= number_format($tx['amount'], 0) ?>
        </div>
        <div class="tx-date"><?= date('M d', strtotime($tx['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div><!-- .app-shell -->

</body>
</html>