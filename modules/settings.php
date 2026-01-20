<?php
// Inventory.php functionality here

/**
 * WordPress Inventory Management System - API Functions
 * Add these functions to your theme's functions.php file
 * Modified for public access without authentication
 */

// Hook to initialize API endpoints
add_action('init', 'ims_init_inventory_api');

function ims_init_inventory_api() {
    // Register REST API routes
    add_action('rest_api_init', 'ims_register_inventory_routes');
}

function ims_register_inventory_routes() {
    // GET /wp-json/ims/v1/inventory
    register_rest_route('ims/v1', '/inventory', array(
        'methods' => 'GET',
        'callback' => 'ims_get_inventory',
        'permission_callback' => '__return_true' // No authentication required
    ));

    // GET /wp-json/ims/v1/inventory/movements
    register_rest_route('ims/v1', '/inventory/movements', array(
        'methods' => 'GET',
        'callback' => 'ims_get_inventory_movements',
        'permission_callback' => '__return_true' // No authentication required
    ));

    // POST /wp-json/ims/v1/inventory/restock
    register_rest_route('ims/v1', '/inventory/restock', array(
        'methods' => 'POST',
        'callback' => 'ims_restock_inventory',
        'permission_callback' => '__return_true' // No authentication required
    ));
}

/**
 * GET /inventory/movements - Get inventory movement history
 */
function ims_get_inventory_movements($request) {
    global $wpdb;
    
    try {
        // Get query parameters
        $page = intval($request->get_param('page')) ?: 1;
        $limit = intval($request->get_param('limit')) ?: 20;
        $product_id = intval($request->get_param('productId'));
        $type = sanitize_text_field($request->get_param('type'));
        $date_from = sanitize_text_field($request->get_param('dateFrom'));
        $date_to = sanitize_text_field($request->get_param('dateTo'));
        
        $offset = ($page - 1) * $limit;
        
        // Build query
        $sql = "
            SELECT 
                im.id,
                im.product_id as productId,
                p.name as productName,
                im.type,
                im.quantity,
                im.balance_before as balanceBefore,
                im.balance_after as balanceAfter,
                im.reference,
                im.reason,
                im.created_at as createdAt
            FROM {$wpdb->prefix}ims_inventory_movements im
            LEFT JOIN {$wpdb->prefix}ims_products p ON im.product_id = p.id
            WHERE 1=1
        ";
        
        $where_conditions = array();
        $params = array();
        
        // Add filters
        if ($product_id) {
            $where_conditions[] = "im.product_id = %d";
            $params[] = $product_id;
        }
        
        if ($type) {
            $where_conditions[] = "im.type = %s";
            $params[] = $type;
        }
        
        if ($date_from) {
            $where_conditions[] = "DATE(im.created_at) >= %s";
            $params[] = $date_from;
        }
        
        if ($date_to) {
            $where_conditions[] = "DATE(im.created_at) <= %s";
            $params[] = $date_to;
        }
        
        // Apply WHERE conditions
        if (!empty($where_conditions)) {
            $sql .= " AND " . implode(' AND ', $where_conditions);
        }
        
        // Get total count for pagination
        $count_sql = str_replace(
            "SELECT im.id, im.product_id as productId, p.name as productName, im.type, im.quantity, im.balance_before as balanceBefore, im.balance_after as balanceAfter, im.reference, im.reason, im.created_at as createdAt",
            "SELECT COUNT(*) as total",
            $sql
        );
        
        if (!empty($params)) {
            $total_items = $wpdb->get_var($wpdb->prepare($count_sql, $params));
        } else {
            $total_items = $wpdb->get_var($count_sql);
        }
        
        // Add ordering and pagination to main query
        $sql .= " ORDER BY im.created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        
        // Execute query
        if (!empty($params)) {
            $movements = $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            $movements = $wpdb->get_results($sql);
        }
        
        // Format response
        $formatted_movements = array();
        foreach ($movements as $movement) {
            $formatted_movements[] = array(
                'id' => intval($movement->id),
                'productId' => intval($movement->productId),
                'productName' => $movement->productName,
                'type' => $movement->type,
                'quantity' => floatval($movement->quantity),
                'balanceBefore' => floatval($movement->balanceBefore),
                'balanceAfter' => floatval($movement->balanceAfter),
                'reference' => $movement->reference,
                'reason' => $movement->reason,
                'createdAt' => date('c', strtotime($movement->createdAt))
            );
        }
        
        $total_pages = ceil($total_items / $limit);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'movements' => $formatted_movements,
                'pagination' => array(
                    'currentPage' => $page,
                    'totalPages' => $total_pages,
                    'totalItems' => intval($total_items),
                    'itemsPerPage' => $limit
                )
            )
        ), 200);
        
    } catch (Exception $e) {
        return new WP_Error('database_error', 'Database error occurred', array('status' => 500));
    }
}

