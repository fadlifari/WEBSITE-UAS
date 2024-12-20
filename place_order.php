<?php
session_start();
require 'vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;

    // Create order document
    $orderData = [
        'user_id' => new MongoDB\BSON\ObjectId($_SESSION['user_id']),
        'products' => array_map(function($item) {
            return [
                'product_id' => new MongoDB\BSON\ObjectId($item['product_id']),
                'quantity' => $item['quantity'],
                'price' => $item['price']
            ];
        }, $data['cart']),
        'shipping_info' => $data['shipping_info'],
        'total_amount' => $data['total_amount'],
        'status' => 'pending',
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'updated_at' => new MongoDB\BSON\UTCDateTime()
    ];

    // Insert order
    $result = $database->orders->insertOne($orderData);

    if ($result->getInsertedCount()) {
        // Update product stock
        foreach ($data['cart'] as $item) {
            $database->products->updateOne(
                ['_id' => new MongoDB\BSON\ObjectId($item['product_id'])],
                ['$inc' => ['stock' => -$item['quantity']]]
            );
        }

        // Clear cart
        unset($_SESSION['cart']);

        echo json_encode([
            'success' => true,
            'message' => 'Order placed successfully',
            'order_id' => (string)$result->getInsertedId()
        ]);
    } else {
        throw new Exception('Failed to place order');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>