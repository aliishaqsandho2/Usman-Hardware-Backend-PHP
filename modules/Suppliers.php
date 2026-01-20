<?php
// Suppliers.php functionality here

// IMS Suppliers API Endpoints
// Add these functions to your WordPress theme's functions.php file

// Register REST API routes for suppliers
add_action('rest_api_init', 'ims_register_suppliers_routes');

function ims_register_suppliers_routes() {
    // GET /wp-json/ims/v1/suppliers
    register_rest_route('ims/v1', '/suppliers', array(
        'methods' => 'GET',
        'callback' => 'ims_get_suppliers',
    ));

    // GET /wp-json/ims/v1/suppliers/{id}
    register_rest_route('ims/v1', '/suppliers/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_supplier_by_id',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

    // POST /wp-json/ims/v1/suppliers
    register_rest_route('ims/v1', '/suppliers', array(
        'methods' => 'POST',
        'callback' => 'ims_create_supplier',
        'args' => array(
            'name' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return !empty($param) && strlen($param) <= 255;
                }
            ),
            'phone' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return !empty($param) && strlen($param) <= 20;
                }
            ),
            'contactPerson' => array(
                'required' => false,
                'validate_callback' => function($param, $request, $key) {
                    return empty($param) || strlen($param) <= 255;
                }
            ),
            'email' => array(
                'required' => false,
                'validate_callback' => function($param, $request, $key) {
                    return empty($param) || is_email($param);
                }
            ),
            'address' => array(
                'required' => false,
            ),
            'city' => array(
                'required' => false,
                'validate_callback' => function($param, $request, $key) {
                    return empty($param) || strlen($param) <= 100;
                }
            ),
        ),
    ));

    // PUT /wp-json/ims/v1/suppliers/{id}
    register_rest_route('ims/v1', '/suppliers/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_supplier',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

    // DELETE /wp-json/ims/v1/suppliers/{id}
    register_rest_route('ims/v1', '/suppliers/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'ims_delete_supplier',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
}

/**
 * GET /suppliers - List all suppliers with pagination and filtering
 */
function ims_get_suppliers($request) {
    global $wpdb;
    
    // Get query parameters
    $page = max(1, intval($request->get_param('page') ?: 1));
    $limit = min(100, max(1, intval($request->get_param('limit') ?: 20)));
    $search = sanitize_text_field($request->get_param('search') ?: '');
    $status = sanitize_text_field($request->get_param('status') ?: '');
    
    $offset = ($page - 1) * $limit;
    
    // Build WHERE clause
    $where_conditions = array();
    $params = array();
    
    if (!empty($search)) {
        $where_conditions[] = "(name LIKE %s OR contact_person LIKE %s OR email LIKE %s OR city LIKE %s)";
        $search_term = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if (!empty($status) && in_array($status, ['active', 'inactive'])) {
        $where_conditions[] = "status = %s";
        $params[] = $status;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Get total count
    $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ims_suppliers $where_clause";
    $total_items = $wpdb->get_var($wpdb->prepare($count_query, $params));
    
    // Get suppliers with additional data
    $suppliers_query = "
        SELECT s.*,
               COALESCE(po_stats.last_order_date, NULL) as last_order_date,
               COALESCE(product_count.products_count, 0) as products_count
        FROM {$wpdb->prefix}ims_suppliers s
        LEFT JOIN (
            SELECT supplier_id, MAX(date) as last_order_date
            FROM {$wpdb->prefix}ims_purchase_orders
            WHERE status = 'completed'
            GROUP BY supplier_id
        ) po_stats ON s.id = po_stats.supplier_id
        LEFT JOIN (
            SELECT supplier_id, COUNT(*) as products_count
            FROM {$wpdb->prefix}ims_products
            WHERE status = 'active'
            GROUP BY supplier_id
        ) product_count ON s.id = product_count.supplier_id
        $where_clause
        ORDER BY s.created_at DESC
        LIMIT %d OFFSET %d
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $suppliers = $wpdb->get_results($wpdb->prepare($suppliers_query, $params));
    
    if ($wpdb->last_error) {
        return new WP_Error('db_error', 'Database error occurred', array('status' => 500));
    }
    
    // Format suppliers data
    $formatted_suppliers = array_map('ims_format_supplier_data', $suppliers);
    
    $total_pages = ceil($total_items / $limit);
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'suppliers' => $formatted_suppliers,
            'pagination' => array(
                'currentPage' => $page,
                'totalPages' => $total_pages,
                'totalItems' => intval($total_items),
                'itemsPerPage' => $limit
            )
        )
    ), 200);
}