/**
 * POST /inventory/restock - Restock inventory
 */
function ims_restock_inventory($request) {
    global $wpdb;
    
    try {
        // Get request body
        $body = $request->get_json_params();
        
        if (!$body) {
            return new WP_Error('invalid_json', 'Invalid JSON in request body', array('status' => 400));
        }
        
        // Validate required fields
        $required_fields = array('productId', 'quantity');
        foreach ($required_fields as $field) {
            if (!isset($body[$field]) || empty($body[$field])) {
                return new WP_Error('missing_field', "Field '$field' is required", array('status' => 400));
            }
        }
        
        $product_id = intval($body['productId']);
        $quantity = floatval($body['quantity']);
        $cost_price = isset($body['costPrice']) ? floatval($body['costPrice']) : null;
        $supplier_id = isset($body['supplierId']) ? intval($body['supplierId']) : null;
        $purchase_order_id = isset($body['purchaseOrderId']) ? intval($body['purchaseOrderId']) : null;
        $notes = isset($body['notes']) ? sanitize_text_field($body['notes']) : '';
        
        // Validate quantity
        if ($quantity <= 0) {
            return new WP_Error('invalid_quantity', 'Quantity must be greater than 0', array('status' => 400));
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Get current product stock
        $current_product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, stock, cost_price FROM {$wpdb->prefix}ims_products WHERE id = %d AND status = 'active'",
            $product_id
        ));
        
        if (!$current_product) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('product_not_found', 'Product not found or inactive', array('status' => 404));
        }
        
        $balance_before = floatval($current_product->stock);
        $balance_after = $balance_before + $quantity;
        
        // Update product stock
        $update_result = $wpdb->update(
            $wpdb->prefix . 'ims_products',
            array(
                'stock' => number_format($balance_after, 2, '.', ''),
                'cost_price' => $cost_price ? number_format($cost_price, 2, '.', '') : $current_product->cost_price,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $product_id),
            array('%f', '%f', '%s'),
            array('%d')
        );
        
        if ($update_result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('update_failed', 'Failed to update product stock', array('status' => 500));
        }
        
        // Create inventory movement record
        $movement_result = $wpdb->insert(
            $wpdb->prefix . 'ims_inventory_movements',
            array(
                'product_id' => $product_id,
                'type' => 'purchase',
                'quantity' => number_format($quantity, 2, '.', ''),
                'balance_before' => number_format($balance_before, 2, '.', ''),
                'balance_after' => number_format($balance_after, 2, '.', ''),
                'reference' => $purchase_order_id ? "PO-{$purchase_order_id}" : "RESTOCK-" . time(),
                'reason' => $notes ?: 'Manual restock',
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s')
        );
        
        if ($movement_result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('movement_failed', 'Failed to create inventory movement', array('status' => 500));
        }
        
        $movement_id = $wpdb->insert_id;
        
        // Update supplier totals if supplier is provided
        if ($supplier_id && $cost_price) {
            $total_purchase = $quantity * $cost_price;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ims_suppliers 
                 SET total_purchases = total_purchases + %f,
                     updated_at = %s
                 WHERE id = %d",
                number_format($total_purchase, 2, '.', ''),
                current_time('mysql'),
                $supplier_id
            ));
        }
        
        // Commit transaction
        $wpdb->query('COMMIT');
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'newStock' => floatval($balance_after),
                'movement' => array(
                    'id' => $movement_id,
                    'type' => 'restock',
                    'quantity' => floatval($quantity),
                    'balanceAfter' => floatval($balance_after)
                )
            ),
            'message' => 'Stock updated successfully'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('database_error', 'Database error occurred', array('status' => 500));
    }
}

