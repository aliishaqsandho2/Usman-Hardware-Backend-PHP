<?php
// Dashboard.php functionality here

/**
 * Enhanced Business Dashboard API for functions.php
 * Comprehensive business insights and analytics
 */

// Register REST API routes
add_action('rest_api_init', function () {
    // Revenue Trend Chart API
    register_rest_route('ims/v1', '/dashboard/revenue-trend', array(
        'methods' => 'GET',
        'callback' => 'ims_get_revenue_trend',
        'permission_callback' => '__return_true',
    ));

    // Sales by Category Pie Chart API
    register_rest_route('ims/v1', '/dashboard/category-performance', array(
        'methods' => 'GET',
        'callback' => 'ims_get_category_performance',
        'permission_callback' => '__return_true',
    ));

    // Daily Sales vs Target Bar Chart API
    register_rest_route('ims/v1', '/dashboard/daily-sales', array(
        'methods' => 'GET',
        'callback' => 'ims_get_daily_sales',
        'permission_callback' => '__return_true',
    ));

    // Inventory Status Line Chart API
    register_rest_route('ims/v1', '/dashboard/inventory-status', array(
        'methods' => 'GET',
        'callback' => 'ims_get_inventory_status',
        'permission_callback' => '__return_true',
    ));

    // Enhanced Dashboard Stats API
    register_rest_route('ims/v1', '/dashboard/enhanced-stats', array(
        'methods' => 'GET',
        'callback' => 'rest_get_enhanced_dashboard_stats',
        'permission_callback' => '__return_true',
    ));
});

// WordPress AJAX handlers
add_action('wp_ajax_get_enhanced_dashboard_stats', 'ajax_get_enhanced_dashboard_stats');
add_action('wp_ajax_nopriv_get_enhanced_dashboard_stats', 'ajax_get_enhanced_dashboard_stats');

// ==================== API FUNCTIONS ====================

/**
 * Revenue Trend Chart API
 * GET /wp-json/ims/v1/dashboard/revenue-trend
 */
