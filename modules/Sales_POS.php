<?php
// Sales (POS).php functionality here

/**
 * IMS Sales API Endpoints
 * Add these functions to your WordPress functions.php file
 * Modified for public access without authentication
 * 
 * Updated to support decimal quantities (quantity field changed from int to decimal(10,2))
 * All quantity fields now use %f format specifier and return float values
 */

// Register REST API endpoints for Sales
add_action('rest_api_init', function () {
    // GET /wp-json/ims/v1/sales
    register_rest_route('ims/v1', '/sales', array(
        'methods' => 'GET',
        'callback' => 'ims_get_sales',
        'permission_callback' => '__return_true' // No authentication required
    ));

    // GET /wp-json/ims/v1/sales/{id}
    register_rest_route('ims/v1', '/sales/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_sale_by_id',
        'permission_callback' => '__return_true' // No authentication required
    ));

    // POST /wp-json/ims/v1/sales
    register_rest_route('ims/v1', '/sales', array(
        'methods' => 'POST',
        'callback' => 'ims_create_sale',
        'permission_callback' => '__return_true' // No authentication required
    ));

    // PUT /wp-json/ims/v1/sales/{id}/status
    register_rest_route('ims/v1', '/sales/(?P<id>\d+)/status', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_sale_status',
        'permission_callback' => '__return_true' // No authentication required
    ));
});

/**
 * GET /sales - List all sales with filtering and pagination
 */
function ims_get_sales($request) {
    global $wpdb;
    
    try {
        // Get query parameters
        $page = max(1, intval($request->get_param('page') ?: 1));
        $limit = min(1000, max(1, intval($request->get_param('limit') ?: 200)));
        $offset = ($page - 1) * $limit;
        
        $date_from = $request->get_param('dateFrom');
        $date_to = $request->get_param('dateTo');
        $customer_id = $request->get_param('customerId');
        $status = $request->get_param('status');
        
        // Build WHERE clause
        $where_conditions = array('1=1');
        $where_values = array();
        
        if ($date_from) {
            $where_conditions[] = 's.date >= %s';
            $where_values[] = $date_from;
        }
        
        if ($date_to) {
            $where_conditions[] = 's.date <= %s';
            $where_values[] = $date_to;
        }
        
        if ($customer_id) {
            $where_conditions[] = 's.customer_id = %d';
            $where_values[] = $customer_id;
        }
        
        if ($status) {
            $where_conditions[] = 's.status = %s';
            $where_values[] = $status;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Main query to get sales with customer info
        $sales_query = "
            SELECT 
                s.*,
                c.name as customer_name
            FROM {$wpdb->prefix}ims_sales s
            LEFT JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id
            WHERE {$where_clause}
            ORDER BY s.created_at DESC
            LIMIT %d OFFSET %d
        ";
        
        $query_values = array_merge($where_values, array($limit, $offset));
        $sales = $wpdb->get_results($wpdb->prepare($sales_query, $query_values));
        
        // Get total count for pagination
        $count_query = "
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}ims_sales s 
            WHERE {$where_clause}
        ";
        $total_items = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        
        // Get sale items for each sale
        $formatted_sales = array();
        foreach ($sales as $sale) {
            $items_query = "
                SELECT 
                    si.*,
                    p.name as product_name,
                    p.sku
                FROM {$wpdb->prefix}ims_sale_items si
                JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
                WHERE si.sale_id = %d
            ";
            $items = $wpdb->get_results($wpdb->prepare($items_query, $sale->id));
            
            $formatted_items = array();
            foreach ($items as $item) {
                            $formatted_items[] = array(
                'productId' => (int)$item->product_id,
                'productName' => $item->product_name,
                'quantity' => (float)$item->quantity,
                'unitPrice' => (float)$item->unit_price,
                'total' => (float)$item->total
            );
            }
            
            $formatted_sales[] = array(
                'id' => (int)$sale->id,
                'orderNumber' => $sale->order_number,
                'customerId' => $sale->customer_id ? (int)$sale->customer_id : null,
                'customerName' => $sale->customer_name,
                'date' => $sale->date,
                'time' => $sale->time,
                'items' => $formatted_items,
                'subtotal' => (float)$sale->subtotal,
                'discount' => (float)$sale->discount,
                'total' => (float)$sale->total,
                'paymentMethod' => $sale->payment_method,
                'status' => $sale->status,
                'createdBy' => $sale->created_by,
                'createdAt' => mysql2date('c', $sale->created_at)
            );
        }
        
        // Calculate summary statistics
        $summary_query = "
            SELECT 
                SUM(total) as total_sales,
                COUNT(*) as total_orders,
                AVG(total) as avg_order_value
            FROM {$wpdb->prefix}ims_sales s
            WHERE {$where_clause}
        ";
        $summary = $wpdb->get_row($wpdb->prepare($summary_query, $where_values));
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'sales' => $formatted_sales,
                'pagination' => array(
                    'currentPage' => $page,
                    'totalPages' => ceil($total_items / $limit),
                    'totalItems' => (int)$total_items,
                    'itemsPerPage' => $limit
                ),
                'summary' => array(
                    'totalSales' => (float)($summary->total_sales ?: 0),
                    'totalOrders' => (int)($summary->total_orders ?: 0),
                    'avgOrderValue' => (float)($summary->avg_order_value ?: 0)
                )
            )
        ), 200);
        
    } catch (Exception $e) {
        error_log('IMS Sales API Error: ' . $e->getMessage());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Internal server error'
        ), 500);
    }
}

