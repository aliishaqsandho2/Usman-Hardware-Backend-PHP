<?php
// Customers.php functionality here


/**
 * IMS Customer API Endpoints
 * Add these functions to your WordPress functions.php file
 */

// Register REST API routes for customers
add_action('rest_api_init', 'ims_register_customer_routes');

function ims_register_customer_routes() {
    // GET /wp-json/ims/v1/customers
    register_rest_route('ims/v1', '/customers', array(
        'methods' => 'GET',
        'callback' => 'ims_get_customers',
    ));
	// Get Customer Balance History
    register_rest_route('ims/v1', '/customers/(?P<customer_id>\d+)/balance-history', array(
        'methods' => 'GET',
        'callback' => 'ims_get_customer_balance_history',
        'permission_callback' => '__return_true'
    ));

    // GET /wp-json/ims/v1/customers/{id}
    register_rest_route('ims/v1', '/customers/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_customer_by_id',
    ));

    // POST /wp-json/ims/v1/customers
    register_rest_route('ims/v1', '/customers', array(
        'methods' => 'POST',
        'callback' => 'ims_create_customer',
    ));

    // PUT /wp-json/ims/v1/customers/{id}
    register_rest_route('ims/v1', '/customers/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_customer',
    ));

    // DELETE /wp-json/ims/v1/customers/{id}
    register_rest_route('ims/v1', '/customers/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'ims_delete_customer',
    ));

    // GET /wp-json/ims/v1/customers/duplicates/phone
    register_rest_route('ims/v1', '/customers/duplicates/phone', array(
        'methods' => 'GET',
        'callback' => 'ims_get_duplicate_customers_by_phone',
        'permission_callback' => '__return_true'
    ));

    // GET /wp-json/ims/v1/customers/duplicates/name
    register_rest_route('ims/v1', '/customers/duplicates/name', array(
        'methods' => 'GET',
        'callback' => 'ims_get_duplicate_customers_by_name',
        'permission_callback' => '__return_true'
    ));

    // POST /wp-json/ims/v1/customers/merge
    register_rest_route('ims/v1', '/customers/merge', array(
        'methods' => 'POST',
        'callback' => 'ims_merge_customers',
        'permission_callback' => '__return_true'
    ));
}

/**
 * GET /customers - Retrieve customers with pagination and filters
 */
function ims_get_customers($request) {
    global $wpdb;
    
    try {
        // Get query parameters
        $page = (int) $request->get_param('page') ?: 1;
        $limit = (int) $request->get_param('limit') ?: 100;
        $search = sanitize_text_field($request->get_param('search'));
        $type = sanitize_text_field($request->get_param('type'));
        $status = sanitize_text_field($request->get_param('status'));
        
        $offset = ($page - 1) * $limit;
        
        // Build WHERE clause
        $where_conditions = [];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(name LIKE %s OR email LIKE %s OR phone LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        }
        
        if (!empty($type) && in_array($type, ['individual', 'business'])) {
            $where_conditions[] = "type = %s";
            $params[] = $type;
        }
        
        if (!empty($status) && in_array($status, ['active', 'inactive'])) {
            $where_conditions[] = "status = %s";
            $params[] = $status;
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Count total records
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ims_customers $where_clause";
        if (!empty($params)) {
            $count_query = $wpdb->prepare($count_query, $params);
        }
        $total_items = $wpdb->get_var($count_query);
        
        // Fetch paginated customers
        $query = "SELECT 
            id, name, email, phone, type, address, city, status,
            credit_limit AS creditLimit, 
            current_balance AS currentBalance,
            total_purchases AS totalPurchases,
            created_at AS createdAt
          FROM {$wpdb->prefix}ims_customers
          $where_clause
          ORDER BY created_at DESC
          LIMIT %d OFFSET %d";
        
        $query_params = array_merge($params, [$limit, $offset]);
        $customers = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        // Get last purchase date for each customer
        foreach ($customers as &$customer) {
            $customer->creditLimit = (float) $customer->creditLimit;
            $customer->currentBalance = (float) $customer->currentBalance;
            $customer->totalPurchases = (float) $customer->totalPurchases;
            
            $last_purchase = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(date) 
                 FROM {$wpdb->prefix}ims_sales 
                 WHERE customer_id = %d AND status = 'completed'",
                $customer->id
            ));
            $customer->lastPurchase = $last_purchase ?: null;
        }
        
        $total_pages = ceil($total_items / $limit);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'customers' => $customers,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => $total_pages,
                    'totalItems' => (int) $total_items,
                    'itemsPerPage' => $limit,
                ]
            ]
        ], 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to retrieve customers',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * GET /customers/{id} - Get customer by ID with related data
 */
function ims_get_customer_by_id($request) {
    global $wpdb;
    
    try {
        $customer_id = (int) $request['id'];
        
        // Get customer details
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                id, name, email, phone, type, address, city, status,
                credit_limit as creditLimit, 
                current_balance as currentBalance,
                total_purchases as totalPurchases,
                created_at as createdAt,
                updated_at as updatedAt
             FROM {$wpdb->prefix}ims_customers 
             WHERE id = %d",
            $customer_id
        ));
        
        if (!$customer) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Customer not found'
            ), 404);
        }
        
        // Get last purchase date
        $last_purchase = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(date) FROM {$wpdb->prefix}ims_sales WHERE customer_id = %d AND status = 'completed'",
            $customer_id
        ));
        $customer->lastPurchase = $last_purchase;
        
        // Get recent orders (last 10)
        $recent_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_number as orderNumber, date, total as amount, status
             FROM {$wpdb->prefix}ims_sales 
             WHERE customer_id = %d 
             ORDER BY date DESC, created_at DESC 
             LIMIT 10",
            $customer_id
        ));
        
        // Get payment history (last 10)
        $payment_history = $wpdb->get_results($wpdb->prepare(
            "SELECT id, amount, date, 'payment' as type, reference
             FROM {$wpdb->prefix}ims_payments 
             WHERE customer_id = %d 
             ORDER BY date DESC, created_at DESC 
             LIMIT 10",
            $customer_id
        ));
        
        // Format numbers
        $customer->creditLimit = (float) $customer->creditLimit;
        $customer->currentBalance = (float) $customer->currentBalance;
        $customer->totalPurchases = (float) $customer->totalPurchases;
        
        foreach ($recent_orders as &$order) {
            $order->amount = (float) $order->amount;
        }
        
        foreach ($payment_history as &$payment) {
            $payment->amount = (float) $payment->amount;
        }
        
        $customer->recentOrders = $recent_orders;
        $customer->paymentHistory = $payment_history;
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $customer
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to retrieve customer',
            'error' => $e->getMessage()
        ), 500);
    }
}

