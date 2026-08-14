<?php
// worker/portfolio.php
include '../db.php';
$worker_id = $_SESSION['user_id'];

// Only process image uploads here (simplified for MVP)
if (isset($_POST['upload_photo'])) {
    $target_dir = "../uploads/portfolios/";
    $file_name = time() . "_" . basename($_FILES["photo"]["name"]);
    $target_file = $target_dir . $file_name;

    if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
        // We can create a simple 'portfolio_images' table or just store in a folder
        // For this MVP, let's assume a table 'portfolio_images' exists or just list files from folder
        // Here I will use a simple DB insert assuming you add this table
        $conn->query("INSERT INTO portfolio_images (user_id, image_path) VALUES ($worker_id, '$file_name')");
        echo "<script>alert('Photo added to portfolio!');</script>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>My Portfolio | Abilisto</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .gallery { display: flex; flex-wrap: wrap; gap: 10px; }
        .gallery img { width: 150px; height: 150px; object-fit: cover; border: 1px solid #ddd; padding: 5px; }
    </style>
</head>
<body>
    <div style="padding: 20px;">
        <a href="dashboard.php">&larr; Back</a>
        <h2>My Work Portfolio</h2>
        <p>Upload photos of your past projects to gain client trust.</p>

        <form method="POST" enctype="multipart/form-data">
            <input type="file" name="photo" required accept="image/*">
            <button type="submit" name="upload_photo">Add Photo</button>
        </form>

        <hr>
        
        <div class="gallery">
            <?php 
            // Create this table in DB: CREATE TABLE portfolio_images (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, image_path VARCHAR(255));
            $images = $conn->query("SELECT * FROM portfolio_images WHERE user_id = $worker_id");
            while($img = $images->fetch_assoc()): 
            ?>
                <img src="../uploads/portfolios/<?php echo $img['image_path']; ?>">
            <?php endwhile; ?>
        </div>
    </div>
</body>
</html>