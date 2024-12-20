<?php
session_start();
require 'vendor/autoload.php';

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $result = $database->orders->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($data['order_id'])],
        ['$set' => [
            'status' => $data['status'],
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ]]
    );

    if ($result->getModifiedCount()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes made']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>