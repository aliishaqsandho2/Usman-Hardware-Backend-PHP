<?php
// Products.php functionality here

/**
 * IMS Products API Endpoints for WordPress
 * Add these functions to your theme's functions.php file
 */

// Register REST API routes
add_action('rest_api_init', 'ims_register_products_routes');

function ims_register_products_routes() {
    // GET /products
    register_rest_route('ims/v1', '/products', array(
        'methods' => 'GET',
        'callback' => 'ims_get_products',
    ));

    // GET /products/:id
    register_rest_route('ims/v1', '/products/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_product',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

    // POST /products
    register_rest_route('ims/v1', '/products', array(
        'methods' => 'POST',
        'callback' => 'ims_create_product',
    ));

    // PUT /products/:id
    register_rest_route('ims/v1', '/products/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_product',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

  register_rest_route('ims/v1', '/products/(?P<id>\d+)', array(
    'methods' => 'DELETE',
    'callback' => 'ims_delete_product',
    'permission_callback' => '__return_true',
    'args' => array(
        'id' => array(
            'validate_callback' => function($param, $request, $key) {
                return is_numeric($param);
            }
        ),
    ),
));


    // POST /products/:id/stock-adjustment
    register_rest_route('ims/v1', '/products/(?P<id>\d+)/stock-adjustment', array(
        'methods' => 'POST',
        'callback' => 'ims_adjust_stock',
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
 * GET /products - Get all products with filtering and pagination
 */
function ims_get_products($request) {
    global $wpdb;
    
    // Get parameters
    $page = intval($request->get_param('page')) ?: 1;
    $limit = intval($request->get_param('limit')) ?: 20;
    $search = sanitize_text_field($request->get_param('search'));
    $category = sanitize_text_field($request->get_param('category'));
    $status = sanitize_text_field($request->get_param('status'));
    $sort_by = sanitize_text_field($request->get_param('sortBy')) ?: 'name';
    $sort_order = sanitize_text_field($request->get_param('sortOrder')) ?: 'asc';
    
    $offset = ($page - 1) * $limit;
    
    // Build query
    $where_conditions = array();
    $params = array();
    
    if ($search) {
        $where_conditions[] = "(p.name LIKE %s OR p.description LIKE %s OR p.sku LIKE %s)";
        $search_term = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if ($category) {
        $where_conditions[] = "c.name = %s";
        $params[] = $category;
    }
    
    if ($status) {
        $where_conditions[] = "p.status = %s";
        $params[] = $status;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Validate sort fields
    $allowed_sort_fields = array('name', 'price', 'stock', 'created_at');
    if (!in_array($sort_by, $allowed_sort_fields)) {
        $sort_by = 'name';
    }
    
    if ($sort_by === 'createdAt') $sort_by = 'created_at';
    $sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';
    
    // Main query
    $query = "
        SELECT 
            p.*,
            c.name as category_name,
            s.name as supplier_name,
            s.id as supplier_id
        FROM {$wpdb->prefix}ims_products p
        LEFT JOIN {$wpdb->prefix}ims_categories c ON p.category_id = c.id
        LEFT JOIN {$wpdb->prefix}ims_suppliers s ON p.supplier_id = s.id
        $where_clause
        ORDER BY p.$sort_by $sort_order
        LIMIT %d OFFSET %d
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $products = $wpdb->get_results($wpdb->prepare($query, $params));
    
    // Count query for pagination
    $count_query = "
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}ims_products p
        LEFT JOIN {$wpdb->prefix}ims_categories c ON p.category_id = c.id
        $where_clause
    ";
    
    $count_params = array_slice($params, 0, -2); // Remove limit and offset
    $total_items = $wpdb->get_var(!empty($count_params) ? $wpdb->prepare($count_query, $count_params) : $count_query);
    
    // Format products
    $formatted_products = array();
    foreach ($products as $product) {
        // Get product images
        $images = $wpdb->get_col($wpdb->prepare(
            "SELECT image_url FROM {$wpdb->prefix}ims_product_images WHERE product_id = %d",
            $product->id
        ));
        
        $formatted_products[] = array(
            'id' => intval($product->id),
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $product->sku,
            'category' => $product->category_name,
            'price' => floatval($product->price),
            'costPrice' => floatval($product->cost_price),
            'stock' => floatval($product->stock),
            'minStock' => floatval($product->min_stock),
            'maxStock' => floatval($product->max_stock),
            'unit' => $product->unit,
            'status' => $product->status,
            'supplier' => array(
                'id' => intval($product->supplier_id),
                'name' => $product->supplier_name
            ),
            'images' => $images,
            'createdAt' => $product->created_at,
            'updatedAt' => $product->updated_at
        );
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'products' => $formatted_products,
            'pagination' => array(
                'currentPage' => $page,
                'totalPages' => ceil($total_items / $limit),
                'totalItems' => intval($total_items),
                'itemsPerPage' => $limit
            )
        )
    ), 200);
}

/**
 * GET /products/:id - Get single product with stock history
 */
function ims_get_product($request) {
    global $wpdb;
    
    $product_id = intval($request['id']);
    $prefix = $wpdb->prefix;

    // Get product details
    $product = $wpdb->get_row($wpdb->prepare("
        SELECT 
            p.*,
            c.name as category_name,
            s.name as supplier_name
        FROM {$prefix}ims_products p
        LEFT JOIN {$prefix}ims_categories c ON p.category_id = c.id
        LEFT JOIN {$prefix}ims_suppliers s ON p.supplier_id = s.id
        WHERE p.id = %d
    ", $product_id));
    
    if (!$product) {
        return new WP_Error('product_not_found', 'Product not found', array('status' => 404));
    }
    
    // Get product images
    $images = $wpdb->get_col($wpdb->prepare(
        "SELECT image_url FROM {$prefix}ims_product_images WHERE product_id = %d",
        $product_id
    ));

    // ✅ Use correct sale history query (replaces old inventory movements)
    $stock_history = $wpdb->get_results($wpdb->prepare("
        SELECT 
            p.name AS product_name,
            si.quantity,
            si.unit_price,
            si.total AS line_total,
            si.is_outsourced,
            si.outsourcing_cost_per_unit,
            s.order_number,
            s.date AS sale_date,
            s.payment_method,
            s.status,
            c.name AS customer_name
        FROM {$prefix}ims_sale_items si
        JOIN {$prefix}ims_sales s ON si.sale_id = s.id
        JOIN {$prefix}ims_products p ON si.product_id = p.id
        LEFT JOIN {$prefix}ims_customers c ON s.customer_id = c.id
        WHERE p.id = %d
        ORDER BY s.date DESC
    ", $product_id));

    // Format sale history for frontend
    $formatted_history = array();
    foreach ($stock_history as $record) {
        $formatted_history[] = array(
            'date' => $record->sale_date,
            'quantity' => floatval($record->quantity),
            'unitPrice' => floatval($record->unit_price),
            'lineTotal' => floatval($record->line_total),
            'isOutsourced' => intval($record->is_outsourced),
            'outsourcingCostPerUnit' => floatval($record->outsourcing_cost_per_unit),
            'orderNumber' => $record->order_number,
            'paymentMethod' => $record->payment_method,
            'status' => $record->status,
            'customerName' => $record->customer_name
        );
    }
    
    // Final API Response (frontend-safe)
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'id' => intval($product->id),
            'name' => $product->name,
            'description' => $product->description,
            'sku' => $product->sku,
            'category' => $product->category_name,
            'price' => floatval($product->price),
            'costPrice' => floatval($product->cost_price),
            'stock' => floatval($product->stock),
            'minStock' => floatval($product->min_stock),
            'maxStock' => floatval($product->max_stock),
            'unit' => $product->unit,
            'status' => $product->status,
            'supplierId' => intval($product->supplier_id),
            'images' => $images,
            'stockHistory' => $formatted_history, // ✅ now uses correct query data
            'createdAt' => $product->created_at,
            'updatedAt' => $product->updated_at
        )
    ), 200);
}

/**
 * POST /products - Create new product
 */
function ims_create_product($request) {
    global $wpdb;
    
    $data = $request->get_json_params();
    
    // Validate required fields
    $required_fields = array('name', 'sku', 'price', 'costPrice', 'unit');
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400));
        }
    }
    
    // Check if SKU already exists
    $existing_sku = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ims_products WHERE sku = %s",
        $data['sku']
    ));
    
    if ($existing_sku) {
        return new WP_Error('duplicate_sku', 'SKU already exists', array('status' => 400));
    }
    
    // Get category ID if category name provided
    $category_id = null;
    if (!empty($data['category'])) {
        $category_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ims_categories WHERE name = %s",
            $data['category']
        ));
    }
    
    // Insert product
    $insert_data = array(
        'name' => sanitize_text_field($data['name']),
        'description' => sanitize_textarea_field($data['description'] ?? ''),
        'sku' => sanitize_text_field($data['sku']),
        'category_id' => $category_id,
        'price' => floatval($data['price']),
        'cost_price' => floatval($data['costPrice']),
        'stock' => number_format(floatval($data['stock'] ?? 0), 2, '.', ''),
        'min_stock' => number_format(floatval($data['minStock'] ?? 0), 2, '.', ''),
        'max_stock' => number_format(floatval($data['maxStock'] ?? 100), 2, '.', ''),
        'unit' => sanitize_text_field($data['unit']),
        'supplier_id' => !empty($data['supplierId']) ? intval($data['supplierId']) : null,
        'status' => 'active'
    );
    
    $result = $wpdb->insert(
        "{$wpdb->prefix}ims_products",
        $insert_data,
        array('%s', '%s', '%s', '%d', '%f', '%f', '%f', '%f', '%f', '%s', '%d', '%s')
    );
    
    if ($result === false) {
        return new WP_Error('insert_failed', 'Failed to create product', array('status' => 500));
    }
    
    $product_id = $wpdb->insert_id;
    
    // Insert product images if provided
    if (!empty($data['images']) && is_array($data['images'])) {
        foreach ($data['images'] as $image_url) {
            $wpdb->insert(
                "{$wpdb->prefix}ims_product_images",
                array(
                    'product_id' => $product_id,
                    'image_url' => esc_url_raw($image_url)
                ),
                array('%d', '%s')
            );
        }
    }
    
    // Create initial inventory movement if stock > 0
    if (floatval($data['stock'] ?? 0) > 0) {
        ims_create_inventory_movement(
            $product_id,
            'adjustment',
            number_format(floatval($data['stock']), 2, '.', ''),
            0,
            number_format(floatval($data['stock']), 2, '.', ''),
            'Initial stock',
            'Product creation'
        );
    }
    
    // Get created product
    $created_product = ims_get_product_by_id($product_id);
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => $created_product,
        'message' => 'Product created successfully'
    ), 201);
}