/**
 * POST /customers - Create new customer
 */
function ims_create_customer($request) {
    global $wpdb;
    
    try {
        $body = $request->get_json_params();
        
        // Validate required fields
        $required_fields = ['name'];
        foreach ($required_fields as $field) {
            if (empty($body[$field])) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => "Field '$field' is required"
                ), 400);
            }
        }
        
        // Validate email uniqueness if provided
        if (!empty($body['email'])) {
            $existing_email = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ims_customers WHERE email = %s",
                $body['email']
            ));
            
            if ($existing_email) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Email already exists'
                ), 400);
            }
        }
        
        // Validate type
        $type = isset($body['type']) ? $body['type'] : 'business';
        if (!in_array($type, ['individual', 'business'])) {
            $type = 'business';
        }
        
        // Prepare data for insertion
        $customer_data = array(
            'name' => sanitize_text_field($body['name']),
            'email' => !empty($body['email']) ? sanitize_email($body['email']) : null,
            'phone' => !empty($body['phone']) ? sanitize_text_field($body['phone']) : null,
            'type' => $type,
            'address' => !empty($body['address']) ? sanitize_textarea_field($body['address']) : null,
            'city' => !empty($body['city']) ? sanitize_text_field($body['city']) : null,
            'status' => 'active',
            'credit_limit' => isset($body['creditLimit']) ? (float) $body['creditLimit'] : 0,
            'current_balance' => 0,
            'total_purchases' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        // Insert customer
        $result = $wpdb->insert(
            $wpdb->prefix . 'ims_customers',
            $customer_data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s')
        );
        
        if ($result === false) {
            throw new Exception('Failed to create customer: ' . $wpdb->last_error);
        }
        
        $customer_id = $wpdb->insert_id;
        
        // Get created customer
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                id, name, email, phone, type, address, city, status,
                credit_limit as creditLimit, 
                current_balance as currentBalance,
                total_purchases as totalPurchases,
                created_at as createdAt
             FROM {$wpdb->prefix}ims_customers 
             WHERE id = %d",
            $customer_id
        ));
        
        // Format numbers
        $customer->creditLimit = (float) $customer->creditLimit;
        $customer->currentBalance = (float) $customer->currentBalance;
        $customer->totalPurchases = (float) $customer->totalPurchases;
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $customer,
            'message' => 'Customer created successfully'
        ), 201);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to create customer',
            'error' => $e->getMessage()
        ), 500);
    }
}

/**
 * PUT /customers/{id} - Update customer
 */
function ims_update_customer($request) {
    global $wpdb;
    
    try {
        $customer_id = (int) $request['id'];
        $body = $request->get_json_params();
        
        // Check if customer exists
        $existing_customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_customers WHERE id = %d",
            $customer_id
        ));
        
        if (!$existing_customer) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Customer not found'
            ), 404);
        }
        
        // Validate email uniqueness if provided and different
        if (!empty($body['email']) && $body['email'] !== $existing_customer->email) {
            $existing_email = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ims_customers WHERE email = %s AND id != %d",
                $body['email'],
                $customer_id
            ));
            
            if ($existing_email) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Email already exists'
                ), 400);
            }
        }
        
        // Prepare update data
        $update_data = array();
        $format = array();
        
        if (isset($body['name'])) {
            $update_data['name'] = sanitize_text_field($body['name']);
            $format[] = '%s';
        }
        
        if (isset($body['email'])) {
            $update_data['email'] = !empty($body['email']) ? sanitize_email($body['email']) : null;
            $format[] = '%s';
        }
        
        if (isset($body['phone'])) {
            $update_data['phone'] = !empty($body['phone']) ? sanitize_text_field($body['phone']) : null;
            $format[] = '%s';
        }
        
       if (isset($body['type']) && in_array($body['type'], ['Temporary', 'Semi-Permanent', 'Permanent'])) {
    $update_data['type'] = $body['type'];
    $format[] = '%s';
}

        
        if (isset($body['address'])) {
            $update_data['address'] = !empty($body['address']) ? sanitize_textarea_field($body['address']) : null;
            $format[] = '%s';
        }
        
        if (isset($body['city'])) {
            $update_data['city'] = !empty($body['city']) ? sanitize_text_field($body['city']) : null;
            $format[] = '%s';
        }
        
        if (isset($body['status']) && in_array($body['status'], ['active', 'inactive'])) {
            $update_data['status'] = $body['status'];
            $format[] = '%s';
        }
        
        if (isset($body['creditLimit'])) {
            $update_data['credit_limit'] = (float) $body['creditLimit'];
            $format[] = '%f';
        }
        
        if (!empty($update_data)) {
            $update_data['updated_at'] = current_time('mysql');
            $format[] = '%s';
            
            $result = $wpdb->update(
                $wpdb->prefix . 'ims_customers',
                $update_data,
                array('id' => $customer_id),
                $format,
                array('%d')
            );
            
            if ($result === false) {
                throw new Exception('Failed to update customer: ' . $wpdb->last_error);
            }
        }
        
        // Get updated customer
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                id, name, email, phone, type, address, city, status,
                credit_limit as creditLimit, 
                current_balance as currentBalance,
                total_purchases as totalPurchases,
                created_at as createdAt,
                updated_at as updatedAt
             FROM {$wpdb->prefix}ims_customers 
             WHERE id = %d",
            $customer_id
        ));
        
        // Format numbers
        $customer->creditLimit = (float) $customer->creditLimit;
        $customer->currentBalance = (float) $customer->currentBalance;
        $customer->totalPurchases = (float) $customer->totalPurchases;
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $customer,
            'message' => 'Customer updated successfully'
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to update customer',
            'error' => $e->getMessage()
        ), 500);
    }
}

