<?php
// Scheduled Expenses API Endpoints
add_action('rest_api_init', function () {
    // Scheduled Expenses Endpoints
    register_rest_route('ims/v1', '/finance/expenses/scheduled', array(
        'methods' => 'GET',
        'callback' => 'ims_get_scheduled_expenses',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/finance/expenses/scheduled', array(
        'methods' => 'POST',
        'callback' => 'ims_create_scheduled_expense',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/finance/expenses/scheduled/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_scheduled_expense',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/finance/expenses/scheduled/(?P<id>\d+)/status', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_scheduled_expense_status',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/finance/expenses/scheduled/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'ims_delete_scheduled_expense',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/finance/expenses/scheduled/next-executions', array(
        'methods' => 'GET',
        'callback' => 'ims_get_next_executions',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/finance/expenses/scheduled/(?P<id>\d+)/execute', array(
        'methods' => 'POST',
        'callback' => 'ims_execute_scheduled_expense',
        'permission_callback' => '__return_true'
    ));
    
    // Regular Expenses Endpoints
    register_rest_route('ims/v1', '/expenses', array(
        'methods' => 'GET',
        'callback' => 'get_expenses',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses', array(
        'methods' => 'POST',
        'callback' => 'create_expense',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'update_expense',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'delete_expense',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses/summary', array(
        'methods' => 'GET',
        'callback' => 'get_expenses_summary',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses/categories', array(
        'methods' => 'GET',
        'callback' => 'get_expense_categories',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses/categories', array(
        'methods' => 'POST',
        'callback' => 'create_expense_category',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses/bulk-delete', array(
        'methods' => 'POST',
        'callback' => 'bulk_delete_expenses',
        'permission_callback' => '__return_true'
    ));
    
    register_rest_route('ims/v1', '/expenses/export', array(
        'methods' => 'GET',
        'callback' => 'export_expenses',
        'permission_callback' => '__return_true'
    ));
});

// Helper function to calculate next execution date
function ims_calculate_next_execution($frequency, $start_date, $last_executed = null) {
    $base_date = $last_executed ? $last_executed : $start_date;
    $date = new DateTime($base_date);
    
    switch ($frequency) {
        case 'daily':
            $date->modify('+1 day');
            break;
        case 'weekly':
            $date->modify('+1 week');
            break;
        case 'monthly':
            $date->modify('+1 month');
            break;
        case 'yearly':
            $date->modify('+1 year');
            break;
    }
    
    return $date->format('Y-m-d');
}

// Utility function to validate date
function validate_date($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// ============================
// SCHEDULED EXPENSES FUNCTIONS
// ============================

// 1. GET Scheduled Expenses
function ims_get_scheduled_expenses($request) {
    global $wpdb;
    
    $params = $request->get_params();
    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
    $limit = isset($params['limit']) ? max(1, intval($params['limit'])) : 10;
    $offset = ($page - 1) * $limit;
    
    $where_conditions = array('1=1');
    $query_params = array();
    
    if (!empty($params['status'])) {
        $where_conditions[] = 'se.status = %s';
        $query_params[] = sanitize_text_field($params['status']);
    }
    
    if (!empty($params['category'])) {
        $where_conditions[] = 'se.category = %s';
        $query_params[] = sanitize_text_field($params['category']);
    }
    
    if (!empty($params['frequency'])) {
        $where_conditions[] = 'se.frequency = %s';
        $query_params[] = sanitize_text_field($params['frequency']);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Count total records
    $count_query = "SELECT COUNT(*) FROM uh_ims_scheduled_expenses se WHERE {$where_clause}";
    if (!empty($query_params)) {
        $count_query = $wpdb->prepare($count_query, $query_params);
    }
    $total_records = $wpdb->get_var($count_query);
    $total_pages = ceil($total_records / $limit);
    
    // Get data
    $data_query = "SELECT se.*, a.account_name 
                   FROM uh_ims_scheduled_expenses se 
                   LEFT JOIN uh_ims_accounts a ON se.account_id = a.id 
                   WHERE {$where_clause} 
                   ORDER BY se.next_execution ASC 
                   LIMIT %d OFFSET %d";
    
    $query_params[] = $limit;
    $query_params[] = $offset;
    
    $data_query = $wpdb->prepare($data_query, $query_params);
    $expenses = $wpdb->get_results($data_query, ARRAY_A);
    
    // Format response
    $formatted_expenses = array();
    foreach ($expenses as $expense) {
        $formatted_expenses[] = array(
            'id' => intval($expense['id']),
            'category' => $expense['category'],
            'description' => $expense['description'],
            'amount' => floatval($expense['amount']),
            'frequency' => $expense['frequency'],
            'next_execution' => $expense['next_execution'],
            'status' => $expense['status'],
            'account_id' => intval($expense['account_id']),
            'account_name' => $expense['account_name'],
            'payment_method' => $expense['payment_method'],
            'created_at' => $expense['created_at'],
            'last_executed' => $expense['last_executed'],
            'execution_count' => intval($expense['execution_count'])
        );
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $formatted_expenses,
        'pagination' => array(
            'currentPage' => $page,
            'totalPages' => $total_pages,
            'totalRecords' => intval($total_records),
            'limit' => $limit
        )
    ));
}

// 2. POST Create Scheduled Expense
function ims_create_scheduled_expense($request) {
    global $wpdb;
    
    $params = $request->get_json_params();
    
    // Validate required fields
    $required_fields = array('category', 'amount', 'frequency', 'start_date', 'account_id', 'payment_method');
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return new WP_Error('missing_field', "Field '{$field}' is required", array('status' => 400));
        }
    }
    
    // Calculate next execution
    $next_execution = ims_calculate_next_execution(
        $params['frequency'], 
        $params['start_date']
    );
    
    $data = array(
        'category' => sanitize_text_field($params['category']),
        'description' => sanitize_text_field($params['description'] ?? ''),
        'amount' => floatval($params['amount']),
        'frequency' => sanitize_text_field($params['frequency']),
        'start_date' => sanitize_text_field($params['start_date']),
        'next_execution' => $next_execution,
        'status' => 'active',
        'account_id' => intval($params['account_id']),
        'payment_method' => sanitize_text_field($params['payment_method']),
        'created_by' => get_current_user_id() ?: null
    );
    
    $format = array('%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%d');
    
    $result = $wpdb->insert('uh_ims_scheduled_expenses', $data, $format);
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to create scheduled expense', array('status' => 500));
    }
    
    $expense_id = $wpdb->insert_id;
    $created_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT se.*, a.account_name FROM uh_ims_scheduled_expenses se 
         LEFT JOIN uh_ims_accounts a ON se.account_id = a.id 
         WHERE se.id = %d", 
        $expense_id
    ), ARRAY_A);
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => array(
            'id' => intval($created_expense['id']),
            'category' => $created_expense['category'],
            'description' => $created_expense['description'],
            'amount' => floatval($created_expense['amount']),
            'frequency' => $created_expense['frequency'],
            'next_execution' => $created_expense['next_execution'],
            'status' => $created_expense['status'],
            'account_id' => intval($created_expense['account_id']),
            'account_name' => $created_expense['account_name'],
            'payment_method' => $created_expense['payment_method'],
            'created_at' => $created_expense['created_at']
        ),
        'message' => 'Scheduled expense created successfully'
    ));
}