/**
 * GET /sales/{id} - Get specific sale details
 */
function ims_get_sale_by_id($request) {
    global $wpdb;
    
    try {
        $sale_id = $request['id'];
        
        // Get sale with customer details
        $sale_query = "
            SELECT 
                s.*,
                c.name as customer_name,
                c.email as customer_email,
                c.phone as customer_phone,
                c.address as customer_address
            FROM {$wpdb->prefix}ims_sales s
            LEFT JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id
            WHERE s.id = %d
        ";
        
        $sale = $wpdb->get_row($wpdb->prepare($sale_query, $sale_id));
        
        if (!$sale) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sale not found'
            ), 404);
        }
        
        // Get sale items
        $items_query = "
            SELECT 
                si.*,
                p.name as product_name,
                p.sku
            FROM {$wpdb->prefix}ims_sale_items si
            JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
            WHERE si.sale_id = %d
        ";
        $items = $wpdb->get_results($wpdb->prepare($items_query, $sale_id));
        
        $formatted_items = array();
        foreach ($items as $item) {
            $formatted_items[] = array(
                'productId' => (int)$item->product_id,
                'productName' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => (float)$item->quantity,
                'unitPrice' => (float)$item->unit_price,
                'total' => (float)$item->total
            );
        }
        
        $customer_details = null;
        if ($sale->customer_name) {
            $customer_details = array(
                'email' => $sale->customer_email,
                'phone' => $sale->customer_phone,
                'address' => $sale->customer_address
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'id' => (int)$sale->id,
                'orderNumber' => $sale->order_number,
                'customerId' => $sale->customer_id ? (int)$sale->customer_id : null,
                'customerName' => $sale->customer_name,
                'customerDetails' => $customer_details,
                'date' => $sale->date,
                'time' => $sale->time,
                'items' => $formatted_items,
                'subtotal' => (float)$sale->subtotal,
                'discount' => (float)$sale->discount,
                'total' => (float)$sale->total,
                'dueDate' => $sale->due_date,
                'paymentMethod' => $sale->payment_method,
                'status' => $sale->status,
                'notes' => $sale->notes,
                'createdBy' => $sale->created_by,
                'createdAt' => mysql2date('c', $sale->created_at)
            )
        ), 200);
        
    } catch (Exception $e) {
        error_log('IMS Sales API Error: ' . $e->getMessage());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Internal server error'
        ), 500);
    }
}

