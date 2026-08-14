<?php
// worker/scan_document.php
include '../db_connect.php';
include '../includes/init_lang.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'worker') {
    header("Location: ../auth/login.php");
    exit();
}

$worker_id = $_SESSION['user_id'];
$document_type = $_GET['type'] ?? 'id';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Scan Document - Abilisto</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">
    <style>
        body { background: #f0f2f5; }
        .scanner-container { max-width: 800px; margin: 30px auto; }
        .camera-card {
            background: white;
            border-radius: 30px;
            padding: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
        }
        #video {
            width: 100%;
            max-height: 400px;
            object-fit: cover;
            border-radius: 20px;
            background: #2d3e50;
            transform: scaleX(-1); /* Mirror effect for selfie mode */
        }
        #canvas {
            display: none;
        }
        .camera-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 20px 0;
        }
        .capture-btn {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #ff4d4d;
            border: 4px solid white;
            box-shadow: 0 0 0 2px #ff4d4d;
            cursor: pointer;
            transition: all 0.3s;
        }
        .capture-btn:hover {
            transform: scale(1.1);
            background: #ff3333;
        }
        .preview-section {
            text-align: center;
            margin-top: 20px;
        }
        #preview {
            max-width: 100%;
            max-height: 300px;
            border-radius: 15px;
            border: 3px solid white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .document-guide {
            position: relative;
            border: 3px dashed rgba(255,255,255,0.5);
            border-radius: 20px;
            margin: 10px 0;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.1);
        }
        .guide-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            border: 3px solid #4CAF50;
            border-radius: 20px;
            box-shadow: 0 0 0 999px rgba(0,0,0,0.5);
        }
        .guide-text {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            color: white;
            font-weight: bold;
            text-shadow: 0 2px 5px rgba(0,0,0,0.5);
        }
        .flip-camera {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.5);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
        }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            color: white;
            flex-direction: column;
        }
        .spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include '../includes/navbar.php'; ?>
    
    <div class="scanner-container">
        <div class="camera-card">
            <h3 class="text-center mb-4">
                <i class="fas fa-camera text-primary"></i> 
                Scan <?php echo ucwords(str_replace('_', ' ', $document_type)); ?>
            </h3>
            
            <!-- Camera Feed -->
            <div class="position-relative">
                <video id="video" autoplay playsinline></video>
                <button class="flip-camera" id="flipCamera" title="Switch Camera">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
            
            <!-- Camera Controls -->
            <div class="camera-controls">
                <button class="capture-btn" id="captureBtn" title="Take Photo"></button>
            </div>
            
            <!-- Preview Section (hidden initially) -->
            <div class="preview-section" id="previewSection" style="display: none;">
                <h5>Review Photo</h5>
                <img id="preview" src="" alt="Preview">
                
                <div class="mt-3 d-flex gap-2 justify-content-center">
                    <button class="btn btn-warning" onclick="retakePhoto()">
                        <i class="fas fa-redo"></i> Retake
                    </button>
                    <button class="btn btn-success" onclick="processPhoto()">
                        <i class="fas fa-check"></i> Process & Verify
                    </button>
                </div>
            </div>
            
            <!-- Document Type Info -->
            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle"></i>
                <strong>Tips for best results:</strong>
                <ul class="mb-0 mt-2">
                    <li>Place document on a flat surface with good lighting</li>
                    <li>Ensure all corners are visible and text is clear</li>
                    <li>Avoid glare and shadows</li>
                    <li>Hold camera steady when taking photo</li>
                </ul>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <h5>Processing Document...</h5>
        <p class="text-muted">Google AI is extracting information</p>
    </div>
    
    <script>
        // Camera elements
        const video = document.getElementById('video');
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d');
        const captureBtn = document.getElementById('captureBtn');
        const previewSection = document.getElementById('previewSection');
        const preview = document.getElementById('preview');
        const flipBtn = document.getElementById('flipCamera');
        const loadingOverlay = document.getElementById('loadingOverlay');
        
        let currentStream = null;
        let usingFrontCamera = true; // Start with front camera (selfie mode for IDs)
        let capturedImage = null;
        
        // Document type
        const documentType = '<?php echo $document_type; ?>';
        
        // Start camera
        async function startCamera(frontCamera = true) {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }
            
            const constraints = {
                video: {
                    facingMode: frontCamera ? 'user' : 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            };
            
            try {
                currentStream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = currentStream;
                console.log('Camera started:', frontCamera ? 'Front' : 'Back');
            } catch (err) {
                console.error('Camera error:', err);
                alert('Unable to access camera. Please ensure you have granted permission.');
            }
        }
        
        // Flip camera
        flipBtn.addEventListener('click', () => {
            usingFrontCamera = !usingFrontCamera;
            startCamera(usingFrontCamera);
        });
        
        // Capture photo
        captureBtn.addEventListener('click', () => {
            // Set canvas dimensions to match video
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            // Draw video frame to canvas
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            // Get image data URL
            const imageData = canvas.toDataURL('image/jpeg', 0.9);
            
            // Show preview
            preview.src = imageData;
            previewSection.style.display = 'block';
            
            // Hide video feed
            video.style.display = 'none';
            
            // Store captured image
            capturedImage = imageData;
        });
        
        // Retake photo
        function retakePhoto() {
            previewSection.style.display = 'none';
            video.style.display = 'block';
            capturedImage = null;
        }
        
        // Process photo with Document AI
        function processPhoto() {
            if (!capturedImage) {
                alert('No photo captured');
                return;
            }
            
            // Show loading
            loadingOverlay.style.display = 'flex';
            
            // Convert base64 to blob
            const block = capturedImage.split(';');
            const contentType = block[0].split(':')[1];
            const realData = block[1].split(',')[1];
            const blob = b64toBlob(realData, contentType);
            
            // Create form data
            const formData = new FormData();
            formData.append('document', blob, 'scan.jpg');
            formData.append('document_type', documentType);
            formData.append('worker_id', '<?php echo $worker_id; ?>');
            
            // Send to server
            fetch('process_scan.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                loadingOverlay.style.display = 'none';
                
                if (data.success) {
                    // Show extracted info
                    showExtractedInfo(data.data);
                } else {
                    alert('Error: ' + data.message);
                    retakePhoto();
                }
            })
            .catch(err => {
                console.error('Upload error:', err);
                loadingOverlay.style.display = 'none';
                alert('Network error. Please try again.');
                retakePhoto();
            });
        }
        
        // Convert base64 to blob
        function b64toBlob(b64Data, contentType = '', sliceSize = 512) {
            const byteCharacters = atob(b64Data);
            const byteArrays = [];
            
            for (let offset = 0; offset < byteCharacters.length; offset += sliceSize) {
                const slice = byteCharacters.slice(offset, offset + sliceSize);
                const byteNumbers = new Array(slice.length);
                
                for (let i = 0; i < slice.length; i++) {
                    byteNumbers[i] = slice.charCodeAt(i);
                }
                
                const byteArray = new Uint8Array(byteNumbers);
                byteArrays.push(byteArray);
            }
            
            return new Blob(byteArrays, { type: contentType });
        }
        
        // Show extracted information modal
        function showExtractedInfo(data) {
            const modal = document.createElement('div');
            modal.className = 'modal fade show';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
            `;
            
            let infoHtml = '';
            if (data && typeof data === 'object') {
                for (const [key, value] of Object.entries(data)) {
                    if (key !== 'text' && value) {
                        infoHtml += `
                            <div class="d-flex justify-content-between mb-2">
                                <strong>${key.replace(/_/g, ' ')}:</strong>
                                <span>${value}</span>
                            </div>
                        `;
                    }
                }
            }
            
            modal.innerHTML = `
                <div style="background: white; border-radius: 30px; padding: 30px; max-width: 500px; width: 90%;">
                    <h4 class="mb-4"><i class="fas fa-robot text-primary"></i> AI Extracted Information</h4>
                    <p class="text-muted small mb-3">Please verify the extracted information:</p>
                    
                    <div style="max-height: 300px; overflow-y: auto;" class="mb-4">
                        ${infoHtml || '<p class="text-warning">No structured data found. Raw text extracted.</p>'}
                    </div>
                    
                    <hr>
                    
                    <div class="d-flex gap-2">
                        <button class="btn btn-success flex-grow-1" onclick="confirmAndSave()">
                            <i class="fas fa-check"></i> Looks Good
                        </button>
                        <button class="btn btn-danger flex-grow-1" onclick="retakePhoto(); this.closest('.modal').remove();">
                            <i class="fas fa-redo"></i> Scan Again
                        </button>
                    </div>
                    
                    <p class="text-muted small text-center mt-3">
                        <i class="fas fa-info-circle"></i> 
                        Admin will review your document for final verification.
                    </p>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Store data for confirmation
            window.extractedData = data;
        }
        
        // Confirm and save to database
        function confirmAndSave() {
            const modal = document.querySelector('.modal');
            if (modal) modal.remove();
            
            loadingOverlay.style.display = 'flex';
            
            fetch('save_scan_result.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    document_type: documentType,
                    extracted_data: window.extractedData,
                    image_data: capturedImage
                })
            })
            .then(response => response.json())
            .then(data => {
                loadingOverlay.style.display = 'none';
                
                if (data.success) {
                    alert('✅ Document submitted for verification! You will be notified once verified.');
                    window.location.href = 'verification.php';
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                console.error('Save error:', err);
                loadingOverlay.style.display = 'none';
                alert('Error saving document. Please try again.');
            });
        }
        
        // Initialize camera on page load
        startCamera(usingFrontCamera);
    </script>
</body>
</html>