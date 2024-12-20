<?php
session_start();
// Cek apakah user sudah login dan role-nya admin
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

require 'vendor/autoload.php';

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    
    // Collections
    $productsCollection = $database->products;
    $categoriesCollection = $database->categories;
    $ordersCollection = $database->orders;
    
    // Mengambil statistik
    $totalProducts = $productsCollection->countDocuments();
    $totalOrders = $ordersCollection->countDocuments();
    
    // Menghitung total revenue
    $totalRevenue = $ordersCollection->aggregate([
        [
            '$group' => [
                '_id' => null,
                'total' => ['$sum' => '$total_amount']
            ]
        ]
    ])->toArray();
    
    $revenue = isset($totalRevenue[0]) ? $totalRevenue[0]['total'] : 0;

    // Mengambil daftar produk dengan kategorinya
    $products = $productsCollection->aggregate([
        [
            '$lookup' => [
                'from' => 'categories',         // Koleksi yang akan di-join (categories)
                'localField' => 'category_id',  // Field di koleksi produk
                'foreignField' => '_id',        // Field di koleksi categories
                'as' => 'category'              // Nama field hasil join
            ]
        ],
        [
            '$unwind' => '$category'            // Membuka array hasil lookup menjadi objek
        ]
    ])->toArray();

    // Perbaikan query aggregation
    $pipeline = [
        [
            '$lookup' => [
                'from' => 'users',           // Koleksi users
                'localField' => 'user_id',   // Field di koleksi orders
                'foreignField' => '_id',     // Field di koleksi users
                'as' => 'user'               // Nama field hasil join
            ]
        ],
        [
            '$lookup' => [
                'from' => 'products',                   // Koleksi products
                'localField' => 'products.product_id',  // Field di orders.products
                'foreignField' => '_id',                 // Field di koleksi products
                'as' => 'product_details'                // Nama field hasil join
            ]
        ],
        [
            '$sort' => ['created_at' => -1]
        ]
    ];

    $orders = $database->orders->aggregate($pipeline)->toArray();

    // Debug: Cek struktur data
    error_log('Orders data: ' . json_encode($orders));

} catch (Exception $e) {
    error_log("Error in aggregation: " . $e->getMessage());
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - ShoeMart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background-color: var(--primary-color);
            color: white;
            padding: 20px;
            position: fixed;
            height: 100vh;
        }

        .sidebar-header {
            padding: 20px 0;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-menu {
            margin-top: 20px;
        }

        .menu-item {
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            display: block;
            transition: 0.3s;
            border-radius: 5px;
        }

        .menu-item:hover {
            background-color: var(--secondary-color);
            color: white;
        }

        .menu-item.active {
            background-color: var(--accent-color);
        }

        /* Main Content Styles */
        .main-content {
            flex: 1;
            margin-left: 250px;
            padding: 20px;
        }

        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .stats-card h3 {
            margin: 0;
            color: var(--primary-color);
        }

        .stats-card p {
            font-size: 24px;
            font-weight: bold;
            margin: 10px 0 0;
            color: var(--accent-color);
        }

        /* Table Styles */
        .data-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .data-table th {
            background-color: var(--primary-color);
            color: white;
        }

        .action-btn {
            padding: 5px 10px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            margin-right: 5px;
        }

        .edit-btn {
            background-color: var(--accent-color);
            color: white;
        }

        .delete-btn {
            background-color: #e74c3c;
            color: white;
        }

        /* Modal Styles */
        .modal-header {
            background-color: var(--primary-color);
            color: white;
        }

        .modal-content {
            border-radius: 10px;
        }

        .btn-group {
            margin-bottom: 20px;
        }

        .btn-group .btn {
            margin-right: 5px;
        }

        .btn i {
            margin-right: 5px;
        }

        .container {
            margin-top: 20px;
        }

        .action-buttons {
            margin-bottom: 20px;
        }

        .action-buttons .btn {
            margin-right: 10px;
        }
    </style>
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>ShoeMart Admin</h3>
                <p>Welcome, <?php echo $_SESSION['admin_username']; ?></p>
            </div>
            <div class="sidebar-menu">
                <a href="#" class="menu-item active">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="#products" class="menu-item">
                    <i class="fas fa-box"></i> Products
                </a>
                <a href="#categories" class="menu-item">
                    <i class="fas fa-tags"></i> Categories
                </a>
                <a href="#orders" class="menu-item">
                    <i class="fas fa-shopping-cart"></i> Orders
                </a>
                <a href="logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Navigation Buttons -->
            <div class="container mt-4">
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="btn-group" role="group">
                            <button class="btn btn-primary" onclick="showDashboard()">
                                <i class="fas fa-home"></i> Dashboard
                            </button>
                            <button class="btn btn-primary" onclick="showProducts()">
                                <i class="fas fa-box"></i> Products
                            </button>
                            <button class="btn btn-primary" onclick="showCategories()">
                                <i class="fas fa-tags"></i> Categories
                            </button>
                            <button class="btn btn-primary" onclick="showOrders()">
                                <i class="fas fa-shopping-cart"></i> Orders
                            </button>
                            <button class="btn btn-primary" onclick="showUsers()">
                                <i class="fas fa-users"></i> Users
                            </button>
                            <button class="btn btn-danger" onclick="logout()">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons for Products -->
            <div class="container">
                <div class="row mb-4">
                    <div class="col-md-12">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#productModal">
                            <i class="fas fa-plus"></i> Add New Product
                        </button>
                        <button class="btn btn-info" onclick="refreshProductList()">
                            <i class="fas fa-sync"></i> Refresh List
                        </button>
                        <button class="btn btn-warning" onclick="exportProducts()">
                            <i class="fas fa-file-export"></i> Export Products
                        </button>
                    </div>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card">
                        <h3>Total Products</h3>
                        <p><?php echo $totalProducts; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <h3>Total Orders</h3>
                        <p><?php echo $totalOrders; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stats-card">
                        <h3>Total Revenue</h3>
                        <p>Rp <?php echo number_format($revenue, 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Featured Products Management Section -->
            <div class="container mt-5">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Featured Products Management</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#featuredProductModal" onclick="resetForm()">
                        <i class="fas fa-plus"></i> Add Featured Product
                    </button>
                </div>

                <!-- Featured Products Table -->
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Name</th>
                                <th>Original Price</th>
                                <th>Sale Price</th>
                                <th>Badge</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            try {
                                $featuredProducts = $database->featured_products->find();
                                foreach ($featuredProducts as $product) {
                                    $productId = (string)$product['_id'];
                            ?>
                                <tr>
                                    <td>
                                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" 
                                             alt="Product" 
                                             style="width: 50px; height: 50px; object-fit: cover;">
                                    </td>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td>Rp <?php echo number_format($product['original_price'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($product['sale_price'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($product['badge']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="editProduct('<?php echo $productId; ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="deleteProduct('<?php echo $productId; ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php
                                }
                            } catch (Exception $e) {
                                echo "<tr><td colspan='6'>Error: " . htmlspecialchars($e->getMessage()) . "</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Orders Section in dashboardadmin.php -->
            <div class="container mt-4">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Customer Orders</h3>
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <select class="form-select" id="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="processing">Processing</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="searchOrder" placeholder="Search by customer name or order ID">
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Customer Name</th>
                                        <th>Email</th>
                                        <th>Products</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                        <th>Order Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($orders)): ?>
                                        <?php foreach ($orders as $order): ?>
                                            <tr>
                                                <td><?php echo substr((string)$order['_id'], -8); ?></td>
                                                <td>
                                                    <?php 
                                                    echo isset($order['user'][0]['name']) 
                                                        ? htmlspecialchars($order['user'][0]['name']) 
                                                        : 'N/A'; 
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    echo isset($order['user'][0]['email']) 
                                                        ? htmlspecialchars($order['user'][0]['email']) 
                                                        : 'N/A'; 
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php if (isset($order['products']) && is_array($order['products'])): ?>
                                                        <ul class="list-unstyled">
                                                            <?php foreach ($order['products'] as $index => $product): ?>
                                                                <li>
                                                                    <?php
                                                                    $productName = isset($order['product_details'][$index]['name']) 
                                                                        ? $order['product_details'][$index]['name'] 
                                                                        : 'Unknown Product';
                                                                    echo htmlspecialchars($productName) . 
                                                                         ' (x' . $product['quantity'] . ')';
                                                                    ?>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <span>No products</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>Rp <?php echo number_format($order['total_amount'] ?? 0, 0, ',', '.'); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo getStatusColor($order['status'] ?? 'pending'); ?>">
                                                        <?php echo ucfirst($order['status'] ?? 'pending'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    echo isset($order['created_at']) 
                                                        ? date('d M Y H:i', $order['created_at']->toDateTime()->getTimestamp())
                                                        : 'N/A';
                                                    ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-primary btn-sm" onclick="viewOrderDetails('<?php echo $order['_id']; ?>')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button class="btn btn-success btn-sm" onclick="updateOrderStatus('<?php echo $order['_id']; ?>', 'completed')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="8" class="text-center">No orders found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            // Helper function untuk warna status
            function getStatusColor($status) {
                return match(strtolower($status)) {
                    'pending' => 'warning',
                    'processing' => 'info',
                    'completed' => 'success',
                    'cancelled' => 'danger',
                    default => 'secondary'
                };
            }

            function formatDate($mongoDate) {
                if ($mongoDate instanceof MongoDB\BSON\UTCDateTime) {
                    return $mongoDate->toDateTime()->format('d M Y H:i');
                }
                return 'N/A';
            }
            ?>
        </div>
    </div>

    <!-- Add/Edit Product Modal -->
    <div class="modal fade" id="productModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="productForm">
                        <input type="hidden" id="productId">
                        <div class="mb-3">
                            <label for="productName" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="productName" required>
                        </div>
                        <div class="mb-3">
                            <label for="productCategory" class="form-label">Category</label>
                            <select class="form-control" id="productCategory" required>
                                <option value="">Select Category</option>
                                <option value="Sneakers">Sneakers</option>
                                <option value="Running">Running</option>
                                <option value="Casual">Casual</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="productPrice" class="form-label">Price</label>
                            <input type="number" class="form-control" id="productPrice" required>
                        </div>
                        <div class="mb-3">
                            <label for="productStock" class="form-label">Stock</label>
                            <input type="number" class="form-control" id="productStock" required>
                        </div>
                        <div class="mb-3">
                            <label for="productImage" class="form-label">Image URL</label>
                            <input type="text" class="form-control" id="productImage" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="saveProduct()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Featured Product Modal -->
    <div class="modal fade" id="featuredProductModal" tabindex="-1" aria-labelledby="featuredModalTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="featuredModalTitle">Add Featured Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="featuredProductForm">
                        <input type="hidden" id="productId" name="productId">
                        <div class="mb-3">
                            <label for="productName" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="productName" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="originalPrice" class="form-label">Original Price</label>
                            <input type="number" class="form-control" id="originalPrice" name="original_price" required>
                        </div>
                        <div class="mb-3">
                            <label for="salePrice" class="form-label">Sale Price</label>
                            <input type="number" class="form-control" id="salePrice" name="sale_price" required>
                        </div>
                        <div class="mb-3">
                            <label for="badge" class="form-label">Badge</label>
                            <select class="form-control" id="badge" name="badge" required>
                                <option value="sale">Sale</option>
                                <option value="new">New</option>
                                <option value="hot">Hot</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="imageUrl" class="form-label">Image URL</label>
                            <input type="text" class="form-control" id="imageUrl" name="image_url" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="saveProduct()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript for CRUD Operations -->
    <script>
    function editProduct(productId) {
        document.getElementById('modalTitle').textContent = 'Edit Product';
        
        // Fetch product data
        fetch(`get_product.php?id=${productId}`)
            .then(response => response.json())
            .then(product => {
                document.getElementById('productId').value = product._id;
                document.getElementById('productName').value = product.name;
                document.getElementById('productCategory').value = product.category;
                document.getElementById('productPrice').value = product.price;
                document.getElementById('productStock').value = product.stock;
                document.getElementById('productImage').value = product.image_url;
                
                new bootstrap.Modal(document.getElementById('productModal')).show();
            });
    }

    function saveProduct() {
        const productData = {
            id: document.getElementById('productId').value,
            name: document.getElementById('productName').value,
            category: document.getElementById('productCategory').value,
            price: parseInt(document.getElementById('productPrice').value),
            stock: parseInt(document.getElementById('productStock').value),
            image_url: document.getElementById('productImage').value
        };

        // Validasi data
        if (!productData.name || !productData.price || !productData.stock) {
            alert('Please fill all required fields');
            return;
        }

        // Kirim data ke server
        fetch('save_product.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(productData)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert('Product saved successfully!');
                // Tutup modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('productModal'));
                modal.hide();
                // Refresh halaman
                location.reload();
            } else {
                alert('Error saving product: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error saving product: ' + error.message);
        });
    }

    // Fungsi untuk menangani klik tombol Add New Product
    document.addEventListener('DOMContentLoaded', function() {
        const addButton = document.querySelector('[data-bs-target="#productModal"]');
        if (addButton) {
            addButton.addEventListener('click', function() {
                // Reset form ketika membuka modal untuk menambah produk baru
                document.getElementById('productForm').reset();
                document.getElementById('productId').value = '';
                document.getElementById('modalTitle').textContent = 'Add New Product';
            });
        }
    });

    // Tambahkan event listener untuk form submit
    document.getElementById('productForm')?.addEventListener('submit', function(e) {
        e.preventDefault();
        saveProduct();
    });

    function deleteProduct(productId) {
        if (confirm('Are you sure you want to delete this product?')) {
            fetch('delete_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: productId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error deleting product: ' + data.message);
                }
            });
        }
    }

    function showDashboard() {
        window.location.href = 'dashboardadmin.php';
    }

    function showProducts() {
        window.location.href = 'dashboardadmin.php#products';
    }

    function showCategories() {
        window.location.href = 'categories.php';
    }

    function showOrders() {
        window.location.href = 'orders.php';
    }

    function showUsers() {
        window.location.href = 'users.php';
    }

    function logout() {
        if(confirm('Are you sure you want to logout?')) {
            window.location.href = 'logout.php';
        }
    }

    function refreshProductList() {
        location.reload();
    }

    function exportProducts() {
        window.location.href = 'export_products.php';
    }

    function addNewFeaturedProduct() {
        // Reset form
        document.getElementById('featuredProductForm').reset();
        document.getElementById('productId').value = '';
        document.getElementById('featuredModalTitle').textContent = 'Add Featured Product';
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('featuredProductModal'));
        modal.show();
    }

    // Event listeners for edit and delete buttons
    document.addEventListener('DOMContentLoaded', function() {
        // Edit button listeners
        document.querySelectorAll('.edit-featured-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                editFeaturedProduct(id);
            });
        });

        // Delete button listeners
        document.querySelectorAll('.delete-featured-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                deleteFeaturedProduct(id);
            });
        });

        // Form submit handler
        document.getElementById('featuredProductForm').addEventListener('submit', function(e) {
            e.preventDefault();
            saveFeaturedProduct();
        });
    });

    function editFeaturedProduct(id) {
        fetch(`get_featured_product.php?id=${id}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(product => {
                document.getElementById('featuredProductId').value = product._id;
                document.getElementById('featuredProductName').value = product.name;
                document.getElementById('featuredProductOriginalPrice').value = product.original_price;
                document.getElementById('featuredProductSalePrice').value = product.sale_price;
                document.getElementById('featuredProductBadge').value = product.badge;
                document.getElementById('featuredProductImage').value = product.image_url;
                
                document.getElementById('featuredModalTitle').textContent = 'Edit Featured Product';
                
                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('featuredProductModal'));
                modal.show();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading product data: ' + error.message);
            });
    }

    function saveFeaturedProduct() {
        const productData = {
            id: document.getElementById('featuredProductId').value,
            name: document.getElementById('featuredProductName').value,
            original_price: parseInt(document.getElementById('featuredProductOriginalPrice').value),
            sale_price: parseInt(document.getElementById('featuredProductSalePrice').value),
            badge: document.getElementById('featuredProductBadge').value,
            image_url: document.getElementById('featuredProductImage').value
        };

        const url = productData.id ? 'update_featured_product.php' : 'add_featured_product.php';

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(productData)
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert(data.message || 'Product saved successfully!');
                location.reload();
            } else {
                throw new Error(data.message || 'Failed to save product');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error saving product: ' + error.message);
        });
    }

    function deleteFeaturedProduct(id) {
        if (confirm('Are you sure you want to delete this featured product?')) {
            fetch('delete_featured_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    alert('Product deleted successfully');
                    location.reload();
                } else {
                    throw new Error(data.message || 'Failed to delete product');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting product: ' + error.message);
            });
        }
    }

    function viewOrderDetails(orderId) {
        const modalId = `orderDetailsModal${orderId.substr(-8)}`;
        const modal = new bootstrap.Modal(document.getElementById(modalId));
        modal.show();
    }

    // Filter functionality
    document.getElementById('statusFilter').addEventListener('change', filterOrders);
    document.getElementById('searchOrder').addEventListener('input', filterOrders);

    function filterOrders() {
        const status = document.getElementById('statusFilter').value.toLowerCase();
        const search = document.getElementById('searchOrder').value.toLowerCase();
        
        document.querySelectorAll('tbody tr').forEach(row => {
            const statusText = row.querySelector('td:nth-child(6)').textContent.toLowerCase();
            const rowText = row.textContent.toLowerCase();
            
            const statusMatch = !status || statusText.includes(status);
            const searchMatch = !search || rowText.includes(search);
            
            row.style.display = statusMatch && searchMatch ? '' : 'none';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Add Featured Product button
        document.querySelector('.btn-primary[onclick="addNewFeaturedProduct()"]')?.addEventListener('click', addNewFeaturedProduct);

        // Edit buttons
        document.querySelectorAll('[data-bs-toggle="modal"][data-bs-target="#featuredProductModal"]').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.closest('tr').dataset.id;
                editFeaturedProduct(id);
            });
        });

        // Delete buttons
        document.querySelectorAll('.delete-featured-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.closest('tr').dataset.id;
                deleteFeaturedProduct(id);
            });
        });
    });

    // Function to reset form when adding new product
    function resetForm() {
        document.getElementById('featuredProductForm').reset();
        document.getElementById('productId').value = '';
        document.getElementById('featuredModalTitle').textContent = 'Add Featured Product';
    }

    // Function to edit product
    function editProduct(id) {
        fetch(`get_featured_product.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('productId').value = id;
                document.getElementById('productName').value = data.name;
                document.getElementById('originalPrice').value = data.original_price;
                document.getElementById('salePrice').value = data.sale_price;
                document.getElementById('badge').value = data.badge;
                document.getElementById('imageUrl').value = data.image_url;
                
                document.getElementById('featuredModalTitle').textContent = 'Edit Featured Product';
                
                // Show modal
                new bootstrap.Modal(document.getElementById('featuredProductModal')).show();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error loading product data');
            });
    }

    // Function to save product (both add and edit)
    function saveProduct() {
        const formData = {
            id: document.getElementById('productId').value,
            name: document.getElementById('productName').value,
            original_price: parseInt(document.getElementById('originalPrice').value),
            sale_price: parseInt(document.getElementById('salePrice').value),
            badge: document.getElementById('badge').value,
            image_url: document.getElementById('imageUrl').value
        };

        const url = formData.id ? 'update_featured_product.php' : 'add_featured_product.php';

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message || 'Product saved successfully!');
                location.reload();
            } else {
                alert(data.message || 'Failed to save product');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error saving product');
        });
    }

    // Function to delete product
    function deleteProduct(id) {
        if (confirm('Are you sure you want to delete this product?')) {
            fetch('delete_featured_product.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Product deleted successfully');
                    location.reload();
                } else {
                    alert(data.message || 'Failed to delete product');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting product');
            });
        }
    }

    // Initialize tooltips and other Bootstrap components
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });

    // Fungsi untuk filter orders
    document.getElementById('statusFilter').addEventListener('change', filterOrders);
    document.getElementById('searchOrder').addEventListener('input', filterOrders);

    function filterOrders() {
        const status = document.getElementById('statusFilter').value.toLowerCase();
        const search = document.getElementById('searchOrder').value.toLowerCase();
        
        document.querySelectorAll('tbody tr').forEach(row => {
            const statusText = row.querySelector('td:nth-child(6)').textContent.toLowerCase();
            const rowText = row.textContent.toLowerCase();
            
            const statusMatch = !status || statusText.includes(status);
            const searchMatch = !search || rowText.includes(search);
            
            row.style.display = statusMatch && searchMatch ? '' : 'none';
        });
    }

    // Fungsi untuk melihat detail order
    function viewOrderDetails(orderId) {
        // Implementasi view detail
        console.log('Viewing order:', orderId);
    }

    // Fungsi untuk update status
    function updateOrderStatus(orderId, newStatus) {
        if (confirm('Are you sure you want to update this order status?')) {
            fetch('update_order_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    order_id: orderId,
                    status: newStatus
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Order status updated successfully');
                    location.reload();
                } else {
                    alert('Error updating order status: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to update order status');
            });
        }
    }
    </script>
</body>
</html>