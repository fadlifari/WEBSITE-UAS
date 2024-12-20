<?php
require 'vendor/autoload.php';
session_start();

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
    $usersCollection = $database->users;
    $adminCollection = $database->admins;

    // Handle User Login
    if (isset($_POST['login_type']) && $_POST['login_type'] === 'user') {
        $email = filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL);
        $password = trim($_POST["password"]);

        if (!$email) {
            $_SESSION['login_error'] = "Please enter a valid email address.";
        } else {
            try {
                $user = $usersCollection->findOne(['email' => $email]);
                if ($user && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (string)$user['_id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = 'customer';
                    
                    // Redirect ke dashboard customer
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $_SESSION['login_error'] = "Invalid email or password.";
                }
            } catch (Exception $e) {
                $_SESSION['login_error'] = "System error. Please try again later.";
            }
        }
    }

    // Handle Admin Login
    if (isset($_POST['login_type']) && $_POST['login_type'] === 'admin') {
        $username = trim($_POST["username"]);
        $password = trim($_POST["password"]);

        try {
            $admin = $adminCollection->findOne(['username' => $username]);
            if ($admin && $password === $admin['password']) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (string)$admin['_id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_role'] = 'admin';
                
                // Redirect ke dashboard admin
                header("Location: dashboardadmin.php");
                exit();
            } else {
                $_SESSION['login_error'] = "Invalid admin credentials.";
            }
        } catch (Exception $e) {
            $_SESSION['login_error'] = "System error. Please try again later.";
        }
    }

    // Handle Register
    if (isset($_POST['register'])) {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        
        // Cek email sudah terdaftar
        $existingUser = $usersCollection->findOne(['email' => $email]);
        if ($existingUser) {
            $_SESSION['login_error'] = "Email already registered!";
        } else {
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert user baru
            $result = $usersCollection->insertOne([
                'name' => $name,
                'email' => $email,
                'password' => $hashedPassword,
                'role' => 'customer',
                'status' => 'active',
                'created_at' => new MongoDB\BSON\UTCDateTime(),
                'updated_at' => new MongoDB\BSON\UTCDateTime()
            ]);

            if ($result->getInsertedCount()) {
                // Set session untuk auto login
                $_SESSION['user_id'] = (string)$result->getInsertedId();
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = 'customer';

                // Redirect ke dashboard
                header("Location: dashboard.php");
                exit();
            }
        }
    }

} catch (Exception $e) {
    $_SESSION['login_error'] = "System error. Please try again later.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ShoeMart Login</title>
    <link crossorigin="anonymous" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" rel="stylesheet"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <style>
        body {
            background-color: #f5e9e2;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background-color: white;
            border-radius: 20px;
            padding: 30px;
            width: 300px;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .login-container h1 {
            font-family: 'Arial', sans-serif;
            font-size: 24px;
            color: #8b5e34;
            margin-bottom: 10px;
        }
        .form-control {
            border-radius: 20px;
            background-color: #f0f0f0;
            border: none;
            margin-bottom: 15px;
            padding-left: 40px;
        }
        .btn {
            width: 100%;
            border-radius: 20px;
            margin-bottom: 10px;
        }
        .btn-user {
            background-color: #8b5e34;
            color: white;
        }
        .btn-admin {
            background-color: #2c3e50;
            color: white;
        }
        .login-type {
            display: none;
        }
        .login-type.active {
            display: block;
        }
        .switch-form {
            color: #8b5e34;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
        }
        .error-message {
            color: red;
            margin-bottom: 10px;
        }
        .mt-3 {
            margin-top: 1rem;
        }
        
        .text-primary {
            color: #8b5e34 !important;
            text-decoration: none;
        }
        
        .text-primary:hover {
            text-decoration: underline;
        }
        
        .forgot-password {
            margin-bottom: 10px;
        }
        
        .alert {
            margin-bottom: 20px;
            padding: 10px;
            border-radius: 10px;
            font-size: 14px;
        }
        
        .alert-success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>ShoeMart.</h1>
        <?php if (isset($_SESSION['register_success'])): ?>
            <div class="alert alert-success">
                <?= $_SESSION['register_success']; unset($_SESSION['register_success']); ?>
            </div>
        <?php endif; ?>
        <p>Log in on ShoeMart :)</p>
        <img alt="Shoe Image" height="100" src="img/Adidas-ZX-1--unscreen.gif" width="100"/>

        <?php if (isset($_SESSION['login_error'])): ?>
            <div class="error-message">
                <?= $_SESSION['login_error']; unset($_SESSION['login_error']); ?>
            </div>
        <?php endif; ?>

        <!-- User Login Form -->
        <form id="userLoginForm" class="login-type active" method="post">
            <input type="hidden" name="login_type" value="user">
            <div class="input-group mb-3">
                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                <input class="form-control" name="email" placeholder="shoemart@gmail.com" type="email" required/>
            </div>
            <div class="input-group mb-3">
                <span class="input-group-text"><i class="fas fa-key"></i></span>
                <input class="form-control" name="password" placeholder="******" type="password" required/>
            </div>
            <button class="btn btn-user" type="submit">LOGIN WITH EMAIL</button>
        </form>

        <!-- Admin Login Form -->
        <form id="adminLoginForm" class="login-type" method="post">
            <input type="hidden" name="login_type" value="admin">
            <div class="input-group mb-3">
                <span class="input-group-text"><i class="fas fa-user"></i></span>
                <input class="form-control" name="username" placeholder="Admin Username" type="text" required/>
            </div>
            <div class="input-group mb-3">
                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                <input class="form-control" name="password" placeholder="Admin Password" type="password" required/>
            </div>
            <button class="btn btn-admin" type="submit">ADMIN LOGIN</button>
        </form>

        <a class="switch-form" onclick="toggleLoginForm()">
            <span id="switchText">Switch to Admin Login</span>
        </a>

        <p class="forgot-password">Forgot Password? <a href="forgot-password.php">Click Here</a></p>

        <div class="mt-3">
            <p class="mb-0">Don't have an account? 
                <a href="register.php" class="text-primary">Register here</a>
            </p>
        </div>
    </div>

    <script>
        function toggleLoginForm() {
            const userForm = document.getElementById('userLoginForm');
            const adminForm = document.getElementById('adminLoginForm');
            const switchText = document.getElementById('switchText');

            if (userForm.classList.contains('active')) {
                userForm.classList.remove('active');
                adminForm.classList.add('active');
                switchText.textContent = 'Switch to User Login';
            } else {
                adminForm.classList.remove('active');
                userForm.classList.add('active');
                switchText.textContent = 'Switch to Admin Login';
            }

        }
    </script>
</body>
</html>
