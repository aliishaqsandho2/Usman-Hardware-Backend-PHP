<?php
/**
 * IMS History API Endpoints
 * 
 * Handles detailed history reports for Products and Customers.
 */

// Register REST API routes
add_action('rest_api_init', function() {
    
    // GET /products/{id}/sales-history
    register_rest_route('ims/v1', '/products/(?P<id>\d+)/sales-history', array(
        'methods' => 'GET',
        'callback' => 'ims_get_product_sales_history',
        'permission_callback' => '__return_true', // Or check capabilities
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));

    // GET /customers/{id}/orders
    register_rest_route('ims/v1', '/customers/(?P<id>\d+)/orders', array(
        'methods' => 'GET',
        'callback' => 'ims_get_customer_order_history',
        'permission_callback' => '__return_true', // Or check capabilities
        'args' => array(
            'id' => array(
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            )
        )
    ));

    // GET /monthly-sales-overview
    register_rest_route('ims/v1', '/monthly-sales-overview', array(
        'methods' => 'GET',
        'callback' => 'ims_get_monthly_sales_overview',
        'permission_callback' => '__return_true', // Or check capabilities
        'args' => array(
            'year' => array(
                'required' => false,
                'validate_callback' => function($param) {
                    return is_numeric($param);
                }
            ),
            'month' => array(
                'required' => false,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param >= 1 && $param <= 12;
                }
            )
        )
    ));

});
/**
 * GET /products/{id}/sales-history
 * 
 * Retrieves complete sales history for a specific product
 * Handles outsourced products correctly and provides detailed profit analysis
 */