function ims_get_revenue_trend($request) {
    global $wpdb;
    
    $period = $request->get_param('period') ?: '30days';
    $start_date = $request->get_param('startDate');
    $end_date = $request->get_param('endDate');
    
    // Calculate date range based on period
    if (!$start_date || !$end_date) {
        $end_date = date('Y-m-d');
        switch ($period) {
            case '7days':
                $start_date = date('Y-m-d', strtotime('-7 days'));
                break;
            case '30days':
                $start_date = date('Y-m-d', strtotime('-30 days'));
                break;
            case '90days':
                $start_date = date('Y-m-d', strtotime('-90 days'));
                break;
            case '1year':
                $start_date = date('Y-m-d', strtotime('-1 year'));
                break;
            default:
                $start_date = date('Y-m-d', strtotime('-30 days'));
        }
    }
    
    // Determine grouping format based on period
    $date_format = '';
    $group_format = '';
    if ($period === '7days') {
        $date_format = '%W';
        $group_format = 'DATE(date)';
    } elseif ($period === '30days') {
        $date_format = '%m-%d';
        $group_format = 'DATE(date)';
    } elseif ($period === '90days') {
        $date_format = '%Y-%m-%d';
        $group_format = 'WEEK(date)';
    } else {
        $date_format = '%Y-%m';
        $group_format = 'YEAR(date), MONTH(date)';
    }
    
    $sales_table = $wpdb->prefix . 'ims_sales';
    
    $query = $wpdb->prepare("
        SELECT 
            DATE_FORMAT(date, %s) as period,
            SUM(total) as revenue,
            COUNT(*) as orders,
            MIN(date) as date
        FROM {$sales_table}
        WHERE date BETWEEN %s AND %s
        AND status = 'completed'
        GROUP BY {$group_format}
        ORDER BY date ASC
    ", $date_format, $start_date, $end_date);
    
    $results = $wpdb->get_results($query);
    
    if ($wpdb->last_error) {
        return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
    }
    
    $data = array();
    foreach ($results as $row) {
        $data[] = array(
            'period' => $row->period,
            'revenue' => floatval($row->revenue),
            'orders' => intval($row->orders),
            'date' => $row->date
        );
    }
    
    return array(
        'success' => true,
        'data' => $data
    );
}

/**
 * Sales by Category Performance API
 * GET /wp-json/ims/v1/dashboard/category-performance
 */
function ims_get_category_performance($request) {
    global $wpdb;
    
    $period = $request->get_param('period') ?: '30days';
    
    // Calculate date range
    $end_date = date('Y-m-d');
    switch ($period) {
        case '7days':
            $start_date = date('Y-m-d', strtotime('-7 days'));
            break;
        case '30days':
            $start_date = date('Y-m-d', strtotime('-30 days'));
            break;
        case '90days':
            $start_date = date('Y-m-d', strtotime('-90 days'));
            break;
        case '1year':
            $start_date = date('Y-m-d', strtotime('-1 year'));
            break;
        default:
            $start_date = date('Y-m-d', strtotime('-30 days'));
    }
    
    $sales_table = $wpdb->prefix . 'ims_sales';
    $sale_items_table = $wpdb->prefix . 'ims_sale_items';
    $products_table = $wpdb->prefix . 'ims_products';
    $categories_table = $wpdb->prefix . 'ims_categories';
    
    $query = $wpdb->prepare("
        SELECT 
            c.name as category,
            COUNT(si.id) as value,
            SUM(si.total) as amount,
            SUM(si.quantity) as unitsSold
        FROM {$sales_table} s
        JOIN {$sale_items_table} si ON s.id = si.sale_id
        JOIN {$products_table} p ON si.product_id = p.id
        LEFT JOIN {$categories_table} c ON p.category_id = c.id
        WHERE s.date BETWEEN %s AND %s
        AND s.status = 'completed'
        GROUP BY c.id, c.name
        ORDER BY amount DESC
    ", $start_date, $end_date);
    
    $results = $wpdb->get_results($query);
    
    if ($wpdb->last_error) {
        return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
    }
    
    $data = array();
    foreach ($results as $row) {
        $data[] = array(
            'category' => $row->category ?: 'Uncategorized',
            'value' => intval($row->value),
            'amount' => floatval($row->amount),
            'unitsSold' => intval($row->unitsSold)
        );
    }
    
    return array(
        'success' => true,
        'data' => $data
    );
}

/**
 * Daily Sales vs Target API
 * GET /wp-json/ims/v1/dashboard/daily-sales
 */
function ims_get_daily_sales($request) {
    global $wpdb;
    
    $days = intval($request->get_param('days')) ?: 7;
    $start_date = date('Y-m-d', strtotime("-{$days} days"));
    $end_date = date('Y-m-d');
    
    $sales_table = $wpdb->prefix . 'ims_sales';
    
    // Get daily target
    $target_query = $wpdb->prepare("
        SELECT AVG(daily_total) * 1.2 as target
        FROM (
            SELECT DATE(date) as sale_date, SUM(total) as daily_total
            FROM {$sales_table}
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND status = 'completed'
            GROUP BY DATE(date)
        ) as daily_sales
    ");
    
    $daily_target = $wpdb->get_var($target_query) ?: 15000;
    
    // Get actual sales for the requested period
    $query = $wpdb->prepare("
        SELECT 
            DATE_FORMAT(date, '%%a') as day,
            COALESCE(SUM(total), 0) as sales,
            DATE(date) as date
        FROM {$sales_table}
        WHERE date BETWEEN %s AND %s
        AND status = 'completed'
        GROUP BY DATE(date)
        ORDER BY date ASC
    ", $start_date, $end_date);
    
    $results = $wpdb->get_results($query);
    
    if ($wpdb->last_error) {
        return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
    }
    
    // Create array for all days in range, filling gaps with zero sales
    $data = array();
    $current_date = strtotime($start_date);
    $end_timestamp = strtotime($end_date);
    
    // Convert results to associative array for easy lookup
    $sales_by_date = array();
    foreach ($results as $row) {
        $sales_by_date[$row->date] = $row;
    }
    
    while ($current_date <= $end_timestamp) {
        $date_str = date('Y-m-d', $current_date);
        $day_name = date('D', $current_date);
        
        $sales = 0;
        if (isset($sales_by_date[$date_str])) {
            $sales = floatval($sales_by_date[$date_str]->sales);
        }
        
        $data[] = array(
            'day' => $day_name,
            'sales' => $sales,
            'target' => floatval($daily_target),
            'date' => $date_str
        );
        
        $current_date = strtotime('+1 day', $current_date);
    }
    
    return array(
        'success' => true,
        'data' => $data
    );
}

/**
 * Enhanced Dashboard Stats API
 * GET /wp-json/ims/v1/dashboard/enhanced-stats
 */
function rest_get_enhanced_dashboard_stats($request) {
    $result = get_enhanced_dashboard_stats();
    
    if ($result['success']) {
        return new WP_REST_Response($result, 200);
    } else {
        return new WP_REST_Response($result, 500);
    }
}

/**
 * AJAX handler for enhanced dashboard stats
 */
function ajax_get_enhanced_dashboard_stats() {
    $result = get_enhanced_dashboard_stats();
    wp_send_json($result);
}

// ==================== HELPER FUNCTIONS ====================

/**
 * Main enhanced dashboard stats function
 */
function get_enhanced_dashboard_stats() {
    global $wpdb;
    
    try {
        $stats = array();
        
        $stats['financial'] = get_financial_overview();
        $stats['sales'] = get_sales_analytics();
        $stats['inventory'] = get_inventory_insights();
        $stats['customers'] = get_customer_analytics();
        $stats['performance'] = get_business_performance();
        $stats['cashFlow'] = get_cash_flow_summary();
        $stats['alerts'] = get_business_alerts();
        
        return array(
            'success' => true,
            'data' => $stats,
            'generated_at' => current_time('mysql')
        );
        
    } catch (Exception $e) {
        return array(
            'success' => false,
            'message' => 'Error fetching dashboard stats: ' . $e->getMessage()
        );
    }
}

function get_financial_overview() {
    global $wpdb;
    
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $this_month = date('Y-m');
    $last_month = date('Y-m', strtotime('-1 month'));
    
    $today_revenue = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}ims_sales WHERE date = %s AND status = 'completed'",
        $today
    ));
    
    $yesterday_revenue = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}ims_sales WHERE date = %s AND status = 'completed'",
        $yesterday
    ));
    
    $month_revenue = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}ims_sales WHERE DATE_FORMAT(date, '%%Y-%%m') = %s AND status = 'completed'",
        $this_month
    ));
    
    $last_month_revenue = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}ims_sales WHERE DATE_FORMAT(date, '%%Y-%%m') = %s AND status = 'completed'",
        $last_month
    ));
    
    $month_expenses = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ims_expenses WHERE DATE_FORMAT(date, '%%Y-%%m') = %s",
        $this_month
    ));
    
    $total_cost = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(si.quantity * p.cost_price), 0) 
         FROM {$wpdb->prefix}ims_sale_items si 
         JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id 
         JOIN {$wpdb->prefix}ims_sales s ON si.sale_id = s.id 
         WHERE DATE_FORMAT(s.date, '%%Y-%%m') = %s AND s.status = 'completed'",
        $this_month
    ));
    
    $gross_profit = $month_revenue - $total_cost;
    $net_profit = $gross_profit - $month_expenses;
    $profit_margin = $month_revenue > 0 ? ($gross_profit / $month_revenue) * 100 : 0;
    
    return array(
        'todayRevenue' => floatval($today_revenue),
        'yesterdayRevenue' => floatval($yesterday_revenue),
        'monthRevenue' => floatval($month_revenue),
        'lastMonthRevenue' => floatval($last_month_revenue),
        'monthExpenses' => floatval($month_expenses),
        'grossProfit' => floatval($gross_profit),
        'netProfit' => floatval($net_profit),
        'profitMargin' => round($profit_margin, 2),
        'revenueGrowth' => $yesterday_revenue > 0 ? round((($today_revenue - $yesterday_revenue) / $yesterday_revenue) * 100, 2) : 0,
        'monthlyGrowth' => $last_month_revenue > 0 ? round((($month_revenue - $last_month_revenue) / $last_month_revenue) * 100, 2) : 0
    );
}

