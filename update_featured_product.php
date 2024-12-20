<?php
session_start();
require 'vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    $collection = $database->featured_products;

    $result = $collection->updateOne(
        ['_id' => new MongoDB\BSON\ObjectId($data['id'])],
        ['$set' => [
            'name' => $data['name'],
            'original_price' => (int)$data['original_price'],
            'sale_price' => (int)$data['sale_price'],
            'badge' => $data['badge'],
            'image_url' => $data['image_url'],
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ]]
    );

    if ($result->getModifiedCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No changes made']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
