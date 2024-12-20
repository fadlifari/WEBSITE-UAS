<?php
session_start();
require 'vendor/autoload.php';

// Redirect jika tidak ada order_success session
if (!isset($_SESSION['order_success'])) {
    header("Location: dashboard.php");
    exit();
}

// Ambil order ID dari session
$orderId = $_SESSION['order_id'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation - ShoeMart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="card text-center">
            <div class="card-body">
                <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
                <h2 class="card-title mt-3">Thank You for Your Order!</h2>
                <p class="card-text">Your order has been successfully placed.</p>
                <p class="card-text">Order ID: <?php echo $orderId; ?></p>
                <p class="card-text">We will process your order shortly.</p>
                <a href="dashboard.php" class="btn btn-primary">Back to Dashboard</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Hapus session setelah ditampilkan
unset($_SESSION['order_success']);
unset($_SESSION['order_id']);
?> 