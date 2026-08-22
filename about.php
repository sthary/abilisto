<?php
// about.php
include 'db_connect.php';
include 'includes/init_lang.php';

// Security Check (optional - remove if you want public access)
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Get user data for profile
$user_sql = "SELECT full_name, profile_pic, email FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

// Placeholder images for team members (using UI Avatars for consistency)
function getAvatarUrl($name, $bg = '135bec') {
    return "https://ui-avatars.com/api/?name=" . urlencode($name) . "&background=" . $bg . "&color=fff&size=128&bold=true&length=2";
}

// Team members data
$developer = [
    'name' => 'Sthary G. Reyes',
    'role' => 'Lead Developer',
    'title' => 'Full Stack Architect',
    'bio' => 'Visionary architect behind Abilisto\'s core infrastructure. Specializing in high-performance distributed systems and hyper-modern user experiences.',
    'avatar' => getAvatarUrl('Sthary Reyes', '135bec'),
    'facebook' => 'https://www.facebook.com/sthxrysm.gr',
    'instagram' => 'https://www.instagram.com/sthxrysm.gr?igsh=d2lpY2wzZ2RlZTg0',
    'github' => 'https://github.com/sthary',
    'linkedin' => '#'
];

$adviser = [
    'name' => 'Joel S. Gracia, MSCS',
    'role' => 'Project Adviser',
    'title' => 'Technical Advisor',
    'bio' => 'Strategic guidance on Abilisto\'s scalability, system architecture, and academic excellence in computer science.',
    'avatar' => getAvatarUrl('Joel Gracia, MSCS', '2563eb'),
    'facebook' => 'https://www.facebook.com/joel.sabanal.gracia',
    'instagram' => '#',
    'github' => '#',
    'linkedin' => '#'
];

$team_members = [
    [
        'name' => 'Angieline Pebojot',
        'role' => 'Lead Field Researcher',
        'title' => 'Coordinated with respondents',
        'avatar' => getAvatarUrl('Angieline Pebojot', '7c3aed'),
        'facebook' => 'https://www.facebook.com/gelinx08',
        'instagram' => '#',
        'github' => '#',
        'linkedin' => '#'
    ],
    [
        'name' => 'Sholima Nadao',
        'role' => 'Survey Operations Lead',
        'title' => 'Handled documentation / paperwork',
        'avatar' => getAvatarUrl('Sholima Nadao', 'db2777'),
        'facebook' => 'https://www.facebook.com/sholima.nadao.3',
        'instagram' => '#',
        'github' => '#',
        'linkedin' => '#'
    ]
];

// Technologies used
$technologies = [
    ['name' => 'PHP', 'color' => 'bg-indigo-500', 'icon' => 'fa-brands fa-php'],
    ['name' => 'MySQL', 'color' => 'bg-orange-500', 'icon' => 'fa-solid fa-database'],
    ['name' => 'JavaScript', 'color' => 'bg-yellow-500', 'icon' => 'fa-brands fa-js'],
    ['name' => 'Tailwind CSS', 'color' => 'bg-sky-500', 'icon' => 'fa-brands fa-css3-alt'],
    ['name' => 'PayMongo', 'color' => 'bg-blue-600', 'icon' => 'fa-solid fa-credit-card'],
    ['name' => 'Google APIs', 'color' => 'bg-green-600', 'icon' => 'fa-brands fa-google'],
];
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Meet the Creators | Abilisto</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link href="assets/fontawesome/css/all.min.css" rel="stylesheet"/>
    
    <style>
        :root {
            --primary: #146af5;
            --background-light: #f8fafc;
        }
        @layer utilities {
            .glass-morphism {
                background: rgba(255, 255, 255, 0.7);
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                border: 1px solid rgba(20, 106, 245, 0.1);
            }
            .profile-glass {
                background: rgba(255, 255, 255, 0.4);
                backdrop-filter: blur(8px);
                border: 2px solid rgba(255, 255, 255, 0.8);
            }
            .ambient-shadow {
                box-shadow: 0 20px 40px -15px rgba(20, 106, 245, 0.08);
            }
            .tool-pill:hover {
                box-shadow: 0 0 20px rgba(20, 106, 245, 0.15);
                transform: translateY(-2px);
            }
            .hide-scrollbar::-webkit-scrollbar {
                display: none;
            }
            .hide-scrollbar {
                -ms-overflow-style: none;
                scrollbar-width: none;
            }
            .social-icon {
                @apply text-slate-400 hover:text-primary transition-colors duration-300;
            }
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
    </style>
    
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#146af5",
                        "background-light": "#f8fafc",
                    },
                    fontFamily: {
                        "display": ["Plus Jakarta Sans", "sans-serif"]
                    },
                    borderRadius: {
                        "DEFAULT": "1rem",
                        "lg": "1.5rem",
                        "xl": "2rem",
                        "2xl": "2.5rem",
                    },
                },
            },
        }
    </script>
