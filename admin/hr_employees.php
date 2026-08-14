<?php
// admin/hr_employees.php
include '../db.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hr') {
    header("Location: ../auth/login.php"); exit();
}

$hr_user_id = $_SESSION['user_id'];
$hr_name    = $conn->query("SELECT full_name FROM users WHERE id=$hr_user_id")->fetch_assoc()['full_name'] ?? 'HR';

// ── Handle POST actions ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $eid        = intval($_POST['employee_id'] ?? 0);
        $name       = $conn->real_escape_string(trim($_POST['full_name']   ?? ''));
        $code       = $conn->real_escape_string(trim($_POST['employee_code']?? ''));
        $email      = $conn->real_escape_string(trim($_POST['email']       ?? ''));
        $phone      = $conn->real_escape_string(trim($_POST['phone']       ?? ''));
        $address    = $conn->real_escape_string(trim($_POST['address']     ?? ''));
        $position   = $conn->real_escape_string(trim($_POST['position']    ?? ''));
        $dept       = $conn->real_escape_string($_POST['department']        ?? 'Other');
        $emp_type   = $conn->real_escape_string($_POST['employment_type']   ?? 'Full-time');
        $emp_status = $conn->real_escape_string($_POST['employment_status'] ?? 'Active');
        $hired      = $conn->real_escape_string($_POST['date_hired']        ?? date('Y-m-d'));
        $salary     = floatval($_POST['basic_salary'] ?? 0);
        $pay_freq   = $conn->real_escape_string($_POST['pay_frequency']     ?? 'Monthly');
        $sss        = $conn->real_escape_string(trim($_POST['sss_number']   ?? ''));
        $ph         = $conn->real_escape_string(trim($_POST['philhealth_number']?? ''));
        $pagibig    = $conn->real_escape_string(trim($_POST['pagibig_number']   ?? ''));
        $tin        = $conn->real_escape_string(trim($_POST['tin_number']       ?? ''));
        $bank       = $conn->real_escape_string(trim($_POST['bank_name']        ?? ''));
        $bank_acc   = $conn->real_escape_string(trim($_POST['bank_account']     ?? ''));
        $gcash      = $conn->real_escape_string(trim($_POST['gcash_number']     ?? ''));
        $notes      = $conn->real_escape_string(trim($_POST['notes']            ?? ''));

        if ($action === 'add') {
            $conn->query("INSERT INTO employees
                (employee_code,full_name,email,phone,address,position,department,employment_type,
                 employment_status,date_hired,basic_salary,pay_frequency,
                 sss_number,philhealth_number,pagibig_number,tin_number,
                 bank_name,bank_account,gcash_number,notes,added_by)
                VALUES
                ('$code','$name','$email','$phone','$address','$position','$dept','$emp_type',
                 '$emp_status','$hired',$salary,'$pay_freq',
                 '$sss','$ph','$pagibig','$tin',
                 '$bank','$bank_acc','$gcash','$notes',$hr_user_id)");
            header("Location: hr_employees.php?msg=added"); exit();
        } else {
            $conn->query("UPDATE employees SET
                employee_code='$code', full_name='$name', email='$email', phone='$phone',
                address='$address', position='$position', department='$dept',
                employment_type='$emp_type', employment_status='$emp_status',
                date_hired='$hired', basic_salary=$salary, pay_frequency='$pay_freq',
                sss_number='$sss', philhealth_number='$ph', pagibig_number='$pagibig',
                tin_number='$tin', bank_name='$bank', bank_account='$bank_acc',
                gcash_number='$gcash', notes='$notes'
                WHERE id=$eid");
            header("Location: hr_employees.php?msg=updated"); exit();
        }
    }

    if ($action === 'delete') {
        $eid = intval($_POST['employee_id']??0);
        // Check not in active payroll
        $in_payroll = (int)$conn->query("SELECT COUNT(*) as c FROM payroll_items pi JOIN payroll_runs pr ON pr.id=pi.payroll_run_id WHERE pi.employee_id=$eid AND pr.status NOT IN ('Cancelled')")->fetch_assoc()['c'];
        if ($in_payroll > 0) {
            header("Location: hr_employees.php?error=has_payroll"); exit();
        }
        $conn->query("DELETE FROM employees WHERE id=$eid");
        header("Location: hr_employees.php?msg=deleted"); exit();
    }

    if ($action === 'toggle_status') {
        $eid    = intval($_POST['employee_id']??0);
        $status = $conn->real_escape_string($_POST['new_status']??'Active');
        $conn->query("UPDATE employees SET employment_status='$status' WHERE id=$eid");
        header("Location: hr_employees.php?msg=status_updated"); exit();
    }
}