/**
 * PUT /products/:id - Update product
 */
function ims_update_product($request) {
    global $wpdb;
    
    $product_id = intval($request['id']);
    $data = $request->get_json_params();
    
    // Check if product exists
    $existing_product = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_products WHERE id = %d",
        $product_id
    ));
    
    if (!$existing_product) {
        return new WP_Error('product_not_found', 'Product not found', array('status' => 404));
    }
    
    // Check SKU uniqueness if SKU is being updated
    if (!empty($data['sku']) && $data['sku'] !== $existing_product->sku) {
        $existing_sku = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ims_products WHERE sku = %s AND id != %d",
            $data['sku'],
            $product_id
        ));
        
        if ($existing_sku) {
            return new WP_Error('duplicate_sku', 'SKU already exists', array('status' => 400));
        }
    }
    
    // Get category ID if category name provided
    $category_id = $existing_product->category_id;
    if (isset($data['category'])) {
        $category_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ims_categories WHERE name = %s",
            $data['category']
        ));
    }
    
    // Prepare update data
    $update_data = array();
    $update_format = array();
    
    $updatable_fields = array(
        'name' => '%s',
        'description' => '%s',
        'sku' => '%s',
        'price' => '%f',
        'costPrice' => '%f',
        'stock' => '%f',
        'minStock' => '%f',
        'maxStock' => '%f',
        'unit' => '%s',
        'status' => '%s',
        'supplierId' => '%d'
    );
    
    foreach ($updatable_fields as $field => $format) {
        if (isset($data[$field])) {
            $db_field = $field;
            // Convert camelCase to snake_case
            if ($field === 'costPrice') $db_field = 'cost_price';
            elseif ($field === 'minStock') $db_field = 'min_stock';
            elseif ($field === 'maxStock') $db_field = 'max_stock';
            elseif ($field === 'supplierId') $db_field = 'supplier_id';
            
            // Format decimal values
            if (in_array($field, ['stock', 'minStock', 'maxStock'])) {
                $update_data[$db_field] = number_format(floatval($data[$field]), 2, '.', '');
            } else {
                $update_data[$db_field] = $data[$field];
            }
            $update_format[] = $format;
        }
    }
    
    if (isset($data['category'])) {
        $update_data['category_id'] = $category_id;
        $update_format[] = '%d';
    }
    
    if (!empty($update_data)) {
        $result = $wpdb->update(
            "{$wpdb->prefix}ims_products",
            $update_data,
            array('id' => $product_id),
            $update_format,
            array('%d')
        );
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update product', array('status' => 500));
        }
    }
    
    // Update images if provided
    if (isset($data['images']) && is_array($data['images'])) {
        // Delete existing images
        $wpdb->delete(
            "{$wpdb->prefix}ims_product_images",
            array('product_id' => $product_id),
            array('%d')
        );
        
        // Insert new images
        foreach ($data['images'] as $image_url) {
            $wpdb->insert(
                "{$wpdb->prefix}ims_product_images",
                array(
                    'product_id' => $product_id,
                    'image_url' => esc_url_raw($image_url)
                ),
                array('%d', '%s')
            );
        }
    }
    
    // Get updated product
    $updated_product = ims_get_product_by_id($product_id);
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => $updated_product,
        'message' => 'Product updated successfully'
    ), 200);
}