/**
 * POST /sales - Create new sale (UPDATED with decimal fixes)
 */
function ims_create_sale($request) {
    global $wpdb;
    
    try {
        $wpdb->query('START TRANSACTION');
        
        $body = $request->get_json_params();
        
        // Validate required fields
        if (empty($body['items']) || !is_array($body['items'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Items are required'
            ), 400);
        }
        
        // Generate order number
        $order_number = ims_generate_order_number();
        
        // Calculate totals
        $subtotal = 0;
        $validated_items = array();
        
        foreach ($body['items'] as $item) {
            if (empty($item['productId']) || empty($item['quantity']) || empty($item['unitPrice'])) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid item data'
                ), 400);
            }
            
            // Check product exists
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ims_products WHERE id = %d AND status = 'active' FOR UPDATE",
                $item['productId']
            ));
            
            if (!$product) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Product not found: ' . $item['productId']
                ), 400);
            }
            
            // Check if item is outsourced
            $is_outsourced = isset($item['outsourcing']) && !empty($item['outsourcing']['supplierId']);
            
            // Check stock only for non-outsourced items
            if (!$is_outsourced && $product->stock < $item['quantity']) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Insufficient stock for product: ' . $product->name
                ), 400);
            }
            
            $item_total = $item['quantity'] * $item['unitPrice'];
            $subtotal += $item_total;
            
            $validated_items[] = array(
                'productId' => $item['productId'],
                'quantity' => $item['quantity'],
                'unitPrice' => $item['unitPrice'],
                'total' => $item_total,
                'product' => $product,
                'is_outsourced' => $is_outsourced,
                'outsourcing_data' => $is_outsourced ? $item['outsourcing'] : null
            );
        }
        
        $discount = isset($body['discount']) ? floatval($body['discount']) : 0;
        $tax_rate = ims_get_tax_rate();
        $tax = ($subtotal - $discount) * ($tax_rate / 100);
        $total = $subtotal - $discount + $tax;
        
        // Insert sale record
        $sale_data = array(
            'order_number' => $order_number,
            'customer_id' => isset($body['customerId']) ? $body['customerId'] : null,
            'date' => current_time('Y-m-d'),
            'time' => current_time('H:i:s'),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'payment_method' => isset($body['paymentMethod']) ? $body['paymentMethod'] : null,
            'status' => 'completed',
            'notes' => isset($body['notes']) ? $body['notes'] : null,
            'created_by' => 0 // Set to 0 since no user authentication
        );
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ims_sales',
            $sale_data,
            array('%s', '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%d')
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to create sale'
            ), 500);
        }
        
        $sale_id = $wpdb->insert_id;
        
        // Insert sale items and update stock (only for non-outsourced items)
        foreach ($validated_items as $item) {
            // Prepare sale item data
            $sale_item_data = array(
                'sale_id' => $sale_id,
                'product_id' => $item['productId'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unitPrice'],
                'total' => $item['total'],
                'is_outsourced' => $item['is_outsourced'] ? 1 : 0
            );
            
            // Add outsourcing data if applicable
            if ($item['is_outsourced']) {
                $sale_item_data['outsourcing_supplier_id'] = $item['outsourcing_data']['supplierId'];
                $sale_item_data['outsourcing_cost_per_unit'] = $item['outsourcing_data']['costPerUnit'];
                
                // Create external purchase record
                $external_purchase_data = array(
                    'sale_id' => $sale_id,
                    'product_id' => $item['productId'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['outsourcing_data']['costPerUnit'],
                    'total' => $item['quantity'] * $item['outsourcing_data']['costPerUnit'],
                    'source' => isset($item['outsourcing_data']['source']) ? $item['outsourcing_data']['source'] : 'External Supplier',
                    'reference' => $order_number
                );
                
                $wpdb->insert(
                    $wpdb->prefix . 'ims_external_purchases',
                    $external_purchase_data,
                    array('%d', '%d', '%f', '%f', '%f', '%s', '%s')
                );
            }
            
            // Insert sale item (Moved outside to ensure it runs for both cases)
            $wpdb->insert(
                $wpdb->prefix . 'ims_sale_items',
                $sale_item_data + array('cost_at_sale' => $item['product']->cost_price),
                array('%d', '%d', '%f', '%f', '%f', '%d', '%d', '%f', '%f')
            );
            
            $sale_item_id = $wpdb->insert_id;
            
            // Create outsourcing order for outsourced items
            if ($item['is_outsourced']) {
                $outsourcing_order_number = 'OUT-' . date('Ymd') . '-' . strtoupper(wp_generate_password(6, false));
                
                $quantity = floatval($item['quantity']);
                $cost_per_unit = floatval($item['outsourcing_data']['costPerUnit']);
                $total_cost = $quantity * $cost_per_unit;
                
                $outsourcing_order_data = array(
                    'order_number' => $outsourcing_order_number,
                    'sale_id' => $sale_id,
                    'sale_item_id' => $sale_item_id,
                    'product_id' => $item['productId'],
                    'supplier_id' => $item['outsourcing_data']['supplierId'],
                    'quantity' => $quantity,
                    'cost_per_unit' => $cost_per_unit,
                    'total_cost' => $total_cost,
                    'notes' => isset($item['outsourcing_data']['notes']) ? $item['outsourcing_data']['notes'] : 'Created from sale ' . $order_number,
                    'status' => 'pending',
                    'created_at' => current_time('mysql', 1),
                    'updated_at' => current_time('mysql', 1)
                );
                
                $wpdb->insert(
                    $wpdb->prefix . 'ims_outsourcing_orders',
                    $outsourcing_order_data,
                    array('%s', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
                );
            } else {
                // Update product stock only for non-outsourced items
                $old_stock = $item['product']->stock;
                $new_stock = $old_stock - $item['quantity'];
                
                $wpdb->update(
                    $wpdb->prefix . 'ims_products',
                    array('stock' => $new_stock),
                    array('id' => $item['productId']),
                    array('%f'),
                    array('%d')
                );
                
                // Record inventory movement
                $wpdb->insert(
                    $wpdb->prefix . 'ims_inventory_movements',
                    array(
                        'product_id' => $item['productId'],
                        'type' => 'sale',
                        'quantity' => -$item['quantity'],
                        'balance_before' => $old_stock,
                        'balance_after' => $new_stock,
                        'reference' => $order_number,
                        'reason' => 'Sale transaction'
                    ),
                    array('%d', '%s', '%f', '%f', '%f', '%s', '%s')
                );
            }
        }

        // =====================================================
        // CALCULATE AND RECORD PROFIT FOR REPORTING
        // =====================================================
        foreach ($validated_items as $item) {
            $quantity = floatval($item['quantity']);
            $unit_price = floatval($item['unitPrice']);
            $revenue = $quantity * $unit_price;
            
            // Calculate COGS
            $cost_price = 0;
            if ($item['is_outsourced']) {
                $cost_price = floatval($item['outsourcing_data']['costPerUnit']);
            } else {
                $cost_price = floatval($item['product']->cost_price);
            }
            $cogs = $quantity * $cost_price;
            
            // Calculate Profit
            $profit = $revenue - $cogs;
            
            // Insert into Profit Table
             $wpdb->insert(
                $wpdb->prefix . 'ims_profit',
                array(
                    'reference_id' => $sale_id,
                    'reference_type' => 'sale',
                    'period_type' => 'sale',
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'expenses' => 0, // Expenses are tracked separately
                    'profit' => $profit,
                    'period_start' => current_time('Y-m-d'),
                    'period_end' => current_time('Y-m-d'),
                    'sale_date' => current_time('Y-m-d'),
                    'product_id' => $item['productId'],
                    'created_at' => current_time('mysql', 1)
                ),
                array('%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%s')
            );
        }
        // =====================================================
        // END PROFIT RECORDING
        // =====================================================
        
        // Update customer balance if customer exists
        if (!empty($body['customerId']) && $body['paymentMethod'] === 'credit') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ims_customers 
                 SET current_balance = current_balance + %f,
                     total_purchases = total_purchases + %f
                 WHERE id = %d",
                $total, $total, $body['customerId']
            ));
        }
        
        $wpdb->query('COMMIT');
        
        $created_sale = ims_get_sale_data($sale_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $created_sale,
            'message' => 'Sale created successfully'
        ), 201);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('IMS Sales API Error: ' . $e->getMessage());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Internal server error'
        ), 500);
    }
}

