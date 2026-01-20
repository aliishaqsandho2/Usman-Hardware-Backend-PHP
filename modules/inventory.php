<?php
// Sales Analytics.php functionality here

/**
 * IMS Reports API Endpoints for WordPress
 * Add these functions to your theme's functions.php file
 */

// Register REST API routes
add_action('rest_api_init', 'ims_register_reports_api_routes');

function ims_register_reports_api_routes() {
    // Sales Report Endpoint
    register_rest_route('ims/v1', '/reports/sales', array(
        'methods' => 'GET',
        'callback' => 'ims_get_sales_report',
        'args' => array(
            'period' => array(
                'default' => 'daily',
                'enum' => array('daily', 'weekly', 'monthly', 'yearly'),
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'dateFrom' => array(
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'ims_validate_date'
            ),
            'dateTo' => array(
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'ims_validate_date'
            ),
            'groupBy' => array(
                'default' => 'date',
                'enum' => array('date', 'product', 'customer', 'category'),
                'sanitize_callback' => 'sanitize_text_field'
            )
        )
    ));
	// GET /wp-json/ims/v1/inventory
    register_rest_route('ims/v1', '/inventory', array(
        'methods' => 'GET',
        'callback' => 'ims_get_inventory',
        'permission_callback' => '__return_true' // No authentication required
    ));

    // Inventory Report Endpoint
    register_rest_route('ims/v1', '/reports/inventory', array(
        'methods' => 'GET',
        'callback' => 'ims_get_inventory_report',
    ));

    // Financial Report Endpoint
    register_rest_route('ims/v1', '/reports/financial', array(
        'methods' => 'GET',
        'callback' => 'ims_get_financial_report',
        'args' => array(
            'period' => array(
                'default' => 'monthly',
                'enum' => array('monthly', 'quarterly', 'yearly'),
                'sanitize_callback' => 'sanitize_text_field'
            ),
            'year' => array(
                'default' => date('Y'),
                'sanitize_callback' => 'absint'
            )
        )
    ));
}


/**
 * GET /inventory - Get inventory list with filtering and pagination
 */
function ims_get_inventory($request) {
    global $wpdb;
    
    try {
        // Get query parameters
        $page = intval($request->get_param('page')) ?: 1;
        $limit = intval($request->get_param('limit')) ?: 20;
        $category = sanitize_text_field($request->get_param('category'));
        $low_stock = $request->get_param('lowStock') === 'true';
        $out_of_stock = $request->get_param('outOfStock') === 'true';
        
        $offset = ($page - 1) * $limit;
        
        // Build base query
        $sql = "
            SELECT 
                p.id as productId,
                p.name as productName,
                p.sku,
                c.name as category,
                p.stock as currentStock,
                p.min_stock as minStock,
                p.max_stock as maxStock,
                p.unit,
                (p.stock * p.cost_price) as value,
                p.updated_at as lastRestocked,
                CASE 
                    WHEN p.stock = 0 THEN 'out'
                    WHEN p.stock <= p.min_stock THEN 'low'
                    ELSE 'adequate'
                END as stockStatus
            FROM {$wpdb->prefix}ims_products p
            LEFT JOIN {$wpdb->prefix}ims_categories c ON p.category_id = c.id
            WHERE p.status = 'active'
        ";
        
        $where_conditions = array();
        $params = array();
        
        // Add category filter
        if ($category) {
            $where_conditions[] = "c.name = %s";
            $params[] = $category;
        }
        
        // Add stock filters
        if ($low_stock) {
            $where_conditions[] = "p.stock <= p.min_stock AND p.stock > 0";
        }
        
        if ($out_of_stock) {
            $where_conditions[] = "p.stock = 0";
        }
        
        // Apply WHERE conditions
        if (!empty($where_conditions)) {
            $sql .= " AND " . implode(' AND ', $where_conditions);
        }
        
        // Add ordering and pagination
        $sql .= " ORDER BY p.name ASC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;
        
        // Execute query
        if (!empty($params)) {
            $prepared_sql = $wpdb->prepare($sql, $params);
        } else {
            $prepared_sql = $sql;
        }
        
        $inventory = $wpdb->get_results($prepared_sql);
        
        // Get summary data
        $summary_sql = "
            SELECT 
                COUNT(*) as totalProducts,
                SUM(p.stock * p.cost_price) as totalValue,
                SUM(CASE WHEN p.stock <= p.min_stock AND p.stock > 0 THEN 1 ELSE 0 END) as lowStockItems,
                SUM(CASE WHEN p.stock = 0 THEN 1 ELSE 0 END) as outOfStockItems
            FROM {$wpdb->prefix}ims_products p
            WHERE p.status = 'active'
        ";
        
        $summary = $wpdb->get_row($summary_sql);
        
        // Format response
        $formatted_inventory = array();
        foreach ($inventory as $item) {
            $formatted_inventory[] = array(
                'productId' => intval($item->productId),
                'productName' => $item->productName,
                'sku' => $item->sku,
                'category' => $item->category,
                'currentStock' => floatval($item->currentStock),
                'minStock' => floatval($item->minStock),
                'maxStock' => floatval($item->maxStock),
                'unit' => $item->unit,
                'value' => floatval($item->value),
                'lastRestocked' => date('Y-m-d', strtotime($item->lastRestocked)),
                'stockStatus' => $item->stockStatus
            );
        }
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'inventory' => $formatted_inventory,
                'summary' => array(
                    'totalProducts' => intval($summary->totalProducts),
                    'totalValue' => floatval($summary->totalValue),
                    'lowStockItems' => intval($summary->lowStockItems),
                    'outOfStockItems' => intval($summary->outOfStockItems)
                )
            )
        ), 200);
        
    } catch (Exception $e) {
        return new WP_Error('database_error', 'Database error occurred', array('status' => 500));
    }
}

