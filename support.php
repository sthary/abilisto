<?php
// support.php
include 'db.php';
include 'includes/init_lang.php';

// Security Check - Optional (remove if you want public access)
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $email = $conn->real_escape_string($_POST['email']);
    $subject = $conn->real_escape_string($_POST['subject']);
    $message = $conn->real_escape_string($_POST['message']);
    
    // Insert into contact_messages table (create this table if needed)
    $sql = "INSERT INTO contact_messages (user_id, name, email, subject, message, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issss", $user_id, $name, $email, $subject, $message);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Message sent successfully! We'll respond within 24 hours.";
        
        // Send email notification to support
        $to = "abilistodevunit@abilisto.site";
        $email_subject = "New Support Request: $subject";
        $email_message = "Name: $name\nEmail: $email\n\nMessage:\n$message";
        mail($to, $email_subject, $email_message);
        
    } else {
        $_SESSION['error'] = "Failed to send message. Please try again.";
    }
    
    header("Location: support.php#contact");
    exit();
}

// Get user data for pre-filling contact form
$user_sql = "SELECT full_name, email FROM users WHERE id = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// FAQ categories and questions
$faqs = [
    'getting_started' => [
        'title' => 'Getting Started',
        'icon' => 'rocket_launch',
        'questions' => [
            [
                'q' => 'How do I create an account?',
                'a' => 'Click the "Sign Up" button on the top right corner. You can register using your email address or Google account. Follow the verification steps sent to your email to activate your account.'
            ],
            [
                'q' => 'How do I verify my professional identity?',
                'a' => 'To maintain marketplace integrity, go to your profile settings and upload a valid government-issued ID. Our automated verification system typically processes requests within 15 minutes using secure encryption.'
            ],
            [
                'q' => 'What services can I offer on Abilisto?',
                'a' => 'You can offer various services including electrical work, plumbing, carpentry, electronics repair, and more. Make sure to complete your profile with your skills and certifications.'
            ]
        ]
    ],
    'payments' => [
        'title' => 'Payments & Payouts',
        'icon' => 'payments',
        'questions' => [
            [
                'q' => 'What are the platform transaction fees?',
                'a' => 'Abilisto charges a 2% platform fee on completed transactions. This fee covers payment processing, escrow services, and customer support. There are no hidden fees or monthly subscriptions.'
            ],
            [
                'q' => 'How do I withdraw my earnings?',
                'a' => 'Go to your Wallet section and click "Withdraw". You can transfer funds to your GCash account or bank account. Withdrawals are processed within 1-2 business days.'
            ],
            [
                'q' => 'Can I integrate my existing bank account?',
                'a' => 'Yes, you can add multiple payout methods including bank accounts and GCash. Go to Settings > Payment Methods to add and manage your accounts.'
            ]
        ]
    ],
    'safety' => [
        'title' => 'Safety & Security',
        'icon' => 'verified_user',
        'questions' => [
            [
                'q' => 'How does the escrow system work?',
                'a' => 'When you book a service, payment is held securely in escrow. The worker only receives the payment after you confirm the job is completed satisfactorily. This protects both parties.'
            ],
            [
                'q' => 'How does the dispute resolution work?',
                'a' => 'If you encounter an issue, you can file a report from your bookings page. Our admin team will review the case, communicate with both parties, and make a fair decision within 48 hours.'
            ],
            [
                'q' => 'Is my personal information secure?',
                'a' => 'Absolutely. We use industry-standard encryption, secure servers, and never share your personal information with third parties. Read our Privacy Policy for more details.'
            ]
        ]
    ],
    'bookings' => [
        'title' => 'Bookings & Jobs',
        'icon' => 'book_online',
        'questions' => [
            [
                'q' => 'How do I book a worker?',
                'a' => 'Browse worker profiles, select the service you need, choose a date and time, and send a booking request. The worker will respond within 24 hours.'
            ],
            [
                'q' => 'Can I cancel a booking?',
                'a' => 'Yes, you can cancel pending bookings from your My Bookings page. For accepted bookings, please contact the worker directly. Repeated cancellations may affect your account standing.'
            ],
            [
                'q' => 'What if the worker doesn\'t show up?',
                'a' => 'If a worker fails to show up, you can file a report from the booking page. Our team will investigate and take appropriate action, including refunding any payments.'
            ]
        ]
    ]
];
?>

