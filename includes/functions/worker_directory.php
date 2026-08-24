<?php
// includes/functions/worker_directory.php
// Shared worker-directory data + rendering, extracted out of
// client/dashboard.php so client/search.php and client/search_results.php
// can reuse the exact same category taxonomy, badge config, query logic,
// and card markup instead of duplicating it.

$BADGE_CONFIG = [
    'Gold'      => ['label'=>'NC III Gold',     'color'=>'#D97706', 'bg'=>'bg-amber-100',  'text'=>'text-amber-800',  'icon'=>'workspace_premium', 'border'=>'border-amber-500', 'light-bg'=>'bg-amber-50'],
    'Silver'    => ['label'=>'NC II Silver',    'color'=>'#64748B', 'bg'=>'bg-slate-100',  'text'=>'text-slate-700',  'icon'=>'military_tech', 'border'=>'border-slate-500', 'light-bg'=>'bg-slate-50'],
    'Bronze'    => ['label'=>'NC I Bronze',     'color'=>'#92400E', 'bg'=>'bg-orange-100', 'text'=>'text-orange-800', 'icon'=>'shield', 'border'=>'border-orange-700', 'light-bg'=>'bg-orange-50'],
    'Community' => ['label'=>'Community Cert',  'color'=>'#6D28D9', 'bg'=>'bg-violet-100', 'text'=>'text-violet-800', 'icon'=>'groups', 'border'=>'border-violet-600', 'light-bg'=>'bg-violet-50'],
    'Unverified'=> ['label'=>'Unverified',      'color'=>'#94A3B8', 'bg'=>'bg-slate-50',   'text'=>'text-slate-400',  'icon'=>'', 'border'=>'border-slate-300', 'light-bg'=>'bg-slate-50'],
];

$MAIN_CATEGORIES = [
    'Construction & Trades'    => ['icon'=>'build',           'sub_icon'=>'wrench'],
    'Automotive & Mechanics'   => ['icon'=>'directions_car',  'sub_icon'=>'car_repair'],
    'Technology & Electronics' => ['icon'=>'computer',        'sub_icon'=>'memory'],
    'Domestic & Personal Care' => ['icon'=>'home',            'sub_icon'=>'cleaning_services'],
    'Culinary Arts'            => ['icon'=>'restaurant',      'sub_icon'=>'bakery_dining'],
    'Creative Arts'            => ['icon'=>'palette',         'sub_icon'=>'photo_camera'],
    'Other'                    => ['icon'=>'category',        'sub_icon'=>'work'],
];

$SUB_ICONS = [
    'Electrical'    => 'bolt',
    'Plumbing'      => 'plumbing',
    'Carpentry'     => 'carpenter',
    'Masonry'       => 'domain',
    'Welding'       => 'whatshot',
    'Construction'  => 'build',
    'Automotive'    => 'directions_car',
    'Motorcycle'    => 'two_wheeler',
    'Electronics'   => 'memory',
    'Aircon Ref'    => 'ac_unit',
    'Computer Tech' => 'computer',
    'Domestic Work' => 'cleaning_services',
    'Caregiving'    => 'elderly',
    'Massage'       => 'spa',
    'Beauty Care'   => 'content_cut',
    'Cookery'       => 'restaurant',
    'Baking'        => 'bakery_dining',
    'Graphic_Design'=> 'palette',
    'Photography'   => 'camera_alt',
    'Videography'   => 'videocam',
    'Music'         => 'music_note',
    'Arts Crafts'   => 'brush',
    'Others'        => 'more_horiz',
];

// The app's fixed 5-municipality enum (schema_postgres.sql CHECK constraint).
$MUNICIPALITIES = ['Carrascal', 'Cantilan', 'Madrid', 'Carmen', 'Lanuza'];

/**
 * Runs the worker-search query with optional filters. Returns raw rows
 * (each with 'main_category' + 'skill_badge_data' for parseSkillBadges()).
 *
 * @param array $opts sub, main, q, municipality, sort ('rating'|'jobs')
 */
