<?php
session_start();
// Cek apakah user sudah login dan role-nya customer
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'customer') {
    header("Location: index.php");
    exit();
}

require 'vendor/autoload.php';

try {
    // Buat koneksi MongoDB
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    
    // Buat index untuk pencarian
    $database->featured_products->createIndex(['name' => 'text', 'description' => 'text']);
    $database->categories->createIndex(['name' => 1]);
    $database->brands->createIndex(['name' => 1]);
    $database->featured_products->createIndex(['price' => 1]);
    $database->featured_products->createIndex(['created_at' => 1]);
    
    // Ambil keyword pencarian
    $searchKeyword = isset($_GET['search']) ? $_GET['search'] : '';

    // Pipeline agregasi untuk featured products dengan lookup
    $pipeline = [
        [
            '$lookup' => [
                'from' => 'categories',
                'localField' => 'category_id',
                'foreignField' => '_id',
                'as' => 'category'
            ]
        ],
        [
            '$lookup' => [
                'from' => 'brands',
                'localField' => 'brand_id',
                'foreignField' => '_id',
                'as' => 'brand'
            ]
        ],
        [
            '$lookup' => [
                'from' => 'reviews',
                'localField' => '_id',
                'foreignField' => 'product_id',
                'as' => 'reviews'
            ]
        ]
    ];

    // Tambahkan filter pencarian jika ada keyword
    if (!empty($searchKeyword)) {
        $pipeline[] = [
            '$match' => [
                '$or' => [
                    ['name' => ['$regex' => $searchKeyword, '$options' => 'i']],
                    ['description' => ['$regex' => $searchKeyword, '$options' => 'i']],
                    ['category.name' => ['$regex' => $searchKeyword, '$options' => 'i']],
                    ['brand.name' => ['$regex' => $searchKeyword, '$options' => 'i']]
                ]
            ]
        ];
    }

    // Tambahkan perhitungan rating rata-rata
    $pipeline[] = [
        '$addFields' => [
            'averageRating' => [
                '$avg' => '$reviews.rating'
            ],
            'reviewCount' => [
                '$size' => '$reviews'
            ],
            'categoryName' => ['$arrayElemAt' => ['$category.name', 0]],
            'brandName' => ['$arrayElemAt' => ['$brand.name', 0]]
        ]
    ];

    // Eksekusi agregasi
    $featuredProducts = $database->featured_products->aggregate($pipeline)->toArray();

} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Fungsi helper untuk format harga
function formatPrice($price) {
    return number_format($price, 0, ',', '.');
}

