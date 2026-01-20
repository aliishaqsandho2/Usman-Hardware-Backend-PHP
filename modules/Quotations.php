<?php
// Quotations.php functionality here

// Quotations API Endpoints for WordPress

// Register API routes
add_action('rest_api_init', function () {
    // GET /quotations - List all quotations
    register_rest_route('ims/v1', '/quotations', array(
        'methods' => 'GET',
        'callback' => 'ims_get_quotations',
        'permission_callback' => '__return_true' // Open API
    ));

    // POST /quotations - Create new quotation
    register_rest_route('ims/v1', '/quotations', array(
        'methods' => 'POST',
        'callback' => 'ims_create_quotation',
        'permission_callback' => '__return_true'
    ));

    // GET /quotations/:id - Get single quotation
    register_rest_route('ims/v1', '/quotations/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_single_quotation',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));

    // PUT /quotations/:id - Update quotation
    register_rest_route('ims/v1', '/quotations/(?P<id>\d+)', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_quotation',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));

    // DELETE /quotations/:id - Delete quotation
    register_rest_route('ims/v1', '/quotations/(?P<id>\d+)', array(
        'methods' => 'DELETE',
        'callback' => 'ims_delete_quotation',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));

    // PUT /quotations/:id/send - Send quotation
    register_rest_route('ims/v1', '/quotations/(?P<id>\d+)/send', array(
        'methods' => 'PUT',
        'callback' => 'ims_send_quotation',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));

    // PUT /quotations/:id/status - Update quotation status (accept/reject)
    register_rest_route('ims/v1', '/quotations/(?P<id>\d+)/status', array(
        'methods' => 'PUT',
        'callback' => 'ims_update_quotation_status',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));

    // PUT /quotations/:id/convert-to-sale - Convert quotation to sale
    register_rest_route('ims/v1', '/quotations/(?P<id>\d+)/convert-to-sale', array(
        'methods' => 'PUT',
        'callback' => 'ims_convert_quotation_to_sale',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));
});

/**
 * GET /quotations
 * Retrieve quotations with filtering and pagination
 */