function searchWorkers($conn, array $opts = []): array {
    $sub          = trim($opts['sub'] ?? '');
    $main         = trim($opts['main'] ?? '');
    $q            = trim($opts['q'] ?? '');
    $municipality = trim($opts['municipality'] ?? '');
    $sort         = ($opts['sort'] ?? 'rating') === 'jobs' ? 'jobs' : 'rating';

    $sql = "
        SELECT
            u.id,
            u.full_name,
            u.municipality,
            u.profile_pic,
            wp.average_rating,
            wp.rating_count,
            wp.jobs_completed,
            wp.minimum_standard_rate,
            ws.main_category,
            STRING_AGG(
                CONCAT_WS('||', ws.sub_category, ws.badge_level, COALESCE(ws.nc_level,''), CASE WHEN ws.is_verified THEN '1' ELSE '0' END),
                ';;'
                ORDER BY
                    CASE ws.badge_level WHEN 'Gold' THEN 1 WHEN 'Silver' THEN 2 WHEN 'Bronze' THEN 3 WHEN 'Community' THEN 4 WHEN 'Unverified' THEN 5 ELSE 6 END,
                    ws.sub_category
            ) AS skill_badge_data
        FROM users u
        JOIN worker_profiles wp ON wp.user_id = u.id
        JOIN worker_skills    ws ON ws.worker_id = u.id
        WHERE u.role = 'worker'
    ";

    $params = [];

    if ($sub !== '') {
        $sql .= " AND ws.sub_category = ?";
        $params[] = $sub;
    } elseif ($main !== '') {
        $sql .= " AND ws.main_category = ?";
        $params[] = $main;
    }

    if ($municipality !== '') {
        $sql .= " AND u.municipality = ?";
        $params[] = $municipality;
    }

    if ($q !== '') {
        $sql .= " AND (
            u.full_name      LIKE ? OR
            ws.sub_category  LIKE ? OR
            ws.main_category LIKE ? OR
            u.municipality   LIKE ?
        )";
        $like_q = '%' . $q . '%';
        $params[] = $like_q;
        $params[] = $like_q;
        $params[] = $like_q;
        $params[] = $like_q;
    }

    $sql .= " GROUP BY u.id, wp.user_id, ws.main_category ORDER BY ";
    $sql .= $sort === 'jobs'
        ? "wp.jobs_completed DESC, wp.average_rating DESC"
        : "wp.average_rating DESC, wp.jobs_completed DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function parseSkillBadges(string $raw): array {
    if (empty($raw)) return [];
    $out = [];
    foreach (explode(';;', $raw) as $chunk) {
        $parts = explode('||', $chunk . '||||');
        $out[] = [
            'sub'         => $parts[0],
            'badge'       => $parts[1] ?: 'Unverified',
            'nc_level'    => $parts[2],
            'is_verified' => (bool)($parts[3] ?? 0),
        ];
    }
    return $out;
}

function getInitials(string $name): string {
    return strtoupper(substr(implode('', array_map(fn($w)=>substr($w,0,1), explode(' ', $name))), 0, 2));
}

function renderColoredSkillTags(array $skills, array $badgeCfg, array $subIcons): void {
    foreach ($skills as $s) {
        $icon = $subIcons[$s['sub']] ?? 'work';
        $cfg = $badgeCfg[$s['badge']] ?? $badgeCfg['Unverified'];

        $bgClass = $cfg['light-bg'] ?? 'bg-slate-50';
        $borderClass = $cfg['border'] ?? 'border-slate-300';
        $textClass = $cfg['text'] ?? 'text-slate-400';

        echo '<span class="skill-tag-colored shrink min-w-0 ' . $bgClass . ' border ' . $borderClass . ' ' . $textClass . '">';
        echo '<span class="material-symbols-outlined shrink-0">' . $icon . '</span>';
        echo '<span class="truncate min-w-0">' . htmlspecialchars($s['sub']) . '</span>';
        echo '</span>';
    }
}