function ims_get_product_sales_history($request) {
    global $wpdb;
    
    $product_id = intval($request['id']);
    
    // Check if product exists
    $product_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ims_products WHERE id = %d",
        $product_id
    ));
    
    if (!$product_exists) {
        return new WP_Error(
            'product_not_found', 
            'Product not found', 
            array('status' => 404)
        );
    }
    
    // Query Sales Data with proper cost calculation
    $query = "
        SELECT 
            s.id as order_id,
            s.order_number,
            s.customer_id,
            c.name as customer_name,
            c.type as customer_type,
            s.date,
            s.time,
            si.quantity,
            si.unit_price,
            si.total,
            si.is_outsourced,
            si.outsourcing_cost_per_unit,
            si.outsourcing_supplier_id,
            p.cost_price as product_cost_price,
            s.status,
            s.payment_method,
            -- Calculate actual cost used (outsourced or regular)
            CASE 
                WHEN si.is_outsourced = 1 THEN 
                    COALESCE(si.outsourcing_cost_per_unit, COALESCE(si.cost_at_sale, p.cost_price))
                ELSE 
                    COALESCE(si.cost_at_sale, p.cost_price)
            END as actual_cost_per_unit,
            -- Calculate total cost
            CASE 
                WHEN si.is_outsourced = 1 THEN 
                    si.quantity * COALESCE(si.outsourcing_cost_per_unit, COALESCE(si.cost_at_sale, p.cost_price))
                ELSE 
                    si.quantity * COALESCE(si.cost_at_sale, p.cost_price)
            END as total_cost,
            -- Get profit from profit table or calculate it
            COALESCE(
                prof.profit, 
                (si.total - (
                    CASE 
                        WHEN si.is_outsourced = 1 THEN 
                            si.quantity * COALESCE(si.outsourcing_cost_per_unit, COALESCE(si.cost_at_sale, p.cost_price))
                        ELSE 
                            si.quantity * COALESCE(si.cost_at_sale, p.cost_price)
                    END
                ))
            ) as profit,
            -- Get supplier name if outsourced
            sup.name as outsourcing_supplier_name
        FROM {$wpdb->prefix}ims_sale_items si
        JOIN {$wpdb->prefix}ims_sales s ON si.sale_id = s.id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        LEFT JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id
        LEFT JOIN {$wpdb->prefix}ims_suppliers sup ON si.outsourcing_supplier_id = sup.id
        LEFT JOIN {$wpdb->prefix}ims_profit prof ON (
            prof.reference_id = s.id 
            AND prof.reference_type = 'sale' 
            AND prof.product_id = si.product_id
        )
        WHERE si.product_id = %d
        AND s.status != 'cancelled'
        ORDER BY s.date DESC, s.time DESC
    ";
    
    $sales = $wpdb->get_results($wpdb->prepare($query, $product_id));
    
    // Initialize summary statistics
    $summary = array(
        'totalSold' => 0,
        'totalRevenue' => 0,
        'totalCost' => 0,
        'totalProfit' => 0,
        'averageProfit' => 0,
        'profitMargin' => 0,
        'uniqueCustomers' => array(),
        'totalOrders' => count($sales),
        'avgQuantityPerOrder' => 0,
        'avgUnitPrice' => 0,
        'outsourcedSales' => 0,
        'regularSales' => 0,
        'negativeProfitSales' => 0,
        'positiveProfitSales' => 0
    );
    
    $formatted_sales = array();
    $total_unit_price = 0;
    
    foreach ($sales as $sale) {
        $quantity = floatval($sale->quantity);
        $total = floatval($sale->total);
        $profit = floatval($sale->profit);
        $total_cost = floatval($sale->total_cost);
        $unit_price = floatval($sale->unit_price);
        $actual_cost_per_unit = floatval($sale->actual_cost_per_unit);
        
        // Update summary totals
        $summary['totalSold'] += $quantity;
        $summary['totalRevenue'] += $total;
        $summary['totalCost'] += $total_cost;
        $summary['totalProfit'] += $profit;
        $total_unit_price += $unit_price;
        
        // Track unique customers
        if ($sale->customer_id) {
            $summary['uniqueCustomers'][$sale->customer_id] = true;
        }
        
        // Count sale types
        if ($sale->is_outsourced) {
            $summary['outsourcedSales']++;
        } else {
            $summary['regularSales']++;
        }
        
        // Track profit distribution
        if ($profit < 0) {
            $summary['negativeProfitSales']++;
        } else {
            $summary['positiveProfitSales']++;
        }
        
        // Calculate profit margin for this sale
        $profit_margin = $total > 0 ? round(($profit / $total) * 100, 2) : 0;
        
        // Format individual sale record
        $formatted_sales[] = array(
            'orderId' => intval($sale->order_id),
            'orderNumber' => $sale->order_number,
            'customerId' => $sale->customer_id ? intval($sale->customer_id) : null,
            'customerName' => $sale->customer_name ?: 'Walk-in Customer',
            'customerType' => $sale->customer_type,
            'date' => $sale->date,
            'time' => $sale->time,
            'quantity' => $quantity,
            'unitPrice' => $unit_price,
            'total' => $total,
            'status' => $sale->status,
            'paymentMethod' => $sale->payment_method,
            'isOutsourced' => (bool)$sale->is_outsourced,
            'outsourcingSupplierName' => $sale->outsourcing_supplier_name,
            'costPerUnit' => $actual_cost_per_unit,
            'totalCost' => $total_cost,
            'profit' => $profit,
            'profitMargin' => $profit_margin,
            'profitStatus' => $profit < 0 ? 'loss' : ($profit > 0 ? 'profit' : 'break_even')
        );
    }
    
    // Finalize summary calculations
    $summary['uniqueCustomers'] = count($summary['uniqueCustomers']);
    
    if ($summary['totalOrders'] > 0) {
        $summary['avgQuantityPerOrder'] = round($summary['totalSold'] / $summary['totalOrders'], 2);
        $summary['avgUnitPrice'] = round($total_unit_price / $summary['totalOrders'], 2);
        $summary['averageProfit'] = round($summary['totalProfit'] / $summary['totalOrders'], 2);
    }
    
    if ($summary['totalRevenue'] > 0) {
        $summary['profitMargin'] = round(($summary['totalProfit'] / $summary['totalRevenue']) * 100, 2);
    }
    
    // Format all numeric values to prevent floating point issues
    $summary['totalSold'] = floatval(number_format($summary['totalSold'], 2, '.', ''));
    $summary['totalRevenue'] = floatval(number_format($summary['totalRevenue'], 2, '.', ''));
    $summary['totalCost'] = floatval(number_format($summary['totalCost'], 2, '.', ''));
    $summary['totalProfit'] = floatval(number_format($summary['totalProfit'], 2, '.', ''));
    $summary['averageProfit'] = floatval(number_format($summary['averageProfit'], 2, '.', ''));
    
    // Get product info for context
    $product_info = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, sku, cost_price, price, stock, unit 
         FROM {$wpdb->prefix}ims_products 
         WHERE id = %d",
        $product_id
    ));
    
    return new WP_REST_Response(array(
        'success' => true,
        'product' => array(
            'id' => intval($product_info->id),
            'name' => $product_info->name,
            'sku' => $product_info->sku,
            'currentCostPrice' => floatval($product_info->cost_price),
            'currentSellingPrice' => floatval($product_info->price),
            'currentStock' => floatval($product_info->stock),
            'unit' => $product_info->unit
        ),
        'summary' => $summary,
        'sales' => $formatted_sales
    ), 200);
}

