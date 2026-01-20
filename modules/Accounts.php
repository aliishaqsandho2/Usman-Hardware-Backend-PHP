<?php
// Add to your theme's functions.php or a custom plugin

// Register REST API routes
add_action('rest_api_init', 'register_ims_rest_routes');

function register_ims_rest_routes() {
    // Accounts endpoints
    register_rest_route('ims/v1', '/accounts', array(
        array(
            'methods' => 'GET',
            'callback' => 'get_accounts',
            'permission_callback' => '__return_true'
        ),
        array(
            'methods' => 'POST',
            'callback' => 'create_account',
            'permission_callback' => '__return_true'
        )
    ));
    
    register_rest_route('ims/v1', '/accounts/(?P<id>\d+)', array(
        array(
            'methods' => 'GET',
            'callback' => 'get_single_account',
            'permission_callback' => '__return_true'
        ),
        array(
            'methods' => 'PUT',
            'callback' => 'update_account',
            'permission_callback' => '__return_true'
        ),
        array(
            'methods' => 'DELETE',
            'callback' => 'delete_account',
            'permission_callback' => '__return_true'
        )
    ));
    
    register_rest_route('ims/v1', '/accounts/summary', array(
        'methods' => 'GET',
        'callback' => 'get_accounts_summary',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/accounts/(?P<id>\d+)/balance', array(
        'methods' => 'POST',
        'callback' => 'update_account_balance',
        'permission_callback' => '__return_true'
    ));

    // Transactions endpoints
    register_rest_route('ims/v1', '/transactions', array(
        array(
            'methods' => 'GET',
            'callback' => 'get_transactions',
            'permission_callback' => '__return_true'
        ),
        array(
            'methods' => 'POST',
            'callback' => 'create_transaction',
            'permission_callback' => '__return_true'
        )
    ));

    // Cash Flow endpoints
    register_rest_route('ims/v1', '/cash-flow', array(
        array(
            'methods' => 'GET',
            'callback' => 'get_cash_flow',
            'permission_callback' => '__return_true'
        ),
        array(
            'methods' => 'POST',
            'callback' => 'create_cash_flow',
            'permission_callback' => '__return_true'
        )
    ));
}

// Global database helper function
function get_ims_db() {
    global $wpdb;
    return $wpdb;
}

// Helper function for API responses
function ims_api_response($data = null, $message = '', $status = 200, $success = true) {
    $response = array(
        'success' => $success,
        'message' => $message,
        'data' => $data
    );
    
    return new WP_REST_Response($response, $status);
}

// Helper function for error responses
function ims_api_error($message, $status = 400) {
    return ims_api_response(null, $message, $status, false);
}

