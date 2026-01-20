<?php

require_once 'utils.php';
require_once 'db.php';

// Global Router
class Router {
    private $routes = [];

    public function add($namespace, $route, $args) {
        // Namespace "ims/v1", Route "/products" -> full regex
        // Convert route params like (?P<id>\d+) to regex if needed, or just use as is if it's already regex-compatible (WP uses regex).
        // WP routes are regex-based.
        
        $full_route = '/' . trim($namespace, '/') . '/' . ltrim($route, '/');
        
        // Ensure consistent structure
        if (isset($args['callback'])) {
            $this->routes[] = [
                'regex' => $full_route,
                'methods' => $args['methods'] ?? 'GET',
                'callback' => $args['callback'],
                'args' => $args['args'] ?? []
            ];
        } else {
            // Array of endpoints (methods) for same route
            foreach ($args as $endpoint) {
                if (isset($endpoint['callback'])) {
                    $this->routes[] = [
                        'regex' => $full_route,
                        'methods' => $endpoint['methods'] ?? 'GET',
                        'callback' => $endpoint['callback'],
                        'args' => $endpoint['args'] ?? []
                    ];
                }
            }
        }
    }

    public function dispatch() {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'];
        
        // Remove script name if running in subdir without rewrite (e.g. /standalone_app/index.php/ims/v1...)
        // But assuming we rely on .htaccess or direct access.
        // Let's assume URI is full path. If we are in /standalone_app, we might need to strip that.
        // For now, naive matching.
        
        // Workaround for subdirectory:
        $script_dir = dirname($_SERVER['SCRIPT_NAME']);
        if ($script_dir !== '/' && strpos($uri, $script_dir) === 0) {
            $uri = substr($uri, strlen($script_dir));
        }
        
        // Debug
        // error_log("Dispatching URI: $uri");

        // Global Auth Check (Middleware)
        global $auth;
        $headers = getallheaders();
        if (isset($headers['Authorization']) && preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $auth->validate_session($matches[1]);
        }
        
        foreach ($this->routes as $route) {
            // WP Routes are regexes usually, e.g. /products/(?P<id>\d+)
            // We need to enclose in delimiters.
            // Note: WP register_rest_route often passes just the route string which is concatenated with namespace.
            // Regex: $namespace . $route
            
            $pattern = '@^' . $route['regex'] . '$@'; // simplified
            // WP uses more complex matching but let's try this.
            
            if (preg_match($pattern, $uri, $matches)) {
                // Check method
                $allowed_methods = explode(',', $route['methods']); // "GET, POST"
                // Trim
                $allowed_methods = array_map('trim', $allowed_methods);
                
                if (in_array($method, $allowed_methods)) {
                    // Prepare Params
                    $request = new WP_REST_Request($method, $uri);
                    
                    // Add URL params (named regex groups)
                    foreach ($matches as $key => $value) {
                        if (is_string($key)) {
                            $request->set_param($key, $value);
                        }
                    }
                    
                    // Call callback
                    $callback = $route['callback'];
                    if (is_callable($callback)) {
                        $response = call_user_func($callback, $request);
                    } else {
                        // Sometimes reference by string name of function
                        if (function_exists($callback)) {
                            $response = $callback($request);
                        } else {
                             $this->send_response(['error' => 'Callback not found'], 500);
                             return;
                        }
                    }
                    
                    // Handle Response
                    if (is_wp_error($response)) {
                         $this->send_response([
                             'code' => $response->get_error_code(),
                             'message' => $response->get_error_message(),
                             'data' => $response->get_error_data()
                         ], $response->get_error_data()['status'] ?? 400);
                    } else if ($response instanceof WP_REST_Response) {
                        $this->send_response($response->get_data(), $response->get_status());
                    } else {
                        $this->send_response($response);
                    }
                    return;
                }
            }
        }
        
        $this->send_response(['code' => 'route_not_found', 'message' => 'Route not found'], 404);
    }
    
    private function send_response($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        echo json_encode($data);
        exit;
    }
}

$router = new Router();

/**
 * WP Compatibility Functions
 */