/**
 * DELETE /products/:id - Delete product
 */
function ims_delete_product($request) {
    global $wpdb;
    
    $product_id = intval($request['id']);
    
    // Check if product exists
    $product = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ims_products WHERE id = %d",
        $product_id
    ));
    
    if (!$product) {
        return new WP_Error('product_not_found', 'Product not found', array('status' => 404));
    }
    
    // Check if product is used in any orders (prevent deletion)
    $used_in_sales = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_sale_items WHERE product_id = %d",
        $product_id
    ));
    
    $used_in_purchases = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_purchase_order_items WHERE product_id = %d",
        $product_id
    ));
    
    if ($used_in_sales > 0 || $used_in_purchases > 0) {
        return new WP_Error('product_in_use', 'Cannot delete product that has been used in orders', array('status' => 400));
    }
    
    // Delete product images first (CASCADE)
    $wpdb->delete(
        "{$wpdb->prefix}ims_product_images",
        array('product_id' => $product_id),
        array('%d')
    );
    
    // Delete inventory movements
    $wpdb->delete(
        "{$wpdb->prefix}ims_inventory_movements",
        array('product_id' => $product_id),
        array('%d')
    );
    
    // Delete product
    $result = $wpdb->delete(
        "{$wpdb->prefix}ims_products",
        array('id' => $product_id),
        array('%d')
    );
    
    if ($result === false) {
        return new WP_Error('delete_failed', 'Failed to delete product', array('status' => 500));
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Product deleted successfully'
    ), 200);
}

