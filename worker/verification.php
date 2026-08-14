<?php
// ============================================================
// verification.php  –  v4: Added face photo step (Step 3.5)
// Changes from v3:
//   1. New Step 3.5 — face selfie, stored in private_uploads/face/
//   2. face_photo saved to verification table
//   3. Step indicator expanded to 5 steps with larger filled icons
//   4. Step indicator icons made more visible (filled style, larger dot)
// DB migration required (run once):
//   ALTER TABLE `verification`
//     ADD COLUMN `face_photo` varchar(255) DEFAULT NULL
//     AFTER `id_photo_back`;
// ============================================================

require_once '../db.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.tailwindcss.com https://fonts.googleapis.com 'unsafe-inline'; style-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");
header('Permissions-Policy: camera=self');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/verification_errors.log');
define('APP_LOG', __DIR__ . '/verification_app.log');
function app_log(string $msg, $data = null): void {
    $entry = date('Y-m-d H:i:s') . ' [INFO] ' . $msg;
    if ($data !== null) $entry .= ' | ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    file_put_contents(APP_LOG, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

define('ENC_KEY_HEX', getenv('VERIFICATION_ENC_KEY') ?: 'a3f5c8d1e2b4f6a8c0d2e4f6a8b0c2d4e6f8a0b2c4d6e8f0a2b4c6d8e0f2a4b6');
define('ENC_KEY', hex2bin(ENC_KEY_HEX));
function encrypt_field(?string $plaintext): ?string {
    if ($plaintext === null || $plaintext === '') return null;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plaintext, 'aes-256-gcm', ENC_KEY, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return base64_encode($iv . $tag . $ct);
}

function harden_session(): void {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ini_set('session.cookie_secure', 1);
    $fp = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if (!isset($_SESSION['_fp'])) { $_SESSION['_fp'] = $fp; }
    elseif (!hash_equals($_SESSION['_fp'], $fp)) { session_unset(); session_destroy(); session_start(); $_SESSION['_fp'] = $fp; }
    if (isset($_SESSION['_last_active']) && (time() - $_SESSION['_last_active']) > 1800) { session_unset(); session_destroy(); session_start(); $_SESSION['_fp'] = $fp; }
    $_SESSION['_last_active'] = time();
}
harden_session();

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) { http_response_code(403); exit('Invalid CSRF token.'); }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function rate_limit(int $max = 10, int $window = 600): void {
    $now = time();
    if (!isset($_SESSION['_rl_count'], $_SESSION['_rl_start'])) { $_SESSION['_rl_count'] = 0; $_SESSION['_rl_start'] = $now; }
    if (($now - $_SESSION['_rl_start']) > $window) { $_SESSION['_rl_count'] = 0; $_SESSION['_rl_start'] = $now; }
    $_SESSION['_rl_count']++;
    if ($_SESSION['_rl_count'] > $max) { http_response_code(429); exit('Too many requests. Please wait a few minutes.'); }
}

