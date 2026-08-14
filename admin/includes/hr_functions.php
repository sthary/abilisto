<?php
// admin/includes/hr_functions.php
// HR Dashboard helper functions

function getWorkerAvatar($pic, $name) {
    if (!empty($pic) && file_exists("../uploads/profiles/".$pic)) {
        return "../uploads/profiles/".$pic;
    }
    return "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=6366F1&color=fff&size=128&bold=true";
}

function getBadgeClass($badge) {
    switch($badge) {
        case 'Gold':
            return 'badge-gold';
        case 'Silver':
            return 'badge-silver';
        case 'Bronze':
            return 'badge-bronze';
        case 'Community':
            return 'badge-community';
        default:
            return 'badge-unverified';
    }
}

function getBadgeColorClass($badge) {
    switch($badge) {
        case 'Gold':
            return 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800';
        case 'Silver':
            return 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700';
        case 'Bronze':
            return 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-400 border-orange-200 dark:border-orange-800';
        case 'Community':
            return 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 border-purple-200 dark:border-purple-800';
        default:
            return 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border-slate-200 dark:border-slate-700';
    }
}

function getAvailabilityBadge($status) {
    if ($status == 'Available') {
        return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 border border-green-200 dark:border-green-800">Available</span>';
    } else {
        return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400 border border-yellow-200 dark:border-yellow-800">Busy</span>';
    }
}

function getWorkerStatusBadge($canAccept) {
    if ($canAccept == 1) {
        return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">Active</span>';
    } else {
        return '<span class="px-2 py-1 rounded-full text-[10px] font-bold bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800">Blocked</span>';
    }
}

function formatDate($date) {
    if (empty($date)) return '-';
    return date('M d, Y', strtotime($date));
}

function formatPhoneNumber($phone) {
    if (empty($phone)) return '—';
    // Format: 09XX XXX XXXX
    if (strlen($phone) >= 11) {
        return substr($phone, 0, 4) . ' ' . substr($phone, 4, 3) . ' ' . substr($phone, 7);
    }
    return $phone;
}

function getMunicipalityOptions($selected = '') {
    $municipalities = ['Carrascal', 'Cantilan', 'Madrid', 'Carmen', 'Lanuza'];
    $html = '';
    foreach ($municipalities as $mun) {
        $sel = ($selected == $mun) ? 'selected' : '';
        $html .= "<option value=\"$mun\" $sel>$mun</option>";
    }
    return $html;
}

function getBadgeOptions($selected = '') {
    $badges = ['All Badges', 'Gold', 'Silver', 'Bronze', 'Community', 'Unverified'];
    $html = '';
    foreach ($badges as $badge) {
        $val = ($badge == 'All Badges') ? '' : $badge;
        $sel = ($selected == $val) ? 'selected' : '';
        $html .= "<option value=\"$val\" $sel>$badge</option>";
    }
    return $html;
}

function getServiceCategories($conn, $selected = '') {
    $sql = "SELECT DISTINCT service_category FROM worker_profiles WHERE service_category IS NOT NULL AND service_category != ''";
    $result = $conn->query($sql);
    $html = '<option value="">All Categories</option>';
    while ($row = $result->fetch()) {
        $cats = explode(',', $row['service_category']);
        foreach ($cats as $cat) {
            $cat = trim($cat);
            $sel = ($selected == $cat) ? 'selected' : '';
            $html .= "<option value=\"$cat\" $sel>" . ucfirst($cat) . "</option>";
        }
    }
    return $html;
}

// Check if user has HR role access
function isHRAccess($conn, $user_id) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    if ($row = $stmt->fetch()) {
        return in_array($row['role'], ['admin', 'hr']);
    }
    return false;
}
?>