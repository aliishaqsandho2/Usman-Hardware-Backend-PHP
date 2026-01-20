<?php
// Add this to your theme's functions.php file

// 1. Daily Sales Progress Dashboard API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/dashboard/daily-progress', array(
        'methods' => 'GET',
        'callback' => 'get_daily_sales_progress',
        'permission_callback' => '__return_true'
    ));
});

function get_daily_sales_progress($data) {
    global $wpdb;
    
    // Refactored to use direct sales table query for reliability
    $query = "
        SELECT 
            CURDATE() AS today,
            COALESCE(SUM(si.total), 0) AS revenue_so_far,
            COALESCE(SUM(CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END), 0) AS cogs_so_far,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit_so_far,
            COUNT(DISTINCT s.id) AS completed_sales,
            
            ROUND((COALESCE(SUM(si.total), 0) / 500000) * 100, 2) AS revenue_progress_percent,
            
            ROUND(((HOUR(CURRENT_TIME) - 8) / 12.0) * 100, 2) AS time_progress_percent,
            
            ROUND(COALESCE(SUM(si.total), 0) * (12.0 / GREATEST(HOUR(CURRENT_TIME) - 8, 1)), 2) AS projected_revenue,
            ROUND(COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) * (12.0 / GREATEST(HOUR(CURRENT_TIME) - 8, 1)), 2) AS projected_profit
            
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date = CURDATE()
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 2. Daily Performance vs Targets API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/dashboard/daily-performance', array(
        'methods' => 'GET',
        'callback' => 'get_daily_performance',
        'permission_callback' => '__return_true'
    ));
});

function get_daily_performance($data) {
    global $wpdb;
    
    // Refactored to use direct sales table query
    $query = "
        SELECT 
            COALESCE(SUM(si.total), 0) AS actual_revenue,
            COALESCE(SUM(CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END), 0) AS actual_cogs,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS actual_profit,
            
            500000 AS target_revenue,
            40000 AS target_profit,
            
            COALESCE(SUM(si.total), 0) - 500000 AS revenue_variance,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) - 40000 AS profit_variance,
            
            CASE 
                WHEN COALESCE(SUM(si.total), 0) > 0 THEN 
                    ROUND((SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)) / SUM(si.total)) * 100, 2)
                ELSE 0 
            END AS actual_margin,
            
            8.0 AS target_margin
            
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date = CURDATE()
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 3. Today vs Last Week Comparison API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/dashboard/week-comparison', array(
        'methods' => 'GET',
        'callback' => 'get_week_comparison',
        'permission_callback' => '__return_true'
    ));
});

function get_week_comparison($data) {
    global $wpdb;
    
    $query = "
        SELECT 
            'Today' AS period,
            CURDATE() AS date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END), 0) AS cogs,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
            
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date = CURDATE()

        UNION ALL

        SELECT 
            'Last Week Same Day' AS period,
            CURDATE() - INTERVAL 7 DAY AS date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END), 0) AS cogs,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
            
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date = CURDATE() - INTERVAL 7 DAY
    ";
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 4. Key Metrics Summary API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/dashboard/key-metrics', array(
        'methods' => 'GET',
        'callback' => 'get_key_metrics',
        'permission_callback' => '__return_true'
    ));
});

