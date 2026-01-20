<?php
require_once 'db.php';

echo "Attempting to connect to database...\n";
global $wpdb;

if ($wpdb) {
    echo "SUCCESS: Database helper initialized.\n";
    
    // Test Select
    $result = $wpdb->get_results("SELECT 1 as test");
    if ($result && $result[0]->test == 1) {
        echo "SUCCESS: Simple SELECT works.\n";
    } else {
        echo "FAILED: Simple SELECT failed.\n";
    }
    
    // Test Prepare
    $sql = $wpdb->prepare("SELECT %s as str, %d as num", 'test_string', 123);
    echo "Prepared SQL: " . $sql . "\n";
    
    // Check for double quotes
    if (strpos($sql, "''test_string''") !== false) {
        echo "FAILED: Double quotes detected!\n";
    } else if (strpos($sql, "'test_string'") !== false) {
        echo "SUCCESS: Quoting looks correct.\n";
    }
    
    // Execute Prepared
    $res = $wpdb->get_results($sql);
    if ($res && $res[0]->str == 'test_string') {
        echo "SUCCESS: Prepared query executed correctly.\n";
    } else {
        echo "FAILED: Prepared query execution failed.\n";
    }

} else {
    echo "FAILED: \$wpdb not available.\n";
}