/**
 * Validate date format
 */
function ims_validate_date($value, $request, $param) {
    if (empty($value)) {
        return true; // Allow empty dates
    }
    
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

/**
 * Sales Report API Endpoint
 */
function ims_get_sales_report($request) {
    global $wpdb;
    
    try {
        $period = $request->get_param('period');
        $date_from = $request->get_param('dateFrom');
        $date_to = $request->get_param('dateTo');
        $group_by = $request->get_param('groupBy');
        
        // Set default date range if not provided
        if (empty($date_from) || empty($date_to)) {
            $date_to = date('Y-m-d');
            switch ($period) {
                case 'weekly':
                    $date_from = date('Y-m-d', strtotime('-7 days'));
                    break;
                case 'monthly':
                    $date_from = date('Y-m-01');
                    break;
                case 'yearly':
                    $date_from = date('Y-01-01');
                    break;
                default:
                    $date_from = date('Y-m-d');
            }
        }
        
        // Build the main sales query based on groupBy parameter
        $sales_data = ims_get_sales_data_by_group($group_by, $date_from, $date_to, $period);
        
        // Get summary data
        $summary = ims_get_sales_summary($date_from, $date_to);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'salesReport' => $sales_data,
                'summary' => $summary
            )
        ), 200);
        
    } catch (Exception $e) {
        return new WP_Error('sales_report_error', $e->getMessage(), array('status' => 500));
    }
}

/**
 * Get sales data grouped by specified parameter
 */