/**
 * POST /products/:id/stock-adjustment - Adjust product stock
 */
function ims_adjust_stock($request) {
    global $wpdb;
    
    $product_id = intval($request['id']);
    $data = $request->get_json_params();
    
    // Validate required fields
    if (empty($data['type']) || !isset($data['quantity'])) {
        return new WP_Error('missing_field', 'Type and quantity are required', array('status' => 400));
    }
    
    // Get current product stock
    $product = $wpdb->get_row($wpdb->prepare(
        "SELECT stock FROM {$wpdb->prefix}ims_products WHERE id = %d",
        $product_id
    ));
    
    if (!$product) {
        return new WP_Error('product_not_found', 'Product not found', array('status' => 404));
    }
    
    $current_stock = floatval($product->stock);
    $adjustment_quantity = number_format(floatval($data['quantity']), 2, '.', '');
    $adjustment_type = sanitize_text_field($data['type']);
    
    // Calculate new stock based on adjustment type
    $new_stock = $current_stock;
    
    switch ($adjustment_type) {
        case 'adjustment':
        case 'restock':
            $new_stock = $current_stock + $adjustment_quantity;
            break;
        case 'damage':
        case 'return':
            $new_stock = $current_stock - abs($adjustment_quantity);
            $adjustment_quantity = -abs($adjustment_quantity);
            break;
        default:
            return new WP_Error('invalid_type', 'Invalid adjustment type', array('status' => 400));
    }
    
    // Ensure stock doesn't go negative
    if ($new_stock < 0) {
        return new WP_Error('insufficient_stock', 'Insufficient stock for adjustment', array('status' => 400));
    }
    
    // Format to 2 decimal places
    $new_stock = number_format($new_stock, 2, '.', '');
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Update product stock
        $update_result = $wpdb->update(
            "{$wpdb->prefix}ims_products",
            array('stock' => $new_stock),
            array('id' => $product_id),
            array('%f'),
            array('%d')
        );
        
        if ($update_result === false) {
            throw new Exception('Failed to update product stock');
        }
        
        // Create inventory movement record
        $movement_id = ims_create_inventory_movement(
            $product_id,
            $adjustment_type,
            $adjustment_quantity,
            $current_stock,
            $new_stock,
            sanitize_text_field($data['reference'] ?? ''),
            sanitize_textarea_field($data['reason'] ?? '')
        );
        
        if (!$movement_id) {
            throw new Exception('Failed to create inventory movement');
        }
        
        $wpdb->query('COMMIT');
        
        // Get movement details
        $movement = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_inventory_movements WHERE id = %d",
            $movement_id
        ));
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'newStock' => floatval($new_stock),
                'adjustment' => array(
                    'id' => intval($movement->id),
                    'type' => $movement->type,
                    'quantity' => floatval($movement->quantity),
                    'reason' => $movement->reason,
                    'createdAt' => $movement->created_at
                )
            )
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('adjustment_failed', $e->getMessage(), array('status' => 500));
    }
}

