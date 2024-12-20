<?php
require 'vendor/autoload.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    $collection = $database->featured_products;

    $id = $_GET['id'];
    $product = $collection->findOne(['_id' => new MongoDB\BSON\ObjectId($id)]);

    if ($product) {
        $product->_id = (string)$product->_id;
        echo json_encode(['success' => true, 'data' => $product]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>