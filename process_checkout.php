<?php
session_start();
require 'vendor/autoload.php';

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = $_SESSION['user_id'];
        $user = $database->users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);

        // Validasi input
        if (empty($_POST['street']) || empty($_POST['city']) || 
            empty($_POST['province']) || empty($_POST['postal_code']) || 
            empty($_POST['payment_method'])) {
            throw new Exception("All fields are required");
        }

        // Ambil items dari cart
        $cartItems = $database->carts->find([
            'user_id' => new MongoDB\BSON\ObjectId($userId)
        ])->toArray();

        if (empty($cartItems)) {
            throw new Exception("Cart is empty");
        }

        // Hitung total dan siapkan products array
        $total = 0;
        $products = [];
        foreach ($cartItems as $item) {
            $product = $database->products->findOne([
                '_id' => $item['product_id']
            ]);

            if ($product) {
                $products[] = [
                    'product_id' => $item['product_id'],
                    'name' => $product['name'],
                    'quantity' => (int)$item['quantity'],
                    'price' => (float)$product['price']
                ];
                $total += $product['price'] * $item['quantity'];
            }
        }

        // Buat order baru
        $orderData = [
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'name' => $user['name'],
            'email' => $user['email'],
            'products' => $products,
            'shipping_address' => [
                'street' => $_POST['street'],
                'city' => $_POST['city'],
                'province' => $_POST['province'],
                'postal_code' => $_POST['postal_code']
            ],
            'payment_method' => $_POST['payment_method'],
            'total_amount' => $total,
            'status' => 'pending',
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ];

        // Simpan order
        $result = $database->orders->insertOne($orderData);

        if ($result->getInsertedCount()) {
            // Hapus cart setelah checkout berhasil
            $database->carts->deleteMany([
                'user_id' => new MongoDB\BSON\ObjectId($userId)
            ]);

            // Set session untuk order berhasil
            $_SESSION['order_success'] = true;
            $_SESSION['order_id'] = (string)$result->getInsertedId();

            // Kirim response sukses
            echo json_encode([
                'success' => true,
                'message' => 'Order placed successfully',
                'order_id' => (string)$result->getInsertedId()
            ]);
        } else {
            throw new Exception("Failed to create order");
        }
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 