// 3. PUT Update Scheduled Expense
function ims_update_scheduled_expense($request) {
    global $wpdb;
    
    $expense_id = $request['id'];
    $params = $request->get_json_params();
    
    // Check if expense exists
    $existing_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM uh_ims_scheduled_expenses WHERE id = %d", 
        $expense_id
    ));
    
    if (!$existing_expense) {
        return new WP_Error('not_found', 'Scheduled expense not found', array('status' => 404));
    }
    
    $data = array();
    $format = array();
    
    $updatable_fields = array('category', 'description', 'amount', 'frequency', 'account_id', 'payment_method');
    
    foreach ($updatable_fields as $field) {
        if (isset($params[$field])) {
            if ($field === 'amount') {
                $data[$field] = floatval($params[$field]);
                $format[] = '%f';
            } elseif ($field === 'account_id') {
                $data[$field] = intval($params[$field]);
                $format[] = '%d';
            } else {
                $data[$field] = sanitize_text_field($params[$field]);
                $format[] = '%s';
            }
        }
    }
    
    if (empty($data)) {
        return new WP_Error('no_data', 'No data provided for update', array('status' => 400));
    }
    
    $data['updated_at'] = current_time('mysql');
    $format[] = '%s';
    
    $result = $wpdb->update(
        'uh_ims_scheduled_expenses',
        $data,
        array('id' => $expense_id),
        $format,
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to update scheduled expense', array('status' => 500));
    }
    
    $updated_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT se.*, a.account_name FROM uh_ims_scheduled_expenses se 
         LEFT JOIN uh_ims_accounts a ON se.account_id = a.id 
         WHERE se.id = %d", 
        $expense_id
    ), ARRAY_A);
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => array(
            'id' => intval($updated_expense['id']),
            'category' => $updated_expense['category'],
            'description' => $updated_expense['description'],
            'amount' => floatval($updated_expense['amount']),
            'frequency' => $updated_expense['frequency'],
            'next_execution' => $updated_expense['next_execution'],
            'status' => $updated_expense['status'],
            'account_id' => intval($updated_expense['account_id']),
            'account_name' => $updated_expense['account_name'],
            'payment_method' => $updated_expense['payment_method'],
            'updated_at' => $updated_expense['updated_at']
        ),
        'message' => 'Scheduled expense updated successfully'
    ));
}