/**
 * Utility function to get database table name with WordPress prefix
 */
function ims_get_table_name($table_name) {
    global $wpdb;
    return $wpdb->prefix . $table_name;
}

/**
 * Error handler for API responses
 */
function ims_handle_api_error($error_code, $message, $status_code = 500) {
    return new WP_Error($error_code, $message, array('status' => $status_code));
}

// Optional: Add CORS support if needed
add_action('rest_api_init', function() {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function($value) {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        return $value;
    });
});


// Register REST API routes for Inventory Management System
add_action('rest_api_init', function () {
    // 1. Get Order Details
    register_rest_route('ims/v1', '/sales/(?P<orderId>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_order_details',
        'permission_callback' => '__return_true', // Open access
    ));

    // 2. Update Order Status
    register_rest_route('ims/v1', '/sales/(?P<orderId>\d+)/status', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_order_status',
        'permission_callback' => '__return_true',
    ));

    // 3. Update Order Details
    register_rest_route('ims/v1', '/sales/(?P<orderId>\d+)/details', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_order_details',
        'permission_callback' => '__return_true',
    ));

    // 4. Return Items (Partial)
    register_rest_route('ims/v1', '/sales/(?P<orderId>\d+)/adjust', array(
        'methods' => 'POST',
        'callback' => 'ims_return_items',
        'permission_callback' => '__return_true',
    ));

    // 5. Complete Order Reversal
    register_rest_route('ims/v1', '/sales/(?P<orderId>\d+)/revert', array(
        'methods' => 'POST',
        'callback' => 'ims_revert_order',
        'permission_callback' => '__return_true',
    ));

  
});

// 1. Get Order Details
function ims_get_order_details($request) {
    global $wpdb;
    $order_id = $request['orderId'];

    // Fetch order
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT s.*, c.name AS customer_name, u.user_login AS created_by
         FROM {$wpdb->prefix}ims_sales s
         LEFT JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id
         LEFT JOIN {$wpdb->prefix}users u ON s.created_by = u.ID
         WHERE s.id = %d",
        $order_id
    ));

    if (!$order) {
        return new WP_Error('no_order', 'Order not found', array('status' => 404));
    }

    // Fetch order items
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT si.*, p.name AS product_name
         FROM {$wpdb->prefix}ims_sale_items si
         JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
         WHERE si.sale_id = %d",
        $order_id
    ));

    $response = array(
        'success' => true,
        'data' => array(
            'id' => (int)$order->id,
            'orderNumber' => $order->order_number,
            'customerId' => (int)$order->customer_id,
            'customerName' => $order->customer_name,
            'date' => $order->date,
            'time' => $order->time,
            'items' => array_map(function ($item) {
                return array(
                    'productId' => (int)$item->product_id,
                    'productName' => $item->product_name,
                    'quantity' => floatval($item->quantity),
                    'unitPrice' => floatval($item->unit_price),
                    'total' => floatval($item->total),
                );
            }, $items),
            'subtotal' => floatval($order->subtotal),
            'discount' => floatval($order->discount),
            'total' => floatval($order->total),
            'paymentMethod' => $order->payment_method,
            'status' => $order->status,
            'notes' => $order->notes,
            'createdBy' => $order->created_by,
            'createdAt' => gmdate('c', strtotime($order->created_at)),
        ),
    );

    return rest_ensure_response($response);
}