function renderWorkerCard(array $worker, array $badgeCfg, array $subIcons): void {
    $skills   = parseSkillBadges($worker['skill_badge_data'] ?? '');
    $uploadsDir = '../uploads/profiles/';
    $hasImage = !empty($worker['profile_pic']) && file_exists($uploadsDir . $worker['profile_pic']);
    $initials = getInitials($worker['full_name']);

    $highestBadge = 'Unverified';
    foreach ($skills as $s) {
        if ($s['badge'] !== 'Unverified') {
            $badgePriority = ['Gold' => 4, 'Silver' => 3, 'Bronze' => 2, 'Community' => 1, 'Unverified' => 0];
            if ($badgePriority[$s['badge']] > $badgePriority[$highestBadge]) {
                $highestBadge = $s['badge'];
            }
        }
    }
    $badgeIcon = $badgeCfg[$highestBadge]['icon'] ?? '';
    // Cap skill tags shown on the grid card — an unlimited, variable-length
    // tag list is what made cards wrap to different heights across a row.
    $shownSkills = array_slice($skills, 0, 2);
    $extraSkillsCount = count($skills) - count($shownSkills);
    ?>
    <a href="worker_details.php?id=<?php echo $worker['id']; ?>"
       class="group bg-white dark:bg-slate-800 rounded-xl lg:rounded-2xl p-2.5 lg:p-4 border border-slate-200 dark:border-slate-700 card-shadow hover:scale-[1.02] hover:shadow-xl transition-all duration-300 flex flex-col w-full h-full worker-card">

        <div class="relative w-full shrink-0 aspect-[4/3] lg:aspect-square rounded-lg lg:rounded-xl overflow-hidden mb-2 lg:mb-4 <?php echo !$hasImage ? 'bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center' : ''; ?>">
            <?php if ($hasImage): ?>
                <img src="<?php echo $uploadsDir . htmlspecialchars($worker['profile_pic']); ?>" class="w-full h-full object-cover" alt="">
            <?php else: ?>
                <span class="text-2xl lg:text-3xl font-black text-white/80"><?php echo $initials; ?></span>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-1 mb-1 lg:mb-1.5">
            <h3 class="text-sm lg:text-base font-bold truncate"><?php echo htmlspecialchars($worker['full_name']); ?></h3>
            <?php if ($badgeIcon): ?>
            <span class="badge-icon shrink-0 <?php echo $badgeCfg[$highestBadge]['bg'] ?? 'bg-slate-100'; ?> <?php echo $badgeCfg[$highestBadge]['text'] ?? 'text-slate-700'; ?>">
                <span class="material-symbols-outlined"><?php echo $badgeIcon; ?></span>
            </span>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-1 text-slate-500 dark:text-slate-400 text-[11px] lg:text-xs mb-2 lg:mb-2.5 truncate">
            <span class="material-symbols-outlined text-xs lg:text-sm shrink-0">location_on</span>
            <span class="truncate"><?php echo htmlspecialchars($worker['municipality'] ?: 'Location not set'); ?></span>
        </div>

        <div class="flex flex-nowrap items-center gap-1 mb-1.5 lg:mb-3 h-[20px] lg:h-[40px] overflow-hidden shrink-0">
            <?php renderColoredSkillTags($shownSkills, $badgeCfg, $subIcons); ?>
            <?php if ($extraSkillsCount > 0): ?>
            <span class="text-[10px] lg:text-xs font-bold text-slate-400">+<?php echo $extraSkillsCount; ?></span>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-0.5 mt-auto mb-2 lg:mb-4">
            <?php
            $rating    = round($worker['average_rating'] * 2) / 2;
            $full      = floor($rating);
            $half      = ($rating - $full) >= 0.5;
            for ($i = 1; $i <= 5; $i++) {
                if ($i <= $full)           echo '<span class="material-symbols-outlined text-yellow-400 rating-star" style="font-size:10px;font-variation-settings:\'FILL\' 1">star</span>';
                elseif ($i==$full+1&&$half) echo '<span class="material-symbols-outlined text-yellow-400 rating-star" style="font-size:10px">star_half</span>';
                else                        echo '<span class="material-symbols-outlined text-slate-300 dark:text-slate-600 rating-star" style="font-size:10px">star</span>';
            }
            ?>
            <span class="ml-0.5 text-[10px] lg:text-sm font-bold"><?php echo number_format($worker['average_rating'], 1); ?></span>
        </div>

        <?php if ($worker['minimum_standard_rate'] > 0): ?>
        <div class="text-[11px] lg:text-sm text-slate-500 dark:text-slate-400 truncate">
            from <strong class="text-primary">₱<?php echo number_format($worker['minimum_standard_rate']); ?></strong>/job
        </div>
        <?php endif; ?>
    </a>
    <?php
}

