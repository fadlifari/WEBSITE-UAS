<?php
session_start();
require 'vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    $ordersCollection = $database->orders;

    $data = json_decode(file_get_contents('php://input'), true);
    
    // Tambahkan informasi user
    $data['user_id'] = new MongoDB\BSON\ObjectId($_SESSION['user_id']);
    $data['order_number'] = 'ORD-' . time();
    $data['created_at'] = new MongoDB\BSON\UTCDateTime();
    
    $result = $ordersCollection->insertOne($data);

    if ($result->getInsertedCount()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Order saved successfully',
            'order_id' => (string)$result->getInsertedId()
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save order']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>