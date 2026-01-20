<?php
/**
 * Employee Management API for WordPress
 * REST API endpoints for employee management system
 */

// Register REST API routes
add_action('rest_api_init', 'ims_register_employee_routes');

function ims_register_employee_routes() {
    // GET all employees with filters
    register_rest_route('ims/v1', '/employees', array(
        array(
            'methods' => 'GET',
            'callback' => 'ims_get_employees',
            'permission_callback' => '__return_true',
        )
    ));
	// GET all employees with filters
    register_rest_route('ims/v1', '/employees', array(
        
        array(
            'methods' => 'POST',
            'callback' => 'ims_create_employee',
            'permission_callback' => '__return_true',
        ),
    ));
    
    // GET, PUT, DELETE employee by ID
    register_rest_route('ims/v1', '/employees/(?P<id>\d+)', array(
        array(
            'methods' => 'GET',
            'callback' => 'ims_get_employee',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods' => 'PUT',
            'callback' => 'ims_update_employee',
            'permission_callback' => '__return_true',
        ),
        array(
            'methods' => 'DELETE',
            'callback' => 'ims_delete_employee',
            'permission_callback' => '__return_true',
        ),
    ));
    
    // GET employee statistics
    register_rest_route('ims/v1', '/employees/statistics', array(
        array(
            'methods' => 'GET',
            'callback' => 'ims_get_employee_statistics',
            'permission_callback' => '__return_true',
        ),
    ));
    
    // PUT update employee status
    register_rest_route('ims/v1', '/employees/(?P<id>\d+)/status', array(
        array(
            'methods' => 'PUT',
            'callback' => 'ims_update_employee_status',
            'permission_callback' => '__return_true',
        ),
    ));
    
    // GET employees by department
    register_rest_route('ims/v1', '/employees/department/(?P<department>[a-zA-Z0-9-]+)', array(
        array(
            'methods' => 'GET',
            'callback' => 'ims_get_employees_by_department',
            'permission_callback' => '__return_true',
        ),
    ));
}

// Helper function to get table name
function ims_get_employees_table() {
    global $wpdb;
    return $wpdb->prefix . 'ims_employees';
}

function ims_get_status_history_table() {
    global $wpdb;
    return $wpdb->prefix . 'ims_employee_status_history';
}

// Helper function to send success response
function ims_send_success_response($data = null, $message = '', $status_code = 200, $pagination = null) {
    $response = array('success' => true);
    if ($data !== null) $response['data'] = $data;
    if ($message) $response['message'] = $message;
    if ($pagination) $response['pagination'] = $pagination;
    return new WP_REST_Response($response, $status_code);
}

// Helper function to send error response
function ims_send_error_response($message, $status_code = 400) {
    return new WP_REST_Response(array(
        'success' => false,
        'message' => $message
    ), $status_code);
}

// Helper function to format employee data
function ims_format_employee_data($employee) {
    return array(
        'id' => intval($employee['id']),
        'name' => $employee['name'],
        'email' => $employee['email'],
        'phone' => $employee['phone'],
        'position' => $employee['position'],
        'department' => $employee['department'],
        'salary' => floatval($employee['salary']),
        'joinDate' => $employee['join_date'],
        'status' => $employee['status'],
        'address' => $employee['address'],
        'experience' => $employee['experience'],
        'avatar' => $employee['avatar'],
        'created_at' => $employee['created_at'],
        'updated_at' => $employee['updated_at']
    );
}

// 1. GET All Employees
function ims_get_employees($request) {
    global $wpdb;
    $table_name = ims_get_employees_table();
    
    $params = $request->get_params();
    $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
    $limit = isset($params['limit']) ? max(1, intval($params['limit'])) : 10;
    $offset = ($page - 1) * $limit;
    
    $where_conditions = array('1=1');
    $query_params = array();
    
    // Department filter
    if (!empty($params['department'])) {
        $where_conditions[] = 'department = %s';
        $query_params[] = sanitize_text_field($params['department']);
    }
    
    // Status filter
    if (!empty($params['status']) && in_array($params['status'], array('active', 'inactive', 'on_leave'))) {
        $where_conditions[] = 'status = %s';
        $query_params[] = sanitize_text_field($params['status']);
    }
    
    // Search filter
    if (!empty($params['search'])) {
        $search_term = '%' . $wpdb->esc_like(sanitize_text_field($params['search'])) . '%';
        $where_conditions[] = '(name LIKE %s OR email LIKE %s OR position LIKE %s)';
        $query_params[] = $search_term;
        $query_params[] = $search_term;
        $query_params[] = $search_term;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Count total records
    $count_query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
    if (!empty($query_params)) {
        $count_query = $wpdb->prepare($count_query, $query_params);
    }
    $total_records = $wpdb->get_var($count_query);
    $total_pages = ceil($total_records / $limit);
    
    // Get employees data
    $data_query = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY id DESC LIMIT %d OFFSET %d";
    $data_params = array_merge($query_params, array($limit, $offset));
    $data_query = $wpdb->prepare($data_query, $data_params);
    
    $employees = $wpdb->get_results($data_query, ARRAY_A);
    
    // Format response
    $formatted_employees = array();
    foreach ($employees as $employee) {
        $formatted_employees[] = ims_format_employee_data($employee);
    }
    
    $pagination = array(
        'currentPage' => $page,
        'totalPages' => $total_pages,
        'totalRecords' => intval($total_records),
        'limit' => $limit
    );
    
    return ims_send_success_response($formatted_employees, '', 200, $pagination);
}

// 2. GET Employee by ID
function ims_get_employee($request) {
    global $wpdb;
    $table_name = ims_get_employees_table();
    
    $employee_id = intval($request['id']);
    
    $employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d", $employee_id
    ), ARRAY_A);
    
    if (!$employee) {
        return ims_send_error_response('Employee not found', 404);
    }
    
    $formatted_employee = ims_format_employee_data($employee);
    return ims_send_success_response($formatted_employee);
}