/**
 * DELETE /customers/{id} - Delete customer (soft delete by setting status to inactive)
 */
function ims_delete_customer($request) {
    global $wpdb;
    
    try {
        $customer_id = (int) $request['id'];
        
        // Check if customer exists
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_customers WHERE id = %d",
            $customer_id
        ));
        
        if (!$customer) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Customer not found'
            ), 404);
        }
        
        // Check if customer has active orders or outstanding balance
        $active_orders = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ims_sales WHERE customer_id = %d AND status IN ('pending', 'confirmed')",
            $customer_id
        ));
        
        if ($active_orders > 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Cannot delete customer with active orders'
            ), 400);
        }
        
        if ($customer->current_balance > 0) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Cannot delete customer with outstanding balance'
            ), 400);
        }
        
        // Soft delete - set status to inactive
        $result = $wpdb->update(
            $wpdb->prefix . 'ims_customers',
            array(
                'status' => 'inactive',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $customer_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            throw new Exception('Failed to delete customer: ' . $wpdb->last_error);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Customer deleted successfully'
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to delete customer',
            'error' => $e->getMessage()
        ), 500);
    }
}

/**
 * Helper function to handle database errors
 */
function ims_handle_db_error($wpdb) {
    if (!empty($wpdb->last_error)) {
        error_log('IMS Database Error: ' . $wpdb->last_error);
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Database error occurred'
        ), 500);
    }
    return null;
}

/**
 * Helper function to validate customer data
 */
function ims_validate_customer_data($data, $is_update = false) {
    $errors = array();
    
    if (!$is_update && empty($data['name'])) {
        $errors[] = 'Name is required';
    }
    
    if (!empty($data['email']) && !is_email($data['email'])) {
        $errors[] = 'Invalid email format';
    }
    
    if (!empty($data['type']) && !in_array($data['type'], ['Temporary', 'Semi-Permanent', 'Permanent'])) {
        $errors[] = 'Type must be Temporary, Semi-Permanent, or Permanent';
    }
    
    if (!empty($data['status']) && !in_array($data['status'], ['active', 'inactive'])) {
        $errors[] = 'Status must be either active or inactive';
    }
    
    if (isset($data['creditLimit']) && !is_numeric($data['creditLimit'])) {
        $errors[] = 'Credit limit must be a number';
    }
    
    return $errors;
}

















///////////////////////////////////// 	PDF EXPORT 


// Register custom REST API endpoint for customer orders
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/customers/(?P<customerId>\d+)/orders', array(
        'methods' => 'GET',
        'callback' => 'ims_get_customer_orders',
        'permission_callback' => '__return_true',
        'args' => array(
            'customerId' => array(
                'required' => true,
                'type' => 'integer',
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                }
            ),
            'status' => array(
                'required' => false,
                'type' => 'string',
                'enum' => array('pending', 'completed', 'cancelled', 'all'),
                'default' => 'all'
            ),
            'includeItems' => array(
                'required' => false,
                'type' => 'boolean',
                'default' => true
            ),
            'dateFrom' => array(
                'required' => false,
                'type' => 'string',
                'validate_callback' => function($param) {
                    return empty($param) || DateTime::createFromFormat('Y-m-d', $param) !== false;
                }
            ),
            'dateTo' => array(
                'required' => false,
                'type' => 'string',
                'validate_callback' => function($param) {
                    return empty($param) || DateTime::createFromFormat('Y-m-d', $param) !== false;
                }
            )
        )
    ));
});

// Callback function to handle customer orders endpoint
function ims_get_customer_orders($request) {
    global $wpdb;
    
    $customer_id = $request['customerId'];
    $status = $request['status'];
    $include_items = $request['includeItems'];
    $date_from = $request['dateFrom'];
    $date_to = $request['dateTo'];
    
    // Validate customer exists
    $customer_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_customers WHERE id = %d",
        $customer_id
    ));
    
    if (!$customer_exists) {
        return new WP_Error(
            'customer_not_found',
            'Customer not found',
            array('status' => 404)
        );
    }
    
    // Build the WHERE clause
    $where_conditions = array('customer_id = %d');
    $where_params = array($customer_id);

    if ($status !== 'all') {
        $where_conditions[] = 'status = %s';
        $where_params[] = $status;
    }
    
    if (!empty($date_from)) {
        $where_conditions[] = 'date >= %s';
        $where_params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $where_conditions[] = 'date <= %s';
        $where_params[] = $date_to;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get orders
    $orders = $wpdb->get_results($wpdb->prepare(
        "SELECT id, order_number, customer_id, date, created_at, subtotal, discount, tax, total as total_amount,
                status, payment_method, 
                CASE 
                    WHEN status = 'completed' AND payment_method = 'cash' 
                    THEN 'paid'
                    ELSE 'pending'
                END as payment_status,
                notes
         FROM {$wpdb->prefix}ims_sales
         WHERE $where_clause
         ORDER BY date DESC",
        $where_params
    ), ARRAY_A);
    
    // Calculate totals
    $total_orders = count($orders);
    $total_value = array_sum(array_column($orders, 'total_amount'));
    
    // Process order items if requested
    if ($include_items) {
        foreach ($orders as &$order) {
            $order['items'] = $wpdb->get_results($wpdb->prepare(
                "SELECT si.id, si.product_id, p.name as product_name, si.quantity, 
                        si.unit_price, si.total
                 FROM {$wpdb->prefix}ims_sale_items si
                 JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
                 WHERE si.sale_id = %d",
                $order['id']
            ), ARRAY_A);
        }
    }
    
    // Format response
    $response = array(
        'success' => true,
        'data' => array(
            'orders' => $orders,
            'totalOrders' => $total_orders,
            'totalValue' => floatval($total_value)
        ),
        'message' => 'Customer orders retrieved successfully'
    );
    
    return rest_ensure_response($response);
}




