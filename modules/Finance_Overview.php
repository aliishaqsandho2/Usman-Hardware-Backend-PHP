<?php
// Register all API endpoints
add_action('rest_api_init', function () {
    // Accounts Payable
    register_rest_route('ims/v1', '/finance/accounts-payable', array(
        'methods' => 'GET',
        'callback' => 'ims_get_accounts_payable',
        'permission_callback' => '__return_true'
    ));
    
    // Accounts Receivable
    register_rest_route('ims/v1', '/finance/accounts-receivable', array(
        'methods' => 'GET',
        'callback' => 'ims_get_accounts_receivable',
        'permission_callback' => '__return_true'
    ));
    
    // Get Payments
    register_rest_route('ims/v1', '/payments', array(
        'methods' => 'GET',
        'callback' => 'ims_get_payments',
        'permission_callback' => '__return_true'
    ));
    
    // Record Payment
    register_rest_route('ims/v1', '/payments', array(
        'methods' => 'POST',
        'callback' => 'ims_record_payment',
        'permission_callback' => '__return_true'
    ));
    
    // Get Cash Flow
    register_rest_route('ims/v1', '/finance/cash-flow', array(
        'methods' => 'GET',
        'callback' => 'ims_get_cash_flow',
        'permission_callback' => '__return_true'
    ));
    
    // Create Cash Flow
    register_rest_route('ims/v1', '/finance/cash-flow', array(
        'methods' => 'POST',
        'callback' => 'ims_create_cash_flow',
        'permission_callback' => '__return_true'
    ));
    
    // Financial Statements
    register_rest_route('ims/v1', '/finance/financial-statements', array(
        'methods' => 'GET',
        'callback' => 'ims_get_financial_statements',
        'permission_callback' => '__return_true'
    ));
    
    // Budget Data (GET/POST)
    register_rest_route('ims/v1', '/finance/budget', array(
        array(
            'methods' => 'GET',
            'callback' => 'ims_get_budget_data',
            'permission_callback' => '__return_true'
        ),
        array(
            'methods' => 'POST',
            'callback' => 'ims_manage_budget',
            'permission_callback' => '__return_true'
        )
    ));
});

// Utility functions
function ims_send_response($data, $status = 200) {
    return new WP_REST_Response(array(
        'success' => true,
        'data' => $data
    ), $status);
}

function ims_send_error($message, $status = 400) {
    return new WP_REST_Response(array(
        'success' => false,
        'error' => $message
    ), $status);
}

// API Functions
function ims_get_accounts_payable($request) {
    global $wpdb;
    
    try {
        $payables = $wpdb->get_results(
            "SELECT 
                po.id,
                po.order_number,
                po.date,
                po.expected_delivery,
                po.total,
                s.name as supplier_name,
                s.contact_person,
                s.phone,
                s.email,
                COALESCE(sp.paid_total, 0) as paid_amount,
                (po.total - COALESCE(sp.paid_total, 0)) as due_amount,
                DATEDIFF(CURDATE(), po.date) as days_outstanding,
                po.status
             FROM {$wpdb->prefix}ims_purchase_orders po
             INNER JOIN {$wpdb->prefix}ims_suppliers s ON po.supplier_id = s.id
             LEFT JOIN (
                 SELECT 
                     invoice_id,
                     SUM(allocated_amount) as paid_total
                 FROM {$wpdb->prefix}ims_payment_allocations 
                 WHERE invoice_type = 'purchase'
                 GROUP BY invoice_id
             ) sp ON po.id = sp.invoice_id
             WHERE po.status IN ('confirmed', 'received')
             AND po.total > COALESCE(sp.paid_total, 0)
             ORDER BY days_outstanding DESC, po.date ASC"
        );
        
        return ims_send_response($payables);
        
    } catch (Exception $e) {
        return ims_send_error('Error fetching accounts payable: ' . $e->getMessage());
    }
}