/**
 * PUT /sales/{id}/status - Update sale status
 */
function ims_update_sale_status($request) {
    global $wpdb;
    
    try {
        $sale_id = $request['id'];
        $body = $request->get_json_params();
        
        if (empty($body['status'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Status is required'
            ), 400);
        }
        
        $allowed_statuses = array('pending', 'completed', 'cancelled');
        if (!in_array($body['status'], $allowed_statuses)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid status'
            ), 400);
        }
        
        // Get current sale
        $sale = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_sales WHERE id = %d",
            $sale_id
        ));
        
        if (!$sale) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Sale not found'
            ), 404);
        }
        
        $wpdb->query('START TRANSACTION');
        
        // FAILSAFE: Prevent un-cancelling a sale
        if ($sale->status === 'cancelled' && $body['status'] !== 'cancelled') {
             return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Cancelled sales cannot be reverted.'
            ), 400);
        }

        // Handle status-specific logic
        if ($body['status'] === 'cancelled' && $sale->status !== 'cancelled') {
            // Restore stock for cancelled sales
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT si.*, p.stock as current_stock 
                 FROM {$wpdb->prefix}ims_sale_items si
                 JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
                 WHERE si.sale_id = %d FOR UPDATE",
                $sale_id
            ));
            
            foreach ($items as $item) {
                $new_stock = $item->current_stock + $item->quantity;
                
                $wpdb->update(
                    $wpdb->prefix . 'ims_products',
                    array('stock' => $new_stock),
                    array('id' => $item->product_id),
                    array('%f'),
                    array('%d')
                );
                
                // Record inventory movement
                $wpdb->insert(
                    $wpdb->prefix . 'ims_inventory_movements',
                    array(
                        'product_id' => $item->product_id,
                        'type' => 'return',
                        'quantity' => $item->quantity,
                        'balance_before' => $item->current_stock,
                        'balance_after' => $new_stock,
                        'reference' => $sale->order_number,
                        'reason' => 'Sale cancelled: ' . ($body['reason'] ?? '')
                    ),
                    array('%d', '%s', '%f', '%f', '%f', '%s', '%s')
                );
            }
            
            // Update customer balance if applicable
            if ($sale->customer_id && $sale->payment_method === 'credit') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ims_customers 
                     SET current_balance = current_balance - %f,
                         total_purchases = total_purchases - %f
                     WHERE id = %d",
                    $sale->total, $sale->total, $sale->customer_id
                ));
            }
        }
        
        // Handle un-cancellation (restoring a cancelled sale)
        elseif ($sale->status === 'cancelled' && $body['status'] !== 'cancelled') {
            // Re-deduct stock for un-cancelled sales
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT si.*, p.stock as current_stock 
                 FROM {$wpdb->prefix}ims_sale_items si
                 JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
                 WHERE si.sale_id = %d FOR UPDATE",
                $sale_id
            ));
            
            foreach ($items as $item) {
                // Skip outsourced items
                if (isset($item->is_outsourced) && $item->is_outsourced) {
                    continue;
                }

                $new_stock = $item->current_stock - $item->quantity;
                
                $wpdb->update(
                    $wpdb->prefix . 'ims_products',
                    array('stock' => $new_stock),
                    array('id' => $item->product_id),
                    array('%f'),
                    array('%d')
                );
                
                // Record inventory movement
                $wpdb->insert(
                    $wpdb->prefix . 'ims_inventory_movements',
                    array(
                        'product_id' => $item->product_id,
                        'type' => 'sale',
                        'quantity' => -$item->quantity,
                        'balance_before' => $item->current_stock,
                        'balance_after' => $new_stock,
                        'reference' => $sale->order_number,
                        'reason' => 'Sale un-cancelled (restored)'
                    ),
                    array('%d', '%s', '%f', '%f', '%f', '%s', '%s')
                );
            }
            
            // Update customer balance if applicable
            if ($sale->customer_id && $sale->payment_method === 'credit') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ims_customers 
                     SET current_balance = current_balance + %f,
                         total_purchases = total_purchases + %f
                     WHERE id = %d",
                    $sale->total, $sale->total, $sale->customer_id
                ));
            }
        }
        
        // Update sale status
        $result = $wpdb->update(
            $wpdb->prefix . 'ims_sales',
            array('status' => $body['status']),
            array('id' => $sale_id),
            array('%s'),
            array('%d')
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update sale status'
            ), 500);
        }
        
        $wpdb->query('COMMIT');
        
        // Get updated sale data
        $updated_sale = ims_get_sale_data($sale_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $updated_sale,
            'message' => 'Sale status updated successfully'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        error_log('IMS Sales API Error: ' . $e->getMessage());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Internal server error'
        ), 500);
    }
}

