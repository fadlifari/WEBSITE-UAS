<?php
require 'vendor/autoload.php';

session_start();

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;

    // Terima data dari request
    $data = json_decode(file_get_contents('php://input'), true);

    // Siapkan data order
    $orderData = [
        'user_id' => new MongoDB\BSON\ObjectId($_SESSION['user_id']),
        'products' => array_map(function($product) {
            return [
                'product_id' => new MongoDB\BSON\ObjectId($product['id']),
                'quantity' => (int)$product['quantity']
            ];
        }, $data['products']),
        'total_amount' => (float)$data['total_amount'],
        'status' => 'pending',
        'created_at' => new MongoDB\BSON\UTCDateTime(),
        'shipping_address' => $data['shipping_address'],
        'payment_method' => $data['payment_method']
    ];

    // Simpan order ke database
    $result = $database->orders->insertOne($orderData);

    if ($result->getInsertedCount()) {
        // Kirim email konfirmasi ke customer
        // ... kode untuk mengirim email ...

        echo json_encode([
            'success' => true,
            'message' => 'Order placed successfully',
            'order_id' => (string)$result->getInsertedId()
        ]);
    } else {
        throw new Exception('Failed to save order');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 