function renderWorkerCardHorizontal(array $worker, array $badgeCfg, array $subIcons): void {
    $skills     = parseSkillBadges($worker['skill_badge_data'] ?? '');
    $uploadsDir = '../uploads/profiles/';
    $hasImage   = !empty($worker['profile_pic']) && file_exists($uploadsDir . $worker['profile_pic']);
    $initials   = getInitials($worker['full_name']);

    $highestBadge = 'Unverified';
    foreach ($skills as $s) {
        if ($s['badge'] !== 'Unverified') {
            $badgePriority = ['Gold' => 4, 'Silver' => 3, 'Bronze' => 2, 'Community' => 1, 'Unverified' => 0];
            if ($badgePriority[$s['badge']] > $badgePriority[$highestBadge]) {
                $highestBadge = $s['badge'];
            }
        }
    }
    $badgeIcon = $badgeCfg[$highestBadge]['icon'] ?? '';
    $shownSkills = array_slice($skills, 0, 2);
    $extraSkillsCount = count($skills) - count($shownSkills);
    ?>
    <a href="worker_details.php?id=<?php echo $worker['id']; ?>"
       class="group bg-white dark:bg-slate-800 rounded-xl lg:rounded-2xl p-2.5 lg:p-4 border border-slate-200 dark:border-slate-700 card-shadow hover:scale-[1.02] hover:shadow-xl transition-all duration-300 min-w-[150px] sm:min-w-[170px] lg:min-w-[240px] w-[170px] lg:w-[240px] flex flex-col flex-shrink-0 h-[236px] lg:h-[392px] worker-card">

        <div class="relative w-full shrink-0 aspect-[4/3] lg:aspect-square rounded-lg lg:rounded-xl overflow-hidden mb-2 lg:mb-4 <?php echo !$hasImage ? 'bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center' : ''; ?>">
            <?php if ($hasImage): ?>
                <img src="<?php echo $uploadsDir . htmlspecialchars($worker['profile_pic']); ?>" class="w-full h-full object-cover" alt="">
            <?php else: ?>
                <span class="text-2xl lg:text-3xl font-black text-white/80"><?php echo $initials; ?></span>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-1 mb-1 lg:mb-1.5">
            <h3 class="text-sm lg:text-base font-bold truncate"><?php echo htmlspecialchars($worker['full_name']); ?></h3>
            <?php if ($badgeIcon): ?>
            <span class="badge-icon shrink-0 <?php echo $badgeCfg[$highestBadge]['bg'] ?? 'bg-slate-100'; ?> <?php echo $badgeCfg[$highestBadge]['text'] ?? 'text-slate-700'; ?>">
                <span class="material-symbols-outlined"><?php echo $badgeIcon; ?></span>
            </span>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-1 text-slate-500 dark:text-slate-400 text-[11px] lg:text-xs mb-2 lg:mb-2.5 truncate">
            <span class="material-symbols-outlined text-xs lg:text-sm shrink-0">location_on</span>
            <span class="truncate"><?php echo htmlspecialchars($worker['municipality'] ?: 'Location not set'); ?></span>
        </div>

        <div class="flex flex-nowrap items-center gap-1 mb-1.5 lg:mb-3 h-[20px] lg:h-[40px] overflow-hidden shrink-0">
            <?php renderColoredSkillTags($shownSkills, $badgeCfg, $subIcons); ?>
            <?php if ($extraSkillsCount > 0): ?>
            <span class="text-[10px] lg:text-xs font-bold text-slate-400">+<?php echo $extraSkillsCount; ?></span>
            <?php endif; ?>
        </div>

        <div class="flex items-center gap-0.5 mt-auto mb-2 lg:mb-4">
            <?php
            $rating = round($worker['average_rating'] * 2) / 2;
            $full   = floor($rating);
            $half   = ($rating - $full) >= 0.5;
            for ($i = 1; $i <= 5; $i++) {
                if ($i <= $full)            echo '<span class="material-symbols-outlined text-yellow-400 rating-star" style="font-size:10px;font-variation-settings:\'FILL\' 1">star</span>';
                elseif ($i==$full+1&&$half) echo '<span class="material-symbols-outlined text-yellow-400 rating-star" style="font-size:10px">star_half</span>';
                else                        echo '<span class="material-symbols-outlined text-slate-300 dark:text-slate-600 rating-star" style="font-size:10px">star</span>';
            }
            ?>
            <span class="ml-0.5 text-[10px] lg:text-sm font-bold"><?php echo number_format($worker['average_rating'], 1); ?></span>
        </div>
    </a>
    <?php
}