// Register custom REST API routes
add_action('rest_api_init', function () {
    // Update Customer Balance (for manual adjustments)
    register_rest_route('ims/v1', '/customers/update-balance', array(
        'methods' => 'POST',
        'callback' => 'ims_update_customer_balance',
        'permission_callback' => '__return_true'
    ));
	 // Get All Credit Customers
    register_rest_route('ims/v1', '/creditcustomers', array(
        'methods' => 'GET',
        'callback' => 'ims_get_credit_customers',
        'permission_callback' => '__return_true'
    ));
    // Get Customer Balance Details
    register_rest_route('ims/v1', '/customers/(?P<customer_id>\d+)/balance', array(
        'methods' => 'GET',
        'callback' => 'ims_get_customer_balance',
        'permission_callback' => '__return_true'
    ));
    
    // Add Credit to Existing Customer
    register_rest_route('ims/v1', '/customers/(?P<customer_id>\d+)/credit', array(
        'methods' => 'POST',
        'callback' => 'ims_add_customer_credit',
        'permission_callback' => '__return_true'
    ));
    
    // Record Customer Payment
    register_rest_route('ims/v1', '/customers/(?P<customer_id>\d+)/payment', array(
        'methods' => 'POST',
        'callback' => 'ims_record_customer_payment',
        'permission_callback' => '__return_true'
    ));
    
    // Get Transaction History
    register_rest_route('ims/v1', '/customers/(?P<customer_id>\d+)/transactions', array(
        'methods' => 'GET',
        'callback' => 'ims_get_customer_transactions',
        'permission_callback' => '__return_true'
    ));
    
    // Create New Customer with Initial Credit
    register_rest_route('ims/v1', '/customers', array(
        'methods' => 'POST',
        'callback' => 'ims_create_customer_initial_credit',
        'permission_callback' => '__return_true'
    ));
   
});

// 1. Get Customer Balance Details
function ims_get_customer_balance($request) {
    global $wpdb;
    
    $customer_id = $request['customer_id'];
    $customers_table = $wpdb->prefix . 'ims_customers';
    $sales_table = $wpdb->prefix . 'ims_sales';
    $payments_table = $wpdb->prefix . 'ims_payments';
    
    // Get customer basic info
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, current_balance, credit_limit, total_purchases 
         FROM $customers_table 
         WHERE id = %d",
        $customer_id
    ));
    
    if (!$customer) {
        return new WP_Error('not_found', 'Customer not found', array('status' => 404));
    }
    
    // Get last transaction date from sales or payments
    $last_sale = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(created_at) 
         FROM $sales_table 
         WHERE customer_id = %d AND status != 'cancelled'",
        $customer_id
    ));
    
    $last_payment = $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(created_at) 
         FROM $payments_table 
         WHERE customer_id = %d",
        $customer_id
    ));
    
    // Determine the most recent transaction
    $last_transaction = max($last_sale, $last_payment);
    
    // Calculate total paid from payments table (receipts are positive amounts)
    $total_paid = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount), 0) 
         FROM $payments_table 
         WHERE customer_id = %d AND payment_type = 'receipt'",
        $customer_id
    ));
    
    // Calculate total credit sales (completed sales that increase balance)
    $total_credit_sales = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total), 0) 
         FROM $sales_table 
         WHERE customer_id = %d AND status = 'completed' AND payment_method = 'credit'",
        $customer_id
    ));
    
    return array(
        'success' => true,
        'data' => array(
            'customer_id' => (int)$customer->id,
            'name' => $customer->name,
            'currentBalance' => (float)$customer->current_balance,
            'totalCredit' => (float)$total_credit_sales,
            'totalPaid' => (float)$total_paid,
            'lastTransaction' => $last_transaction ?: null,
            'creditLimit' => (float)$customer->credit_limit
        )
    );
}

// 2. Add Credit to Existing Customer
function ims_add_customer_credit($request) {
    global $wpdb;
    
    $customer_id = $request['customer_id'];
    $amount = floatval($request['amount']);
    $notes = sanitize_text_field($request['notes']);
    
    if ($amount <= 0) {
        return new WP_Error('invalid_amount', 'Amount must be greater than 0', array('status' => 400));
    }
    
    $customers_table = $wpdb->prefix . 'ims_customers';
    $transactions_table = $wpdb->prefix . 'ims_transactions';
    
    // Get current customer balance
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT current_balance FROM $customers_table WHERE id = %d",
        $customer_id
    ));
    
    if (!$customer) {
        return new WP_Error('not_found', 'Customer not found', array('status' => 404));
    }
    
    $current_balance = floatval($customer->current_balance);
    $new_balance = $current_balance + $amount;
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Update customer balance
        $wpdb->update(
            $customers_table,
            array('current_balance' => $new_balance),
            array('id' => $customer_id),
            array('%f'),
            array('%d')
        );
        
        // Create transaction record
        $transaction_number = 'CREDIT-' . date('YmdHis');
        $wpdb->insert(
            $transactions_table,
            array(
                'transaction_date' => current_time('mysql'),
                'transaction_number' => $transaction_number,
                'description' => $notes ?: 'Manual credit adjustment',
                'reference_type' => 'adjustment',
                'reference_id' => $customer_id,
                'total_amount' => $amount,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%f', '%s')
        );
        
        $transaction_id = $wpdb->insert_id;
        
        $wpdb->query('COMMIT');
        
        return array(
            'success' => true,
            'message' => 'Credit added successfully',
            'data' => array(
                'transaction_id' => $transaction_id,
                'customer_id' => (int)$customer_id,
                'amount' => $amount,
                'type' => 'credit',
                'new_balance' => $new_balance,
                'created_at' => current_time('mysql')
            )
        );
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('transaction_failed', 'Failed to add credit', array('status' => 500));
    }
}