/**
 * GET /customers/{id}/orders
 */
function ims_get_customer_order_history($request) {
    global $wpdb;

    $customer_id = intval($request['id']);

    // Check Customer
    $customer_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ims_customers WHERE id = %d",
        $customer_id
    ));

    if (!$customer_exists) {
        return new WP_Error('customer_not_found', 'Customer not found', array('status' => 404));
    }

    // 1. Get Orders
    // We fetch all sales for this customer
    $orders_query = "
        SELECT * 
        FROM {$wpdb->prefix}ims_sales 
        WHERE customer_id = %d 
        ORDER BY date DESC
    ";
    $orders = $wpdb->get_results($wpdb->prepare($orders_query, $customer_id));

    $formatted_orders = array();
    $total_orders = 0;
    $total_spent = 0;
    $unique_products_map = array();

    // Prepare monthly spending map
    $monthly_spending_map = array();

    foreach ($orders as $order) {
        // Skip cancelled orders for stats? Usually yes, but history should show them.
        // Let's include them in the list but maybe exclude from 'totalSpent' stats if status is cancelled.
        // The prompt doesn't specify deeply, but typically "Total Spent" implies completed/valid sales.
        // However, standard sales history often lists everything.
        // I will count stats for everything EXCEPT 'cancelled'.

        $is_valid = ($order->status !== 'cancelled');

        if ($is_valid) {
            $total_orders++;
            $total_spent += floatval($order->total);

            // Monthly Spending
            $month_key = substr($order->date, 0, 7); // YYYY-MM
            if (!isset($monthly_spending_map[$month_key])) {
                $monthly_spending_map[$month_key] = array('amount' => 0, 'count' => 0);
            }
            $monthly_spending_map[$month_key]['amount'] += floatval($order->total);
            $monthly_spending_map[$month_key]['count']++;
        }

        // Fetch Items for this order
        // This N+1 query pattern is okay for single customer history (usually not thousands of orders per customer).
        // Optimization: Could fetch ALL items for ALL customer orders in one go if needed, but this is simpler for now.
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT si.*, p.name as product_name 
             FROM {$wpdb->prefix}ims_sale_items si
             JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
             WHERE si.sale_id = %d",
            $order->id
        ));

        $formatted_items = array();
        foreach ($items as $item) {
            $formatted_items[] = array(
                'productName' => $item->product_name,
                'quantity' => floatval($item->quantity),
                'unitPrice' => floatval($item->unit_price),
                'total' => floatval($item->total)
            );

            if ($is_valid) {
                if (!isset($unique_products_map[$item->product_id])) {
                    $unique_products_map[$item->product_id] = array(
                        'name' => $item->product_name,
                        'purchaseCount' => 0, // Number of times ordered (orders involved)
                        'totalQty' => 0,
                        'totalSpent' => 0
                    );
                }
                // We add to stats. Note: If a product appears twice in same order (rare), purchaseCount typically increments once per order? 
                // Or just count lines. I'll count lines/occurrences.
                $unique_products_map[$item->product_id]['purchaseCount']++;
                $unique_products_map[$item->product_id]['totalQty'] += floatval($item->quantity);
                $unique_products_map[$item->product_id]['totalSpent'] += floatval($item->total);
            }
        }

        $formatted_orders[] = array(
            'id' => intval($order->id),
            'orderNumber' => $order->order_number,
            'date' => $order->date,
            'items' => $formatted_items,
            'total' => floatval($order->total),
            'status' => $order->status,
            'paymentMethod' => $order->payment_method
        );
    }

    // 3. Format Monthly Spending
    $monthly_spending = array();
    foreach ($monthly_spending_map as $month => $data) {
        $monthly_spending[] = array(
            'month' => $month,
            'amount' => floatval(number_format($data['amount'], 2, '.', '')),
            'orderCount' => $data['count']
        );
    }
    // Sort months desc
    rsort($monthly_spending);

    // 4. Format Favorite Products
    // Convert map to array
    $favorite_products = array_values($unique_products_map);
    // Sort by totalQty desc
    usort($favorite_products, function($a, $b) {
        return $b['totalQty'] <=> $a['totalQty'];
    });
    // Limit to top 10? Request didn't say, but good practice. I'll leave unlimited or maybe top 20.
    // Let's return all, user can filter.

    // Summary
    $summary = array(
        'totalOrders' => $total_orders,
        'totalSpent' => floatval(number_format($total_spent, 2, '.', '')),
        'avgOrderValue' => $total_orders > 0 ? floatval(number_format($total_spent / $total_orders, 2, '.', '')) : 0,
        'uniqueProducts' => count($unique_products_map)
    );

    return new WP_REST_Response(array(
        'orders' => $formatted_orders,
        'summary' => $summary,
        'favoriteProducts' => $favorite_products,
        'monthlySpending' => $monthly_spending
    ), 200);
}