function ims_get_accounts_receivable($request) {
    global $wpdb;
    
    try {
        $receivables = $wpdb->get_results(
            "SELECT s.id, s.order_number, s.date, s.due_date, s.total, 
                    c.name as customer_name, c.phone, c.email,
                    COALESCE(pa.allocated_total, 0) as paid_amount,
                    (s.total - COALESCE(pa.allocated_total, 0)) as due_amount,
                    DATEDIFF(CURDATE(), s.due_date) as days_overdue
             FROM {$wpdb->prefix}ims_sales s 
             INNER JOIN {$wpdb->prefix}ims_customers c ON s.customer_id = c.id 
             LEFT JOIN (
                 SELECT invoice_id, SUM(allocated_amount) as allocated_total 
                 FROM {$wpdb->prefix}ims_payment_allocations 
                 WHERE invoice_type = 'sale' 
                 GROUP BY invoice_id
             ) pa ON s.id = pa.invoice_id 
             WHERE s.status = 'completed' 
             AND s.payment_method = 'credit'
             AND s.total > COALESCE(pa.allocated_total, 0)
             ORDER BY days_overdue DESC, s.due_date ASC"
        );
        
        return ims_send_response($receivables);
        
    } catch (Exception $e) {
        return ims_send_error('Error fetching accounts receivable: ' . $e->getMessage());
    }
}

function ims_get_payments($request) {
    global $wpdb;
    
    try {
        $results = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}ims_payments ORDER BY id DESC LIMIT 10");
        return ims_send_response($results);
        
    } catch (Exception $e) {
        return ims_send_error('Error fetching payments: ' . $e->getMessage());
    }
}

function ims_record_payment($request) {
    global $wpdb;
    
    try {
        $params = $request->get_params();
        
        $required = array('amount', 'payment_method', 'date', 'payment_type');
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return ims_send_error("Missing required field: {$field}");
            }
        }
        
        $wpdb->query('START TRANSACTION');
        
        $payment_data = array(
            'amount' => $params['amount'],
            'payment_method' => $params['payment_method'],
            'payment_type' => $params['payment_type'],
            'reference' => isset($params['reference']) ? $params['reference'] : '',
            'notes' => isset($params['notes']) ? $params['notes'] : '',
            'date' => $params['date'],
            'status' => isset($params['status']) ? $params['status'] : 'pending',
            'created_at' => current_time('mysql')
        );
        
        if ($params['payment_type'] === 'receipt' && !empty($params['customer_id'])) {
            $payment_data['customer_id'] = $params['customer_id'];
        } elseif ($params['payment_type'] === 'payment' && !empty($params['supplier_id'])) {
            $supplier_payment_id = $wpdb->insert(
                "{$wpdb->prefix}ims_supplier_payments",
                array(
                    'supplier_id' => $params['supplier_id'],
                    'amount' => $params['amount'],
                    'payment_method' => $params['payment_method'],
                    'reference' => isset($params['reference']) ? $params['reference'] : '',
                    'notes' => isset($params['notes']) ? $params['notes'] : '',
                    'date' => $params['date'],
                    'created_at' => current_time('mysql')
                ),
                array('%d', '%f', '%s', '%s', '%s', '%s', '%s')
            );
            
            if (!$supplier_payment_id) {
                $wpdb->query('ROLLBACK');
                return ims_send_error('Failed to create supplier payment');
            }
            
            $wpdb->query('COMMIT');
            return ims_send_response(array(
                'message' => 'Supplier payment recorded successfully',
                'payment_id' => $supplier_payment_id,
                'type' => 'supplier_payment'
            ), 201);
        }
        
        $payment_id = $wpdb->insert("{$wpdb->prefix}ims_payments", $payment_data);
        
        if (!$payment_id) {
            $wpdb->query('ROLLBACK');
            return ims_send_error('Failed to create payment record');
        }
        
        if (!empty($params['allocations']) && is_array($params['allocations'])) {
            foreach ($params['allocations'] as $allocation) {
                $wpdb->insert(
                    "{$wpdb->prefix}ims_payment_allocations",
                    array(
                        'payment_id' => $payment_id,
                        'invoice_id' => $allocation['invoice_id'],
                        'invoice_type' => $allocation['invoice_type'],
                        'allocated_amount' => $allocation['amount'],
                        'allocation_date' => $params['date'],
                        'created_at' => current_time('mysql')
                    )
                );
            }
        }
        
        $cash_flow_type = ($params['payment_type'] === 'receipt') ? 'inflow' : 'outflow';
        $wpdb->insert(
            "{$wpdb->prefix}ims_cash_flow",
            array(
                'type' => $cash_flow_type,
                'amount' => $params['amount'],
                'reference' => isset($params['reference']) ? $params['reference'] : 'Payment ' . $payment_id,
                'description' => isset($params['notes']) ? $params['notes'] : 'Payment recorded',
                'date' => $params['date'],
                'created_at' => current_time('mysql')
            )
        );
        
        $wpdb->query('COMMIT');
        
        return ims_send_response(array(
            'message' => 'Payment recorded successfully',
            'payment_id' => $payment_id,
            'type' => 'customer_payment'
        ), 201);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return ims_send_error('Error recording payment: ' . $e->getMessage());
    }
}