// 4. PUT Update Status
function ims_update_scheduled_expense_status($request) {
    global $wpdb;
    
    $expense_id = $request['id'];
    $params = $request->get_json_params();
    
    if (empty($params['status'])) {
        return new WP_Error('missing_field', "Field 'status' is required", array('status' => 400));
    }
    
    $allowed_statuses = array('active', 'paused', 'inactive');
    if (!in_array($params['status'], $allowed_statuses)) {
        return new WP_Error('invalid_status', 'Invalid status value', array('status' => 400));
    }
    
    // Check if expense exists
    $existing_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM uh_ims_scheduled_expenses WHERE id = %d", 
        $expense_id
    ));
    
    if (!$existing_expense) {
        return new WP_Error('not_found', 'Scheduled expense not found', array('status' => 404));
    }
    
    $result = $wpdb->update(
        'uh_ims_scheduled_expenses',
        array(
            'status' => sanitize_text_field($params['status']),
            'updated_at' => current_time('mysql')
        ),
        array('id' => $expense_id),
        array('%s', '%s'),
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to update status', array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => array(
            'id' => intval($expense_id),
            'status' => sanitize_text_field($params['status']),
            'updated_at' => current_time('mysql')
        ),
        'message' => 'Scheduled expense status updated to ' . $params['status']
    ));
}

// 5. DELETE Scheduled Expense
function ims_delete_scheduled_expense($request) {
    global $wpdb;
    
    $expense_id = $request['id'];
    
    // Check if expense exists
    $existing_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM uh_ims_scheduled_expenses WHERE id = %d", 
        $expense_id
    ));
    
    if (!$existing_expense) {
        return new WP_Error('not_found', 'Scheduled expense not found', array('status' => 404));
    }
    
    $result = $wpdb->delete(
        'uh_ims_scheduled_expenses',
        array('id' => $expense_id),
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to delete scheduled expense', array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => array('deleted' => true),
        'message' => 'Scheduled expense deleted successfully'
    ));
}

