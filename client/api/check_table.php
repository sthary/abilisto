<?php
// client/api/check_table.php
session_start();
require_once '../../db.php';

header('Content-Type: application/json');

$tables = ['users', 'worker_profiles', 'job_broadcasts'];
$structure = [];

foreach ($tables as $table) {
    $result = $conn->query("SHOW COLUMNS FROM $table");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    $structure[$table] = $columns;
}

echo json_encode($structure, JSON_PRETTY_PRINT);
?>