// GET /accounts - List all accounts
function get_accounts($request) {
    global $wpdb;
    
    $params = $request->get_params();
    $page = max(1, intval($params['page'] ?? 1));
    $limit = min(100, max(1, intval($params['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    $where_conditions = array('1=1');
    $query_params = array();
    
    // Filter by account type
    if (!empty($params['type'])) {
        $where_conditions[] = 'account_type = %s';
        $query_params[] = sanitize_text_field($params['type']);
    }
    
    // Filter by active status
    if (isset($params['active'])) {
        $active = filter_var($params['active'], FILTER_VALIDATE_BOOLEAN);
        $where_conditions[] = 'is_active = %d';
        $query_params[] = $active ? 1 : 0;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ims_accounts WHERE {$where_clause}";
    if (!empty($query_params)) {
        $count_query = $wpdb->prepare($count_query, $query_params);
    }
    $total = $wpdb->get_var($count_query);
    
    // Get accounts
    $query = "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d";
    $query_params[] = $limit;
    $query_params[] = $offset;
    
    $accounts = $wpdb->get_results($wpdb->prepare($query, $query_params));
    
    $pagination = array(
        'page' => $page,
        'limit' => $limit,
        'total' => intval($total),
        'pages' => ceil($total / $limit)
    );
    
    return ims_api_response(array(
        'accounts' => $accounts,
        'pagination' => $pagination
    ), 'Accounts retrieved successfully');
}

// POST /accounts - Create account
function create_account($request) {
    global $wpdb;
    
    $params = $request->get_json_params();
    
    // Validate required fields
    $required_fields = array('account_code', 'account_name', 'account_type');
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return ims_api_error("Missing required field: {$field}");
        }
    }
    
    // Validate account type
    $valid_types = array('asset', 'liability', 'equity', 'revenue', 'expense', 'bank', 'cash');
    if (!in_array($params['account_type'], $valid_types)) {
        return ims_api_error('Invalid account type');
    }
    
    // Check if account code already exists
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_accounts WHERE account_code = %s",
        sanitize_text_field($params['account_code'])
    ));
    
    if ($existing) {
        return ims_api_error('Account code already exists');
    }
    
    $data = array(
        'account_code' => sanitize_text_field($params['account_code']),
        'account_name' => sanitize_text_field($params['account_name']),
        'account_type' => sanitize_text_field($params['account_type']),
        'balance' => floatval($params['balance'] ?? 0.00),
        'is_active' => isset($params['is_active']) ? (bool)$params['is_active'] : true,
        'created_at' => current_time('mysql')
    );
    
    $result = $wpdb->insert("{$wpdb->prefix}ims_accounts", $data);
    
    if ($result === false) {
        return ims_api_error('Failed to create account');
    }
    
    $account_id = $wpdb->insert_id;
    $new_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d", $account_id
    ));
    
    return ims_api_response($new_account, 'Account created successfully', 201);
}

// GET /accounts/{id} - Get single account
function get_single_account($request) {
    global $wpdb;
    
    $account_id = $request['id'];
    
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d", $account_id
    ));
    
    if (!$account) {
        return ims_api_error('Account not found', 404);
    }
    
    return ims_api_response($account, 'Account retrieved successfully');
}

// PUT /accounts/{id} - Update account (FIXED)
function update_account($request) {
    global $wpdb;
    
    $account_id = $request['id'];
    $params = $request->get_json_params();
    
    // Check if account exists
    $existing_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d", $account_id
    ));
    
    if (!$existing_account) {
        return ims_api_error('Account not found', 404);
    }
    
    $data = array();
    $allowed_fields = array('account_code', 'account_name', 'account_type', 'balance', 'is_active');
    
    foreach ($allowed_fields as $field) {
        if (isset($params[$field])) {
            if ($field === 'account_type') {
                $valid_types = array('asset', 'liability', 'equity', 'revenue', 'expense', 'bank', 'cash');
                if (!in_array($params[$field], $valid_types)) {
                    return ims_api_error('Invalid account type');
                }
            }
            
            if ($field === 'balance') {
                $data[$field] = floatval($params[$field]);
            } elseif ($field === 'is_active') {
                $data[$field] = (bool)$params[$field] ? 1 : 0;
            } else {
                $data[$field] = sanitize_text_field($params[$field]);
            }
        }
    }
    
    // Check if account code is being changed and if it already exists
    if (isset($data['account_code']) && $data['account_code'] !== $existing_account->account_code) {
        $existing_code = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ims_accounts WHERE account_code = %s AND id != %d",
            $data['account_code'], $account_id
        ));
        
        if ($existing_code) {
            return ims_api_error('Account code already exists');
        }
    }
    
    if (empty($data)) {
        return ims_api_error('No valid fields to update');
    }
    
    $result = $wpdb->update(
        "{$wpdb->prefix}ims_accounts",
        $data,
        array('id' => $account_id),
        array('%s', '%s', '%s', '%f', '%d') // Format specifiers
    );
    
    if ($result === false) {
        return ims_api_error('Failed to update account: ' . $wpdb->last_error);
    }
    
    $updated_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d", $account_id
    ));
    
    return ims_api_response($updated_account, 'Account updated successfully');
}