// 6. GET Next Executions
function ims_get_next_executions($request) {
    global $wpdb;
    
    $params = $request->get_params();
    $days = isset($params['days']) ? max(1, intval($params['days'])) : 7;
    
    $end_date = date('Y-m-d', strtotime("+{$days} days"));
    $today = date('Y-m-d');
    
    $query = $wpdb->prepare(
        "SELECT id, description, amount, next_execution, frequency 
         FROM uh_ims_scheduled_expenses 
         WHERE status = 'active' 
         AND next_execution BETWEEN %s AND %s
         ORDER BY next_execution ASC",
        $today,
        $end_date
    );
    
    $expenses = $wpdb->get_results($query, ARRAY_A);
    
    $formatted_expenses = array();
    foreach ($expenses as $expense) {
        $next_execution = new DateTime($expense['next_execution']);
        $today_obj = new DateTime($today);
        $days_until = $today_obj->diff($next_execution)->days;
        
        $formatted_expenses[] = array(
            'id' => intval($expense['id']),
            'description' => $expense['description'],
            'amount' => floatval($expense['amount']),
            'next_execution' => $expense['next_execution'],
            'days_until' => $days_until,
            'frequency' => $expense['frequency']
        );
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $formatted_expenses
    ));
}

// 7. POST Execute Scheduled Expense
function ims_execute_scheduled_expense($request) {
    global $wpdb;
    
    $expense_id = $request['id'];
    
    // Check if expense exists and is active
    $scheduled_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM uh_ims_scheduled_expenses WHERE id = %d AND status = 'active'", 
        $expense_id
    ), ARRAY_A);
    
    if (!$scheduled_expense) {
        return new WP_Error('not_found', 'Active scheduled expense not found', array('status' => 404));
    }
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Create the actual expense record
        $expense_data = array(
            'category' => $scheduled_expense['category'],
            'account_id' => $scheduled_expense['account_id'],
            'description' => $scheduled_expense['description'] . ' (Scheduled)',
            'amount' => $scheduled_expense['amount'],
            'date' => current_time('mysql'),
            'payment_method' => $scheduled_expense['payment_method'],
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql')
        );
        
        $expense_format = array('%s', '%d', '%s', '%f', '%s', '%s', '%d', '%s');
        
        $expense_result = $wpdb->insert('uh_ims_expenses', $expense_data, $expense_format);
        
        if (!$expense_result) {
            throw new Exception('Failed to create expense record');
        }
        
        $new_expense_id = $wpdb->insert_id;
        
        // Create transaction record
        $transaction_number = 'EXP-' . date('YmdHis') . '-' . $new_expense_id;
        
        $transaction_data = array(
            'transaction_date' => current_time('mysql'),
            'transaction_number' => $transaction_number,
            'description' => $scheduled_expense['description'] . ' (Scheduled)',
            'reference_type' => 'expense',
            'reference_id' => $new_expense_id,
            'total_amount' => $scheduled_expense['amount'],
            'created_at' => current_time('mysql')
        );
        
        $transaction_result = $wpdb->insert('uh_ims_transactions', $transaction_data);
        
        if (!$transaction_result) {
            throw new Exception('Failed to create transaction record');
        }
        
        $transaction_id = $wpdb->insert_id;
        
        // Update expense with transaction ID
        $wpdb->update(
            'uh_ims_expenses',
            array('transaction_id' => $transaction_id),
            array('id' => $new_expense_id),
            array('%d'),
            array('%d')
        );
        
        // Create transaction entries (debit expense account, credit bank account)
        // Expense account (debit)
        $wpdb->insert('uh_ims_transaction_entries', array(
            'transaction_id' => $transaction_id,
            'account_id' => $scheduled_expense['account_id'], // Expense account
            'entry_type' => 'debit',
            'amount' => $scheduled_expense['amount'],
            'description' => $scheduled_expense['description']
        ));
        
        // Bank/Cash account (credit) - you might want to specify a different account for credit
        $bank_account_id = 1; // You may want to make this configurable
        $wpdb->insert('uh_ims_transaction_entries', array(
            'transaction_id' => $transaction_id,
            'account_id' => $bank_account_id, // Bank account
            'entry_type' => 'credit',
            'amount' => $scheduled_expense['amount'],
            'description' => $scheduled_expense['description']
        ));
        
        // Update scheduled expense
        $next_execution = ims_calculate_next_execution(
            $scheduled_expense['frequency'],
            $scheduled_expense['start_date'],
            $scheduled_expense['next_execution']
        );
        
        $wpdb->update(
            'uh_ims_scheduled_expenses',
            array(
                'last_executed' => current_time('mysql'),
                'next_execution' => $next_execution,
                'execution_count' => $scheduled_expense['execution_count'] + 1,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $expense_id),
            array('%s', '%s', '%d', '%s'),
            array('%d')
        );
        
        // Commit transaction
        $wpdb->query('COMMIT');
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'scheduled_expense_id' => intval($expense_id),
                'expense_id' => intval($new_expense_id),
                'transaction_id' => intval($transaction_id),
                'executed_at' => current_time('mysql'),
                'next_execution' => $next_execution,
                'execution_count' => $scheduled_expense['execution_count'] + 1
            ),
            'message' => 'Scheduled expense executed successfully'
        ));
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $wpdb->query('ROLLBACK');
        return new WP_Error('execution_failed', $e->getMessage(), array('status' => 500));
    }
}

