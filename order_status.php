<?php
session_start();
require 'vendor/autoload.php';

if (!isset($_GET['id'])) {
    header("Location: dashboard.php");
    exit();
}

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;

    $order = $database->orders->findOne([
        '_id' => new MongoDB\BSON\ObjectId($_GET['id'])
    ]);

    if (!$order) {
        throw new Exception("Order not found");
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Status - ShoeMart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="card">
            <div class="card-header">
                <h3>Order Status</h3>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5>Order Details</h5>
                        <p>Order ID: <?php echo $order['_id']; ?></p>
                        <p>Date: <?php echo $order['created_at']->toDateTime()->format('d M Y H:i'); ?></p>
                        <p>Status: <span class="badge bg-<?php echo getStatusColor($order['status']); ?>"><?php echo ucfirst($order['status']); ?></span></p>
                    </div>
                    <div class="col-md-6">
                        <h5>Shipping Address</h5>
                        <p><?php echo $order['shipping_address']['street']; ?></p>
                        <p><?php echo $order['shipping_address']['city']; ?>, <?php echo $order['shipping_address']['province']; ?></p>
                        <p><?php echo $order['shipping_address']['postal_code']; ?></p>
                    </div>
                </div>

                <div class="order-tracking mb-4">
                    <div class="progress" style="height: 5px;">
                        <div class="progress-bar" role="progressbar" style="width: <?php echo getProgressWidth($order['status']); ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-3">
                        <div class="text-center">
                            <i class="fas fa-check-circle <?php echo isStepComplete($order['status'], 'pending') ? 'text-success' : 'text-secondary'; ?>"></i>
                            <p>Order Placed</p>
                        </div>
                        <div class="text-center">
                            <i class="fas fa-box <?php echo isStepComplete($order['status'], 'processing') ? 'text-success' : 'text-secondary'; ?>"></i>
                            <p>Processing</p>
                        </div>
                        <div class="text-center">
                            <i class="fas fa-shipping-fast <?php echo isStepComplete($order['status'], 'shipped') ? 'text-success' : 'text-secondary'; ?>"></i>
                            <p>Shipped</p>
                        </div>
                        <div class="text-center">
                            <i class="fas fa-check <?php echo isStepComplete($order['status'], 'completed') ? 'text-success' : 'text-secondary'; ?>"></i>
                            <p>Delivered</p>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($order['products'] as $product): ?>
                            <tr>
                                <td><?php echo $product['name']; ?></td>
                                <td><?php echo $product['quantity']; ?></td>
                                <td>Rp <?php echo number_format($product['price'], 0, ',', '.'); ?></td>
                                <td>Rp <?php echo number_format($product['price'] * $product['quantity'], 0, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="3">Total</th>
                                <th>Rp <?php echo number_format($order['total_amount'], 0, ',', '.'); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="text-center mt-4">
                    <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
function getStatusColor($status) {
    return match($status) {
        'pending' => 'warning',
        'processing' => 'info',
        'shipped' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
        default => 'secondary'
    };
}

function getProgressWidth($status) {
    return match($status) {
        'pending' => 25,
        'processing' => 50,
        'shipped' => 75,
        'completed' => 100,
        default => 0
    };
}

function isStepComplete($currentStatus, $step) {
    $steps = ['pending' => 1, 'processing' => 2, 'shipped' => 3, 'completed' => 4];
    return $steps[$currentStatus] >= $steps[$step];
}
?> 