function get_key_metrics($data) {
    global $wpdb;
    
    $query = "
        SELECT 
            (SELECT COALESCE(SUM(si.total), 0)
             FROM {$wpdb->prefix}ims_sales s
             JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
             WHERE s.status = 'completed'
             AND s.date = CURDATE()) AS today_revenue,

            (SELECT COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0)
             FROM {$wpdb->prefix}ims_sales s
             JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
             JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
             WHERE s.status = 'completed'
             AND s.date = CURDATE()) AS today_profit,

            (SELECT COALESCE(SUM(si.total), 0)
             FROM {$wpdb->prefix}ims_sales s
             JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
             WHERE s.status = 'completed'
             AND s.date = CURDATE() - INTERVAL 1 DAY) AS yesterday_revenue,

            (SELECT COALESCE(SUM(si.total), 0)
             FROM {$wpdb->prefix}ims_sales s
             JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
             WHERE s.status = 'completed'
             AND YEAR(s.date) = YEAR(CURDATE()) AND MONTH(s.date) = MONTH(CURDATE())) AS month_revenue,

            (SELECT COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0)
             FROM {$wpdb->prefix}ims_sales s
             JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
             JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
             WHERE s.status = 'completed'
             AND YEAR(s.date) = YEAR(CURDATE()) AND MONTH(s.date) = MONTH(CURDATE())) AS month_profit
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 5. Category Performance API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/category-performance', array(
        'methods' => 'GET',
        'callback' => 'get_category_performance',
        'permission_callback' => '__return_true'
    ));
});

function get_category_performance($data) {
    global $wpdb;
    
    // Refactored to use direct sales table query
    $query = "
        SELECT 
            c.name AS category_name,
            COUNT(DISTINCT s.id) AS sales_count,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, prod.cost_price) ELSE si.quantity * prod.cost_price END), 0) AS cogs,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, prod.cost_price) ELSE si.quantity * prod.cost_price END)), 0) AS profit,
            ROUND((COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, prod.cost_price) ELSE si.quantity * prod.cost_price END)), 0) / 
                   NULLIF(COALESCE(SUM(si.total), 0), 0)) * 100, 2) AS margin
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products prod ON si.product_id = prod.id
        LEFT JOIN {$wpdb->prefix}ims_categories c ON prod.category_id = c.id
        WHERE s.status = 'completed'
        AND s.date = CURDATE()
        GROUP BY c.id, c.name
        ORDER BY profit DESC
    ";
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 6. Profit Overview API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/profit-overview', array(
        'methods' => 'GET',
        'callback' => 'get_profit_overview',
        'permission_callback' => '__return_true'
    ));
});

function get_profit_overview($data) {
    global $wpdb;
    
    $query = "
        SELECT 
            (SELECT COALESCE(daily_profit, 0) 
             FROM vw_daily_profit 
             WHERE profit_date = CURDATE()) AS today_profit,

            (SELECT COALESCE(daily_revenue, 0) 
             FROM vw_daily_profit 
             WHERE profit_date = CURDATE()) AS today_revenue,
            
            (SELECT COALESCE(SUM(daily_profit), 0) 
             FROM vw_daily_profit 
             WHERE profit_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)) AS week_profit,
            
            (SELECT COALESCE(monthly_profit, 0) 
             FROM vw_monthly_profit 
             WHERE year = YEAR(CURDATE()) 
               AND month = MONTH(CURDATE())) AS month_profit,
            
            (SELECT COALESCE(SUM(monthly_profit), 0) 
             FROM vw_monthly_profit 
             WHERE year = YEAR(CURDATE())) AS ytd_profit,
            
            (SELECT MAX(daily_profit) 
             FROM vw_daily_profit) AS best_day_profit,

            (SELECT profit_date 
             FROM vw_daily_profit 
             ORDER BY daily_profit DESC 
             LIMIT 1) AS best_day_date
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 7. Target Achievement API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/target-achievement', array(
        'methods' => 'GET',
        'callback' => 'get_target_achievement',
        'permission_callback' => '__return_true'
    ));
});

