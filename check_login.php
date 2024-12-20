<?php
session_start();
header('Content-Type: application/json');

echo json_encode([
    'isLoggedIn' => isset($_SESSION['user_id']),
    'message' => isset($_SESSION['user_id']) ? 'User is logged in' : 'User is not logged in'
]);
?>