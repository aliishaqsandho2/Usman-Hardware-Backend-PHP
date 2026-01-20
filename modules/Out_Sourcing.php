<?php 



// Outsourcing API Endpoints
add_action('rest_api_init', function () {
    // 1. GET /outsourcing - List all outsourcing orders
    register_rest_route('ims/v1', '/outsourcing', array(
        'methods' => 'GET',
        'callback' => 'get_outsourcing_orders',
        'permission_callback' => '__return_true'
    ));
    
    // 2. PUT /outsourcing/(?P<id>\d+)/status - Update outsourcing order status
    register_rest_route('ims/v1', '/outsourcing/(?P<id>\d+)/status', array(
        'methods' => 'PUT',
        'callback' => 'update_outsourcing_status',
        'permission_callback' => '__return_true'
    ));
    
    // 3. GET /outsourcing/supplier/(?P<supplierId>\d+) - Get orders by supplier
    register_rest_route('ims/v1', '/outsourcing/supplier/(?P<supplierId>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_outsourcing_by_supplier',
        'permission_callback' => '__return_true'
    ));
    
    // 4. POST /outsourcing - Create a new outsourcing order (NEW)
    register_rest_route('ims/v1', '/outsourcing', array(
        'methods' => 'POST',
        'callback' => 'create_outsourcing_order',
        'permission_callback' => '__return_true'
    ));
});

