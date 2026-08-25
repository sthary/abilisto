<?php
// client/edit_profile_photo.php
// Dedicated page for adding/changing a profile picture, with an in-browser
// crop tool (pan + zoom inside a circular guide) so the exported image is
// already framed the way it'll display everywhere else in the app (a
// square, object-cover'd into a circle). Shared by both roles — reachable
// from client/profile.php and worker/profile_edit.php.
session_start();
include '../db_connect.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['client', 'worker'], true)) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id   = $_SESSION['user_id'];
$is_worker = $_SESSION['role'] === 'worker';
$back_link = $is_worker ? '../worker/profile_edit.php' : 'profile.php';

const PROFILE_PHOTO_MAX_BYTES = 10 * 1024 * 1024; // 10MB

// AJAX save handler — receives the already-cropped square JPEG as a Blob.
if (isset($_POST['action']) && $_POST['action'] === 'save_photo') {
    header('Content-Type: application/json');

    if (!isset($_FILES['photo'])) {
        echo json_encode(['success' => false, 'error' => 'No file received.']);
        exit;
    }
    if ($_FILES['photo']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['photo']['error'] === UPLOAD_ERR_FORM_SIZE) {
        echo json_encode(['success' => false, 'error' => 'That file is larger than this server currently accepts. Please try a smaller photo.']);
        exit;
    }
    if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Upload failed. Please try again.']);
        exit;
    }
    if ($_FILES['photo']['size'] > PROFILE_PHOTO_MAX_BYTES) {
        echo json_encode(['success' => false, 'error' => 'Photo must be 10MB or smaller.']);
        exit;
    }
    // Confirm it's actually a readable image, not just something renamed
    // to look like one.
    $image_info = @getimagesize($_FILES['photo']['tmp_name']);
    if ($image_info === false || !in_array($image_info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        echo json_encode(['success' => false, 'error' => 'That file doesn\'t look like a valid image.']);
        exit;
    }

    $fname = 'profile_' . $user_id . '_' . time() . '.jpg';
    $dest  = '../uploads/profiles/' . $fname;

    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
        // Clean up the old file so uploads/profiles/ doesn't accumulate
        // orphaned images every time someone changes their photo.
        $old_stmt = $conn->prepare("SELECT profile_pic FROM users WHERE id = ?");
        $old_stmt->execute([$user_id]);
        $old_file = $old_stmt->fetchColumn();
        if ($old_file && $old_file !== $fname && file_exists('../uploads/profiles/' . $old_file)) {
            @unlink('../uploads/profiles/' . $old_file);
        }

        $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?")->execute([$fname, $user_id]);
        echo json_encode(['success' => true, 'file' => $fname]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not save the file. Please try again.']);
    }
    exit;
}