// DELETE /accounts/{id} - Delete account (FIXED)
function delete_account($request) {
    global $wpdb;
    
    $account_id = $request['id'];
    
    // Check if account exists
    $existing_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d", $account_id
    ));
    
    if (!$existing_account) {
        return ims_api_error('Account not found', 404);
    }
    
    // Check if account has related records in other tables (soft check)
    $tables_to_check = array(
        'ims_cash_flow' => 'account_id',
        'ims_expenses' => 'account_id',
        'ims_payments' => 'account_id'
    );
    
    foreach ($tables_to_check as $table => $column) {
        $has_records = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", 
            $account_id
        ));
        
        if ($has_records > 0) {
            return ims_api_error("Cannot delete account. It has related records in {$table} table.");
        }
    }
    
    $result = $wpdb->delete(
        "{$wpdb->prefix}ims_accounts",
        array('id' => $account_id),
        array('%d')
    );
    
    if ($result === false) {
        return ims_api_error('Failed to delete account: ' . $wpdb->last_error);
    }
    
    return ims_api_response(null, 'Account deleted successfully');
}

// GET /accounts/summary - Get accounts summary
function get_accounts_summary($request) {
    global $wpdb;
    
    $summary = $wpdb->get_results("
        SELECT 
            account_type,
            COUNT(*) as total_accounts,
            SUM(balance) as total_balance,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_accounts,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_accounts
        FROM {$wpdb->prefix}ims_accounts 
        GROUP BY account_type
    ");
    
    $overall = $wpdb->get_row("
        SELECT 
            COUNT(*) as total_accounts,
            SUM(balance) as overall_balance,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as total_active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as total_inactive
        FROM {$wpdb->prefix}ims_accounts
    ");
    
    return ims_api_response(array(
        'summary_by_type' => $summary,
        'overall' => $overall
    ), 'Accounts summary retrieved successfully');
}

// POST /accounts/{id}/balance - Update account balance (NEW)
function update_account_balance($request) {
    global $wpdb;
    
    $account_id = $request['id'];
    $params = $request->get_json_params();
    
    // Check if account exists
    $existing_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d", $account_id
    ));
    
    if (!$existing_account) {
        return ims_api_error('Account not found', 404);
    }
    
    if (!isset($params['balance']) || !is_numeric($params['balance'])) {
        return ims_api_error('Valid balance amount is required');
    }
    
    $new_balance = floatval($params['balance']);
    $adjustment_reason = sanitize_text_field($params['reason'] ?? 'Manual balance adjustment');
    
    // Update account balance
    $result = $wpdb->update(
        "{$wpdb->prefix}ims_accounts",
        array('balance' => $new_balance),
        array('id' => $account_id),
        array('%f'),
        array('%d')
    );
    
    if ($result === false) {
        return ims_api_error('Failed to update account balance: ' . $wpdb->last_error);
    }
    
    // Create a transaction record for the balance adjustment
    $transaction_number = 'ADJ_' . time() . '_' . $account_id;
    $adjustment_amount = abs($new_balance - $existing_account->balance);
    
    $transaction_data = array(
        'transaction_date' => current_time('mysql'),
        'transaction_number' => $transaction_number,
        'description' => $adjustment_reason,
        'reference_type' => 'adjustment',
        'reference_id' => $account_id,
        'total_amount' => $adjustment_amount,
        'created_at' => current_time('mysql')
    );
    
    $wpdb->insert("{$wpdb->prefix}ims_transactions", $transaction_data);
    
    $updated_account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d", $account_id
    ));
    
    return ims_api_response(array(
        'account' => $updated_account,
        'previous_balance' => $existing_account->balance,
        'new_balance' => $new_balance,
        'adjustment' => $new_balance - $existing_account->balance,
        'transaction_number' => $transaction_number
    ), 'Account balance updated successfully');
}

