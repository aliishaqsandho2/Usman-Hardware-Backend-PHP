<?php
// Register API routes
add_action('rest_api_init', function () {
    // GET /purchase-orders - List all purchase orders
    register_rest_route('ims/v1', '/purchase-orders', array(
        'methods' => 'GET',
        'callback' => 'ims_get_purchase_orders',
        'permission_callback' => '__return_true'
    ));

    // POST /purchase-orders - Create a new purchase order
    register_rest_route('ims/v1', '/purchase-orders', array(
        'methods' => 'POST',
        'callback' => 'ims_create_purchase_order',
        'permission_callback' => '__return_true'
    ));

    // GET /purchase-orders/:id - Retrieve a single purchase order
    register_rest_route('ims/v1', '/purchase-orders/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_single_purchase_order',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            )
        )
    ));

    // PUT /purchase-orders/:id - Update a purchase order
    register_rest_route('ims/v1', '/purchase-orders/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_purchase_order',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            )
        )
    ));

    // DELETE /purchase-orders/:id - Delete a purchase order
    register_rest_route('ims/v1', '/purchase-orders/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'ims_delete_purchase_order',
        'permission_callback' => '__true',
        'args' => array(
            'id' => array(
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            )
        )
    ));

    // PUT /purchase-orders/:id/receive - Mark purchase order as received
    register_rest_route('ims/v1', '/purchase-orders/(?P<id>\d+)/receive', array(
        'methods' => 'PUT',
        'callback' => 'ims_receive_purchase_order',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            )
        )
    ));
});

/**
 * GET /purchase-orders
 * Retrieve purchase orders with filtering and pagination
 */
