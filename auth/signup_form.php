<?php
// auth/signup_form.php
session_start();
include 'google_config.php';

$role       = isset($_GET['role']) ? $_GET['role'] : 'client';
$role_title = ucfirst($role);
$is_worker  = ($role == 'worker');
$role_color    = $is_worker ? '#10b981' : '#146af5';
$role_gradient = $is_worker ? 'from-emerald-400 to-emerald-600' : 'from-blue-400 to-blue-600';
$role_icon     = $is_worker ? 'construction' : 'person';

// Read and clear server-side error from session
$signup_error     = '';
$signup_error_msg = '';
if (isset($_SESSION['signup_error'])) {
    $signup_error = $_SESSION['signup_error'];
    if (isset($_SESSION['signup_error_msg'])) {
        $signup_error_msg = $_SESSION['signup_error_msg'];
    }
    unset($_SESSION['signup_error']);
    unset($_SESSION['signup_error_msg']);
}
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <!-- (all your existing head content unchanged) -->
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" name="viewport"/>
    <title>Sign Up as <?php echo $role_title; ?> | Abilisto</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,typography,container-queries"></script>
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "<?php echo $role_color; ?>",
                        "background-light": "#f0f7ff",
                        "background-dark": "#0f172a",
                    },
                    fontFamily: { display: ["Plus Jakarta Sans", "sans-serif"] },
                    borderRadius: { DEFAULT: "16px" },
                    backdropBlur: { xs: '2px' }
                },
            },
        };
    </script>
    <style type="text/tailwindcss">
        body {
            font-family: "Plus Jakarta Sans", sans-serif;
            background: radial-gradient(circle at top left, #e0f2fe, #f0f9ff, #fff);
        }
        .glass-card {
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            background-color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .input-glow:focus {
            box-shadow: 0 0 15px <?php echo $is_worker ? 'rgba(16, 185, 129, 0.2)' : 'rgba(20, 106, 245, 0.2)'; ?>;
        }
        .btn-glow {
            box-shadow: 0 10px 25px -5px <?php echo $is_worker ? 'rgba(16, 185, 129, 0.4)' : 'rgba(20, 106, 245, 0.4)'; ?>;
        }
        .btn-glow:hover {
            box-shadow: 0 15px 30px -5px <?php echo $is_worker ? 'rgba(16, 185, 129, 0.5)' : 'rgba(20, 106, 245, 0.5)'; ?>;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-slideUp { animation: slideUp 0.5s ease forwards; }
        .input-wrapper { position: relative; }
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 0.875rem;
            color: #94a3b8;
            transition: color 0.2s;
            pointer-events: none;
            z-index: 10;
        }
        .input-wrapper:focus-within .input-icon { color: <?php echo $role_color; ?>; }
        .input-field {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            background-color: rgba(255, 255, 255, 0.5);
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            transition: all 0.2s;
        }
        .input-field:focus { outline: none; border-color: transparent; }

        /* Eye toggle button inside password field */
        .input-field-password { padding-right: 2.75rem; }

        .eye-btn {
            position: absolute;
            right: 0.75rem;
            top: 0.75rem;
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
            padding: 0;
            line-height: 1;
            z-index: 10;
        }
        .eye-btn:hover { color: <?php echo $role_color; ?>; }

        .input-error { border-color: #ef4444 !important; }
        .error-message {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
            margin-left: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        /* Server error banner */
        .server-error-banner {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 0.75rem;
            padding: 0.875rem 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #991b1b;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-3 md:p-6 transition-colors duration-500">

<div class="w-full max-w-4xl animate-slideUp">
    <div class="glass-card rounded-2xl p-5 md:p-8 shadow-xl relative overflow-hidden">

        <div class="absolute -top-20 -right-20 w-48 h-48 bg-primary/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-20 -left-20 w-48 h-48 bg-blue-400/10 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Role Badge -->
        <div class="flex justify-center mb-5">
            <div class="inline-flex items-center gap-1.5 px-4 py-1.5 bg-primary text-white rounded-full text-xs font-semibold">
                <span class="material-icons-round text-sm"><?php echo $role_icon; ?></span>
                Signing up as <?php echo $role_title; ?>
            </div>
        </div>

        <!-- Header -->
        <div class="text-center mb-6">
            <h1 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white mb-2 tracking-tight">Create Your Account</h1>
            <p class="text-slate-500 dark:text-slate-400 text-sm tracking-wide">Join Abilisto and start your journey today</p>
        </div>

        <!-- SERVER ERROR BANNER -->
        <?php if ($signup_error): ?>
        <div class="server-error-banner">
            <span class="material-icons-round text-red-500 text-base">error</span>
            <?php
                if ($signup_error === 'email_taken') {
                    echo 'This email address is already registered. <a href="login.php" class="underline font-bold ml-1">Log in instead?</a>';
                } elseif ($signup_error === 'phone_taken') {
                    echo 'This phone number is already linked to an existing account.';
                } else {
                    echo 'Something went wrong. Please try again.';
                }
            ?>
        </div>
        <?php endif; ?>

        <!-- Registration Form -->
        <form action="register_core.php" method="POST" class="space-y-8" id="signupForm" onsubmit="return validateForm()">
            <input type="hidden" name="role" value="<?php echo $role; ?>">

            <!-- Personal Information -->
            <div class="space-y-4">
                <div class="flex items-center gap-2 px-3 py-1.5 w-fit bg-emerald-50 dark:bg-emerald-900/30 text-primary rounded-xl">
                    <span class="material-icons-round text-sm">person</span>
                    <h2 class="font-bold uppercase tracking-[0.2em] text-[10px]">Personal Information</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                    <!-- Full Name -->
                    <div>
                        <div class="input-wrapper">
                            <span class="material-icons-round input-icon">badge</span>
                            <input type="text" name="full_name" id="full_name"
                                   class="input-field input-glow text-sm text-slate-900 placeholder:text-slate-400"
                                   placeholder="Full Name (e.g., Juan Dela Cruz)" required>
                        </div>
                    </div>

                    <!-- Email -->
                    <div>
                        <div class="input-wrapper">
                            <span class="material-icons-round input-icon">alternate_email</span>
                            <input type="email" name="email" id="email"
                                   class="input-field input-glow text-sm text-slate-900 placeholder:text-slate-400
                                          <?php echo ($signup_error === 'email_taken') ? 'input-error' : ''; ?>"
                                   placeholder="Email Address" required>
                        </div>
                        <div id="email-error" class="error-message <?php echo ($signup_error === 'email_taken') ? '' : 'hidden'; ?>">
                            <?php if ($signup_error === 'email_taken'): ?>
                                <span class="material-icons-round text-xs">error</span> Email already registered.
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Password — with eye toggle -->
                    <div>
                        <div class="input-wrapper">
                            <span class="material-icons-round input-icon">lock_open</span>
                            <input type="password" name="password" id="password"
                                   class="input-field input-field-password input-glow text-sm text-slate-900 placeholder:text-slate-400"
                                   placeholder="Password" required>
                            <!-- Eye toggle button -->
                            <button type="button" class="eye-btn" id="togglePassword" tabindex="-1"
                                    aria-label="Toggle password visibility">
                                <span class="material-icons-round text-lg" id="eyeIcon">visibility</span>
                            </button>
                        </div>
                        <div id="password-error" class="error-message hidden"></div>
                    </div>

                    <!-- Phone -->
                    <div>
                        <div class="input-wrapper">
                            <span class="material-icons-round input-icon">smartphone</span>
                            <input type="tel" name="phone" id="phone"
                                   class="input-field input-glow text-sm text-slate-900 placeholder:text-slate-400
                                          <?php echo ($signup_error === 'phone_taken') ? 'input-error' : ''; ?>"
                                   placeholder="Phone Number (09XX XXX XXXX)" required>
                        </div>
                        <div id="phone-error" class="error-message <?php echo ($signup_error === 'phone_taken') ? '' : 'hidden'; ?>">
                            <?php if ($signup_error === 'phone_taken'): ?>
                                <span class="material-icons-round text-xs">error</span> Phone number already in use.
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Location Details -->
            <div class="space-y-4">
                <div class="flex items-center gap-2 px-3 py-1.5 w-fit bg-blue-50 dark:bg-blue-900/30 text-blue-500 rounded-xl">
                    <span class="material-icons-round text-sm">map</span>
                    <h2 class="font-bold uppercase tracking-[0.2em] text-[10px]">Location Details</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Province (fixed) -->
                    <div class="relative">
                        <select class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-xl text-sm text-slate-900 appearance-none" disabled>
                            <option selected>Province (Surigao del Sur)</option>
                        </select>
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 material-icons-round text-sm text-slate-400 pointer-events-none">expand_more</span>
                    </div>

                    <!-- Municipality -->
                    <div class="relative">
                        <select name="municipality" id="municipality"
                                class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary outline-none appearance-none text-sm text-slate-900"
                                onchange="updateBarangays()" required>
                            <option value="" disabled selected>Municipality / City</option>
                            <option>Tandag City</option><option>Bislig City</option><option>Barobo</option>
                            <option>Bayabas</option><option>Cagwait</option><option>Cantilan</option>
                            <option>Carmen</option><option>Carrascal</option><option>Cortes</option>
                            <option>Hinatuan</option><option>Lanuza</option><option>Lianga</option>
                            <option>Lingig</option><option>Madrid</option><option>Marihatag</option>
                            <option>San Agustin</option><option>San Miguel</option><option>Tagbina</option>
                            <option>Tago</option>
                        </select>
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 material-icons-round text-sm text-slate-400 pointer-events-none">expand_more</span>
                    </div>

                    <!-- Barangay -->
                    <div class="relative md:col-span-2">
                        <select name="barangay" id="barangay"
                                class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary outline-none appearance-none text-sm text-slate-900"
                                required>
                            <option value="" disabled selected>Select Barangay</option>
                        </select>
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 material-icons-round text-sm text-slate-400 pointer-events-none">expand_more</span>
                    </div>

                    <!-- Street (optional) -->
                    <div class="relative md:col-span-2">
                        <input type="text" name="street" id="street"
                               class="w-full px-4 py-3 bg-white/50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-primary outline-none text-sm text-slate-900 placeholder:text-slate-400"
                               placeholder="Street / Building / Landmark (Optional)">
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="space-y-5 pt-4">
                <button type="submit" name="register_btn"
                        class="w-full py-3 bg-gradient-to-r <?php echo $role_gradient; ?> text-white font-bold rounded-xl flex items-center justify-center gap-2 text-sm transition-all transform hover:-translate-y-1 active:scale-95 btn-glow">
                    <span class="material-icons-round text-sm">send</span>
                    Create <?php echo $role_title; ?> Account
                </button>

                <div class="flex items-center gap-3">
                    <div class="flex-1 h-px bg-slate-200"></div>
                    <span class="text-slate-400 text-xs font-medium">or</span>
                    <div class="flex-1 h-px bg-slate-200"></div>
                </div>

                <!-- Google Sign Up -->
                <a href="<?php echo $google_login_url; ?>"
                   class="w-full py-3 glass-card border border-slate-200 hover:bg-white/80 rounded-xl flex items-center justify-center gap-2 transition-all text-sm text-slate-700 font-semibold group">
                    <svg class="w-5 h-5 group-hover:scale-110 transition-transform" viewBox="0 0 48 48">
                        <path d="M44.5 20H24v8.5h11.8C34.7 33.9 30.1 37 24 37c-7.2 0-13-5.8-13-13s5.8-13 13-13c3.1 0 5.9 1.1 8.1 2.9l6.4-6.4C34.6 4.1 29.6 2 24 2 11.8 2 2 11.8 2 24s9.8 22 22 22c11 0 21-8 21-22 0-1.3-.2-2.7-.5-4z" fill="#4285F4"/>
                        <path d="M6.3 14.7l6.6 4.8C14.7 16.2 19.1 14 24 14c3.1 0 5.9 1.1 8.1 2.9l6.4-6.4C34.6 4.1 29.6 2 24 2 16.1 2 9.2 6.2 6.3 12.7z" fill="#EA4335"/>
                        <path d="M24 46c5.9 0 10.9-2 14.9-5.4l-7.1-5.9C29.6 36.4 27 37 24 37c-4.9 0-9.2-2.2-11.1-5.5l-6.6 5.1C9.2 41.8 16.1 46 24 46z" fill="#34A853"/>
                        <path d="M4.6 14.7l6.6 4.8C10.4 21.1 10 22.5 10 24s.4 2.9 1.2 4.5l-6.6 5.1C3.1 30.6 2 27.4 2 24s1.1-6.6 2.6-9.3z" fill="#FBBC05"/>
                    </svg>
                    Continue with Google
                </a>

                <div class="text-center space-y-3">
                    <p class="text-xs text-slate-500">
                        Already have an account?
                        <a class="text-primary font-bold hover:underline underline-offset-4 ml-1" href="login.php">Log In</a>
                    </p>
                    <div class="flex items-center justify-center gap-1.5 text-[9px] md:text-[10px] text-slate-400 px-4">
                        <span class="material-icons-round text-primary text-xs">check_circle</span>
                        <span>By signing up, you agree to our
                            <a class="underline" href="../terms.html">Terms of Service</a> and
                            <a class="underline" href="../privacy.html">Privacy Policy</a>
                        </span>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ============================================
    // EYE TOGGLE — show/hide password
    // ============================================
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    toggleBtn.addEventListener('click', function () {
        const isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        eyeIcon.textContent = isHidden ? 'visibility_off' : 'visibility';
    });

    // ============================================
    // BARANGAY DATA (unchanged)
    // ============================================
    const barangays = {
        'Tandag City': ['Bag-ong Lungsod','Bioto','Buenavista','Dagocdoc','Mabua','Maitum','Maticdum','Pandanon','Pangi','Quezon','Rosario','Salvacion','San Agustin Norte','San Agustin Sur','San Antonio','San Isidro','San Jose','Telaje','Tigman','Union','Villa Riza'],
        'Bislig City': ['Bucto','Burboanan','Caguyao','Coleto','Comawas','Kahayag','Labisma','Lawigan','Mangagoy','Mone','Pamanlinan','Poblacion','San Antonio','San Fernando','San Isidro','San Jose','San Roque','San Vicente','Santa Cruz','Tabon','Tumanan','Mahayag','Pamaypayan','San Agustin'],
        'Barobo': ['Amaga','Bahi','Cabacungan','Cambagang','Causwagan','Dapdap','Dughan','Gamut','Javier','Kinayan','Mamis','Poblacion','Rizal','San Jose','San Roque','San Vicente','Sua','Sudlon','Tambis','Unidad','Wakat'],
        'Bayabas': ['Amag','Balete','Cabugo','Cagbaoto','La Paz','Magobawok','Panaosawon'],
        'Cagwait': ['Aras-asan','Bacolod','Bitaugan','La Purisima','Magpayang','Mat-e','Poblacion','Tubo-tubo','Unidad','Villanueva','Tawas'],
        'Cantilan': ['Bugsukan','Buntalid','Cabangahan','Cabas-an','Calagdaan','Consuelo','General Island','Lininti-an','Lobo','Magasang','Magosilom','Pag-antayan','Palasao','Parang','San Pedro','Tapi','Tigabong'],
        'Carmen': ['Antao','Cancavan','Esperanza','Hinapuyan','Poblacion','Puyat','San Vicente','Santa Cruz'],
        'Carrascal': ['Adlay','Babuyan','Baybay','Bon-ot','Caglayag','Doyos','Embarcadero','Gamut','Pantukan','Saca','Tag-anito','Bacolod','Cabayawa','Manlangit'],
        'Cortes': ['Balibadon','Burgos','Capandan','Mabahin','Madrelino','Manlico','Matho','Poblacion','Tag-anongan','Tigao','Tuboran','Uba'],
        'Hinatuan': ['Baculin','Bogak','Cambatong','Campoyong','Harip','Loyola','Magapua','Mahayag','Pagan','Poblacion','Port Lamon','Roxas','San Juan','Sasa','Tagasaka','Talisay','Tarusan','Tidman','Bigaan','Cabuan','Calatngan','Malixi','Santa Cruz','Buhisan'],
        'Lanuza': ['Agsam','Bocboc','Habag','Mampi','Pakwan','Poblacion','Sibahay','Gamut','Langka','Sumalig','Granada','Purok','San Isidro'],
        'Lianga': ['Anibongan','Banahao','Baucawe','Diatagon','Ganayon','Liatimco','Mahayahay','Payasan','Poblacion','San Isidro','Saint Christine','Manyayay','Diomanoy'],
        'Lingig': ['Anibongan','Barcelona','Bogak','Handamayan','Libertad','Mahayahay','Mandus','Pagtila-an','Poblacion','Sabang','Salvacion','San Roque','Tagpoporan','Union','Valencia','Rajah Cabungso-an','Pangpang','San Vicente'],
        'Madrid': ['Bagsac','Bayogo','Linibonan','Magsaysay','Manga','Panayogon','Patong Patong','Quirino','San Antonio','San Juan','San Roque','San Vicente','Songkit','Union'],
        'Marihatag': ['Alegria','Amontay','Antipolo','Arorogan','Burgos','Central','Mararag','Poblacion','San Isidro','San Vicente','Santa Cruz','Santa Maria'],
        'San Agustin': ['Buhisan','Cagwait','Carmen','Hinchunan','Hornasan','Janipaan','Kauswagan','Oteiza','Poblacion','Salvacion','Santo Niño','Sibahay','Tina'],
        'San Miguel': ['Bolhoon','Carromata','Coronon','Dagohoy','Janipaan','Mabuhay','Poblacion','San Isidro','San Juan','San Roque','San Vicente','Santa Cruz','Tina','Liberty','Mahayag','Baybay','Cawilan','Umalag'],
        'Tagbina': ['Batunan','Carpenito','Doña Carmen','Hinagdanan','Kahayagan','Lago','Maglambing','Maglatab','Magsaysay','Malixi','Manambia','Osmeña','Poblacion','Quezon','San Vicente','Santa Cruz','Santa Fe','Santa Juana','Santa Maria','Sayon','Soriano','Tagongon','Trinidad','Ugoban','Villaverde'],
        'Tago': ['Alba','Anahao Bag-o','Anahao Daan','Badong','Bajao','Bangsud','Cabangahan','Cagdapao','Camagong','Caras-an','Cayale','Dayo-an','Gamut','Jubang','Kinabigtasan','Layog','Lindoy','Mercedes','Purisima','Sumo-sumo','Umbay','Unaban','Unidos','Victoria']
    };

    window.updateBarangays = function () {
        const municipality = document.getElementById('municipality').value;
        const barangaySelect = document.getElementById('barangay');
        barangaySelect.innerHTML = '<option value="" disabled selected>Select Barangay</option>';
        if (municipality && barangays[municipality]) {
            barangays[municipality].sort().forEach(function (b) {
                const opt = document.createElement('option');
                opt.value = opt.textContent = b;
                barangaySelect.appendChild(opt);
            });
            barangaySelect.disabled = false;
        } else {
            barangaySelect.disabled = true;
        }
    };
    updateBarangays();

    // ============================================
    // REAL-TIME VALIDATION (unchanged)
    // ============================================
    const emailInput    = document.getElementById('email');
    const phoneInput    = document.getElementById('phone');
    const passwordInput2 = document.getElementById('password');

    emailInput.addEventListener('blur', function () {
        if (this.value.trim() && !validateEmail(this.value.trim())) {
            showError('email', 'Please enter a valid email address');
        } else if (this.value.trim()) {
            hideError('email');
        }
    });

    phoneInput.addEventListener('blur', function () {
        const clean = this.value.trim().replace(/\s/g, '');
        if (this.value.trim() && !validatePhone(clean)) {
            showError('phone', 'Phone number must be 11 digits starting with 09');
        } else if (this.value.trim()) {
            hideError('phone');
        }
    });

    passwordInput2.addEventListener('blur', function () {
        if (this.value && !validatePassword(this.value)) {
            showError('password', 'Password must be at least 8 characters with 1 uppercase and 1 number/special character');
        } else if (this.value) {
            hideError('password');
        }
    });

    phoneInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^\d]/g, '');
    });
});

