<?php
// ============================================================
// junkshop/login.php  —  Standalone Junk Shop Login
// Uses junkshops table (separate from users)
// ============================================================
include __DIR__ . '/../db_connect.php';   // $conn (PDO)
require_once __DIR__ . '/../includes/functions/feature_flags.php';

// Already logged in → go to dashboard
if (!empty($_SESSION['junkshop_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$greenloop_off = !isFeatureEnabled($conn, 'feature_greenloop_enabled');
if ($greenloop_off) {
    $error = 'GreenLoop is temporarily unavailable. Please check back later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$greenloop_off) {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare("SELECT id, shop_name, owner_name, password, is_active FROM junkshops WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $shop = $stmt->fetch();

        if (!$shop || !password_verify($password, $shop['password'])) {
            $error = 'Invalid email or password.';
        } elseif (!$shop['is_active']) {
            $error = 'Your account has been deactivated. Contact GreenLoop support.';
        } else {
            // Set session
            $_SESSION['junkshop_id']    = (int)$shop['id'];
            $_SESSION['junkshop_name']  = $shop['shop_name'];
            $_SESSION['junkshop_owner'] = $shop['owner_name'];

            // Update last login
            $id = (int)$shop['id'];
            $stmt = $conn->prepare("UPDATE junkshops SET last_login_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            header('Location: dashboard.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GreenLoop Partner Login</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  body { font-family: 'Sora', sans-serif; }
  @keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-10px)} }
  .float { animation: float 4s ease-in-out infinite; }
  @keyframes fade-up { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
  .fade-up { animation: fade-up 0.5s ease forwards; }
</style>
</head>
<body class="bg-gradient-to-br from-green-50 via-white to-emerald-50 min-h-screen flex items-center justify-center p-4">

<!-- Background grid -->
<div class="fixed inset-0 bg-[linear-gradient(rgba(34,197,94,0.05)_1px,transparent_1px),linear-gradient(90deg,rgba(34,197,94,0.05)_1px,transparent_1px)] bg-[size:60px_60px] pointer-events-none"></div>
<!-- Glow -->
<div class="fixed top-1/3 left-1/2 -translate-x-1/2 w-96 h-96 bg-green-400/10 rounded-full blur-3xl pointer-events-none"></div>

<div class="relative w-full max-w-sm fade-up">

  <!-- Logo -->
  <div class="text-center mb-8">
    <div class="float inline-flex w-20 h-20 rounded-2xl bg-white border border-gray-200 shadow-lg items-center justify-center p-2 mb-4">
      <img src="/2.png" alt="GreenLoop Logo" class="w-full h-full object-contain" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
      <span class="hidden text-3xl">♻️</span>
    </div>
    <h1 class="text-2xl font-bold text-gray-800">GreenLoop Partner</h1>
    <p class="text-sm text-gray-500 mt-1">Junk Shop Portal</p>
  </div>

  <!-- Card -->
  <div class="bg-white border border-gray-200 rounded-3xl p-8 shadow-xl">

    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-600 text-sm px-4 py-3 rounded-xl mb-5 flex items-center gap-2">
      <span class="text-base">⚠️</span> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>

      <div class="mb-4">
        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Email Address</label>
        <div class="relative">
          <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-lg">✉️</span>
          <input type="email" name="email" required
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                 placeholder="shop@example.com"
                 class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 text-gray-800 rounded-xl text-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all">
        </div>
      </div>

      <div class="mb-6">
        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Password</label>
        <div class="relative">
          <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-lg">🔒</span>
          <input type="password" name="password" id="pwd-input" required
                 placeholder="••••••••"
                 class="w-full pl-10 pr-12 py-3 bg-gray-50 border border-gray-200 text-gray-800 rounded-xl text-sm placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all">
          <button type="button" onclick="togglePwd()"
                  class="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 text-sm font-medium transition-colors" id="pwd-toggle">Show</button>
        </div>
      </div>

      <button type="submit" <?php echo $greenloop_off ? 'disabled' : ''; ?>
              class="w-full py-3.5 bg-green-600 hover:bg-green-700 active:scale-95 text-white font-bold rounded-xl transition-all flex items-center justify-center gap-2 text-sm shadow-lg shadow-green-600/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:active:scale-100">
        <span>🚛</span> Sign In to Partner Portal
      </button>

    </form>

    <p class="text-center text-xs text-gray-500 mt-5">
      Not a partner yet? <a href="mailto:support@abilisto.com" class="text-green-600 hover:text-green-700 font-semibold">Contact GreenLoop support</a>
    </p>
  </div>

  <p class="text-center text-xs text-gray-400 mt-6">GreenLoop by Abilisto · Partner Program</p>
</div>

<script>
function togglePwd() {
  const input = document.getElementById('pwd-input');
  const btn   = document.getElementById('pwd-toggle');
  if (input.type === 'password') { input.type = 'text'; btn.textContent = 'Hide'; }
  else                           { input.type = 'password'; btn.textContent = 'Show'; }
}
</script>
</body>
</html>