function ims_get_purchase_orders($request) {
    global $wpdb;
    
    try {
        // Get parameters
        $page = max(1, intval($request->get_param('page') ?: 1));
        $limit = max(1, min(100, intval($request->get_param('limit') ?: 20)));
        $supplier_id = $request->get_param('supplierId');
        $status = $request->get_param('status');
        $date_from = $request->get_param('dateFrom');
        $date_to = $request->get_param('dateTo');
        $search = $request->get_param('search');
        
        $offset = ($page - 1) * $limit;
        
        // Build WHERE clause
        $where_conditions = array();
        $where_params = array();
        
        if ($supplier_id && is_numeric($supplier_id)) {
            $where_conditions[] = "po.supplier_id = %d";
            $where_params[] = $supplier_id;
        }
        
        if ($status && in_array($status, ['draft', 'sent', 'confirmed', 'received', 'cancelled'])) {
            $where_conditions[] = "po.status = %s";
            $where_params[] = $status;
        }
        
        if ($date_from) {
            if (!DateTime::createFromFormat('Y-m-d', $date_from)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid dateFrom format. Use Y-m-d.'
                ), 400);
            }
            $where_conditions[] = "po.date >= %s";
            $where_params[] = $date_from;
        }
        
        if ($date_to) {
            if (!DateTime::createFromFormat('Y-m-d', $date_to)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid dateTo format. Use Y-m-d.'
                ), 400);
            }
            $where_conditions[] = "po.date <= %s";
            $where_params[] = $date_to;
        }
        
        if ($search) {
            $where_conditions[] = "(po.order_number LIKE %s OR s.name LIKE %s)";
            $where_params[] = '%' . $wpdb->esc_like($search) . '%';
            $where_params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Get total count
        $count_query = "
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}ims_purchase_orders po 
            LEFT JOIN {$wpdb->prefix}ims_suppliers s ON po.supplier_id = s.id
            $where_clause
        ";
        
        $total_items = $wpdb->get_var(!empty($where_params) ? 
            $wpdb->prepare($count_query, $where_params) : $count_query);
        
        // Get purchase orders
        $query = "
            SELECT 
                po.*,
                s.name as supplier_name,
                u.display_name as created_by_name
            FROM {$wpdb->prefix}ims_purchase_orders po
            LEFT JOIN {$wpdb->prefix}ims_suppliers s ON po.supplier_id = s.id
            LEFT JOIN {$wpdb->prefix}users u ON po.created_by = u.ID
            $where_clause
            ORDER BY po.created_at DESC
            LIMIT %d OFFSET %d
        ";
        
        $query_params = array_merge($where_params, [$limit, $offset]);
        $purchase_orders = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        // Get items for each purchase order
        $formatted_orders = array();
        foreach ($purchase_orders as $order) {
            $items_query = "
                SELECT 
                    poi.*,
                    p.name as product_name
                FROM {$wpdb->prefix}ims_purchase_order_items poi
                LEFT JOIN {$wpdb->prefix}ims_products p ON poi.product_id = p.id
                WHERE poi.purchase_order_id = %d
                ORDER BY poi.id
            ";
            
            $items = $wpdb->get_results($wpdb->prepare($items_query, $order->id));
            
            $formatted_items = array();
            foreach ($items as $item) {
                $formatted_items[] = array(
                    'id' => intval($item->id),
                    'productId' => intval($item->product_id),
                    'productName' => $item->product_name,
                    'quantity' => floatval($item->quantity),
                    'unitPrice' => floatval($item->unit_price),
                    'total' => floatval($item->total),
                    'quantityReceived' => floatval($item->quantity_received),
                    'itemCondition' => $item->item_condition
                );
            }
            
            $formatted_orders[] = array(
                'id' => intval($order->id),
                'orderNumber' => $order->order_number,
                'supplierId' => $order->supplier_id ? intval($order->supplier_id) : null,
                'supplierName' => $order->supplier_name,
                'date' => $order->date,
                'expectedDelivery' => $order->expected_delivery,
                'items' => $formatted_items,
                'total' => floatval($order->total),
                'status' => $order->status,
                'notes' => $order->notes,
                'createdBy' => $order->created_by_name ?: 'System',
                'createdAt' => mysql_to_rfc3339($order->created_at),
                'updatedAt' => mysql_to_rfc3339($order->updated_at)
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'purchaseOrders' => $formatted_orders,
                'pagination' => array(
                    'currentPage' => $page,
                    'totalPages' => ceil($total_items / $limit),
                    'totalItems' => intval($total_items),
                    'itemsPerPage' => $limit
                )
            )
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to retrieve purchase orders: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * GET /purchase-orders/:id
 * Retrieve a single purchase order by ID
 */
function ims_get_single_purchase_order($request) {
    try {
        $id = intval($request['id']);
        $order = ims_get_purchase_order_by_id($id);
        
        if (!$order) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Purchase order not found'
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $order
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to retrieve purchase order: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * POST /purchase-orders
 * Create a new purchase order
 */
function ims_create_purchase_order($request) {
    global $wpdb;
    
    try {
        $data = $request->get_json_params();
        
        // Validate required fields
        if (empty($data['supplierId']) || empty($data['items']) || !is_array($data['items'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Missing required fields: supplierId and items are required'
            ), 400);
        }
        
        // Validate supplier exists
        $supplier = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d AND status = 'active'",
            $data['supplierId']
        ));
        
        if (!$supplier) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid or inactive supplier'
            ), 400);
        }
        
        // Validate expected delivery date if provided
        if (!empty($data['expectedDelivery']) && !DateTime::createFromFormat('Y-m-d', $data['expectedDelivery'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid expectedDelivery format. Use Y-m-d.'
            ), 400);
        }
        
        // Generate order number
        $order_number = ims_generate_purchase_order_number();
        
        // Calculate total
        $total = 0;
        $validated_items = array();
        
        foreach ($data['items'] as $item) {
            if (empty($item['productId']) || !isset($item['quantity']) || !isset($item['unitPrice'])) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Each item must have productId, quantity, and unitPrice'
                ), 400);
            }
            
            // Validate quantity and unitPrice
            if (!is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid quantity for product ID: ' . $item['productId']
                ), 400);
            }
            
            if (!is_numeric($item['unitPrice']) || $item['unitPrice'] < 0) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid unitPrice for product ID: ' . $item['productId']
                ), 400);
            }
            
            // Validate product exists
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ims_products WHERE id = %d AND status = 'active'",
                $item['productId']
            ));
            
            if (!$product) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid product ID: ' . $item['productId']
                ), 400);
            }
            
            $item_total = floatval($item['quantity']) * floatval($item['unitPrice']);
            $total += $item_total;
            
            $validated_items[] = array(
                'product_id' => intval($item['productId']),
                'quantity' => number_format($item['quantity'], 2, '.', ''),
                'unit_price' => number_format($item['unitPrice'], 2, '.', ''),
                'total' => number_format($item_total, 2, '.', '')
            );
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Insert purchase order
        $result = $wpdb->insert(
            "{$wpdb->prefix}ims_purchase_orders",
            array(
                'order_number' => $order_number,
                'supplier_id' => $data['supplierId'],
                'date' => current_time('Y-m-d'),
                'expected_delivery' => !empty($data['expectedDelivery']) ? $data['expectedDelivery'] : null,
                'subtotal' => $total,
                'tax' => 0,
                'total' => number_format($total, 2, '.', ''),
                'status' => 'draft',
                'notes' => sanitize_textarea_field($data['notes'] ?? ''),
                'created_by' => get_current_user_id() ?: 0,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to create purchase order'
            ), 500);
        }
        
        $purchase_order_id = $wpdb->insert_id;
        
        // Insert purchase order items
        foreach ($validated_items as $item) {
            $result = $wpdb->insert(
                "{$wpdb->prefix}ims_purchase_order_items",
                array(
                    'purchase_order_id' => $purchase_order_id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total'],
                    'quantity_received' => number_format(0, 2, '.', ''),
                    'item_condition' => 'good'
                ),
                array('%d', '%d', '%f', '%f', '%f', '%f', '%s')
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Failed to create purchase order items'
                ), 500);
            }
        }
        
        // FIXED: Update supplier total_purchases within transaction using direct query
        $update_supplier = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ims_suppliers 
             SET total_purchases = total_purchases + %f,
                 updated_at = %s
             WHERE id = %d",
            $total,
            current_time('mysql'),
            $data['supplierId']
        ));
        
        if ($update_supplier === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update supplier total purchases'
            ), 500);
        }
        
        $wpdb->query('COMMIT');
        
        // Get the created purchase order
        $created_order = ims_get_purchase_order_by_id($purchase_order_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $created_order,
            'message' => 'Purchase order created successfully'
        ), 201);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to create purchase order: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * PUT /purchase-orders/:id
 * Update an existing purchase order
 */