<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Help & FAQ Center | Abilisto</title>
    
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    
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
            .search-glow {
                box-shadow: 0 0 40px -10px rgba(20, 106, 245, 0.2);
            }
            .faq-gradient-active {
                background: linear-gradient(90deg, rgba(20, 106, 245, 0.03) 0%, rgba(255, 255, 255, 0) 100%);
            }
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        .faq-answer.open {
            max-height: 300px;
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
                    },
                },
            },
        }
    </script>
</head>
<body class="bg-background-light font-display text-slate-900 antialiased selection:bg-primary/10">
<div class="relative min-h-screen flex flex-col">

    <!-- Main Content (Navbar removed as requested) -->
    <main class="flex-1 w-full max-w-[1000px] mx-auto px-6 pb-20">
        
        <!-- Back to Settings Link -->
        <div class="mb-12 mt-10">
            <a href="settings.php" class="inline-flex items-center gap-2 group text-slate-400 hover:text-primary transition-colors">
                <span class="material-symbols-outlined text-lg">arrow_back</span>
                <span class="text-[11px] uppercase tracking-[0.3em] font-bold">Back to Settings</span>
            </a>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-green-100 text-green-800 border border-green-200 flex items-center justify-between" id="successMessage">
            <span><?php echo $_SESSION['success']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-green-600 hover:text-green-800">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-rose-100 text-rose-800 border border-rose-200 flex items-center justify-between" id="errorMessage">
            <span><?php echo $_SESSION['error']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-800">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        </div>
        <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <!-- Header -->
        <div class="text-center mb-16">
            <h1 class="text-4xl md:text-5xl font-black tracking-tight mb-8 text-slate-900">
                How can we <span class="text-primary">help you?</span>
            </h1>

        
        <!-- Category Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-20">
            <?php foreach ($faqs as $key => $category): ?>
            <a href="#<?php echo $key; ?>" class="glass-morphism p-8 rounded-xl hover:translate-y-[-4px] transition-all cursor-pointer group">
                <div class="w-12 h-12 rounded-xl bg-primary/5 flex items-center justify-center text-primary mb-6 group-hover:bg-primary group-hover:text-white transition-all">
                    <span class="material-symbols-outlined"><?php echo $category['icon']; ?></span>
                </div>
                <h3 class="text-sm font-bold tracking-[0.1em] uppercase mb-2"><?php echo $category['title']; ?></h3>
                <p class="text-slate-500 text-xs leading-relaxed tracking-wide"><?php echo count($category['questions']); ?> articles</p>
            </a>
            <?php endforeach; ?>
        </div>
        
        <!-- FAQ Sections -->
        <div class="max-w-3xl mx-auto mb-24">
            <?php foreach ($faqs as $key => $category): ?>
            <div id="<?php echo $key; ?>" class="mb-12 scroll-mt-20">
                <div class="flex items-center gap-4 mb-8">
                    <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                        <span class="material-symbols-outlined text-primary"><?php echo $category['icon']; ?></span>
                    </div>
                    <h2 class="text-[11px] font-black uppercase tracking-[0.4em] text-slate-400"><?php echo $category['title']; ?></h2>
                    <div class="h-px flex-1 bg-slate-200"></div>
                </div>
                
                <div class="space-y-4">
                    <?php foreach ($category['questions'] as $index => $faq): ?>
                    <div class="faq-item group border border-slate-200/60 rounded-[16px] overflow-hidden bg-white/50 transition-all hover:border-primary/30" data-question="<?php echo strtolower($faq['q'] . ' ' . $faq['a']); ?>">
                        <button onclick="toggleFaq(this)" class="w-full flex items-center justify-between p-6 text-left faq-gradient-active">
                            <span class="font-semibold text-slate-800 tracking-tight"><?php echo $faq['q']; ?></span>
                            <span class="material-symbols-outlined text-primary transition-transform duration-300">expand_more</span>
                        </button>
                        <div class="faq-answer px-6">
                            <div class="pb-6 text-slate-500 text-sm leading-relaxed tracking-wide">
                                <?php echo $faq['a']; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Contact Section -->
        <div class="glass-morphism rounded-2xl p-12 text-center relative overflow-hidden" id="contact">
            <div class="absolute top-0 right-0 p-8 opacity-5">
                <span class="material-symbols-outlined text-9xl">support_agent</span>
            </div>
            <h2 class="text-2xl font-bold mb-4 tracking-tight">Still need help?</h2>
            <p class="text-slate-500 text-sm mb-10 max-w-md mx-auto leading-relaxed tracking-wide">
                Our specialized support ecosystem is available 24/7 to ensure your professional workflow remains uninterrupted.
            </p>
            
            <!-- Contact Options -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-8">
                <!-- Facebook Messenger -->
                <a href="https://www.facebook.com/profile.php?id=61590436745827" target="_blank"
                   class="w-full sm:w-auto px-10 py-4 bg-[#1877F2] text-white rounded-full font-bold text-[11px] uppercase tracking-[0.2em] shadow-lg shadow-[#1877F2]/20 hover:bg-[#0d65d9] transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-lg">chat</span>
                    Message on Facebook
                </a>

                <!-- Email -->
                <a href="mailto:abilistodevunit@abilisto.site"
                   class="w-full sm:w-auto px-10 py-4 border border-slate-200 bg-white text-slate-700 rounded-full font-bold text-[11px] uppercase tracking-[0.2em] hover:bg-slate-50 transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-lg">mail</span>
                    abilistodevunit@abilisto.site
                </a>
            </div>