function get_sales_analytics() {
    global $wpdb;
    
    $today = date('Y-m-d');
    $this_week = date('Y-m-d', strtotime('-7 days'));
    $this_month = date('Y-m');
    
    $today_sales = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_sales WHERE date = %s AND status = 'completed'",
        $today
    ));
    
    $week_sales = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_sales WHERE date >= %s AND status = 'completed'",
        $this_week
    ));
    
    $avg_order_value = $wpdb->get_var($wpdb->prepare(
        "SELECT AVG(total) FROM {$wpdb->prefix}ims_sales WHERE DATE_FORMAT(date, '%%Y-%%m') = %s AND status = 'completed'",
        $this_month
    ));
    
    $payment_methods = $wpdb->get_results($wpdb->prepare(
        "SELECT payment_method, COUNT(*) as count, SUM(total) as amount 
         FROM {$wpdb->prefix}ims_sales 
         WHERE DATE_FORMAT(date, '%%Y-%%m') = %s AND status = 'completed' 
         GROUP BY payment_method",
        $this_month
    ));
    
    $payment_stats = array();
    foreach ($payment_methods as $method) {
        $payment_stats[] = array(
            'method' => $method->payment_method,
            'count' => intval($method->count),
            'amount' => floatval($method->amount)
        );
    }
    
    $pending_value = $wpdb->get_var(
        "SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}ims_sales WHERE status = 'pending'"
    );
    
    $high_value_sales = $wpdb->get_results(
        "SELECT 
            c.id as customer_id,
            COALESCE(c.name, 'Walk-in') as customer_name,
            SUM(s.total) as total_spent,
            MAX(s.date) as last_purchase_date,
            MAX(s.order_number) as last_order_number
         FROM {$wpdb->prefix}ims_sales s
         LEFT JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id
         WHERE s.status = 'completed'
         GROUP BY c.id
         HAVING total_spent > 1000
         ORDER BY total_spent DESC
         LIMIT 5"
    );
    
    $high_sales = array();
    foreach ($high_value_sales as $sale) {
        $high_sales[] = array(
            'orderNumber' => $sale->last_order_number,
            'amount' => floatval($sale->total_spent),
            'customer' => $sale->customer_name,
            'date' => $sale->last_purchase_date
        );
    }
    
    return array(
        'todaySales' => intval($today_sales),
        'weekSales' => intval($week_sales),
        'avgOrderValue' => round(floatval($avg_order_value), 2),
        'pendingOrdersValue' => floatval($pending_value),
        'paymentMethods' => $payment_stats,
        'highValueSales' => $high_sales
    );
}

