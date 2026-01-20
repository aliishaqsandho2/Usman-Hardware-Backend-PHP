<?php
/**
 * IMS Inventory Logs API Endpoints
 */

add_action('rest_api_init', 'ims_register_inventory_logs_routes');

function ims_register_inventory_logs_routes() {
    register_rest_route('ims/v1', '/inventory-logs', array(
        'methods' => 'GET',
        'callback' => 'ims_get_inventory_logs',
        'permission_callback' => '__return_true',
        'args' => array(
            'product_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
            'page' => array(
                'default' => 1,
                'sanitize_callback' => 'absint'
            ),
            'limit' => array(
                'default' => 20,
                // 'sanitize_callback' => 'absint' // Removing strict absint to allow checking for -1 in callback manually or handle string
            )
        )
    ));
}

function ims_get_inventory_logs($request) {
    global $wpdb;
    
    $product_id = $request->get_param('productId');
    if (!$product_id) {
        $product_id = $request->get_param('product_id');
    }
    
    $page = intval($request->get_param('page')) ?: 1;
    $limit_param = $request->get_param('limit');
    $limit = ($limit_param == -1) ? 999999999 : (intval($limit_param) ?: 20); // Support -1 for all
    $offset = ($page - 1) * $limit;
    $sort_order = strtoupper($request->get_param('sortOrder')) === 'ASC' ? 'ASC' : 'DESC'; // Default to DESC (latest first)
    
    $where_conditions = array();
    $params = array();
    
    if ($product_id) {
        $where_conditions[] = "im.product_id = %d";
        $params[] = $product_id;
    }
    
    // Date filters
    $date_from = $request->get_param('dateFrom');
    $date_to = $request->get_param('dateTo');
    
    if ($date_from) {
        $where_conditions[] = "DATE(im.created_at) >= %s";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_conditions[] = "DATE(im.created_at) <= %s";
        $params[] = $date_to;
    }

    $type = $request->get_param('type');
    if ($type) {
        $where_conditions[] = "im.type = %s";
        $params[] = $type;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Main Query
    // We join Sales and POs to get extra context.
    // For Sales: try im.sale_id first. If NULL, try matching reference to order_number (only if type is sale)
    // For Purchases: match reference to order_number (only if type is purchase)
    
    $query = "
        SELECT 
            im.*,
            p.name as product_name,
            p.sku as product_sku,
            
            -- Sale Info
            COALESCE(s.order_number, CASE WHEN im.type = 'sale' THEN im.reference ELSE NULL END) as sale_order_number,
            c.name as customer_name,
            
            -- Purchase Info
            po.order_number as purchase_order_number,
            sup.name as supplier_name
            
        FROM {$wpdb->prefix}ims_inventory_movements im
        LEFT JOIN {$wpdb->prefix}ims_products p ON im.product_id = p.id
        
        -- Join Sales
        LEFT JOIN {$wpdb->prefix}ims_sales s ON (im.sale_id = s.id OR (im.sale_id IS NULL AND im.type = 'sale' AND im.reference = s.order_number))
        LEFT JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id
        
        -- Join Purchase Orders
        LEFT JOIN {$wpdb->prefix}ims_purchase_orders po ON (im.type = 'purchase' AND im.reference = po.order_number)
        LEFT JOIN {$wpdb->prefix}ims_suppliers sup ON po.supplier_id = sup.id
        
        $where_clause
        ORDER BY im.created_at $sort_order
        LIMIT %d OFFSET %d
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $logs = $wpdb->get_results($wpdb->prepare($query, $params));
    
    // Count Query
    $count_query = "
        SELECT COUNT(*)
        FROM {$wpdb->prefix}ims_inventory_movements im
        $where_clause
    ";
    
    $count_params = array_slice($params, 0, -2); // Remove limit and offset
    $total_items = $wpdb->get_var(!empty($count_params) ? $wpdb->prepare($count_query, $count_params) : $count_query);
    
    // Format response
    $formatted_logs = array();
    foreach ($logs as $log) {
        $formatted_logs[] = array(
            'id' => intval($log->id),
            'productId' => intval($log->product_id),
            'productName' => $log->product_name,
            'productSku' => $log->product_sku,
            'type' => $log->type,
            'quantity' => floatval($log->quantity),
            'balanceBefore' => floatval($log->balance_before),
            'balanceAfter' => floatval($log->balance_after),
            'reference' => $log->reference,
            'reason' => $log->reason,
            'condition' => $log->condition, // 'good' or 'damaged'
            'createdAt' => $log->created_at,
            
            // Detailed Context
            'sale' => $log->sale_order_number ? array(
                'orderNumber' => $log->sale_order_number,
                'customerName' => $log->customer_name
            ) : null,
            
            'purchase' => $log->purchase_order_number ? array(
                'orderNumber' => $log->purchase_order_number,
                'supplierName' => $log->supplier_name
            ) : null
        );
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'logs' => $formatted_logs,
            'pagination' => array(
                'currentPage' => $page,
                'totalPages' => $limit > 0 ? ceil($total_items / $limit) : 1,
                'totalItems' => intval($total_items),
                'itemsPerPage' => $limit
            )
        )
    ), 200);
}