// Ketika customer melakukan order
if (isset($_POST['place_order'])) {
    try {
        // Generate order ID
        $orderId = new MongoDB\BSON\ObjectId();
        
        // Siapkan data order
        $orderData = [
            '_id' => $orderId,
            'user_id' => new MongoDB\BSON\ObjectId($_SESSION['user_id']),
            'order_number' => 'ORD-' . time(),
            'products' => $_POST['products'], // Array produk yang diorder
            'shipping_address' => [
                'street' => $_POST['street'],
                'city' => $_POST['city'],
                'province' => $_POST['province'],
                'postal_code' => $_POST['postal_code']
            ],
            'payment_method' => $_POST['payment_method'],
            'payment_status' => 'pending',
            'order_status' => 'pending',
            'total_amount' => $_POST['total_amount'],
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ];

        // Insert ke collection orders
        $result = $database->orders->insertOne($orderData);

        if ($result->getInsertedCount()) {
            $_SESSION['order_success'] = "Order placed successfully!";
            // Redirect ke halaman order history
            header("Location: order_history.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['order_error'] = "Failed to place order: " . $e->getMessage();
    }
}

// Fungsi untuk memproses checkout
if (isset($_POST['checkout'])) {
    try {
        // Ambil data user
        $userId = $_SESSION['user_id'];
        $user = $database->users->findOne(['_id' => new MongoDB\BSON\ObjectId($userId)]);

        // Siapkan data order
        $orderData = [
            '_id' => new MongoDB\BSON\ObjectId(),
            'user_id' => new MongoDB\BSON\ObjectId($userId),
            'name' => $user['name'],
            'email' => $user['email'],
            'products' => [],
            'shipping_address' => [
                'street' => $_POST['street'],
                'city' => $_POST['city'],
                'province' => $_POST['province'],
                'postal_code' => $_POST['postal_code']
            ],
            'payment_method' => $_POST['payment_method'],
            'total_amount' => 0,
            'status' => 'pending',
            'created_at' => new MongoDB\BSON\UTCDateTime(),
            'updated_at' => new MongoDB\BSON\UTCDateTime()
        ];

        // Ambil produk dari cart dan tambahkan ke order
        $cartItems = $database->carts->find([
            'user_id' => new MongoDB\BSON\ObjectId($userId)
        ])->toArray();

        foreach ($cartItems as $item) {
            $product = $database->products->findOne([
                '_id' => $item['product_id']
            ]);

            if ($product) {
                $orderData['products'][] = [
                    'product_id' => $item['product_id'],
                    'name' => $product['name'],
                    'quantity' => $item['quantity'],
                    'price' => $product['price']
                ];
                $orderData['total_amount'] += ($product['price'] * $item['quantity']);
            }
        }

        // Simpan order ke database
        $result = $database->orders->insertOne($orderData);

        if ($result->getInsertedCount()) {
            // Hapus items dari cart
            $database->carts->deleteMany([
                'user_id' => new MongoDB\BSON\ObjectId($userId)
            ]);

            // Set session untuk menampilkan pesan sukses
            $_SESSION['order_success'] = true;
            $_SESSION['order_id'] = (string)$orderData['_id'];

            // Redirect ke halaman konfirmasi
            header("Location: order_confirmation.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['checkout_error'] = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Bear Shop</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background: #f8f9fa;
            border-right: 1px solid #e9ecef;
            padding-top: 20px;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .sidebar.collapsed {
            width: 70px;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .main-content.expanded {
            margin-left: 70px;
        }

        .sidebar-header {
            padding: 10px 20px;
            color: #2c3e50;
            text-align: center;
            margin-bottom: 20px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .sidebar-menu li {
            margin-bottom: 5px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #6c757d;
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 8px;
            margin: 0 10px;
        }

        .sidebar-menu a:hover {
            background-color: #e9ecef;
            color: #2c3e50;
        }

        .sidebar-menu i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
            color: #6c757d;
        }

        .sidebar-menu a:hover i {
            color: #2c3e50;
        }

        .menu-text {
            transition: opacity 0.3s ease, display 0.3s ease;
        }

        .collapsed .menu-text {
            display: none;
        }

        .toggle-btn {
            position: absolute;
            top: 10px;
            right: -40px;
            background: #f8f9fa;
            color: #6c757d;
            border: 1px solid #e9ecef;
            padding: 5px 10px;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1001;
        }

        .toggle-btn:hover {
            background: #e9ecef;
            color: #2c3e50;
        }

        .user-profile {
            position: absolute;
            bottom: 20px;
            width: 100%;
            padding: 10px 20px;
            color: #6c757d;
            border-top: 1px solid #e9ecef;
        }

        .profile-info {
            display: flex;
            align-items: center;
        }

        .profile-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 10px;
            border: 2px solid #e9ecef;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background: white;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .menu-header {
            padding: 12px 20px;
            color: #adb5bd;
            font-size: 0.8em;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .sidebar-menu .text-danger {
            color: #dc3545 !important;
        }

        .sidebar-menu .text-danger:hover {
            background-color: #fff5f5;
        }

        .sidebar-menu .text-danger i {
            color: #dc3545;
        }

        .sidebar.collapsed .menu-header {
            display: none;
        }

        /* Hover effect enhancement */
        .sidebar-menu a:hover {
            background-color: #e9ecef;
            padding-left: 25px;
        }

        /* Active menu item */
        .sidebar-menu a.active {
            background-color: #e9ecef;
            color: #2c3e50;
            font-weight: 500;
        }

        .sidebar-menu a.active i {
            color: #2c3e50;
        }

        /* Submenu styling if needed */
        .sidebar-menu .submenu {
            list-style: none;
            padding-left: 50px;
            display: none;
        }

        .sidebar-menu .submenu.show {
            display: block;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }
            
            .main-content {
                margin-left: 70px;
            }
            
            .menu-text {
                display: none;
            }
            
            .sidebar:hover {
                width: 250px;
            }
            
            .sidebar:hover .menu-text {
                display: inline;
            }
        }

        /* Carousel Styles */
        .carousel-container {
            position: relative;
            width: 100%;
            height: 80vh;
            overflow: hidden;
            margin-bottom: 30px;
        }

        .carousel-slide {
            position: relative;
            width: 300%; /* 3x lebar untuk 3 gambar */
            height: 100%;
            display: flex;
            transition: transform 0.8s ease-in-out;
        }

        .carousel-image {
            width: 33.333%; /* Setiap gambar mengambil 1/3 dari total lebar */
            height: 100%;
            object-fit: cover;
        }

        /* Dots Navigation */
        .carousel-dots {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            z-index: 10;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 30px;
            backdrop-filter: blur(5px);
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .dot.active {
            background-color: white;
            transform: scale(1.2);
        }

        /* Optional: Add overlay for better text visibility */
        .carousel-container::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                rgba(0, 0, 0, 0.3),
                rgba(0, 0, 0, 0.1)
            );
            pointer-events: none;
        }

        /* Progress Bar */
        .slide-progress {
            position: absolute;
            bottom: 0;
            left: 0;
            height: 3px;
            background: rgba(255, 255, 255, 0.7);
            width: 0;
            z-index: 10;
        }

        .slide-progress.active {
            animation: slideLoader 5s linear;
        }

        @keyframes slideLoader {
            0% { width: 0; }
            100% { width: 100%; }
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .carousel-container {
                height: 50vh;
            }
        }

        .featured-products {
            padding: 60px 20px;
            background: #f8f9fa;
        }

        .section-header {
            text-align: center;
            margin-bottom: 50px;
        }

        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
        }

        .section-subtitle {
            color: #95a5a6;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }

        .section-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            margin: 30px 0;
        }

        .divider-line {
            height: 1px;
            width: 100px;
            background: linear-gradient(to right, transparent, #e0e0e0, transparent);
        }

        .section-divider i {
            color: #f1c40f;
            font-size: 1.2rem;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .product-card {
            position: relative;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .product-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
        }

        .product-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            padding: 8px 15px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            z-index: 1;
            backdrop-filter: blur(5px);
        }

        .product-badge.sale {
            background: rgba(255, 71, 87, 0.95);
            color: white;
        }

        .product-badge.new {
            background: rgba(46, 213, 115, 0.95);
            color: white;
        }

        .product-card img {
            width: 100%;
            height: 300px;
            object-fit: cover;
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .product-card:hover img {
            transform: scale(1.08);
        }

        .product-info {
            padding: 25px;
            background: white;
        }

        .product-info h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2d3436;
            margin-bottom: 15px;
            height: 2.4em;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .product-price {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 15px 0;
        }

        .price {
            font-size: 1.4rem;
            font-weight: 700;
            color: #2d3436;
        }

        .original-price {
            font-size: 1rem;
            color: #b2bec3;
            text-decoration: line-through;
        }

        .product-rating {
            margin: 15px 0;
            color: #f1c40f;
            font-size: 0.9rem;
        }

        .product-rating span {
            color: #95a5a6;
            margin-left: 8px;
        }

        .add-to-cart {
            width: 100%;
            padding: 15px;
            background: #2d3436;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .add-to-cart:hover {
            background: #34495e;
            transform: translateY(-2px);
        }

        .add-to-cart::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .add-to-cart:active::after {
            width: 200px;
            height: 200px;
            opacity: 0;
        }

        /* Hover effect untuk rating */
        .product-rating i {
            transition: transform 0.2s ease;
        }

        .product-rating:hover i {
            transform: scale(1.2);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .featured-products {
                padding: 40px 15px;
            }

            .section-title {
                font-size: 2rem;
            }

            .product-grid {
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 20px;
                padding: 10px;
            }

            .product-card img {
                height: 250px;
            }

            .product-info {
                padding: 20px;
            }

            .price {
                font-size: 1.2rem;
            }
        }

        /* Optional: Animasi loading skeleton */
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }

        .loading .product-card {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 1000px 100%;
            animation: shimmer 2s infinite;
        }

        .cart-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .cart-container {
                grid-template-columns: 1fr;
            }
        }

        .cart-items {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            overflow-y: auto;
            max-height: 400px;
        }

        .cart-item {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px;
            border-bottom: 1px solid #e9ecef;
            transition: background 0.3s ease;
        }

        .cart-item:hover {
            background: #f8f9fa;
        }

        .cart-item img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 10px;
            margin-right: 15px;
        }

        .cart-item-details {
            flex-grow: 1;
        }

        .cart-item-details h4 {
            font-size: 1rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .cart-item-details p {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 10px;
        }

        .va-display {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 1.2em;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .quantity-controls button {
            background: #e9ecef;
            border: none;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .quantity-controls button:hover {
            background: #d1d1d1;
        }

        .remove-item {
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 5px 10px;
            transition: color 0.3s ease;
        }

        .remove-item:hover {
            color: #a71d2a;
        }

        .cart-summary {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            height: fit-content;
        }

        .cart-summary h3 {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
            color: #2c3e50;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            color: #6c757d;
        }

        .summary-item.total {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 2px solid #e9ecef;
            font-weight: bold;
            color: #2c3e50;
            font-size: 1.2em;
        }

        .checkout-btn {
            width: 100%;
            padding: 15px;
            background: #2ecc71;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
        }

        .checkout-btn:hover {
            background: #27ae60;
            transform: translateY(-2px);
        }

        .checkout-section {
            background: #f8f9fa;
            padding: 30px 20px;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-width: 600px;
            margin: 30px auto;
            text-align: center;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }

        .checkout-section.show {
            opacity: 1;
            transform: translateY(0);
        }

        .checkout-form {
            display: grid;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            color: #2c3e50;
            font-weight: 600;
        }

        select.form-control {
            padding: 8px;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            font-size: 0.9rem;
            width: 100%;
        }

        .qris-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .qris-code {
            width: 200px;
            height: 200px;
            border: 1px solid #e9ecef;
            border-radius: 10px;
        }

        .qris-instructions {
            font-size: 1rem;
            color: #2c3e50;
        }

        .checkout-btn {
            width: 100%;
            padding: 12px;
            background: #2ecc71;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 15px;
        }

        #va-timer {
            font-weight: bold;
            color: #dc3545;
        }

        .processing-container {
        background: white;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        padding: 30px;
        max-width: 800px;
        margin: 0 auto;
    }

    .order-confirmation {
        display: flex;
        align-items: center;
        gap: 30px;
    }

    .processing-image {
        width: 250px;
        height: 250px;
        object-fit: contain;
    }

    .order-details {
        flex-grow: 1;
    }

    .order-info {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e9ecef;
    }

    .info-row.total {
        font-weight: bold;
        border-bottom: none;
    }

    .label {
        color: #6c757d;
    }

    .value {
        color: #2c3e50;
    }

    .order-items {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .items-list {
        max-height: 200px;
        overflow-y: auto;
    }

    .order-tracking {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 20px;
    }

    .tracking-info {
        display: flex;
        justify-content: space-between;
    }

    .tracking-step {
        display: flex;
        flex-direction: column;
        align-items: center;
        color: #6c757d;
    }

    .tracking-step.active,
    .tracking-step.active i {
        color: #2ecc71;
    }

    .tracking-step i {
        font-size: 1.5rem;
        margin-bottom: 10px;
    }

    @media (max-width: 768px) {
        .order-confirmation {
            flex-direction: column;
        }

        .processing-image {
            width: 200px;
            height: 200px;
        }
    }
    

    .search-section {
        background: #f8f9fa;
        border-bottom: 1px solid #e9ecef;
    }

    .search-form .input-group {
        max-width: 800px;
        margin: 0 auto;
    }

    .search-form input {
        border-radius: 25px 0 0 25px;
        padding: 12px 20px;
        border: 2px solid #e9ecef;
        border-right: none;
    }

    .search-form button {
        border-radius: 0 25px 25px 0;
        padding: 12px 30px;
    }

    .filter-options {
        max-width: 800px;
        margin: 0 auto;
    }

    .filter-options select {
        border: 2px solid #e9ecef;
        border-radius: 20px;
        padding: 8px 15px;
    }

    .search-results-header {
        text-align: center;
        padding: 20px 0;
    }

    .no-results {
        text-align: center;
        padding: 50px 0;
        color: #6c757d;
    }

    .product-category,
    .product-brand {
        font-size: 0.9em;
        color: #6c757d;
        margin-bottom: 5px;
    }

    /* Animasi hover untuk card */
    .product-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .product-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1);
    }

    /* CSS untuk Order Cards */
    .order-card {
        transition: transform 0.2s;
        border: none;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .order-card:hover {
        transform: translateY(-5px);
    }

    /* CSS untuk Rating Stars */
    .rating {
        display: flex;
        flex-direction: row-reverse;
        justify-content: flex-end;
    }

    .rating input {
        display: none;
    }

    .rating label {
        cursor: pointer;
        font-size: 30px;
        color: #ddd;
    }

    .rating label:hover,
    .rating label:hover ~ label,
    .rating input:checked ~ label {
        color: #ffd700;
    }

    /* CSS untuk Tracking Timeline */
    .tracking-timeline {
        position: relative;
        padding: 20px 0;
    }

    .tracking-item {
        padding: 20px 0;
        position: relative;
        border-left: 2px solid #e9ecef;
        margin-left: 20px;
    }

    .tracking-item.completed {
        border-left-color: #28a745;
    }

    .tracking-icon {
        position: absolute;
        left: -11px;
        top: 20px;
        width: 20px;
        height: 20px;
        background: white;
        border-radius: 50%;
        border: 2px solid #e9ecef;
    }

    .tracking-item.completed .tracking-icon {
        border-color: #28a745;
        background: #28a745;
        color: white;
    }

    .tracking-content {
        margin-left: 30px;
    }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <div class="sidebar-header">
            <h4 class="menu-text">ShoeMart</h4>
        </div>
        <ul class="sidebar-menu">
            <li>
                <a href="#" onclick="scrollToHome(); return false;">
                    <i class="fas fa-home"></i>
                    <span class="menu-text">Home</span>
                </a>
            </li>
            <li>
                <a href="#" onclick="scrollToProducts(); return false;">
                    <i class="fas fa-box"></i>
                    <span class="menu-text">Products</span>
                </a>
            </li>
            <li>
                <a href="#" onclick="scrollToCart(); return false;">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="menu-text">Cart</span>
                </a>
            </li>
            <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
            <!-- Admin Navigation -->
            <li class="menu-header">
                <span class="menu-text">Admin Area</span>
            </li>
            <li>
                <a href="admin/dashboard.php">
                    <i class="fas fa-tachometer-alt"></i>
                    <span class="menu-text">Admin Dashboard</span>
                </a>
            </li>
            <li>
                <a href="admin/users.php">
                    <i class="fas fa-users"></i>
                    <span class="menu-text">Manage Users</span>
                </a>
            </li>
            <li>
                <a href="admin/products.php">
                    <i class="fas fa-boxes"></i>
                    <span class="menu-text">Manage Products</span>
                </a>
            </li>
            <li>
                <a href="admin/orders.php">
                    <i class="fas fa-shopping-bag"></i>
                    <span class="menu-text">Manage Orders</span>
                </a>
            </li>
            <li>
                <a href="admin/categories.php">
                    <i class="fas fa-tags"></i>
                    <span class="menu-text">Categories</span>
                </a>
            </li>
            <li>
                <a href="admin/reports.php">
                    <i class="fas fa-chart-bar"></i>
                    <span class="menu-text">Reports</span>
                </a>
            </li>
            <li>
                <a href="admin/settings.php">
                    <i class="fas fa-cog"></i>
                    <span class="menu-text">Settings</span>
                </a>
            </li>
            <?php endif; ?>
            <li class="menu-header">
                <span class="menu-text">Account</span>
            </li>
            <?php if(isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                <li>
                    <a href="admin/dashboardadmin.php">
                        <i class="fas fa-user"></i>
                        <span class="menu-text">My Profile</span>
                    </a>
                </li>
            <?php else: ?>
                <li>
                    <a href="profile.php">
                        <i class="fas fa-user"></i>
                        <span class="menu-text">My Profile</span>
                    </a>
                </li>
            <?php endif; ?>
            <li>
                <a href="logout.php" class="text-danger">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="menu-text">Logout</span>
                </a>
            </li>
        </ul>
        <div class="user-profile">
            <div class="profile-info">
                <img src="img/Adidas-ZX-1--unscreen.gif" alt="Profile" class="profile-img">
                <span class="menu-text"><?php echo isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Guest'; ?></span>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="main-content">
        <!-- Carousel Section -->
        <div class="carousel-container">
            <div class="carousel-slide">
                <img src="https://i.pinimg.com/originals/bc/38/2e/bc382ee64cd5d4030572692aa76cecf8.gif" alt="Slide 1" class="carousel-image">
                <img src="https://i.pinimg.com/originals/6f/e7/b0/6fe7b06bb6deb70155df5d7e3329ed82.gif" alt="Slide 2" class="carousel-image">
                <img src="https://i.pinimg.com/736x/47/3d/4f/473d4f16acdb8f871be6b6d53ed5ba78.jpg" alt="Slide 3" class="carousel-image">
            </div>
            <div class="carousel-dots">
                <span class="dot active" onclick="currentSlide(1)"></span>
                <span class="dot" onclick="currentSlide(2)"></span>
                <span class="dot" onclick="currentSlide(3)"></span>
            </div>
            <div class="slide-progress"></div>
        </div>

        <!-- Tambahkan form pencarian di bawah carousel -->
        <div class="search-section py-4">
            <div class="container">
                <form action="" method="GET" class="search-form">
                    <div class="input-group">
                        <input type="text" 
                               name="search" 
                               class="form-control" 
                               placeholder="Search for products..." 
                               value="<?php echo htmlspecialchars($searchKeyword); ?>">
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                    <!-- Filter Options -->
                    <div class="filter-options mt-3">
                        <div class="row g-2">
                            <div class="col-md-3">
                                <select class="form-select" name="category">
                                    <option value="">All Categories</option>
                                    <?php
                                    $categories = $database->categories->find();
                                    foreach ($categories as $category) {
                                        echo "<option value='" . $category['_id'] . "'>" . 
                                             htmlspecialchars($category['name']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="brand">
                                    <option value="">All Brands</option>
                                    <?php
                                    $brands = $database->brands->find();
                                    foreach ($brands as $brand) {
                                        echo "<option value='" . $brand['_id'] . "'>" . 
                                             htmlspecialchars($brand['name']) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="price_range">
                                    <option value="">Price Range</option>
                                    <option value="0-500000">Under Rp 500.000</option>
                                    <option value="500000-1000000">Rp 500.000 - Rp 1.000.000</option>
                                    <option value="1000000-2000000">Rp 1.000.000 - Rp 2.000.000</option>
                                    <option value="2000000+">Above Rp 2.000.000</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" name="sort">
                                    <option value="">Sort By</option>
                                    <option value="price_asc">Price: Low to High</option>
                                    <option value="price_desc">Price: High to Low</option>
                                    <option value="rating_desc">Highest Rating</option>
                                    <option value="newest">Newest First</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Hasil Pencarian Produk -->
        <div class="featured-products">
            <div class="container">
                <?php if (!empty($searchKeyword)): ?>
                    <div class="search-results-header mb-4">
                        <h3>Search Results for "<?php echo htmlspecialchars($searchKeyword); ?>"</h3>
                        <p><?php echo count($featuredProducts); ?> products found</p>
                    </div>
                <?php endif; ?>

                <div class="product-grid">
                    <?php if (!empty($featuredProducts)): ?>
                        <?php foreach ($featuredProducts as $product): ?>
                            <div class="product-card">
                                <?php if (isset($product['badge'])): ?>
                                    <div class="product-badge <?php echo strtolower($product['badge']); ?>">
                                        <?php echo htmlspecialchars($product['badge']); ?>
                                    </div>
                                <?php endif; ?>

                                <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($product['name']); ?>">

                                <div class="product-info">
                                    <div class="product-category">
                                        <?php echo htmlspecialchars($product['categoryName'] ?? ''); ?>
                                    </div>
                                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                    <div class="product-brand">
                                        <?php echo htmlspecialchars($product['brandName'] ?? ''); ?>
                                    </div>

                                    <div class="product-price">
                                        <span class="price">
                                            Rp <?php echo number_format($product['sale_price'], 0, ',', '.'); ?>
                                        </span>
                                        <?php if (isset($product['original_price']) && 
                                                $product['original_price'] > $product['sale_price']): ?>
                                            <span class="original-price">
                                                Rp <?php echo number_format($product['original_price'], 0, ',', '.'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="product-rating">
                                        <?php
                                        $rating = isset($product['averageRating']) ? 
                                                 round($product['averageRating'], 1) : 0;
                                        for ($i = 1; $i <= 5; $i++):
                                        ?>
                                            <i class="fas fa-star<?php echo $i <= $rating ? 
                                               ' text-warning' : ' text-muted'; ?>"></i>
                                        <?php endfor; ?>
                                        <span>(<?php echo $product['reviewCount'] ?? 0; ?>)</span>
                                    </div>

                                    <button class="add-to-cart" 
                                            onclick="addToCart('<?php echo (string)$product['_id']; ?>')">
                                        Add to Cart
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-results">
                            <i class="fas fa-search fa-3x mb-3"></i>
                            <h3>No products found</h3>
                            <p>Try different keywords or browse our categories</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="cart-container" id="cart-container" style="display: none;">
            <div class="cart-items">
                <!-- Cart items will be dynamically loaded here -->
            </div>

            <div class="cart-summary">
                <h3>Cart Summary</h3>
                <div class="summary-item">
                    <span>Subtotal</span>
                    <span class="subtotal">Rp 0</span>
                </div>
                <div class="summary-item">
                    <span>Shipping</span>
                    <span class="shipping">Rp 0</span>
                </div>
                <div class="summary-item total">
                    <span>Total</span>
                    <span class="total-amount">Rp 0</span>
                </div>
                <button class="checkout-btn" onclick="proceedToCheckout()">Proceed to Checkout</button>
            </div>
        </div>

        <!-- Checkout Form Section -->
        <div class="checkout-section" id="checkout-section" style="display: none;">
            <div class="section-header">
                <h2 class="section-title">Checkout Details</h2>
                <p class="section-subtitle">Enter your details to proceed</p>
                <div class="section-divider">
                    <span class="divider-line"></span>
                    <i class="fas fa-user"></i>
                    <span class="divider-line"></span>
                </div>
            </div>

            <form class="checkout-form" id="checkout-form">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" class="form-control" placeholder="Full Name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" class="form-control" placeholder="Email" required>
                </div>
                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" class="form-control" placeholder="Shipping Address" required>
                </div>
                
                <!-- Tambahkan form filter pengiriman -->
                <div class="form-group">
                    <label for="shipping">Shipping Method</label>
                    <select id="shipping" class="form-control" required onchange="updateShippingCost()">
                        <option value="">Select Shipping Method</option>
                        <optgroup label="JNE">
                            <option value="jne_reg">JNE REG (2-3 days) - Rp 9.000</option>
                            <option value="jne_yes">JNE YES (1 day) - Rp 18.000</option>
                            <option value="jne_oke">JNE OKE (3-4 days) - Rp 7.000</option>
                        </optgroup>
                        <optgroup label="J&T">
                            <option value="jnt_reg">J&T Regular (2-3 days) - Rp 8.000</option>
                            <option value="jnt_express">J&T Express (1-2 days) - Rp 15.000</option>
                        </optgroup>
                    </select>
                </div>

                <!-- Tambahkan detail pengiriman -->
                <div class="shipping-details" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <h4 class="mb-3">Shipping Details</h4>
                    <div class="shipping-info">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Estimated Delivery:</span>
                            <span id="estimatedDelivery">-</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Shipping Cost:</span>
                            <span id="shippingCost">Rp 0</span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold">
                            <span>Total with Shipping:</span>
                            <span id="totalWithShipping">Rp 0</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="payment">Payment Method</label>
                    <select id="payment" class="form-control" required onchange="updatePaymentMethod()">
                        <option value="">Select Payment Method</option>
                        <optgroup label="E-Wallet">
                            <option value="dana">DANA</option>
                            <option value="gopay">GoPay</option>
                            <option value="ovo">OVO</option>
                        </optgroup>
                        <optgroup label="Bank Transfer">
                            <option value="bca">BCA Virtual Account</option>
                            <option value="bni">BNI Virtual Account</option>
                            <option value="mandiri">Mandiri Virtual Account</option>
                        </optgroup>
                        <optgroup label="Direct Payment">
                            <option value="qris">QRIS</option>
                            <option value="cod">Cash on Delivery (COD)</option>
                        </optgroup>
                    </select>
                </div>

                <!-- Payment Details Section - akan muncul sesuai metode yang dipilih -->
                <div id="payment-details" style="display: none;">
                    <!-- QRIS Payment -->
                    <div id="qris-payment" class="payment-method-detail">
                        <img src="img/WhatsApp Image 2024-12-11 at 00.14.43.jpeg" alt="QRIS Code" class="qris-code">
                        <p class="payment-instructions">Scan QR code using your preferred e-wallet app</p>
                    </div>

                    <!-- Virtual Account Payment -->
                    <div id="va-payment" class="payment-method-detail">
                        <div class="va-number">
                            <h4>Virtual Account Number:</h4>
                            <div class="va-display">
                                <span id="va-number">8234567890123456</span>
                                <button onclick="copyVANumber()" class="copy-btn">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="bank-instructions">
                            <p>Please transfer to the virtual account number above</p>
                            <p>Account will expire in: <span id="va-timer">60:00</span></p>
                        </div>
                    </div>

                    <!-- E-Wallet Payment -->
                    <div id="ewallet-payment" class="payment-method-detail">
                        <div class="qr-section">
                            <img id="ewallet-qr" src="" alt="E-Wallet QR" class="qris-code">
                        </div>
                        <div class="ewallet-instructions">
                            <p>Open your <span id="ewallet-name"></span> app and scan the QR code</p>
                        </div>
                    </div>

                    <!-- COD Payment -->
                    <div id="cod-payment" class="payment-method-detail">
                        <div class="cod-info">
                            <i class="fas fa-truck fa-3x mb-3"></i>
                            <h4>Cash on Delivery</h4>
                            <p>Pay in cash when your order arrives</p>
                            <ul class="cod-notes">
                                <li>Please prepare exact amount</li>
                                <li>Our courier will verify your payment upon delivery</li>
                                <li>Maximum order value: Rp 5.000.000</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <button type="button" class="checkout-btn" onclick="showPesananDiproses()">Checkout</button>
            </form>
        </div>

        <!-- QRIS Payment Section -->
        <div class="processing-section" id="processing-section" style="display: none;">
            <div class="processing-container">
                <div class="order-confirmation">
                    <div class="processing-image-container
                        <img src="img/order-processing.gif" alt="Processing Order" class="processing-image">
                    </div>
                    <div class="order-details">
                        <h2>Pesanan Berhasil!</h2>
                        <p class="status-text">Pesanan Anda sedang diproses</p>
                        
                        <div class="order-info">
                            <div class="info-row">
                                <span class="label">Order ID:</span>
                                <span class="value">#ORD<?php echo rand(100000, 999999); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Tanggal Order:</span>
                                <span class="value"><?php echo date('d M Y H:i'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="label">Status:</span>
                                <span class="value status-badge">Sedang Diproses</span>
                            </div>
                            <div class="info-row total">
                                <span class="label">Total Pembayaran:</span>
                                <span class="value">Rp <span id="finalTotal">0</span></span>
                            </div>
                        </div>

                        <div class="order-tracking">
                            <h3>Status Pesanan</h3>
                            <div class="tracking-info">
                                <div class="tracking-step active">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Order Diterima</span>
                                </div>
                                <div class="tracking-step active">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <span>Pembayaran</span>
                                </div>
                                <div class="tracking-step">
                                    <i class="fas fa-box"></i>
                                    <span>Dikemas</span>
                                </div>
                                <div class="tracking-step">
                                    <i class="fas fa-shipping-fast"></i>
                                    <span>Dikirim</span>
                                </div>
                            </div>
                        </div>

                        <div class="next-steps">
                            <h3>Langkah Selanjutnya</h3>
                            <ul>
                                <li>Tim kami akan segera memproses pesanan Anda</li>
                                <li>Anda akan menerima email konfirmasi dengan detail pesanan</li>
                                <li>Tracking number akan dikirim setelah paket diserahkan ke kurir</li>
                            </ul>
                        </div>

                        <div class="action-buttons">
                            <a href="dashboard.php" class="btn btn-primary">
                                <i class="fas fa-home"></i> Kembali ke Beranda
                            </a>
                            <a href="orders.php" class="btn btn-secondary">
                                <i class="fas fa-list"></i> Lihat Pesanan Saya
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add more content sections as needed -->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Fungsi toggle sidebar yang diperbaiki
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const menuTexts = document.querySelectorAll('.menu-text');
            
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            
            // Toggle visibility untuk text menu
            menuTexts.forEach(text => {
                if (sidebar.classList.contains('collapsed')) {
                    text.style.display = 'none';
                } else {
                    text.style.display = 'inline';
                }
            });
        }

        // Tambahkan event listener untuk responsive toggle
        document.addEventListener('DOMContentLoaded', function() {
            const mediaQuery = window.matchMedia('(max-width: 768px)');
            
            function handleScreenChange(e) {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('main-content');
                const menuTexts = document.querySelectorAll('.menu-text');
                
                if (e.matches) { // Jika layar kecil
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                    menuTexts.forEach(text => text.style.display = 'none');
                } else {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('expanded');
                    menuTexts.forEach(text => text.style.display = 'inline');
                }
            }
            
            // Jalankan pengecekan saat pertama kali load
            handleScreenChange(mediaQuery);
            // Tambahkan listener untuk perubahan ukuran layar
            mediaQuery.addListener(handleScreenChange);
        });

        // Add active class to current menu item
        document.addEventListener('DOMContentLoaded', function() {
            const currentLocation = window.location.pathname;
            const menuItems = document.querySelectorAll('.sidebar-menu a');
            
            menuItems.forEach(item => {
                const href = item.getAttribute('href');
                if (currentLocation.includes(href)) {
                    item.classList.add('active');
                    
                    // If it's a submenu item, show parent menu
                    const parentHeader = item.closest('li').previousElementSibling;
                    if (parentHeader && parentHeader.classList.contains('menu-header')) {
                        parentHeader.style.display = 'block';
                    }
                }
            });
        });

        // Optional: Add smooth scrolling for long sidebars
        const sidebar = document.querySelector('.sidebar');
        if (sidebar.scrollHeight > sidebar.clientHeight) {
            sidebar.style.overflowY = 'auto';
        }

        let slideIndex = 1;
        let slideInterval;
        const slideDelay = 5000; // 5 seconds

        function showSlides(n) {
            const slides = document.querySelector('.carousel-slide');
            const dots = document.getElementsByClassName('dot');
            const progress = document.querySelector('.slide-progress');
            
            if (n > 3) slideIndex = 1;
            if (n < 1) slideIndex = 3;

            // Calculate translation percentage
            const translateValue = -(slideIndex - 1) * 33.333;
            slides.style.transform = `translateX(${translateValue}%)`;

            // Update dots
            for (let i = 0; i < dots.length; i++) {
                dots[i].classList.remove('active');
            }
            dots[slideIndex - 1].classList.add('active');

            // Reset and start progress bar
            progress.classList.remove('active');
            void progress.offsetWidth; // Trigger reflow
            progress.classList.add('active');
        }

        function currentSlide(n) {
            clearInterval(slideInterval);
            showSlides(slideIndex = n);
            startAutoSlide();
        }

        function nextSlide() {
            showSlides(slideIndex += 1);
        }

        function startAutoSlide() {
            slideInterval = setInterval(nextSlide, slideDelay);
        }

        // Initialize carousel
        document.addEventListener('DOMContentLoaded', function() {
            showSlides(slideIndex);
            startAutoSlide();

            // Pause on hover
            const carousel = document.querySelector('.carousel-container');
            carousel.addEventListener('mouseenter', () => {
                clearInterval(slideInterval);
                document.querySelector('.slide-progress').style.animationPlayState = 'paused';
            });
            
            carousel.addEventListener('mouseleave', () => {
                startAutoSlide();
                document.querySelector('.slide-progress').style.animationPlayState = 'running';
            });

            // Optional: Add touch support for mobile
            let touchStartX = 0;
            let touchEndX = 0;

            carousel.addEventListener('touchstart', e => {
                touchStartX = e.changedTouches[0].screenX;
            });

            carousel.addEventListener('touchend', e => {
                touchEndX = e.changedTouches[0].screenX;
                if (touchStartX - touchEndX > 50) {
                    // Swipe left
                    clearInterval(slideInterval);
                    nextSlide();
                    startAutoSlide();
                } else if (touchEndX - touchStartX > 50) {
                    // Swipe right
                    clearInterval(slideInterval);
                    showSlides(slideIndex -= 1);
                    startAutoSlide();
                }
            });
        });

        // Shopping Cart functionality
        document.addEventListener('DOMContentLoaded', function() {
            const addToCartButtons = document.querySelectorAll('.add-to-cart');
            const cartItems = document.querySelector('.cart-items');
            const subtotalElement = document.querySelector('.subtotal');
            const shippingElement = document.querySelector('.shipping');
            const totalElement = document.querySelector('.total-amount');
            const cartContainer = document.getElementById('cart-container');
            
            let cart = [];

            addToCartButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const productCard = this.closest('.product-card');
                    const productId = this.dataset.id;
                    const productName = productCard.querySelector('h3').textContent;
                    const productPrice = productCard.querySelector('.price').textContent;
                    const productImage = productCard.querySelector('img').src;

                    // Add to cart array
                    cart.push({
                        id: productId,
                        name: productName,
                        price: productPrice,
                        image: productImage,
                        quantity: 1
                    });

                    updateCartDisplay();
                    showCart();
                    scrollToCart();
                });
            });

            document.addEventListener('DOMContentLoaded', function () {
    const checkoutButton = document.getElementById('checkout-button');

    if (checkoutButton) {
        checkoutButton.addEventListener('click', function () {
            // Logika untuk proses checkout
            console.log('Checkout button clicked');
            // Tampilkan bagian checkout atau kirim data ke server
        });
    } else {
        console.error('Checkout button not found');
    }
});


            function updateCartDisplay() {
                cartItems.innerHTML = cart.map(item => `
                    <div class="cart-item" data-id="${item.id}">
                        <img src="${item.image}" alt="${item.name}">
                        <div class="cart-item-details">
                            <h4>${item.name}</h4>
                            <p>${item.price}</p>
                            <div class="quantity-controls">
                                <button onclick="updateQuantity('${item.id}', -1)">-</button>
                                <span>${item.quantity}</span>
                                <button onclick="updateQuantity('${item.id}', 1)">+</button>
                            </div>
                        </div>
                        <button onclick="removeFromCart('${item.id}')" class="remove-item">×</button>
                    </div>
                `).join('');

                updateCartSummary();
            }

            function updateCartSummary() {
                // Calculate subtotal
                const subtotal = cart.reduce((total, item) => {
                    const price = parseInt(item.price.replace(/[^\d]/g, ''));
                    return total + (price * item.quantity);
                }, 0);

                // Set shipping cost (example: 10% of subtotal)
                const shipping = subtotal * 0.1;

                // Update display
                subtotalElement.textContent = `Rp ${subtotal.toLocaleString()}`;
                shippingElement.textContent = `Rp ${shipping.toLocaleString()}`;
                totalElement.textContent = `Rp ${(subtotal + shipping).toLocaleString()}`;
            }

            function showCart() {
                cartContainer.style.display = 'block';
                setTimeout(() => {
                    cartContainer.classList.add('show');
                }, 10);
            }

            function scrollToCart() {
                cartContainer.scrollIntoView({ behavior: 'smooth' });
            }

            // Make these functions global
            window.updateQuantity = function(id, change) {
                const item = cart.find(item => item.id === id);
                if (item) {
                    item.quantity = Math.max(1, item.quantity + change);
                    updateCartDisplay();
                }
            };

            window.removeFromCart = function(id) {
                cart = cart.filter(item => item.id !== id);
                updateCartDisplay();
            };
        });

        function proceedToCheckout() {
            const checkoutSection = document.getElementById('checkout-section');
            checkoutSection.style.display = 'block';
            setTimeout(() => {
                checkoutSection.classList.add('show');
                scrollToCheckout();
            }, 10);
        }

        function scrollToCheckout() {
            const checkoutSection = document.getElementById('checkout-section');
            checkoutSection.scrollIntoView({ behavior: 'smooth' });
        }

        function updateShippingCost() {
            const shippingSelect = document.getElementById('shipping');
            const estimatedDeliveryElement = document.getElementById('estimatedDelivery');
            const shippingCostElement = document.getElementById('shippingCost');
            const totalWithShippingElement = document.getElementById('totalWithShipping');
            
            // Dapatkan total dari cart (asumsikan sudah ada)
            const cartTotal = parseFloat(document.querySelector('.total-amount').textContent.replace(/[^\d]/g, ''));
            
            let shippingCost = 0;
            let estimatedDelivery = '-';
            
            // Set biaya dan estimasi berdasarkan pilihan
            switch(shippingSelect.value) {
                case 'jne_reg':
                    shippingCost = 9000;
                    estimatedDelivery = '2-3 days';
                    break;
                case 'jne_yes':
                    shippingCost = 18000;
                    estimatedDelivery = '1 day';
                    break;
                case 'jne_oke':
                    shippingCost = 7000;
                    estimatedDelivery = '3-4 days';
                    break;
                case 'jnt_reg':
                    shippingCost = 8000;
                    estimatedDelivery = '2-3 days';
                    break;
                case 'jnt_express':
                    shippingCost = 15000;
                    estimatedDelivery = '1-2 days';
                    break;
            }
            
            // Update tampilan
            estimatedDeliveryElement.textContent = estimatedDelivery;
            shippingCostElement.textContent = `Rp ${shippingCost.toLocaleString()}`;
            const total = cartTotal + shippingCost;
            totalWithShippingElement.textContent = `Rp ${total.toLocaleString()}`;
        }

        function showPesananDiproses() {
            const checkoutForm = document.getElementById('checkout-form');
            const shippingMethod = document.getElementById('shipping').value;
            
            if (!shippingMethod) {
                alert('Silakan pilih metode pengiriman');
                return;
            }
            
            if (checkoutForm.checkValidity()) {
                // Kumpulkan data pesanan
                const orderData = {
                    customer_name: document.getElementById('name').value,
                    email: document.getElementById('email').value,
                    address: document.getElementById('address').value,
                    shipping_method: document.getElementById('shipping').options[document.getElementById('shipping').selectedIndex].text,
                    payment_method: document.getElementById('payment').options[document.getElementById('payment').selectedIndex].text,
                    total_amount: document.getElementById('totalWithShipping').textContent.replace('Rp ', ''),
                    items: getCartItems(), // Fungsi untuk mengambil items dari cart
                    order_status: 'pending',
                    created_at: new Date().toISOString()
                };

                // Kirim data ke server
                fetch('save_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(orderData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Tampilkan halaman sukses
                        document.getElementById('checkout-section').style.display = 'none';
                        const processingSection = document.getElementById('processing-section');
                        processingSection.style.display = 'block';
                        processingSection.scrollIntoView({ behavior: 'smooth' });
                    } else {
                        alert('Error saving order: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to process order');
                });
            } else {
                checkoutForm.reportValidity();
            }
        }

        // Fungsi helper untuk mengambil items dari cart
        function getCartItems() {
            const cartItems = [];
            document.querySelectorAll('.cart-item').forEach(item => {
                cartItems.push({
                    product_id: item.dataset.productId,
                    name: item.querySelector('.item-name').textContent,
                    price: item.querySelector('.item-price').textContent.replace('Rp ', ''),
                    quantity: parseInt(item.querySelector('.item-quantity').value)
                });
            });
            return cartItems;
        }

        function updatePaymentMethod() {
            const paymentSelect = document.getElementById('payment');
            const paymentDetails = document.getElementById('payment-details');
            const qrisPayment = document.getElementById('qris-payment');
            const vaPayment = document.getElementById('va-payment');
            const ewalletPayment = document.getElementById('ewallet-payment');
            const codPayment = document.getElementById('cod-payment');

            // Sembunyikan semua metode pembayaran
            [qrisPayment, vaPayment, ewalletPayment, codPayment].forEach(el => {
                if (el) el.style.display = 'none';
            });

            // Tampilkan detail pembayaran
            paymentDetails.style.display = 'block';

            // Tampilkan metode yang dipilih
            switch(paymentSelect.value) {
                case 'qris':
                    qrisPayment.style.display = 'block';
                    break;
                case 'bca':
                case 'bni':
                case 'mandiri':
                    vaPayment.style.display = 'block';
                    generateVANumber(paymentSelect.value);
                    startVATimer();
                    break;
                case 'dana':
                case 'gopay':
                case 'ovo':
                    ewalletPayment.style.display = 'block';
                    setupEwallet(paymentSelect.value);
                    break;
                case 'cod':
                    codPayment.style.display = 'block';
                    break;
            }
        }

        function generateVANumber(bank) {
            // Simulasi generate VA number
            const bankCodes = {
                'bca': '82',
                'bni': '88',
                'mandiri': '89'
            };
            const randomNum = Math.floor(Math.random() * 10000000000000).toString().padStart(13, '0');
            const vaNumber = bankCodes[bank] + randomNum;
            document.getElementById('va-number').textContent = vaNumber;
        }

        fetch('/api/get-order-details')
    .then(response => response.json())
    .then(data => {
        // Isi elemen dengan data dari API
        document.getElementById('order-name').textContent = data.name;
        document.getElementById('order-email').textContent = data.email;
        document.getElementById('order-address').textContent = data.address;
        document.getElementById('order-shipping').textContent = data.shipping;
        document.getElementById('order-payment').textContent = data.payment;
        document.getElementById('order-total').textContent = data.total;

        // Tambahkan produk ke daftar
        const itemsList = document.getElementById('order-items-list');
        data.items.forEach(item => {
            const itemDiv = document.createElement('div');
            itemDiv.textContent = `${item.name} - ${item.price}`;
            itemsList.appendChild(itemDiv);
        });

        // Tampilkan elemen
        document.getElementById('processing-section').style.display = 'block';
    })
    .catch(error => console.error('Error:', error));


        function copyVANumber() {
            const vaNumber = document.getElementById('va-number').textContent;
            navigator.clipboard.writeText(vaNumber).then(() => {
                alert('Virtual Account Number copied to clipboard!');
            });
        }

        function startVATimer() {
            let hours = 1;
            let minutes = 0;
            let seconds = 0;
            
            const timer = setInterval(() => {
                if (hours === 0 && minutes === 0 && seconds === 0) {
                    clearInterval(timer);
                    alert('Virtual Account has expired!');
                    return;
                }

                if (seconds === 0) {
                    if (minutes === 0) {
                        hours--;
                        minutes = 59;
                    } else {
                        minutes--;
                    }
                    seconds = 59;
                } else {
                    seconds--;
                }

                document.getElementById('va-timer').textContent = 
                    `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }, 1000);
        }

        function setupEwallet(type) {
            const ewalletName = document.getElementById('ewallet-name');
            const ewalletQR = document.getElementById('ewallet-qr');
            
            // Set nama e-wallet
            ewalletName.textContent = type.toUpperCase();
            
            // Set QR code sesuai e-wallet
            switch(type) {
                case 'dana':
                    ewalletQR.src = 'img/WhatsApp Image 2024-12-11 at 00.14.43.jpeg';
                    break;
                case 'gopay':
                    ewalletQR.src = 'https://indonesiaberbagi.id/wp-content/uploads/2021/12/qr-code-gopay-2.jpeg';
                    break;
                case 'ovo':
                    ewalletQR.src = 'https://pbs.twimg.com/media/Dttq8EEUUAAhv_I.jpg'
                    break;
            }
        }

        function editProduct(id) {
    console.log('Editing product:', id);
    // ... kode lainnya ...
}

// Di form submit
document.getElementById('editProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    console.log('Form submitted');
    // ... kode lainnya ...
});

function showAddFeaturedProductModal() {
    document.getElementById('productForm').reset();
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

function addFeaturedProduct() {
    const productData = {
        name: document.getElementById('productName').value,
        price: document.getElementById('productPrice').value,
        image_url: document.getElementById('productImage').value,
        badge: document.getElementById('productBadge').value,
        rating: document.getElementById('productRating').value
    };

    fetch('add_featured_product.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(productData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error adding product: ' + data.message);
        }
    });
}

// Tambahkan fungsi addToCart yang baru
function addToCart(productId) {
    // Cek status login melalui AJAX
    fetch('check_login.php')
        .then(response => response.json())
        .then(data => {
            if (!data.isLoggedIn) {
                // Jika belum login, simpan product_id di session dan redirect ke register
                window.location.href = 'register.php?redirect=product&id=' + productId;
            } else {
                // Jika sudah login, lanjutkan proses add to cart
                addProductToCart(productId);
            }
        });
}

function showSuccessMessage() {
    const successMessage = document.createElement('div');
    successMessage.className = 'alert alert-success';
    successMessage.textContent = 'Pesanan Anda berhasil! Terima kasih telah berbelanja.';
    document.body.appendChild(successMessage);
}

// Fungsi untuk memproses checkout
function processCheckout(event) {
    event.preventDefault();
    
    // Tampilkan loading modal
    const processingModal = new bootstrap.Modal(document.getElementById('orderProcessingModal'));
    processingModal.show();

    // Ambil data form
    const formData = new FormData(document.getElementById('checkoutForm'));

    // Kirim data ke server
    fetch('process_checkout.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Tampilkan modal sukses
            processingModal.hide();
            showSuccessModal(data.order_id);
        } else {
            throw new Error(data.message);
        }
    })
    .catch(error => {
        processingModal.hide();
        alert('Error: ' + error.message);
    });
}

// Fungsi untuk menampilkan modal sukses
function showSuccessModal(orderId) {
    const successModal = `
        <div class="modal fade" id="successModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Order Successful!</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
                        <h4 class="mt-3">Thank you for your order!</h4>
                        <p>Order ID: ${orderId}</p>
                        <p>We will process your order shortly.</p>
                    </div>
                    <div class="modal-footer">
                        <a href="order_status.php?id=${orderId}" class="btn btn-primary">Track Order</a>
                        <a href="dashboard.php" class="btn btn-secondary">Continue Shopping</a>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Tambahkan modal ke document
    document.body.insertAdjacentHTML('beforeend', successModal);
    
    // Tampilkan modal
    const modal = new bootstrap.Modal(document.getElementById('successModal'));
    modal.show();

    // Redirect setelah modal ditutup
    document.getElementById('successModal').addEventListener('hidden.bs.modal', function () {
        window.location.href = 'order_status.php?id=' + orderId;
    });
}

// Tambahkan event listener untuk form checkout
document.getElementById('checkoutForm').addEventListener('submit', processCheckout);
    </script>

    <!-- Modal Processing -->
    <div class="modal fade" id="orderProcessingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h5>Processing Your Order</h5>
                    <p>Please wait while we process your order...</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>