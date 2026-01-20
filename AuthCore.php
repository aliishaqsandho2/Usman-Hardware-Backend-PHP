<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils.php';

class AuthCore {
    private $db;
    private $currentUser = null;
    private $currentSession = null;
    private $isAuthenticated = false;

    public function __construct() {
        global $wpdb;
        $this->db = $wpdb;
    }

    /**
     * Authenticate User (Login)
     */
    public function login($username_or_email, $password, $device_info = []) {
        // 1. Get User
        $user = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->db->prefix}users WHERE (username = %s OR email = %s) AND status != 'deleted'",
            $username_or_email, $username_or_email
        ));

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        if (!$user) {
            $this->log_attempt(null, $username_or_email, $username_or_email, false, 'User not found', $ip, $user_agent);
            return new WP_Error('invalid_credentials', 'Invalid username or password', ['status' => 401]);
        }
        
        // 2. Check Lockout
        if ($user->status === 'locked') {
             // Check if lockout has expired (though the event scheduler should handle this, let's duplicate logic for immediate feedback)
             if (strtotime($user->account_locked_until) > time()) {
                 return new WP_Error('account_locked', 'Account is locked. Try again later.', ['status' => 403]);
             }
        }

        // 3. Verify Password
        if (!password_verify($password, $user->password_hash)) {
            $this->log_attempt($user->id, $username_or_email, $username_or_email, false, 'Invalid password', $ip, $user_agent);
            return new WP_Error('invalid_credentials', 'Invalid username or password', ['status' => 401]);
        }

        // 4. Check Status (suspended, inactive)
        if ($user->status === 'suspended' || $user->status === 'inactive') {
             $this->log_attempt($user->id, $username_or_email, $username_or_email, false, 'Account ' . $user->status, $ip, $user_agent);
             return new WP_Error('account_inactive', 'Account is ' . $user->status, ['status' => 403]);
        }

        // 5. MFA Check
        if ($user->mfa_enabled) {
            // If MFA enabled, return temporary token or require MFA verification step
            // For now, let's assume direct login if MFA not fully implemented in frontend, 
            // BUT schema has MFA, so we should support it.
            // Simplified: return 'mfa_required' response with a temp session or require 2nd step.
            // Let's implement full login for now, and note MFA TODO.
            // Or better: Create a session but mark `mfa_verified` as false.
        }

        // 6. Create Session
        $session_token = bin2hex(random_bytes(32));
        $refresh_token = bin2hex(random_bytes(32));
        $expires_at = date('Y-m-d H:i:s', time() + (8 * 3600)); // 8 hours

        $this->db->insert(
            "{$this->db->prefix}ims_user_sessions",
            [
                'user_id' => $user->id,
                'session_token' => $session_token,
                'refresh_token' => $refresh_token,
                'ip_address' => $ip,
                'user_agent' => $user_agent,
                'device_type' => $device_info['type'] ?? 'desktop',
                'device_name' => $device_info['name'] ?? 'Unknown',
                'device_id' => $device_info['id'] ?? 'unknown',
                'is_active' => 1,
                'mfa_verified' => $user->mfa_enabled ? 0 : 1, // If MFA enabled, mark unverified
                'expires_at' => $expires_at,
                'last_activity' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
        );
        
        $session_id = $this->db->insert_id;

        // 7. Log Success
        $this->log_attempt($user->id, $username_or_email, $username_or_email, true, 'Login successful', $ip, $user_agent);

        // Update User Last Login
        $this->db->update(
            "{$this->db->prefix}users",
            ['last_login' => current_time('mysql'), 'last_login_ip' => $ip],
            ['id' => $user->id]
        );

        // Return Data
        return [
            'token' => $session_token,
            'refresh_token' => $refresh_token,
            'user' => [
                'id' => (int)$user->id,
                'username' => $user->username,
                'email' => $user->email,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'role' => $this->get_user_role_name($user->id),
                'permissions' => $this->get_user_permissions_list($user->id),
                'mfaRequired' => (bool)$user->mfa_enabled,
                'mfaVerified' => !$user->mfa_enabled // If not enabled, it's verified by default
            ],
            'expires_at' => $expires_at
        ];
    }
    
    /**
     * Validate Session from Token
     */
    public function validate_session($token) {
        if (empty($token)) return false;

        $query = "CALL sp_validate_session(%s)";
        // Since my DB wrapper might not handle stored procedure result sets perfectly if they return multiple,
        // Let's use get_row. sp_validate_session returns a single row.
        // NOTE: Standard mysqli query handling for stored procs can be tricky with next_result().
        // Let's try raw query or just reimplement the SELECT logic here for stability if needed.
        // But let's try calling the SP first as requested.
        
        // ISSUE: DB helper prepare might check quotes.
        // Let's rely on manual query for SP call to ensure we process result set.
        
        $sql = "CALL sp_validate_session('" . $this->db->mysqli->real_escape_string($token) . "')";
        
        // We need to use multi_query or just query, but we must clear results.
        // My DB helper `get_row` uses `query`.
        // Let's just run the SELECT directly to avoid SP complexity with the custom DB wrapper for now, 
        // OR try the SP. Let's stick to the user request "utilize... functionality".
        // I'll try the SP. If it fails due to the wrapper, I'll fix the wrapper.
        
        $result = $this->db->get_row($sql);
        
        // Clean up any extra result sets from SP
        while($this->db->mysqli->more_results()) {
            $this->db->mysqli->next_result();
        }

        if ($result && $result->validation_status === 'valid') {
            $this->currentUser = $this->get_user_by_id($result->user_id);
            $this->currentSession = $result;
            $this->isAuthenticated = true;
            
            // Set global current user for compatibility
            global $current_user_obj;
            $current_user_obj = $this->currentUser;
            
            return true;
        }
        
        return false;
    }

    /**
     * Check Permission
     */
    public function check_permission($permission) {
        if (!$this->isAuthenticated || !$this->currentUser) return false;
        
        // Super Admin Bypass
        if ($this->has_role('super_admin')) return true;

        $user_id = $this->currentUser->id;
        // Use SP
        $sql = $this->db->prepare("CALL sp_check_user_permission(%d, %s)", $user_id, $permission);
        $result = $this->db->get_row($sql);
        
         // Clean up
        while($this->db->mysqli->more_results()) {
            $this->db->mysqli->next_result();
        }
        
        return ($result && $result->has_permission);
    }
    
    public function has_role($role_name) {
         if (!$this->isAuthenticated || !$this->currentUser) return false;
         $roles = $this->get_user_roles($this->currentUser->id);
         return in_array($role_name, $roles);
    }

    /**
     * Logout
     */
    public function logout($token) {
        $this->db->update(
            "{$this->db->prefix}ims_user_sessions",
            ['is_active' => 0, 'terminated_at' => current_time('mysql')],
            ['session_token' => $token]
        );
    }

    /**
     * Get Current User
     */
    public function get_current_user() {
        return $this->currentUser;
    }
    
    // --- Helpers ---

    private function log_attempt($user_id, $username, $email, $success, $reason, $ip, $agent) {
         // CALL sp_log_login_attempt(user_id, username, email, success, reason, ip, agent)
         // Handling booleans in SP call
         $succ_int = $success ? 1 : 0;
         $sql = $this->db->prepare(
             "CALL sp_log_login_attempt(%d, %s, %s, %d, %s, %s, %s)",
             $user_id, $username, $email, $succ_int, $reason, $ip, $agent
         );
         $this->db->query($sql);
         while($this->db->mysqli->more_results()) { $this->db->mysqli->next_result(); }
    }

    private function get_user_by_id($id) {
        return $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->db->prefix}users WHERE id = %d", $id
        ));
    }
    
    private function get_user_roles($user_id) {
        // Simple query
        $results = $this->db->get_col($this->db->prepare(
            "SELECT r.name FROM {$this->db->prefix}ims_roles r 
             JOIN {$this->db->prefix}ims_user_roles ur ON r.id = ur.role_id 
             WHERE ur.user_id = %d", $user_id
        ));
        return $results;
    }
    
    private function get_user_role_name($user_id) {
         $roles = $this->get_user_roles($user_id);
         return !empty($roles) ? $roles[0] : 'viewer';
    }
    
    private function get_user_permissions_list($user_id) {
        // Use sp_get_user_permissions
        $sql = $this->db->prepare("CALL sp_get_user_permissions(%d)", $user_id);
        $results = $this->db->get_results($sql);
        while($this->db->mysqli->more_results()) { $this->db->mysqli->next_result(); }
        
        $perms = [];
        foreach($results as $r) {
            $perms[] = $r->name;
        }
        return $perms;
    }
}

// Global instance
global $auth;
$auth = new AuthCore();