function ims_update_purchase_order($request) {
    global $wpdb;
    
    try {
        $purchase_order_id = intval($request['id']);
        $data = $request->get_json_params();
        
        // Validate purchase order exists
        $purchase_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_purchase_orders WHERE id = %d",
            $purchase_order_id
        ));
        
        if (!$purchase_order) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Purchase order not found'
            ), 404);
        }
        
        // Only allow updates for draft or sent orders
        if (!in_array($purchase_order->status, ['draft', 'sent'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Only draft or sent purchase orders can be updated'
            ), 400);
        }
        
        // Validate supplier if provided
        if (!empty($data['supplierId'])) {
            $supplier = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ims_suppliers WHERE id = %d AND status = 'active'",
                $data['supplierId']
            ));
            
            if (!$supplier) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid or inactive supplier'
                ), 400);
            }
        }
        
        // Validate expected delivery date if provided
        if (!empty($data['expectedDelivery']) && !DateTime::createFromFormat('Y-m-d', $data['expectedDelivery'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid expectedDelivery format. Use Y-m-d.'
            ), 400);
        }
        
        // Validate status if provided
        if (!empty($data['status']) && !in_array($data['status'], ['draft', 'sent', 'confirmed', 'cancelled'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid status. Allowed: draft, sent, confirmed, cancelled'
            ), 400);
        }
        
        // Prevent updating already cancelled orders (except to uncancel)
        if ($purchase_order->status === 'cancelled' && (!isset($data['status']) || $data['status'] === 'cancelled')) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Cancelled purchase orders cannot be modified'
            ), 400);
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        $old_total = floatval($purchase_order->total);
        $old_supplier_id = intval($purchase_order->supplier_id);
        $new_total = $old_total;
        $new_supplier_id = !empty($data['supplierId']) ? intval($data['supplierId']) : $old_supplier_id;
        
        // Handle items update if provided
        if (!empty($data['items']) && is_array($data['items'])) {
            $validated_items = array();
            $new_total = 0;
            
            foreach ($data['items'] as $item) {
                if (empty($item['productId']) || !isset($item['quantity']) || !isset($item['unitPrice'])) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Each item must have productId, quantity, and unitPrice'
                    ), 400);
                }
                
                // Validate quantity and unitPrice
                if (!is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Invalid quantity for product ID: ' . $item['productId']
                    ), 400);
                }
                
                if (!is_numeric($item['unitPrice']) || $item['unitPrice'] < 0) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Invalid unitPrice for product ID: ' . $item['productId']
                    ), 400);
                }
                
                // Validate product exists
                $product = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ims_products WHERE id = %d AND status = 'active'",
                    $item['productId']
                ));
                
                if (!$product) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Invalid product ID: ' . $item['productId']
                    ), 400);
                }
                
                $item_total = floatval($item['quantity']) * floatval($item['unitPrice']);
                $new_total += $item_total;
                
                $validated_items[] = array(
                    'product_id' => intval($item['productId']),
                    'quantity' => number_format($item['quantity'], 2, '.', ''),
                    'unit_price' => number_format($item['unitPrice'], 2, '.', ''),
                    'total' => number_format($item_total, 2, '.', '')
                );
            }
            
            // Delete existing items
            $wpdb->delete(
                "{$wpdb->prefix}ims_purchase_order_items",
                array('purchase_order_id' => $purchase_order_id),
                array('%d')
            );
            
            // Insert new items
            foreach ($validated_items as $item) {
                $result = $wpdb->insert(
                    "{$wpdb->prefix}ims_purchase_order_items",
                    array(
                        'purchase_order_id' => $purchase_order_id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total' => $item['total'],
                        'quantity_received' => number_format(0, 2, '.', ''),
                        'item_condition' => 'good'
                    ),
                    array('%d', '%d', '%f', '%f', '%f', '%f', '%s')
                );
                
                if ($result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Failed to update purchase order items'
                    ), 500);
                }
            }
        }
        
        // FIXED: Handle supplier totals with proper SQL queries
        $is_cancelling = (!empty($data['status']) && $data['status'] === 'cancelled');
        $is_uncancelling = (!empty($data['status']) && $data['status'] !== 'cancelled' && $purchase_order->status === 'cancelled');
        
        // Handle supplier total updates
        if ($new_supplier_id != $old_supplier_id || $new_total != $old_total || $is_cancelling || $is_uncancelling) {
            
            // If order is being cancelled, subtract from supplier
            if ($is_cancelling && $old_supplier_id && $old_total > 0) {
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ims_suppliers 
                     SET total_purchases = total_purchases - %f,
                         updated_at = %s
                     WHERE id = %d AND total_purchases >= %f",
                    $old_total,
                    current_time('mysql'),
                    $old_supplier_id,
                    $old_total
                ));
                
                if ($result === false || $wpdb->rows_affected == 0) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Failed to update supplier totals. Insufficient total purchases.'
                    ), 400);
                }
            }
            // If order is being uncancelled, add back to supplier
            elseif ($is_uncancelling && $old_supplier_id && $old_total > 0) {
                $result = $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ims_suppliers 
                     SET total_purchases = total_purchases + %f,
                         updated_at = %s
                     WHERE id = %d",
                    $old_total,
                    current_time('mysql'),
                    $old_supplier_id
                ));
                
                if ($result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Failed to update supplier totals'
                    ), 500);
                }
            }
            // Handle normal supplier/total changes
            elseif (!$is_cancelling) {
                // If supplier changed
                if ($new_supplier_id != $old_supplier_id) {
                    // Subtract old total from old supplier
                    if ($old_supplier_id && $old_total > 0) {
                        $result = $wpdb->query($wpdb->prepare(
                            "UPDATE {$wpdb->prefix}ims_suppliers 
                             SET total_purchases = total_purchases - %f,
                                 updated_at = %s
                             WHERE id = %d AND total_purchases >= %f",
                            $old_total,
                            current_time('mysql'),
                            $old_supplier_id,
                            $old_total
                        ));
                        
                        if ($result === false || $wpdb->rows_affected == 0) {
                            $wpdb->query('ROLLBACK');
                            return new WP_REST_Response(array(
                                'success' => false,
                                'message' => 'Failed to update old supplier totals. Insufficient total purchases.'
                            ), 400);
                        }
                    }
                    
                    // Add new total to new supplier
                    if ($new_supplier_id && $new_total > 0) {
                        $result = $wpdb->query($wpdb->prepare(
                            "UPDATE {$wpdb->prefix}ims_suppliers 
                             SET total_purchases = total_purchases + %f,
                                 updated_at = %s
                             WHERE id = %d",
                            $new_total,
                            current_time('mysql'),
                            $new_supplier_id
                        ));
                        
                        if ($result === false) {
                            $wpdb->query('ROLLBACK');
                            return new WP_REST_Response(array(
                                'success' => false,
                                'message' => 'Failed to update new supplier totals'
                            ), 500);
                        }
                    }
                } else {
                    // Same supplier, different total
                    $total_difference = $new_total - $old_total;
                    if (abs($total_difference) > 0.01 && $new_supplier_id) {
                        if ($total_difference < 0) {
                            // Subtracting from supplier
                            $result = $wpdb->query($wpdb->prepare(
                                "UPDATE {$wpdb->prefix}ims_suppliers 
                                 SET total_purchases = total_purchases - %f,
                                     updated_at = %s
                                 WHERE id = %d AND total_purchases >= %f",
                                abs($total_difference),
                                current_time('mysql'),
                                $new_supplier_id,
                                abs($total_difference)
                            ));
                            
                            if ($result === false || $wpdb->rows_affected == 0) {
                                $wpdb->query('ROLLBACK');
                                return new WP_REST_Response(array(
                                    'success' => false,
                                    'message' => 'Failed to update supplier totals. Insufficient total purchases.'
                                ), 400);
                            }
                        } else {
                            // Adding to supplier
                            $result = $wpdb->query($wpdb->prepare(
                                "UPDATE {$wpdb->prefix}ims_suppliers 
                                 SET total_purchases = total_purchases + %f,
                                     updated_at = %s
                                 WHERE id = %d",
                                $total_difference,
                                current_time('mysql'),
                                $new_supplier_id
                            ));
                            
                            if ($result === false) {
                                $wpdb->query('ROLLBACK');
                                return new WP_REST_Response(array(
                                    'success' => false,
                                    'message' => 'Failed to update supplier totals'
                                ), 500);
                            }
                        }
                    }
                }
            }
        }
        
        // Update purchase order
        $update_data = array(
            'subtotal' => number_format($new_total, 2, '.', ''),
            'tax' => number_format(0, 2, '.', ''),
            'total' => number_format($new_total, 2, '.', ''),
            'updated_at' => current_time('mysql')
        );
        $update_formats = array('%f', '%f', '%f', '%s');
        
        if (!empty($data['supplierId'])) {
            $update_data['supplier_id'] = $new_supplier_id;
            $update_formats[] = '%d';
        }
        
        if (isset($data['expectedDelivery'])) {
            $update_data['expected_delivery'] = $data['expectedDelivery'] ?: null;
            $update_formats[] = '%s';
        }
        
        if (!empty($data['status'])) {
            $update_data['status'] = $data['status'];
            $update_formats[] = '%s';
        }
        
        if (isset($data['notes'])) {
            $update_data['notes'] = sanitize_textarea_field($data['notes']);
            $update_formats[] = '%s';
        }
        
        $result = $wpdb->update(
            "{$wpdb->prefix}ims_purchase_orders",
            $update_data,
            array('id' => $purchase_order_id),
            $update_formats,
            array('%d')
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update purchase order'
            ), 500);
        }
        
        $wpdb->query('COMMIT');
        
        // Get updated purchase order
        $updated_order = ims_get_purchase_order_by_id($purchase_order_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $updated_order,
            'message' => 'Purchase order updated successfully'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to update purchase order: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * DELETE /purchase-orders/:id
 * Delete a purchase order
 */
function ims_delete_purchase_order($request) {
    global $wpdb;
    
    try {
        $purchase_order_id = intval($request['id']);
        
        // Validate purchase order exists
        $purchase_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_purchase_orders WHERE id = %d",
            $purchase_order_id
        ));
        
        if (!$purchase_order) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Purchase order not found'
            ), 404);
        }
        
        // Only allow deletion for draft orders
        if ($purchase_order->status !== 'draft') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Only draft purchase orders can be deleted'
            ), 400);
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Delete purchase order items
        $delete_items = $wpdb->delete(
            "{$wpdb->prefix}ims_purchase_order_items",
            array('purchase_order_id' => $purchase_order_id),
            array('%d')
        );
        
        if ($delete_items === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to delete purchase order items'
            ), 500);
        }
        
        // Delete purchase order
        $delete_order = $wpdb->delete(
            "{$wpdb->prefix}ims_purchase_orders",
            array('id' => $purchase_order_id),
            array('%d')
        );
        
        if ($delete_order === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to delete purchase order'
            ), 500);
        }
        
        // FIXED: Update supplier total_purchases within transaction
        if ($purchase_order->supplier_id && floatval($purchase_order->total) > 0) {
            $update_supplier = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ims_suppliers 
                 SET total_purchases = total_purchases - %f,
                     updated_at = %s
                 WHERE id = %d AND total_purchases >= %f",
                $purchase_order->total,
                current_time('mysql'),
                $purchase_order->supplier_id,
                $purchase_order->total
            ));
            
            if ($update_supplier === false || $wpdb->rows_affected == 0) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Failed to update supplier totals. Insufficient total purchases.'
                ), 400);
            }
        }
        
        $wpdb->query('COMMIT');
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Purchase order deleted successfully'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to delete purchase order: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * PUT /purchase-orders/:id/receive
 * Mark purchase order as received with item details
 */