function ims_get_cash_flow($request) {
    global $wpdb;
    
    try {
        $params = $request->get_params();
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $per_page = isset($params['per_page']) ? max(1, intval($params['per_page'])) : 50;
        $offset = ($page - 1) * $per_page;
        
        $where_conditions = array('1=1');
        $query_params = array();
        
        if (!empty($params['type'])) {
            $where_conditions[] = 'cf.type = %s';
            $query_params[] = $params['type'];
        }
        
        if (!empty($params['date_from'])) {
            $where_conditions[] = 'cf.date >= %s';
            $query_params[] = $params['date_from'];
        }
        
        if (!empty($params['date_to'])) {
            $where_conditions[] = 'cf.date <= %s';
            $query_params[] = $params['date_to'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $cash_flow = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    cf.*,
                    a.account_name,
                    a.account_code,
                    p.reference as payment_reference,
                    c.name as customer_name,
                    s.name as supplier_name
                 FROM {$wpdb->prefix}ims_cash_flow cf
                 LEFT JOIN {$wpdb->prefix}ims_accounts a ON cf.account_id = a.id
                 LEFT JOIN {$wpdb->prefix}ims_payments p ON cf.transaction_id = p.id
                 LEFT JOIN {$wpdb->prefix}ims_customers c ON p.customer_id = c.id
                 LEFT JOIN {$wpdb->prefix}ims_supplier_payments sp ON cf.transaction_id = sp.id
                 LEFT JOIN {$wpdb->prefix}ims_suppliers s ON sp.supplier_id = s.id
                 WHERE {$where_clause}
                 ORDER BY cf.date DESC, cf.created_at DESC
                 LIMIT %d OFFSET %d",
                array_merge($query_params, array($per_page, $offset))
            )
        );
        
        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ims_cash_flow cf WHERE {$where_clause}",
                $query_params
            )
        );
        
        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    SUM(CASE WHEN type = 'inflow' THEN amount ELSE 0 END) as total_inflow,
                    SUM(CASE WHEN type = 'outflow' THEN amount ELSE 0 END) as total_outflow,
                    COUNT(*) as total_transactions
                 FROM {$wpdb->prefix}ims_cash_flow 
                 WHERE {$where_clause}",
                $query_params
            )
        );
        
        return ims_send_response(array(
            'transactions' => $cash_flow,
            'summary' => $summary,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => intval($total),
                'total_pages' => ceil($total / $per_page)
            )
        ));
        
    } catch (Exception $e) {
        return ims_send_error('Error fetching cash flow: ' . $e->getMessage());
    }
}

function ims_create_cash_flow($request) {
    global $wpdb;
    
    try {
        $params = $request->get_params();
        
        $required = array('type', 'amount', 'date');
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return ims_send_error("Missing required field: {$field}");
            }
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        // Check if account exists and Lock it (if account_id is provided)
        if (!empty($params['account_id'])) {
            $account = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ims_accounts WHERE id = %d FOR UPDATE",
                intval($params['account_id'])
            ));
            
            if (!$account) {
                $wpdb->query('ROLLBACK');
                return ims_send_error('Account not found');
            }
        }
        
        $cash_flow_id = $wpdb->insert(
            "{$wpdb->prefix}ims_cash_flow",
            array(
                'type' => $params['type'],
                'amount' => $params['amount'],
                'account_id' => isset($params['account_id']) ? $params['account_id'] : null,
                'reference' => isset($params['reference']) ? $params['reference'] : '',
                'description' => isset($params['description']) ? $params['description'] : '',
                'date' => $params['date'],
                'created_at' => current_time('mysql')
            ),
            array('%s', '%f', '%d', '%s', '%s', '%s', '%s')
        );
        
        if (!$cash_flow_id) {
            $wpdb->query('ROLLBACK');
            return ims_send_error('Failed to create cash flow entry');
        }

        // Update Account Balance if account exists
        if (!empty($params['account_id']) && isset($account)) {
            $current_balance = floatval($account->balance);
            $amount = floatval($params['amount']);
            
            if ($params['type'] === 'inflow') {
                $new_balance = $current_balance + $amount;
            } else {
                $new_balance = $current_balance - $amount;
            }

            $update_account = $wpdb->update(
                "{$wpdb->prefix}ims_accounts",
                array('balance' => $new_balance),
                array('id' => $account->id),
                array('%f'),
                array('%d')
            );

            if ($update_account === false) {
                $wpdb->query('ROLLBACK');
                return ims_send_error('Failed to update account balance');
            }
        }
        
        $wpdb->query('COMMIT');

        return ims_send_response(array(
            'message' => 'Cash flow entry created successfully',
            'cash_flow_id' => $cash_flow_id
        ), 201);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return ims_send_error('Error creating cash flow entry: ' . $e->getMessage());
    }
}


