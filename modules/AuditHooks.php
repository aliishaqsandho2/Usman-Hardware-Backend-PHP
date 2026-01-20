<?php

// Audit Hooks - Extracted from usman_hardware.php
// This runs on module load, which is effectively "init" for our standalone app.

global $wpdb;

// Only set audit context if user is logged in
if (is_user_logged_in()) {
    $current_user = wp_get_current_user();
    
    // Set user context for audit logging
    if ($current_user && isset($current_user->ID)) {
        $wpdb->query($wpdb->prepare("SET @audit_user_id = %d", $current_user->ID));
        $wpdb->query($wpdb->prepare("SET @audit_user_login = %s", $current_user->user_login));
    }
    
    // Set IP address if available
    $ip_address = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip_address = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_address = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip_address = $_SERVER['REMOTE_ADDR'];
    }
    
    if ($ip_address) {
        $wpdb->query($wpdb->prepare("SET @audit_ip_address = %s", $ip_address));
    }
    
    // Set user agent if available
    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'], 0, 500); // Limit to 500 chars
        $wpdb->query($wpdb->prepare("SET @audit_user_agent = %s", $user_agent));
    }
}