/**
 * GET /suppliers/{id} - Get single supplier with detailed information
 */
function ims_get_supplier_by_id($request) {
    global $wpdb;
    
    $supplier_id = intval($request['id']);
    
    // Get supplier with stats
    $supplier_query = "
        SELECT s.*,
               COALESCE(po_stats.last_order_date, NULL) as last_order_date
        FROM {$wpdb->prefix}ims_suppliers s
        LEFT JOIN (
            SELECT supplier_id, MAX(date) as last_order_date
            FROM {$wpdb->prefix}ims_purchase_orders
            WHERE status = 'completed'
            GROUP BY supplier_id
        ) po_stats ON s.id = po_stats.supplier_id
        WHERE s.id = %d
    ";
    
    $supplier = $wpdb->get_row($wpdb->prepare($supplier_query, $supplier_id));
    
    if (!$supplier) {
        return new WP_Error('supplier_not_found', 'Supplier not found', array('status' => 404));
    }
    
    // Get supplier's products
    $products_query = "
        SELECT id, name, sku, cost_price
        FROM {$wpdb->prefix}ims_products
        WHERE supplier_id = %d AND status = 'active'
        ORDER BY name
        LIMIT 10
    ";
    
    $products = $wpdb->get_results($wpdb->prepare($products_query, $supplier_id));
    
    // Get recent orders
    $orders_query = "
        SELECT id, order_number, date, total as amount, status
        FROM {$wpdb->prefix}ims_purchase_orders
        WHERE supplier_id = %d
        ORDER BY date DESC
        LIMIT 5
    ";
    
    $recent_orders = $wpdb->get_results($wpdb->prepare($orders_query, $supplier_id));
    
    // Format data
    $formatted_supplier = ims_format_supplier_data($supplier);
    $formatted_supplier['products'] = array_map(function($product) {
        return array(
            'id' => intval($product->id),
            'name' => $product->name,
            'sku' => $product->sku,
            'costPrice' => floatval($product->cost_price)
        );
    }, $products);
    
    $formatted_supplier['recentOrders'] = array_map(function($order) {
        return array(
            'id' => intval($order->id),
            'orderNumber' => $order->order_number,
            'date' => $order->date,
            'amount' => floatval($order->amount),
            'status' => $order->status
        );
    }, $recent_orders);
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => $formatted_supplier
    ), 200);
}

/**
 * POST /suppliers - Create new supplier
 */
function ims_create_supplier($request) {
    global $wpdb;
    
    $data = array(
        'name' => sanitize_text_field($request->get_param('name')),
        'phone' => sanitize_text_field($request->get_param('phone') ?: ''),
        'contact_person' => sanitize_text_field($request->get_param('contactPerson') ?: ''),
        'email' => sanitize_email($request->get_param('email') ?: ''),
        'address' => sanitize_textarea_field($request->get_param('address') ?: ''),
        'city' => sanitize_text_field($request->get_param('city') ?: ''),
        'status' => 'active',
        'total_purchases' => 0,
        'pending_payments' => 0,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    
    // Check if phone already exists
    if (!empty($data['phone'])) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ims_suppliers WHERE phone = %s",
            $data['phone']
        ));
        
        if ($existing) {
            return new WP_Error('phone_exists', 'Phone number already exists', array('status' => 409));
        }
    }
    
    // Check if email already exists (if provided)
    if (!empty($data['email'])) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ims_suppliers WHERE email = %s",
            $data['email']
        ));
        
        if ($existing) {
            return new WP_Error('email_exists', 'Email already exists', array('status' => 409));
        }
    }
    
    $result = $wpdb->insert(
        $wpdb->prefix . 'ims_suppliers',
        $data,
        array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s')
    );
    
    if ($result === false) {
        return new WP_Error('creation_failed', 'Failed to create supplier', array('status' => 500));
    }
    
    $supplier_id = $wpdb->insert_id;
    
    // Get the created supplier
    $created_supplier = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d",
        $supplier_id
    ));
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => ims_format_supplier_data($created_supplier),
        'message' => 'Supplier created successfully'
    ), 201);
}

/**
 * PUT /suppliers/{id} - Update supplier
 */