function get_inventory_insights() {
    global $wpdb;
    
    $total_inventory_value = $wpdb->get_var(
        "SELECT SUM(stock * cost_price) FROM {$wpdb->prefix}ims_products WHERE status = 'active'"
    );
    
    $retail_inventory_value = $wpdb->get_var(
        "SELECT SUM(stock * price) FROM {$wpdb->prefix}ims_products WHERE status = 'active'"
    );
    
    $low_stock_count = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_products WHERE stock <= min_stock AND status = 'active'"
    );
    
    $out_of_stock = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_products WHERE stock = 0 AND status = 'active'"
    );
    
    $overstock_items = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_products WHERE stock > max_stock AND status = 'active'"
    );
    
    $fast_moving = $wpdb->get_results(
        "SELECT p.name, SUM(si.quantity) as sold, p.stock
         FROM {$wpdb->prefix}ims_products p
         JOIN {$wpdb->prefix}ims_sale_items si ON p.id = si.product_id
         JOIN {$wpdb->prefix}ims_sales s ON si.sale_id = s.id
         WHERE s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND s.status = 'completed'
         GROUP BY p.id, p.name, p.stock
         ORDER BY sold DESC LIMIT 5"
    );
    
    $fast_products = array();
    foreach ($fast_moving as $product) {
        $fast_products[] = array(
            'name' => $product->name,
            'sold' => intval($product->sold),
            'remaining' => intval($product->stock)
        );
    }
    
    $dead_stock_value = $wpdb->get_var(
        "SELECT SUM(p.stock * p.cost_price)
         FROM {$wpdb->prefix}ims_products p
         WHERE p.status = 'active'
         AND p.id NOT IN (
             SELECT DISTINCT si.product_id 
             FROM {$wpdb->prefix}ims_sale_items si
             JOIN {$wpdb->prefix}ims_sales s ON si.sale_id = s.id
             WHERE s.date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         )"
    );
    
    return array(
        'totalInventoryValue' => floatval($total_inventory_value),
        'retailInventoryValue' => floatval($retail_inventory_value),
        'lowStockItems' => intval($low_stock_count),
        'outOfStockItems' => intval($out_of_stock),
        'overstockItems' => intval($overstock_items),
        'fastMovingProducts' => $fast_products,
        'deadStockValue' => floatval($dead_stock_value),
        'inventoryTurnover' => $total_inventory_value > 0 ? round(($retail_inventory_value / $total_inventory_value), 2) : 0
    );
}

function get_customer_analytics() {
    global $wpdb;
    
    $this_month = date('Y-m');
    
    $total_customers = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_customers WHERE status = 'active'"
    );
    
    $new_customers_month = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_customers WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
        $this_month
    ));
    
    $avg_customer_value = $wpdb->get_var(
        "SELECT AVG(total_purchases) FROM {$wpdb->prefix}ims_customers WHERE total_purchases > 0"
    );
    
    $top_customers = $wpdb->get_results(
        "SELECT name, total_purchases, current_balance 
         FROM {$wpdb->prefix}ims_customers 
         WHERE status = 'active' AND total_purchases > 0
         ORDER BY total_purchases DESC LIMIT 5"
    );
    
    $customer_list = array();
    foreach ($top_customers as $customer) {
        $customer_list[] = array(
            'name' => $customer->name,
            'totalPurchases' => floatval($customer->total_purchases),
            'balance' => floatval($customer->current_balance)
        );
    }
    
    $customer_types = $wpdb->get_results(
        "SELECT type, COUNT(*) as count FROM {$wpdb->prefix}ims_customers WHERE status = 'active' GROUP BY type"
    );
    
    $types_data = array();
    foreach ($customer_types as $type) {
        $types_data[] = array(
            'type' => $type->type,
            'count' => intval($type->count)
        );
    }
    
    $total_receivables = $wpdb->get_var(
        "SELECT SUM(current_balance) FROM {$wpdb->prefix}ims_customers WHERE current_balance > 0"
    );
    
    return array(
        'totalCustomers' => intval($total_customers),
        'newCustomersThisMonth' => intval($new_customers_month),
        'avgCustomerValue' => round(floatval($avg_customer_value), 2),
        'topCustomers' => $customer_list,
        'customerTypes' => $types_data,
        'totalReceivables' => floatval($total_receivables)
    );
}

function get_business_performance() {
    global $wpdb;
    
    $weekly_trend = $wpdb->get_results(
        "SELECT 
            WEEK(date) as week_num,
            SUM(total) as revenue,
            COUNT(*) as orders
         FROM {$wpdb->prefix}ims_sales 
         WHERE date >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) AND status = 'completed'
         GROUP BY WEEK(date)
         ORDER BY week_num"
    );
    
    $trend_data = array();
    foreach ($weekly_trend as $week) {
        $trend_data[] = array(
            'week' => 'Week ' . $week->week_num,
            'revenue' => floatval($week->revenue),
            'orders' => intval($week->orders)
        );
    }
    
    $daily_avg_revenue = $wpdb->get_var(
        "SELECT AVG(daily_total) FROM (
            SELECT SUM(total) as daily_total 
            FROM {$wpdb->prefix}ims_sales 
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'completed'
            GROUP BY date
        ) as daily_sales"
    );
    
    $daily_avg_orders = $wpdb->get_var(
        "SELECT AVG(daily_count) FROM (
            SELECT COUNT(*) as daily_count 
            FROM {$wpdb->prefix}ims_sales 
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND status = 'completed'
            GROUP BY date
        ) as daily_orders"
    );
    
    $category_performance = $wpdb->get_results(
        "SELECT 
            c.name as category,
            SUM(si.total) as revenue,
            SUM(si.quantity) as units_sold
         FROM {$wpdb->prefix}ims_categories c
         JOIN {$wpdb->prefix}ims_products p ON c.id = p.category_id
         JOIN {$wpdb->prefix}ims_sale_items si ON p.id = si.product_id
         JOIN {$wpdb->prefix}ims_sales s ON si.sale_id = s.id
         WHERE s.status = 'completed' AND s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         GROUP BY c.id, c.name
         ORDER BY revenue DESC"
    );
    
    $category_data = array();
    foreach ($category_performance as $cat) {
        $category_data[] = array(
            'category' => $cat->category,
            'revenue' => floatval($cat->revenue),
            'unitsSold' => intval($cat->units_sold)
        );
    }
    
    return array(
        'weeklyTrend' => $trend_data,
        'dailyAvgRevenue' => round(floatval($daily_avg_revenue), 2),
        'dailyAvgOrders' => round(floatval($daily_avg_orders), 1),
        'categoryPerformance' => $category_data
    );
}