// 1. GET /outsourcing - List all outsourcing orders
function get_outsourcing_orders($request) {
    global $wpdb;
    
    // Get parameters
    $page = $request->get_param('page') ? intval($request->get_param('page')) : 1;
    $limit = $request->get_param('limit') ? intval($request->get_param('limit')) : 10;
    $status = $request->get_param('status');
    $supplier_id = $request->get_param('supplier_id');
    $date_from = $request->get_param('date_from');
    $date_to = $request->get_param('date_to');
    $search = $request->get_param('search');
    
    $offset = ($page - 1) * $limit;
    
    // Build query
    $query = "SELECT o.*, p.name as product_name, s.name as supplier_name
              FROM {$wpdb->prefix}ims_outsourcing_orders o
              LEFT JOIN {$wpdb->prefix}ims_products p ON o.product_id = p.id
              LEFT JOIN {$wpdb->prefix}ims_suppliers s ON o.supplier_id = s.id
              WHERE 1=1";
    
    $count_query = "SELECT COUNT(o.id)
                    FROM {$wpdb->prefix}ims_outsourcing_orders o
                    LEFT JOIN {$wpdb->prefix}ims_products p ON o.product_id = p.id
                    LEFT JOIN {$wpdb->prefix}ims_suppliers s ON o.supplier_id = s.id
                    WHERE 1=1";
    
    $params = array();
    
    // Add filters
    if ($status) {
        $query .= " AND o.status = %s";
        $count_query .= " AND o.status = %s";
        $params[] = $status;
    }
    
    if ($supplier_id) {
        $query .= " AND o.supplier_id = %d";
        $count_query .= " AND o.supplier_id = %d";
        $params[] = $supplier_id;
    }
    
    if ($date_from) {
        $query .= " AND DATE(o.created_at) >= %s";
        $count_query .= " AND DATE(o.created_at) >= %s";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $query .= " AND DATE(o.created_at) <= %s";
        $count_query .= " AND DATE(o.created_at) <= %s";
        $params[] = $date_to;
    }
    
    if ($search) {
        $query .= " AND (p.name LIKE %s OR s.name LIKE %s OR o.order_number LIKE %s)";
        $count_query .= " AND (p.name LIKE %s OR s.name LIKE %s OR o.order_number LIKE %s)";
        $search_term = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    // Add ordering and pagination
    $query .= " ORDER BY o.created_at DESC LIMIT %d OFFSET %d";
    $params[] = $limit;
    $params[] = $offset;
    
    // Prepare and execute queries
    if (!empty($params)) {
        $orders = $wpdb->get_results($wpdb->prepare($query, $params));
        $total_items = $wpdb->get_var($wpdb->prepare($count_query, $params));
    } else {
        $orders = $wpdb->get_results($query);
        $total_items = $wpdb->get_var($count_query);
    }
    
    // Calculate pagination details
    $total_pages = ceil($total_items / $limit);
    
    // Format response
    $formatted_orders = array();
    foreach ($orders as $order) {
        $formatted_orders[] = array(
            'id' => intval($order->id),
            'order_number' => $order->order_number,
            'sale_id' => intval($order->sale_id),
            'sale_item_id' => $order->sale_item_id ? intval($order->sale_item_id) : null,
            'product_id' => intval($order->product_id),
            'supplier_id' => intval($order->supplier_id),
            'quantity' => floatval($order->quantity),
            'cost_per_unit' => floatval($order->cost_per_unit),
            'total_cost' => floatval($order->total_cost),
            'notes' => $order->notes,
            'status' => $order->status,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'product_name' => $order->product_name,
            'supplier_name' => $order->supplier_name
        );
    }
    
    $response = array(
        'success' => true,
        'data' => array(
            'orders' => $formatted_orders,
            'pagination' => array(
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_items' => intval($total_items),
                'items_per_page' => $limit,
                'has_next_page' => $page < $total_pages,
                'has_previous_page' => $page > 1
            )
        )
    );
    
    return new WP_REST_Response($response, 200);
}

// 2. PUT /outsourcing/{id}/status - Update outsourcing order status
function update_outsourcing_status($request) {
    global $wpdb;
    
    $id = $request->get_param('id');
    $status = $request->get_param('status');
    $notes = $request->get_param('notes');
    
    // Validate status
    $valid_statuses = array('pending', 'ordered', 'delivered', 'cancelled');
    if (!in_array($status, $valid_statuses)) {
        return new WP_Error('invalid_status', 'Invalid status value. Valid values: ' . implode(', ', $valid_statuses), array('status' => 400));
    }
    
    // Check if order exists
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_outsourcing_orders WHERE id = %d",
        $id
    ));
    
    if (!$order) {
        return new WP_Error('not_found', 'Outsourcing order not found', array('status' => 404));
    }
    
    // If status is being changed to delivered, update product stock
    if ($status === 'delivered' && $order->status !== 'delivered') {
        // Get product current stock
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT stock FROM {$wpdb->prefix}ims_products WHERE id = %d",
            $order->product_id
        ));
        
        if ($product) {
            $new_stock = $product->stock + $order->quantity;
            
            // Update product stock
            $wpdb->update(
                $wpdb->prefix . 'ims_products',
                array('stock' => $new_stock),
                array('id' => $order->product_id),
                array('%d'),
                array('%d')
            );
            
            // Record inventory movement
            $wpdb->insert(
                $wpdb->prefix . 'ims_inventory_movements',
                array(
                    'product_id' => $order->product_id,
                    'type' => 'outsourcing_delivery',
                    'quantity' => $order->quantity,
                    'balance_before' => $product->stock,
                    'balance_after' => $new_stock,
                    'reference' => $order->order_number,
                    'reason' => 'Outsourcing delivery'
                ),
                array('%d', '%s', '%d', '%d', '%d', '%s', '%s')
            );
        }
    }
    
    // Prepare update data
    $update_data = array(
        'status' => $status,
        'updated_at' => current_time('mysql', 1)
    );
    
    if ($notes) {
        $current_notes = $order->notes ? $order->notes . "\n" : '';
        $update_data['notes'] = $current_notes . date('Y-m-d H:i:s') . ': ' . $notes;
    }
    
    // Update order
    $result = $wpdb->update(
        $wpdb->prefix . 'ims_outsourcing_orders',
        $update_data,
        array('id' => $id),
        array('%s', '%s', '%s'),
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('update_failed', 'Failed to update order status', array('status' => 500));
    }
    
    // Get updated order with product and supplier details
    $updated_order = $wpdb->get_row($wpdb->prepare(
        "SELECT o.*, p.name as product_name, s.name as supplier_name
         FROM {$wpdb->prefix}ims_outsourcing_orders o
         LEFT JOIN {$wpdb->prefix}ims_products p ON o.product_id = p.id
         LEFT JOIN {$wpdb->prefix}ims_suppliers s ON o.supplier_id = s.id
         WHERE o.id = %d",
        $id
    ));
    
    // Format response
    $response_data = array(
        'id' => intval($updated_order->id),
        'order_number' => $updated_order->order_number,
        'sale_id' => intval($updated_order->sale_id),
        'sale_item_id' => $updated_order->sale_item_id ? intval($updated_order->sale_item_id) : null,
        'product_id' => intval($updated_order->product_id),
        'supplier_id' => intval($updated_order->supplier_id),
        'quantity' => floatval($updated_order->quantity),
        'cost_per_unit' => floatval($updated_order->cost_per_unit),
        'total_cost' => floatval($updated_order->total_cost),
        'notes' => $updated_order->notes,
        'status' => $updated_order->status,
        'created_at' => $updated_order->created_at,
        'updated_at' => $updated_order->updated_at,
        'product_name' => $updated_order->product_name,
        'supplier_name' => $updated_order->supplier_name
    );
    
    $response = array(
        'success' => true,
        'data' => $response_data
    );
    
    return new WP_REST_Response($response, 200);
}

