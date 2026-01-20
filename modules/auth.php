<?php

require_once __DIR__ . '/../AuthCore.php';

// Register routes
add_action('rest_api_init', function() {
    register_rest_route('ims/v1', '/auth/login', [
        'methods' => 'POST',
        'callback' => 'ims_auth_login',
        'permission_callback' => '__return_true' // Public
    ]);
    
    register_rest_route('ims/v1', '/auth/logout', [
        'methods' => 'POST',
        'callback' => 'ims_auth_logout',
        'permission_callback' => '__return_true' // Public (validates token inside)
    ]);
    
    register_rest_route('ims/v1', '/auth/me', [
        'methods' => 'GET',
        'callback' => 'ims_auth_me',
        'permission_callback' => 'ims_auth_check'
    ]);
});

/**
 * POST /auth/login
 */
function ims_auth_login($request) {
    global $auth;
    $params = $request->get_json_params();
    
    if (empty($params['username']) || empty($params['password'])) {
        return new WP_Error('missing_credentials', 'Username and Password are required', ['status' => 400]);
    }
    
    $device_info = [
        'type' => $params['deviceType'] ?? 'desktop',
        'name' => $params['deviceName'] ?? 'Unknown',
        'id' => $params['deviceId'] ?? 'unknown'
    ];
    
    $result = $auth->login($params['username'], $params['password'], $device_info);
    
    if (is_wp_error($result)) {
        return $result;
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Login successful',
        'data' => $result
    ], 200);
}

/**
 * POST /auth/logout
 */
function ims_auth_logout($request) {
    global $auth;
    
    // Extract token from header
    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    
    if ($token) {
        $auth->logout($token);
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Logged out successfully'
    ], 200);
}

/**
 * GET /auth/me
 */
function ims_auth_me($request) {
    global $auth;
    $user = $auth->get_current_user();
    
    if (!$user) {
         return new WP_Error('unauthorized', 'Not logged in', ['status' => 401]);
    }
    
    // Get full permissions
    global $wpdb;
    // We can rely on AuthCore helper, but it's private. Let's make it public or re-query.
    // Ideally AuthCore should expose getUserDetails.
    // For now, reconstruct.
    
    // Re-use logic from login response for consistency
    // But we need to use public methods.
    // Let's assume user object is basic.
    
    // We need permissions again.
    // Creating a quick helper in this file or reusing.
    // Actually, $auth->login returns it.
    
    // Let's fix AuthCore to store permissions on currentUser or expose method.
    // For now, minimal response.
    
    return new WP_REST_Response([
        'success' => true,
        'data' => [
            'id' => (int)$user->id,
            'username' => $user->username,
            'email' => $user->email,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'role' => $user->role ?? 'unknown', // Need to populate this in middleware
            // Active session info
            'lastLogin' => $user->last_login
        ]
    ], 200);
}

/**
 * Permission Callback
 */
function ims_auth_check() {
    global $router, $auth;
    // Middleware should have run by now and set $auth->isAuthenticated
    // But we need to verify token here if not globally done.
    
    // Extract token
    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    
    if ($auth->validate_session($token)) {
        return true;
    }
    
    return false;
}