function ims_get_quotations($request) {
    global $wpdb;
    
    try {
        // Get parameters
        $page = max(1, intval($request->get_param('page') ?: 1));
        $limit = max(1, min(100, intval($request->get_param('limit') ?: 20)));
        $customer_id = $request->get_param('customerId');
        $status = $request->get_param('status');
        $quote_number = $request->get_param('quoteNumber');
        $date_from = $request->get_param('dateFrom');
        $date_to = $request->get_param('dateTo');
        
        $offset = ($page - 1) * $limit;
        
        // Build WHERE clause
        $where_conditions = array();
        $where_params = array();
        
        if ($customer_id) {
            $where_conditions[] = "q.customer_id = %d";
            $where_params[] = $customer_id;
        }
        
        if ($status && in_array($status, ['draft', 'sent', 'accepted', 'rejected', 'expired'])) {
            $where_conditions[] = "q.status = %s";
            $where_params[] = $status;
        }

        if ($quote_number) {
            $where_conditions[] = "q.quote_number LIKE %s";
            $where_params[] = '%' . $wpdb->esc_like($quote_number) . '%';
        }

        if ($date_from) {
            $where_conditions[] = "q.date >= %s";
            $where_params[] = $date_from;
        }

        if ($date_to) {
            $where_conditions[] = "q.date <= %s";
            $where_params[] = $date_to;
        }
        
        $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}ims_quotations q $where_clause";
        $total_items = $wpdb->get_var(!empty($where_params) ? 
            $wpdb->prepare($count_query, $where_params) : $count_query);
        
        // Get quotations
        $query = "
            SELECT 
                q.*,
                c.name as customer_name,
                u.display_name as created_by_name
            FROM {$wpdb->prefix}ims_quotations q
            LEFT JOIN {$wpdb->prefix}ims_customers c ON q.customer_id = c.id
            LEFT JOIN {$wpdb->prefix}users u ON q.created_by = u.ID
            $where_clause
            ORDER BY q.created_at DESC
            LIMIT %d OFFSET %d
        ";
        
        $query_params = array_merge($where_params, [$limit, $offset]);
        $quotations = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        // Get items for each quotation
        $formatted_quotations = array();
        foreach ($quotations as $quotation) {
            $items_query = "
                SELECT 
                    qi.*,
                    p.name as product_name
                FROM {$wpdb->prefix}ims_quotation_items qi
                LEFT JOIN {$wpdb->prefix}ims_products p ON qi.product_id = p.id
                WHERE qi.quotation_id = %d
                ORDER BY qi.id
            ";
            
            $items = $wpdb->get_results($wpdb->prepare($items_query, $quotation->id));
            
            $formatted_items = array();
            foreach ($items as $item) {
                $formatted_items[] = array(
                    'id' => intval($item->id),
                    'productId' => intval($item->product_id),
                    'productName' => $item->product_name,
                    'quantity' => intval($item->quantity),
                    'unitPrice' => floatval($item->unit_price),
                    'total' => floatval($item->total)
                );
            }
            
            $formatted_quotations[] = array(
                'id' => intval($quotation->id),
                'quoteNumber' => $quotation->quote_number,
                'customerId' => intval($quotation->customer_id),
                'customerName' => $quotation->customer_name,
                'date' => $quotation->date,
                'validUntil' => $quotation->valid_until,
                'items' => $formatted_items,
                'subtotal' => floatval($quotation->subtotal),
                'discount' => floatval($quotation->discount),
                'total' => floatval($quotation->total),
                'status' => $quotation->status,
                'notes' => $quotation->notes,
                'createdBy' => $quotation->created_by_name ?: 'System',
                'createdAt' => $quotation->created_at
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'quotations' => $formatted_quotations,
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
            'message' => 'Failed to retrieve quotations: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * GET /quotations/:id
 * Retrieve a single quotation
 */
function ims_get_single_quotation($request) {
    try {
        $quotation = ims_get_quotation_by_id(intval($request['id']));
        
        if (!$quotation) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation not found'
            ), 404);
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $quotation
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to retrieve quotation: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * POST /quotations
 * Create a new quotation
 */
function ims_create_quotation($request) {
    global $wpdb;
    
    try {
        $data = $request->get_json_params();
        
        // Validate required fields
        if (empty($data['customerId']) || empty($data['items']) || !is_array($data['items'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Missing required fields: customerId and items are required'
            ), 400);
        }
        
        // Validate customer exists
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_customers WHERE id = %d AND status = 'active'",
            $data['customerId']
        ));
        
        if (!$customer) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid or inactive customer'
            ), 400);
        }
        
        // Validate validUntil date
        $valid_until = $data['validUntil'] ?? date('Y-m-d', strtotime('+30 days'));
        if (strtotime($valid_until) <= strtotime(date('Y-m-d'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Valid until date must be in the future'
            ), 400);
        }
        
        // Generate quote number
        $quote_number = ims_generate_quote_number();
        
        // Calculate totals
        $subtotal = 0;
        $validated_items = array();
        
        foreach ($data['items'] as $item) {
            if (empty($item['productId']) || empty($item['quantity']) || empty($item['unitPrice'])) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Each item must have productId, quantity, and unitPrice'
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
            $subtotal += $item_total;
            
            $validated_items[] = array(
                'product_id' => intval($item['productId']),
                'quantity' => intval($item['quantity']),
                'unit_price' => floatval($item['unitPrice']),
                'total' => $item_total
            );
        }
        
        // Apply discount
        $discount = floatval($data['discount'] ?? 0);
        $total = $subtotal - $discount;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Insert quotation
        $result = $wpdb->insert(
            $wpdb->prefix . 'ims_quotations',
            array(
                'quote_number' => $quote_number,
                'customer_id' => $data['customerId'],
                'date' => current_time('Y-m-d'),
                'valid_until' => $valid_until,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total' => $total,
                'status' => 'draft',
                'notes' => $data['notes'] ?? '',
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to create quotation'
            ), 500);
        }
        
        $quotation_id = $wpdb->insert_id;
        
        // Insert quotation items
        foreach ($validated_items as $item) {
            $result = $wpdb->insert(
                $wpdb->prefix . 'ims_quotation_items',
                array(
                    'quotation_id' => $quotation_id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total']
                ),
                array('%d', '%d', '%d', '%f', '%f')
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Failed to create quotation items'
                ), 500);
            }
        }
        
        $wpdb->query('COMMIT');
        
        // Get the created quotation
        $created_quotation = ims_get_quotation_by_id($quotation_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $created_quotation,
            'message' => 'Quotation created successfully'
        ), 201);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to create quotation: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * PUT /quotations/:id
 * Update an existing quotation
 */