function ims_get_financial_statements($request) {
    global $wpdb;
    
    try {
        $params = $request->get_params();
        $date_from = isset($params['date_from']) ? $params['date_from'] : date('Y-m-01');
        $date_to = isset($params['date_to']) ? $params['date_to'] : date('Y-m-t');
        $statement_type = isset($params['type']) ? $params['type'] : 'all';
        
        $statements = array();
        
        if (in_array($statement_type, array('income', 'all'))) {
            $income_statement = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT 
                        SUM(s.total) as total_revenue,
                        SUM(ep.total) as cost_of_goods_sold,
                        SUM(s.total) - SUM(COALESCE(ep.total, 0)) as gross_profit,
                        SUM(e.amount) as total_expenses,
                        (SUM(s.total) - SUM(COALESCE(ep.total, 0)) - SUM(COALESCE(e.amount, 0))) as net_income
                     FROM {$wpdb->prefix}ims_sales s
                     LEFT JOIN {$wpdb->prefix}ims_external_purchases ep ON s.id = ep.sale_id
                     LEFT JOIN {$wpdb->prefix}ims_expenses e ON e.date BETWEEN %s AND %s
                     WHERE s.status = 'completed' 
                     AND s.date BETWEEN %s AND %s",
                    array($date_from, $date_to, $date_from, $date_to)
                )
            );
            
            $statements['income_statement'] = array(
                'period' => array('from' => $date_from, 'to' => $date_to),
                'revenue' => floatval($income_statement->total_revenue),
                'cost_of_goods_sold' => floatval($income_statement->cost_of_goods_sold),
                'gross_profit' => floatval($income_statement->gross_profit),
                'expenses' => floatval($income_statement->total_expenses),
                'net_income' => floatval($income_statement->net_income)
            );
        }
        
        if (in_array($statement_type, array('balance', 'all'))) {
            $assets = $wpdb->get_var(
                "SELECT COALESCE(SUM(balance), 0) 
                 FROM {$wpdb->prefix}ims_accounts 
                 WHERE account_type IN ('asset', 'bank', 'cash') AND is_active = 1"
            );
            
            $liabilities = $wpdb->get_var(
                "SELECT COALESCE(SUM(balance), 0) 
                 FROM {$wpdb->prefix}ims_accounts 
                 WHERE account_type = 'liability' AND is_active = 1"
            );
            
            $equity = $wpdb->get_var(
                "SELECT COALESCE(SUM(balance), 0) 
                 FROM {$wpdb->prefix}ims_accounts 
                 WHERE account_type = 'equity' AND is_active = 1"
            );
            
            $net_income = $statements['income_statement']['net_income'] ?? 0;
            $retained_earnings = $equity + $net_income;
            
            $statements['balance_sheet'] = array(
                'as_of_date' => $date_to,
                'assets' => array(
                    'current_assets' => floatval($assets),
                    'total_assets' => floatval($assets)
                ),
                'liabilities' => array(
                    'current_liabilities' => floatval($liabilities),
                    'total_liabilities' => floatval($liabilities)
                ),
                'equity' => array(
                    'retained_earnings' => floatval($retained_earnings),
                    'total_equity' => floatval($retained_earnings)
                ),
                'balance' => (floatval($assets) === (floatval($liabilities) + floatval($retained_earnings)))
            );
        }
        
        if (in_array($statement_type, array('cash_flow', 'all'))) {
            $cash_flow = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT 
                        SUM(CASE WHEN type = 'inflow' THEN amount ELSE 0 END) as operating_inflow,
                        SUM(CASE WHEN type = 'outflow' THEN amount ELSE 0 END) as operating_outflow,
                        SUM(CASE WHEN type = 'inflow' THEN amount ELSE -amount END) as net_cash_flow
                     FROM {$wpdb->prefix}ims_cash_flow 
                     WHERE date BETWEEN %s AND %s",
                    array($date_from, $date_to)
                )
            );
            
            $statements['cash_flow_statement'] = array(
                'period' => array('from' => $date_from, 'to' => $date_to),
                'operating_activities' => array(
                    'cash_inflows' => floatval($cash_flow->operating_inflow),
                    'cash_outflows' => floatval($cash_flow->operating_outflow),
                    'net_cash_flow' => floatval($cash_flow->net_cash_flow)
                ),
                'net_increase_in_cash' => floatval($cash_flow->net_cash_flow)
            );
        }
        
        return ims_send_response($statements);
        
    } catch (Exception $e) {
        return ims_send_error('Error generating financial statements: ' . $e->getMessage());
    }
}