// 3. Record Customer Payment
function ims_record_customer_payment($request) {
    global $wpdb;
    
    $customer_id = $request['customer_id'];
    $amount = floatval($request['amount']);
    $method = sanitize_text_field($request['method']);
    $reference = sanitize_text_field($request['reference']);
    $notes = sanitize_text_field($request['notes']);
    
    if ($amount <= 0) {
        return new WP_Error('invalid_amount', 'Amount must be greater than 0', array('status' => 400));
    }
    
    $customers_table = $wpdb->prefix . 'ims_customers';
    $payments_table = $wpdb->prefix . 'ims_payments';
    $transactions_table = $wpdb->prefix . 'ims_transactions';
    
    // Get current customer balance
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT current_balance FROM $customers_table WHERE id = %d",
        $customer_id
    ));
    
    if (!$customer) {
        return new WP_Error('not_found', 'Customer not found', array('status' => 404));
    }
    
    $current_balance = floatval($customer->current_balance);
    $new_balance = $current_balance - $amount; // Payment reduces balance
    
    // Validate payment method
    $valid_methods = ['cash', 'bank_transfer', 'cheque'];
    if (!in_array($method, $valid_methods)) {
        $method = 'cash';
    }
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Update customer balance
        $wpdb->update(
            $customers_table,
            array('current_balance' => $new_balance),
            array('id' => $customer_id),
            array('%f'),
            array('%d')
        );
        
        // Create payment record
        $wpdb->insert(
            $payments_table,
            array(
                'customer_id' => $customer_id,
                'amount' => $amount,
                'payment_method' => $method,
                'reference' => $reference,
                'notes' => $notes,
                'date' => current_time('mysql'),
                'payment_type' => 'receipt',
                'status' => 'cleared',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        
        $payment_id = $wpdb->insert_id;
        
        // Create transaction record
        $transaction_number = 'PAYMENT-' . date('YmdHis');
        $wpdb->insert(
            $transactions_table,
            array(
                'transaction_date' => current_time('mysql'),
                'transaction_number' => $transaction_number,
                'description' => $notes ?: 'Customer payment received',
                'reference_type' => 'payment',
                'reference_id' => $payment_id,
                'total_amount' => $amount,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%f', '%s')
        );
        
        $transaction_id = $wpdb->insert_id;
        
        $wpdb->query('COMMIT');
        
        return array(
            'success' => true,
            'message' => 'Payment recorded successfully',
            'data' => array(
                'transaction_id' => $transaction_id,
                'payment_id' => $payment_id,
                'customer_id' => (int)$customer_id,
                'amount' => $amount,
                'method' => $method,
                'new_balance' => $new_balance,
                'created_at' => current_time('mysql')
            )
        );
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('transaction_failed', 'Failed to record payment', array('status' => 500));
    }
}

// 4. Get Transaction History
function ims_get_customer_transactions($request) {
    global $wpdb;
    
    $customer_id = $request['customer_id'];
    $limit = isset($request['limit']) ? intval($request['limit']) : 50;
    $offset = isset($request['offset']) ? intval($request['offset']) : 0;
    $type = isset($request['type']) ? sanitize_text_field($request['type']) : '';
    
    $sales_table = $wpdb->prefix . 'ims_sales';
    $payments_table = $wpdb->prefix . 'ims_payments';
    $transactions_table = $wpdb->prefix . 'ims_transactions';
    
    // Combine sales and payments into a unified transactions view
    $sales_query = $wpdb->prepare(
        "SELECT 
            id as source_id,
            'sale' as type,
            total as amount,
            created_at,
            order_number as reference,
            notes,
            payment_method as method,
            'sale' as source_table
         FROM $sales_table 
         WHERE customer_id = %d AND status != 'cancelled'",
        $customer_id
    );
    
    $payments_query = $wpdb->prepare(
        "SELECT 
            id as source_id,
            'payment' as type,
            amount,
            created_at,
            reference,
            notes,
            payment_method as method,
            'payment' as source_table
         FROM $payments_table 
         WHERE customer_id = %d AND payment_type = 'receipt'",
        $customer_id
    );
    
    // Combine the queries
    $union_query = "($sales_query) UNION ALL ($payments_query)";
    
    // Add type filter if specified
    $where_conditions = ["1=1"];
    $query_params = [];
    
    if ($type && in_array($type, ['sale', 'payment'])) {
        $where_conditions[] = "type = %s";
        $query_params[] = $type;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get transactions with pagination
    $transactions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM ($union_query) as combined 
         WHERE $where_clause 
         ORDER BY created_at DESC 
         LIMIT %d OFFSET %d",
        array_merge($query_params, [$limit, $offset])
    ));
    
    // Get total count
    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ($union_query) as combined WHERE $where_clause",
        $query_params
    ));
    
    $formatted_transactions = array();
    foreach ($transactions as $transaction) {
        $amount = (float)$transaction->amount;
        $transaction_type = $transaction->type;
        
        // For display, show positive amounts for both sales and payments
        // but you might want to show payments as negative in some contexts
        $display_amount = $transaction_type === 'payment' ? -$amount : $amount;
        
        $formatted_transactions[] = array(
            'id' => (int)$transaction->source_id,
            'type' => $transaction_type,
            'amount' => $display_amount,
            'reference' => $transaction->reference,
            'method' => $transaction->method,
            'notes' => $transaction->notes,
            'created_at' => $transaction->created_at,
            'source_table' => $transaction->source_table
        );
    }
    
    return array(
        'success' => true,
        'data' => array(
            'transactions' => $formatted_transactions,
            'total' => (int)$total,
            'has_more' => ($offset + $limit) < $total
        )
    );
}

