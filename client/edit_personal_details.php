<?php
// client/edit_personal_details.php
// Dedicated page for editing address + phone (municipality, street,
// barangay, province, and — for workers — birthdate). Full name is never
// editable anywhere in the app, so it isn't a field here either; it's just
// shown for context. Reachable from both client/profile.php and
// worker/profile_edit.php, which now only show these fields read-only.
session_start();
include '../db_connect.php';
include '../includes/init_lang.php';
include '../includes/sms_sender.php';
require_once '../includes/functions/ph_provinces.php';
require_once '../includes/functions/ph_municipalities.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['client', 'worker'], true)) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$is_worker = $_SESSION['role'] === 'worker';
$back_link = $is_worker ? '../worker/profile_edit.php' : 'profile.php';

$provinces = getPhilippineProvinces();
$municipalities_by_province = getPhilippineMunicipalities();

$msg = '';
$msg_type = '';
$otp_modal_open = false;

$user_stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

// A. Handle address/birthdate/phone update
if (isset($_POST['update_details'])) {
    $street      = trim($_POST['street'] ?? '');
    $barangay    = trim($_POST['barangay'] ?? '');
    $municipality_raw = trim($_POST['municipality'] ?? '');
    $province    = trim($_POST['province'] ?? '');
    $new_phone_raw = trim($_POST['phone'] ?? '');

    $province_valid = in_array($province, $provinces, true);
    $municipality = ($province_valid && in_array($municipality_raw, $municipalities_by_province[$province] ?? [], true))
        ? $municipality_raw : null;

    if (!$province_valid) {
        $msg = "Please select a valid province.";
        $msg_type = "error";
    } elseif ($municipality === null) {
        $msg = "Please select a valid municipality for the chosen province.";
        $msg_type = "error";
    } elseif ($street === '' || $barangay === '') {
        $msg = "Please fill in street and barangay.";
        $msg_type = "error";
    } else {
        $full_address = "$street, Barangay $barangay, $municipality, $province";

        $phone_digits = preg_replace('/[^0-9]/', '', $new_phone_raw);
        $phone_changed = false;
        $new_phone_stored = $user['phone'];
        if ($phone_digits !== '' && $phone_digits !== $user['phone']) {
            // Accept either the stored 63-prefixed format or the human 09 format.
            if (preg_match('/^09[0-9]{9}$/', $phone_digits)) {
                $new_phone_stored = '63' . substr($phone_digits, 1);
                $phone_changed = true;
            } elseif (preg_match('/^639[0-9]{9}$/', $phone_digits) && $phone_digits !== $user['phone']) {
                $new_phone_stored = $phone_digits;
                $phone_changed = true;
            } else {
                $msg = "Please enter a valid 11-digit Philippine mobile number (e.g., 09123456789).";
                $msg_type = "error";
            }
        }

        if ($msg_type !== 'error') {
            if ($is_worker) {
                $birthdate = $_POST['birthdate'] ?: null;
                $conn->prepare("UPDATE users SET address = ?, municipality = ?, birthdate = ? WHERE id = ?")
                     ->execute([$full_address, $municipality, $birthdate, $user_id]);
            } else {
                $conn->prepare("UPDATE users SET address = ?, municipality = ? WHERE id = ?")
                     ->execute([$full_address, $municipality, $user_id]);
            }

            if ($phone_changed) {
                $otp_response = sendOTP($new_phone_stored);
                $_SESSION['temp_new_phone'] = $new_phone_stored;
                $_SESSION['temp_otp'] = isset($otp_response['data']['otp_code'])
                    ? $otp_response['data']['otp_code']
                    : rand(100000, 999999);
                $otp_modal_open = true;
            } else {
                $msg = "Details updated successfully!";
                $msg_type = "success";
            }

            // Refresh $user for the re-render below.
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch();
        }
    }
}

