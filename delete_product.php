<?php
session_start();
require 'vendor/autoload.php';

// Cek apakah user sudah login sebagai admin
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    // Koneksi ke MongoDB
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    $collection = $database->products;

    // Ambil data yang dikirim
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id'])) {
        throw new Exception('Product ID is required');
    }

    // Hapus produk berdasarkan ID
    $result = $collection->deleteOne([
        '_id' => new MongoDB\BSON\ObjectId($data['id'])
    ]);

    // Cek hasil penghapusan
    if ($result->getDeletedCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Product not found or already deleted'
        ]);
    }

} catch (Exception $e) {
    // Tangani error
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?> 