// 3. GET /outsourcing/supplier/{supplierId} - Get orders by supplier
function get_outsourcing_by_supplier($request) {
    global $wpdb;
    
    $supplier_id = $request->get_param('supplierId');
    $page = $request->get_param('page') ? intval($request->get_param('page')) : 1;
    $limit = $request->get_param('limit') ? intval($request->get_param('limit')) : 10;
    $status = $request->get_param('status');
    $date_from = $request->get_param('date_from');
    $date_to = $request->get_param('date_to');
    
    $offset = ($page - 1) * $limit;
    
    // Check if supplier exists
    $supplier = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d",
        $supplier_id
    ));
    
    if (!$supplier) {
        return new WP_Error('not_found', 'Supplier not found', array('status' => 404));
    }
    
    // Build query
    $query = "SELECT o.*, p.name as product_name
              FROM {$wpdb->prefix}ims_outsourcing_orders o
              LEFT JOIN {$wpdb->prefix}ims_products p ON o.product_id = p.id
              WHERE o.supplier_id = %d";
    
    $count_query = "SELECT COUNT(o.id)
                    FROM {$wpdb->prefix}ims_outsourcing_orders o
                    WHERE o.supplier_id = %d";
    
    $params = array($supplier_id);
    
    // Add filters
    if ($status) {
        $query .= " AND o.status = %s";
        $count_query .= " AND o.status = %s";
        $params[] = $status;
    }
    
    if ($date_from) {
        $query .= " AND DATE(o.created_at) >= %s";
        $count_query .= " AND DATE(o.created_at) >= %s";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $query .= " AND DATE(o.created_at) <= %s";
        $count_query .= " AND DATE(o.created_at) <= %s";
        $params[] = $date_to;
    }
    
    // Add ordering and pagination
    $query .= " ORDER BY o.created_at DESC LIMIT %d OFFSET %d";
    $params[] = $limit;
    $params[] = $offset;
    
    // Prepare and execute queries
    $orders = $wpdb->get_results($wpdb->prepare($query, $params));
    
    // For count query, we need to handle parameters differently
    $count_params = array($supplier_id);
    if ($status) $count_params[] = $status;
    if ($date_from) $count_params[] = $date_from;
    if ($date_to) $count_params[] = $date_to;
    
    if (count($count_params) > 1) {
        $count_placeholders = array_fill(0, count($count_params) - 1, '%s');
        array_unshift($count_placeholders, '%d');
        $count_query_prepared = $wpdb->prepare($count_query, $count_params);
        $total_items = $wpdb->get_var($count_query_prepared);
    } else {
        $total_items = $wpdb->get_var($wpdb->prepare($count_query, $supplier_id));
    }
    
    // Calculate pagination details
    $total_pages = ceil($total_items / $limit);
    
    // Format orders
    $formatted_orders = array();
    foreach ($orders as $order) {
        $formatted_orders[] = array(
            'id' => intval($order->id),
            'order_number' => $order->order_number,
            'sale_id' => intval($order->sale_id),
            'sale_item_id' => $order->sale_item_id ? intval($order->sale_item_id) : null,
            'product_id' => intval($order->product_id),
            'supplier_id' => intval($order->supplier_id),
            'quantity' => floatval($order->quantity),
            'cost_per_unit' => floatval($order->cost_per_unit),
            'total_cost' => floatval($order->total_cost),
            'notes' => $order->notes,
            'status' => $order->status,
            'created_at' => $order->created_at,
            'updated_at' => $order->updated_at,
            'product_name' => $order->product_name
        );
    }
    
    // Format supplier info
    $formatted_supplier = array(
        'id' => intval($supplier->id),
        'name' => $supplier->name,
        'contact_person' => $supplier->contact_person,
        'phone' => $supplier->phone,
        'email' => $supplier->email
    );
    
    $response = array(
        'success' => true,
        'data' => array(
            'orders' => $formatted_orders,
            'pagination' => array(
                'current_page' => $page,
                'total_pages' => $total_pages,
                'total_items' => intval($total_items),
                'items_per_page' => $limit,
                'has_next_page' => $page < $total_pages,
                'has_previous_page' => $page > 1
            ),
            'supplier' => $formatted_supplier
        )
    );
    
    return new WP_REST_Response($response, 200);
}