/**
 * Helper function to create inventory movement
 */
function ims_create_inventory_movement($product_id, $type, $quantity, $balance_before, $balance_after, $reference = '', $reason = '') {
    global $wpdb;
    
    $result = $wpdb->insert(
        "{$wpdb->prefix}ims_inventory_movements",
        array(
            'product_id' => $product_id,
            'type' => $type,
            'quantity' => $quantity,
            'balance_before' => $balance_before,
            'balance_after' => $balance_after,
            'reference' => $reference,
            'reason' => $reason
        ),
        array('%d', '%s', '%f', '%f', '%f', '%s', '%s')
    );
    
    return $result ? $wpdb->insert_id : false;
}

/**
 * Helper function to get formatted product by ID
 */
function ims_get_product_by_id($product_id) {
    global $wpdb;
    
    $product = $wpdb->get_row($wpdb->prepare("
        SELECT 
            p.*,
            c.name as category_name,
            s.name as supplier_name
        FROM {$wpdb->prefix}ims_products p
        LEFT JOIN {$wpdb->prefix}ims_categories c ON p.category_id = c.id
        LEFT JOIN {$wpdb->prefix}ims_suppliers s ON p.supplier_id = s.id
        WHERE p.id = %d
    ", $product_id));
    
    if (!$product) {
        return null;
    }
    
    // Get product images
    $images = $wpdb->get_col($wpdb->prepare(
        "SELECT image_url FROM {$wpdb->prefix}ims_product_images WHERE product_id = %d",
        $product_id
    ));
    
    return array(
        'id' => intval($product->id),
        'name' => $product->name,
        'description' => $product->description,
        'sku' => $product->sku,
        'category' => $product->category_name,
        'price' => floatval($product->price),
        'costPrice' => floatval($product->cost_price),
        'stock' => floatval($product->stock),
        'minStock' => floatval($product->min_stock),
        'maxStock' => floatval($product->max_stock),
        'unit' => $product->unit,
        'status' => $product->status,
        'supplierId' => intval($product->supplier_id),
        'images' => $images,
        'createdAt' => $product->created_at,
        'updatedAt' => $product->updated_at
    );
}



/**
 * IMS Categories and Units API Endpoints
 * WordPress REST API endpoints for Inventory Management System
 */

// Hook to register REST API routes
add_action('rest_api_init', 'ims_register_category_unit_routes');

function ims_register_category_unit_routes() {
    // Categories routes
    register_rest_route('ims/v1', '/categories', array(
        array(
            'methods' => 'GET',
            'callback' => 'ims_get_categories',
            'permission_callback' => 'ims_check_permissions'
        ),
        array(
            'methods' => 'POST',
            'callback' => 'ims_create_category',
            'permission_callback' => 'ims_check_permissions',
            'args' => array(
                'name' => array(
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => 'ims_validate_category_name'
                )
            )
        )
    ));

    // Units routes
    register_rest_route('ims/v1', '/units', array(
        array(
            'methods' => 'GET',
            'callback' => 'ims_get_units',
            'permission_callback' => 'ims_check_permissions'
        ),
        array(
            'methods' => 'POST',
            'callback' => 'ims_create_unit',
            'permission_callback' => 'ims_check_permissions',
            'args' => array(
                'name' => array(
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => 'ims_validate_unit_name'
                ),
                'label' => array(
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => 'ims_validate_unit_label'
                )
            )
        )
    ));
}

/**
 * Permission callback for API endpoints - Open access
 */
function ims_check_permissions() {
    return true; // Allow public access
}

/**
 * GET /categories - Get all product categories
 */
function ims_get_categories(WP_REST_Request $request) {
    global $wpdb;
    
    try {
        $table_name = $wpdb->prefix . 'ims_categories';
        
        // Get categories with product count
        $query = $wpdb->prepare("
            SELECT 
                c.id,
                c.name,
                c.created_at,
                COUNT(p.id) as product_count
            FROM {$table_name} c
            LEFT JOIN {$wpdb->prefix}ims_products p ON c.id = p.category_id AND p.status = 'active'
            GROUP BY c.id, c.name, c.created_at
            ORDER BY c.name ASC
        ");
        
        $categories = $wpdb->get_results($query, ARRAY_A);
        
        if ($wpdb->last_error) {
            return new WP_Error('db_error', 'Database error: ' . $wpdb->last_error, array('status' => 500));
        }
        
        // Format response
        $formatted_categories = array_map(function($category) {
            return array(
                'id' => (int) $category['id'],
                'name' => $category['name'],
                'product_count' => (int) $category['product_count'],
                'created_at' => $category['created_at']
            );
        }, $categories);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $formatted_categories,
            'total' => count($formatted_categories)
        ), 200);
        
    } catch (Exception $e) {
        return new WP_Error('server_error', 'Server error: ' . $e->getMessage(), array('status' => 500));
    }
}

/**
 * POST /categories - Add new category
 */
function ims_create_category(WP_REST_Request $request) {
    global $wpdb;
    
    try {
        $name = sanitize_text_field($request->get_param('name'));
        $table_name = $wpdb->prefix . 'ims_categories';
        
        // Check if category already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE name = %s",
            $name
        ));
        
        if ($existing) {
            return new WP_Error('duplicate_category', 'Category already exists', array('status' => 409));
        }
        
        // Insert new category
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $name,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s')
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Failed to create category: ' . $wpdb->last_error, array('status' => 500));
        }
        
        $category_id = $wpdb->insert_id;
        
        // Get the created category
        $category = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, created_at FROM {$table_name} WHERE id = %d",
            $category_id
        ), ARRAY_A);
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Category created successfully',
            'data' => array(
                'id' => (int) $category['id'],
                'name' => $category['name'],
                'product_count' => 0,
                'created_at' => $category['created_at']
            )
        ), 201);
        
    } catch (Exception $e) {
        return new WP_Error('server_error', 'Server error: ' . $e->getMessage(), array('status' => 500));
    }
}