function ims_get_budget_data($request) {
    global $wpdb;
    
    try {
        $params = $request->get_params();
        $year = isset($params['year']) ? $params['year'] : date('Y');
        $category = isset($params['category']) ? $params['category'] : null;
        
        $budget_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ims_budgets'");
        
        if (!$budget_table) {
            $monthly_actuals = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT 
                        DATE_FORMAT(date, '%Y-%m') as month,
                        SUM(amount) as actual_amount,
                        'expense' as category
                     FROM {$wpdb->prefix}ims_expenses 
                     WHERE YEAR(date) = %d
                     GROUP BY DATE_FORMAT(date, '%Y-%m')
                     ORDER BY month",
                    array($year)
                )
            );
            
            return ims_send_response(array(
                'budgets' => array(),
                'actuals' => $monthly_actuals,
                'message' => 'Budget table not found. Use POST to create budgets.'
            ));
        }
        
        $where_conditions = array('1=1');
        $query_params = array($year);
        
        if (!empty($category)) {
            $where_conditions[] = 'category = %s';
            $query_params[] = $category;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        $budgets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ims_budgets 
                 WHERE year = %d AND {$where_clause}
                 ORDER BY category, month",
                $query_params
            )
        );
        
        $actuals = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT 
                    category,
                    DATE_FORMAT(date, '%Y-%m') as month,
                    SUM(amount) as actual_amount
                 FROM {$wpdb->prefix}ims_expenses 
                 WHERE YEAR(date) = %d
                 GROUP BY category, DATE_FORMAT(date, '%Y-%m')
                 ORDER BY category, month",
                array($year)
            )
        );
        
        return ims_send_response(array(
            'budgets' => $budgets,
            'actuals' => $actuals,
            'year' => $year
        ));
        
    } catch (Exception $e) {
        return ims_send_error('Error fetching budget data: ' . $e->getMessage());
    }
}

function ims_manage_budget($request) {
    global $wpdb;
    
    try {
        $params = $request->get_params();
        
        $required = array('year', 'month', 'category', 'budget_amount');
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return ims_send_error("Missing required field: {$field}");
            }
        }
        
        $budget_table = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}ims_budgets'");
        
        if (!$budget_table) {
            $wpdb->query("
                CREATE TABLE {$wpdb->prefix}ims_budgets (
                    id bigint(20) NOT NULL AUTO_INCREMENT,
                    year int(4) NOT NULL,
                    month int(2) NOT NULL,
                    category varchar(100) NOT NULL,
                    budget_amount decimal(10,2) NOT NULL,
                    actual_amount decimal(10,2) DEFAULT 0.00,
                    variance decimal(10,2) DEFAULT 0.00,
                    created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                    updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY unique_budget (year, month, category)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
        
        $result = $wpdb->replace(
            "{$wpdb->prefix}ims_budgets",
            array(
                'year' => $params['year'],
                'month' => $params['month'],
                'category' => $params['category'],
                'budget_amount' => $params['budget_amount'],
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%f', '%s')
        );
        
        if ($result !== false) {
            return ims_send_response(array(
                'message' => 'Budget saved successfully',
                'action' => $result > 1 ? 'updated' : 'created'
            ), 201);
        } else {
            return ims_send_error('Failed to save budget');
        }
        
    } catch (Exception $e) {
        return ims_send_error('Error managing budget: ' . $e->getMessage());
    }
}

?>
