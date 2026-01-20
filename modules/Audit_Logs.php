<?php
/**
 * Audit Log API Endpoints for WordPress
 * Provides comprehensive access to audit trail data
 * 
 * Base URL: /wp-json/ims/v1/audit-logs
 */

// Register REST API routes
add_action('rest_api_init', 'ims_register_audit_log_routes');

function ims_register_audit_log_routes() {
    // GET /audit-logs - Get all audit logs with filtering and pagination
    register_rest_route('ims/v1', '/audit-logs', array(
        'methods' => 'GET',
        'callback' => 'ims_get_audit_logs',
        'permission_callback' => '__return_true'
    ));

    // GET /audit-logs/:id - Get single audit log entry
    register_rest_route('ims/v1', '/audit-logs/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_audit_log',
        'permission_callback' => '__return_true',
        'args' => array(
            'id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

    // GET /audit-logs/record/:table/:id - Get audit history for specific record
    register_rest_route('ims/v1', '/audit-logs/record/(?P<table>[a-zA-Z_]+)/(?P<id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_record_audit_history',
        'permission_callback' => '__return_true',
        'args' => array(
            'table' => array(
                'validate_callback' => function($param, $request, $key) {
                    return preg_match('/^[a-zA-Z_]+$/', $param);
                }
            ),
            'id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

    // GET /audit-logs/user/:user_id - Get audit logs for specific user
    register_rest_route('ims/v1', '/audit-logs/user/(?P<user_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'ims_get_user_audit_logs',
        'permission_callback' => '__return_true',
        'args' => array(
            'user_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));

    // GET /audit-logs/stats - Get audit log statistics
    register_rest_route('ims/v1', '/audit-logs/stats', array(
        'methods' => 'GET',
        'callback' => 'ims_get_audit_stats',
        'permission_callback' => '__return_true'
    ));

    // GET /audit-logs/tables - Get list of audited tables
    register_rest_route('ims/v1', '/audit-logs/tables', array(
        'methods' => 'GET',
        'callback' => 'ims_get_audited_tables',
        'permission_callback' => '__return_true'
    ));

    // GET /audit-logs/users - Get list of users who made changes
    register_rest_route('ims/v1', '/audit-logs/users', array(
        'methods' => 'GET',
        'callback' => 'ims_get_audit_users',
        'permission_callback' => '__return_true'
    ));
}

/**
 * GET /audit-logs - Get all audit logs with filtering and pagination
 * 
 * Query Parameters:
 * - page: Page number (default: 1)
 * - limit: Items per page (default: 20, max: 100)
 * - table: Filter by table name
 * - action: Filter by action (INSERT, UPDATE, DELETE)
 * - user_id: Filter by user ID
 * - user_login: Filter by username
 * - record_id: Filter by record ID (requires table parameter)
 * - date_from: Filter from date (YYYY-MM-DD)
 * - date_to: Filter to date (YYYY-MM-DD)
 * - search: Search in table_name, user_login, or record_id
 * - sortBy: Sort field (created_at, table_name, action, user_login)
 * - sortOrder: Sort order (asc, desc) - default: desc
 */
function ims_get_audit_logs($request) {
    global $wpdb;
    
    // Get parameters
    $page = intval($request->get_param('page')) ?: 1;
    $limit = min(intval($request->get_param('limit')) ?: 20, 100); // Max 100 items
    $table = sanitize_text_field($request->get_param('table'));
    $action = sanitize_text_field($request->get_param('action'));
    $user_id = $request->get_param('user_id') ? intval($request->get_param('user_id')) : null;
    $user_login = sanitize_text_field($request->get_param('user_login'));
    $record_id = $request->get_param('record_id') ? intval($request->get_param('record_id')) : null;
    $date_from = sanitize_text_field($request->get_param('date_from'));
    $date_to = sanitize_text_field($request->get_param('date_to'));
    $search = sanitize_text_field($request->get_param('search'));
    $sort_by = sanitize_text_field($request->get_param('sortBy')) ?: 'created_at';
    $sort_order = sanitize_text_field($request->get_param('sortOrder')) ?: 'desc';
    
    $offset = ($page - 1) * $limit;
    
    // Build WHERE conditions
    $where_conditions = array();
    $params = array();
    
    if ($table) {
        $where_conditions[] = "table_name = %s";
        $params[] = $table;
    }
    
    if ($action && in_array(strtoupper($action), array('INSERT', 'UPDATE', 'DELETE'))) {
        $where_conditions[] = "action = %s";
        $params[] = strtoupper($action);
    }
    
    if ($user_id !== null) {
        $where_conditions[] = "user_id = %d";
        $params[] = $user_id;
    }
    
    if ($user_login) {
        $where_conditions[] = "user_login = %s";
        $params[] = $user_login;
    }
    
    if ($record_id !== null && $table) {
        $where_conditions[] = "record_id = %d";
        $params[] = $record_id;
    }
    
    if ($date_from) {
        $where_conditions[] = "DATE(created_at) >= %s";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_conditions[] = "DATE(created_at) <= %s";
        $params[] = $date_to;
    }
    
    if ($search) {
        $where_conditions[] = "(table_name LIKE %s OR user_login LIKE %s OR CAST(record_id AS CHAR) LIKE %s)";
        $search_term = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Validate sort fields
    $allowed_sort_fields = array('created_at', 'table_name', 'action', 'user_login', 'record_id');
    if (!in_array($sort_by, $allowed_sort_fields)) {
        $sort_by = 'created_at';
    }
    
    $sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
    
    // Main query
    $query = "
        SELECT 
            id,
            table_name,
            record_id,
            action,
            user_id,
            user_login,
            old_data,
            new_data,
            changed_fields,
            ip_address,
            user_agent,
            created_at
        FROM {$wpdb->prefix}ims_audit_log
        $where_clause
        ORDER BY $sort_by $sort_order
        LIMIT %d OFFSET %d
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $audit_logs = $wpdb->get_results($wpdb->prepare($query, $params));
    
    // Count query for pagination
    $count_query = "
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}ims_audit_log
        $where_clause
    ";
    
    $count_params = array_slice($params, 0, -2); // Remove limit and offset
    $total_items = $wpdb->get_var(!empty($count_params) ? $wpdb->prepare($count_query, $count_params) : $count_query);
    
    // Format audit logs
    $formatted_logs = array();
    foreach ($audit_logs as $log) {
        $formatted_logs[] = ims_format_audit_log($log);
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'logs' => $formatted_logs,
            'pagination' => array(
                'currentPage' => $page,
                'totalPages' => ceil($total_items / $limit),
                'totalItems' => intval($total_items),
                'itemsPerPage' => $limit
            )
        )
    ), 200);
}

/**
 * GET /audit-logs/:id - Get single audit log entry
 */
function ims_get_audit_log($request) {
    global $wpdb;
    
    $log_id = intval($request['id']);
    
    $log = $wpdb->get_row($wpdb->prepare("
        SELECT *
        FROM {$wpdb->prefix}ims_audit_log
        WHERE id = %d
    ", $log_id));
    
    if (!$log) {
        return new WP_Error('log_not_found', 'Audit log entry not found', array('status' => 404));
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => ims_format_audit_log($log)
    ), 200);
}

/**
 * GET /audit-logs/record/:table/:id - Get audit history for specific record
 */
function ims_get_record_audit_history($request) {
    global $wpdb;
    
    $table = sanitize_text_field($request['table']);
    $record_id = intval($request['id']);
    
    // Get parameters
    $page = intval($request->get_param('page')) ?: 1;
    $limit = min(intval($request->get_param('limit')) ?: 50, 100);
    $offset = ($page - 1) * $limit;
    
    // Get audit history
    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT *
        FROM {$wpdb->prefix}ims_audit_log
        WHERE table_name = %s AND record_id = %d
        ORDER BY created_at DESC
        LIMIT %d OFFSET %d
    ", $table, $record_id, $limit, $offset));
    
    // Get total count
    $total_items = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->prefix}ims_audit_log
        WHERE table_name = %s AND record_id = %d
    ", $table, $record_id));
    
    // Format logs
    $formatted_logs = array();
    foreach ($logs as $log) {
        $formatted_logs[] = ims_format_audit_log($log);
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'table' => $table,
            'recordId' => $record_id,
            'history' => $formatted_logs,
            'pagination' => array(
                'currentPage' => $page,
                'totalPages' => ceil($total_items / $limit),
                'totalItems' => intval($total_items),
                'itemsPerPage' => $limit
            )
        )
    ), 200);
}

/**
 * GET /audit-logs/user/:user_id - Get audit logs for specific user
 */
function ims_get_user_audit_logs($request) {
    global $wpdb;
    
    $user_id = intval($request['user_id']);
    
    // Get parameters
    $page = intval($request->get_param('page')) ?: 1;
    $limit = min(intval($request->get_param('limit')) ?: 20, 100);
    $offset = ($page - 1) * $limit;
    
    // Get user info
    $user = get_userdata($user_id);
    if (!$user) {
        return new WP_Error('user_not_found', 'User not found', array('status' => 404));
    }
    
    // Get audit logs
    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT *
        FROM {$wpdb->prefix}ims_audit_log
        WHERE user_id = %d
        ORDER BY created_at DESC
        LIMIT %d OFFSET %d
    ", $user_id, $limit, $offset));
    
    // Get total count
    $total_items = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->prefix}ims_audit_log
        WHERE user_id = %d
    ", $user_id));
    
    // Get activity summary
    $activity_summary = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COUNT(*) as total_actions,
            COUNT(CASE WHEN action = 'INSERT' THEN 1 END) as inserts,
            COUNT(CASE WHEN action = 'UPDATE' THEN 1 END) as updates,
            COUNT(CASE WHEN action = 'DELETE' THEN 1 END) as deletes,
            MIN(created_at) as first_action,
            MAX(created_at) as last_action
        FROM {$wpdb->prefix}ims_audit_log
        WHERE user_id = %d
    ", $user_id));
    
    // Format logs
    $formatted_logs = array();
    foreach ($logs as $log) {
        $formatted_logs[] = ims_format_audit_log($log);
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'user' => array(
                'id' => $user_id,
                'login' => $user->user_login,
                'email' => $user->user_email,
                'displayName' => $user->display_name
            ),
            'summary' => array(
                'totalActions' => intval($activity_summary->total_actions),
                'inserts' => intval($activity_summary->inserts),
                'updates' => intval($activity_summary->updates),
                'deletes' => intval($activity_summary->deletes),
                'firstAction' => $activity_summary->first_action,
                'lastAction' => $activity_summary->last_action
            ),
            'logs' => $formatted_logs,
            'pagination' => array(
                'currentPage' => $page,
                'totalPages' => ceil($total_items / $limit),
                'totalItems' => intval($total_items),
                'itemsPerPage' => $limit
            )
        )
    ), 200);
}

