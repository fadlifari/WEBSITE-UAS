<?php
session_start();
require 'vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $client = new MongoDB\Client("mongodb://localhost:27017");
        $database = $client->shoemart_db;

        // Siapkan data pesanan
        $orderData = [
            'user_id' => new MongoDB\BSON\ObjectId($_SESSION['user_id']),
            'name' => $_POST['name'],
            'email' => $_POST['email'],
            'address' => $_POST['address'],
            'shipping_method' => $_POST['shipping'],
            'payment_method' => $_POST['payment'],
            'total_amount' => $_POST['total_amount'],
            'order_status' => 'processing',
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ];

        // Insert ke collection orders
        $result = $database->orders->insertOne($orderData);

        if ($result->getInsertedCount()) {
            // Redirect ke halaman konfirmasi
            header("Location: order_confirmation.php");
            exit();
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>