<?php

/**
 * WP_Error Emulation
 */
class WP_Error {
    public $errors = array();
    public $error_data = array();

    public function __construct($code = '', $message = '', $data = '') {
        if (empty($code)) {
            return;
        }
        $this->errors[$code][] = $message;
        if (!empty($data)) {
            $this->error_data[$code] = $data;
        }
    }

    public function get_error_code() {
        $codes = array_keys($this->errors);
        if (empty($codes)) {
            return '';
        }
        return $codes[0];
    }

    public function get_error_message($code = '') {
        if (empty($code)) {
            $code = $this->get_error_code();
        }
        if (isset($this->errors[$code])) {
            return $this->errors[$code][0];
        }
        return '';
    }
    
    public function get_error_data($code = '') {
        if (empty($code)) {
            $code = $this->get_error_code();
        }
        if (isset($this->error_data[$code])) {
            return $this->error_data[$code];
        }
        return null;
    }
}

/**
 * WP_REST_Response Emulation
 */
class WP_REST_Response {
    public $data;
    public $status = 200;

    public function __construct($data = null, $status = 200) {
        $this->data = $data;
        $this->status = $status;
    }

    public function get_data() {
        return $this->data;
    }
    
    public function set_data($data) {
        $this->data = $data;
    }

    public function get_status() {
        return $this->status;
    }
}

/**
 * Emulate WP Sanitization Functions
 */
function sanitize_text_field($str) {
    if (is_object($str) || is_array($str)) {
        return '';
    }
    $str = (string) $str;
    $filtered = strip_tags($str);
    $filtered = preg_replace('/[\r\n\t ]+/', ' ', $filtered);
    return trim($filtered);
}

function sanitize_textarea_field($str) {
    if (is_object($str) || is_array($str)) {
        return '';
    }
    $filtered = (string) $str;
    $filtered = strip_tags($filtered); // Typically WP keeps some, but for now strict
    return trim($filtered);
}

function esc_url_raw($url) {
    return filter_var($url, FILTER_SANITIZE_URL);
}

function wp_strip_all_tags($string, $remove_breaks = false) {
    $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string);
    $string = strip_tags($string);
    if ($remove_breaks) {
        $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
    }
    return trim($string);
}

function is_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Mock Request Class
 */
class WP_REST_Request implements ArrayAccess {
    private $params = [];
    private $json_params = [];
    private $method;
    private $route;

    public function __construct($method, $route) {
        $this->method = $method;
        $this->route = $route;
        $this->params = $_GET; // basic
        if ($method === 'POST' || $method === 'PUT') {
            $input = file_get_contents('php://input');
            $this->json_params = json_decode($input, true) ?: [];
            $this->params = array_merge($this->params, $_POST);
        }
    }

    public function get_param($key) {
        // Check route params first (injected by router)
        if (isset($this->params[$key])) return $this->params[$key];
        if (isset($this->json_params[$key])) return $this->json_params[$key];
        return null;
    }
    
    public function get_params() {
         return array_merge($this->params, $this->json_params);
    }

    public function get_json_params() {
        return $this->json_params;
    }

    public function set_param($key, $value) {
        $this->params[$key] = $value;
    }

    // ArrayAccess implementation
    public function offsetExists($offset): bool {
        return isset($this->params[$offset]) || isset($this->json_params[$offset]);
    }

    public function offsetGet($offset): mixed {
        return $this->get_param($offset);
    }

    public function offsetSet($offset, $value): void {
        $this->set_param($offset, $value);
    }

    public function offsetUnset($offset): void {
        unset($this->params[$offset]);
        unset($this->json_params[$offset]);
    }
}

// Allow $request['key'] access
class_alias('WP_REST_Request', 'Ims_Request_Base'); // workaround if needed, 
// To strictly support array access:
 
 
 // We will fix the Request class in the next tool call properly with ArrayAccess interface.