// ============================
// REGULAR EXPENSES FUNCTIONS
// ============================

// GET /expenses - List expenses
function get_expenses($request) {
    global $wpdb;
    
    $params = $request->get_params();
    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
    $limit = isset($params['limit']) ? min(100, max(1, intval($params['limit']))) : 50;
    $offset = ($page - 1) * $limit;
    
    // Build WHERE clause
    $where_conditions = array('1=1');
    $where_values = array();
    
    if (!empty($params['category'])) {
        $where_conditions[] = 'category = %s';
        $where_values[] = sanitize_text_field($params['category']);
    }
    
    if (!empty($params['account_id'])) {
        $where_conditions[] = 'account_id = %d';
        $where_values[] = intval($params['account_id']);
    }
    
    if (!empty($params['payment_method'])) {
        $where_conditions[] = 'payment_method = %s';
        $where_values[] = sanitize_text_field($params['payment_method']);
    }
    
    if (!empty($params['date_from']) && validate_date($params['date_from'])) {
        $where_conditions[] = 'date >= %s';
        $where_values[] = sanitize_text_field($params['date_from']);
    }
    
    if (!empty($params['date_to']) && validate_date($params['date_to'])) {
        $where_conditions[] = 'date <= %s';
        $where_values[] = sanitize_text_field($params['date_to']);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ims_expenses WHERE {$where_clause}";
    $total_count = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
    
    // Get data
    $query = "SELECT * FROM {$wpdb->prefix}ims_expenses 
              WHERE {$where_clause} 
              ORDER BY date DESC, created_at DESC 
              LIMIT %d OFFSET %d";
    
    $where_values[] = $limit;
    $where_values[] = $offset;
    
    $expenses = $wpdb->get_results($wpdb->prepare($query, $where_values));
    
    // Format response
    $formatted_expenses = array();
    foreach ($expenses as $expense) {
        $formatted_expenses[] = array(
            'id' => intval($expense->id),
            'category' => $expense->category,
            'account_id' => $expense->account_id ? intval($expense->account_id) : null,
            'transaction_id' => $expense->transaction_id ? intval($expense->transaction_id) : null,
            'description' => $expense->description,
            'amount' => floatval($expense->amount),
            'date' => $expense->date,
            'reference' => $expense->reference,
            'payment_method' => $expense->payment_method,
            'receipt_url' => $expense->receipt_url,
            'created_by' => $expense->created_by ? intval($expense->created_by) : null,
            'created_at' => $expense->created_at
        );
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $formatted_expenses,
        'pagination' => array(
            'page' => $page,
            'limit' => $limit,
            'total' => intval($total_count),
            'pages' => ceil($total_count / $limit)
        )
    ));
}

