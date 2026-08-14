<?php
// admin/verify_users.php
include '../db.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// 1. Handle Actions (Approve/Reject)
if (isset($_GET['action']) && isset($_GET['doc_id']) && isset($_GET['worker_id'])) {
    $doc_id = $_GET['doc_id'];
    $worker_id = $_GET['worker_id'];
    $action = $_GET['action']; // 'approve' or 'reject'

    if ($action == 'approve') {
        // A. Update Document Status
        $conn->query("UPDATE worker_docs SET status='Verified' WHERE id=$doc_id");
        
        // B. Enable 'TESDA Verified' badge in worker profile
        // Only if the doc is a TESDA Cert (NC I, II, or III)
        $doc_type = $conn->query("SELECT doc_type FROM worker_docs WHERE id=$doc_id")->fetch_assoc()['doc_type'];
        
        if (strpos($doc_type, 'NC') !== false) { 
            $conn->query("UPDATE worker_profiles SET is_tesda_verified=1 WHERE user_id=$worker_id");
        }
        
        $msg = "Worker Approved!";
    } else {
        // Reject
        $conn->query("UPDATE worker_docs SET status='Rejected' WHERE id=$doc_id");
        $msg = "Document Rejected.";
    }
}

// 2. Fetch Pending Documents
// We join with the 'users' table to see WHO uploaded it
$sql = "SELECT worker_docs.*, users.full_name, users.municipality 
        FROM worker_docs 
        JOIN users ON worker_docs.user_id = users.id 
        WHERE worker_docs.status = 'Pending'";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify Users | Abilisto</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .nav { background: #dc3545; padding: 15px; }
        .nav a { color: white; margin-right: 20px; text-decoration: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background-color: #f8f9fa; }
        .btn { padding: 5px 10px; color: white; text-decoration: none; border-radius: 4px; }
        .btn-approve { background-color: #28a745; }
        .btn-reject { background-color: #dc3545; }
        .doc-preview { max-width: 100px; max-height: 100px; border: 1px solid #ccc; cursor: pointer; }
    </style>
</head>
<body>

    <div class="nav">
        <span>🛡️ Abilisto Admin</span>
        <a href="dashboard.php" style="margin-left: 30px;">Dashboard</a>
        <a href="verify_users.php">Verify Workers</a>
    </div>

    <div style="padding: 20px;">
        <h2>Pending Verification Requests</h2>
        <?php if(isset($msg)) echo "<p style='color:green; font-weight:bold;'>$msg</p>"; ?>

        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Worker Name</th>
                        <th>Municipality</th>
                        <th>Document Type</th>
                        <th>Preview</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $row['full_name']; ?></td>
                        <td><?php echo $row['municipality']; ?></td>
                        <td><?php echo $row['doc_type']; ?></td>
                        
                        <td>
                            <a href="../uploads/credentials/<?php echo $row['file_path']; ?>" target="_blank">
                                <img src="../uploads/credentials/<?php echo $row['file_path']; ?>" class="doc-preview" alt="Doc">
                                <br><small>View Full</small>
                            </a>
                        </td>
                        
                        <td>
                            <a href="verify_users.php?action=approve&doc_id=<?php echo $row['id']; ?>&worker_id=<?php echo $row['user_id']; ?>" 
                               class="btn btn-approve" onclick="return confirm('Approve this worker?');">
                               ✅ Approve
                            </a>
                            
                            <a href="verify_users.php?action=reject&doc_id=<?php echo $row['id']; ?>&worker_id=<?php echo $row['user_id']; ?>" 
                               class="btn btn-reject" onclick="return confirm('Reject this document?');" style="margin-left: 10px;">
                               ❌ Reject
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="margin-top: 20px; color: gray;">No pending documents to review.</p>
        <?php endif; ?>
    </div>

</body>
</html>