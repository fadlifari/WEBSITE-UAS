<?php
require 'vendor/autoload.php';

try {
    $client = new MongoDB\Client("mongodb://localhost:27017");
    $database = $client->shoemart_db;
} catch (Exception $e) {
    die("Error connecting to MongoDB: " . $e->getMessage());
}
?>