// 3. POST Create Employee
function ims_create_employee($request) {
    global $wpdb;
    $table_name = ims_get_employees_table();
    
    $params = $request->get_params();
    
    // Validate required fields
    $required_fields = array('name', 'email', 'position', 'department');
    foreach ($required_fields as $field) {
        if (empty($params[$field])) {
            return ims_send_error_response("Field '{$field}' is required");
        }
    }
    
    // Check if email already exists
    $existing_employee = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE email = %s", sanitize_email($params['email'])
    ));
    
    if ($existing_employee) {
        return ims_send_error_response('Email already exists');
    }
    
    // Prepare employee data
    $employee_data = array(
        'name' => sanitize_text_field($params['name']),
        'email' => sanitize_email($params['email']),
        'phone' => isset($params['phone']) ? sanitize_text_field($params['phone']) : '',
        'position' => sanitize_text_field($params['position']),
        'department' => sanitize_text_field($params['department']),
        'salary' => isset($params['salary']) ? floatval($params['salary']) : 0,
        'join_date' => current_time('Y-m-d'),
        'status' => isset($params['status']) && in_array($params['status'], array('active', 'inactive', 'on_leave')) 
                    ? sanitize_text_field($params['status']) : 'active',
        'address' => isset($params['address']) ? sanitize_textarea_field($params['address']) : '',
        'experience' => isset($params['experience']) ? sanitize_text_field($params['experience']) : '',
        'avatar' => isset($params['avatar']) ? esc_url_raw($params['avatar']) : null,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    
    // Insert employee
    $result = $wpdb->insert($table_name, $employee_data);
    
    if (!$result) {
        return ims_send_error_response('Failed to create employee');
    }
    
    $employee_id = $wpdb->insert_id;
    $new_employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d", $employee_id
    ), ARRAY_A);
    
    $formatted_employee = ims_format_employee_data($new_employee);
    return ims_send_success_response($formatted_employee, 'Employee created successfully', 201);
}

// 4. PUT Update Employee
function ims_update_employee($request) {
    global $wpdb;
    $table_name = ims_get_employees_table();
    
    $employee_id = intval($request['id']);
    $params = $request->get_params();
    
    // Check if employee exists
    $existing_employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d", $employee_id
    ), ARRAY_A);
    
    if (!$existing_employee) {
        return ims_send_error_response('Employee not found', 404);
    }
    
    // Check if email already exists (excluding current employee)
    if (!empty($params['email'])) {
        $email_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE email = %s AND id != %d", 
            sanitize_email($params['email']), $employee_id
        ));
        
        if ($email_exists) {
            return ims_send_error_response('Email already exists');
        }
    }
    
    // Prepare update data
    $update_data = array();
    $allowed_fields = array('name', 'email', 'phone', 'position', 'department', 
                           'salary', 'address', 'experience', 'status');
    
    foreach ($allowed_fields as $field) {
        if (isset($params[$field])) {
            if ($field === 'email') {
                $update_data[$field] = sanitize_email($params[$field]);
            } elseif ($field === 'salary') {
                $update_data[$field] = floatval($params[$field]);
            } elseif ($field === 'address') {
                $update_data[$field] = sanitize_textarea_field($params[$field]);
            } else {
                $update_data[$field] = sanitize_text_field($params[$field]);
            }
        }
    }
    
    $update_data['updated_at'] = current_time('mysql');
    
    // Update employee
    $result = $wpdb->update($table_name, $update_data, array('id' => $employee_id));
    
    if ($result === false) {
        return ims_send_error_response('Failed to update employee');
    }
    
    $updated_employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE id = %d", $employee_id
    ), ARRAY_A);
    
    $formatted_employee = ims_format_employee_data($updated_employee);
    return ims_send_success_response($formatted_employee, 'Employee updated successfully');
}

// 5. DELETE Employee
function ims_delete_employee($request) {
    global $wpdb;
    $table_name = ims_get_employees_table();
    
    $employee_id = intval($request['id']);
    
    // Check if employee exists
    $existing_employee = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table_name} WHERE id = %d", $employee_id
    ));
    
    if (!$existing_employee) {
        return ims_send_error_response('Employee not found', 404);
    }
    
    // Delete employee
    $result = $wpdb->delete($table_name, array('id' => $employee_id));
    
    if (!$result) {
        return ims_send_error_response('Failed to delete employee');
    }
    
    return ims_send_success_response(array('deleted' => true), 'Employee deleted successfully');
}