// B. Handle OTP verification for the phone change
if (isset($_POST['verify_otp'])) {
    if (isset($_SESSION['temp_otp']) && $_POST['otp_code'] == $_SESSION['temp_otp']) {
        $new_p = $_SESSION['temp_new_phone'];
        $conn->prepare("UPDATE users SET phone = ?, is_phone_verified = TRUE WHERE id = ?")->execute([$new_p, $user_id]);
        unset($_SESSION['temp_otp'], $_SESSION['temp_new_phone']);
        $msg = "Phone number updated and verified!";
        $msg_type = "success";
        $user_stmt->execute([$user_id]);
        $user = $user_stmt->fetch();
    } else {
        $msg = "Invalid OTP code.";
        $msg_type = "error";
        $otp_modal_open = true;
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['lang'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Edit Personal Details | Abilisto</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: { extend: {
                colors: { primary: "#146af5", "background-light": "#F8FAFC", "background-dark": "#0F172A" },
                fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
            } },
        };
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-300 font-sans">

<?php include '../includes/navbar.php'; ?>

<main class="max-w-2xl mx-auto px-4 py-6 md:py-10">

    <a href="<?php echo htmlspecialchars($back_link); ?>" class="inline-flex items-center gap-2 text-sm font-medium text-slate-500 dark:text-slate-400 hover:text-primary mb-6">
        <span class="material-symbols-outlined text-lg">arrow_back</span> Back to Profile
    </a>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-md border border-slate-100 dark:border-slate-700 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-2">
            <div class="p-2 bg-primary/10 rounded-lg">
                <span class="material-symbols-outlined text-primary">edit_location_alt</span>
            </div>
            <div>
                <h1 class="text-xl font-bold text-slate-900 dark:text-white">Edit Personal Details</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Editing details for <strong><?php echo htmlspecialchars($user['full_name']); ?></strong></p>
            </div>
        </div>

        <?php if ($msg): ?>
        <div class="mt-5 p-4 rounded-xl flex items-center gap-3 <?php echo $msg_type === 'success' ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'; ?>">
            <span class="material-symbols-outlined <?php echo $msg_type === 'success' ? 'text-green-500' : 'text-red-500'; ?>"><?php echo $msg_type === 'success' ? 'check_circle' : 'error'; ?></span>
            <span class="text-sm font-medium <?php echo $msg_type === 'success' ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'; ?>"><?php echo htmlspecialchars($msg); ?></span>
        </div>
        <?php endif; ?>

        <div class="mt-5 p-3 rounded-xl bg-slate-50 dark:bg-slate-900/50 text-xs text-slate-500 dark:text-slate-400">
            Current address on file: <?php echo htmlspecialchars($user['address'] ?: 'Not set yet'); ?>
        </div>

        <form method="POST" class="mt-6 space-y-5">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-1.5 md:col-span-2">
                    <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Street / House No. / Purok</label>
                    <input type="text" name="street" placeholder="e.g. Purok 3, Rizal Street"
                           class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-sm font-medium" required>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Barangay</label>
                    <input type="text" name="barangay" placeholder="e.g. Poblacion"
                           class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-sm font-medium" required>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Province</label>
                    <select name="province" id="province" required
                            onchange="populateMunicipalities()"
                            class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-sm font-medium">
                        <?php foreach ($provinces as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo ($p === 'Surigao del Sur') ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Municipality</label>
                    <select name="municipality" id="municipality" required
                            class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-sm font-medium">
                        <option value="">Select province first...</option>
                    </select>
                </div>

                <?php if ($is_worker): ?>
                <div class="space-y-1.5">
                    <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Birthdate</label>
                    <input type="date" name="birthdate" value="<?php echo htmlspecialchars($user['birthdate'] ?? ''); ?>"
                           class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-sm font-medium">
                </div>
                <?php endif; ?>

                <div class="space-y-1.5 <?php echo $is_worker ? '' : 'md:col-span-2'; ?>">
                    <label class="block text-xs font-bold text-primary uppercase tracking-widest ml-1">Phone Number</label>
                    <input type="tel" name="phone" placeholder="09XXXXXXXXX"
                           value="<?php echo htmlspecialchars(preg_match('/^63/', $user['phone']) ? '0' . substr($user['phone'], 2) : $user['phone']); ?>"
                           class="w-full bg-slate-50 dark:bg-slate-900 border-none focus:ring-2 focus:ring-primary rounded-xl py-3 px-4 text-slate-800 dark:text-white text-sm font-medium">
                    <p class="text-[11px] text-slate-400 ml-1">Changing this requires OTP verification via SMS.</p>
                </div>
            </div>

            <button type="submit" name="update_details"
                    class="w-full bg-primary hover:bg-blue-600 text-white font-bold py-3.5 rounded-xl transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined text-sm">save</span> Save Details
            </button>
        </form>
    </div>
</main>

<?php if ($otp_modal_open): ?>
<div class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div class="bg-white dark:bg-slate-800 rounded-2xl max-w-md w-full p-6">
        <div class="text-center">
            <div class="w-16 h-16 mx-auto mb-3 bg-primary/10 rounded-full flex items-center justify-center">
                <span class="material-symbols-outlined text-3xl text-primary">sms</span>
            </div>
            <h3 class="text-xl font-bold mb-2 text-slate-900 dark:text-white">Verify Your Number</h3>
            <p class="text-slate-500 dark:text-slate-400 mb-4 text-sm">We sent a code to your new number</p>
            <form method="POST">
                <div class="relative mb-4">
                    <input type="text" name="otp_code" class="w-full text-center text-xl tracking-[0.5em] bg-slate-50 dark:bg-slate-900 border-2 border-primary/20 focus:border-primary rounded-lg py-3 px-3" placeholder="000000" maxlength="6" autofocus required>
                </div>
                <div class="space-y-2">
                    <button type="submit" name="verify_otp" class="w-full bg-primary text-white py-3 rounded-lg font-bold">Verify & Continue</button>
                    <a href="edit_personal_details.php" class="block w-full text-center text-slate-500 hover:text-slate-700 dark:text-slate-400 py-2 text-sm">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    const municipalitiesByProvince = <?php echo json_encode($municipalities_by_province); ?>;
    const currentMunicipality = <?php echo json_encode($user['municipality'] ?? ''); ?>;

    function populateMunicipalities() {
        const province = document.getElementById('province').value;
        const select = document.getElementById('municipality');
        const list = municipalitiesByProvince[province] || [];
        select.innerHTML = '<option value="">Select municipality...</option>';
        list.forEach(function (m) {
            const opt = document.createElement('option');
            opt.value = opt.textContent = m;
            if (m === currentMunicipality) opt.selected = true;
            select.appendChild(opt);
        });
    }
    populateMunicipalities();
</script>

</body>
</html>