// POST /expenses - Create expense
function create_expense($request) {
    global $wpdb;
    
    $params = $request->get_params();
    
    // Validate required fields
    $required_fields = array('category', 'amount', 'date', 'payment_method');
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return new WP_Error('missing_field', "Field '{$field}' is required", array('status' => 400));
        }
    }
    
    // Validate amount
    $amount = floatval($params['amount']);
    if ($amount <= 0) {
        return new WP_Error('invalid_amount', 'Amount must be greater than 0', array('status' => 400));
    }
    
    // Validate date
    if (!validate_date($params['date'])) {
        return new WP_Error('invalid_date', 'Date must be in YYYY-MM-DD format', array('status' => 400));
    }
    
    // Validate payment method
    $valid_payment_methods = array('cash', 'bank_transfer', 'cheque');
    if (!in_array($params['payment_method'], $valid_payment_methods)) {
        return new WP_Error('invalid_payment_method', 'Invalid payment method', array('status' => 400));
    }
    
    // Prepare data
    $data = array(
        'category' => sanitize_text_field($params['category']),
        'account_id' => !empty($params['account_id']) ? intval($params['account_id']) : null,
        'description' => !empty($params['description']) ? sanitize_textarea_field($params['description']) : null,
        'amount' => $amount,
        'date' => sanitize_text_field($params['date']),
        'reference' => !empty($params['reference']) ? sanitize_text_field($params['reference']) : null,
        'payment_method' => sanitize_text_field($params['payment_method']),
        'receipt_url' => !empty($params['receipt_url']) ? esc_url_raw($params['receipt_url']) : null,
        'created_by' => !empty($params['created_by']) ? intval($params['created_by']) : null,
        'created_at' => current_time('mysql')
    );
    
    $format = array('%s', '%d', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s');
    
    // Insert expense
    $result = $wpdb->insert("{$wpdb->prefix}ims_expenses", $data, $format);
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to create expense', array('status' => 500));
    }
    
    $expense_id = $wpdb->insert_id;
    
    // Get created expense
    $expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_expenses WHERE id = %d", 
        $expense_id
    ));
    
    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Expense created successfully',
        'data' => array(
            'id' => intval($expense->id),
            'category' => $expense->category,
            'account_id' => $expense->account_id ? intval($expense->account_id) : null,
            'description' => $expense->description,
            'amount' => floatval($expense->amount),
            'date' => $expense->date,
            'reference' => $expense->reference,
            'payment_method' => $expense->payment_method,
            'receipt_url' => $expense->receipt_url,
            'created_by' => $expense->created_by ? intval($expense->created_by) : null,
            'created_at' => $expense->created_at
        )
    ));
}

// PUT /expenses/{id} - Update expense
function update_expense($request) {
    global $wpdb;
    
    $expense_id = intval($request['id']);
    $params = $request->get_params();
    
    // Check if expense exists
    $existing_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_expenses WHERE id = %d", 
        $expense_id
    ));
    
    if (!$existing_expense) {
        return new WP_Error('not_found', 'Expense not found', array('status' => 404));
    }
    
    // Prepare update data
    $data = array();
    $format = array();
    
    if (isset($params['category'])) {
        $data['category'] = sanitize_text_field($params['category']);
        $format[] = '%s';
    }
    
    if (isset($params['account_id'])) {
        $data['account_id'] = !empty($params['account_id']) ? intval($params['account_id']) : null;
        $format[] = '%d';
    }
    
    if (isset($params['description'])) {
        $data['description'] = sanitize_textarea_field($params['description']);
        $format[] = '%s';
    }
    
    if (isset($params['amount'])) {
        $amount = floatval($params['amount']);
        if ($amount <= 0) {
            return new WP_Error('invalid_amount', 'Amount must be greater than 0', array('status' => 400));
        }
        $data['amount'] = $amount;
        $format[] = '%f';
    }
    
    if (isset($params['date'])) {
        if (!validate_date($params['date'])) {
            return new WP_Error('invalid_date', 'Date must be in YYYY-MM-DD format', array('status' => 400));
        }
        $data['date'] = sanitize_text_field($params['date']);
        $format[] = '%s';
    }
    
    if (isset($params['reference'])) {
        $data['reference'] = sanitize_text_field($params['reference']);
        $format[] = '%s';
    }
    
    if (isset($params['payment_method'])) {
        $valid_payment_methods = array('cash', 'bank_transfer', 'cheque');
        if (!in_array($params['payment_method'], $valid_payment_methods)) {
            return new WP_Error('invalid_payment_method', 'Invalid payment method', array('status' => 400));
        }
        $data['payment_method'] = sanitize_text_field($params['payment_method']);
        $format[] = '%s';
    }
    
    if (isset($params['receipt_url'])) {
        $data['receipt_url'] = esc_url_raw($params['receipt_url']);
        $format[] = '%s';
    }
    
    if (empty($data)) {
        return new WP_Error('no_data', 'No data provided for update', array('status' => 400));
    }
    
    // Update expense
    $result = $wpdb->update(
        "{$wpdb->prefix}ims_expenses",
        $data,
        array('id' => $expense_id),
        $format,
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to update expense', array('status' => 500));
    }
    
    // Get updated expense
    $expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_expenses WHERE id = %d", 
        $expense_id
    ));
    
    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Expense updated successfully',
        'data' => array(
            'id' => intval($expense->id),
            'category' => $expense->category,
            'account_id' => $expense->account_id ? intval($expense->account_id) : null,
            'description' => $expense->description,
            'amount' => floatval($expense->amount),
            'date' => $expense->date,
            'reference' => $expense->reference,
            'payment_method' => $expense->payment_method,
            'receipt_url' => $expense->receipt_url,
            'created_by' => $expense->created_by ? intval($expense->created_by) : null,
            'created_at' => $expense->created_at
        )
    ));
}