</head>
<body class="bg-[#F8FAFC] font-display text-slate-900 antialiased selection:bg-primary/10">
<div class="relative min-h-screen flex flex-col">
    
    <main class="flex-1 w-full max-w-[1100px] mx-auto px-6 pb-20">
        
        <!-- Main Creator Section -->
        <section class="text-center mt-8 mb-24">
            <h1 class="text-5xl md:text-6xl font-black tracking-tighter mb-16 text-slate-900">
                Meet the <span class="text-primary">Creators</span>
            </h1>
            
            <div class="max-w-xl mx-auto">
                <div class="glass-morphism rounded-2xl p-10 ambient-shadow relative overflow-hidden group">
                    <div class="absolute -top-24 -right-24 w-48 h-48 bg-primary/5 rounded-full blur-3xl"></div>
                    <div class="relative z-10">
                        <div class="w-32 h-32 mx-auto mb-8 relative">
                            <div class="absolute inset-0 profile-glass rounded-full scale-110"></div>
                            <img alt="<?php echo $developer['name']; ?>" 
                                 class="w-full h-full rounded-full object-cover relative z-10 border-4 border-white" 
                                 src="<?php echo $developer['avatar']; ?>"/>
                        </div>
                        <span class="text-[10px] font-black uppercase tracking-[0.4em] text-primary mb-3 block"><?php echo $developer['role']; ?></span>
                        <h2 class="text-3xl font-bold mb-4 tracking-tight"><?php echo $developer['name']; ?></h2>
                        <p class="text-slate-500 text-sm leading-relaxed tracking-wide max-w-sm mx-auto">
                            <?php echo $developer['bio']; ?>
                        </p>
                        <div class="flex justify-center gap-6 mt-10">
                            <a href="<?php echo $developer['facebook']; ?>" target="_blank" class="social-icon hover:text-[#1877F2]">
                                <i class="fa-brands fa-facebook-f text-lg"></i>
                            </a>
                            <a href="<?php echo $developer['instagram']; ?>" target="_blank" class="social-icon hover:text-[#E4405F]">
                                <i class="fa-brands fa-instagram text-lg"></i>
                            </a>
                            <a href="<?php echo $developer['github']; ?>" target="_blank" class="social-icon hover:text-[#333]">
                                <i class="fa-brands fa-github text-lg"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Advisors & Team Section -->
        <section class="mb-32">
            <div class="flex items-center gap-4 mb-12">
                <h2 class="text-[11px] font-black uppercase tracking-[0.4em] text-slate-400 shrink-0">Advisors & Team</h2>
                <div class="h-px flex-1 bg-slate-200"></div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
                <!-- Adviser Card -->
                <div class="glass-morphism p-8 rounded-xl ambient-shadow border-slate-200/60 relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-24 h-24 bg-primary/5 rounded-bl-full"></div>
                    <span class="text-[9px] font-black uppercase tracking-[0.3em] text-primary mb-4 block"><?php echo $adviser['role']; ?></span>
                    <h3 class="text-lg font-bold mb-2"><?php echo $adviser['name']; ?></h3>
                    <p class="text-slate-500 text-xs leading-relaxed tracking-wide mb-8"><?php echo $adviser['bio']; ?></p>
                    <div class="flex items-center justify-between">
                        <div class="h-12 w-12 rounded-full border-2 border-white overflow-hidden shadow-sm">
                            <img alt="<?php echo $adviser['name']; ?>" class="w-full h-full object-cover" src="<?php echo $adviser['avatar']; ?>"/>
                        </div>
                        <div class="flex gap-4">
                            <a href="<?php echo $adviser['facebook']; ?>" class="social-icon hover:text-[#1877F2]"><i class="fa-brands fa-facebook-f text-sm"></i></a>
                            <a href="<?php echo $adviser['instagram']; ?>" class="social-icon hover:text-[#E4405F]"><i class="fa-brands fa-instagram text-sm"></i></a>
                        </div>
                    </div>
                </div>
                
                <!-- Team Members Grid -->
                <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-6">
                    <?php foreach ($team_members as $member): ?>
                    <div class="p-6 rounded-xl border border-slate-200/60 bg-white/40 hover:bg-white/80 transition-all group">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-4">
                                <div class="w-14 h-14 rounded-full overflow-hidden bg-slate-100 border-2 border-white shadow-sm">
                                    <img alt="<?php echo $member['name']; ?>" class="w-full h-full object-cover" src="<?php echo $member['avatar']; ?>"/>
                                </div>
                                <div>
                                    <h4 class="text-base font-bold tracking-tight"><?php echo $member['name']; ?></h4>
                                    <p class="text-[10px] font-medium text-slate-400 uppercase tracking-widest"><?php echo $member['title']; ?></p>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2 mb-3"><?php echo $member['role']; ?></p>
                        <div class="flex gap-4 pt-3 mt-2 border-t border-slate-100">
                            <a href="<?php echo $member['facebook']; ?>" class="social-icon hover:text-[#1877F2]"><i class="fa-brands fa-facebook-f text-sm"></i></a>
                            <a href="<?php echo $member['instagram']; ?>" class="social-icon hover:text-[#E4405F]"><i class="fa-brands fa-instagram text-sm"></i></a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        
        <!-- Technologies Section -->
        <section class="mb-32">
            <div class="text-center mb-10">
                <h2 class="text-[11px] font-black uppercase tracking-[0.5em] text-slate-400">Built With</h2>
            </div>
            <div class="flex overflow-x-auto gap-4 pb-8 hide-scrollbar justify-start md:justify-center px-4">
                <?php foreach ($technologies as $tech): ?>
                <div class="glass-morphism px-6 py-3 rounded-full flex items-center gap-3 tool-pill transition-all shrink-0">
                    <div class="w-5 h-5 <?php echo $tech['color']; ?> rounded-sm flex items-center justify-center text-white text-[10px]">
                        <i class="<?php echo $tech['icon']; ?>"></i>
                    </div>
                    <span class="text-xs font-bold tracking-tight text-slate-700"><?php echo $tech['name']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</div>

<!-- JavaScript for Interactive Elements -->
<script>
    // Smooth scroll to sections
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
    
    // Add hover animation for tool pills
    document.querySelectorAll('.tool-pill').forEach(pill => {
        pill.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        pill.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
</script>

</body>
</html>