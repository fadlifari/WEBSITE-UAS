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
    $productId = $data['product_id'];
    $quantity = $data['quantity'] ?? 1;

    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;

    // Cek stok produk
    $product = $database->products->findOne(['_id' => new MongoDB\BSON\ObjectId($productId)]);
    
    if (!$product) {
        throw new Exception('Product not found');
    }

    if ($product->stock < $quantity) {
        throw new Exception('Insufficient stock');
    }

    // Inisialisasi cart jika belum ada
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    // Tambah/update item di cart
    $found = false;
    foreach ($_SESSION['cart'] as &$item) {
        if ($item['product_id'] == $productId) {
            $item['quantity'] += $quantity;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $_SESSION['cart'][] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'price' => $product->price,
            'name' => $product->name
        ];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Product added to cart',
        'cart_count' => count($_SESSION['cart'])
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>