function get_target_achievement($data) {
    global $wpdb;
    
    $query = "
        SELECT 
            'Today' as period,
            COALESCE((SELECT daily_profit FROM vw_daily_profit WHERE profit_date = CURDATE()), 0) as actual_profit,
            40000 as target_profit,
            COALESCE((SELECT daily_profit FROM vw_daily_profit WHERE profit_date = CURDATE()), 0) - 40000 as variance

        UNION ALL

        SELECT 
            'This Week' as period,
            COALESCE(SUM(daily_profit), 0) as actual_profit,
            200000 as target_profit,
            COALESCE(SUM(daily_profit), 0) - 200000 as variance
        FROM vw_daily_profit 
        WHERE profit_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
        AND profit_date <= CURDATE()

        UNION ALL

        SELECT 
            'This Month' as period,
            COALESCE((SELECT monthly_profit FROM vw_monthly_profit 
                      WHERE year = YEAR(CURDATE()) AND month = MONTH(CURDATE())), 0) as actual_profit,
            600000 as target_profit,
            COALESCE((SELECT monthly_profit FROM vw_monthly_profit 
                      WHERE year = YEAR(CURDATE()) AND month = MONTH(CURDATE())), 0) - 600000 as variance
    ";
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 8. Monthly Trends API (with optional year parameter)
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/monthly-trends', array(
        'methods' => 'GET',
        'callback' => 'get_monthly_trends',
        'permission_callback' => '__return_true',
        'args' => array(
            'limit' => array(
                'default' => 6,
                'sanitize_callback' => 'absint'
            )
        )
    ));
});

function get_monthly_trends($data) {
    global $wpdb;
    
    $limit = $data['limit'] ?? 6;
    
    $query = $wpdb->prepare("
        SELECT
            CONCAT(mp.year, '-', LPAD(mp.month, 2, '0')) AS period,
            mp.monthly_revenue,
            mp.monthly_profit,
            ROUND((mp.monthly_profit / mp.monthly_revenue) * 100, 2) AS margin,
            COALESCE(s.sales_count, 0) AS sales_count,
            ROUND(mp.monthly_profit / COALESCE(s.sales_count, 1), 2) AS profit_per_sale
        FROM vw_monthly_profit mp
        LEFT JOIN (
            SELECT 
                YEAR(s.date) AS year,
                MONTH(s.date) AS month,
                COUNT(*) AS sales_count
            FROM uh_ims_sales s
            GROUP BY YEAR(s.date), MONTH(s.date)
        ) s ON s.year = mp.year AND s.month = mp.month
        ORDER BY mp.year DESC, mp.month DESC
        LIMIT %d
    ", $limit);
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 9. Weekly Trends API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/weekly-trends', array(
        'methods' => 'GET',
        'callback' => 'get_weekly_trends',
        'permission_callback' => '__return_true',
        'args' => array(
            'limit' => array(
                'default' => 4,
                'sanitize_callback' => 'absint'
            )
        )
    ));
});

function get_weekly_trends($data) {
    global $wpdb;
    
    $limit = $data['limit'] ?? 4;
    
    $query = $wpdb->prepare("
        SELECT
            CONCAT('Week ', week) AS week_number,
            week_start,
            week_end,
            weekly_profit,
            weekly_revenue,
            ROUND((weekly_profit/weekly_revenue)*100, 2) AS week_margin,
            sales_count
        FROM vw_weekly_profit
        ORDER BY year DESC, week DESC
        LIMIT %d
    ", $limit);
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 10. Top Customers API (with optional month/year parameters)
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/top-customers', array(
        'methods' => 'GET',
        'callback' => 'get_top_customers',
        'permission_callback' => '__return_true',
        'args' => array(
            'limit' => array(
                'default' => 10,
                'sanitize_callback' => 'absint'
            ),
            'month' => array(
                'default' => null,
                'sanitize_callback' => 'absint'
            ),
            'year' => array(
                'default' => null,
                'sanitize_callback' => 'absint'
            )
        )
    ));
});