// GET /transactions - Get all transactions (NEW)
function get_transactions($request) {
    global $wpdb;
    
    $params = $request->get_params();
    $page = max(1, intval($params['page'] ?? 1));
    $limit = min(100, max(1, intval($params['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    $where_conditions = array('1=1');
    $query_params = array();
    
    // Filter by reference type
    if (!empty($params['reference_type'])) {
        $where_conditions[] = 'reference_type = %s';
        $query_params[] = sanitize_text_field($params['reference_type']);
    }
    
    // Filter by date range
    if (!empty($params['start_date'])) {
        $where_conditions[] = 'transaction_date >= %s';
        $query_params[] = sanitize_text_field($params['start_date']);
    }
    
    if (!empty($params['end_date'])) {
        $where_conditions[] = 'transaction_date <= %s';
        $query_params[] = sanitize_text_field($params['end_date']);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ims_transactions WHERE {$where_clause}";
    if (!empty($query_params)) {
        $count_query = $wpdb->prepare($count_query, $query_params);
    }
    $total = $wpdb->get_var($count_query);
    
    // Get transactions
    $query = "SELECT * FROM {$wpdb->prefix}ims_transactions WHERE {$where_clause} ORDER BY transaction_date DESC, id DESC LIMIT %d OFFSET %d";
    $query_params[] = $limit;
    $query_params[] = $offset;
    
    $transactions = $wpdb->get_results($wpdb->prepare($query, $query_params));
    
    $pagination = array(
        'page' => $page,
        'limit' => $limit,
        'total' => intval($total),
        'pages' => ceil($total / $limit)
    );
    
    return ims_api_response(array(
        'transactions' => $transactions,
        'pagination' => $pagination
    ), 'Transactions retrieved successfully');
}

// POST /transactions - Create transaction (NEW)
function create_transaction($request) {
    global $wpdb;
    
    $params = $request->get_json_params();
    
    // Validate required fields
    $required_fields = array('transaction_date', 'transaction_number', 'reference_type', 'total_amount');
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return ims_api_error("Missing required field: {$field}");
        }
    }
    
    // Validate reference type
    $valid_types = array('sale', 'purchase', 'payment', 'expense', 'adjustment');
    if (!in_array($params['reference_type'], $valid_types)) {
        return ims_api_error('Invalid reference type');
    }
    
    // Check if transaction number already exists
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_transactions WHERE transaction_number = %s",
        sanitize_text_field($params['transaction_number'])
    ));
    
    if ($existing) {
        return ims_api_error('Transaction number already exists');
    }
    
    $data = array(
        'transaction_date' => sanitize_text_field($params['transaction_date']),
        'transaction_number' => sanitize_text_field($params['transaction_number']),
        'description' => sanitize_text_field($params['description'] ?? ''),
        'reference_type' => sanitize_text_field($params['reference_type']),
        'reference_id' => intval($params['reference_id'] ?? 0),
        'total_amount' => floatval($params['total_amount']),
        'created_at' => current_time('mysql')
    );
    
    $result = $wpdb->insert("{$wpdb->prefix}ims_transactions", $data);
    
    if ($result === false) {
        return ims_api_error('Failed to create transaction: ' . $wpdb->last_error);
    }
    
    $transaction_id = $wpdb->insert_id;
    $new_transaction = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_transactions WHERE id = %d", $transaction_id
    ));
    
    return ims_api_response($new_transaction, 'Transaction created successfully', 201);
}