// DELETE /expenses/{id} - Delete expense
function delete_expense($request) {
    global $wpdb;
    
    $expense_id = intval($request['id']);
    
    // Check if expense exists
    $existing_expense = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_expenses WHERE id = %d", 
        $expense_id
    ));
    
    if (!$existing_expense) {
        return new WP_Error('not_found', 'Expense not found', array('status' => 404));
    }
    
    // Delete expense
    $result = $wpdb->delete(
        "{$wpdb->prefix}ims_expenses",
        array('id' => $expense_id),
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to delete expense', array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Expense deleted successfully'
    ));
}

// GET /expenses/summary - Get expense analytics
function get_expenses_summary($request) {
    global $wpdb;
    
    $params = $request->get_params();
    
    // Build WHERE clause
    $where_conditions = array('1=1');
    $where_values = array();
    
    if (!empty($params['date_from']) && validate_date($params['date_from'])) {
        $where_conditions[] = 'date >= %s';
        $where_values[] = sanitize_text_field($params['date_from']);
    }
    
    if (!empty($params['date_to']) && validate_date($params['date_to'])) {
        $where_conditions[] = 'date <= %s';
        $where_values[] = sanitize_text_field($params['date_to']);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Total expenses
    $total_query = "SELECT COUNT(*) as count, SUM(amount) as total 
                    FROM {$wpdb->prefix}ims_expenses 
                    WHERE {$where_clause}";
    $total_result = $wpdb->get_row($wpdb->prepare($total_query, $where_values));
    
    // By category
    $category_query = "SELECT category, COUNT(*) as count, SUM(amount) as total 
                       FROM {$wpdb->prefix}ims_expenses 
                       WHERE {$where_clause} 
                       GROUP BY category 
                       ORDER BY total DESC";
    $category_results = $wpdb->get_results($wpdb->prepare($category_query, $where_values));
    
    // By payment method
    $payment_query = "SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
                      FROM {$wpdb->prefix}ims_expenses 
                      WHERE {$where_clause} 
                      GROUP BY payment_method 
                      ORDER BY total DESC";
    $payment_results = $wpdb->get_results($wpdb->prepare($payment_query, $where_values));
    
    // Monthly trend (last 6 months)
    $monthly_query = "SELECT YEAR(date) as year, MONTH(date) as month, 
                             COUNT(*) as count, SUM(amount) as total 
                      FROM {$wpdb->prefix}ims_expenses 
                      WHERE date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) 
                      GROUP BY YEAR(date), MONTH(date) 
                      ORDER BY year DESC, month DESC 
                      LIMIT 6";
    $monthly_results = $wpdb->get_results($monthly_query);
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => array(
            'total' => array(
                'count' => intval($total_result->count),
                'amount' => floatval($total_result->total ?: 0)
            ),
            'by_category' => $category_results,
            'by_payment_method' => $payment_results,
            'monthly_trend' => $monthly_results
        )
    ));
}