function get_top_customers($data) {
    global $wpdb;
    
    $limit = $data['limit'] ?? 10;
    $month = $data['month'] ?? date('n');
    $year = $data['year'] ?? date('Y');
    
    $query = $wpdb->prepare("
        SELECT
            c.name AS customer_name,
            c.type AS customer_type,
            COUNT(DISTINCT s.id) AS monthly_orders,
            COALESCE(SUM(si.total), 0) AS monthly_revenue,
            COALESCE(SUM(CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END), 0) AS monthly_cogs,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS monthly_profit,
            
            CASE 
                WHEN SUM(si.total) > 0 THEN 
                    ROUND((SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)) / SUM(si.total)) * 100, 2)
                ELSE 0 
            END AS margin
            
        FROM {$wpdb->prefix}ims_customers c
        JOIN {$wpdb->prefix}ims_sales s ON c.id = s.customer_id
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
          AND YEAR(s.date) = %d
          AND MONTH(s.date) = %d
        GROUP BY c.id, c.name, c.type
        ORDER BY monthly_profit DESC
        LIMIT %d
    ", $year, $month, $limit);
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 11. YTD Summary API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/ytd-summary', array(
        'methods' => 'GET',
        'callback' => 'get_ytd_summary',
        'permission_callback' => '__return_true'
    ));
});

function get_ytd_summary($data) {
    global $wpdb;
    
    $query = "
        SELECT
            SUM(monthly_revenue) AS ytd_revenue,
            SUM(monthly_profit) AS ytd_profit,
            ROUND((SUM(monthly_profit)/SUM(monthly_revenue))*100, 2) AS ytd_margin,
            SUM(sales_count) AS ytd_sales
        FROM vw_monthly_profit
        WHERE year = YEAR(CURDATE())
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 12. Current Month Performance API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/current-month', array(
        'methods' => 'GET',
        'callback' => 'get_current_month_performance',
        'permission_callback' => '__return_true'
    ));
});

function get_current_month_performance($data) {
    global $wpdb;
    
    $query = "
        SELECT 
            monthly_revenue as current_month_revenue,
            monthly_cogs as current_month_cogs,
            monthly_profit as current_month_profit,
            ROUND((monthly_profit/monthly_revenue)*100, 2) as current_month_margin,
            sales_count as month_sales
        FROM vw_monthly_profit 
        WHERE year = YEAR(CURDATE()) AND month = MONTH(CURDATE())
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 13. Weekly Performance API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/weekly-performance', array(
        'methods' => 'GET',
        'callback' => 'get_weekly_performance',
        'permission_callback' => '__return_true'
    ));
});

function get_weekly_performance($data) {
    global $wpdb;
    
    $query = "
        SELECT 
            SUM(daily_profit) as week_profit,
            SUM(daily_revenue) as week_revenue,
            SUM(sales_count) as week_sales,
            ROUND((SUM(daily_profit)/SUM(daily_revenue))*100, 2) as week_margin
        FROM vw_daily_profit 
        WHERE profit_date >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
        AND profit_date <= CURDATE()
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 14. Today's Performance API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/today-performance', array(
        'methods' => 'GET',
        'callback' => 'get_today_performance',
        'permission_callback' => '__return_true'
    ));
});

function get_today_performance($data) {
    global $wpdb;
    
    $query = "
        SELECT 
            COALESCE(SUM(daily_profit), 0) as today_profit,
            COALESCE(SUM(daily_revenue), 0) as today_revenue,
            COALESCE(SUM(sales_count), 0) as today_sales
        FROM vw_daily_profit 
        WHERE profit_date = CURDATE()
    ";
    
    $results = $wpdb->get_row($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 15. Weekly and Monthly Comparison API (Corrected)
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/period-comparison', array(
        'methods' => 'GET',
        'callback' => 'get_period_comparison',
        'permission_callback' => '__return_true'
    ));
});