// ============================================
// VALIDATION HELPERS (unchanged)
// ============================================
function validateEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}
function validatePhone(phone) {
    return /^09\d{9}$/.test(phone.replace(/\s/g, ''));
}
function validatePassword(password) {
    return password.length >= 8
        && /[A-Z]/.test(password)
        && /[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
}
function showError(inputId, message) {
    const input    = document.getElementById(inputId);
    const errorDiv = document.getElementById(inputId + '-error');
    input.classList.add('input-error');
    errorDiv.innerHTML = '<span class="material-icons-round text-xs">error</span> ' + message;
    errorDiv.classList.remove('hidden');
}
function hideError(inputId) {
    const input    = document.getElementById(inputId);
    const errorDiv = document.getElementById(inputId + '-error');
    input.classList.remove('input-error');
    errorDiv.classList.add('hidden');
}
function validateForm() {
    let isValid = true;
    const email    = document.getElementById('email').value.trim();
    const phone    = document.getElementById('phone').value.trim();
    const password = document.getElementById('password').value;

    if (!validateEmail(email)) {
        showError('email', 'Please enter a valid email address (e.g., name@domain.com)');
        isValid = false;
    } else { hideError('email'); }

    if (!validatePhone(phone.replace(/\s/g, ''))) {
        showError('phone', 'Phone number must be 11 digits starting with 09 (e.g., 09123456789)');
        isValid = false;
    } else { hideError('phone'); }

    if (!validatePassword(password)) {
        showError('password', 'Password must be at least 8 characters with 1 uppercase and 1 number/special character');
        isValid = false;
    } else { hideError('password'); }

    if (!isValid) {
        const firstError = document.querySelector('.input-error');
        if (firstError) firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    return isValid;
}
</script>

</body>
</html>