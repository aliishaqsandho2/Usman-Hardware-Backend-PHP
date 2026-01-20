<?php

// Database Configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'usmanhardware_inventory');
define('DB_USER', 'root');
define('DB_PASSWORD', 'password_here'); 
define('DB_CHARSET', 'utf8mb4');

// App Configuration
define('APP_URL', 'http://localhost:8000'); 
define('API_NAMESPACE', '/ims/v1');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable for production