function ims_get_sales_data_by_group($group_by, $date_from, $date_to, $period) {
    global $wpdb;
    
    $sales_table = $wpdb->prefix . 'ims_sales';
    $items_table = $wpdb->prefix . 'ims_sale_items';
    $products_table = $wpdb->prefix . 'ims_products';
    $customers_table = $wpdb->prefix . 'ims_customers';
    $categories_table = $wpdb->prefix . 'ims_categories';
    
    switch ($group_by) {
        case 'product':
            $query = $wpdb->prepare("
                SELECT 
                    p.name as period,
                    SUM(s.total) as totalSales,
                    COUNT(DISTINCT s.id) as totalOrders,
                    ROUND(SUM(s.total) / COUNT(DISTINCT s.id), 2) as avgOrderValue,
                    SUM(si.quantity) as totalQuantity
                FROM {$sales_table} s
                JOIN {$items_table} si ON s.id = si.sale_id
                JOIN {$products_table} p ON si.product_id = p.id
                WHERE s.date BETWEEN %s AND %s 
                AND s.status = 'completed'
                GROUP BY p.id, p.name
                ORDER BY totalSales DESC
            ", $date_from, $date_to);
            break;
            
        case 'customer':
            $query = $wpdb->prepare("
                SELECT 
                    c.name as period,
                    SUM(s.total) as totalSales,
                    COUNT(s.id) as totalOrders,
                    ROUND(SUM(s.total) / COUNT(s.id), 2) as avgOrderValue,
                    c.type as customerType
                FROM {$sales_table} s
                JOIN {$customers_table} c ON s.customer_id = c.id
                WHERE s.date BETWEEN %s AND %s 
                AND s.status = 'completed'
                GROUP BY c.id, c.name
                ORDER BY totalSales DESC
            ", $date_from, $date_to);
            break;
            
        case 'category':
            $query = $wpdb->prepare("
                SELECT 
                    cat.name as period,
                    SUM(s.total) as totalSales,
                    COUNT(DISTINCT s.id) as totalOrders,
                    ROUND(SUM(s.total) / COUNT(DISTINCT s.id), 2) as avgOrderValue,
                    SUM(si.quantity) as totalQuantity
                FROM {$sales_table} s
                JOIN {$items_table} si ON s.id = si.sale_id
                JOIN {$products_table} p ON si.product_id = p.id
                JOIN {$categories_table} cat ON p.category_id = cat.id
                WHERE s.date BETWEEN %s AND %s 
                AND s.status = 'completed'
                GROUP BY cat.id, cat.name
                ORDER BY totalSales DESC
            ", $date_from, $date_to);
            break;
            
        default: // date
            $date_format = ims_get_date_format_by_period($period);
            $query = $wpdb->prepare("
                SELECT 
                    DATE_FORMAT(s.date, '{$date_format}') as period,
                    SUM(s.total) as totalSales,
                    COUNT(s.id) as totalOrders,
                    ROUND(SUM(s.total) / COUNT(s.id), 2) as avgOrderValue,
                    (SELECT p.name FROM {$items_table} si 
                     JOIN {$products_table} p ON si.product_id = p.id 
                     WHERE si.sale_id IN (SELECT id FROM {$sales_table} WHERE DATE_FORMAT(date, '{$date_format}') = DATE_FORMAT(s.date, '{$date_format}'))
                     GROUP BY p.id ORDER BY SUM(si.quantity) DESC LIMIT 1) as topProduct
                FROM {$sales_table} s
                WHERE s.date BETWEEN %s AND %s 
                AND s.status = 'completed'
                GROUP BY DATE_FORMAT(s.date, '{$date_format}')
                ORDER BY s.date DESC
            ", $date_from, $date_to);
    }
    
    return $wpdb->get_results($query, ARRAY_A);
}

/**
 * Get date format based on period
 */
function ims_get_date_format_by_period($period) {
    switch ($period) {
        case 'yearly':
            return '%Y';
        case 'monthly':
            return '%Y-%m';
        case 'weekly':
            return '%Y-%u';
        default:
            return '%Y-%m-%d';
    }
}

/**
 * Get sales summary data
 */
function ims_get_sales_summary($date_from, $date_to) {
    global $wpdb;
    
    $sales_table = $wpdb->prefix . 'ims_sales';
    
    // Current period summary
    $current_summary = $wpdb->get_row($wpdb->prepare("
        SELECT 
            SUM(total) as totalRevenue,
            COUNT(id) as totalOrders,
            ROUND(AVG(total), 2) as avgOrderValue
        FROM {$sales_table}
        WHERE date BETWEEN %s AND %s 
        AND status = 'completed'
    ", $date_from, $date_to), ARRAY_A);
    
    // Previous period for growth calculation
    $days_diff = (strtotime($date_to) - strtotime($date_from)) / (60 * 60 * 24);
    $prev_date_from = date('Y-m-d', strtotime($date_from . ' -' . ($days_diff + 1) . ' days'));
    $prev_date_to = date('Y-m-d', strtotime($date_from . ' -1 day'));
    
    $prev_summary = $wpdb->get_row($wpdb->prepare("
        SELECT SUM(total) as totalRevenue
        FROM {$sales_table}
        WHERE date BETWEEN %s AND %s 
        AND status = 'completed'
    ", $prev_date_from, $prev_date_to), ARRAY_A);
    
    // Calculate growth
    $growth = 0;
    if ($prev_summary['totalRevenue'] > 0) {
        $growth = round((($current_summary['totalRevenue'] - $prev_summary['totalRevenue']) / $prev_summary['totalRevenue']) * 100, 2);
    }
    
    return array(
        'totalRevenue' => floatval($current_summary['totalRevenue'] ?? 0),
        'totalOrders' => intval($current_summary['totalOrders'] ?? 0),
        'avgOrderValue' => floatval($current_summary['avgOrderValue'] ?? 0),
        'growth' => $growth
    );
}

/**
 * Inventory Report API Endpoint
 */
function ims_get_inventory_report($request) {
    global $wpdb;
    
    try {
        $products_table = $wpdb->prefix . 'ims_products';
        $items_table = $wpdb->prefix . 'ims_sale_items';
        $sales_table = $wpdb->prefix . 'ims_sales';
        
        // Get basic inventory stats
        $inventory_stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as totalProducts,
                SUM(stock * cost_price) as totalValue
            FROM {$products_table}
            WHERE status = 'active'
        ", ARRAY_A);
        
        // Get low stock items
        $low_stock_items = $wpdb->get_results("
            SELECT 
                id as productId,
                name as productName, 
                stock as currentStock,
                min_stock as minStock,
                (min_stock * 2) as reorderQuantity
            FROM {$products_table}
            WHERE stock <= min_stock 
            AND status = 'active'
            ORDER BY (stock - min_stock) ASC
        ", ARRAY_A);
        
        // Get fast moving items (last 30 days)
        $fast_moving_items = $wpdb->get_results("
            SELECT 
                p.id as productId,
                p.name as productName,
                SUM(si.quantity) as soldQuantity,
                SUM(si.total) as revenue
            FROM {$products_table} p
            JOIN {$items_table} si ON p.id = si.product_id
            JOIN {$sales_table} s ON si.sale_id = s.id
            WHERE s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND s.status = 'completed'
            GROUP BY p.id, p.name
            HAVING soldQuantity > 0
            ORDER BY soldQuantity DESC
            LIMIT 10
        ", ARRAY_A);
        
        // Get slow moving items (items with low sales in last 60 days)
        $slow_moving_items = $wpdb->get_results("
            SELECT 
                p.id as productId,
                p.name as productName,
                COALESCE(SUM(si.quantity), 0) as soldQuantity,
                COALESCE(SUM(si.total), 0) as revenue
            FROM {$products_table} p
            LEFT JOIN {$items_table} si ON p.id = si.product_id
            LEFT JOIN {$sales_table} s ON si.sale_id = s.id 
                AND s.date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                AND s.status = 'completed'
            WHERE p.status = 'active'
            GROUP BY p.id, p.name
            HAVING soldQuantity <= 2
            ORDER BY soldQuantity ASC
            LIMIT 10
        ", ARRAY_A);
        
        // Get dead stock (no sales in last 90 days and stock > 0)
        $dead_stock = $wpdb->get_results("
            SELECT 
                p.id as productId,
                p.name as productName,
                p.stock as currentStock,
                (p.stock * p.cost_price) as valueAtCost
            FROM {$products_table} p
            WHERE p.status = 'active' 
            AND p.stock > 0
            AND p.id NOT IN (
                SELECT DISTINCT si.product_id 
                FROM {$items_table} si
                JOIN {$sales_table} s ON si.sale_id = s.id
                WHERE s.date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                AND s.status = 'completed'
            )
            ORDER BY valueAtCost DESC
        ", ARRAY_A);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'inventoryReport' => array(
                    'totalProducts' => intval($inventory_stats['totalProducts']),
                    'totalValue' => floatval($inventory_stats['totalValue'] ?? 0),
                    'lowStockItems' => $low_stock_items,
                    'fastMovingItems' => $fast_moving_items,
                    'slowMovingItems' => $slow_moving_items,
                    'deadStock' => $dead_stock
                )
            )
        ), 200);
        
    } catch (Exception $e) {
        return new WP_Error('inventory_report_error', $e->getMessage(), array('status' => 500));
    }
}

/**
 * Financial Report API Endpoint
 */
function ims_get_financial_report($request) {
    global $wpdb;
    
    try {
        $period = $request->get_param('period');
        $year = $request->get_param('year');
        
        $sales_table = $wpdb->prefix . 'ims_sales';
        $expenses_table = $wpdb->prefix . 'ims_expenses';
        $purchases_table = $wpdb->prefix . 'ims_purchase_orders';
        $cash_flow_table = $wpdb->prefix . 'ims_cash_flow';
        
        // Get revenue breakdown
        $revenue_data = ims_get_revenue_breakdown($period, $year);
        
        // Get expenses breakdown
        $expenses_data = ims_get_expenses_breakdown($period, $year);
        
        // Calculate profit metrics
        $total_revenue = array_sum(array_column($revenue_data['breakdown'], 'amount'));
        $total_expenses = array_sum(array_column($expenses_data['breakdown'], 'amount'));
        
        // Get cost of goods sold (from purchase orders)
        $cogs = $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(total), 0)
            FROM {$purchases_table}
            WHERE YEAR(date) = %d 
            AND status IN ('received', 'confirmed')
        ", $year));
        
        $gross_profit = $total_revenue - floatval($cogs);
        $net_profit = $gross_profit - $total_expenses;
        $profit_margin = $total_revenue > 0 ? round(($net_profit / $total_revenue) * 100, 1) : 0;
        
        // Get cash flow data
        $cash_flow_data = ims_get_cash_flow_data($year);
        
        return new WP_REST_Response(array(
            'success' => true,
            'data' => array(
                'financialReport' => array(
                    'revenue' => $revenue_data,
                    'expenses' => $expenses_data,
                    'profit' => array(
                        'gross' => $gross_profit,
                        'net' => $net_profit,
                        'margin' => $profit_margin
                    ),
                    'cashFlow' => $cash_flow_data
                )
            )
        ), 200);
        
    } catch (Exception $e) {
        return new WP_Error('financial_report_error', $e->getMessage(), array('status' => 500));
    }
}

/**
 * Get revenue breakdown by period
 */
function ims_get_revenue_breakdown($period, $year) {
    global $wpdb;
    
    $sales_table = $wpdb->prefix . 'ims_sales';
    
    switch ($period) {
        case 'quarterly':
            $query = $wpdb->prepare("
                SELECT 
                    CONCAT('Q', QUARTER(date)) as period,
                    SUM(total) as amount
                FROM {$sales_table}
                WHERE YEAR(date) = %d 
                AND status = 'completed'
                GROUP BY QUARTER(date)
                ORDER BY QUARTER(date)
            ", $year);
            break;
            
        case 'yearly':
            $query = $wpdb->prepare("
                SELECT 
                    YEAR(date) as period,
                    SUM(total) as amount
                FROM {$sales_table}
                WHERE date >= DATE_SUB(MAKEDATE(%d, 1), INTERVAL 4 YEAR)
                AND status = 'completed'
                GROUP BY YEAR(date)
                ORDER BY YEAR(date)
            ", $year);
            break;
            
        default: // monthly
            $query = $wpdb->prepare("
                SELECT 
                    MONTHNAME(date) as period,
                    SUM(total) as amount
                FROM {$sales_table}
                WHERE YEAR(date) = %d 
                AND status = 'completed'
                GROUP BY MONTH(date), MONTHNAME(date)
                ORDER BY MONTH(date)
            ", $year);
    }
    
    $breakdown = $wpdb->get_results($query, ARRAY_A);
    $total = array_sum(array_column($breakdown, 'amount'));
    
    return array(
        'total' => floatval($total),
        'breakdown' => $breakdown
    );
}

/**
 * Get expenses breakdown by category
 */
function ims_get_expenses_breakdown($period, $year) {
    global $wpdb;
    
    $expenses_table = $wpdb->prefix . 'ims_expenses';
    $purchases_table = $wpdb->prefix . 'ims_purchase_orders';
    
    // Get regular expenses
    $expenses_query = $wpdb->prepare("
        SELECT 
            category,
            SUM(amount) as amount
        FROM {$expenses_table}
        WHERE YEAR(date) = %d
        GROUP BY category
        ORDER BY amount DESC
    ", $year);
    
    $expenses = $wpdb->get_results($expenses_query, ARRAY_A);
    
    // Add purchases as an expense category
    $purchases_total = $wpdb->get_var($wpdb->prepare("
        SELECT COALESCE(SUM(total), 0)
        FROM {$purchases_table}
        WHERE YEAR(date) = %d 
        AND status IN ('received', 'confirmed')
    ", $year));
    
    if ($purchases_total > 0) {
        array_unshift($expenses, array(
            'category' => 'Purchases',
            'amount' => floatval($purchases_total)
        ));
    }
    
    $total = array_sum(array_column($expenses, 'amount'));
    
    return array(
        'total' => $total,
        'breakdown' => $expenses
    );
}

/**
 * Get cash flow data
 */
function ims_get_cash_flow_data($year) {
    global $wpdb;
    
    $cash_flow_table = $wpdb->prefix . 'ims_cash_flow';
    
    // Get opening balance (last day of previous year)
    $opening_balance = $wpdb->get_var($wpdb->prepare("
        SELECT COALESCE(
            (SELECT SUM(CASE WHEN type = 'inflow' THEN amount ELSE -amount END)
             FROM {$cash_flow_table} 
             WHERE date < %s), 
            100000
        ) as opening_balance
    ", $year . '-01-01'));
    
    // Get inflows and outflows for the year
    $cash_flows = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'inflow' THEN amount END), 0) as total_inflow,
            COALESCE(SUM(CASE WHEN type = 'outflow' THEN amount END), 0) as total_outflow
        FROM {$cash_flow_table}
        WHERE YEAR(date) = %d
    ", $year), ARRAY_A);
    
    $closing_balance = $opening_balance + $cash_flows['total_inflow'] - $cash_flows['total_outflow'];
    
    return array(
        'opening' => floatval($opening_balance),
        'inflow' => floatval($cash_flows['total_inflow']),
        'outflow' => floatval($cash_flows['total_outflow']),
        'closing' => floatval($closing_balance)
    );
}