// GET /expenses/categories - List categories
function get_expense_categories($request) {
    global $wpdb;
    
    $categories = $wpdb->get_col("SELECT DISTINCT category FROM {$wpdb->prefix}ims_expenses ORDER BY category");
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $categories
    ));
}

// POST /expenses/categories - Create category
function create_expense_category($request) {
    global $wpdb;
    
    $params = $request->get_params();
    
    if (empty($params['category'])) {
        return new WP_Error('missing_field', 'Category name is required', array('status' => 400));
    }
    
    $category = sanitize_text_field($params['category']);
    
    // Check if category already exists
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_expenses WHERE category = %s", 
        $category
    ));
    
    if ($existing > 0) {
        return new WP_Error('duplicate_category', 'Category already exists', array('status' => 400));
    }
    
    // Note: Since categories are not stored in a separate table, we can't "create" them
    // We'll just return the category name for consistency
    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Category can be used (categories are dynamic based on existing expenses)',
        'data' => array('category' => $category)
    ));
}

// POST /expenses/bulk-delete - Bulk delete
function bulk_delete_expenses($request) {
    global $wpdb;
    
    $params = $request->get_params();
    
    if (empty($params['ids']) || !is_array($params['ids'])) {
        return new WP_Error('missing_field', 'Array of expense IDs is required', array('status' => 400));
    }
    
    $expense_ids = array_map('intval', $params['ids']);
    $expense_ids = array_filter($expense_ids);
    
    if (empty($expense_ids)) {
        return new WP_Error('invalid_ids', 'No valid expense IDs provided', array('status' => 400));
    }
    
    $placeholders = implode(',', array_fill(0, count($expense_ids), '%d'));
    
    // Delete expenses
    $query = $wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}ims_expenses WHERE id IN ({$placeholders})",
        $expense_ids
    );
    
    $result = $wpdb->query($query);
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to delete expenses', array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'message' => "{$result} expenses deleted successfully"
    ));
}

// GET /expenses/export - Export data
function export_expenses($request) {
    global $wpdb;
    
    $params = $request->get_params();
    
    // Build WHERE clause
    $where_conditions = array('1=1');
    $where_values = array();
    
    if (!empty($params['category'])) {
        $where_conditions[] = 'category = %s';
        $where_values[] = sanitize_text_field($params['category']);
    }
    
    if (!empty($params['date_from']) && validate_date($params['date_from'])) {
        $where_conditions[] = 'date >= %s';
        $where_values[] = sanitize_text_field($params['date_from']);
    }
    
    if (!empty($params['date_to']) && validate_date($params['date_to'])) {
        $where_conditions[] = 'date <= %s';
        $where_values[] = sanitize_text_field($params['date_to']);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get all expenses for export
    $query = "SELECT * FROM {$wpdb->prefix}ims_expenses 
              WHERE {$where_clause} 
              ORDER BY date DESC, created_at DESC";
    
    $expenses = $wpdb->get_results($wpdb->prepare($query, $where_values));
    
    // Format data for export
    $export_data = array();
    foreach ($expenses as $expense) {
        $export_data[] = array(
            'ID' => $expense->id,
            'Category' => $expense->category,
            'Account ID' => $expense->account_id,
            'Description' => $expense->description,
            'Amount' => floatval($expense->amount),
            'Date' => $expense->date,
            'Reference' => $expense->reference,
            'Payment Method' => $expense->payment_method,
            'Receipt URL' => $expense->receipt_url,
            'Created By' => $expense->created_by,
            'Created At' => $expense->created_at
        );
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $export_data,
        'export_info' => array(
            'format' => 'JSON',
            'total_records' => count($export_data),
            'exported_at' => current_time('mysql')
        )
    ));
}
?>