// ── Editing? ────────────────────────────────────────────────
$edit_emp = null;
if (isset($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $edit_emp = $conn->query("SELECT * FROM employees WHERE id=$eid")->fetch_assoc();
}
$show_form = isset($_GET['action']) && $_GET['action']==='add' || $edit_emp;

// ── Filters ─────────────────────────────────────────────────
$search   = $conn->real_escape_string($_GET['search']  ?? '');
$dept_f   = $conn->real_escape_string($_GET['dept']    ?? '');
$status_f = $conn->real_escape_string($_GET['status']  ?? '');

$where = "WHERE 1=1";
if ($search)   $where .= " AND (full_name LIKE '%$search%' OR employee_code LIKE '%$search%' OR position LIKE '%$search%' OR email LIKE '%$search%')";
if ($dept_f)   $where .= " AND department='$dept_f'";
if ($status_f) $where .= " AND employment_status='$status_f'";

$employees = $conn->query("SELECT * FROM employees $where ORDER BY employment_status='Active' DESC, full_name ASC");
$total = $employees ? $employees->num_rows : 0;

// ── Auto-generate employee code ─────────────────────────────
$last_code = $conn->query("SELECT employee_code FROM employees ORDER BY id DESC LIMIT 1")->fetch_assoc();
$last_code = $last_code['employee_code'] ?? 'EMP-000';
preg_match('/(\d+)$/', $last_code, $m);
$next_code = 'EMP-' . str_pad((intval($m[1]??0)+1), 3, '0', STR_PAD_LEFT);

$current_date = date('M d, Y');
$DEPTS = ['Management','Finance','HR','Tech','Operations','Support','Other'];
$EMP_TYPES = ['Full-time','Part-time','Contractual','Freelancer'];
$PAY_FREQS = ['Monthly','Semi-monthly','Weekly'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/><meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Abilisto — Employees</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons+Round" rel="stylesheet"/>
<script>
    tailwind.config={darkMode:"class",theme:{extend:{colors:{hr:"#8B5CF6",equity:"#10B981",danger:"#F43F5E",expense:"#F59E0B"},fontFamily:{display:["Plus Jakarta Sans","sans-serif"],body:["Plus Jakarta Sans","sans-serif"]}}}};
    if(localStorage.getItem('darkMode')==='true')document.documentElement.classList.add('dark');
    function toggleDarkMode(){document.documentElement.classList.toggle('dark');localStorage.setItem('darkMode',document.documentElement.classList.contains('dark'));}
    function toggleMobileMenu(){document.getElementById('mobile-sidebar').classList.toggle('-translate-x-full');document.getElementById('menu-overlay').classList.toggle('hidden');}
</script>
<style>
    body{font-family:'Plus Jakarta Sans',sans-serif;background:#F5F3FF;}
    .dark body{background:#0B0F1A;}
    h1,h2,h3,.font-display{font-family:'Plus Jakarta Sans',sans-serif;}
    .glass-card{background:rgba(255,255,255,0.75);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.6);box-shadow:0 4px 24px rgba(0,0,0,0.06);}
    .dark .glass-card{background:rgba(17,24,39,0.75);border:1px solid rgba(255,255,255,0.07);}
    .hr-input{background:rgba(255,255,255,0.8);border:1.5px solid rgba(0,0,0,0.1);border-radius:10px;padding:8px 12px;font-size:13px;width:100%;color:#1e293b;transition:border-color .2s;}
    .dark .hr-input{background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:#e2e8f0;}
    .hr-input:focus{outline:none;border-color:#8B5CF6;box-shadow:0 0 0 3px rgba(139,92,246,.15);}
    .hr-label{font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748b;display:block;margin-bottom:4px;}
    .dark .hr-label{color:#94a3b8;}
    .fin-table thead th{font-family:'Plus Jakarta Sans',sans-serif;font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;padding:10px 16px;border-bottom:1px solid rgba(0,0,0,0.06);white-space:nowrap;}
    .dark .fin-table thead th{border-color:rgba(255,255,255,0.06);}
    .fin-table tbody td{padding:12px 16px;font-size:13px;border-bottom:1px solid rgba(0,0,0,0.04);}
    .dark .fin-table tbody td{border-color:rgba(255,255,255,0.04);}
    .fin-table tbody tr:hover{background:rgba(139,92,246,0.04);}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:10px;font-weight:700;}
    .badge-active{background:rgba(16,185,129,.12);color:#10B981;border:1px solid rgba(16,185,129,.2);}
    .badge-leave{background:rgba(245,158,11,.12);color:#F59E0B;border:1px solid rgba(245,158,11,.2);}
    .badge-terminated{background:rgba(244,63,94,.12);color:#F43F5E;border:1px solid rgba(244,63,94,.2);}
    .badge-resigned{background:rgba(148,163,184,.12);color:#64748b;border:1px solid rgba(148,163,184,.2);}
    .slim-scroll::-webkit-scrollbar{width:4px;}.slim-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px;}
    .dark .slim-scroll::-webkit-scrollbar-thumb{background:#334155;}
    /* Slide-in form panel */
    .form-panel{position:fixed;top:0;right:0;width:100%;max-width:580px;height:100%;z-index:60;
                background:white;box-shadow:-8px 0 40px rgba(0,0,0,0.12);
                transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);
                display:flex;flex-direction:column;overflow:hidden;}
    .dark .form-panel{background:#111827;}
    .form-panel.open{transform:translateX(0);}
    .form-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.3);z-index:59;display:none;}
    .form-overlay.open{display:block;}
    #toast{position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.15);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none;}
    #toast.show{transform:translateY(0);opacity:1;}
    #toast.success{background:#10B981;color:white;}
    #toast.error{background:#F43F5E;color:white;}
</style>
</head>
<body class="min-h-screen text-slate-900 dark:text-slate-100">
<?php include 'hr_navbar.php'; ?>

<!-- Toast -->
<div id="toast"></div>

<!-- Form overlay -->
<div class="form-overlay <?php echo $show_form ? 'open' : ''; ?>" id="form-overlay" onclick="closeForm()"></div>

<!-- Slide-in Employee Form -->
<div class="form-panel <?php echo $show_form ? 'open' : ''; ?>" id="employee-form-panel">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex-shrink-0">
        <div>
            <h2 class="font-display text-base font-bold"><?php echo $edit_emp ? 'Edit Employee' : 'Add New Employee'; ?></h2>
            <p class="text-[10px] text-slate-400 uppercase tracking-wider"><?php echo $edit_emp ? 'Update employee details' : 'Create employee record'; ?></p>
        </div>
        <button onclick="closeForm()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400">
            <span class="material-icons-round">close</span>
        </button>
    </div>

    <form method="POST" class="flex-1 overflow-y-auto slim-scroll p-6 space-y-5">
        <input type="hidden" name="action" value="<?php echo $edit_emp ? 'edit' : 'add'; ?>">
        <?php if($edit_emp): ?><input type="hidden" name="employee_id" value="<?php echo $edit_emp['id']; ?>"><?php endif; ?>

        <!-- Basic Info -->
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-violet-500 mb-3 flex items-center gap-2"><span class="material-icons-round text-sm">person</span>Basic Information</p>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="hr-label">Employee Code</label>
                    <input type="text" name="employee_code" class="hr-input" required
                           value="<?php echo htmlspecialchars($edit_emp['employee_code'] ?? $next_code); ?>">
                </div>
                <div>
                    <label class="hr-label">Date Hired</label>
                    <input type="date" name="date_hired" class="hr-input" required
                           value="<?php echo $edit_emp['date_hired'] ?? date('Y-m-d'); ?>">
                </div>
                <div class="col-span-2">
                    <label class="hr-label">Full Name</label>
                    <input type="text" name="full_name" class="hr-input" required placeholder="Juan Dela Cruz"
                           value="<?php echo htmlspecialchars($edit_emp['full_name'] ?? ''); ?>">
                </div>
                <div>
                    <label class="hr-label">Email</label>
                    <input type="email" name="email" class="hr-input" placeholder="juan@abilisto.com"
                           value="<?php echo htmlspecialchars($edit_emp['email'] ?? ''); ?>">
                </div>
                <div>
                    <label class="hr-label">Phone</label>
                    <input type="text" name="phone" class="hr-input" placeholder="09XXXXXXXXX"
                           value="<?php echo htmlspecialchars($edit_emp['phone'] ?? ''); ?>">
                </div>
                <div class="col-span-2">
                    <label class="hr-label">Address</label>
                    <input type="text" name="address" class="hr-input" placeholder="Purok 1, Poblacion, Cantilan"
                           value="<?php echo htmlspecialchars($edit_emp['address'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- Position & Department -->
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-violet-500 mb-3 flex items-center gap-2"><span class="material-icons-round text-sm">work</span>Position & Status</p>
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="hr-label">Position / Job Title</label>
                    <input type="text" name="position" class="hr-input" required placeholder="e.g. Finance Officer, Developer"
                           value="<?php echo htmlspecialchars($edit_emp['position'] ?? ''); ?>">
                </div>
                <div>
                    <label class="hr-label">Department</label>
                    <select name="department" class="hr-input">
                        <?php foreach($DEPTS as $d): ?>
                        <option value="<?php echo $d; ?>" <?php echo (($edit_emp['department'] ?? 'Other') == $d) ? 'selected' : ''; ?>><?php echo $d; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="hr-label">Employment Type</label>
                    <select name="employment_type" class="hr-input">
                        <?php foreach($EMP_TYPES as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo (($edit_emp['employment_type'] ?? 'Full-time') == $t) ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="hr-label">Status</label>
                    <select name="employment_status" class="hr-input">
                        <?php foreach(['Active','On Leave','Terminated','Resigned'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo (($edit_emp['employment_status'] ?? 'Active') == $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="hr-label">Pay Frequency</label>
                    <select name="pay_frequency" class="hr-input">
                        <?php foreach($PAY_FREQS as $f): ?>
                        <option value="<?php echo $f; ?>" <?php echo (($edit_emp['pay_frequency'] ?? 'Monthly') == $f) ? 'selected' : ''; ?>><?php echo $f; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Salary -->
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-violet-500 mb-3 flex items-center gap-2"><span class="material-icons-round text-sm">payments</span>Compensation</p>
            <div>
                <label class="hr-label">Basic Monthly Salary (₱)</label>
                <input type="number" name="basic_salary" class="hr-input" step="0.01" min="0" required placeholder="0.00"
                       value="<?php echo $edit_emp['basic_salary'] ?? ''; ?>">
            </div>
        </div>

        <!-- Government IDs -->
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-violet-500 mb-3 flex items-center gap-2"><span class="material-icons-round text-sm">badge</span>Government IDs</p>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="hr-label">SSS Number</label><input type="text" name="sss_number" class="hr-input" placeholder="##-#######-#" value="<?php echo htmlspecialchars($edit_emp['sss_number'] ?? ''); ?>"></div>
                <div><label class="hr-label">PhilHealth No.</label><input type="text" name="philhealth_number" class="hr-input" placeholder="############" value="<?php echo htmlspecialchars($edit_emp['philhealth_number'] ?? ''); ?>"></div>
                <div><label class="hr-label">Pag-IBIG No.</label><input type="text" name="pagibig_number" class="hr-input" placeholder="############" value="<?php echo htmlspecialchars($edit_emp['pagibig_number'] ?? ''); ?>"></div>
                <div><label class="hr-label">TIN Number</label><input type="text" name="tin_number" class="hr-input" placeholder="###-###-###" value="<?php echo htmlspecialchars($edit_emp['tin_number'] ?? ''); ?>"></div>
            </div>
        </div>

        <!-- Payment Details -->
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-violet-500 mb-3 flex items-center gap-2"><span class="material-icons-round text-sm">account_balance</span>Payment Details</p>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="hr-label">Bank Name</label><input type="text" name="bank_name" class="hr-input" placeholder="BDO, BPI, UnionBank…" value="<?php echo htmlspecialchars($edit_emp['bank_name'] ?? ''); ?>"></div>
                <div><label class="hr-label">Bank Account No.</label><input type="text" name="bank_account" class="hr-input" placeholder="Account number" value="<?php echo htmlspecialchars($edit_emp['bank_account'] ?? ''); ?>"></div>
                <div class="col-span-2"><label class="hr-label">GCash Number</label><input type="text" name="gcash_number" class="hr-input" placeholder="09XXXXXXXXX" value="<?php echo htmlspecialchars($edit_emp['gcash_number'] ?? ''); ?>"></div>
            </div>
        </div>

        <!-- Notes -->
        <div>
            <label class="hr-label">Notes <span class="normal-case text-[9px] opacity-60">(optional)</span></label>
            <textarea name="notes" class="hr-input resize-none" rows="2" placeholder="Any additional notes…"><?php echo htmlspecialchars($edit_emp['notes'] ?? ''); ?></textarea>
        </div>

        <!-- Submit -->
        <div class="pb-4">
            <button type="submit" class="w-full py-3 bg-hr text-white font-bold text-sm rounded-xl hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
                <span class="material-icons-round text-base"><?php echo $edit_emp ? 'save' : 'person_add'; ?></span>
                <?php echo $edit_emp ? 'Save Changes' : 'Add Employee'; ?>
            </button>
        </div>
    </form>
</div>

<main class="lg:ml-72 min-h-screen p-4 md:p-8">

    <!-- Top Bar -->
    <header class="glass-card sticky top-4 z-40 px-5 py-3 rounded-2xl mb-8 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <button class="lg:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500" onclick="toggleMobileMenu()">
                <span class="material-icons-round">menu</span>
            </button>
            <div>
                <h1 class="font-display text-lg font-bold leading-tight">Employees</h1>
                <p class="text-[10px] text-slate-400 uppercase tracking-widest font-semibold"><?php echo $total; ?> record<?php echo $total != 1 ? 's' : ''; ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="openForm()" class="flex items-center gap-2 px-4 py-2 bg-hr text-white text-xs font-bold rounded-xl hover:opacity-90 transition-all">
                <span class="material-icons-round text-sm">person_add</span> Add Employee
            </button>
            <button onclick="toggleDarkMode()" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-500 dark:text-slate-400">
                <span class="material-icons-round text-xl">dark_mode</span>
            </button>
        </div>
    </header>

    <!-- Toast messages -->
    <?php
    $msgs = [
        'added' => ['success', 'Employee added successfully.'],
        'updated' => ['success', 'Employee updated.'],
        'deleted' => ['success', 'Employee removed.'],
        'status_updated' => ['success', 'Status updated.'],
        'has_payroll' => ['error', 'Cannot delete — employee has payroll records.']
    ];
    if(isset($_GET['msg']) && isset($msgs[$_GET['msg']])) { 
        $m = $msgs[$_GET['msg']]; 
        echo "<script>document.addEventListener('DOMContentLoaded',()=>showToast('{$m[1]}','{$m[0]}'));</script>"; 
    }
    if(isset($_GET['error']) && isset($msgs[$_GET['error']])) { 
        $m = $msgs[$_GET['error']]; 
        echo "<script>document.addEventListener('DOMContentLoaded',()=>showToast('{$m[1]}','{$m[0]}'));</script>"; 
    }
    ?>

    <!-- Filters -->
    <div class="glass-card rounded-2xl p-5 mb-6">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[180px]">
                <label class="hr-label">Search</label>
                <input type="text" name="search" class="hr-input" placeholder="Name, code, position, email…" value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div>
                <label class="hr-label">Department</label>
                <select name="dept" class="hr-input" style="min-width:140px">
                    <option value="">All Depts</option>
                    <?php foreach($DEPTS as $d): ?><option value="<?php echo $d; ?>" <?php echo $dept_f === $d ? 'selected' : ''; ?>><?php echo $d; ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="hr-label">Status</label>
                <select name="status" class="hr-input" style="min-width:130px">
                    <option value="">All Statuses</option>
                    <?php foreach(['Active','On Leave','Terminated','Resigned'] as $s): ?>
                    <option value="<?php echo $s; ?>" <?php echo $status_f === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="px-4 py-2 bg-hr text-white text-xs font-bold rounded-xl hover:opacity-90 flex items-center gap-1">
                    <span class="material-icons-round text-sm">filter_list</span> Filter
                </button>
                <a href="hr_employees.php" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-500 text-xs font-bold rounded-xl hover:opacity-80 flex items-center gap-1">
                    <span class="material-icons-round text-sm">clear</span> Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="glass-card rounded-2xl overflow-hidden">
        <div class="overflow-x-auto slim-scroll">
            <table class="fin-table w-full">
                <thead>
                    <tr>
                        <th class="text-left">Employee</th>
                        <th class="text-left">Position</th>
                        <th class="text-left">Department</th>
                        <th class="text-left">Type</th>
                        <th class="text-right">Basic Salary</th>
                        <th class="text-left">Status</th>
                        <th class="text-left">Date Hired</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if($employees && $employees->num_rows > 0):
                    while($e = $employees->fetch_assoc()):
                        $sclass = [
                            'Active' => 'badge-active',
                            'On Leave' => 'badge-leave',
                            'Terminated' => 'badge-terminated',
                            'Resigned' => 'badge-resigned'
                        ][$e['employment_status']] ?? 'badge-draft';
                ?>
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full bg-violet-100 dark:bg-violet-900/30 flex items-center justify-center flex-shrink-0">
                                    <span class="text-xs font-bold text-hr"><?php echo strtoupper(substr($e['full_name'],0,2)); ?></span>
                                </div>
                                <div>
                                    <p class="font-semibold text-[13px]"><?php echo htmlspecialchars($e['full_name']); ?></p>
                                    <p class="text-[10px] text-slate-400 font-mono"><?php echo $e['employee_code']; ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="text-[12px]"><?php echo htmlspecialchars($e['position']); ?></td>
                        <td class="text-[12px]"><?php echo $e['department']; ?></td>
                        <td class="text-[11px] text-slate-500"><?php echo $e['employment_type']; ?></td>
                        <td class="text-right font-mono font-bold text-[13px]">₱<?php echo number_format($e['basic_salary'],2); ?></td>
                        <td><span class="badge <?php echo $sclass; ?>"><?php echo $e['employment_status']; ?></span></td>
                        <td class="text-[11px] text-slate-500"><?php echo date('M d, Y',strtotime($e['date_hired'])); ?></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="?edit=<?php echo $e['id']; ?>" class="p-1.5 text-slate-400 hover:text-hr transition-colors rounded-lg hover:bg-violet-50 dark:hover:bg-violet-900/20" title="Edit">
                                    <span class="material-icons-round text-base">edit</span>
                                </a>
                                <?php if($e['employment_status'] === 'Active'): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="employee_id" value="<?php echo $e['id']; ?>">
                                    <input type="hidden" name="new_status" value="On Leave">
                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-expense transition-colors rounded-lg hover:bg-amber-50 dark:hover:bg-amber-900/20" title="Mark On Leave" onclick="return confirm('Mark as On Leave?')">
                                        <span class="material-icons-round text-base">event_busy</span>
                                    </button>
                                </form>
                                <?php elseif($e['employment_status'] === 'On Leave'): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="employee_id" value="<?php echo $e['id']; ?>">
                                    <input type="hidden" name="new_status" value="Active">
                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-equity transition-colors rounded-lg hover:bg-emerald-50 dark:hover:bg-emerald-900/20" title="Mark Active" onclick="return confirm('Mark as Active?')">
                                        <span class="material-icons-round text-base">event_available</span>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete <?php echo addslashes(htmlspecialchars($e['full_name'])); ?>? This cannot be undone.')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="employee_id" value="<?php echo $e['id']; ?>">
                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-danger transition-colors rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20" title="Delete">
                                        <span class="material-icons-round text-base">delete_outline</span>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="8" class="text-center py-16 text-slate-400">
                        <span class="material-icons-round text-3xl block mb-2 opacity-30">badge</span>
                        No employees found. <button onclick="openForm()" class="text-hr font-semibold">Add your first employee →</button>
                    </td></tr>
                <?php endif; ?>
                </tbody>
             </table>
        </div>
    </div>

</main>

<script>
function openForm(){
    document.getElementById('employee-form-panel').classList.add('open');
    document.getElementById('form-overlay').classList.add('open');
}
function closeForm(){
    document.getElementById('employee-form-panel').classList.remove('open');
    document.getElementById('form-overlay').classList.remove('open');
    if(window.location.search.includes('edit=')){
        history.replaceState({},'',window.location.pathname);
    }
}
<?php if($edit_emp): ?>
document.getElementById('employee-form-panel').classList.add('open');
document.getElementById('form-overlay').classList.add('open');
<?php endif; ?>

function showToast(msg,type){
    const t=document.getElementById('toast');
    t.textContent=msg;
    t.className = 'show ' + type;
    setTimeout(()=>{t.className = type;},3500);
}
</script>
</body>
</html>