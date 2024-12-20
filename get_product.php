<?php
require 'vendor/autoload.php';

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    $collection = $database->featured_products;

    $productId = new MongoDB\BSON\ObjectId($_GET['id']);
    $product = $collection->findOne(['_id' => $productId]);
    
    if ($product) {
        $response = [
            '_id' => (string)$product->_id,
            'name' => $product->name,
            'original_price' => $product->original_price,
            'sale_price' => $product->sale_price,
            'badge' => $product->badge,
            'image_url' => $product->image_url
        ];
        echo json_encode($response);
    } else {
        echo json_encode(['error' => 'Featured product not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?> 