function ims_update_quotation($request) {
    global $wpdb;
    
    try {
        $quotation_id = intval($request['id']);
        $data = $request->get_json_params();
        
        // Check if quotation exists
        $quotation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_quotations WHERE id = %d",
            $quotation_id
        ));
        
        if (!$quotation) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation not found'
            ), 404);
        }
        
        // Only draft quotations can be updated
        if ($quotation->status !== 'draft') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Only draft quotations can be updated'
            ), 400);
        }
        
        // Validate customer if provided
        if (isset($data['customerId'])) {
            $customer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ims_customers WHERE id = %d AND status = 'active'",
                $data['customerId']
            ));
            
            if (!$customer) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Invalid or inactive customer'
                ), 400);
            }
        }
        
        // Validate validUntil date if provided
        if (isset($data['validUntil']) && strtotime($data['validUntil']) <= strtotime(date('Y-m-d'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Valid until date must be in the future'
            ), 400);
        }
        
        // Calculate totals if items are provided
        $validated_items = array();
        $subtotal = $quotation->subtotal;
        $total = $quotation->total;
        $discount = $quotation->discount;
        
        if (isset($data['items']) && is_array($data['items'])) {
            $subtotal = 0;
            foreach ($data['items'] as $item) {
                if (empty($item['productId']) || empty($item['quantity']) || empty($item['unitPrice'])) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Each item must have productId, quantity, and unitPrice'
                    ), 400);
                }
                
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
                $subtotal += $item_total;
                
                $validated_items[] = array(
                    'product_id' => intval($item['productId']),
                    'quantity' => intval($item['quantity']),
                    'unit_price' => floatval($item['unitPrice']),
                    'total' => $item_total
                );
            }
            
            $discount = floatval($data['discount'] ?? $quotation->discount);
            $total = $subtotal - $discount;
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Update quotation
        $update_data = array(
            'updated_at' => current_time('mysql')
        );
        
        if (isset($data['customerId'])) {
            $update_data['customer_id'] = $data['customerId'];
        }
        if (isset($data['validUntil'])) {
            $update_data['valid_until'] = $data['validUntil'];
        }
        if (isset($data['notes'])) {
            $update_data['notes'] = $data['notes'];
        }
        if (isset($data['items'])) {
            $update_data['subtotal'] = $subtotal;
            $update_data['discount'] = $discount;
            $update_data['total'] = $total;
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ims_quotations',
            $update_data,
            array('id' => $quotation_id),
            array_fill(0, count($update_data), '%s'),
            array('%d')
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update quotation'
            ), 500);
        }
        
        // Update items if provided
        if (!empty($validated_items)) {
            // Delete existing items
            $wpdb->delete(
                $wpdb->prefix . 'ims_quotation_items',
                array('quotation_id' => $quotation_id),
                array('%d')
            );
            
            // Insert new items
            foreach ($validated_items as $item) {
                $result = $wpdb->insert(
                    $wpdb->prefix . 'ims_quotation_items',
                    array(
                        'quotation_id' => $quotation_id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total' => $item['total']
                    ),
                    array('%d', '%d', '%d', '%f', '%f')
                );
                
                if ($result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'Failed to update quotation items'
                    ), 500);
                }
            }
        }
        
        $wpdb->query('COMMIT');
        
        // Get updated quotation
        $updated_quotation = ims_get_quotation_by_id($quotation_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $updated_quotation,
            'message' => 'Quotation updated successfully'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to update quotation: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * DELETE /quotations/:id
 * Delete a quotation
 */
function ims_delete_quotation($request) {
    global $wpdb;
    
    try {
        $quotation_id = intval($request['id']);
        
        // Check if quotation exists
        $quotation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_quotations WHERE id = %d",
            $quotation_id
        ));
        
        if (!$quotation) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation not found'
            ), 404);
        }
        
        // Only draft quotations can be deleted
        if ($quotation->status !== 'draft') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Only draft quotations can be deleted'
            ), 400);
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Delete quotation items (CASCADE will handle this, but explicit for clarity)
        $wpdb->delete(
            $wpdb->prefix . 'ims_quotation_items',
            array('quotation_id' => $quotation_id),
            array('%d')
        );
        
        // Delete quotation
        $result = $wpdb->delete(
            $wpdb->prefix . 'ims_quotations',
            array('id' => $quotation_id),
            array('%d')
        );
        
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to delete quotation'
            ), 500);
        }
        
        $wpdb->query('COMMIT');
        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'Quotation deleted successfully'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to delete quotation: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * PUT /quotations/:id/send
 * Send a quotation (change status to 'sent')
 */