// 4. POST /outsourcing - Create a new outsourcing order (NEW)
function create_outsourcing_order($request) {
    global $wpdb;
    
    try {
        $body = $request->get_json_params();
        
        // Validate required fields
        $required_fields = array('productId', 'supplierId', 'quantity', 'costPerUnit');
        foreach ($required_fields as $field) {
            if (!isset($body[$field]) || empty($body[$field])) {
                return new WP_Error('missing_field', 'Missing required field: ' . $field, array('status' => 400));
            }
        }
        
        // Check if product exists
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_products WHERE id = %d AND status = 'active'",
            $body['productId']
        ));
        
        if (!$product) {
            return new WP_Error('not_found', 'Product not found', array('status' => 404));
        }
        
        // Check if supplier exists
        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d",
            $body['supplierId']
        ));
        
        if (!$supplier) {
            return new WP_Error('not_found', 'Supplier not found', array('status' => 404));
        }
        
        // Generate order number
        $order_number = 'OUT-' . date('Ymd') . '-' . strtoupper(wp_generate_password(6, false));
        
        // Calculate total cost
        $quantity = floatval($body['quantity']);
        $cost_per_unit = floatval($body['costPerUnit']);
        $total_cost = $quantity * $cost_per_unit;
        
        // Prepare order data
        $order_data = array(
            'order_number' => $order_number,
            'sale_id' => isset($body['saleId']) ? $body['saleId'] : null,
            'sale_item_id' => isset($body['saleItemId']) ? $body['saleItemId'] : null,
            'product_id' => $body['productId'],
            'supplier_id' => $body['supplierId'],
            'quantity' => $quantity,
            'cost_per_unit' => $cost_per_unit,
            'total_cost' => $total_cost,
            'notes' => isset($body['notes']) ? $body['notes'] : null,
            'status' => 'pending',
            'created_at' => current_time('mysql', 1),
            'updated_at' => current_time('mysql', 1)
        );
        
        // Insert order
        $result = $wpdb->insert(
            $wpdb->prefix . 'ims_outsourcing_orders',
            $order_data,
            array('%s', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create outsourcing order', array('status' => 500));
        }
        
        $order_id = $wpdb->insert_id;
        
        // Get the created order with product and supplier details
        $created_order = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, p.name as product_name, s.name as supplier_name
             FROM {$wpdb->prefix}ims_outsourcing_orders o
             LEFT JOIN {$wpdb->prefix}ims_products p ON o.product_id = p.id
             LEFT JOIN {$wpdb->prefix}ims_suppliers s ON o.supplier_id = s.id
             WHERE o.id = %d",
            $order_id
        ));
        
        // Format response
        $response_data = array(
            'id' => intval($created_order->id),
            'order_number' => $created_order->order_number,
            'sale_id' => intval($created_order->sale_id),
            'sale_item_id' => $created_order->sale_item_id ? intval($created_order->sale_item_id) : null,
            'product_id' => intval($created_order->product_id),
            'supplier_id' => intval($created_order->supplier_id),
            'quantity' => floatval($created_order->quantity),
            'cost_per_unit' => floatval($created_order->cost_per_unit),
            'total_cost' => floatval($created_order->total_cost),
            'notes' => $created_order->notes,
            'status' => $created_order->status,
            'created_at' => $created_order->created_at,
            'updated_at' => $created_order->updated_at,
            'product_name' => $created_order->product_name,
            'supplier_name' => $created_order->supplier_name
        );
        
        $response = array(
            'success' => true,
            'data' => $response_data,
            'message' => 'Outsourcing order created successfully'
        );
        
        return new WP_REST_Response($response, 201);
        
    } catch (Exception $e) {
        error_log('IMS Outsourcing API Error: ' . $e->getMessage());
        return new WP_Error('internal_error', 'Internal server error', array('status' => 500));
    }
}