// 5. Create New Customer with Initial Credit
function ims_create_customer_initial_credit($request) {
    global $wpdb;
    
    $name = sanitize_text_field($request['name']);
    $email = sanitize_email($request['email']);
    $phone = sanitize_text_field($request['phone']);
    $type = sanitize_text_field($request['type']);
    $credit_limit = isset($request['creditLimit']) ? floatval($request['creditLimit']) : 0;
    $initial_credit = isset($request['initialCredit']) ? floatval($request['initialCredit']) : 0;
    $address = sanitize_text_field($request['address']);
    $city = sanitize_text_field($request['city']);
    
    if (empty($name)) {
        return new WP_Error('invalid_data', 'Name is required', array('status' => 400));
    }
    
    $customers_table = $wpdb->prefix . 'ims_customers';
    $transactions_table = $wpdb->prefix . 'ims_transactions';
    
    // Validate customer type
    $valid_types = ['Temporary', 'Semi-Permanent', 'Permanent'];
    if (!in_array($type, $valid_types)) {
        $type = 'Permanent';
    }
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Create customer
        $wpdb->insert(
            $customers_table,
            array(
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'type' => $type,
                'address' => $address,
                'city' => $city,
                'credit_limit' => $credit_limit,
                'current_balance' => $initial_credit,
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%s')
        );
        
        $customer_id = $wpdb->insert_id;
        
        // If initial credit is provided, record transaction
        if ($initial_credit > 0) {
            $transaction_number = 'INIT-CREDIT-' . date('YmdHis');
            $wpdb->insert(
                $transactions_table,
                array(
                    'transaction_date' => current_time('mysql'),
                    'transaction_number' => $transaction_number,
                    'description' => 'Initial credit for new customer',
                    'reference_type' => 'adjustment',
                    'reference_id' => $customer_id,
                    'total_amount' => $initial_credit,
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%f', '%s')
            );
        }
        
        $wpdb->query('COMMIT');
        
        return array(
            'success' => true,
            'message' => 'Customer created successfully',
            'data' => array(
                'id' => $customer_id,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'type' => $type,
                'currentBalance' => $initial_credit,
                'creditLimit' => $credit_limit,
                'created_at' => current_time('mysql')
            )
        );
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('creation_failed', 'Failed to create customer: ' . $e->getMessage(), array('status' => 500));
    }
}


// Update Customer Balance (Manual Adjustment) - Fixed version
function ims_update_customer_balance($request) {
    global $wpdb;
    
    $customer_id = intval($request['customerId']);
    $amount = floatval($request['amount']);
    $type = sanitize_text_field($request['type']); // 'credit' or 'debit'
    $description = sanitize_text_field($request['description']);
    
    // Allow both positive and negative amounts, but not zero
    if ($amount === 0.0) {
        return new WP_Error('invalid_amount', 'Amount cannot be zero', array('status' => 400));
    }
    
    if (!in_array($type, ['credit', 'debit'])) {
        return new WP_Error('invalid_type', 'Type must be credit or debit', array('status' => 400));
    }
    
    $customers_table = $wpdb->prefix . 'ims_customers';
    $transactions_table = $wpdb->prefix . 'ims_transactions';
    $payments_table = $wpdb->prefix . 'ims_payments';
    
    // Get current customer balance
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT current_balance, name FROM $customers_table WHERE id = %d",
        $customer_id
    ));
    
    if (!$customer) {
        return new WP_Error('not_found', 'Customer not found', array('status' => 404));
    }
    
    $current_balance = floatval($customer->current_balance);
    
    // Calculate new balance based on type and amount sign
    // For debit: negative amount increases balance, positive decreases
    // For credit: positive amount increases balance, negative decreases
    if ($type === 'debit') {
        // Debit with negative amount actually adds to balance (reversal)
        // Debit with positive amount reduces balance (normal payment)
        $new_balance = $current_balance - $amount;
        $transaction_type = 'payment';
        $payment_type = 'receipt';
    } else {
        // Credit with positive amount increases balance (normal credit)
        // Credit with negative amount reduces balance (reversal)
        $new_balance = $current_balance + $amount;
        $transaction_type = 'credit';
        $payment_type = 'credit_adjustment';
    }
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Update customer balance
        $wpdb->update(
            $customers_table,
            array('current_balance' => $new_balance),
            array('id' => $customer_id),
            array('%f'),
            array('%d')
        );
        
        // Determine if this is a normal transaction or reversal
        $is_reversal = ($type === 'debit' && $amount < 0) || ($type === 'credit' && $amount < 0);
        
        // If it's a debit (payment), record in payments table
        if ($type === 'debit') {
            $wpdb->insert(
                $payments_table,
                array(
                    'customer_id' => $customer_id,
                    'amount' => abs($amount), // Store absolute value
                    'payment_method' => 'cash', // Default to cash for manual adjustments
                    'reference' => 'MANUAL-' . date('YmdHis'),
                    'notes' => $description ?: ($is_reversal ? 'Payment reversal' : 'Manual payment'),
                    'date' => current_time('mysql'),
                    'payment_type' => 'receipt',
                    'status' => 'cleared',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            $payment_id = $wpdb->insert_id;
            
            // Create transaction record linked to payment
            $transaction_number = 'MANUAL-' . ($is_reversal ? 'REVERSAL-' : 'PAY-') . date('YmdHis');
            $wpdb->insert(
                $transactions_table,
                array(
                    'transaction_date' => current_time('mysql'),
                    'transaction_number' => $transaction_number,
                    'description' => $description ?: ($is_reversal ? 'Payment reversal' : 'Manual payment recorded'),
                    'reference_type' => 'payment',
                    'reference_id' => $payment_id,
                    'total_amount' => $amount, // Store the actual amount (could be negative)
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%f', '%s')
            );
        } else {
            // For credit adjustments, record in transactions table
            $transaction_number = 'MANUAL-' . ($is_reversal ? 'REVERSAL-' : 'CREDIT-') . date('YmdHis');
            $wpdb->insert(
                $transactions_table,
                array(
                    'transaction_date' => current_time('mysql'),
                    'transaction_number' => $transaction_number,
                    'description' => $description ?: ($is_reversal ? 'Credit reversal' : 'Manual credit adjustment'),
                    'reference_type' => 'adjustment',
                    'reference_id' => $customer_id,
                    'total_amount' => $amount, // Store the actual amount (could be negative)
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%d', '%f', '%s')
            );
        }
        
        $transaction_id = $wpdb->insert_id;
        
        $wpdb->query('COMMIT');
        
        return array(
            'success' => true,
            'message' => 'Balance updated successfully',
            'data' => array(
                'customer_id' => $customer_id,
                'customer_name' => $customer->name,
                'type' => $type,
                'amount' => $amount,
                'absolute_amount' => abs($amount),
                'previous_balance' => $current_balance,
                'new_balance' => $new_balance,
                'description' => $description,
                'transaction_id' => $transaction_id,
                'is_reversal' => $is_reversal,
                'updated_at' => current_time('mysql')
            )
        );
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('update_failed', 'Failed to update balance: ' . $e->getMessage(), array('status' => 500));
    }
}



// Get Customer Balance History
function ims_get_customer_balance_history($request) {
    global $wpdb;
    
    $customer_id = $request['customer_id'];
    $limit = isset($request['limit']) ? intval($request['limit']) : 50;
    $offset = isset($request['offset']) ? intval($request['offset']) : 0;
    
    $customers_table = $wpdb->prefix . 'ims_customers';
    $sales_table = $wpdb->prefix . 'ims_sales';
    $payments_table = $wpdb->prefix . 'ims_payments';
    $transactions_table = $wpdb->prefix . 'ims_transactions';
    
    // Verify customer exists
    $customer = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name FROM $customers_table WHERE id = %d",
        $customer_id
    ));
    
    if (!$customer) {
        return new WP_Error('not_found', 'Customer not found', array('status' => 404));
    }
    
    // Combine sales, payments, and manual transactions into unified history
    $sales_query = $wpdb->prepare(
        "SELECT 
            id as source_id,
            'sale' as type,
            total as amount,
            created_at,
            order_number as reference,
            notes,
            payment_method as method,
            'sale' as source_table,
            NULL as balance_after
         FROM $sales_table 
         WHERE customer_id = %d AND status != 'cancelled'",
        $customer_id
    );
    
    $payments_query = $wpdb->prepare(
        "SELECT 
            id as source_id,
            'payment' as type,
            amount,
            created_at,
            reference,
            notes,
            payment_method as method,
            'payment' as source_table,
            NULL as balance_after
         FROM $payments_table 
         WHERE customer_id = %d AND payment_type = 'receipt'",
        $customer_id
    );
    
    $transactions_query = $wpdb->prepare(
        "SELECT 
            id as source_id,
            CASE 
                WHEN reference_type = 'adjustment' AND total_amount > 0 THEN 'credit_adjustment'
                WHEN reference_type = 'adjustment' AND total_amount < 0 THEN 'debit_adjustment'
                ELSE reference_type
            END as type,
            total_amount as amount,
            created_at,
            transaction_number as reference,
            description as notes,
            'manual' as method,
            'transaction' as source_table,
            NULL as balance_after
         FROM $transactions_table 
         WHERE reference_id = %d AND reference_type IN ('adjustment', 'payment')",
        $customer_id
    );
    
    // Combine all queries
    $union_query = "($sales_query) UNION ALL ($payments_query) UNION ALL ($transactions_query)";
    
    // Get transactions with pagination
    $transactions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM ($union_query) as combined 
         ORDER BY created_at DESC 
         LIMIT %d OFFSET %d",
        array($limit, $offset)
    ));
    
    // Get total count
    $total = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ($union_query) as combined",
        $customer_id
    ));
    
    // Format transactions with proper descriptions and amounts
    $formatted_transactions = array();
    foreach ($transactions as $transaction) {
        $amount = (float)$transaction->amount;
        $transaction_type = $transaction->type;
        
        // Determine display amount and description
        switch ($transaction_type) {
            case 'sale':
                $display_amount = $amount;
                $description = 'Sale: ' . ($transaction->reference ?: 'No reference');
                break;
            case 'payment':
                $display_amount = -$amount; // Payments reduce balance
                $description = 'Payment: ' . ($transaction->notes ?: 'No description');
                break;
            case 'credit_adjustment':
                $display_amount = $amount;
                $description = 'Credit Adjustment: ' . ($transaction->notes ?: 'Manual credit');
                break;
            case 'debit_adjustment':
                $display_amount = $amount; // Already negative from database
                $description = 'Debit Adjustment: ' . ($transaction->notes ?: 'Manual debit');
                break;
            default:
                $display_amount = $amount;
                $description = $transaction->notes ?: 'Transaction';
        }
        
        $formatted_transactions[] = array(
            'id' => (int)$transaction->source_id,
            'type' => $transaction_type,
            'amount' => $display_amount,
            'absolute_amount' => abs($display_amount),
            'reference' => $transaction->reference,
            'method' => $transaction->method,
            'description' => $description,
            'notes' => $transaction->notes,
            'created_at' => $transaction->created_at,
            'source_table' => $transaction->source_table
        );
    }
    
    return array(
        'success' => true,
        'data' => array(
            'customer_id' => (int)$customer_id,
            'customer_name' => $customer->name,
            'transactions' => $formatted_transactions,
            'pagination' => array(
                'total' => (int)$total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total
            )
        )
    );
}