/**
 * GET /units - Get all product units
 * Since units aren't in a separate table, we'll get distinct units from products
 */
function ims_get_units(WP_REST_Request $request) {
    global $wpdb;
    
    try {
        $products_table = $wpdb->prefix . 'ims_products';
        
        // Get distinct units from products table
        $query = $wpdb->prepare("
            SELECT 
                unit as name,
                unit as label,
                COUNT(*) as usage_count
            FROM {$products_table} 
            WHERE unit IS NOT NULL AND unit != '' 
            GROUP BY unit 
            ORDER BY usage_count DESC, unit ASC
        ");
        
        $units = $wpdb->get_results($query, ARRAY_A);
        
        if ($wpdb->last_error) {
            return new WP_Error('db_error', 'Database error: ' . $wpdb->last_error, array('status' => 500));
        }
        
        // Add some common predefined units if no units exist
        if (empty($units)) {
            $predefined_units = array(
                array('name' => 'piece', 'label' => 'Piece', 'usage_count' => 0),
                array('name' => 'kg', 'label' => 'Kilogram', 'usage_count' => 0),
                array('name' => 'liter', 'label' => 'Liter', 'usage_count' => 0),
                array('name' => 'meter', 'label' => 'Meter', 'usage_count' => 0),
                array('name' => 'box', 'label' => 'Box', 'usage_count' => 0),
                array('name' => 'pack', 'label' => 'Pack', 'usage_count' => 0)
            );
            $units = $predefined_units;
        }
        
        // Format response
        $formatted_units = array_map(function($unit) {
            return array(
                'name' => $unit['name'],
                'label' => ucfirst($unit['label']),
                'usage_count' => (int) $unit['usage_count']
            );
        }, $units);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $formatted_units,
            'total' => count($formatted_units)
        ), 200);
        
    } catch (Exception $e) {
        return new WP_Error('server_error', 'Server error: ' . $e->getMessage(), array('status' => 500));
    }
}