/**
 * GET /monthly-sales-overview
 * 
 * Retrieves monthly sales data from vw_monthly_sales_overview
 */
function ims_get_monthly_sales_overview($request) {
    global $wpdb;

    $year = $request->get_param('year');
    $month = $request->get_param('month');

    $query = "SELECT * FROM vw_monthly_sales_overview";
    $where = array();
    $params = array();

    if (!empty($year)) {
        $where[] = "year = %d";
        $params[] = intval($year);
    }

    if (!empty($month)) {
        $where[] = "month = %d";
        $params[] = intval($month);
    }

    if (!empty($where)) {
        $query .= " WHERE " . implode(' AND ', $where);
    }
    
    // Order by year DESC, month DESC
    $query .= " ORDER BY year DESC, month DESC";

    if (!empty($params)) {
        $results = $wpdb->get_results($wpdb->prepare($query, $params));
    } else {
        $results = $wpdb->get_results($query);
    }

    $formatted_results = array();

    foreach ($results as $row) {
        $formatted_results[] = array(
            'year' => intval($row->year),
            'month' => intval($row->month),
            'total_orders' => intval($row->total_orders),
            'unique_customers' => intval($row->unique_customers),
            'unique_products_sold' => intval($row->unique_products_sold),
            'total_items_sold' => floatval($row->total_items_sold), // Using float for decimal/number
            'total_subtotal' => $row->total_subtotal,
            'total_discount' => $row->total_discount,
            'total_tax' => $row->total_tax,
            'total_revenue' => $row->total_revenue,
            'avg_order_value' => $row->avg_order_value,
            'cash_orders' => floatval($row->cash_orders),
            'credit_orders' => floatval($row->credit_orders),
            'bank_transfer_orders' => floatval($row->bank_transfer_orders),
            'permanent_customer_revenue' => $row->permanent_customer_revenue,
            'semi_permanent_customer_revenue' => $row->semi_permanent_customer_revenue,
            'temporary_customer_revenue' => $row->temporary_customer_revenue
        );
    }

    return new WP_REST_Response($formatted_results, 200);
}