function get_cash_flow_summary() {
    global $wpdb;
    
    $this_month = date('Y-m');
    
    $cash_inflows = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ims_cash_flow 
         WHERE type = 'inflow' AND DATE_FORMAT(date, '%%Y-%%m') = %s",
        $this_month
    ));
    
    $cash_outflows = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ims_cash_flow 
         WHERE type = 'outflow' AND DATE_FORMAT(date, '%%Y-%%m') = %s",
        $this_month
    ));
    
    $net_cash_flow = $cash_inflows - $cash_outflows;
    
    $recent_payments = $wpdb->get_results(
        "SELECT c.name as customer, p.amount, p.date
         FROM {$wpdb->prefix}ims_payments p
         JOIN {$wpdb->prefix}ims_customers c ON p.customer_id = c.id
         ORDER BY p.created_at DESC LIMIT 5"
    );
    
    $payment_list = array();
    foreach ($recent_payments as $payment) {
        $payment_list[] = array(
            'customer' => $payment->customer,
            'amount' => floatval($payment->amount),
            'date' => $payment->date
        );
    }
    
    return array(
        'monthlyInflows' => floatval($cash_inflows),
        'monthlyOutflows' => floatval($cash_outflows),
        'netCashFlow' => floatval($net_cash_flow),
        'recentPayments' => $payment_list
    );
}

function get_business_alerts() {
    global $wpdb;
    
    $alerts = array();
    
    $critical_stock = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_products WHERE stock <= 5 AND status = 'active'"
    );
    if ($critical_stock > 0) {
        $alerts[] = array(
            'type' => 'critical',
            'title' => 'Critical Stock Alert',
            'message' => "$critical_stock products are critically low on stock",
            'action' => 'reorder_inventory'
        );
    }
    
    $overdue_amount = $wpdb->get_var(
        "SELECT SUM(total) FROM {$wpdb->prefix}ims_sales 
         WHERE status = 'pending' AND due_date < CURDATE()"
    );
    if ($overdue_amount > 0) {
        $alerts[] = array(
            'type' => 'warning',
            'title' => 'Overdue Payments',
            'message' => 'PKR ' . number_format($overdue_amount, 2) . ' in overdue payments',
            'action' => 'follow_up_payments'
        );
    }
    
    $high_pending = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}ims_sales WHERE status = 'pending' AND total > 5000"
    );
    if ($high_pending > 0) {
        $alerts[] = array(
            'type' => 'info',
            'title' => 'High Value Pending Orders',
            'message' => "$high_pending high-value orders are pending completion",
            'action' => 'process_orders'
        );
    }
    
    return $alerts;
}

/**
 * Inventory Status API
 * GET /wp-json/ims/v1/dashboard/inventory-status
 */
function ims_get_inventory_status($request) {
    global $wpdb;
    
    $products_table = $wpdb->prefix . 'ims_products';
    $categories_table = $wpdb->prefix . 'ims_categories';
    $sale_items_table = $wpdb->prefix . 'ims_sale_items';
    $sales_table = $wpdb->prefix . 'ims_sales';
    
    // Get inventory status by category
    $query = "
        SELECT 
            c.name as category,
            SUM(p.stock) as stock,
            COALESCE(sold_data.sold, 0) as sold,
            MIN(p.min_stock) as reorderLevel
        FROM {$products_table} p
        LEFT JOIN {$categories_table} c ON p.category_id = c.id
        LEFT JOIN (
            SELECT 
                p2.category_id,
                SUM(si.quantity) as sold
            FROM {$sale_items_table} si
            JOIN {$sales_table} s ON si.sale_id = s.id
            JOIN {$products_table} p2 ON si.product_id = p2.id
            WHERE s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            AND s.status = 'completed'
            GROUP BY p2.category_id
        ) sold_data ON p.category_id = sold_data.category_id
        WHERE p.status = 'active'
        GROUP BY c.id, c.name
        ORDER BY c.name
    ";
    
    $results = $wpdb->get_results($query);
    
    if ($wpdb->last_error) {
        return new WP_Error('database_error', $wpdb->last_error, array('status' => 500));
    }
    
    $data = array();
    foreach ($results as $row) {
        $data[] = array(
            'category' => $row->category ?: 'Uncategorized',
            'stock' => floatval($row->stock),
            'sold' => floatval($row->sold),
            'reorderLevel' => floatval($row->reorderLevel)
        );
    }
    
    return array(
        'success' => true,
        'data' => $data
    );
}






















