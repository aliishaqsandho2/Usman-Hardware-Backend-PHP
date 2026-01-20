<?php
// Customer Insights.php functionality here

// Register REST API endpoint for updating customer credit
add_action('rest_api_init', function () {
    register_rest_route('ims/v1', '/customers/(?P<id>\d+)/credit', array(
        'methods' => 'POST',
        'callback' => 'ims_update_customer_credit',
        'args' => array(
            'id' => array(
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                }
            ),
            'credit_limit' => array(
                'required' => false,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param >= 0;
                }
            ),
            'current_balance' => array(
                'required' => false,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param >= 0;
                }
            )
        )
    ));
});

// Callback to handle customer credit update
function ims_update_customer_credit(WP_REST_Request $request) {
    global $wpdb;
    
    $customer_id = $request->get_param('id');
    $credit_limit = $request->get_param('credit_limit');
    $current_balance = $request->get_param('current_balance');

    // Check if customer exists
    $customer = $wpdb->get_row(
        $wpdb->prepare("SELECT id FROM {$wpdb->prefix}ims_customers WHERE id = %d", $customer_id)
    );

    if (!$customer) {
        return new WP_Error('no_customer', 'Customer not found', array('status' => 404));
    }

    // Validate that current_balance does not exceed credit_limit if both are provided
    if (!is_null($credit_limit) && !is_null($current_balance) && $current_balance > $credit_limit) {
        return new WP_Error('invalid_balance', 'Current balance cannot exceed credit limit', array('status' => 400));
    }

    // Prepare data to update
    $data = array();
    $format = array();

    if (!is_null($credit_limit)) {
        $data['credit_limit'] = $credit_limit;
        $format[] = '%f';
    }

    if (!is_null($current_balance)) {
        $data['current_balance'] = $current_balance;
        $format[] = '%f';
    }

    if (empty($data)) {
        return new WP_Error('no_data', 'No data provided to update', array('status' => 400));
    }

    // Update the customer record
    $updated = $wpdb->update(
        "{$wpdb->prefix}ims_customers",
        $data,
        array('id' => $customer_id),
        $format,
        array('%d')
    );

    if ($updated === false) {
        return new WP_Error('update_failed', 'Failed to update customer credit', array('status' => 500));
    }

    // Fetch updated customer data
    $updated_customer = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, name, credit_limit, current_balance, (credit_limit - current_balance) AS available_credit
             FROM {$wpdb->prefix}ims_customers
             WHERE id = %d",
            $customer_id
        ),
        ARRAY_A
    );

    return rest_ensure_response(array(
        'status' => 'success',
        'data' => $updated_customer
    ));
}