/**
 * Helper function to generate order number
 */
function ims_generate_order_number() {
    global $wpdb;
    
    $prefix = 'ORD-';
    $today = date('Ymd');
    
    // Get the last order number for today
    $last_order = $wpdb->get_var($wpdb->prepare(
        "SELECT order_number FROM {$wpdb->prefix}ims_sales 
         WHERE order_number LIKE %s 
         ORDER BY id DESC LIMIT 1",
        $prefix . $today . '%'
    ));
    
    if ($last_order) {
        $last_number = intval(substr($last_order, -3));
        $new_number = str_pad($last_number + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $new_number = '001';
    }
    
    return $prefix . $today . $new_number;
}

/**
 * Helper function to get complete sale data
 */
function ims_get_sale_data($sale_id) {
    global $wpdb;
    
    $sale = $wpdb->get_row($wpdb->prepare(
        "SELECT s.*, c.name as customer_name
         FROM {$wpdb->prefix}ims_sales s
         LEFT JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id
         WHERE s.id = %d",
        $sale_id
    ));
    
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT si.*, p.name as product_name, p.sku
         FROM {$wpdb->prefix}ims_sale_items si
         JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
         WHERE si.sale_id = %d",
        $sale_id
    ));
    
    $formatted_items = array();
    foreach ($items as $item) {
        $formatted_items[] = array(
            'productId' => (int)$item->product_id,
            'productName' => $item->product_name,
            'sku' => $item->sku,
            'quantity' => (float)$item->quantity,
            'unitPrice' => (float)$item->unit_price,
            'total' => (float)$item->total
        );
    }
    
    return array(
        'id' => (int)$sale->id,
        'orderNumber' => $sale->order_number,
        'customerId' => $sale->customer_id ? (int)$sale->customer_id : null,
        'customerName' => $sale->customer_name,
        'date' => $sale->date,
        'time' => $sale->time,
        'items' => $formatted_items,
        'subtotal' => (float)$sale->subtotal,
        'discount' => (float)$sale->discount,
        'total' => (float)$sale->total,
        'paymentMethod' => $sale->payment_method,
        'status' => $sale->status,
        'notes' => $sale->notes,
        'createdBy' => $sale->created_by,
        'createdAt' => mysql2date('c', $sale->created_at)
    );
}

/**
 * Get the current tax rate
 * 
 * @return float Tax rate percentage
 */
function ims_get_tax_rate() {
    // You can modify this to fetch from a settings table or use a constant
    return 0; // Default 16% tax rate
}