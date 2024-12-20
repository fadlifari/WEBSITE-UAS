<?php
session_start();
require 'vendor/autoload.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    
    $orderId = new MongoDB\BSON\ObjectId($_GET['id']);
    
    $order = $database->orders->aggregate([
        [
            '$match' => [
                '_id' => $orderId
            ]
        ],
        [
            '$lookup' => [
                'from' => 'users',
                'localField' => 'user_id',
                'foreignField' => '_id',
                'as' => 'user'
            ]
        ],
        [
            '$lookup' => [
                'from' => 'products',
                'localField' => 'products.product_id',
                'foreignField' => '_id',
                'as' => 'product_details'
            ]
        ],
        [
            '$unwind' => '$user'
        ]
    ])->toArray();

    if (count($order) > 0) {
        echo json_encode($order[0]);
    } else {
        echo json_encode(['error' => 'Order not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} 