function ims_send_quotation($request) {
    global $wpdb;
    
    try {
        $quotation_id = intval($request['id']);
        
        // Check if quotation exists
        $quotation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_quotations WHERE id = %d",
            $quotation_id
        ));
        
        if (!$quotation) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation not found'
            ), 404);
        }
        
        // Only draft quotations can be sent
        if ($quotation->status !== 'draft') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Only draft quotations can be sent'
            ), 400);
        }
        
        // Update status to sent
        $result = $wpdb->update(
            $wpdb->prefix . 'ims_quotations',
            array(
                'status' => 'sent',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $quotation_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to send quotation'
            ), 500);
        }
        
        // Get updated quotation
        $updated_quotation = ims_get_quotation_by_id($quotation_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $updated_quotation,
            'message' => 'Quotation sent successfully'
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to send quotation: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * PUT /quotations/:id/status
 * Update quotation status (accept/reject)
 */
function ims_update_quotation_status($request) {
    global $wpdb;
    
    try {
        $quotation_id = intval($request['id']);
        $data = $request->get_json_params();
        
        // Validate status
        if (empty($data['status']) || !in_array($data['status'], ['accepted', 'rejected'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid status. Must be "accepted" or "rejected"'
            ), 400);
        }
        
        // Check if quotation exists
        $quotation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_quotations WHERE id = %d",
            $quotation_id
        ));
        
        if (!$quotation) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation not found'
            ), 404);
        }
        
        // Only sent quotations can be accepted/rejected
        if ($quotation->status !== 'sent') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Only sent quotations can be accepted or rejected'
            ), 400);
        }
        
        // Check if quotation is still valid
        if (strtotime($quotation->valid_until) < strtotime(date('Y-m-d'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation has expired'
            ), 400);
        }
        
        // Update status
        $result = $wpdb->update(
            $wpdb->prefix . 'ims_quotations',
            array(
                'status' => $data['status'],
                'updated_at' => current_time('mysql')
            ),
            array('id' => $quotation_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update quotation status'
            ), 500);
        }
        
        // Get updated quotation
        $updated_quotation = ims_get_quotation_by_id($quotation_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => $updated_quotation,
            'message' => 'Quotation status updated successfully'
        ), 200);
        
    } catch (Exception $e) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to update quotation status: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * PUT /quotations/:id/convert-to-sale
 * Convert quotation to sale
 */
function ims_convert_quotation_to_sale($request) {
    global $wpdb;
    
    try {
        $quotation_id = intval($request['id']);
        
        // Get quotation details
        $quotation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_quotations WHERE id = %d",
            $quotation_id
        ));
        
        if (!$quotation) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation not found'
            ), 404);
        }
        
        // Check if quotation can be converted
        if ($quotation->status !== 'sent') {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Only sent quotations can be converted to sales'
            ), 400);
        }
        
        // Check if quotation is still valid
        if (strtotime($quotation->valid_until) < strtotime(date('Y-m-d'))) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Quotation has expired and cannot be converted'
            ), 400);
        }
        
        // Get quotation items
        $quotation_items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ims_quotation_items WHERE quotation_id = %d",
            $quotation_id
        ));
        
        if (empty($quotation_items)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'No items found in quotation'
            ), 400);
        }
        
        // Check stock availability
        foreach ($quotation_items as $item) {
            $product = $wpdb->get_row($wpdb->prepare(
                "SELECT stock, name FROM {$wpdb->prefix}ims_products WHERE id = %d",
                $item->product_id
            ));
            
            if (!$product || $product->stock < $item->quantity) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => "Insufficient stock for product: {$product->name}. Available: {$product->stock}, Required: {$item->quantity}"
                ), 400);
            }
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Generate order number for sale
        $order_number = ims_generate_order_number();
        
        // Create sale record
        $sale_result = $wpdb->insert(
            $wpdb->prefix . 'ims_sales',
            array(
                'order_number' => $order_number,
                'customer_id' => $quotation->customer_id,
                'date' => current_time('Y-m-d'),
                'time' => current_time('H:i:s'),
                'subtotal' => $quotation->subtotal,
                'discount' => $quotation->discount,
                'total' => $quotation->total,
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'payment_method' => 'credit',
                'status' => 'pending',
                'notes' => "Converted from quotation: {$quotation->quote_number}",
                'created_by' => get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );
        
        if ($sale_result === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to create sale record'
            ), 500);
        }
        
        $sale_id = $wpdb->insert_id;
        
        // Create sale items and update stock
        foreach ($quotation_items as $item) {
            // Insert sale item
            $item_result = $wpdb->insert(
                $wpdb->prefix . 'ims_sale_items',
                array(
                    'sale_id' => $sale_id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total' => $item->total
                ),
                array('%d', '%d', '%d', '%f', '%f')
            );
            
            if ($item_result === false) {
                $wpdb->query('ROLLBACK');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Failed to create sale items'
                ), 500);
            }
            
            // Get current stock
            $current_stock = $wpdb->get_var($wpdb->prepare(
                "SELECT stock FROM {$wpdb->prefix}ims_products WHERE id = %d",
                $item->product_id
            ));
            
            $new_stock = $current_stock - $item->quantity;
            
            // Update product stock
            $wpdb->update(
                $wpdb->prefix . 'ims_products',
                array('stock' => $new_stock, 'updated_at' => current_time('mysql')),
                array('id' => $item->product_id),
                array('%d', '%s'),
                array('%d')
            );
            
            // Record inventory movement
            $wpdb->insert(
                $wpdb->prefix . 'ims_inventory_movements',
                array(
                    'product_id' => $item->product_id,
                    'type' => 'sale',
                    'quantity' => -$item->quantity,
                    'balance_before' => $current_stock,
                    'balance_after' => $new_stock,
                    'reference' => $order_number,
                    'reason' => 'Sale from quotation conversion',
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s')
            );
        }
        
        // Update quotation status to accepted
        $quotation_update = $wpdb->update(
            $wpdb->prefix . 'ims_quotations',
            array(
                'status' => 'accepted',
                'updated_at' => current_time('mysql')
            ),
            array('id' => $quotation_id),
            array('%s', '%s'),
            array('%d')
        );
        
        if ($quotation_update === false) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to update quotation status'
            ), 500);
        }
        
        // Update customer balance
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}ims_customers 
            SET current_balance = current_balance + %f,
                total_purchases = total_purchases + %f,
                updated_at = %s
            WHERE id = %d
        ", $quotation->total, $quotation->total, current_time('mysql'), $quotation->customer_id));
        
        $wpdb->query('COMMIT');
        
        // Get created sale and updated quotation
        $created_sale = ims_get_sale_by_id($sale_id);
        $updated_quotation = ims_get_quotation_by_id($quotation_id);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'sale' => $created_sale,
                'quotation' => $updated_quotation
            ),
            'message' => 'Quotation converted to sale successfully'
        ), 200);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to convert quotation to sale: ' . $e->getMessage()
        ), 500);
    }
}

