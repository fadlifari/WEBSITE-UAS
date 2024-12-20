<?php
require 'vendor/autoload.php';

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    $collection = $database->products;

    // Ambil data yang dikirim
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Invalid input data');
    }

    // Siapkan data produk
    $product = [
        'name' => $data['name'],
        'category' => $data['category'],
        'price' => (int)$data['price'],
        'stock' => (int)$data['stock'],
        'image_url' => $data['image_url'],
        'updated_at' => new MongoDB\BSON\UTCDateTime()
    ];

    if (empty($data['id'])) {
        // Tambah produk baru
        $product['created_at'] = new MongoDB\BSON\UTCDateTime();
        $result = $collection->insertOne($product);
        $success = $result->getInsertedCount() > 0;
    } else {
        // Update produk yang ada
        $result = $collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($data['id'])],
            ['$set' => $product]
        );
        $success = $result->getModifiedCount() > 0;
    }

    // Kirim response
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $success ? 'Product saved successfully' : 'No changes made'
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 