function ims_receive_purchase_order($request) {
    global $wpdb;
    
    try {
        $purchase_order_id = intval($request['id']);
        $data = $request->get_json_params();
        
        // Validate purchase order exists
        $purchase_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_purchase_orders WHERE id = %d",
            $purchase_order_id
        ));
        
        if (!$purchase_order) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Purchase order not found'
            ), 404);
        }
        
        // FIXED: Check if already received
        if ($purchase_order->status === 'received') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Purchase order is already marked as received'
            ), 400);
        }
        
        // Validate status
        if (!in_array($purchase_order->status, ['confirmed', 'sent', 'draft'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Purchase order must be confirmed or sent to receive items'
            ), 400);
        }
        
        // Validate items
        if (empty($data['items']) || !is_array($data['items'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Items data is required'
            ), 400);
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Get all order items to validate
        $order_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_purchase_order_items 
             WHERE purchase_order_id = %d",
            $purchase_order_id
        ));
        
        $order_items_map = array();
        foreach ($order_items as $item) {
            $order_items_map[$item->product_id] = $item;
        }
        
        $has_received_items = false;
        
        // Update purchase order items and product stock
        foreach ($data['items'] as $item) {
            if (empty($item['productId']) || !isset($item['quantityReceived'])) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Each item must have productId and quantityReceived'
                ), 400);
            }
            
            // Validate quantityReceived
            $quantity_received = floatval($item['quantityReceived']);
            if ($quantity_received < 0) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid quantityReceived for product ID: ' . $item['productId']
                ), 400);
            }
            
            $condition = isset($item['condition']) ? $item['condition'] : 'good';
            if (!in_array($condition, ['good', 'damaged'])) {
                $condition = 'good';
            }
            
            $product_id = intval($item['productId']);
            
            // Validate product exists in order
            if (!isset($order_items_map[$product_id])) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid product ID in items: ' . $product_id
                ), 400);
            }
            
            $original_item = $order_items_map[$product_id];
            $already_received = floatval($original_item->quantity_received);
            $remaining_quantity = floatval($original_item->quantity) - $already_received;
            
            // Check if quantity received exceeds remaining quantity
            if ($quantity_received > $remaining_quantity) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => sprintf(
                        'Received quantity (%.2f) exceeds remaining quantity (%.2f) for product ID: %d',
                        $quantity_received,
                        $remaining_quantity,
                        $product_id
                    )
                ), 400);
            }
            
            // Skip if no quantity to receive
            if ($quantity_received == 0) {
                continue;
            }
            
            $has_received_items = true;
            
            // Update purchase order item
            $new_total_received = $already_received + $quantity_received;
            $result = $wpdb->update(
                "{$wpdb->prefix}ims_purchase_order_items",
                array(
                    'quantity_received' => number_format($new_total_received, 2, '.', ''),
                    'item_condition' => $condition
                ),
                array(
                    'purchase_order_id' => $purchase_order_id,
                    'product_id' => $product_id
                ),
                array('%f', '%s'),
                array('%d', '%d')
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Failed to update purchase order items for product ID: ' . $product_id
                ), 500);
            }
            
            // Update product stock for good condition items only
            if ($condition === 'good' && $quantity_received > 0) {
                // Get current product stock with FOR UPDATE to prevent race conditions
                $current_product = $wpdb->get_row($wpdb->prepare(
                    "SELECT stock FROM {$wpdb->prefix}ims_products WHERE id = %d FOR UPDATE",
                    $product_id
                ));
                
                if (!$current_product) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Product not found for ID: ' . $product_id
                    ), 400);
                }
                
                $current_stock = floatval($current_product->stock);
                $new_stock = $current_stock + $quantity_received;
                
                // Prevent negative stock (shouldn't happen here as we're adding)
                if ($new_stock < 0) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Cannot update stock to negative value for product ID: ' . $product_id
                    ), 400);
                }
                
                // Update product stock
                $stock_result = $wpdb->update(
                    "{$wpdb->prefix}ims_products",
                    array(
                        'stock' => number_format($new_stock, 2, '.', ''),
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $product_id),
                    array('%f', '%s'),
                    array('%d')
                );
                
                if ($stock_result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Failed to update product stock for ID: ' . $product_id
                    ), 500);
                }
                
                // Record inventory movement
                $movement_result = $wpdb->insert(
                    "{$wpdb->prefix}ims_inventory_movements",
                    array(
                        'product_id' => $product_id,
                        'type' => 'purchase',
                        'quantity' => number_format($quantity_received, 2, '.', ''),
                        'balance_before' => number_format($current_stock, 2, '.', ''),
                        'balance_after' => number_format($new_stock, 2, '.', ''),
                        'reference' => $purchase_order->order_number,
                        'reason' => 'Purchase order received',
                        'condition' => $condition,
                        'created_at' => current_time('mysql')
                    ),
                    array('%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s')
                );
                
                if ($movement_result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Failed to record inventory movement for product ID: ' . $product_id
                    ), 500);
                }
            }
        }
        
        // Check if at least some items were received
        if (!$has_received_items) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No items were received. Quantity received must be greater than 0 for at least one item.'
            ), 400);
        }
        
        // Determine if order should be marked as partially received or fully received
        $all_items = $wpdb->get_results($wpdb->prepare(
            "SELECT quantity, quantity_received FROM {$wpdb->prefix}ims_purchase_order_items 
             WHERE purchase_order_id = %d",
            $purchase_order_id
        ));
        
        $is_fully_received = true;
        foreach ($all_items as $item) {
            if (floatval($item->quantity_received) < floatval($item->quantity)) {
                $is_fully_received = false;
                break;
            }
        }
        
        $new_status = $is_fully_received ? 'received' : 'partially_received';
        
        // Update purchase order status and notes
        $new_notes = $purchase_order->notes ?: '';
        if (isset($data['notes']) && !empty(trim($data['notes']))) {
            $new_notes .= ($new_notes ? "\n" : "") . sanitize_textarea_field($data['notes']);
        }
        
        $po_result = $wpdb->update(
            "{$wpdb->prefix}ims_purchase_orders",
            array(
                'status' => $new_status,
                'notes' => $new_notes,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $purchase_order_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        if ($po_result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update purchase order status'
            ), 500);
        }
        
        $wpdb->query('COMMIT');
        
        // Get updated purchase order
        $updated_order = ims_get_purchase_order_by_id($purchase_order_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $updated_order,
            'message' => $is_fully_received ? 
                'Purchase order marked as fully received and stock updated' :
                'Purchase order marked as partially received and stock updated'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to receive purchase order: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * Helper function to get purchase order by ID
 */
function ims_get_purchase_order_by_id($id) {
    global $wpdb;
    
    $order = $wpdb->get_row($wpdb->prepare("
        SELECT 
            po.*,
            s.name as supplier_name,
            u.display_name as created_by_name
        FROM {$wpdb->prefix}ims_purchase_orders po
        LEFT JOIN {$wpdb->prefix}ims_suppliers s ON po.supplier_id = s.id
        LEFT JOIN {$wpdb->prefix}users u ON po.created_by = u.ID
        WHERE po.id = %d
    ", $id));
    
    if (!$order) {
        return null;
    }
    
    $items = $wpdb->get_results($wpdb->prepare("
        SELECT 
            poi.*,
            p.name as product_name
        FROM {$wpdb->prefix}ims_purchase_order_items poi
        LEFT JOIN {$wpdb->prefix}ims_products p ON poi.product_id = p.id
        WHERE poi.purchase_order_id = %d
        ORDER BY poi.id
    ", $id));
    
    $formatted_items = array();
    foreach ($items as $item) {
        $formatted_items[] = array(
            'id' => intval($item->id),
            'productId' => intval($item->product_id),
            'productName' => $item->product_name ?: '',
            'quantity' => floatval($item->quantity),
            'unitPrice' => floatval($item->unit_price),
            'total' => floatval($item->total),
            'quantityReceived' => floatval($item->quantity_received),
            'itemCondition' => $item->item_condition
        );
    }
    
    return array(
        'id' => intval($order->id),
        'orderNumber' => $order->order_number,
        'supplierId' => $order->supplier_id ? intval($order->supplier_id) : null,
        'supplierName' => $order->supplier_name ?: '',
        'date' => $order->date,
        'expectedDelivery' => $order->expected_delivery,
        'items' => $formatted_items,
        'total' => floatval($order->total),
        'status' => $order->status,
        'notes' => $order->notes ?: '',
        'createdBy' => $order->created_by_name ?: 'System',
        'createdAt' => mysql_to_rfc3339($order->created_at),
        'updatedAt' => mysql_to_rfc3339($order->updated_at)
    );
}

/**
 * Helper function to generate purchase order number
 */
function ims_generate_purchase_order_number() {
    global $wpdb;
    
    $prefix = 'PO-';
    $year = date('Y');
    $month = date('m');
    
    // Get the last order number for this month
    $last_number = $wpdb->get_var($wpdb->prepare(
        "SELECT order_number 
         FROM {$wpdb->prefix}ims_purchase_orders 
         WHERE order_number LIKE %s 
         ORDER BY id DESC 
         LIMIT 1",
        $prefix . $year . $month . '%'
    ));
    
    $number = $last_number ? intval(substr($last_number, -3)) + 1 : 1;
    
    return $prefix . $year . $month . sprintf('%03d', $number);
}
