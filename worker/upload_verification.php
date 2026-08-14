<?php
// worker/upload_verification.php
include '../db.php';
include '../includes/services/DocumentAIService.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$docAI = new DocumentAIService();
$message = '';
$message_type = '';

if (isset($_POST['submit_document'])) {
    $document_type = $_POST['document_type'];
    $target_dir = "../uploads/verification/";
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_name = time() . '_' . $worker_id . '_' . basename($_FILES['document']['name']);
    $target_file = $target_dir . $file_name;
    $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
    
    // Validate file type
    $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!in_array($file_type, $allowed_types)) {
        $message = "Only JPG, PNG, and PDF files are allowed.";
        $message_type = "danger";
    } elseif (move_uploaded_file($_FILES['document']['tmp_name'], $target_file)) {
        
        // Determine MIME type
        $mime_type = $file_type == 'pdf' ? 'application/pdf' : 'image/' . $file_type;
        
        // Process with Document AI
        if ($document_type == 'id') {
            $result = $docAI->extractIDInfo($target_file);
        } else {
            $result = $docAI->extractNCInfo($target_file);
        }
        
        if ($result['success']) {
            // Save extracted data
            $extracted_data = json_encode($result['data']);
            
            $sql = "INSERT INTO verification_documents 
                    (worker_id, document_type, file_path, extracted_data, status, uploaded_at) 
                    VALUES (?, ?, ?, ?, 'pending', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isss", $worker_id, $document_type, $target_file, $extracted_data);
            
            if ($stmt->execute()) {
                $message = "Document uploaded and processed successfully! Pending verification.";
                $message_type = "success";
            } else {
                $message = "Database error: " . $conn->error;
                $message_type = "danger";
            }
        } else {
            $message = "Document processing failed. Our team will review it manually.";
            $message_type = "warning";
            
            // Save for manual review
            $sql = "INSERT INTO verification_documents 
                    (worker_id, document_type, file_path, status, uploaded_at) 
                    VALUES (?, ?, ?, 'pending_review', NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $worker_id, $document_type, $target_file);
            $stmt->execute();
        }
    } else {
        $message = "Sorry, there was an error uploading your file.";
        $message_type = "danger";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Upload Verification Documents</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <style>
        body { background: #f0f2f5; }
        .container { max-width: 600px; margin: 50px auto; }
        .card { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .info-box { background: #e3f2fd; border-radius: 10px; padding: 15px; margin: 20px 0; }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container">
        <div class="card p-4">
            <h2 class="text-center mb-4">
                <i class="fas fa-shield-alt text-primary"></i> 
                Verification Documents
            </h2>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <div class="d-flex align-items-center gap-3">
                    <i class="fas fa-robot fa-2x text-primary"></i>
                    <div>
                        <h5 class="mb-1">AI-Powered Verification</h5>
                        <p class="mb-0 small">Google AI will automatically extract your information for faster verification.</p>
                    </div>
                </div>
            </div>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label fw-bold">Document Type</label>
                    <select name="document_type" class="form-select" required>
                        <option value="">-- Select Document Type --</option>
                        <option value="id">Government ID (UMID, Driver's License, Passport)</option>
                        <option value="nc_i">NC I Certificate</option>
                        <option value="nc_ii">NC II Certificate</option>
                        <option value="nc_iii">NC III Certificate</option>
                        <option value="barangay">Barangay Clearance</option>
                        <option value="other">Other Document</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Upload Document</label>
                    <input type="file" name="document" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                    <small class="text-muted">Accepted formats: JPG, PNG, PDF (Max 5MB)</small>
                </div>
                
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="consent" required>
                    <label class="form-check-label" for="consent">
                        I confirm that this document is genuine and belongs to me.
                    </label>
                </div>

                <!-- Add this to the top of your upload_verification.php, right after the form opens -->
<div class="text-center mb-4">
    <a href="scan_document.php?type=id" class="btn btn-primary btn-lg px-5" style="border-radius: 50px;">
        <i class="fas fa-camera"></i> Scan with Camera
    </a>
    <p class="text-muted mt-2">or upload manually</p>
</div>
                
                <button type="submit" name="submit_document" class="btn btn-primary w-100 py-3">
                    <i class="fas fa-cloud-upload-alt"></i> Upload & Verify
                </button>
            </form>
            
            <hr class="my-4">
            
            <div class="row text-center g-3">
                <div class="col-4">
                    <i class="fas fa-camera fa-2x text-muted mb-2"></i>
                    <p class="small mb-0">Take a clear photo</p>
                </div>
                <div class="col-4">
                    <i class="fas fa-robot fa-2x text-muted mb-2"></i>
                    <p class="small mb-0">AI extracts data</p>
                </div>
                <div class="col-4">
                    <i class="fas fa-check-circle fa-2x text-muted mb-2"></i>
                    <p class="small mb-0">Admin verifies</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>