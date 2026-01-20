<?php

require_once 'config.php';

class DB {
    public $mysqli;
    public $prefix = 'uh_'; // As seen in user sql output (uh_ims_...)
    public $last_error = '';
    public $insert_id = 0;

    public function __construct() {
        $this->mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        if ($this->mysqli->connect_error) {
            die("Connection failed: " . $this->mysqli->connect_error);
        }
        $this->mysqli->set_charset(DB_CHARSET);
    }

    public function prepare($query, ...$args) {
        if (empty($args)) {
            return $query;
        }

        // If args is an array in the first position (common WP usage: prepare($sql, $args))
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        // Handle WP style placeholders: %s, %d, %f
        // This is a naive implementation.
        // It replaces the first occurrence of a placeholder with the escaped value.
        // NOTE: WP's prepare is complex. Simple vsprintf might work if we escape first.
        
        $escaped_args = [];
        foreach ($args as $arg) {
            if (is_int($arg)) {
                $escaped_args[] = $arg;
            } elseif (is_float($arg)) {
                $escaped_args[] = $arg;
            } elseif ($arg === null) {
                $escaped_args[] = 'NULL'; 
            } else {
                $escaped_args[] = "'" . $this->mysqli->real_escape_string((string)$arg) . "'";
            }
        }
        
        // We can't easily use vsprintf because WP syntax is slightly different (%d means unquoted integer, %s means quoted string in standard SQL but in WP it handles the quoting? No wait.)
        // WP prepare: "SELECT * FROM table WHERE id = %d AND name = %s"
        // $wpdb->prepare($sql, 1, 'foo') -> SELECT * FROM table WHERE id = 1 AND name = 'foo'
        
        // My simple prepare strategy: replace %s with '%s', %d with %d, etc, then use vsprintf?
        // Actually, WP expectation is that input string contains %d, %s, %f.
        // And we should inject the SCALAR values properly formatted and escaped.
        
        
        // BETTER APPROACH: Custom replacement loop to handle it safely.
        
        $sql = $query;
        $parts = preg_split('/(%[sdfF])/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $final_sql = '';
        $arg_index = 0;
        
        foreach ($parts as $part) {
            if ($part === '%s') {
                if (isset($args[$arg_index])) {
                   $final_sql .= "'" . $this->mysqli->real_escape_string((string)$args[$arg_index]) . "'";
                } else {
                   $final_sql .= "''"; // Fallback
                }
                $arg_index++;
            } elseif ($part === '%d' || $part === '%f' || $part === '%F') {
                 if (isset($args[$arg_index])) {
                     $val = $args[$arg_index];
                     if (!is_numeric($val)) $val = 0; // WP behavior
                     $final_sql .= $val;
                 } else {
                     $final_sql .= '0';
                 }
                 $arg_index++;
            } else {
                $final_sql .= $part;
            }
        }
        
        return $final_sql;
    }

    public function get_results($query, $output = 'OBJECT') {
        $result = $this->mysqli->query($query);
        if (!$result) {
            $this->last_error = $this->mysqli->error;
            return [];
        }
        
        $rows = [];
        while ($row = $result->fetch_object()) {
            $rows[] = $row;
        }
        $result->free();
        
        if ($output == 'ARRAY_A') {
            return json_decode(json_encode($rows), true);
        }
        
        return $rows;
    }

    public function get_row($query) {
        $results = $this->get_results($query);
        return !empty($results) ? $results[0] : null;
    }

    public function get_var($query) {
        $result = $this->mysqli->query($query);
        if (!$result || $result->num_rows == 0) return null;
        $row = $result->fetch_array(MYSQLI_NUM);
        return $row[0];
    }
    
    public function get_col($query) {
        $results = $this->mysqli->query($query);
        if (!$results) return [];
        $col = [];
        while($row = $results->fetch_array(MYSQLI_NUM)) {
            $col[] = $row[0];
        }
        return $col;
    }

    public function insert($table, $data, $format = null) {
        $cols = [];
        $vals = [];
        
        foreach ($data as $key => $value) {
            $cols[] = "`$key`";
            $vals[] = "'" . $this->mysqli->real_escape_string((string)$value) . "'";
        }
        
        $col_str = implode(',', $cols);
        $val_str = implode(',', $vals);
        
        $query = "INSERT INTO $table ($col_str) VALUES ($val_str)";
        
        if ($this->mysqli->query($query)) {
            $this->insert_id = $this->mysqli->insert_id;
            return $this->insert_id;
        } else {
            $this->last_error = $this->mysqli->error;
            return false;
        }
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $set = [];
        foreach ($data as $key => $value) {
            $val = "'" . $this->mysqli->real_escape_string((string)$value) . "'";
            $set[] = "`$key` = $val";
        }
        
        $where_clauses = [];
        foreach ($where as $key => $value) {
            $val = "'" . $this->mysqli->real_escape_string((string)$value) . "'";
            $where_clauses[] = "`$key` = $val";
        }
        
        $query = "UPDATE $table SET " . implode(',', $set) . " WHERE " . implode(' AND ', $where_clauses);
        
        return $this->mysqli->query($query);
    }
    
    public function delete($table, $where, $where_format = null) {
        $where_clauses = [];
        foreach ($where as $key => $value) {
            $val = "'" . $this->mysqli->real_escape_string((string)$value) . "'";
            $where_clauses[] = "`$key` = $val";
        }
        
        $query = "DELETE FROM $table WHERE " . implode(' AND ', $where_clauses);
        
        return $this->mysqli->query($query);
    }
    
    public function query($query) {
        $query = trim($query);
        // Specialized handling for Transactions
        if ($query === 'START TRANSACTION') return $this->mysqli->begin_transaction();
        if ($query === 'COMMIT') return $this->mysqli->commit();
        if ($query === 'ROLLBACK') return $this->mysqli->rollback();
        
        return $this->mysqli->query($query);
    }
    
    public function esc_like($text) {
        return addcslashes($this->mysqli->real_escape_string($text), '%_');
    }
}

// Global instance as standard in WP
$wpdb = new DB();