/**
 * WP Compatibility Functions
 */
function register_rest_route($namespace, $route, $args) {
    global $router;
    $router->add($namespace, $route, $args);
}

function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    if ($hook === 'rest_api_init' || $hook === 'init') {
        if (is_callable($callback)) {
            call_user_func($callback);
        } else if (function_exists($callback)) {
            $callback();
        }
    }
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    // No-op
}

function remove_filter($tag, $function_to_remove, $priority = 10) {
    // No-op
}


// Initialize Auth
require_once __DIR__ . '/AuthCore.php';

function is_user_logged_in() {
    global $auth;
    // Check headers/session if not already checked
    // We expect middleware to run before this is called in business logic.
    // Ideally, we run middleware at dispatch time.
    // For is_user_logged_in to work anywhere, we might need to lazy load validation.
    
    // Check if we have a current user;
    if ($auth->get_current_user()) return true;
    
    // Attempt validation from headers now?
    $headers = getallheaders();
    $token = null;
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            $token = $matches[1];
        }
    }
    return $auth->validate_session($token);
}

function wp_get_current_user() {
    global $auth;
    $user = $auth->get_current_user();
    if ($user) return $user;
    
    // Return empty user object if not logged in
    return (object) ['ID' => 0, 'user_login' => '', 'roles' => []];
}

function current_user_can($permission) {
    global $auth;
    return $auth->check_permission($permission);
}

function get_site_url() {
    return APP_URL;
}

function home_url($path = '') {
    return APP_URL . $path;
}

function wp_remote_post($url, $args) {
    return ['body' => json_encode(['token' => 'mock_token'])];
}

function wp_remote_retrieve_body($response) {
    return $response['body'];
}

function is_wp_error($thing) {
    return ($thing instanceof WP_Error);
}

function current_time($type, $gmt = 0) {
    return ($type == 'mysql') ? date('Y-m-d H:i:s') : time();
}

function rest_ensure_response($response) {
    if ($response instanceof WP_REST_Response) {
        return $response;
    }
    if (is_wp_error($response)) {
        return $response;
    }
    return new WP_REST_Response($response);
}

function get_option($option, $default = false) {
    global $wpdb;
    $table = $wpdb->prefix . 'options'; // uh_options
    // Check if table exists is too heavy? No, just run query.
    // If table differs, we might need configuration. Assuming uh_options.
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $table WHERE option_name = %s", $option));
    if ($row) {
        return maybe_unserialize($row->option_value);
    }
    return $default;
}

function update_option($option, $value, $autoload = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'options';
    $serialized_value = maybe_serialize($value);
    
    $exists = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM $table WHERE option_name = %s", $option));
    
    if ($exists) {
        $wpdb->update($table, ['option_value' => $serialized_value], ['option_name' => $option]);
    } else {
        $wpdb->insert($table, ['option_name' => $option, 'option_value' => $serialized_value, 'autoload' => 'yes']);
    }
    return true;
}

function maybe_unserialize($original) {
    if (is_serialized($original)) { 
        return @unserialize($original);
    }
    return $original;
}

function maybe_serialize($data) {
    if (is_array($data) || is_object($data)) return serialize($data);
    return $data;
}


function is_serialized($data, $strict = true) {
    if (!is_string($data)) return false;
    $data = trim($data);
    if ('N;' === $data) return true;
    if (strlen($data) < 4) return false;
    if (':' !== $data[1]) return false;
    if ($strict) {
        $lastc = substr($data, -1);
        if (';' !== $lastc && '}' !== $lastc) return false;
    }
    $token = $data[0];
    switch ($token) {
        case 's': case 'a': case 'O':
            return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
        case 'b': case 'i': case 'd':
            return (bool) preg_match("/^{$token}:[0-9.E+-]+;/s", $data);
    }
    return false;
}

function absint($maybeint) {
    return abs(intval($maybeint));
}



// Load Modules
$modules_dir = __DIR__ . '/modules/';
foreach (glob($modules_dir . '*.php') as $file) {
    require_once $file;
}

// Dispatch
$router->dispatch();