function get_period_comparison($data) {
    global $wpdb;
    
    // Using direct calculation from sales/items tables to match the logic of working views (vw_monthly_profit)
    // This avoids dependency on uh_ims_profit table which requires backfilling
    
    $query = "
        SELECT 
            'today' AS period,
            CURDATE() AS start_date,
            CURDATE() AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date = CURDATE()
        
        UNION ALL
        
        SELECT 
            'last_week' AS period,
            CURDATE() - INTERVAL 7 DAY AS start_date,
            CURDATE() - INTERVAL 1 DAY AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE() - INTERVAL 1 DAY
        
        UNION ALL
        
        SELECT 
            'last_2_weeks' AS period,
            CURDATE() - INTERVAL 14 DAY AS start_date,
            CURDATE() - INTERVAL 8 DAY AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date BETWEEN CURDATE() - INTERVAL 14 DAY AND CURDATE() - INTERVAL 8 DAY
        
        UNION ALL
        
        SELECT 
            'last_3_weeks' AS period,
            CURDATE() - INTERVAL 21 DAY AS start_date,
            CURDATE() - INTERVAL 15 DAY AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date BETWEEN CURDATE() - INTERVAL 21 DAY AND CURDATE() - INTERVAL 15 DAY
        
        UNION ALL
        
        SELECT 
            'last_4_weeks' AS period,
            CURDATE() - INTERVAL 28 DAY AS start_date,
            CURDATE() - INTERVAL 22 DAY AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date BETWEEN CURDATE() - INTERVAL 28 DAY AND CURDATE() - INTERVAL 22 DAY
        
        UNION ALL
        
        SELECT 
            'last_30_days' AS period,
            CURDATE() - INTERVAL 30 DAY AS start_date,
            CURDATE() - INTERVAL 1 DAY AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE() - INTERVAL 1 DAY
    ";
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}

// 16. This Month and This Week Performance API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/reports/current-period-performance', array(
        'methods' => 'GET',
        'callback' => 'get_current_period_performance',
        'permission_callback' => '__return_true'
    ));
});

function get_current_period_performance($data) {
    global $wpdb;
    
    // Calculate date ranges
    $first_day_of_month = date('Y-m-01'); // First day of current month
    $today = date('Y-m-d'); // Today
    
    // Calculate Monday of this week (this week starting from Monday)
    $monday_this_week = date('Y-m-d', strtotime('monday this week'));
    
    $query = $wpdb->prepare("
        SELECT 
            'this_month' AS period,
            %s AS start_date,
            %s AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit,
            COUNT(DISTINCT s.id) AS transactions
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date BETWEEN %s AND %s
        
        UNION ALL
        
        SELECT 
            'this_week' AS period,
            %s AS start_date,
            %s AS end_date,
            COALESCE(SUM(si.total), 0) AS revenue,
            COALESCE(SUM(si.total - (CASE WHEN si.is_outsourced = 1 THEN si.quantity * COALESCE(si.outsourcing_cost_per_unit, p.cost_price) ELSE si.quantity * p.cost_price END)), 0) AS profit,
            COUNT(DISTINCT s.id) AS transactions
        FROM {$wpdb->prefix}ims_sales s
        JOIN {$wpdb->prefix}ims_sale_items si ON s.id = si.sale_id
        JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
        WHERE s.status = 'completed'
        AND s.date BETWEEN %s AND %s
    ",
    // This month parameters
    $first_day_of_month, $today, $first_day_of_month, $today,
    
    // This week parameters
    $monday_this_week, $today, $monday_this_week, $today
    );
    
    $results = $wpdb->get_results($query, ARRAY_A);
    
    return rest_ensure_response($results);
}
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/daily-report', [
        'methods'  => 'GET',
        'callback' => 'ims_get_daily_report',
        'permission_callback' => '__return_true', // ✅ open endpoint
    ]);
 register_rest_route('ims/v1', '/monthly-report', [
        'methods'  => 'GET',
        'callback' => 'ims_get_monthly_report',
        'permission_callback' => '__return_true',
    ]);
});