// 2. Update Order Status
function ims_update_order_status($request) {
    global $wpdb;
    $order_id = $request['orderId'];
    $params = $request->get_json_params();

    if (!isset($params['status']) || !in_array($params['status'], ['pending', 'completed', 'cancelled'])) {
        return new WP_Error('invalid_status', 'Invalid status provided', array('status' => 400));
    }

    // Check if order exists
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_sales WHERE id = %d",
        $order_id
    ));

    if (!$order) {
        return new WP_Error('no_order', 'Order not found', array('status' => 404));
    }

    // Update status
    $wpdb->update(
        "{$wpdb->prefix}ims_sales",
        array('status' => $params['status'], 'updated_at' => current_time('mysql', true)),
        array('id' => $order_id),
        array('%s', '%s'),
        array('%d')
    );

    $response = array(
        'success' => true,
        'message' => 'Order status updated successfully',
        'data' => array(
            'id' => (int)$order_id,
            'status' => $params['status'],
            'updatedAt' => gmdate('c'),
        ),
    );

    return rest_ensure_response($response);
}
function ims_update_order_details($request) {
    global $wpdb;
    $order_id = $request['orderId'];
    $params = $request->get_json_params();

    // Validate input
    if (empty($params)) {
        return new WP_Error('invalid_params', 'No valid parameters provided', array('status' => 400));
    }

    // Check if order exists
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_sales WHERE id = %d",
        $order_id
    ));

    if (!$order) {
        return new WP_Error('no_order', 'Order not found', array('status' => 404));
    }

    // Prepare update data
    $update_data = array();
    $update_formats = array();
    $customer = null;

    if (isset($params['paymentMethod']) && in_array($params['paymentMethod'], ['cash', 'credit', 'bank_transfer', 'cheque'])) {
        $update_data['payment_method'] = $params['paymentMethod'];
        $update_formats[] = '%s';
    }

    if (isset($params['customerId'])) {
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, current_balance, credit_limit FROM {$wpdb->prefix}ims_customers WHERE id = %d",
            $params['customerId']
        ));
        if (!$customer) {
            return new WP_Error('no_customer', 'Customer not found', array('status' => 404));
        }
        $update_data['customer_id'] = $params['customerId'];
        $update_formats[] = '%d';
    }

    if (isset($params['notes'])) {
        $update_data['notes'] = sanitize_text_field($params['notes']);
        $update_formats[] = '%s';
    }

    if (empty($update_data)) {
        return new WP_Error('no_updates', 'No valid fields to update', array('status' => 400));
    }

    $update_data['updated_at'] = current_time('mysql', true);
    $update_formats[] = '%s';

    // Handle credit balance updates
    $customer_id = $update_data['customer_id'] ?? $order->customer_id;
    if (!isset($customer)) {
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, current_balance, credit_limit FROM {$wpdb->prefix}ims_customers WHERE id = %d",
            $customer_id
        ));
    }

    $new_payment_method = $update_data['payment_method'] ?? $order->payment_method;
    $old_payment_method = $order->payment_method;
    $order_total = floatval($order->total);

    // Start a transaction to ensure data consistency
    $wpdb->query('START TRANSACTION');

    try {
        // Update customer balance if payment method changes
        if ($new_payment_method !== $old_payment_method) {
            if ($new_payment_method === 'credit' && $old_payment_method !== 'credit') {
                // Changing to credit: increase customer's current_balance
                $new_balance = floatval($customer->current_balance) + $order_total;
                if ($new_balance > floatval($customer->credit_limit)) {
                    throw new Exception('Credit limit exceeded for customer');
                }
                $wpdb->update(
                    "{$wpdb->prefix}ims_customers",
                    array('current_balance' => number_format($new_balance, 2, '.', '')),
                    array('id' => $customer_id),
                    array('%f'),
                    array('%d')
                );
            } elseif ($old_payment_method === 'credit' && $new_payment_method !== 'credit') {
                // Changing from credit to paid: decrease customer's current_balance
                $new_balance = max(0, floatval($customer->current_balance) - $order_total);
                $wpdb->update(
                    "{$wpdb->prefix}ims_customers",
                    array('current_balance' => number_format($new_balance, 2, '.', '')),
                    array('id' => $customer_id),
                    array('%f'),
                    array('%d')
                );

                // Optionally, record a payment in ims_payments
                $wpdb->insert(
                    "{$wpdb->prefix}ims_payments",
                    array(
                        'customer_id' => $customer_id,
                        'amount' => number_format($order_total, 2, '.', ''),
                        'payment_method' => $new_payment_method,
                        'reference' => 'Order #' . $order->order_number,
                        'notes' => 'Payment for order updated from credit to ' . $new_payment_method,
                        'date' => current_time('Y-m-d'),
                        'created_at' => current_time('mysql', true)
                    ),
                    array('%d', '%f', '%s', '%s', '%s', '%s', '%s')
                );
            }
        }

        // Update order
        $wpdb->update(
            "{$wpdb->prefix}ims_sales",
            $update_data,
            array('id' => $order_id),
            $update_formats,
            array('%d')
        );

        // Commit transaction
        $wpdb->query('COMMIT');

        // Fetch updated customer name
        $customer_name = $customer->name;

        $response = array(
            'success' => true,
            'message' => 'Order details updated successfully',
            'data' => array(
                'id' => (int)$order_id,
                'paymentMethod' => $new_payment_method,
                'customerId' => (int)$customer_id,
                'customerName' => $customer_name,
                'updatedAt' => gmdate('c'),
                'currentBalance' => floatval($new_balance ?? $customer->current_balance) // Include updated balance in response
            ),
        );

        return rest_ensure_response($response);

    } catch (Exception $e) {
        // Rollback transaction on error
        $wpdb->query('ROLLBACK');
        return new WP_Error('update_failed', $e->getMessage(), array('status' => 400));
    }
}
// 4. Return Items (Partial)
function ims_return_items($request) {
    global $wpdb;
    $order_id = $request['orderId'];
    $params = $request->get_json_params();

    // Validate input
    if (!isset($params['type']) || $params['type'] !== 'return' || empty($params['items']) || !isset($params['refundAmount']) || !isset($params['restockItems'])) {
        return new WP_Error('invalid_params', 'Invalid or missing parameters', array('status' => 400));
    }

    // Check if order exists and is completed
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_sales WHERE id = %d AND status = 'completed'",
        $order_id
    ));

    if (!$order) {
        return new WP_Error('no_order', 'Order not found or not completed', array('status' => 404));
    }

    // Start transaction
    $wpdb->query('START TRANSACTION');

    try {
        // Create adjustment record
        $wpdb->insert(
            "{$wpdb->prefix}ims_sale_adjustments",
            array(
                'sale_id' => $order_id,
                'type' => 'return',
                'reason' => sanitize_text_field($params['adjustmentReason'] ?? ''),
                'refund_amount' => number_format(floatval($params['refundAmount']), 2, '.', ''),
                'restock_items' => $params['restockItems'] ? 1 : 0,
                'processed_at' => current_time('mysql', true),
            ),
            array('%d', '%s', '%s', '%f', '%d', '%s')
        );

        $adjustment_id = $wpdb->insert_id;
        $updated_inventory = array();
        $total_refund = 0;

        foreach ($params['items'] as $item) {
            if (!isset($item['productId']) || !isset($item['quantity']) || !isset($item['reason'])) {
                throw new Exception('Invalid item data: missing productId, quantity, or reason');
            }

            $return_quantity = floatval($item['quantity']);
            
            // Validate return quantity
            if ($return_quantity <= 0) {
                throw new Exception('Return quantity must be greater than 0');
            }

            // Verify sale item exists and get current quantity
            $sale_item = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ims_sale_items WHERE sale_id = %d AND product_id = %d",
                $order_id, $item['productId']
            ));

            if (!$sale_item) {
                throw new Exception('Product not found in this order');
            }

            $current_quantity = floatval($sale_item->quantity);
            
            // Validate that return quantity doesn't exceed purchased quantity
            if ($return_quantity > $current_quantity) {
                throw new Exception('Return quantity cannot exceed purchased quantity');
            }

            // Update sale item quantity and total
            $new_quantity = $current_quantity - $return_quantity;
            $new_total = $new_quantity * floatval($sale_item->unit_price);

            if ($new_quantity > 0) {
                // Update existing sale item
                $wpdb->update(
                    "{$wpdb->prefix}ims_sale_items",
                    array(
                        'quantity' => number_format($new_quantity, 2, '.', ''),
                        'total' => number_format($new_total, 2, '.', ''),
                    ),
                    array(
                        'sale_id' => $order_id,
                        'product_id' => $item['productId'],
                    ),
                    array('%f', '%f'),
                    array('%d', '%d')
                );
            } else {
                // Delete sale item if quantity becomes 0
                $wpdb->delete(
                    "{$wpdb->prefix}ims_sale_items",
                    array(
                        'sale_id' => $order_id,
                        'product_id' => $item['productId'],
                    ),
                    array('%d', '%d')
                );
            }

            // Insert adjustment item
            $wpdb->insert(
                "{$wpdb->prefix}ims_sale_adjustment_items",
                array(
                    'adjustment_id' => $adjustment_id,
                    'product_id' => $item['productId'],
                    'quantity' => number_format($return_quantity, 2, '.', ''),
                    'reason' => sanitize_text_field($item['reason']),
                    'restocked' => $params['restockItems'] ? 1 : 0,
                ),
                array('%d', '%d', '%f', '%s', '%d')
            );

            if ($params['restockItems']) {
                // Update inventory
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT stock FROM {$wpdb->prefix}ims_products WHERE id = %d",
                    $item['productId']
                ));

                if (!$product) {
                    throw new Exception('Product not found in inventory');
                }

                $current_stock = floatval($product->stock);
                $new_stock = $current_stock + $return_quantity;
                
                $wpdb->update(
                    "{$wpdb->prefix}ims_products",
                    array('stock' => number_format($new_stock, 2, '.', '')),
                    array('id' => $item['productId']),
                    array('%f'),
                    array('%d')
                );

                // Log inventory movement
                $wpdb->insert(
                    "{$wpdb->prefix}ims_inventory_movements",
                    array(
                        'product_id' => $item['productId'],
                        'type' => 'return',
                        'quantity' => number_format($return_quantity, 2, '.', ''),
                        'balance_before' => number_format($current_stock, 2, '.', ''),
                        'balance_after' => number_format($new_stock, 2, '.', ''),
                        'reference' => 'ADJ-' . $adjustment_id,
                        'reason' => $item['reason'],
                        'created_at' => current_time('mysql', true),
                    ),
                    array('%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s')
                );

                $updated_inventory[] = array(
                    'productId' => (int)$item['productId'],
                    'newStock' => floatval($new_stock),
                );
            }

            $total_refund += $return_quantity * floatval($sale_item->unit_price);
        }

        // Validate refund amount matches calculated total
        $calculated_refund = floatval($params['refundAmount']);
        if (abs($total_refund - $calculated_refund) > 0.01) { // Allow small rounding differences
            throw new Exception('Refund amount does not match calculated total from returned items');
        }

        // Update order totals
        $new_total = floatval($order->total) - $calculated_refund;
        $wpdb->update(
            "{$wpdb->prefix}ims_sales",
            array(
                'total' => number_format($new_total, 2, '.', ''), 
                'subtotal' => number_format($new_total, 2, '.', ''), 
                'updated_at' => current_time('mysql', true)
            ),
            array('id' => $order_id),
            array('%f', '%f', '%s'),
            array('%d')
        );

        $wpdb->query('COMMIT');

        $response = array(
            'success' => true,
            'message' => 'Items returned successfully',
            'data' => array(
                'adjustmentId' => (int)$adjustment_id,
                'orderId' => (int)$order_id,
                'refundAmount' => floatval($params['refundAmount']),
                'itemsReturned' => array_map(function ($item) use ($params) {
                    return array(
                        'productId' => (int)$item['productId'],
                        'quantity' => floatval($item['quantity']),
                        'restocked' => $params['restockItems'],
                    );
                }, $params['items']),
                'updatedInventory' => $updated_inventory,
                'processedAt' => gmdate('c'),
            ),
        );

        return rest_ensure_response($response);
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('return_failed', $e->getMessage(), array('status' => 400));
    }
}

