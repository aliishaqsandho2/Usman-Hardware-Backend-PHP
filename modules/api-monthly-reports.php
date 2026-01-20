<?php
/**
 * Monthly Reporting REST API Endpoints
 * Add this code to your WordPress theme's functions.php file
 * All endpoints are open (no authentication required)
 */

// Register REST API routes
add_action('rest_api_init', function () {
    
    // 1. Monthly Product Sales Report
    register_rest_route('ims/v1', '/monthly-product-sales', array(
        'methods' => 'GET',
        'callback' => 'get_monthly_product_sales_report',
        'permission_callback' => '__return_true', // Open endpoint
    ));
    
    // 2. Monthly Customer Purchase Report
    register_rest_route('ims/v1', '/monthly-customer-purchases', array(
        'methods' => 'GET',
        'callback' => 'get_monthly_customer_purchase_report',
        'permission_callback' => '__return_true',
    ));
    
    // 3. Monthly Top Products
    register_rest_route('ims/v1', '/monthly-top-products', array(
        'methods' => 'GET',
        'callback' => 'get_monthly_top_products',
        'permission_callback' => '__return_true',
    ));
    
    // 4. Monthly Top Customers
    register_rest_route('ims/v1', '/monthly-top-customers', array(
        'methods' => 'GET',
        'callback' => 'get_monthly_top_customers',
        'permission_callback' => '__return_true',
    ));
});

/**
 * API 1: Monthly Product Sales Report
 * GET /wp-json/ims/v1/monthly-product-sales?year=2026&month=1&limit=50
 */
function get_monthly_product_sales_report($request) {
    global $wpdb;
    
    $year = $request->get_param('year');
    $month = $request->get_param('month');
    $limit = $request->get_param('limit') ?: 100;
    $offset = $request->get_param('offset') ?: 0;
    
    $where = "1=1";
    if ($year) {
        $where .= $wpdb->prepare(" AND year = %d", $year);
    }
    if ($month) {
        $where .= $wpdb->prepare(" AND month = %d", $month);
    }
    
    $query = "SELECT * FROM vw_monthly_product_sales_report 
              WHERE {$where} 
              ORDER BY year DESC, month DESC, total_revenue DESC 
              LIMIT %d OFFSET %d";
    
    $results = $wpdb->get_results($wpdb->prepare($query, $limit, $offset));
    
    if ($wpdb->last_error) {
        return new WP_Error('db_error', $wpdb->last_error, array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $results,
        'count' => count($results),
        'params' => array(
            'year' => $year,
            'month' => $month,
            'limit' => $limit,
            'offset' => $offset
        )
    ));
}

/**
 * API 2: Monthly Customer Purchase Report
 * GET /wp-json/ims/v1/monthly-customer-purchases?year=2025&month=12
 */
function get_monthly_customer_purchase_report($request) {
    global $wpdb;
    
    $year = $request->get_param('year');
    $month = $request->get_param('month');
    $customer_type = $request->get_param('customer_type');
    $limit = $request->get_param('limit') ?: 100;
    $offset = $request->get_param('offset') ?: 0;
    
    $where = "1=1";
    if ($year) {
        $where .= $wpdb->prepare(" AND year = %d", $year);
    }
    if ($month) {
        $where .= $wpdb->prepare(" AND month = %d", $month);
    }
    if ($customer_type) {
        $where .= $wpdb->prepare(" AND customer_type = %s", $customer_type);
    }
    
    $query = "SELECT * FROM vw_monthly_customer_purchase_report 
              WHERE {$where} 
              ORDER BY year DESC, month DESC, total_purchase_value DESC 
              LIMIT %d OFFSET %d";
    
    $results = $wpdb->get_results($wpdb->prepare($query, $limit, $offset));
    
    if ($wpdb->last_error) {
        return new WP_Error('db_error', $wpdb->last_error, array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $results,
        'count' => count($results)
    ));
}

/**
 * API 3: Monthly Top Products
 * GET /wp-json/ims/v1/monthly-top-products?year=2026&month=1&limit=10
 */
function get_monthly_top_products($request) {
    global $wpdb;
    
    $year = $request->get_param('year');
    $month = $request->get_param('month');
    $category = $request->get_param('category');
    $limit = $request->get_param('limit') ?: 10;
    
    $where = "1=1";
    if ($year) {
        $where .= $wpdb->prepare(" AND year = %d", $year);
    }
    if ($month) {
        $where .= $wpdb->prepare(" AND month = %d", $month);
    }
    if ($category) {
        $where .= $wpdb->prepare(" AND category_name = %s", $category);
    }
    
    $query = "SELECT * FROM vw_monthly_top_products 
              WHERE {$where} 
              ORDER BY year DESC, month DESC, total_revenue DESC 
              LIMIT %d";
    
    $results = $wpdb->get_results($wpdb->prepare($query, $limit));
    
    if ($wpdb->last_error) {
        return new WP_Error('db_error', $wpdb->last_error, array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $results,
        'count' => count($results)
    ));
}

/**
 * API 4: Monthly Top Customers
 * GET /wp-json/ims/v1/monthly-top-customers?year=2026&month=1&limit=10
 */
function get_monthly_top_customers($request) {
    global $wpdb;
    
    $year = $request->get_param('year');
    $month = $request->get_param('month');
    $customer_type = $request->get_param('customer_type');
    $limit = $request->get_param('limit') ?: 10;
    
    $where = "1=1";
    if ($year) {
        $where .= $wpdb->prepare(" AND year = %d", $year);
    }
    if ($month) {
        $where .= $wpdb->prepare(" AND month = %d", $month);
    }
    if ($customer_type) {
        $where .= $wpdb->prepare(" AND customer_type = %s", $customer_type);
    }
    
    $query = "SELECT * FROM vw_monthly_top_customers 
              WHERE {$where} 
              ORDER BY year DESC, month DESC, total_purchase_value DESC 
              LIMIT %d";
    
    $results = $wpdb->get_results($wpdb->prepare($query, $limit));
    
    if ($wpdb->last_error) {
        return new WP_Error('db_error', $wpdb->last_error, array('status' => 500));
    }
    
    return rest_ensure_response(array(
        'success' => true,
        'data' => $results,
        'count' => count($results)
    ));
}