function san_text(?string $v, int $max = 255): string { return mb_substr(strip_tags(trim((string)($v ?? ''))), 0, $max); }
function san_int(?string $v, int $min, int $max): ?int { $i = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]); return ($i === false) ? null : (int)$i; }
function san_date(?string $v): ?string { if (!$v) return null; $d = DateTime::createFromFormat('Y-m-d', $v); return ($d && $d->format('Y-m-d') === $v) ? $v : null; }
function san_enum(?string $v, array $allowed): ?string { return in_array($v, $allowed, true) ? $v : null; }
$e = fn(?string $s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/heic']);
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp', 'heic']);
define('MAX_FILE_SIZE', 8 * 1024 * 1024);
define('UPLOAD_BASE', dirname(__DIR__) . '/private_uploads');
function validate_and_store_upload(array $file_arr, string $sub_dir): array {
    if ($file_arr['error'] !== UPLOAD_ERR_OK) return ['error' => 'Upload error code: ' . (int)$file_arr['error']];
    if ($file_arr['size'] > MAX_FILE_SIZE) return ['error' => 'File exceeds the 8 MB size limit.'];
    if (!is_uploaded_file($file_arr['tmp_name'])) return ['error' => 'Invalid upload.'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = $finfo->file($file_arr['tmp_name']);
    if (!in_array($detected, ALLOWED_MIME_TYPES, true)) return ['error' => 'Only JPEG, PNG, WebP, or HEIC images are accepted.'];
    $orig_ext = strtolower(pathinfo($file_arr['name'], PATHINFO_EXTENSION));
    if (!in_array($orig_ext, ALLOWED_EXTENSIONS, true)) return ['error' => 'File extension not allowed.'];
    $dir = UPLOAD_BASE . '/' . $sub_dir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return ['error' => 'Upload directory could not be created.'];
    $new_name = bin2hex(random_bytes(16)) . '.' . $orig_ext;
    if (!move_uploaded_file($file_arr['tmp_name'], $dir . '/' . $new_name)) return ['error' => 'Failed to save uploaded file.'];
    return ['path' => $sub_dir . '/' . $new_name];
}

const VALID_NATIONALITY  = ['Filipino', 'Others'];
const VALID_CIVIL_STATUS = ['Single', 'Married', 'Divorced', 'Widowed', 'Separated'];
const VALID_SEX          = ['Male', 'Female'];
const VALID_NC_LEVEL     = ['NC I', 'NC II', 'NC III'];
const VALID_ID_TYPES     = ['Passport', 'SSS ID', 'GSIS ID', "Driver's License", 'PhilHealth ID', "Voter's ID", 'PRC ID', 'Postal ID', 'National ID', 'Barangay ID'];
const NC_TO_BADGE        = ['NC I' => 'Bronze', 'NC II' => 'Silver', 'NC III' => 'Gold'];
const VALID_SUB_CATEGORIES = ['Electrical','Plumbing','Carpentry','Masonry','Welding','Construction','Automotive','Motorcycle','Electronics','Aircon Ref','Computer Tech','Domestic Work','Caregiving','Massage','Beauty Care','Cookery','Baking','Graphic_Design','Photography','Videography','Music','Arts Crafts','Others'];
const SUB_EMOJI = ['Electrical'=>'⚡','Plumbing'=>'💧','Carpentry'=>'🔨','Masonry'=>'🧱','Welding'=>'🔥','Construction'=>'🏗️','Automotive'=>'🚘','Motorcycle'=>'🏍️','Electronics'=>'📱','Aircon Ref'=>'❄️','Computer Tech'=>'💻','Domestic Work'=>'🧹','Caregiving'=>'🤱','Massage'=>'💆','Beauty Care'=>'💇','Cookery'=>'👨‍🍳','Baking'=>'🥖','Graphic_Design'=>'🎨','Photography'=>'📸','Videography'=>'🎥','Music'=>'🎵','Arts Crafts'=>'🖌️','Others'=>'🔹'];

$barangays = [
    'Tandag City (Capital)' => ['Awasian','Bagong Lungsod','Bioto','Bongtod','Buenavista','Dagocdoc','Mabua','Mabuhay','Maitum','Maticdum','Pandanon','Pangi','Quezon','Rosario','Salvacion','San Agustin Norte','San Agustin Sur','San Antonio','San Jose','Telaje','Victoria'],
    'Cantilan'  => ['Bugsukan','Buntalid','Cabangahan','Cabas-an','Calagdaan','Consuelo','General Island','Lininti-an','Lobo','Magasang','Magosilom','Pag-antayan','Palasao','Parang','San Pedro','Tapi','Tigabong'],
    'Carrascal' => ['Adlay','Babuyan','Bacolod','Baybay','Bon-ot','Caglayag','Dahican','Doyos','Embarcadero','Gamuton','Panikian','Pantukan','Saca','Tag-anito'],
    'Madrid'    => ['Bagsac','Bayogo','Linibonan','Magsaysay','Manga','Panayogan','Patong','Poblacion','Punot','San Antonio','San Juan','San Roque','Union','Quirino'],
    'Carmen'    => ['Antao','Cancavan','Esperanza','Hinapuyan','Poblacion','Puyat','San Vicente','Santa Cruz'],
    'Lanuza'    => ['Agsam','Bocawe','Bunga','Gamuton','Habag','Mampi','Nurcia','Pakwan','Sibahay','Zone I','Zone II','Zone III','Zone IV'],
    'Cortes'    => ['Balibadon','Burgos','Capandan','Mabahin','Madrelino','Manlico','Matho','Poblacion','Tag-anongan','Tigao','Tuboran','Uba'],
    'Tago'      => ['Alba','Anahao Bag-o','Anahao Daan','Badong','Bajao','Bangsud','Cabangahan','Cagdapao','Camagong','Caras-an','Cayale','Dayo-an','Gamut','Jubang','Kinabigtasan','Layog','Lindoy','Mercedes','Purisima','Sumo-sumo','Umbay','Unaban','Unidos','Victoria'],
    'San Miguel'=> ['Bagyang','Baras','Bitaugan','Bolhoon','Calatngan','Carromata','Castillo','Libas Gua','Libas Sud','Magroyong','Mahayag','Patong','Poblacion','Sagbayan','San Roque','Siagao','Tina','Umalag'],
    'Bayabas'   => ['Amag','Balete','Cabugo','Cagbaoto','La Paz','Magobago','Panaosawon'],
    'Cagwait'   => ['Aras-asan','Bacolod','Bitaugan East','Bitaugan West','La Purisima','Lactudan','Mat-e','Poblacion','Tawagan','Tubo-tubo','Unidad'],
    'Marihatag' => ['Alegria','Amontay','Antipolo','Arorogan','Bayan','Mahaba','Mararag','Poblacion','San Antonio','San Isidro','San Pedro','Santa Cruz'],
    'Bislig City'  => ['Bucto','Burboanan','Caguyao','Coleto','Cumawas','Kahayag','Labisma','Lawigan','Maharlika','Mangagoy','Mone','Pamanlinan','Pamaypayan','Poblacion','San Antonio','San Fernando','San Isidro','San Jose','San Roque','San Vicente','Santa Cruz','Sibaroy','Tabon','Tumanan'],
    'San Agustin'  => ['Britania','Buatong','Buhisan','Gata','Hornasan','Janipaan','Kauswagan','Oteiza','Poblacion','Pong-on','Pongtod','Salvacion','Santo Niño'],
    'Lianga'       => ['Banahao','Baucawe','Diatagon','Ganayon','Liatimco','Manyayay','Payasan','Poblacion','Saint Christine','San Isidro','San Jose','San Roque'],
    'Barobo'       => ['Amaga','Bahi','Cabacungan','Cambine','Causwagan','Datu Facundo','Datu Anecita','Glan','Guinhalinan','Javier','Kinabigtasan','Magsaysay','Mamis','Pagsilaan','Poblacion','Rizal','San Roque','San Jose','San Vicente','Sua','Tambis'],
    'Tagbina'      => ['Batunan','Carpenito','Doña Carmen','Hinagdanan','Kahayagan','Lago','Maglambing','Maglatab','Magsaysay','Malixi','Manambia','Osmeña','Poblacion','Quezon','San Vicente','Santa Cruz','Santa Fe','Santa Juana','Santa Maria','Sayon','Soriano','Tagongon','Trinidad','Ugoban','Villaverde'],
    'Hinatuan'     => ['Baculin','Benigno Aquino','Bigaan','Cambatong','Campa','Dugmanon','Harip','La Casa','Loyola','Maligaya','Pagtigni-an','Pocto','Port Lamon','Roxas','San Juan','Sasa','Tagasaka','Tagbobonga','Talisay','Tarusan','Tidman','Tiwi','Zone I','Zone II'],
    'Lingig'       => ['Anibongan','Barcelona','Bogak','Bituaugan','Handayan','Mahayahay','Mandus','Mansa-ilao','Pagtila-an','Palo Alto','Poblacion','Rajah Cabungso-an','Sabang','Salvacion','San Roque','Tagpoporan','Union','Valencia'],
];
ksort($barangays);
$municipalities = array_keys($barangays);
$district1 = ['Tandag City (Capital)','Cantilan','Carrascal','Madrid','Carmen','Lanuza','Cortes','Tago','San Miguel','Bayabas','Cagwait','Marihatag'];
$district2 = ['Bislig City','San Agustin','Lianga','Barobo','Tagbina','Hinatuan','Lingig'];

if (!isset($_SESSION['user_id'])) { http_response_code(403); exit('You must be logged in.'); }
$user_id = (int)$_SESSION['user_id'];

$worker_skills = [];
try {
    $stmt = $pdo->prepare("SELECT sub_category, main_category FROM worker_skills WHERE worker_id = ? ORDER BY id ASC LIMIT 3");
    $stmt->execute([$user_id]);
    $worker_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $ex) { app_log('Error fetching worker skills', ['msg' => $ex->getMessage()]); }

$show_pending_modal = false;
try {
    $stmt = $pdo->prepare("SELECT verification_status FROM verification WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing && $existing['verification_status'] === 'pending') $show_pending_modal = true;
} catch (PDOException $ex) { app_log('Error checking existing verification', ['msg' => $ex->getMessage()]); }

$error = null; $success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit(15, 600);
    csrf_verify();

    if (isset($_POST['step1_submit'])) {
        $same     = !empty($_POST['same_as_permanent']) ? 1 : 0;
        $perm_mun = san_enum($_POST['permanent_municipality'] ?? null, $municipalities);
        $perm_bar = ($perm_mun && isset($barangays[$perm_mun])) ? san_enum($_POST['permanent_barangay'] ?? null, $barangays[$perm_mun]) : null;
        if ($same) { $cur_mun = $perm_mun; $cur_bar = $perm_bar; $cur_str = san_text($_POST['permanent_street'] ?? ''); $cur_hse = san_text($_POST['permanent_house'] ?? ''); }
        else { $cur_mun = san_enum($_POST['current_municipality'] ?? null, $municipalities); $cur_bar = ($cur_mun && isset($barangays[$cur_mun])) ? san_enum($_POST['current_barangay'] ?? null, $barangays[$cur_mun]) : null; $cur_str = san_text($_POST['current_street'] ?? ''); $cur_hse = san_text($_POST['current_house'] ?? ''); }
        $dob = san_date($_POST['date_of_birth'] ?? null); $age = san_int($_POST['age'] ?? null, 18, 120); $nat = san_enum($_POST['nationality'] ?? null, VALID_NATIONALITY); $cs = san_enum($_POST['civil_status'] ?? null, VALID_CIVIL_STATUS); $sex = san_enum($_POST['sex'] ?? null, VALID_SEX);
        if (!$perm_mun || !$perm_bar || !$dob || !$age || !$nat || !$cs || !$sex) { $error = 'Please fill all required fields with valid values.'; }
        else {
            $_SESSION['verification'] = ['user_id'=>$user_id,'first_name'=>san_text($_POST['first_name']??''),'middle_name'=>san_text($_POST['middle_name']??''),'last_name'=>san_text($_POST['last_name']??''),'age'=>$age,'nationality'=>$nat,'civil_status'=>$cs,'date_of_birth'=>$dob,'place_of_birth'=>san_text($_POST['place_of_birth']??''),'sex'=>$sex,'permanent_region'=>'Surigao del Sur','permanent_municipality'=>$perm_mun,'permanent_barangay'=>$perm_bar,'permanent_street'=>san_text($_POST['permanent_street']??''),'permanent_house_details'=>san_text($_POST['permanent_house']??''),'current_region'=>'Surigao del Sur','current_municipality'=>$cur_mun,'current_barangay'=>$cur_bar,'current_street'=>$cur_str,'current_house_details'=>$cur_hse,'same_as_permanent'=>$same,'zip_code'=>san_text($_POST['zip_code']??'',10)];
            $_SESSION['verification_step'] = 2;
        }
    }
    elseif (isset($_POST['step2_submit'])) {
        $id_type = san_enum($_POST['id_type'] ?? null, VALID_ID_TYPES); $id_number = san_text($_POST['id_number'] ?? '', 100);
        if (!$id_type || $id_number === '') { $error = 'Please select an ID type and enter your ID number.'; }
        else { $_SESSION['verification']['id_type'] = $id_type; $_SESSION['verification']['id_number'] = encrypt_field($id_number); $_SESSION['verification_step'] = 3; }
    }
    elseif (isset($_POST['upload_id_submit'])) {
        $front = validate_and_store_upload($_FILES['id_front'] ?? [], 'ids');
        if (isset($front['error'])) { $error = 'Front ID: ' . $front['error']; }
        else {
            $back = validate_and_store_upload($_FILES['id_back'] ?? [], 'ids');
            if (isset($back['error'])) { $error = 'Back ID: ' . $back['error']; }
            else {
                $_SESSION['verification']['id_photo_front'] = $front['path'];
                $_SESSION['verification']['id_photo_back']  = $back['path'];
                $_SESSION['verification_step'] = 35; // → face photo step
            }
        }
    }
    // ---- NEW STEP: face photo ----
    elseif (isset($_POST['upload_face_submit'])) {
        $face = validate_and_store_upload($_FILES['face_photo'] ?? [], 'face');
        if (isset($face['error'])) { $error = 'Face photo: ' . $face['error']; }
        else {
            $_SESSION['verification']['face_photo'] = $face['path'];
            $_SESSION['verification_step'] = 4;
        }
    }
    elseif (isset($_POST['submit_nc_step'])) {
        $skill_nc_data = []; $has_any_nc = false; $upload_error = null;
        foreach ($worker_skills as $si => $skill) {
            $sub      = $skill['sub_category'];
            $nc_level = san_enum($_POST['nc_level'][$si] ?? null, VALID_NC_LEVEL);
            $nc_num   = san_text($_POST['nc_certificate_number'][$si] ?? '', 100);
            $has_file = isset($_FILES['nc_certificate']['error'][$si]) && $_FILES['nc_certificate']['error'][$si] === UPLOAD_ERR_OK;
            if (!$nc_level && !$nc_num && !$has_file) { $skill_nc_data[$si] = ['sub_category'=>$sub,'submitted'=>false]; continue; }
            if (!$nc_level) { $upload_error = "Please select the NC level for {$sub}."; break; }
            if ($nc_num === '') { $upload_error = "Please enter the NC certificate number for {$sub}."; break; }
            if (!$has_file) { $upload_error = "Please upload the certificate photo for {$sub}."; break; }
            $file_arr = ['name'=>$_FILES['nc_certificate']['name'][$si],'type'=>$_FILES['nc_certificate']['type'][$si],'tmp_name'=>$_FILES['nc_certificate']['tmp_name'][$si],'error'=>$_FILES['nc_certificate']['error'][$si],'size'=>$_FILES['nc_certificate']['size'][$si]];
            $nc_upload = validate_and_store_upload($file_arr, 'nc_certificates');
            if (isset($nc_upload['error'])) { $upload_error = "NC cert for {$sub}: " . $nc_upload['error']; break; }
            $skill_nc_data[$si] = ['sub_category'=>$sub,'submitted'=>true,'nc_level'=>$nc_level,'nc_certificate_number'=>encrypt_field($nc_num),'nc_certificate_image'=>$nc_upload['path'],'badge_level'=>NC_TO_BADGE[$nc_level]];
            $has_any_nc = true;
        }
        if ($upload_error) { $error = $upload_error; }
        else { $_SESSION['verification']['skill_nc_data'] = $skill_nc_data; $_SESSION['verification']['has_nc_certificate'] = $has_any_nc ? 1 : 0; if (save_verification($_SESSION['verification'], $pdo)) { $success = true; } else { $error = 'Failed to save verification data. Please try again.'; } }
    }
}

// ============================================================
// save_verification() — KEY FIX:
//   NC certs now go into verification_nc_skills (not verification_documents)
//   so admin/verifications.php can read them correctly.
//   v4 addition: face_photo column included in INSERT.
// ============================================================
function save_verification(array $data, PDO $pdo): bool {
    try {
        $pdo->beginTransaction();

        // 1. Insert KYC record (face_photo added in v4)
        $stmt = $pdo->prepare("INSERT INTO verification (user_id,first_name,middle_name,last_name,age,nationality,civil_status,date_of_birth,place_of_birth,sex,permanent_region,permanent_municipality,permanent_barangay,permanent_street,permanent_house_details,current_region,current_municipality,current_barangay,current_street,current_house_details,same_as_permanent,zip_code,id_type,id_number,id_photo_front,id_photo_back,face_photo,has_nc_certificate,verification_status) VALUES (:user_id,:first_name,:middle_name,:last_name,:age,:nationality,:civil_status,:date_of_birth,:place_of_birth,:sex,:permanent_region,:permanent_municipality,:permanent_barangay,:permanent_street,:permanent_house_details,:current_region,:current_municipality,:current_barangay,:current_street,:current_house_details,:same_as_permanent,:zip_code,:id_type,:id_number,:id_photo_front,:id_photo_back,:face_photo,:has_nc_certificate,'pending')");
        $stmt->execute([':user_id'=>$data['user_id'],':first_name'=>$data['first_name']??'',':middle_name'=>$data['middle_name']?:null,':last_name'=>$data['last_name']??'',':age'=>$data['age'],':nationality'=>$data['nationality']??'',':civil_status'=>$data['civil_status']??'',':date_of_birth'=>$data['date_of_birth']??'',':place_of_birth'=>$data['place_of_birth']??'',':sex'=>$data['sex']??'',':permanent_region'=>'Surigao del Sur',':permanent_municipality'=>$data['permanent_municipality']??'',':permanent_barangay'=>$data['permanent_barangay']??'',':permanent_street'=>$data['permanent_street']?:null,':permanent_house_details'=>$data['permanent_house_details']?:null,':current_region'=>'Surigao del Sur',':current_municipality'=>$data['current_municipality']??'',':current_barangay'=>$data['current_barangay']??'',':current_street'=>$data['current_street']?:null,':current_house_details'=>$data['current_house_details']?:null,':same_as_permanent'=>$data['same_as_permanent']??0,':zip_code'=>$data['zip_code']??'',':id_type'=>$data['id_type']??'',':id_number'=>$data['id_number']?:null,':id_photo_front'=>$data['id_photo_front']?:null,':id_photo_back'=>$data['id_photo_back']?:null,':face_photo'=>$data['face_photo']?:null,':has_nc_certificate'=>$data['has_nc_certificate']??0]);
        $verification_id = $pdo->lastInsertId();

        // 2. Update worker_skills with NC data (pending admin review)
        foreach (($data['skill_nc_data'] ?? []) as $nc) {
            if (empty($nc['submitted'])) continue;
            $upd = $pdo->prepare("UPDATE worker_skills SET nc_level=:nc_level, nc_certificate_number=:nc_cert_num, nc_certificate_image=:nc_cert_img, badge_level=:badge_level, is_verified=0, verification_notes='Pending admin review', updated_at=NOW() WHERE worker_id=:worker_id AND sub_category=:sub_category");
            $upd->execute([':nc_level'=>$nc['nc_level'],':nc_cert_num'=>$nc['nc_certificate_number'],':nc_cert_img'=>$nc['nc_certificate_image'],':badge_level'=>$nc['badge_level'],':worker_id'=>$data['user_id'],':sub_category'=>$nc['sub_category']]);
        }

        // 3. *** FIX: Insert into verification_nc_skills so admin panel can read them ***
        //    (Previously inserted into verification_documents — wrong table)
        $nc_ins = $pdo->prepare("
            INSERT INTO verification_nc_skills
                (verification_id, worker_id, sub_category, main_category, nc_level,
                 badge_level, nc_certificate_number, nc_certificate_image, created_at)
            VALUES
                (:vid, :wid, :sub, :main, :nc_level, :badge, :cert_num, :cert_img, NOW())
        ");
        $get_main = $pdo->prepare("SELECT main_category FROM worker_skills WHERE worker_id = :wid AND sub_category = :sub LIMIT 1");

        foreach (($data['skill_nc_data'] ?? []) as $nc) {
            if (empty($nc['submitted'])) continue;
            $get_main->execute([':wid' => $data['user_id'], ':sub' => $nc['sub_category']]);
            $main_cat = $get_main->fetchColumn() ?: '';
            $nc_ins->execute([
                ':vid'      => $verification_id,
                ':wid'      => $data['user_id'],
                ':sub'      => $nc['sub_category'],
                ':main'     => $main_cat,
                ':nc_level' => $nc['nc_level'],
                ':badge'    => $nc['badge_level'],
                ':cert_num' => $nc['nc_certificate_number'],
                ':cert_img' => $nc['nc_certificate_image'],
            ]);
        }

        $pdo->commit();
        app_log('Verification saved', ['user_id'=>$data['user_id'],'verification_id'=>$verification_id,'nc_count'=>count(array_filter($data['skill_nc_data']??[], fn($n)=>!empty($n['submitted'])))]);
        unset($_SESSION['verification'], $_SESSION['verification_step']);
        return true;
    } catch (PDOException $ex) {
        $pdo->rollBack();
        app_log('DB error in save_verification', ['msg' => $ex->getMessage()]);
        return false;
    }
}

if (!isset($_SESSION['verification_step'])) $_SESSION['verification_step'] = 1;
$step = (int)$_SESSION['verification_step'];
// Internal: step 35 = face photo (between ID scan and NC certs)
// Display mapping: 1→1, 2→2, 3→3, 35→4, 4→5
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Identity Verification · Abilisto</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com"></script>
<style>
body{font-family:'Plus Jakarta Sans',sans-serif;background-color:#F8FAFC;color:#0F172A;}
.glass{backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);background:rgba(255,255,255,0.8);border:1px solid rgba(255,255,255,0.45);}
.field-group{display:flex;flex-direction:column;gap:.375rem;}
.field-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#64748B;padding-left:.125rem;}
.field-input{width:100%;padding:.75rem 1rem;border-radius:.75rem;background:rgba(255,255,255,.5);backdrop-filter:blur(4px);border:1px solid rgba(226,232,240,.8);font-size:.875rem;color:#1E293B;outline:none;transition:all .2s;}
.field-input:focus{border-color:#2563EB;box-shadow:0 0 0 2px rgba(37,99,235,.15);}
.field-input-icon{width:100%;padding:.75rem 1rem .75rem 2.5rem;border-radius:.75rem;background:rgba(255,255,255,.5);backdrop-filter:blur(4px);border:1px solid rgba(226,232,240,.8);font-size:.875rem;color:#1E293B;outline:none;transition:all .2s;}
.field-input-icon:focus{border-color:#2563EB;box-shadow:0 0 0 2px rgba(37,99,235,.15);}
.field-select{width:100%;padding:.75rem 2.25rem .75rem 2.5rem;border-radius:.75rem;background:rgba(255,255,255,.5);backdrop-filter:blur(4px);border:1px solid rgba(226,232,240,.8);font-size:.875rem;color:#1E293B;outline:none;transition:all .2s;appearance:none;}
.field-select:focus{border-color:#2563EB;box-shadow:0 0 0 2px rgba(37,99,235,.15);}
.field-select-plain{width:100%;padding:.75rem 1rem;border-radius:.75rem;background:rgba(255,255,255,.5);backdrop-filter:blur(4px);border:1px solid rgba(226,232,240,.8);font-size:.875rem;color:#1E293B;outline:none;transition:all .2s;appearance:none;}
.field-select-plain:focus{border-color:#2563EB;box-shadow:0 0 0 2px rgba(37,99,235,.15);}
.field-icon{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:#1E293B;font-size:18px;pointer-events:none;}
.field-chevron{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);color:#94A3B8;pointer-events:none;}
.active-glow{box-shadow:0 0 16px rgba(37,99,235,.25);}
.sex-radio:checked+div{background:linear-gradient(to right,#2563EB,#4F46E5);color:white;border-color:transparent;box-shadow:0 4px 6px -1px rgba(37,99,235,.2);}
.sex-radio:checked+div .material-symbols-outlined{color:white;}
/* ---- Step indicator dots: filled icons, larger, more visible ---- */
.step-dot-active{
    width:3rem;height:3rem;border-radius:9999px;
    background:linear-gradient(135deg,#2563EB,#4F46E5);
    color:#fff;display:flex;align-items:center;justify-content:center;
    box-shadow:0 0 0 4px rgba(37,99,235,.2),0 4px 14px rgba(37,99,235,.4);
}
.step-dot-done{
    width:3rem;height:3rem;border-radius:9999px;
    background:linear-gradient(135deg,#2563EB,#4F46E5);
    color:#fff;display:flex;align-items:center;justify-content:center;
    opacity:.65;
}
.step-dot-inactive{
    width:3rem;height:3rem;border-radius:9999px;
    background:#F1F5F9;border:2px solid #E2E8F0;
    color:#94A3B8;display:flex;align-items:center;justify-content:center;
}
.step-dot-active  .material-symbols-outlined,
.step-dot-done    .material-symbols-outlined {
    font-size:1.5rem;
    font-variation-settings:'FILL' 1,'wght' 700,'GRAD' 0,'opsz' 24;
}
.step-dot-inactive .material-symbols-outlined {
    font-size:1.375rem;
    font-variation-settings:'FILL' 0,'wght' 300,'GRAD' 0,'opsz' 24;
}
/* ---------------------------------------------------------------- */
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
.camera-preview{max-width:100%;max-height:220px;border-radius:14px;margin-top:10px;}
.section-card{padding:1.25rem;border-radius:1rem;background:rgba(255,255,255,.6);border:1px solid rgba(226,232,240,.6);box-shadow:0 1px 2px rgba(0,0,0,.05);}
.section-card-header{display:flex;align-items:center;gap:.625rem;margin-bottom:1.25rem;padding-bottom:1rem;border-bottom:1px solid #F1F5F9;}
.section-icon{width:2rem;height:2rem;border-radius:.5rem;background:#EFF6FF;display:flex;align-items:center;justify-content:center;color:#2563EB;flex-shrink:0;}
.success-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(255,255,255,.95);backdrop-filter:blur(10px);display:flex;align-items:center;justify-content:center;z-index:9999;animation:fadeIn .3s ease-out;}
.success-check{width:80px;height:80px;background:linear-gradient(135deg,#10b981,#059669);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;animation:scaleIn .5s ease-out;}
.success-check .material-symbols-outlined{font-size:48px;color:white;}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes scaleIn{from{transform:scale(0);}to{transform:scale(1);}}
.progress-bar{width:0%;animation:progress 3s linear forwards;}
@keyframes progress{to{width:100%;}}
.skill-nc-panel{border:2px solid rgba(226,232,240,.8);border-radius:1rem;overflow:hidden;transition:border-color .2s,box-shadow .2s;}
.skill-nc-panel.has-nc{border-color:#3B82F6;box-shadow:0 0 0 3px rgba(59,130,246,.12);}
.skill-nc-panel.skip-nc{border-color:rgba(226,232,240,.5);opacity:.55;}
.skill-nc-header{display:flex;align-items:center;gap:12px;padding:14px 16px;background:rgba(255,255,255,.7);cursor:pointer;user-select:none;}
.skill-nc-body{padding:0 16px;max-height:0;overflow:hidden;transition:max-height .35s cubic-bezier(.22,1,.36,1),padding .35s;}
.skill-nc-body.open{max-height:700px;padding:16px;}
.skill-emoji{font-size:22px;line-height:1;}
.nc-status-pill{margin-left:auto;font-size:10px;font-weight:700;padding:3px 10px;border-radius:99px;transition:all .2s;white-space:nowrap;}
.nc-status-pill.optional{background:rgba(100,116,139,.1);color:#64748b;border:1px solid rgba(100,116,139,.2);}
.nc-status-pill.filled{background:rgba(34,197,94,.1);color:#15803d;border:1px solid rgba(34,197,94,.25);}
.nc-status-pill.inprogress{background:rgba(245,158,11,.1);color:#b45309;border:1px solid rgba(245,158,11,.25);}
.nc-status-pill.skipped{background:rgba(100,116,139,.06);color:#94a3b8;border:1px solid rgba(100,116,139,.15);}
.badge-preview-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:800;border:1px solid;}
.badge-Gold{background:rgba(217,119,6,.15);color:#B45309;border-color:rgba(217,119,6,.3);}
.badge-Silver{background:rgba(148,163,184,.15);color:#475569;border-color:rgba(148,163,184,.3);}
.badge-Bronze{background:rgba(146,64,14,.15);color:#92400E;border-color:rgba(146,64,14,.3);}
.nc-summary-bar{display:flex;gap:6px;flex-wrap:wrap;}
.nc-cam-feed{width:100%;max-height:220px;background:#000;border-radius:12px;}
#camera-feed-front,#camera-feed-back{width:100%;max-height:260px;background:#000;border-radius:14px;}
/* face step */
#face-cam-feed{width:100%;max-height:340px;object-fit:cover;background:#000;border-radius:14px;}
/* photo preview with remove button */
.photo-preview-wrapper{position:relative;display:inline-block;margin-top:10px;}
.photo-preview-wrapper img{max-width:100%;max-height:220px;border-radius:14px;border:3px solid #2563eb;}
.remove-photo-btn{position:absolute;top:-10px;right:-10px;background:#EF4444;color:white;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid white;font-size:14px;font-weight:bold;transition:all .2s;box-shadow:0 2px 6px rgba(0,0,0,.2);}
.remove-photo-btn:hover{background:#DC2626;transform:scale(1.05);}
</style>
</head>
<body class="bg-[#F8FAFC] text-[#0F172A] min-h-screen">
<div class="max-w-4xl mx-auto px-4 py-8 md:py-12">
<div class="glass rounded-[32px] shadow-xl overflow-hidden p-6 md:p-10">

<!-- Step indicator — 5 steps (face photo is step 4, NC certs is step 5) -->
<div class="relative mb-10 px-2">
    <div class="absolute top-6 left-0 w-full h-[2px] bg-slate-200 z-0"></div>
    <div class="flex justify-between items-center relative z-10">
        <?php
        $display_step = match($step) { 1=>1, 2=>2, 3=>3, 35=>4, 4=>5, default=>1 };
        $step_defs = [[1,'person','Info'],[2,'badge','ID Type'],[3,'document_scanner','Scan ID'],[4,'face','Face'],[5,'verified','NC Certs']];
        foreach ($step_defs as [$s, $icon, $label]):
            if ($display_step > $s)       $cls = 'step-dot-done';
            elseif ($display_step === $s)  $cls = 'step-dot-active';
            else                           $cls = 'step-dot-inactive';
            $text_cls = $display_step >= $s ? 'text-blue-600' : 'text-slate-400';
        ?>
        <div class="flex flex-col items-center gap-2">
            <div class="<?=$cls?>"><span class="material-symbols-outlined"><?=$e($icon)?></span></div>
            <span class="text-[9px] font-bold tracking-[0.15em] uppercase <?=$text_cls?>"><?=$e($label)?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Error -->
<?php if($error): ?><div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl text-red-600 text-sm flex items-start gap-3"><span class="material-symbols-outlined text-lg mt-0.5">error</span><?=$e($error)?></div><?php endif; ?>

<?php if($step===1): ?>
<div class="space-y-8">
<header><h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-slate-900 mb-2">Personal Information</h1><p class="text-sm text-slate-500 max-w-xl">Provide your legal details to complete the verification process.</p></header>
<form method="POST" class="space-y-6">
<input type="hidden" name="csrf_token" value="<?=$e($csrf)?>">
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="field-group"><label class="field-label" for="first_name">First Name <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">person</span><input class="field-input-icon" id="first_name" name="first_name" placeholder="e.g. Juan" type="text" maxlength="100" required/></div></div>
    <div class="field-group"><label class="field-label" for="middle_name">Middle Name</label><input class="field-input" id="middle_name" name="middle_name" placeholder="e.g. Santos" type="text" maxlength="100"/></div>
    <div class="field-group"><label class="field-label" for="last_name">Last Name <span class="text-red-400">*</span></label><input class="field-input" id="last_name" name="last_name" placeholder="e.g. Dela Cruz" type="text" maxlength="100" required/></div>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <div class="field-group"><label class="field-label" for="age">Age <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">cake</span><input class="field-input-icon" id="age" name="age" placeholder="e.g. 25" type="number" min="18" max="120" required/></div></div>
    <div class="field-group"><label class="field-label">Nationality <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">public</span><select class="field-select" name="nationality" required><option disabled selected value="">Select…</option><option>Filipino</option><option>Others</option></select><span class="material-symbols-outlined field-chevron">expand_more</span></div></div>
    <div class="field-group"><label class="field-label">Civil Status <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">family_restroom</span><select class="field-select" name="civil_status" required><option disabled selected value="">Select…</option><option>Single</option><option>Married</option><option>Divorced</option><option>Widowed</option><option>Separated</option></select><span class="material-symbols-outlined field-chevron">expand_more</span></div></div>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="field-group"><label class="field-label" for="date_of_birth">Date of Birth <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">calendar_today</span><input class="field-input-icon" id="date_of_birth" name="date_of_birth" type="date" required/></div></div>
    <div class="field-group"><label class="field-label" for="place_of_birth">Place of Birth <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">location_on</span><input class="field-input-icon" id="place_of_birth" name="place_of_birth" type="text" placeholder="e.g. Davao City" maxlength="150" required/></div></div>
</div>
<div class="field-group"><label class="field-label">Sex <span class="text-red-400">*</span></label><div class="flex gap-3"><?php foreach(['Male','Female'] as $sx): ?><label class="flex-1 cursor-pointer group"><input type="radio" name="sex" value="<?=$e($sx)?>" class="sex-radio sr-only" required><div class="flex items-center justify-center gap-2 py-3 rounded-xl border border-slate-200 text-sm font-semibold text-slate-600 transition-all duration-200 group-hover:border-blue-300"><span class="material-symbols-outlined text-lg"><?=$sx==='Male'?'male':'female'?></span><?=$e($sx)?></div></label><?php endforeach; ?></div></div>
<div class="section-card"><div class="section-card-header"><div class="section-icon"><span class="material-symbols-outlined text-lg">home</span></div><h3 class="text-sm font-bold text-slate-800">Permanent Address</h3></div><p class="text-[11px] text-slate-400 mb-3 -mt-2">Region: <strong>Surigao del Sur</strong></p>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="field-group"><label class="field-label">Municipality <span class="text-red-400">*</span></label><div class="relative"><select class="field-select-plain" name="permanent_municipality" id="permanent_municipality" onchange="updateBarangays(this.value,'permanent')" required><option value="">Select municipality…</option><optgroup label="DISTRICT 1"><?php foreach($district1 as $m): if(in_array($m,$municipalities)): ?><option value="<?=$e($m)?>"><?=$e($m)?></option><?php endif;endforeach;?></optgroup><optgroup label="DISTRICT 2"><?php foreach($district2 as $m): if(in_array($m,$municipalities)): ?><option value="<?=$e($m)?>"><?=$e($m)?></option><?php endif;endforeach;?></optgroup></select><span class="material-symbols-outlined field-chevron">expand_more</span></div></div>
    <div class="field-group"><label class="field-label">Barangay <span class="text-red-400">*</span></label><div class="relative"><select class="field-select-plain" name="permanent_barangay" id="permanent_barangay" required><option value="">Select barangay…</option></select><span class="material-symbols-outlined field-chevron">expand_more</span></div></div>
    <div class="field-group"><label class="field-label">Street</label><input class="field-input" name="permanent_street" type="text" placeholder="Street / Purok" maxlength="200"/></div>
    <div class="field-group"><label class="field-label">House / Unit Details</label><input class="field-input" name="permanent_house" type="text" placeholder="House No., Lot" maxlength="200"/></div>
</div></div>
<div class="section-card"><div class="section-card-header"><div class="section-icon"><span class="material-symbols-outlined text-lg">pin_drop</span></div><h3 class="text-sm font-bold text-slate-800">Current Address</h3></div>
<label class="flex items-center gap-2 mb-4 cursor-pointer"><input type="checkbox" name="same_as_permanent" id="sameCheck" class="w-4 h-4 text-blue-600 rounded" onchange="toggleCurrentAddress(this.checked)"><span class="text-sm text-slate-600 font-medium">Same as Permanent Address</span></label>
<div id="currentAddressFields" class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="field-group"><label class="field-label">Municipality <span class="text-red-400">*</span></label><div class="relative"><select class="field-select-plain" name="current_municipality" id="current_municipality" onchange="updateBarangays(this.value,'current')"><option value="">Select municipality…</option><optgroup label="DISTRICT 1"><?php foreach($district1 as $m): if(in_array($m,$municipalities)): ?><option value="<?=$e($m)?>"><?=$e($m)?></option><?php endif;endforeach;?></optgroup><optgroup label="DISTRICT 2"><?php foreach($district2 as $m): if(in_array($m,$municipalities)): ?><option value="<?=$e($m)?>"><?=$e($m)?></option><?php endif;endforeach;?></optgroup></select><span class="material-symbols-outlined field-chevron">expand_more</span></div></div>
    <div class="field-group"><label class="field-label">Barangay <span class="text-red-400">*</span></label><div class="relative"><select class="field-select-plain" name="current_barangay" id="current_barangay"><option value="">Select barangay…</option></select><span class="material-symbols-outlined field-chevron">expand_more</span></div></div>
    <div class="field-group"><label class="field-label">Street</label><input class="field-input" name="current_street" type="text" placeholder="Street / Purok" maxlength="200"/></div>
    <div class="field-group"><label class="field-label">House / Unit Details</label><input class="field-input" name="current_house" type="text" placeholder="House No., Lot" maxlength="200"/></div>
</div></div>
<div class="field-group"><label class="field-label" for="zip_code">ZIP Code <span class="text-red-400">*</span></label><input class="field-input" id="zip_code" name="zip_code" type="text" placeholder="e.g. 8300" pattern="[0-9]{4,6}" maxlength="6" required/></div>
<button type="submit" name="step1_submit" class="group relative w-full overflow-hidden rounded-xl p-[1px] transition-all hover:scale-[1.01] active:scale-[0.98] active-glow"><div class="absolute inset-0 bg-gradient-to-r from-blue-700 via-blue-500 to-indigo-700"></div><div class="relative flex items-center justify-center gap-3 px-8 py-4 rounded-[11px] bg-blue-600/95 hover:bg-transparent transition-all text-white"><span class="font-bold tracking-[0.05em] text-sm uppercase">Next: ID Type</span><span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">arrow_forward</span></div></button>
</form></div>
<?php endif; ?>

<?php if($step===2): ?>
<div class="space-y-8">
<header><h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-2">ID Information</h1><p class="text-sm text-slate-500">Select your government-issued ID. Your ID number is encrypted end-to-end.</p></header>
<form method="POST" class="space-y-6"><input type="hidden" name="csrf_token" value="<?=$e($csrf)?>">
<div class="field-group"><label class="field-label">ID Type <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">badge</span><select class="field-select" name="id_type" required><option disabled selected value="">Select ID type…</option><?php foreach(VALID_ID_TYPES as $t): ?><option value="<?=$e($t)?>"><?=$e($t)?></option><?php endforeach;?></select><span class="material-symbols-outlined field-chevron">expand_more</span></div></div>
<div class="field-group"><label class="field-label" for="id_number">ID Number <span class="text-red-400">*</span></label><div class="relative"><span class="material-symbols-outlined field-icon">tag</span><input class="field-input-icon" id="id_number" name="id_number" type="text" placeholder="Enter your ID number" maxlength="100" required autocomplete="off"/></div><p class="text-[10px] text-slate-400 mt-1 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">lock</span>Encrypted with AES-256-GCM</p></div>
<button type="submit" name="step2_submit" class="group relative w-full overflow-hidden rounded-xl p-[1px] transition-all hover:scale-[1.01] active:scale-[0.98] active-glow"><div class="absolute inset-0 bg-gradient-to-r from-blue-700 via-blue-500 to-indigo-700"></div><div class="relative flex items-center justify-center gap-3 px-8 py-4 rounded-[11px] bg-blue-600/95 hover:bg-transparent transition-all text-white"><span class="font-bold tracking-[0.05em] text-sm uppercase">Next: Upload ID Photos</span><span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">arrow_forward</span></div></button>
</form></div>
<?php endif; ?>

<?php if($step===3): ?>
<div class="space-y-8">
<header><h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-2">Scan Your ID</h1><p class="text-sm text-slate-500 max-w-xl">Upload clear photos of both sides. JPEG, PNG, WebP, HEIC — max 8 MB.</p></header>
<form method="POST" enctype="multipart/form-data" class="space-y-6"><input type="hidden" name="csrf_token" value="<?=$e($csrf)?>">
<div class="section-card"><div class="section-card-header"><div class="section-icon"><span class="material-symbols-outlined text-lg">front_hand</span></div><h3 class="text-sm font-bold text-slate-800">Front of ID</h3></div>
<div class="field-group mb-4"><label class="field-label">Upload from device</label><input type="file" name="id_front" accept="image/jpeg,image/png,image/webp,image/heic" class="field-input text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100" id="idFileInput" onchange="previewImage(this,'idFrontPreview')" required></div>
<button type="button" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-blue-600 font-semibold text-xs hover:bg-blue-50 transition-all flex items-center justify-center gap-2" onclick="openCamera('front')" id="openCameraBtnFront"><span class="material-symbols-outlined text-lg">camera_alt</span>Use Camera</button>
<div id="cameraSectionFront" style="display:none;" class="mt-3"><video id="camera-feed-front" autoplay playsinline class="w-full rounded-xl"></video><div class="flex gap-3 mt-3"><button type="button" class="flex-1 px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold text-xs" onclick="capturePhoto('front')">Capture</button><button type="button" class="flex-1 px-4 py-2 rounded-xl bg-slate-100 text-slate-600 font-semibold text-xs" onclick="closeCamera('front')">Cancel</button></div><canvas id="photoCanvasFront" style="display:none;"></canvas></div>
<div id="idFrontPreview" class="mt-4 text-center"></div></div>
<div class="section-card"><div class="section-card-header"><div class="section-icon"><span class="material-symbols-outlined text-lg">back_hand</span></div><h3 class="text-sm font-bold text-slate-800">Back of ID</h3></div>
<div class="field-group mb-4"><label class="field-label">Upload from device</label><input type="file" name="id_back" accept="image/jpeg,image/png,image/webp,image/heic" class="field-input text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100" id="idFileInputBack" onchange="previewImage(this,'idBackPreview')" required></div>
<button type="button" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-blue-600 font-semibold text-xs hover:bg-blue-50 transition-all flex items-center justify-center gap-2" onclick="openCamera('back')" id="openCameraBtnBack"><span class="material-symbols-outlined text-lg">camera_alt</span>Use Camera</button>
<div id="cameraSectionBack" style="display:none;" class="mt-3"><video id="camera-feed-back" autoplay playsinline class="w-full rounded-xl"></video><div class="flex gap-3 mt-3"><button type="button" class="flex-1 px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold text-xs" onclick="capturePhoto('back')">Capture</button><button type="button" class="flex-1 px-4 py-2 rounded-xl bg-slate-100 text-slate-600 font-semibold text-xs" onclick="closeCamera('back')">Cancel</button></div><canvas id="photoCanvasBack" style="display:none;"></canvas></div>
<div id="idBackPreview" class="mt-4 text-center"></div></div>
<button type="submit" name="upload_id_submit" class="group relative w-full overflow-hidden rounded-xl p-[1px] transition-all hover:scale-[1.01] active:scale-[0.98] active-glow"><div class="absolute inset-0 bg-gradient-to-r from-blue-700 via-blue-500 to-indigo-700"></div><div class="relative flex items-center justify-center gap-3 px-8 py-4 rounded-[11px] bg-blue-600/95 hover:bg-transparent transition-all text-white"><span class="font-bold tracking-[0.05em] text-sm uppercase">Upload ID &amp; Continue</span><span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">arrow_forward</span></div></button>
</form></div>
<?php endif; ?>

<?php /* ============================================================
       STEP 35 — Face Photo (NEW in v4)
       File stored in private_uploads/face/ same as private_uploads/ids/
       ============================================================ */ ?>
<?php if($step===35): ?>
<div class="space-y-6">
<header>
    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-2">Face Verification</h1>
    <p class="text-sm text-slate-500 max-w-xl">Take a clear selfie or upload a photo of your face. Make sure you are well-lit and your face is unobstructed. JPEG, PNG, WebP, HEIC — max 8 MB.</p>
</header>

<form method="POST" enctype="multipart/form-data" class="space-y-6">
<input type="hidden" name="csrf_token" value="<?=$e($csrf)?>">

<div class="section-card">
    <div class="section-card-header">
        <div class="section-icon"><span class="material-symbols-outlined text-lg">face</span></div>
        <h3 class="text-sm font-bold text-slate-800">Your Face Photo</h3>
    </div>

    <!-- Tip chips -->
    <div class="flex flex-wrap gap-2 mb-5">
        <?php foreach(['Good lighting','Face centered & visible','No sunglasses','No hat or mask'] as $tip): ?>
        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-semibold bg-blue-50 text-blue-600 border border-blue-100">
            <span class="material-symbols-outlined text-[12px]" style="font-variation-settings:'FILL' 1,'wght' 600,'GRAD' 0,'opsz' 24;">check_circle</span><?=$e($tip)?>
        </span>
        <?php endforeach; ?>
    </div>

    <!-- Upload from device -->
    <div class="field-group mb-4">
        <label class="field-label">Upload from device</label>
        <input type="file" name="face_photo" id="faceFileInput"
               accept="image/jpeg,image/png,image/webp,image/heic"
               class="field-input text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100"
               onchange="previewFaceFile(this)" required>
    </div>

    <!-- Camera (front-facing) -->
    <button type="button"
            id="openFaceCamBtn"
            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-blue-600 font-semibold text-xs hover:bg-blue-50 transition-all flex items-center justify-center gap-2"
            onclick="openFaceCam()">
        <span class="material-symbols-outlined text-lg">camera_alt</span>Use Front Camera (Selfie)
    </button>

    <div id="faceCamSection" style="display:none;" class="mt-3">
        <!-- Oval overlay guide -->
        <div class="relative rounded-xl overflow-hidden" style="background:#000;">
            <video id="face-cam-feed" autoplay playsinline
                   style="width:100%;max-height:340px;object-fit:cover;display:block;border-radius:14px;"></video>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;">
                <div style="width:155px;height:205px;border-radius:50%;border:3px dashed rgba(255,255,255,.8);box-shadow:0 0 0 9999px rgba(0,0,0,.42);"></div>
            </div>
            <div style="position:absolute;bottom:10px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.55);color:#fff;font-size:10px;font-weight:700;padding:4px 14px;border-radius:99px;white-space:nowrap;letter-spacing:.05em;">
                Center your face in the oval
            </div>
        </div>
        <div class="flex gap-3 mt-3">
            <button type="button"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-blue-600 text-white font-semibold text-xs flex items-center justify-center gap-1.5"
                    onclick="captureFace()">
                <span class="material-symbols-outlined text-base">camera</span>Capture
            </button>
            <button type="button"
                    class="flex-1 px-4 py-2.5 rounded-xl bg-slate-100 text-slate-600 font-semibold text-xs"
                    onclick="closeFaceCam()">Cancel</button>
        </div>
        <canvas id="faceCamCanvas" style="display:none;"></canvas>
    </div>

    <!-- Preview with remove button -->
    <div id="facePreview" class="mt-4 text-center"></div>
</div>

<button type="submit" name="upload_face_submit"
        class="group relative w-full overflow-hidden rounded-xl p-[1px] transition-all hover:scale-[1.01] active:scale-[0.98] active-glow">
    <div class="absolute inset-0 bg-gradient-to-r from-blue-700 via-blue-500 to-indigo-700"></div>
    <div class="relative flex items-center justify-center gap-3 px-8 py-4 rounded-[11px] bg-blue-600/95 hover:bg-transparent transition-all text-white">
        <span class="font-bold tracking-[0.05em] text-sm uppercase">Submit Face &amp; Continue</span>
        <span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">arrow_forward</span>
    </div>
</button>
</form>
</div>
<?php endif; ?>

<?php if($step===4): ?>
<div class="space-y-6">
<header>
    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mb-1">NC Certificates <span class="text-slate-400 text-lg font-medium">(Optional)</span></h1>
    <p class="text-sm text-slate-500 max-w-xl">You registered <strong><?=count($worker_skills)?> skill<?=count($worker_skills)!==1?'s':''?></strong>. Submit NC certificates for any or all of them. Skills with a complete certificate will earn a badge upgrade. The rest stay <em>Unverified</em>.</p>
</header>

<div class="flex flex-wrap gap-2">
    <?php foreach($worker_skills as $sk): ?>
    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-100">
        <?=SUB_EMOJI[$sk['sub_category']]??'🔹'?> <?=$e($sk['sub_category'])?>
    </span>
    <?php endforeach; ?>
</div>

<?php if(empty($worker_skills)): ?>
<div class="section-card text-center py-10"><span class="material-symbols-outlined text-4xl text-slate-300 mb-3 block">work_off</span><p class="font-semibold text-slate-600 mb-1">No skills registered yet</p><p class="text-xs text-slate-400">Complete your skill profile before submitting NC certificates.</p></div>
<?php else: ?>
<form method="POST" enctype="multipart/form-data" id="ncForm" class="space-y-4">
<input type="hidden" name="csrf_token" value="<?=$e($csrf)?>">

<?php foreach($worker_skills as $si => $skill):
    $sub   = $skill['sub_category'];
    $main  = $skill['main_category'];
    $emoji = SUB_EMOJI[$sub] ?? '🔹';
?>
<div class="skill-nc-panel" id="panel-<?=$si?>">
    <div class="skill-nc-header" onclick="togglePanel(<?=$si?>)">
        <span class="skill-emoji"><?=$emoji?></span>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-slate-800 truncate"><?=$e($sub)?></p>
            <p class="text-[10px] text-slate-400 font-medium"><?=$e($main)?></p>
        </div>
        <span class="badge-preview-chip mr-2" id="badge-chip-<?=$si?>" style="display:none;"></span>
        <span class="nc-status-pill optional" id="pill-<?=$si?>">Optional</span>
        <span class="material-symbols-outlined text-slate-400 ml-2 text-lg transition-transform duration-200" id="chevron-<?=$si?>">expand_more</span>
    </div>

    <div class="skill-nc-body" id="body-<?=$si?>">
        <div class="space-y-4 pb-2">
            <label class="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" class="w-4 h-4 rounded text-slate-400" id="skip-<?=$si?>" onchange="toggleSkip(<?=$si?>,this.checked)">
                <span class="text-xs text-slate-500 font-medium">I don't have an NC certificate for <strong><?=$e($sub)?></strong></span>
            </label>

            <div id="nc-fields-<?=$si?>">
                <div class="field-group mb-4">
                    <label class="field-label">NC Level <span class="text-red-400">*</span></label>
                    <div class="relative">
                        <span class="material-symbols-outlined field-icon">school</span>
                        <select class="field-select" name="nc_level[<?=$si?>]" id="nc-level-<?=$si?>" onchange="onLevelChange(<?=$si?>,this.value)">
                            <option disabled selected value="">Select NC Level…</option>
                            <option value="NC I">NC I  →  🟤 Bronze badge</option>
                            <option value="NC II">NC II  →  ⚪ Silver badge</option>
                            <option value="NC III">NC III  →  🟡 Gold badge</option>
                        </select>
                        <span class="material-symbols-outlined field-chevron">expand_more</span>
                    </div>
                </div>

                <div class="field-group mb-4">
                    <label class="field-label" for="nc-num-<?=$si?>">NC Certificate Number <span class="text-red-400">*</span></label>
                    <div class="relative">
                        <span class="material-symbols-outlined field-icon">tag</span>
                        <input class="field-input-icon" id="nc-num-<?=$si?>" name="nc_certificate_number[<?=$si?>]" type="text"
                               placeholder="e.g. TESDA-2024-<?=strtoupper(substr($sub,0,3))?>-00123"
                               maxlength="100" autocomplete="off" oninput="checkFilled(<?=$si?>)"/>
                    </div>
                    <p class="text-[10px] text-slate-400 mt-1 flex items-center gap-1"><span class="material-symbols-outlined text-[13px]">lock</span>Encrypted with AES-256-GCM</p>
                </div>

                <div class="field-group mb-3">
                    <label class="field-label">Certificate Photo <span class="text-red-400">*</span></label>
                    <input type="file" name="nc_certificate[<?=$si?>]" id="nc-file-<?=$si?>"
                           accept="image/jpeg,image/png,image/webp,image/heic"
                           class="field-input text-slate-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100"
                           onchange="previewNCImg(this,<?=$si?>); checkFilled(<?=$si?>)">
                </div>

                <button type="button" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 text-blue-600 font-semibold text-xs hover:bg-blue-50 transition-all flex items-center justify-center gap-2"
                        onclick="openNCCam(<?=$si?>)" id="nc-cam-btn-<?=$si?>">
                    <span class="material-symbols-outlined text-lg">camera_alt</span>Use Camera
                </button>
                <div id="nc-cam-sec-<?=$si?>" style="display:none;" class="mt-3">
                    <video class="nc-cam-feed" id="nc-cam-feed-<?=$si?>" autoplay playsinline></video>
                    <div class="flex gap-3 mt-3">
                        <button type="button" class="flex-1 px-4 py-2 rounded-xl bg-blue-600 text-white font-semibold text-xs" onclick="captureNCCam(<?=$si?>)">Capture</button>
                        <button type="button" class="flex-1 px-4 py-2 rounded-xl bg-slate-100 text-slate-600 font-semibold text-xs" onclick="closeNCCam(<?=$si?>)">Cancel</button>
                    </div>
                    <canvas id="nc-cam-canvas-<?=$si?>" style="display:none;"></canvas>
                </div>
                <div id="nc-preview-<?=$si?>" class="text-center mt-3"></div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="section-card bg-slate-50/50">
    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3">Submission summary</p>
    <div class="nc-summary-bar" id="nc-summary">
        <?php foreach($worker_skills as $si => $sk): ?>
        <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border transition-all duration-200"
             id="sum-chip-<?=$si?>" style="background:rgba(100,116,139,.08);color:#64748b;border-color:rgba(100,116,139,.2);">
            <?=SUB_EMOJI[$sk['sub_category']]??'🔹'?> <?=$e($sk['sub_category'])?>
            <span class="material-symbols-outlined text-[13px] ml-0.5" id="sum-icon-<?=$si?>">radio_button_unchecked</span>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="text-[10px] text-slate-400 mt-3">Skills marked ✓ will be submitted for badge verification.</p>
</div>

<button type="submit" name="submit_nc_step" class="group relative w-full overflow-hidden rounded-xl p-[1px] transition-all hover:scale-[1.01] active:scale-[0.98] active-glow">
    <div class="absolute inset-0 bg-gradient-to-r from-blue-700 via-blue-500 to-indigo-700"></div>
    <div class="relative flex items-center justify-center gap-3 px-8 py-4 rounded-[11px] bg-blue-600/95 hover:bg-transparent transition-all text-white">
        <span class="font-bold tracking-[0.05em] text-sm uppercase" id="submit-lbl">Submit Verification</span>
        <span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">verified</span>
    </div>
</button>
<p class="text-center text-[11px] text-slate-400">You can submit certificates for remaining skills anytime from your dashboard.</p>
</form>
<?php endif; ?>
</div>
<?php endif; ?>

</div>
<div class="mt-8 flex flex-col items-center gap-3">
    <div class="flex items-center gap-2 text-slate-400 text-xs font-medium"><span class="material-symbols-outlined text-base">lock</span><p>AES-256-GCM Encryption · CSRF Protection · Rate Limited · MIME Validated</p></div>
    <p class="text-slate-400 text-[9px] tracking-[0.2em] uppercase font-bold">© <?=date('Y')?> Abilisto Verification</p>
</div>
</div>

<div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50" id="pendingModal" style="display:none!important;">
<div class="bg-white rounded-2xl max-w-md mx-4 p-6 shadow-2xl"><div class="text-center"><div class="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-3xl text-yellow-600">hourglass_empty</span></div><h3 class="text-xl font-bold mb-2">Verification in Progress</h3><p class="text-sm text-slate-500 mb-6">Your information is being reviewed. This usually takes 1–2 business days.</p><button type="button" class="px-6 py-3 rounded-xl bg-blue-600 text-white font-semibold text-sm w-full" onclick="document.getElementById('pendingModal').style.display='none'">Close</button></div></div>
</div>

<?php if($success): ?>
<div class="success-overlay"><div class="text-center"><div class="success-check"><span class="material-symbols-outlined">check</span></div><h2 class="text-2xl font-bold text-slate-900 mb-2">Verification Submitted!</h2><p class="text-slate-600 mb-6">Your information is now being reviewed. Redirecting to dashboard...</p><div class="w-48 h-1 bg-slate-200 rounded-full mx-auto overflow-hidden"><div class="h-full bg-gradient-to-r from-blue-600 to-indigo-600 progress-bar"></div></div></div></div>
<script>setTimeout(()=>window.location.href='dashboard.php',3000);</script>
<?php endif; ?>

<script>
const barangays = <?=json_encode($barangays, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
function updateBarangays(municipality,type){const sel=document.getElementById(type+'_barangay');if(!sel)return;sel.innerHTML='<option value="">Select barangay…</option>';if(municipality&&barangays[municipality]){[...barangays[municipality]].sort().forEach(b=>{const o=document.createElement('option');o.value=b;o.textContent=b;sel.appendChild(o);});sel.disabled=false;}else sel.disabled=true;}
function toggleCurrentAddress(checked){const f=document.getElementById('currentAddressFields');if(!f)return;f.style.opacity=checked?'0.4':'1';f.querySelectorAll('input,select').forEach(el=>{el.disabled=checked;if(!checked&&el.tagName==='SELECT'&&(el.id==='current_municipality'||el.id==='current_barangay'))el.setAttribute('required','');else if(checked)el.removeAttribute('required');});}
function previewImage(input,previewId,type='id'){const p=document.getElementById(previewId);if(!p)return;p.innerHTML='';if(input.files&&input.files[0]){const r=new FileReader();r.onload=e=>{const wrapper=document.createElement('div');wrapper.className='photo-preview-wrapper';const img=document.createElement('img');img.src=e.target.result;img.className='camera-preview';const removeBtn=document.createElement('div');removeBtn.className='remove-photo-btn';removeBtn.innerHTML='✕';removeBtn.onclick=function(e){e.stopPropagation();removePhoto(input,previewId);};wrapper.appendChild(img);wrapper.appendChild(removeBtn);p.appendChild(wrapper);};r.readAsDataURL(input.files[0]);}}
function removePhoto(inputElement, previewId){inputElement.value='';document.getElementById(previewId).innerHTML='';}

let camStream=null;
function openCamera(type){const cap=s=>s.charAt(0).toUpperCase()+s.slice(1);document.getElementById('cameraSection'+cap(type)).style.display='block';document.getElementById('openCameraBtn'+cap(type)).style.display='none';navigator.mediaDevices?.getUserMedia({video:{facingMode:'environment'}}).then(s=>{camStream=s;const v=document.getElementById('camera-feed-'+type);v.srcObject=s;v.play();}).catch(err=>{alert('Camera unavailable: '+err.message);closeCamera(type);});}
function closeCamera(type){if(camStream){camStream.getTracks().forEach(t=>t.stop());camStream=null;}const cap=s=>s.charAt(0).toUpperCase()+s.slice(1);document.getElementById('cameraSection'+cap(type)).style.display='none';document.getElementById('openCameraBtn'+cap(type)).style.display='block';const v=document.getElementById('camera-feed-'+type);if(v)v.srcObject=null;}
function capturePhoto(type){const cap=s=>s.charAt(0).toUpperCase()+s.slice(1);const video=document.getElementById('camera-feed-'+type);const canvas=document.getElementById('photoCanvas'+cap(type));const ctx=canvas.getContext('2d');canvas.width=video.videoWidth;canvas.height=video.videoHeight;ctx.drawImage(video,0,0);canvas.toBlob(blob=>{const file=new File([blob],'capture_'+type+'.jpg',{type:'image/jpeg'});const dt=new DataTransfer();dt.items.add(file);const inp=type==='front'?document.getElementById('idFileInput'):document.getElementById('idFileInputBack');inp.files=dt.files;previewImage({files:[file]},type==='front'?'idFrontPreview':'idBackPreview');closeCamera(type);},'image/jpeg');}

// ---- Face camera (front-facing) ----
let faceCamStream = null;
function openFaceCam() {
    document.getElementById('faceCamSection').style.display = 'block';
    document.getElementById('openFaceCamBtn').style.display = 'none';
    navigator.mediaDevices?.getUserMedia({video: {facingMode: 'user'}})
        .then(s => { faceCamStream = s; const v = document.getElementById('face-cam-feed'); v.srcObject = s; v.play(); })
        .catch(err => { alert('Camera unavailable: ' + err.message); closeFaceCam(); });
}
function closeFaceCam() {
    if (faceCamStream) { faceCamStream.getTracks().forEach(t => t.stop()); faceCamStream = null; }
    document.getElementById('faceCamSection').style.display = 'none';
    document.getElementById('openFaceCamBtn').style.display = 'block';
    const v = document.getElementById('face-cam-feed');
    if (v) v.srcObject = null;
}
function captureFace() {
    const video = document.getElementById('face-cam-feed');
    const canvas = document.getElementById('faceCamCanvas');
    const ctx = canvas.getContext('2d');
    canvas.width = video.videoWidth; canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0);
    canvas.toBlob(blob => {
        const file = new File([blob], 'face_selfie.jpg', {type: 'image/jpeg'});
        const dt = new DataTransfer(); dt.items.add(file);
        document.getElementById('faceFileInput').files = dt.files;
        previewFaceFile({files: [file]});
        closeFaceCam();
    }, 'image/jpeg');
}
function previewFaceFile(input) {
    const p = document.getElementById('facePreview');
    if (!p || !input.files || !input.files[0]) return;
    const r = new FileReader();
    r.onload = ev => {
        p.innerHTML = '';
        const wrapper = document.createElement('div');
        wrapper.className = 'photo-preview-wrapper';
        const img = document.createElement('img');
        img.src = ev.target.result;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '260px';
        img.style.borderRadius = '14px';
        img.style.border = '3px solid #2563eb';
        const removeBtn = document.createElement('div');
        removeBtn.className = 'remove-photo-btn';
        removeBtn.innerHTML = '✕';
        removeBtn.onclick = function(e) {
            e.stopPropagation();
            document.getElementById('faceFileInput').value = '';
            p.innerHTML = '';
        };
        wrapper.appendChild(img);
        wrapper.appendChild(removeBtn);
        p.appendChild(wrapper);
    };
    r.readAsDataURL(input.files[0]);
}
// ---- End face camera ----

const BADGE_MAP={'NC I':{label:'NC I · Bronze',cls:'badge-Bronze'},'NC II':{label:'NC II · Silver',cls:'badge-Silver'},'NC III':{label:'NC III · Gold',cls:'badge-Gold'}};
const ncStreams={};
const PANEL_COUNT=<?=count($worker_skills)?>;
function togglePanel(i){const body=document.getElementById('body-'+i),ch=document.getElementById('chevron-'+i);const open=body.classList.contains('open');body.classList.toggle('open',!open);ch.style.transform=open?'':'rotate(180deg)';}
function toggleSkip(i,skipped){const fields=document.getElementById('nc-fields-'+i);const panel=document.getElementById('panel-'+i);const pill=document.getElementById('pill-'+i);const chip=document.getElementById('badge-chip-'+i);if(skipped){fields.style.opacity='0.3';fields.querySelectorAll('input,select').forEach(el=>el.disabled=true);panel.classList.remove('has-nc');panel.classList.add('skip-nc');pill.className='nc-status-pill skipped';pill.textContent='Skipped';if(chip)chip.style.display='none';setSummaryChip(i,'skip');}else{fields.style.opacity='1';fields.querySelectorAll('input,select').forEach(el=>el.disabled=false);panel.classList.remove('skip-nc');checkFilled(i);}updateSubmitLabel();}
function onLevelChange(i,val){const chip=document.getElementById('badge-chip-'+i);if(chip&&BADGE_MAP[val]){chip.className='badge-preview-chip '+BADGE_MAP[val].cls;chip.textContent=BADGE_MAP[val].label;chip.style.display='inline-flex';}else if(chip){chip.style.display='none';}checkFilled(i);}
function checkFilled(i){const skip=document.getElementById('skip-'+i)?.checked;if(skip)return;const level=document.getElementById('nc-level-'+i)?.value;const num=(document.getElementById('nc-num-'+i)?.value||'').trim();const file=(document.getElementById('nc-file-'+i)?.files?.length||0)>0;const panel=document.getElementById('panel-'+i);const pill=document.getElementById('pill-'+i);const filled=!!(level&&num&&file);const partial=!!(level||num||file);panel.classList.toggle('has-nc',filled);panel.classList.remove('skip-nc');if(filled){pill.className='nc-status-pill filled';pill.textContent='Ready ✓';setSummaryChip(i,'done');}else if(partial){pill.className='nc-status-pill inprogress';pill.textContent='In progress…';setSummaryChip(i,'partial');}else{pill.className='nc-status-pill optional';pill.textContent='Optional';setSummaryChip(i,'empty');}updateSubmitLabel();}
function setSummaryChip(i,state){const ch=document.getElementById('sum-chip-'+i),ic=document.getElementById('sum-icon-'+i);const styles={done:['rgba(34,197,94,.1)','#15803d','rgba(34,197,94,.25)','check_circle'],partial:['rgba(245,158,11,.1)','#b45309','rgba(245,158,11,.25)','pending'],empty:['rgba(100,116,139,.08)','#64748b','rgba(100,116,139,.2)','radio_button_unchecked'],skip:['rgba(100,116,139,.05)','#94a3b8','rgba(100,116,139,.12)','remove_circle_outline']};const[bg,col,br,icon]=styles[state]||styles.empty;if(ch){ch.style.background=bg;ch.style.color=col;ch.style.borderColor=br;}if(ic)ic.textContent=icon;}
function updateSubmitLabel(){let ready=0;for(let i=0;i<PANEL_COUNT;i++){const skip=document.getElementById('skip-'+i)?.checked;const level=document.getElementById('nc-level-'+i)?.value;const num=(document.getElementById('nc-num-'+i)?.value||'').trim();const file=(document.getElementById('nc-file-'+i)?.files?.length||0)>0;if(!skip&&level&&num&&file)ready++;}const lbl=document.getElementById('submit-lbl');if(lbl)lbl.textContent=ready>0?`Submit Verification (${ready} NC cert${ready>1?'s':''})`:'Submit Verification (no NC certs)';}
function previewNCImg(input,i){const p=document.getElementById('nc-preview-'+i);if(!p||!input.files[0])return;p.innerHTML='';const r=new FileReader();r.onload=e=>{const wrapper=document.createElement('div');wrapper.className='photo-preview-wrapper';const img=document.createElement('img');img.src=e.target.result;img.className='camera-preview';const removeBtn=document.createElement('div');removeBtn.className='remove-photo-btn';removeBtn.innerHTML='✕';removeBtn.onclick=function(ev){ev.stopPropagation();input.value='';p.innerHTML='';checkFilled(i);};wrapper.appendChild(img);wrapper.appendChild(removeBtn);p.appendChild(wrapper);};r.readAsDataURL(input.files[0]);}
function openNCCam(i){document.getElementById('nc-cam-sec-'+i).style.display='block';document.getElementById('nc-cam-btn-'+i).style.display='none';navigator.mediaDevices?.getUserMedia({video:{facingMode:'environment'}}).then(s=>{ncStreams[i]=s;const v=document.getElementById('nc-cam-feed-'+i);v.srcObject=s;v.play();}).catch(err=>{alert('Camera unavailable: '+err.message);closeNCCam(i);});}
function closeNCCam(i){if(ncStreams[i]){ncStreams[i].getTracks().forEach(t=>t.stop());delete ncStreams[i];}document.getElementById('nc-cam-sec-'+i).style.display='none';document.getElementById('nc-cam-btn-'+i).style.display='block';const v=document.getElementById('nc-cam-feed-'+i);if(v)v.srcObject=null;}
function captureNCCam(i){const video=document.getElementById('nc-cam-feed-'+i);const canvas=document.getElementById('nc-cam-canvas-'+i);const ctx=canvas.getContext('2d');canvas.width=video.videoWidth;canvas.height=video.videoHeight;ctx.drawImage(video,0,0);canvas.toBlob(blob=>{const file=new File([blob],`nc_skill_${i}.jpg`,{type:'image/jpeg'});const dt=new DataTransfer();dt.items.add(file);const inp=document.getElementById('nc-file-'+i);inp.files=dt.files;previewNCImg({files:[file]},i);checkFilled(i);closeNCCam(i);},'image/jpeg');}
document.addEventListener('DOMContentLoaded',function(){const sc=document.getElementById('sameCheck');if(sc)toggleCurrentAddress(sc.checked);const pb=document.getElementById('permanent_barangay');if(pb)pb.disabled=true;const cb=document.getElementById('current_barangay');if(cb)cb.disabled=true;const b0=document.getElementById('body-0');if(b0){b0.classList.add('open');const c0=document.getElementById('chevron-0');if(c0)c0.style.transform='rotate(180deg)';}});
<?php if($show_pending_modal): ?>document.addEventListener('DOMContentLoaded',()=>{document.getElementById('pendingModal').style.cssText='display:flex!important';});<?php endif; ?>
</script>
</body>
</html>