/**
 * Helper function to get quotation by ID
 */
function ims_get_quotation_by_id($id) {
    global $wpdb;
    
    $quotation = $wpdb->get_row($wpdb->prepare("
        SELECT 
            q.*,
            c.name as customer_name,
            u.display_name as created_by_name
        FROM {$wpdb->prefix}ims_quotations q
        LEFT JOIN {$wpdb->prefix}ims_customers c ON q.customer_id = c.id
        LEFT JOIN {$wpdb->prefix}users u ON q.created_by = u.ID
        WHERE q.id = %d
    ", $id));
    
    if (!$quotation) return null;
    
    $items = $wpdb->get_results($wpdb->prepare("
        SELECT 
            qi.*,
            p.name as product_name
        FROM {$wpdb->prefix}ims_quotation_items qi
        LEFT JOIN {$wpdb->prefix}ims_products p ON qi.product_id = p.id
        WHERE qi.quotation_id = %d
    ", $id));
    
    $formatted_items = array();
    foreach ($items as $item) {
        $formatted_items[] = array(
            'id' => intval($item->id),
            'productId' => intval($item->product_id),
            'productName' => $item->product_name,
            'quantity' => intval($item->quantity),
            'unitPrice' => floatval($item->unit_price),
            'total' => floatval($item->total)
        );
    }
    
    return array(
        'id' => intval($quotation->id),
        'quoteNumber' => $quotation->quote_number,
        'customerId' => intval($quotation->customer_id),
        'customerName' => $quotation->customer_name,
        'date' => $quotation->date,
        'validUntil' => $quotation->valid_until,
        'items' => $formatted_items,
        'subtotal' => floatval($quotation->subtotal),
        'discount' => floatval($quotation->discount),
        'total' => floatval($quotation->total),
        'status' => $quotation->status,
        'notes' => $quotation->notes,
        'createdBy' => $quotation->created_by_name ?: 'System',
        'createdAt' => $quotation->created_at
    );
}

/**
 * Helper function to generate quote number
 */
function ims_generate_quote_number() {
    global $wpdb;
    
    $prefix = 'QUO-';
    $year = date('Y');
    $month = date('m');
    
    $last_number = $wpdb->get_var($wpdb->prepare("
        SELECT quote_number 
        FROM {$wpdb->prefix}ims_quotations 
        WHERE quote_number LIKE %s 
        ORDER BY id DESC 
        LIMIT 1
    ", $prefix . $year . $month . '%'));
    
    if ($last_number) {
        $number = intval(substr($last_number, -3)) + 1;
    } else {
        $number = 1;
    }
    
    return $prefix . $year . $month . sprintf('%03d', $number);
}