// Get All Credit Customers
function ims_get_credit_customers($request) {
    global $wpdb;
    
    $customers_table = $wpdb->prefix . 'ims_customers';
    
    // Get pagination parameters
    $page = isset($request['page']) ? max(1, intval($request['page'])) : 1;
    $per_page = isset($request['per_page']) ? min(max(1, intval($request['per_page'])), 200) : 100; // Max 200 per page
    $offset = ($page - 1) * $per_page;
    
    // Optional filters
    $status = isset($request['status']) ? sanitize_text_field($request['status']) : '';
    $type = isset($request['type']) ? sanitize_text_field($request['type']) : '';
    $search = isset($request['search']) ? sanitize_text_field($request['search']) : '';
    
    // Build WHERE conditions
    $where_conditions = array("1=1");
    $query_params = array();
    
    // Filter by status
    if ($status && in_array($status, ['active', 'inactive'])) {
        $where_conditions[] = "status = %s";
        $query_params[] = $status;
    }
    
    // Filter by type
    if ($type && in_array($type, ['Temporary', 'Semi-Permanent', 'Permanent'])) {
        $where_conditions[] = "type = %s";
        $query_params[] = $type;
    }
    
    // Search filter (name, email, phone)
    if ($search) {
        $where_conditions[] = "(name LIKE %s OR email LIKE %s OR phone LIKE %s)";
        $query_params[] = '%' . $wpdb->esc_like($search) . '%';
        $query_params[] = '%' . $wpdb->esc_like($search) . '%';
        $query_params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    
    // Filter customers with credit (non-zero balance or credit limit)
	$where_conditions[] = "(current_balance <> 0)";

    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $total_query = "SELECT COUNT(*) FROM $customers_table WHERE $where_clause";
    if ($query_params) {
        $total_query = $wpdb->prepare($total_query, $query_params);
    }
    $total = $wpdb->get_var($total_query);
    
    // Get customers with pagination
    $customers_query = "SELECT 
        id, 
        name, 
        email, 
        phone, 
        type, 
        status, 
        current_balance, 
        credit_limit,
        address,
        city,
        created_at
    FROM $customers_table 
    WHERE $where_clause 
    ORDER BY current_balance DESC, name ASC 
    LIMIT %d OFFSET %d";
    
    // Add pagination parameters to query params
    $query_params[] = $per_page;
    $query_params[] = $offset;
    
    $customers = $wpdb->get_results($wpdb->prepare($customers_query, $query_params));
    
    // Format response
    $formatted_customers = array();
    foreach ($customers as $customer) {
        $formatted_customers[] = array(
            'id' => (int)$customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'type' => $customer->type,
            'status' => $customer->status,
            'currentBalance' => (float)$customer->current_balance,
            'creditLimit' => (float)$customer->credit_limit,
            'address' => $customer->address,
            'city' => $customer->city,
            'created_at' => $customer->created_at
        );
    }
    
    return array(
        'success' => true,
        'data' => array(
            'customers' => $formatted_customers,
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        )
    );
}





/**
 * Custom API Endpoints for Customer Management
 * Place this code in your theme's functions.php or custom plugin
 */

// Register custom API endpoints
add_action('rest_api_init', function () {

    // DELETE /customers/:id
    register_rest_route('ims/v1', '/customers/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'uh_delete_customer_by_id',
        'permission_callback' => '__return_true'
    ));

    // DELETE /customers/inactive/bulk-delete
    register_rest_route('ims/v1', '/customers/inactive/bulk-delete', array(
        'methods' => 'DELETE',
        'callback' => 'uh_bulk_delete_inactive_customers',
        'permission_callback' => '__return_true'
    ));
});
/**
 * DELETE /customers/:id
 * Delete a single customer by ID
 */