// 6. GET Employee Statistics
function ims_get_employee_statistics($request) {
    global $wpdb;
    $table_name = ims_get_employees_table();
    
    // Basic statistics
    $total_employees = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    $active_employees = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'active'");
    $inactive_employees = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'inactive'");
    $on_leave_employees = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE status = 'on_leave'");
    
    $average_salary = $wpdb->get_var("SELECT AVG(salary) FROM {$table_name} WHERE salary > 0");
    $total_salary_cost = $wpdb->get_var("SELECT SUM(salary) FROM {$table_name} WHERE salary > 0");
    
    // Department statistics
    $department_stats = $wpdb->get_results("
        SELECT department, COUNT(*) as count, AVG(salary) as average_salary 
        FROM {$table_name} 
        WHERE department IS NOT NULL AND department != '' 
        GROUP BY department
    ", ARRAY_A);
    
    // Recent hires (last 30 days)
    $recent_hires = $wpdb->get_results("
        SELECT id, name, position, join_date 
        FROM {$table_name} 
        WHERE join_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
        ORDER BY join_date DESC 
        LIMIT 5
    ", ARRAY_A);
    
    $statistics = array(
        'total_employees' => intval($total_employees),
        'active_employees' => intval($active_employees),
        'inactive_employees' => intval($inactive_employees),
        'on_leave_employees' => intval($on_leave_employees),
        'average_salary' => round(floatval($average_salary), 2),
        'total_salary_cost' => round(floatval($total_salary_cost), 2),
        'departments' => $department_stats,
        'recent_hires' => $recent_hires
    );
    
    return ims_send_success_response($statistics);
}

// 7. PUT Update Employee Status
function ims_update_employee_status($request) {
    global $wpdb;
    $employees_table = ims_get_employees_table();
    $status_history_table = ims_get_status_history_table();
    
    $employee_id = intval($request['id']);
    $params = $request->get_params();
    
    // Validate required fields
    if (empty($params['status']) || !in_array($params['status'], array('active', 'inactive', 'on_leave'))) {
        return ims_send_error_response('Valid status is required (active, inactive, on_leave)');
    }
    
    // Check if employee exists
    $existing_employee = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$employees_table} WHERE id = %d", $employee_id
    ), ARRAY_A);
    
    if (!$existing_employee) {
        return ims_send_error_response('Employee not found', 404);
    }
    
    $new_status = sanitize_text_field($params['status']);
    $reason = isset($params['reason']) ? sanitize_textarea_field($params['reason']) : '';
    $effective_date = isset($params['effective_date']) ? sanitize_text_field($params['effective_date']) : current_time('Y-m-d');
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Record status change history
        $history_data = array(
            'employee_id' => $employee_id,
            'old_status' => $existing_employee['status'],
            'new_status' => $new_status,
            'reason' => $reason,
            'effective_date' => $effective_date,
            'created_at' => current_time('mysql')
        );
        
        $wpdb->insert($status_history_table, $history_data);
        
        // Update employee status
        $update_data = array(
            'status' => $new_status,
            'updated_at' => current_time('mysql')
        );
        
        $result = $wpdb->update($employees_table, $update_data, array('id' => $employee_id));
        
        if ($result === false) {
            throw new Exception('Failed to update employee status');
        }
        
        $wpdb->query('COMMIT');
        
        $response_data = array(
            'id' => $employee_id,
            'status' => $new_status,
            'updated_at' => current_time('mysql')
        );
        
        return ims_send_success_response($response_data, 'Employee status updated successfully');
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return ims_send_error_response('Failed to update employee status: ' . $e->getMessage());
    }
}

// 8. GET Employees by Department
function ims_get_employees_by_department($request) {
    global $wpdb;
    $table_name = ims_get_employees_table();
    
    $department = sanitize_text_field($request['department']);
    
    // Get department employees
    $employees = $wpdb->get_results($wpdb->prepare("
        SELECT id, name, position, salary, status 
        FROM {$table_name} 
        WHERE department = %s 
        ORDER BY name ASC
    ", $department), ARRAY_A);
    
    // Get department statistics
    $statistics = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count,
            AVG(salary) as average_salary,
            SUM(salary) as total_salary
        FROM {$table_name} 
        WHERE department = %s
    ", $department), ARRAY_A);
    
    $response_data = array(
        'department' => $department,
        'employees' => $employees,
        'statistics' => array(
            'total_count' => intval($statistics['total_count']),
            'active_count' => intval($statistics['active_count']),
            'inactive_count' => intval($statistics['inactive_count']),
            'average_salary' => round(floatval($statistics['average_salary']), 2),
            'total_salary' => round(floatval($statistics['total_salary']), 2)
        )
    );
    
    return ims_send_success_response($response_data);
}

?>