// 5. Complete Order Reversal
function ims_revert_order($request) {
    global $wpdb;
    $order_id = $request['orderId'];
    $params = $request->get_json_params();

    // Validate input
    if (!isset($params['reason']) || !isset($params['restoreInventory']) || !isset($params['processRefund'])) {
        return new WP_Error('invalid_params', 'Missing required parameters', array('status' => 400));
    }

    // Check if order exists and is not already cancelled
    $order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ims_sales WHERE id = %d AND status != 'cancelled'",
        $order_id
    ));

    if (!$order) {
        return new WP_Error('no_order', 'Order not found or already cancelled', array('status' => 404));
    }

    // Start transaction
    $wpdb->query('START TRANSACTION');

    try {
        // Create adjustment record
        $wpdb->insert(
            "{$wpdb->prefix}ims_sale_adjustments",
            array(
                'sale_id' => $order_id,
                'type' => 'full_reversal',
                'reason' => sanitize_text_field($params['reason']),
                'refund_amount' => number_format(floatval($order->total), 2, '.', ''),
                'restock_items' => $params['restoreInventory'] ? 1 : 0,
                'processed_at' => current_time('mysql', true),
            ),
            array('%d', '%s', '%s', '%f', '%d', '%s')
        );

        $adjustment_id = $wpdb->insert_id;

        $inventory_restored = array();
        if ($params['restoreInventory']) {
            // Fetch order items
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, quantity FROM {$wpdb->prefix}ims_sale_items WHERE sale_id = %d",
                $order_id
            ));

            foreach ($items as $item) {
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT stock FROM {$wpdb->prefix}ims_products WHERE id = %d",
                    $item->product_id
                ));

                if (!$product) {
                    throw new Exception('Product not found');
                }

                $new_stock = floatval($product->stock) + floatval($item->quantity);
                $wpdb->update(
                    "{$wpdb->prefix}ims_products",
                    array('stock' => number_format($new_stock, 2, '.', '')),
                    array('id' => $item->product_id),
                    array('%f'),
                    array('%d')
                );

                // Log inventory movement
                $wpdb->insert(
                    "{$wpdb->prefix}ims_inventory_movements",
                    array(
                        'product_id' => $item->product_id,
                        'type' => 'return',
                        'quantity' => number_format(floatval($item->quantity), 2, '.', ''),
                        'balance_before' => number_format(floatval($product->stock), 2, '.', ''),
                        'balance_after' => number_format($new_stock, 2, '.', ''),
                        'reference' => 'ADJ-' . $adjustment_id,
                        'reason' => $params['reason'],
                        'created_at' => current_time('mysql', true),
                    ),
                    array('%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s')
                );

                $inventory_restored[] = array(
                    'productId' => (int)$item->product_id,
                    'quantityRestored' => floatval($item->quantity),
                    'newStock' => floatval($new_stock),
                );
            }
        }

        // Update order status
        $wpdb->update(
            "{$wpdb->prefix}ims_sales",
            array(
                'status' => 'cancelled',
                'cancel_reason' => sanitize_text_field($params['reason']),
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => $order_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        $wpdb->query('COMMIT');

        $response = array(
            'success' => true,
            'message' => 'Order completely reverted successfully',
            'data' => array(
                'orderId' => (int)$order_id,
                'originalStatus' => $order->status,
                'newStatus' => 'cancelled',
                'refundAmount' => floatval($order->total),
                'inventoryRestored' => $inventory_restored,
                'adjustmentRecord' => array(
                    'id' => (int)$adjustment_id,
                    'type' => 'full_reversal',
                    'reason' => $params['reason'],
                ),
                'processedAt' => gmdate('c'),
            ),
        );

        return rest_ensure_response($response);
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('revert_failed', $e->getMessage(), array('status' => 400));
    }
}




// Register the Settings API Endpoint
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/settings', array(
        'methods' => 'GET',
        'callback' => 'ims_get_settings',
        'permission_callback' => '__return_true', // Consider restricting this if possible
    ));
});
/**
 * Callback function to fetch settings from the database.
 * Returns a JSON object with key-value pairs.
 */
function ims_get_settings() {
    global $wpdb;
    // Define the table name
    // $wpdb->prefix handles the 'wp_' or custom prefix automatically if you used it in creation.
    // However, your SQL created 'ims_settings' directly. 
    // If you created it as 'wp_ims_settings', use: $table_name = $wpdb->prefix . 'ims_settings';
    // Since the SQL was explicitly 'ims_settings', we use that.
    $table_name = 'ims_settings';
    // Check if table exists to avoid errors
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        return new WP_Error('no_table', 'Settings table not found', array('status' => 500));
    }
    // Query all settings
    $results = $wpdb->get_results("SELECT setting_key, setting_value FROM $table_name", ARRAY_A);
    $settings = array();
    if ($results) {
        foreach ($results as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings; // Returns JSON automatically
}