function ims_update_supplier($request) {
    global $wpdb;
    
    $supplier_id = intval($request['id']);
    
    // Check if supplier exists
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d",
        $supplier_id
    ));
    
    if (!$existing) {
        return new WP_Error('supplier_not_found', 'Supplier not found', array('status' => 404));
    }
    
    // Prepare update data
    $data = array();
    $format = array();
    
    if ($request->has_param('name')) {
        $data['name'] = sanitize_text_field($request->get_param('name'));
        $format[] = '%s';
    }
    
    if ($request->has_param('phone')) {
        $phone = sanitize_text_field($request->get_param('phone'));
        
        // Check if phone already exists for other suppliers
        if (!empty($phone)) {
            $existing_phone = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ims_suppliers WHERE phone = %s AND id != %d",
                $phone, $supplier_id
            ));
            
            if ($existing_phone) {
                return new WP_Error('phone_exists', 'Phone number already exists', array('status' => 409));
            }
        }
        
        $data['phone'] = $phone;
        $format[] = '%s';
    }
    
    if ($request->has_param('contactPerson')) {
        $data['contact_person'] = sanitize_text_field($request->get_param('contactPerson'));
        $format[] = '%s';
    }
    
    if ($request->has_param('email')) {
        $email = sanitize_email($request->get_param('email'));
        
        // Check if email already exists for other suppliers
        if (!empty($email)) {
            $existing_email = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ims_suppliers WHERE email = %s AND id != %d",
                $email, $supplier_id
            ));
            
            if ($existing_email) {
                return new WP_Error('email_exists', 'Email already exists', array('status' => 409));
            }
        }
        
        $data['email'] = $email;
        $format[] = '%s';
    }
    
    if ($request->has_param('address')) {
        $data['address'] = sanitize_textarea_field($request->get_param('address'));
        $format[] = '%s';
    }
    
    if ($request->has_param('city')) {
        $data['city'] = sanitize_text_field($request->get_param('city'));
        $format[] = '%s';
    }
    
    if ($request->has_param('status')) {
        $status = sanitize_text_field($request->get_param('status'));
        if (in_array($status, ['active', 'inactive'])) {
            $data['status'] = $status;
            $format[] = '%s';
        }
    }
    
    if (empty($data)) {
        return new WP_Error('no_data', 'No valid data provided for update', array('status' => 400));
    }
    
    $data['updated_at'] = current_time('mysql');
    $format[] = '%s';
    
    $result = $wpdb->update(
        $wpdb->prefix . 'ims_suppliers',
        $data,
        array('id' => $supplier_id),
        $format,
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('update_failed', 'Failed to update supplier', array('status' => 500));
    }
    
    // Get updated supplier
    $updated_supplier = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d",
        $supplier_id
    ));
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => ims_format_supplier_data($updated_supplier),
        'message' => 'Supplier updated successfully'
    ), 200);
}

/**
 * DELETE /suppliers/{id} - Delete supplier
 */
function ims_delete_supplier($request) {
    global $wpdb;
    
    $supplier_id = intval($request['id']);
    
    // Check if supplier exists
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d",
        $supplier_id
    ));
    
    if (!$existing) {
        return new WP_Error('supplier_not_found', 'Supplier not found', array('status' => 404));
    }
    
    // Check if supplier has associated products or purchase orders
    $products_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_products WHERE supplier_id = %d",
        $supplier_id
    ));
    
    $orders_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_purchase_orders WHERE supplier_id = %d",
        $supplier_id
    ));
    
    if ($products_count > 0 || $orders_count > 0) {
        return new WP_Error('supplier_in_use', 'Cannot delete supplier with associated products or orders', array('status' => 409));
    }
    
    $result = $wpdb->delete(
        $wpdb->prefix . 'ims_suppliers',
        array('id' => $supplier_id),
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('deletion_failed', 'Failed to delete supplier', array('status' => 500));
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Supplier deleted successfully'
    ), 200);
}

/**
 * Helper function to format supplier data
 */
function ims_format_supplier_data($supplier) {
    return array(
        'id' => intval($supplier->id),
        'name' => $supplier->name,
        'contactPerson' => $supplier->contact_person ?: '',
        'phone' => $supplier->phone ?: '',
        'email' => $supplier->email ?: '',
        'address' => $supplier->address ?: '',
        'city' => $supplier->city ?: '',
        'status' => $supplier->status,
        'totalPurchases' => floatval($supplier->total_purchases),
        'pendingPayments' => floatval($supplier->pending_payments),
        'lastOrderDate' => isset($supplier->last_order_date) ? $supplier->last_order_date : null,
        'productsCount' => isset($supplier->products_count) ? intval($supplier->products_count) : 0,
        'createdAt' => mysql2date('c', $supplier->created_at)
    );
}

