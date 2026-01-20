<?php

// Register Routes
add_action('rest_api_init', function() {
    // Users CRUD
    register_rest_route('ims/v1', '/users', [
        'methods' => 'GET',
        'callback' => 'ims_get_users',
        'permission_callback' => function() { return current_user_can('users.read'); }
    ]);

    register_rest_route('ims/v1', '/users', [
        'methods' => 'POST',
        'callback' => 'ims_create_user',
        'permission_callback' => function() { return current_user_can('users.create'); }
    ]);
    
    register_rest_route('ims/v1', '/users/(?P<id>\d+)', [
        'methods' => 'PUT',
        'callback' => 'ims_update_user',
        'permission_callback' => function() { return current_user_can('users.update'); }
    ]);
    
    // Roles
    register_rest_route('ims/v1', '/roles', [
        'methods' => 'GET',
        'callback' => 'ims_get_roles',
        'permission_callback' => function() { return current_user_can('users.manage_roles'); }
    ]);
});

/**
 * GET /users
 */
function ims_get_users($request) {
    global $wpdb;
    
    // Simple fetch
    $users = $wpdb->get_results("SELECT id, username, email, first_name, last_name, status, last_login FROM {$wpdb->prefix}users WHERE deleted_at IS NULL");
    
    // Enrich with roles (N+1 prob but ok for now)
    foreach ($users as $u) {
        $roles = $wpdb->get_col($wpdb->prepare(
            "SELECT r.display_name FROM {$wpdb->prefix}ims_roles r
             JOIN {$wpdb->prefix}ims_user_roles ur ON r.id = ur.role_id
             WHERE ur.user_id = %d", $u->id
        ));
        $u->roles = $roles;
    }

    return new WP_REST_Response(['success' => true, 'data' => $users], 200);
}

/**
 * POST /users
 */
function ims_create_user($request) {
    global $wpdb;
    $p = $request->get_json_params();
    
    if (empty($p['username']) || empty($p['email']) || empty($p['password'])) {
        return new WP_Error('missing_params', 'Username, Email, Password required', ['status' => 400]);
    }
    
    // Hash Pwd
    $hash = password_hash($p['password'], PASSWORD_BCRYPT);
    
    $res = $wpdb->insert("{$wpdb->prefix}users", [
        'username' => $p['username'],
        'email' => $p['email'],
        'password_hash' => $hash,
        'first_name' => $p['firstName'] ?? '',
        'last_name' => $p['lastName'] ?? '',
        'status' => 'active',
        'email_verified' => 1 // Auto verify for admin created
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%d']);
    
    if (!$res) return new WP_Error('db_error', $wpdb->last_error, ['status' => 500]);
    
    $uid = $wpdb->insert_id;
    
    // Assign Role
    if (!empty($p['role'])) {
        $rid = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ims_roles WHERE name = %s", $p['role']));
        if ($rid) {
            $wpdb->insert("{$wpdb->prefix}ims_user_roles", ['user_id' => $uid, 'role_id' => $rid], ['%d', '%d']);
        }
    }
    
    return new WP_REST_Response(['success' => true, 'id' => $uid], 201);
}

/**
 * GET /roles
 */
function ims_get_roles() {
    global $wpdb;
    $roles = $wpdb->get_results("SELECT id, name, display_name FROM {$wpdb->prefix}ims_roles WHERE status = 'active'");
    return new WP_REST_Response(['success' => true, 'data' => $roles], 200);
}