function ims_get_daily_report() { 
    global $wpdb; 
    
    $results = $wpdb->get_results("
        SELECT 
            date_series.profit_date as date,
            COALESCE(dss.revenue, 0) as revenue,
            COALESCE(dss.profit, 0) as profit,
            COALESCE(dss.sales_count, 0) as sales_count,
            COALESCE(dss.profit_margin, 0) as profit_margin
        FROM (
            SELECT CURDATE() - INTERVAL (n) DAY as profit_date 
            FROM (
                SELECT 0 as n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 
                UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 
                UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
            ) numbers
        ) date_series
        LEFT JOIN vw_daily_sales_summary dss ON dss.sale_date = date_series.profit_date
        ORDER BY date_series.profit_date ASC;
    ");
    
    return rest_ensure_response($results); 
}
function ims_get_monthly_report() {
    global $wpdb;

    $results = $wpdb->get_results("
        SELECT 
            date_series.year,
            date_series.month,
            CONCAT(date_series.year, '-', LPAD(date_series.month, 2, '0')) as period,
            COALESCE(mss.revenue, 0) as revenue,
            COALESCE(mss.profit, 0) as profit,
            COALESCE(mss.sales_count, 0) as sales_count,
            COALESCE(mss.profit_margin, 0) as profit_margin
        FROM (
            SELECT 
                YEAR(CURDATE() - INTERVAL (n) MONTH) as year,
                MONTH(CURDATE() - INTERVAL (n) MONTH) as month
            FROM (
                SELECT 0 as n UNION SELECT 1 UNION SELECT 2 
                UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
            ) numbers
        ) date_series
        LEFT JOIN vw_monthly_sales_summary mss ON mss.year = date_series.year 
                                              AND mss.month = date_series.month
        ORDER BY date_series.year DESC, date_series.month DESC;
    ");

    return rest_ensure_response($results);
}

// Backfill Profit Data API
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/profit/backfill', array(
        'methods' => 'POST',
        'callback' => 'ims_backfill_profit_data',
        'permission_callback' => '__return_true'
    ));
});

function ims_backfill_profit_data() {
    global $wpdb;
    
    // 1. Clear existing profit data for sales
    $wpdb->query("DELETE FROM {$wpdb->prefix}ims_profit WHERE reference_type = 'sale'");
    
    // 2. Get all completed sales
    $sales = $wpdb->get_results("
        SELECT id, date, created_at 
        FROM {$wpdb->prefix}ims_sales 
        WHERE status = 'completed'
    ");
    
    $count = 0;
    
    foreach ($sales as $sale) {
        // Get items for this sale
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT si.*, p.cost_price as current_cost_price
            FROM {$wpdb->prefix}ims_sale_items si
            JOIN {$wpdb->prefix}ims_products p ON si.product_id = p.id
            WHERE si.sale_id = %d
        ", $sale->id));
        
        foreach ($items as $item) {
            $quantity = floatval($item->quantity);
            $unit_price = isset($item->total) && $quantity > 0 ? floatval($item->total) / $quantity : 0; // Robust price calc
            // Wait, we should use total directly for revenue to match exact sales figures
            $revenue = floatval($item->total);
            
            // Calculate COGS
            $cost_price = 0;
            if ($item->is_outsourced && !empty($item->outsourcing_cost_per_unit)) {
                $cost_price = floatval($item->outsourcing_cost_per_unit);
            } else {
                // Use current cost price as fallback for historical data
                $cost_price = floatval($item->current_cost_price);
            }
            $cogs = $quantity * $cost_price;
            $profit = $revenue - $cogs;
            
            // Insert
            $wpdb->insert(
                $wpdb->prefix . 'ims_profit',
                array(
                    'reference_id' => $sale->id,
                    'reference_type' => 'sale',
                    'period_type' => 'sale',
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'expenses' => 0,
                    'profit' => $profit,
                    'period_start' => $sale->date,
                    'period_end' => $sale->date,
                    'sale_date' => $sale->date,
                    'product_id' => $item->product_id,
                    'created_at' => $sale->created_at
                ),
                array('%d', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%s')
            );
        }
        $count++;
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'message' => "Successfully backfilled profit data for $count sales."
    ));
}
?>