// GET /cash-flow - Get cash flow data (NEW)
function get_cash_flow($request) {
    global $wpdb;
    
    $params = $request->get_params();
    $page = max(1, intval($params['page'] ?? 1));
    $limit = min(100, max(1, intval($params['limit'] ?? 50)));
    $offset = ($page - 1) * $limit;
    
    $where_conditions = array('1=1');
    $query_params = array();
    
    // Filter by type (inflow/outflow)
    if (!empty($params['type'])) {
        $where_conditions[] = 'type = %s';
        $query_params[] = sanitize_text_field($params['type']);
    }
    
    // Filter by account
    if (!empty($params['account_id'])) {
        $where_conditions[] = 'account_id = %d';
        $query_params[] = intval($params['account_id']);
    }
    
    // Filter by date range
    if (!empty($params['start_date'])) {
        $where_conditions[] = 'date >= %s';
        $query_params[] = sanitize_text_field($params['start_date']);
    }
    
    if (!empty($params['end_date'])) {
        $where_conditions[] = 'date <= %s';
        $query_params[] = sanitize_text_field($params['end_date']);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Join with accounts table to get account information
    $query = "
        SELECT cf.*, a.account_name, a.account_code 
        FROM {$wpdb->prefix}ims_cash_flow cf 
        LEFT JOIN {$wpdb->prefix}ims_accounts a ON cf.account_id = a.id 
        WHERE {$where_clause} 
        ORDER BY cf.date DESC, cf.id DESC 
        LIMIT %d OFFSET %d
    ";
    
    $query_params[] = $limit;
    $query_params[] = $offset;
    
    $cash_flow = $wpdb->get_results($wpdb->prepare($query, $query_params));
    
    // Get total count
    $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ims_cash_flow WHERE {$where_clause}";
    if (!empty($query_params)) {
        $count_query = $wpdb->prepare($count_query, array_slice($query_params, 0, -2));
    }
    $total = $wpdb->get_var($count_query);
    
    // Get summary
    $summary = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            SUM(CASE WHEN type = 'inflow' THEN amount ELSE 0 END) as total_inflow,
            SUM(CASE WHEN type = 'outflow' THEN amount ELSE 0 END) as total_outflow,
            COUNT(*) as total_records
        FROM {$wpdb->prefix}ims_cash_flow 
        WHERE {$where_clause}",
        array_slice($query_params, 0, -2)
    ));
    
    $pagination = array(
        'page' => $page,
        'limit' => $limit,
        'total' => intval($total),
        'pages' => ceil($total / $limit)
    );
    
    return ims_api_response(array(
        'cash_flow' => $cash_flow,
        'summary' => $summary,
        'pagination' => $pagination
    ), 'Cash flow data retrieved successfully');
}

// POST /cash-flow - Create cash flow entry (NEW)
function create_cash_flow($request) {
    global $wpdb;
    
    $params = $request->get_json_params();
    
    // Validate required fields
    $required_fields = array('type', 'account_id', 'amount', 'date');
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return ims_api_error("Missing required field: {$field}");
        }
    }
    
    // Validate type
    $valid_types = array('inflow', 'outflow');
    if (!in_array($params['type'], $valid_types)) {
        return ims_api_error('Invalid type. Must be "inflow" or "outflow"');
    }

    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    // Check if account exists and Lock it
    $account = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d FOR UPDATE",
        intval($params['account_id'])
    ));
    
    if (!$account) {
        $wpdb->query('ROLLBACK');
        return ims_api_error('Account not found');
    }
    
    $data = array(
        'type' => sanitize_text_field($params['type']),
        'account_id' => intval($params['account_id']),
        'transaction_id' => intval($params['transaction_id'] ?? 0),
        'amount' => floatval($params['amount']),
        'reference' => sanitize_text_field($params['reference'] ?? ''),
        'description' => sanitize_text_field($params['description'] ?? ''),
        'date' => sanitize_text_field($params['date']),
        'created_at' => current_time('mysql')
    );
    
    $result = $wpdb->insert("{$wpdb->prefix}ims_cash_flow", $data);
    
    if ($result === false) {
        $wpdb->query('ROLLBACK');
        return ims_api_error('Failed to create cash flow entry: ' . $wpdb->last_error);
    }
    
    $cash_flow_id = $wpdb->insert_id;

    // Update Account Balance
    $current_balance = floatval($account->balance);
    $amount = floatval($params['amount']);
    
    if ($params['type'] === 'inflow') {
        $new_balance = $current_balance + $amount;
    } else {
        $new_balance = $current_balance - $amount;
    }

    $update_account = $wpdb->update(
        "{$wpdb->prefix}ims_accounts",
        array('balance' => $new_balance),
        array('id' => $account->id),
        array('%f'),
        array('%d')
    );

    if ($update_account === false) {
        $wpdb->query('ROLLBACK');
        return ims_api_error('Failed to update account balance');
    }
    
    $wpdb->query('COMMIT');

    // Get the created entry with account info
    $new_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT cf.*, a.account_name, a.account_code 
         FROM {$wpdb->prefix}ims_cash_flow cf 
         LEFT JOIN {$wpdb->prefix}ims_accounts a ON cf.account_id = a.id 
         WHERE cf.id = %d", 
        $cash_flow_id
    ));
    
    return ims_api_response($new_entry, 'Cash flow entry created successfully', 201);
}

?>