$user_stmt = $conn->prepare("SELECT full_name, profile_pic FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();
$current_avatar = (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic']))
    ? '../uploads/profiles/' . $user['profile_pic']
    : null;
$initials = strtoupper(substr(implode('', array_map(fn($w) => substr($w, 0, 1), explode(' ', $user['full_name']))), 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Edit Profile Photo | Abilisto</title>
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
    <style>
        #cropStage {
            position: relative;
            width: 100%;
            max-width: 300px;
            aspect-ratio: 1 / 1;
            margin: 0 auto;
            border-radius: 50%;
            overflow: hidden;
            background: #e2e8f0;
            touch-action: none;
            cursor: grab;
            box-shadow: 0 0 0 4px rgba(20,106,245,0.15), 0 8px 24px rgba(0,0,0,0.12);
        }
        .dark #cropStage { background: #1e293b; }
        #cropStage.dragging { cursor: grabbing; }
        #cropImage {
            position: absolute;
            top: 0; left: 0;
            user-select: none;
            -webkit-user-drag: none;
            pointer-events: none;
        }
        #cropEmptyState {
            position: absolute; inset: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 6px;
            color: #94a3b8;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-slate-900 dark:text-slate-100 min-h-screen transition-colors duration-300 font-sans">

<?php include '../includes/navbar.php'; ?>

<main class="max-w-lg mx-auto px-4 py-6 md:py-10">

    <a href="<?php echo htmlspecialchars($back_link); ?>" class="inline-flex items-center gap-2 text-sm font-medium text-slate-500 dark:text-slate-400 hover:text-primary mb-6">
        <span class="material-symbols-outlined text-lg">arrow_back</span> Back to Profile
    </a>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-md border border-slate-100 dark:border-slate-700 p-6 md:p-8">
        <div class="flex items-center gap-3 mb-6">
            <div class="p-2 bg-primary/10 rounded-lg">
                <span class="material-symbols-outlined text-primary">add_a_photo</span>
            </div>
            <div>
                <h1 class="text-xl font-bold text-slate-900 dark:text-white">Edit Profile Photo</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Position and zoom until your face fits the circle</p>
            </div>
        </div>

        <div id="cropStage">
            <img id="cropImage" alt="">
            <div id="cropEmptyState">
                <?php if ($current_avatar): ?>
                    <img src="<?php echo htmlspecialchars($current_avatar); ?>" class="w-full h-full object-cover" alt="Current photo">
                <?php else: ?>
                    <span class="material-symbols-outlined text-5xl">person</span>
                    <span class="text-xs font-bold"><?php echo htmlspecialchars($initials); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div id="zoomRow" class="mt-5 flex items-center gap-3 hidden">
            <span class="material-symbols-outlined text-slate-400 text-lg">zoom_out</span>
            <input type="range" id="zoomSlider" min="100" max="300" value="100" class="flex-1 accent-primary">
            <span class="material-symbols-outlined text-slate-400 text-lg">zoom_in</span>
        </div>

        <div class="mt-6 flex justify-center">
            <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" class="hidden">
            <button type="button" id="chooseBtn" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-slate-100 dark:bg-slate-700 hover:bg-slate-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-semibold text-sm transition-all">
                <span class="material-symbols-outlined text-lg">photo_library</span>
                <?php echo $current_avatar ? 'Choose a different photo' : 'Choose a photo'; ?>
            </button>
        </div>

        <div class="mt-6 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 flex gap-3">
            <span class="material-symbols-outlined text-amber-500 shrink-0">face</span>
            <p class="text-xs text-amber-700 dark:text-amber-400 leading-relaxed">
                Your profile picture must be a real, clear photo of your face — not an anime character, logo, pet, or other image. Workers with a non-face photo may have it removed during verification.
            </p>
        </div>

        <p id="photoError" class="mt-4 text-sm text-red-600 dark:text-red-400 hidden"></p>
        <p class="mt-3 text-[11px] text-center text-slate-400">JPG, PNG, or WEBP · max 10MB</p>

        <button type="button" id="saveBtn" disabled
                class="mt-6 w-full bg-primary hover:bg-blue-600 disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold py-3.5 rounded-xl transition-all flex items-center justify-center gap-2">
            <span class="material-symbols-outlined text-sm" id="saveBtnIcon">save</span>
            <span id="saveBtnText">Save Photo</span>
        </button>
    </div>
</main>

<?php include '../includes/abilisto_alert.php'; ?>
<script>
(function () {
    const stage       = document.getElementById('cropStage');
    const img         = document.getElementById('cropImage');
    const emptyState  = document.getElementById('cropEmptyState');
    const photoInput  = document.getElementById('photoInput');
    const chooseBtn   = document.getElementById('chooseBtn');
    const zoomRow     = document.getElementById('zoomRow');
    const zoomSlider  = document.getElementById('zoomSlider');
    const saveBtn     = document.getElementById('saveBtn');
    const saveBtnText = document.getElementById('saveBtnText');
    const saveBtnIcon = document.getElementById('saveBtnIcon');
    const errorEl     = document.getElementById('photoError');

    const MAX_BYTES = 10 * 1024 * 1024;

    let naturalW = 0, naturalH = 0, baseScale = 1, zoom = 1, panX = 0, panY = 0;
    let hasImage = false;

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
    }
    function clearError() {
        errorEl.classList.add('hidden');
        errorEl.textContent = '';
    }

    function stageSize() {
        return stage.getBoundingClientRect().width;
    }

    function clampPan() {
        const size = stageSize();
        const renderedW = naturalW * baseScale * zoom;
        const renderedH = naturalH * baseScale * zoom;
        panX = Math.min(0, Math.max(size - renderedW, panX));
        panY = Math.min(0, Math.max(size - renderedH, panY));
    }

    function applyTransform() {
        const size = stageSize();
        const renderedW = naturalW * baseScale * zoom;
        const renderedH = naturalH * baseScale * zoom;
        img.style.width  = renderedW + 'px';
        img.style.height = renderedH + 'px';
        img.style.left   = panX + 'px';
        img.style.top    = panY + 'px';
    }

    function loadFile(file) {
        clearError();
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            showError('Please choose a JPG, PNG, or WEBP image.');
            return;
        }
        if (file.size > MAX_BYTES) {
            showError('That photo is larger than 10MB. Please choose a smaller file.');
            return;
        }
        const url = URL.createObjectURL(file);
        const tempImg = new Image();
        tempImg.onload = function () {
            naturalW = tempImg.naturalWidth;
            naturalH = tempImg.naturalHeight;
            img.src = url;
            img.style.display = 'block';
            emptyState.style.display = 'none';

            const size = stageSize();
            baseScale = Math.max(size / naturalW, size / naturalH);
            zoom = 1;
            zoomSlider.value = 100;
            panX = (size - naturalW * baseScale) / 2;
            panY = (size - naturalH * baseScale) / 2;
            applyTransform();

            hasImage = true;
            zoomRow.classList.remove('hidden');
            saveBtn.disabled = false;
        };
        tempImg.onerror = function () {
            showError('Could not read that image. Please try another file.');
        };
        tempImg.src = url;
    }

    chooseBtn.addEventListener('click', () => photoInput.click());
    photoInput.addEventListener('change', function () {
        if (this.files && this.files[0]) loadFile(this.files[0]);
    });

    // Drag-to-pan (mouse + touch)
    let dragging = false, startX = 0, startY = 0, startPanX = 0, startPanY = 0;
    function dragStart(clientX, clientY) {
        if (!hasImage) return;
        dragging = true;
        stage.classList.add('dragging');
        startX = clientX; startY = clientY;
        startPanX = panX; startPanY = panY;
    }
    function dragMove(clientX, clientY) {
        if (!dragging) return;
        panX = startPanX + (clientX - startX);
        panY = startPanY + (clientY - startY);
        clampPan();
        applyTransform();
    }
    function dragEnd() {
        dragging = false;
        stage.classList.remove('dragging');
    }
    stage.addEventListener('mousedown', (e) => dragStart(e.clientX, e.clientY));
    window.addEventListener('mousemove', (e) => dragMove(e.clientX, e.clientY));
    window.addEventListener('mouseup', dragEnd);
    stage.addEventListener('touchstart', (e) => {
        if (e.touches.length === 1) dragStart(e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });
    stage.addEventListener('touchmove', (e) => {
        if (e.touches.length === 1) { dragMove(e.touches[0].clientX, e.touches[0].clientY); e.preventDefault(); }
    }, { passive: false });
    stage.addEventListener('touchend', dragEnd);

    zoomSlider.addEventListener('input', function () {
        zoom = parseInt(this.value, 10) / 100;
        clampPan();
        applyTransform();
    });

    window.addEventListener('resize', function () {
        if (hasImage) { clampPan(); applyTransform(); }
    });

    saveBtn.addEventListener('click', function () {
        if (!hasImage) return;
        clearError();

        const size = stageSize();
        const renderScale = baseScale * zoom;
        const sourceX = -panX / renderScale;
        const sourceY = -panY / renderScale;
        const sourceSize = size / renderScale;

        const OUTPUT = 480;
        const canvas = document.createElement('canvas');
        canvas.width = OUTPUT;
        canvas.height = OUTPUT;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, sourceX, sourceY, sourceSize, sourceSize, 0, 0, OUTPUT, OUTPUT);

        saveBtn.disabled = true;
        saveBtnText.textContent = 'Saving...';
        saveBtnIcon.textContent = 'hourglass_top';

        canvas.toBlob(function (blob) {
            if (!blob) {
                showError('Could not process that image. Please try again.');
                saveBtn.disabled = false;
                saveBtnText.textContent = 'Save Photo';
                saveBtnIcon.textContent = 'save';
                return;
            }
            const formData = new FormData();
            formData.append('action', 'save_photo');
            formData.append('photo', blob, 'cropped.jpg');

            fetch('edit_profile_photo.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        abilistoAlert('✅ Profile photo updated!', 'success').then(function () {
                            window.location.href = '<?php echo htmlspecialchars($back_link); ?>';
                        });
                    } else {
                        showError(data.error || 'Something went wrong. Please try again.');
                        saveBtn.disabled = false;
                        saveBtnText.textContent = 'Save Photo';
                        saveBtnIcon.textContent = 'save';
                    }
                })
                .catch(function () {
                    showError('Network error. Please try again.');
                    saveBtn.disabled = false;
                    saveBtnText.textContent = 'Save Photo';
                    saveBtnIcon.textContent = 'save';
                });
        }, 'image/jpeg', 0.92);
    });
})();
</script>

</body>
</html>