/**
 * POST /units - Add new unit
 * This will add to a units cache or could be used to validate new units
 */
function ims_create_unit(WP_REST_Request $request) {
    try {
        $name = sanitize_text_field($request->get_param('name'));
        $label = sanitize_text_field($request->get_param('label'));
        
        // Since we don't have a units table, we'll store in WordPress options
        // or you could create a units table if needed
        $existing_units = get_option('ims_custom_units', array());
        
        // Check if unit already exists
        $unit_exists = false;
        foreach ($existing_units as $unit) {
            if ($unit['name'] === $name) {
                $unit_exists = true;
                break;
            }
        }
        
        if ($unit_exists) {
            return new WP_Error('duplicate_unit', 'Unit already exists', array('status' => 409));
        }
        
        // Add new unit
        $new_unit = array(
            'name' => $name,
            'label' => $label,
            'created_at' => current_time('mysql')
        );
        
        $existing_units[] = $new_unit;
        update_option('ims_custom_units', $existing_units);
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Unit created successfully',
            'data' => array(
                'name' => $name,
                'label' => $label,
                'usage_count' => 0
            )
        ), 201);
        
    } catch (Exception $e) {
        return new WP_Error('server_error', 'Server error: ' . $e->getMessage(), array('status' => 500));
    }
}

/**
 * Validation Functions
 */
function ims_validate_category_name($value, $request, $param) {
    if (empty(trim($value))) {
        return new WP_Error('invalid_category_name', 'Category name cannot be empty');
    }
    
    if (strlen($value) > 100) {
        return new WP_Error('invalid_category_name', 'Category name cannot exceed 100 characters');
    }
    
    return true;
}

function ims_validate_unit_name($value, $request, $param) {
    if (empty(trim($value))) {
        return new WP_Error('invalid_unit_name', 'Unit name cannot be empty');
    }
    
    if (strlen($value) > 50) {
        return new WP_Error('invalid_unit_name', 'Unit name cannot exceed 50 characters');
    }
    
    return true;
}

function ims_validate_unit_label($value, $request, $param) {
    if (empty(trim($value))) {
        return new WP_Error('invalid_unit_label', 'Unit label cannot be empty');
    }
    
    if (strlen($value) > 100) {
        return new WP_Error('invalid_unit_label', 'Unit label cannot exceed 100 characters');
    }
    
    return true;
}

/**
 * Helper function to get unit options for forms
 */
function ims_get_unit_options() {
    global $wpdb;
    
    // Get units from products
    $products_table = $wpdb->prefix . 'ims_products';
    $units_from_products = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT unit 
        FROM {$products_table} 
        WHERE unit IS NOT NULL AND unit != '' 
        ORDER BY unit ASC
    "));
    
    // Get custom units
    $custom_units = get_option('ims_custom_units', array());
    $custom_unit_names = array_column($custom_units, 'name');
    
    // Merge and remove duplicates
    $all_units = array_unique(array_merge($units_from_products, $custom_unit_names));
    sort($all_units);
    
    return $all_units;
}

// Optional: Create units table for better management
function ims_create_units_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ims_units';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        name varchar(50) NOT NULL,
        label varchar(100) NOT NULL,
        abbreviation varchar(10) DEFAULT NULL,
        type enum('weight','volume','length','quantity','time') DEFAULT 'quantity',
        is_active tinyint(1) DEFAULT 1,
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}