/**
 * Performance Analytics REST API Endpoints
 * Add this code to your theme's functions.php
 */

// Register REST API routes
add_action('rest_api_init', function () {
    // Weekly Performance Trend API
    register_rest_route('ims/v1', '/performance/weekly-trend', array(
        'methods' => 'GET',
        'callback' => 'ims_get_weekly_performance_trend',
        'permission_callback' => '__return_true'
    ));

    // Recent High-Value Sales API
    register_rest_route('ims/v1', '/sales/high-value-recent', array(
        'methods' => 'GET',
        'callback' => 'ims_get_recent_high_value_sales',
        'permission_callback' => '__return_true',
        'args' => array(
            'limit' => array(
                'default' => 15,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));

    // Top and Dead Products API
    register_rest_route('ims/v1', '/products/performance', array(
        'methods' => 'GET',
        'callback' => 'ims_get_products_performance',
        'permission_callback' => '__return_true',
        'args' => array(
            'limit' => array(
                'default' => 15,
                'sanitize_callback' => 'absint',
            ),
            'period_days' => array(
                'default' => 90,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));
});

/**
 * API 1: Weekly Performance Trend (Past 12 Weeks)
 * Returns revenue, profit, COGS, expenses, and sales count for each week
 * 
 * Endpoint: /wp-json/ims/v1/performance/weekly-trend
 * Method: GET
 */
function ims_get_weekly_performance_trend() {
    global $wpdb;
    
    $query = "
        SELECT 
            year,
            week,
            DATE_FORMAT(week_start, '%Y-%m-%d') as week_start_date,
            DATE_FORMAT(week_end, '%Y-%m-%d') as week_end_date,
            CONCAT('Week ', week, ', ', year) as week_label,
            CONCAT(DATE_FORMAT(week_start, '%b %d'), ' - ', DATE_FORMAT(week_end, '%b %d')) as date_range,
            CAST(weekly_revenue AS DECIMAL(10,2)) as revenue,
            CAST(weekly_cogs AS DECIMAL(10,2)) as cogs,
            CAST(weekly_expenses AS DECIMAL(10,2)) as expenses,
            CAST(weekly_profit AS DECIMAL(10,2)) as profit,
            sales_count,
            CASE 
                WHEN weekly_revenue > 0 
                THEN CAST((weekly_profit / weekly_revenue * 100) AS DECIMAL(5,2))
                ELSE 0 
            END as profit_margin_percent
        FROM vw_weekly_profit
        ORDER BY year DESC, week DESC
        LIMIT 12
    ";
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    if ($wpdb->last_error) {
        return new WP_Error(
            'database_error',
            'Database query failed: ' . $wpdb->last_error,
            array('status' => 500)
        );
    }
    
    // Reverse array to show oldest to newest for graph
    $results = array_reverse($results);
    
    // Prepare response with summary statistics
    $total_revenue = array_sum(array_column($results, 'revenue'));
    $total_profit = array_sum(array_column($results, 'profit'));
    $total_sales = array_sum(array_column($results, 'sales_count'));
    $avg_profit_margin = $total_revenue > 0 ? ($total_profit / $total_revenue * 100) : 0;
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => $results,
        'summary' => array(
            'total_weeks' => count($results),
            'total_revenue' => number_format($total_revenue, 2),
            'total_profit' => number_format($total_profit, 2),
            'total_sales' => (int)$total_sales,
            'avg_profit_margin' => number_format($avg_profit_margin, 2),
        ),
        'metadata' => array(
            'timestamp' => current_time('mysql'),
            'period' => 'Last 12 Weeks'
        )
    ), 200);
}

/**
 * API 2: Recent High-Value Sales (Minimum 15)
 * Returns recent completed sales ordered by total amount
 * 
 * Endpoint: /wp-json/ims/v1/sales/high-value-recent?limit=15
 * Method: GET
 * Parameters: limit (default: 15)
 */
function ims_get_recent_high_value_sales($request) {
    global $wpdb;
    
    $limit = $request->get_param('limit');
    $limit = max(15, $limit); // Ensure minimum 15 records
    
    $query = $wpdb->prepare("
        SELECT 
            s.id,
            s.order_number,
            DATE_FORMAT(s.date, '%%Y-%%m-%%d') as sale_date,
            DATE_FORMAT(s.date, '%%b %%d, %%Y') as sale_date_formatted,
            s.time as sale_time,
            CAST(s.total AS DECIMAL(10,2)) as total_amount,
            CAST(s.subtotal AS DECIMAL(10,2)) as subtotal,
            CAST(s.discount AS DECIMAL(10,2)) as discount,
            CAST(s.tax AS DECIMAL(10,2)) as tax,
            s.payment_method,
            s.status,
            c.id as customer_id,
            c.name as customer_name,
            c.phone as customer_phone,
            c.type as customer_type,
            COUNT(si.id) as items_count,
            SUM(si.quantity) as total_items_quantity,
            CAST(SUM(si.quantity * p.cost_price) AS DECIMAL(10,2)) as total_cogs,
            CAST(s.total - SUM(si.quantity * p.cost_price) AS DECIMAL(10,2)) as estimated_profit,
            CASE 
                WHEN s.total > 0 
                THEN CAST(((s.total - SUM(si.quantity * p.cost_price)) / s.total * 100) AS DECIMAL(5,2))
                ELSE 0 
            END as profit_margin_percent,
            DATEDIFF(CURDATE(), s.date) as days_ago
        FROM uh_ims_sales s
        LEFT JOIN uh_ims_customers c ON s.customer_id = c.id
        LEFT JOIN uh_ims_sale_items si ON s.id = si.sale_id
        LEFT JOIN uh_ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        GROUP BY s.id
        ORDER BY s.total DESC, s.date DESC
        LIMIT %d
    ", $limit);
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    if ($wpdb->last_error) {
        return new WP_Error(
            'database_error',
            'Database query failed: ' . $wpdb->last_error,
            array('status' => 500)
        );
    }
    
    // Calculate summary statistics
    $total_value = array_sum(array_column($results, 'total_amount'));
    $avg_value = count($results) > 0 ? $total_value / count($results) : 0;
    $total_profit = array_sum(array_column($results, 'estimated_profit'));
    $avg_profit_margin = $total_value > 0 ? ($total_profit / $total_value * 100) : 0;
    
    // Add rank to each sale
    foreach ($results as $index => &$sale) {
        $sale['rank'] = $index + 1;
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => $results,
        'summary' => array(
            'total_sales' => count($results),
            'total_value' => number_format($total_value, 2),
            'average_value' => number_format($avg_value, 2),
            'total_profit' => number_format($total_profit, 2),
            'avg_profit_margin' => number_format($avg_profit_margin, 2),
            'highest_sale' => count($results) > 0 ? number_format($results[0]['total_amount'], 2) : 0,
            'lowest_sale' => count($results) > 0 ? number_format($results[count($results)-1]['total_amount'], 2) : 0,
        ),
        'metadata' => array(
            'timestamp' => current_time('mysql'),
            'limit' => $limit,
            'query_type' => 'High-Value Recent Sales'
        )
    ), 200);
}

/**
 * API 3: Top and Dead Products Performance
 * Returns top 15 selling products and bottom 15 dead stock products
 * 
 * Endpoint: /wp-json/ims/v1/products/performance?limit=15&period_days=90
 * Method: GET
 * Parameters: 
 *   - limit (default: 15) - Number of products in each category
 *   - period_days (default: 90) - Period to analyze for top products
 */
function ims_get_products_performance($request) {
    global $wpdb;
    
    $limit = $request->get_param('limit');
    $period_days = $request->get_param('period_days');
    
    // Query for TOP PRODUCTS (based on revenue in specified period)
    $top_products_query = $wpdb->prepare("
        SELECT 
            p.id,
            p.name as product_name,
            p.sku,
            c.name as category_name,
            CAST(p.price AS DECIMAL(10,2)) as unit_price,
            CAST(p.cost_price AS DECIMAL(10,2)) as cost_price,
            CAST(p.stock AS DECIMAL(10,2)) as current_stock,
            p.unit,
            p.status,
            COUNT(DISTINCT si.sale_id) as total_orders,
            CAST(SUM(si.quantity) AS DECIMAL(10,2)) as total_quantity_sold,
            CAST(SUM(si.total) AS DECIMAL(10,2)) as total_revenue,
            CAST(SUM(si.quantity * p.cost_price) AS DECIMAL(10,2)) as total_cogs,
            CAST(SUM(si.total - si.quantity * p.cost_price) AS DECIMAL(10,2)) as total_profit,
            CASE 
                WHEN SUM(si.total) > 0 
                THEN CAST((SUM(si.total - si.quantity * p.cost_price) / SUM(si.total) * 100) AS DECIMAL(5,2))
                ELSE 0 
            END as profit_margin_percent,
            DATE_FORMAT(MAX(s.date), '%%Y-%%m-%%d') as last_sale_date,
            DATEDIFF(CURDATE(), MAX(s.date)) as days_since_last_sale
        FROM uh_ims_products p
        LEFT JOIN uh_ims_categories c ON p.category_id = c.id
        INNER JOIN uh_ims_sale_items si ON p.id = si.product_id
        INNER JOIN uh_ims_sales s ON si.sale_id = s.id
        WHERE s.status = 'completed'
            AND s.date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            AND p.status = 'active'
        GROUP BY p.id
        ORDER BY total_revenue DESC
        LIMIT %d
    ", $period_days, $limit);
    
    $top_products = $wpdb->get_results($top_products_query, ARRAY_A);
    
    // Query for DEAD PRODUCTS (no sales or very low sales in specified period)
    $dead_products_query = $wpdb->prepare("
        SELECT 
            p.id,
            p.name as product_name,
            p.sku,
            c.name as category_name,
            CAST(p.price AS DECIMAL(10,2)) as unit_price,
            CAST(p.cost_price AS DECIMAL(10,2)) as cost_price,
            CAST(p.stock AS DECIMAL(10,2)) as current_stock,
            p.unit,
            p.status,
            COALESCE(sale_data.total_orders, 0) as total_orders,
            COALESCE(CAST(sale_data.total_quantity_sold AS DECIMAL(10,2)), 0) as total_quantity_sold,
            COALESCE(CAST(sale_data.total_revenue AS DECIMAL(10,2)), 0) as total_revenue,
            COALESCE(CAST(sale_data.total_cogs AS DECIMAL(10,2)), 0) as total_cogs,
            COALESCE(CAST(sale_data.total_profit AS DECIMAL(10,2)), 0) as total_profit,
            COALESCE(sale_data.profit_margin_percent, 0) as profit_margin_percent,
            DATE_FORMAT(sale_data.last_sale_date, '%%Y-%%m-%%d') as last_sale_date,
            COALESCE(sale_data.days_since_last_sale, 9999) as days_since_last_sale,
            CAST(p.stock * p.cost_price AS DECIMAL(10,2)) as dead_stock_value
        FROM uh_ims_products p
        LEFT JOIN uh_ims_categories c ON p.category_id = c.id
        LEFT JOIN (
            SELECT 
                si.product_id,
                COUNT(DISTINCT si.sale_id) as total_orders,
                SUM(si.quantity) as total_quantity_sold,
                SUM(si.total) as total_revenue,
                SUM(si.quantity * p2.cost_price) as total_cogs,
                SUM(si.total - si.quantity * p2.cost_price) as total_profit,
                CASE 
                    WHEN SUM(si.total) > 0 
                    THEN (SUM(si.total - si.quantity * p2.cost_price) / SUM(si.total) * 100)
                    ELSE 0 
                END as profit_margin_percent,
                MAX(s.date) as last_sale_date,
                DATEDIFF(CURDATE(), MAX(s.date)) as days_since_last_sale
            FROM uh_ims_sale_items si
            INNER JOIN uh_ims_sales s ON si.sale_id = s.id
            INNER JOIN uh_ims_products p2 ON si.product_id = p2.id
            WHERE s.status = 'completed'
                AND s.date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            GROUP BY si.product_id
        ) sale_data ON p.id = sale_data.product_id
        WHERE p.status = 'active'
            AND p.stock > 0
        ORDER BY 
            COALESCE(sale_data.total_revenue, 0) ASC,
            days_since_last_sale DESC,
            p.stock DESC
        LIMIT %d
    ", $period_days, $limit);
    
    $dead_products = $wpdb->get_results($dead_products_query, ARRAY_A);
    
    if ($wpdb->last_error) {
        return new WP_Error(
            'database_error',
            'Database query failed: ' . $wpdb->last_error,
            array('status' => 500)
        );
    }
    
    // Add rankings
    foreach ($top_products as $index => &$product) {
        $product['rank'] = $index + 1;
    }
    
    foreach ($dead_products as $index => &$product) {
        $product['rank'] = $index + 1;
    }
    
    // Calculate summaries
    $top_summary = array(
        'count' => count($top_products),
        'total_revenue' => number_format(array_sum(array_column($top_products, 'total_revenue')), 2),
        'total_profit' => number_format(array_sum(array_column($top_products, 'total_profit')), 2),
        'total_quantity_sold' => number_format(array_sum(array_column($top_products, 'total_quantity_sold')), 2),
        'avg_profit_margin' => count($top_products) > 0 
            ? number_format(array_sum(array_column($top_products, 'profit_margin_percent')) / count($top_products), 2)
            : 0
    );
    
    $dead_summary = array(
        'count' => count($dead_products),
        'total_dead_stock_value' => number_format(array_sum(array_column($dead_products, 'dead_stock_value')), 2),
        'total_stock_units' => number_format(array_sum(array_column($dead_products, 'current_stock')), 2),
        'avg_days_since_sale' => count($dead_products) > 0 
            ? round(array_sum(array_column($dead_products, 'days_since_last_sale')) / count($dead_products))
            : 0
    );
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'top_products' => $top_products,
            'dead_products' => $dead_products
        ),
        'summary' => array(
            'top_products' => $top_summary,
            'dead_products' => $dead_summary
        ),
        'metadata' => array(
            'timestamp' => current_time('mysql'),
            'period_days' => $period_days,
            'limit_per_category' => $limit,
            'analysis_period' => "Last {$period_days} days"
        )
    ), 200);
}

/**
 * Usage Examples:
 * 
 * 1. Weekly Performance Trend:
 *    GET /wp-json/ims/v1/performance/weekly-trend
 * 
 * 2. Recent High-Value Sales (default 15):
 *    GET /wp-json/ims/v1/sales/high-value-recent
 * 
 * 3. Recent High-Value Sales (custom limit):
 *    GET /wp-json/ims/v1/sales/high-value-recent?limit=25
 * 
 * 4. Top and Dead Products (default 15 each, last 90 days):
 *    GET /wp-json/ims/v1/products/performance
 * 
 * 5. Top and Dead Products (custom parameters):
 *    GET /wp-json/ims/v1/products/performance?limit=20&period_days=180
 * 
 * Response Format:
 * {
 *   "success": true,
 *   "data": [...],
 *   "summary": {...},
 *   "metadata": {...}
 * }
 */
?>