function uh_delete_customer_by_id(WP_REST_Request $request) {
    global $wpdb;
    
    $customer_id = (int)$request->get_param('id');
    $table_name = $wpdb->prefix . 'ims_customers';
    
    // Check if customer exists
    $existing_customer = $wpdb->get_row(
        $wpdb->prepare("SELECT id FROM $table_name WHERE id = %d", $customer_id)
    );
    
    if (!$existing_customer) {
        return new WP_Error('customer_not_found', 'Customer not found', array('status' => 404));
    }
    
    // Delete customer
    $deleted = $wpdb->delete(
        $table_name,
        ['id' => $customer_id],
        ['%d']
    );
    
    if ($deleted === false) {
        return new WP_Error('delete_failed', 'Failed to delete customer', array('status' => 500));
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Customer deleted'
    ], 200);
}

/**
 * DELETE /customers/inactive/bulk-delete
 * Delete all inactive customers at once
 */
function uh_bulk_delete_inactive_customers(WP_REST_Request $request) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ims_customers';
    
    // First, get count of inactive customers
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'inactive'");
    
    // Delete all inactive customers
    $deleted = $wpdb->query("DELETE FROM $table_name WHERE status = 'inactive'");
    
    if ($deleted === false) {
        return new WP_Error('bulk_delete_failed', 'Failed to delete inactive customers', array('status' => 500));
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'All inactive customers deleted',
        'count' => (int)$count
    ], 200);
}

/**
 * GET /customers/duplicates/phone - Get duplicate customers by phone
 */
function ims_get_duplicate_customers_by_phone($request) {
    global $wpdb;
    
    try {
        $results = $wpdb->get_results("CALL get_duplicate_customers_by_phone()");

        return new WP_REST_Response([
            'success' => true,
            'data' => $results
        ], 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to retrieve duplicate customers by phone',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * GET /customers/duplicates/name - Get duplicate customers by name
 */
function ims_get_duplicate_customers_by_name($request) {
    global $wpdb;
    
    try {
        $results = $wpdb->get_results("CALL GetDuplicateCustomerNames()");

        return new WP_REST_Response([
            'success' => true,
            'data' => $results
        ], 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to retrieve duplicate customers by name',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * POST /customers/merge - Merge customers
 */
function ims_merge_customers($request) {
    global $wpdb;
    
    try {
        $body = $request->get_json_params();
        
        $kept_id = isset($body['kept_id']) ? (int)$body['kept_id'] : 0;
        $merged_ids = isset($body['merged_ids']) ? $body['merged_ids'] : ''; 
        
        if (empty($kept_id) || empty($merged_ids)) {
             return new WP_REST_Response([
                'success' => false,
                'message' => 'kept_id and merged_ids are required.'
            ], 400);
        }

        // Ensure merged_ids is a string for the stored procedure
        if (is_array($merged_ids)) {
            $merged_ids = implode(',', $merged_ids);
        }

        // Sanitize string (simple check for comma-separated numbers)
        if (!preg_match('/^[\d,]+$/', $merged_ids)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid format for merged_ids. Must be comma-separated integers.'
            ], 400);
        }

        // Execute Stored Procedure
        $sql = $wpdb->prepare("CALL merge_customers(%d, %s)", $kept_id, $merged_ids);
        $result = $wpdb->get_row($sql);
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $result,
            'message' => 'Merge operation executed'
        ], 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Failed to merge customers',
            'error' => $e->getMessage()
        ], 500);
    }
}