/**
 * GET /audit-logs/stats - Get audit log statistics
 */
function ims_get_audit_stats($request) {
    global $wpdb;
    
    $days = intval($request->get_param('days')) ?: 30;
    
    // Overall statistics
    $overall_stats = $wpdb->get_row("
        SELECT 
            COUNT(*) as total_entries,
            COUNT(CASE WHEN action = 'INSERT' THEN 1 END) as total_inserts,
            COUNT(CASE WHEN action = 'UPDATE' THEN 1 END) as total_updates,
            COUNT(CASE WHEN action = 'DELETE' THEN 1 END) as total_deletes,
            COUNT(DISTINCT table_name) as tables_tracked,
            COUNT(DISTINCT user_id) as unique_users,
            MIN(created_at) as oldest_entry,
            MAX(created_at) as newest_entry
        FROM {$wpdb->prefix}ims_audit_log
    ");
    
    // Recent activity (last N days)
    $recent_stats = $wpdb->get_row($wpdb->prepare("
        SELECT 
            COUNT(*) as recent_entries,
            COUNT(CASE WHEN action = 'INSERT' THEN 1 END) as recent_inserts,
            COUNT(CASE WHEN action = 'UPDATE' THEN 1 END) as recent_updates,
            COUNT(CASE WHEN action = 'DELETE' THEN 1 END) as recent_deletes
        FROM {$wpdb->prefix}ims_audit_log
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
    ", $days));
    
    // Activity by table (top 10)
    $table_stats = $wpdb->get_results("
        SELECT 
            table_name,
            COUNT(*) as change_count,
            COUNT(CASE WHEN action = 'INSERT' THEN 1 END) as inserts,
            COUNT(CASE WHEN action = 'UPDATE' THEN 1 END) as updates,
            COUNT(CASE WHEN action = 'DELETE' THEN 1 END) as deletes
        FROM {$wpdb->prefix}ims_audit_log
        GROUP BY table_name
        ORDER BY change_count DESC
        LIMIT 10
    ");
    
    // Activity by user (top 10)
    $user_stats = $wpdb->get_results("
        SELECT 
            user_login,
            user_id,
            COUNT(*) as action_count,
            COUNT(CASE WHEN action = 'INSERT' THEN 1 END) as inserts,
            COUNT(CASE WHEN action = 'UPDATE' THEN 1 END) as updates,
            COUNT(CASE WHEN action = 'DELETE' THEN 1 END) as deletes
        FROM {$wpdb->prefix}ims_audit_log
        WHERE user_login != 'system'
        GROUP BY user_login, user_id
        ORDER BY action_count DESC
        LIMIT 10
    ");
    
    // Daily activity (last 7 days)
    $daily_activity = $wpdb->get_results("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total_actions,
            COUNT(CASE WHEN action = 'INSERT' THEN 1 END) as inserts,
            COUNT(CASE WHEN action = 'UPDATE' THEN 1 END) as updates,
            COUNT(CASE WHEN action = 'DELETE' THEN 1 END) as deletes
        FROM {$wpdb->prefix}ims_audit_log
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date DESC
    ");
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'overall' => array(
                'totalEntries' => intval($overall_stats->total_entries),
                'totalInserts' => intval($overall_stats->total_inserts),
                'totalUpdates' => intval($overall_stats->total_updates),
                'totalDeletes' => intval($overall_stats->total_deletes),
                'tablesTracked' => intval($overall_stats->tables_tracked),
                'uniqueUsers' => intval($overall_stats->unique_users),
                'oldestEntry' => $overall_stats->oldest_entry,
                'newestEntry' => $overall_stats->newest_entry
            ),
            'recent' => array(
                'days' => $days,
                'entries' => intval($recent_stats->recent_entries),
                'inserts' => intval($recent_stats->recent_inserts),
                'updates' => intval($recent_stats->recent_updates),
                'deletes' => intval($recent_stats->recent_deletes)
            ),
            'byTable' => array_map(function($stat) {
                return array(
                    'tableName' => $stat->table_name,
                    'changeCount' => intval($stat->change_count),
                    'inserts' => intval($stat->inserts),
                    'updates' => intval($stat->updates),
                    'deletes' => intval($stat->deletes)
                );
            }, $table_stats),
            'byUser' => array_map(function($stat) {
                return array(
                    'userId' => intval($stat->user_id),
                    'userLogin' => $stat->user_login,
                    'actionCount' => intval($stat->action_count),
                    'inserts' => intval($stat->inserts),
                    'updates' => intval($stat->updates),
                    'deletes' => intval($stat->deletes)
                );
            }, $user_stats),
            'dailyActivity' => array_map(function($day) {
                return array(
                    'date' => $day->date,
                    'totalActions' => intval($day->total_actions),
                    'inserts' => intval($day->inserts),
                    'updates' => intval($day->updates),
                    'deletes' => intval($day->deletes)
                );
            }, $daily_activity)
        )
    ), 200);
}

/**
 * GET /audit-logs/tables - Get list of audited tables
 */
function ims_get_audited_tables($request) {
    global $wpdb;
    
    $tables = $wpdb->get_results("
        SELECT 
            table_name,
            COUNT(*) as entry_count,
            MIN(created_at) as first_entry,
            MAX(created_at) as last_entry
        FROM {$wpdb->prefix}ims_audit_log
        GROUP BY table_name
        ORDER BY table_name ASC
    ");
    
    $formatted_tables = array();
    foreach ($tables as $table) {
        $formatted_tables[] = array(
            'tableName' => $table->table_name,
            'entryCount' => intval($table->entry_count),
            'firstEntry' => $table->first_entry,
            'lastEntry' => $table->last_entry
        );
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'tables' => $formatted_tables,
            'totalTables' => count($formatted_tables)
        )
    ), 200);
}

/**
 * GET /audit-logs/users - Get list of users who made changes
 */
function ims_get_audit_users($request) {
    global $wpdb;
    
    $users = $wpdb->get_results("
        SELECT 
            user_id,
            user_login,
            COUNT(*) as action_count,
            MIN(created_at) as first_action,
            MAX(created_at) as last_action
        FROM {$wpdb->prefix}ims_audit_log
        GROUP BY user_id, user_login
        ORDER BY action_count DESC
    ");
    
    $formatted_users = array();
    foreach ($users as $user) {
        $formatted_users[] = array(
            'userId' => intval($user->user_id),
            'userLogin' => $user->user_login,
            'actionCount' => intval($user->action_count),
            'firstAction' => $user->first_action,
            'lastAction' => $user->last_action
        );
    }
    
    return new WP_REST_Response(array(
        'success' => true,
        'data' => array(
            'users' => $formatted_users,
            'totalUsers' => count($formatted_users)
        )
    ), 200);
}

/**
 * Helper function to format audit log entry
 */
function ims_format_audit_log($log) {
    // Parse JSON fields
    $old_data = null;
    $new_data = null;
    $changed_fields = null;
    
    if ($log->old_data) {
        $old_data = json_decode($log->old_data, true);
    }
    
    if ($log->new_data) {
        $new_data = json_decode($log->new_data, true);
    }
    
    if ($log->changed_fields) {
        $changed_fields = json_decode($log->changed_fields, true);
    }
    
    return array(
        'id' => intval($log->id),
        'tableName' => $log->table_name,
        'recordId' => intval($log->record_id),
        'action' => $log->action,
        'userId' => $log->user_id ? intval($log->user_id) : null,
        'userLogin' => $log->user_login,
        'oldData' => $old_data,
        'newData' => $new_data,
        'changedFields' => $changed_fields,
        'ipAddress' => $log->ip_address,
        'userAgent' => $log->user_agent,
        'createdAt' => $log->created_at
    );
}