<!-- JavaScript for Interactive Features -->
<script>
    // Toggle FAQ accordion
    function toggleFaq(button) {
        const faqItem = button.closest('.faq-item');
        const answer = faqItem.querySelector('.faq-answer');
        const icon = button.querySelector('.material-symbols-outlined');
        
        // Close all other FAQs
        document.querySelectorAll('.faq-item').forEach(item => {
            if (item !== faqItem) {
                const otherAnswer = item.querySelector('.faq-answer');
                const otherIcon = item.querySelector('.material-symbols-outlined');
                otherAnswer.classList.remove('open');
                otherIcon.style.transform = 'rotate(0deg)';
            }
        });
        
        // Toggle current FAQ
        answer.classList.toggle('open');
        icon.style.transform = answer.classList.contains('open') ? 'rotate(180deg)' : 'rotate(0deg)';
    }
    
    // Search functionality
    function searchFAQs() {
        const searchTerm = document.getElementById('searchFaq').value.toLowerCase();
        const faqItems = document.querySelectorAll('.faq-item');
        let visibleCount = 0;
        
        faqItems.forEach(item => {
            const question = item.getAttribute('data-question') || '';
            if (question.includes(searchTerm) || searchTerm === '') {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
        
        // Show/hide category headers based on visible items
        document.querySelectorAll('[id^="getting_started"], [id^="payments"], [id^="safety"], [id^="bookings"]').forEach(section => {
            const items = section.querySelectorAll('.faq-item');
            let hasVisible = false;
            items.forEach(item => {
                if (item.style.display !== 'none') hasVisible = true;
            });
            section.style.display = hasVisible ? '' : 'none';
        });
        
        // Show "no results" message if needed
        let noResultsMsg = document.getElementById('noResults');
        if (visibleCount === 0) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('div');
                noResultsMsg.id = 'noResults';
                noResultsMsg.className = 'text-center py-12';
                noResultsMsg.innerHTML = `
                    <span class="material-symbols-outlined text-4xl text-slate-300 mb-3">search_off</span>
                    <p class="text-slate-500">No matching questions found.</p>
                    <p class="text-sm text-slate-400 mt-2">Try different keywords or browse categories above.</p>
                `;
                document.querySelector('.max-w-3xl').appendChild(noResultsMsg);
            }
        } else if (noResultsMsg) {
            noResultsMsg.remove();
        }
    }
    
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
    
    // Auto-hide success/error messages after 5 seconds
    setTimeout(() => {
        ['successMessage', 'errorMessage'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.style.transition = 'opacity 0.5s';
                el.style.opacity = '0';
                setTimeout(() => el.remove(), 500);
            }
        });
    }, 5000);
</script>

</body>
</html>