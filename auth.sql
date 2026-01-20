-- ============================================================================
-- SECURE AUTHENTICATION SYSTEM FOR INVENTORY MANAGEMENT
-- ============================================================================
-- Features:
-- 1. Multi-factor authentication (MFA)
-- 2. Password policies and history
-- 3. Session management with device tracking
-- 4. Role-based access control (RBAC) with granular permissions
-- 5. IP whitelisting/blacklisting
-- 6. Account lockout after failed attempts
-- 7. Password reset with secure tokens
-- 8. Activity logging and suspicious behavior detection
-- 9. API key management for integrations
-- 10. Single Sign-On (SSO) support ready
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE `ims_settings`;
DROP TABLE `uh_commentmeta`;
DROP TABLE `uh_comments`;
DROP TABLE `uh_options`;
DROP TABLE `uh_postmeta`;
DROP TABLE `uh_posts`;
DROP TABLE `uh_term_relationships`;
DROP TABLE `uh_term_taxonomy`;
DROP TABLE `uh_termmeta`;
DROP TABLE `uh_terms`;
DROP TABLE `uh_usermeta`;
DROP TABLE `uh_users`;

-- Drop existing authentication tables
DROP TABLE IF EXISTS uh_ims_user_sessions;
DROP TABLE IF EXISTS uh_ims_user_mfa;
DROP TABLE IF EXISTS uh_ims_user_password_history;
DROP TABLE IF EXISTS uh_ims_user_login_attempts;
DROP TABLE IF EXISTS uh_ims_user_permissions;
DROP TABLE IF EXISTS uh_ims_role_permissions;
DROP TABLE IF EXISTS uh_ims_permissions;
DROP TABLE IF EXISTS uh_ims_user_roles;
DROP TABLE IF EXISTS uh_ims_roles;
DROP TABLE IF EXISTS uh_ims_password_reset_tokens;
DROP TABLE IF EXISTS uh_ims_api_keys;
DROP TABLE IF EXISTS uh_ims_ip_whitelist;
DROP TABLE IF EXISTS uh_ims_ip_blacklist;
DROP TABLE IF EXISTS uh_ims_user_devices;
DROP TABLE IF EXISTS uh_ims_user_activity_log;
DROP TABLE IF EXISTS uh_users;

SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================================
-- 1. CORE USER TABLE
-- ============================================================================
CREATE TABLE uh_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  
  -- Basic Info
  username VARCHAR(60) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL UNIQUE,
  email_verified BOOLEAN DEFAULT FALSE,
  email_verified_at TIMESTAMP NULL,
  
  -- Password (hashed with bcrypt/argon2)
  password_hash VARCHAR(255) NOT NULL,
  password_changed_at TIMESTAMP NULL,
  password_expires_at TIMESTAMP NULL,
  require_password_change BOOLEAN DEFAULT FALSE,
  
  -- Personal Info
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NULL,
  phone_verified BOOLEAN DEFAULT FALSE,
  avatar_url VARCHAR(500) NULL,
  
  -- Account Status
  status ENUM('active', 'inactive', 'suspended', 'locked', 'pending_verification') 
    NOT NULL DEFAULT 'pending_verification',
  account_locked_until TIMESTAMP NULL,
  failed_login_attempts INT DEFAULT 0,
  last_failed_login TIMESTAMP NULL,
  
  -- Security Settings
  mfa_enabled BOOLEAN DEFAULT FALSE,
  mfa_enforced BOOLEAN DEFAULT FALSE,
  
  -- Session & Login Info
  last_login TIMESTAMP NULL,
  last_login_ip VARCHAR(45) NULL,
  last_login_device VARCHAR(255) NULL,
  
  -- Metadata
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by BIGINT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  deleted_at TIMESTAMP NULL,
  deleted_by BIGINT UNSIGNED NULL,
  
  PRIMARY KEY (id),
  INDEX idx_username (username),
  INDEX idx_email (email),
  INDEX idx_status (status),
  INDEX idx_created_at (created_at),
  INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 2. ROLES & PERMISSIONS (RBAC)
-- ============================================================================

-- Roles Table
CREATE TABLE uh_ims_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  is_system_role BOOLEAN DEFAULT FALSE, -- Cannot be deleted
  priority INT DEFAULT 0, -- Higher priority = more access
  status ENUM('active', 'inactive') DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_name (name),
  INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Permissions Table
CREATE TABLE uh_ims_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  module VARCHAR(50) NOT NULL, -- e.g., 'sales', 'inventory', 'reports'
  action VARCHAR(50) NOT NULL, -- e.g., 'create', 'read', 'update', 'delete'
  is_system_permission BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_name (name),
  INDEX idx_module (module),
  INDEX idx_module_action (module, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- User Roles (Many-to-Many)
CREATE TABLE uh_ims_user_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by BIGINT UNSIGNED NULL,
  expires_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_user_role (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES uh_ims_roles(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_role_id (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Role Permissions (Many-to-Many)
CREATE TABLE uh_ims_role_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_role_permission (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES uh_ims_roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES uh_ims_permissions(id) ON DELETE CASCADE,
  INDEX idx_role_id (role_id),
  INDEX idx_permission_id (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- User-specific permissions (override role permissions)
CREATE TABLE uh_ims_user_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  permission_type ENUM('grant', 'revoke') NOT NULL, -- Grant additional or revoke from role
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by BIGINT UNSIGNED NULL,
  expires_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_user_permission (user_id, permission_id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES uh_ims_permissions(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_permission_id (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 3. MULTI-FACTOR AUTHENTICATION (MFA)
-- ============================================================================
CREATE TABLE uh_ims_user_mfa (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  mfa_type ENUM('totp', 'sms', 'email', 'backup_codes') NOT NULL,
  
  -- TOTP (Time-based One-Time Password - Google Authenticator, etc.)
  totp_secret VARCHAR(255) NULL, -- Encrypted
  
  -- Backup codes
  backup_codes JSON NULL, -- Array of hashed backup codes
  
  -- SMS/Email
  phone_number VARCHAR(20) NULL,
  email_address VARCHAR(100) NULL,
  
  -- Status
  is_primary BOOLEAN DEFAULT FALSE,
  verified BOOLEAN DEFAULT FALSE,
  verified_at TIMESTAMP NULL,
  last_used_at TIMESTAMP NULL,
  
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_mfa_type (mfa_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 4. SESSION MANAGEMENT
-- ============================================================================
CREATE TABLE uh_ims_user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  
  -- Session identifiers
  session_token VARCHAR(255) NOT NULL UNIQUE,
  refresh_token VARCHAR(255) NULL UNIQUE,
  
  -- Device & Location Info
  ip_address VARCHAR(45) NOT NULL,
  user_agent TEXT NULL,
  device_type ENUM('desktop', 'mobile', 'tablet', 'api') DEFAULT 'desktop',
  device_name VARCHAR(255) NULL,
  device_id VARCHAR(255) NULL, -- Browser fingerprint or device ID
  
  -- Location (optional, can be populated via IP geolocation)
  country VARCHAR(100) NULL,
  city VARCHAR(100) NULL,
  
  -- Session Status
  is_active BOOLEAN DEFAULT TRUE,
  last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  -- Security flags
  is_suspicious BOOLEAN DEFAULT FALSE,
  mfa_verified BOOLEAN DEFAULT FALSE,
  
  -- Timestamps
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  terminated_at TIMESTAMP NULL,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_session_token (session_token),
  INDEX idx_is_active (is_active),
  INDEX idx_expires_at (expires_at),
  INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 5. LOGIN ATTEMPTS & SECURITY
-- ============================================================================
CREATE TABLE uh_ims_user_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL, -- NULL if user not found
  username_attempted VARCHAR(100) NULL,
  email_attempted VARCHAR(100) NULL,
  
  -- Attempt Info
  success BOOLEAN NOT NULL,
  failure_reason VARCHAR(255) NULL,
  
  -- Device & Location
  ip_address VARCHAR(45) NOT NULL,
  user_agent TEXT NULL,
  country VARCHAR(100) NULL,
  city VARCHAR(100) NULL,
  
  -- Risk Assessment
  risk_score INT DEFAULT 0, -- 0-100
  is_suspicious BOOLEAN DEFAULT FALSE,
  blocked BOOLEAN DEFAULT FALSE,
  
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE SET NULL,
  INDEX idx_user_id (user_id),
  INDEX idx_ip_address (ip_address),
  INDEX idx_attempted_at (attempted_at),
  INDEX idx_success (success),
  INDEX idx_suspicious (is_suspicious)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 6. PASSWORD SECURITY
-- ============================================================================

-- Password History (prevent reuse)
CREATE TABLE uh_ims_user_password_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Password Reset Tokens
CREATE TABLE uh_ims_password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token VARCHAR(255) NOT NULL UNIQUE,
  
  -- Security
  ip_address VARCHAR(45) NOT NULL,
  user_agent TEXT NULL,
  
  -- Status
  used BOOLEAN DEFAULT FALSE,
  used_at TIMESTAMP NULL,
  
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NOT NULL,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_token (token),
  INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 7. API KEY MANAGEMENT
-- ============================================================================
CREATE TABLE uh_ims_api_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  
  -- Key Info
  key_name VARCHAR(100) NOT NULL,
  api_key VARCHAR(255) NOT NULL UNIQUE,
  api_secret_hash VARCHAR(255) NOT NULL, -- Hashed secret
  
  -- Permissions & Scope
  scopes JSON NULL, -- Array of allowed permissions
  allowed_ips JSON NULL, -- Array of allowed IP addresses
  rate_limit INT DEFAULT 1000, -- Requests per hour
  
  -- Status
  is_active BOOLEAN DEFAULT TRUE,
  last_used_at TIMESTAMP NULL,
  last_used_ip VARCHAR(45) NULL,
  usage_count BIGINT DEFAULT 0,
  
  -- Timestamps
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  revoked_at TIMESTAMP NULL,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  INDEX idx_user_id (user_id),
  INDEX idx_api_key (api_key),
  INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 8. IP MANAGEMENT
-- ============================================================================

-- IP Whitelist (trusted IPs)
CREATE TABLE uh_ims_ip_whitelist (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL UNIQUE,
  ip_range VARCHAR(100) NULL, -- CIDR notation, e.g., 192.168.1.0/24
  description TEXT NULL,
  added_by BIGINT UNSIGNED NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  
  PRIMARY KEY (id),
  INDEX idx_ip_address (ip_address),
  INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- IP Blacklist (blocked IPs)
CREATE TABLE uh_ims_ip_blacklist (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL UNIQUE,
  reason TEXT NULL,
  threat_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
  auto_blocked BOOLEAN DEFAULT FALSE,
  blocked_by BIGINT UNSIGNED NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  unblocked_at TIMESTAMP NULL,
  
  PRIMARY KEY (id),
  INDEX idx_ip_address (ip_address),
  INDEX idx_is_active (is_active),
  INDEX idx_threat_level (threat_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 9. DEVICE TRACKING
-- ============================================================================
CREATE TABLE uh_ims_user_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  
  -- Device Identification
  device_id VARCHAR(255) NOT NULL, -- Browser fingerprint or device ID
  device_name VARCHAR(255) NULL,
  device_type ENUM('desktop', 'mobile', 'tablet', 'api') DEFAULT 'desktop',
  
  -- Device Info
  browser VARCHAR(100) NULL,
  os VARCHAR(100) NULL,
  user_agent TEXT NULL,
  
  -- Trust Status
  is_trusted BOOLEAN DEFAULT FALSE,
  trusted_at TIMESTAMP NULL,
  
  -- Activity
  first_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_ip VARCHAR(45) NULL,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_device (user_id, device_id),
  INDEX idx_user_id (user_id),
  INDEX idx_device_id (device_id),
  INDEX idx_is_trusted (is_trusted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 10. ACTIVITY LOGGING
-- ============================================================================
CREATE TABLE uh_ims_user_activity_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  session_id BIGINT UNSIGNED NULL,
  
  -- Activity Details
  activity_type ENUM('login', 'logout', 'password_change', 'profile_update', 
                     'permission_change', 'suspicious_activity', 'api_access',
                     'data_export', 'settings_change', 'other') NOT NULL,
  activity_description TEXT NULL,
  module VARCHAR(50) NULL,
  action VARCHAR(50) NULL,
  
  -- Context
  ip_address VARCHAR(45) NULL,
  user_agent TEXT NULL,
  request_method VARCHAR(10) NULL,
  request_url TEXT NULL,
  
  -- Security
  risk_score INT DEFAULT 0,
  is_suspicious BOOLEAN DEFAULT FALSE,
  
  -- Metadata
  metadata JSON NULL,
  
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (id),
  FOREIGN KEY (user_id) REFERENCES uh_users(id) ON DELETE SET NULL,
  FOREIGN KEY (session_id) REFERENCES uh_ims_user_sessions(id) ON DELETE SET NULL,
  INDEX idx_user_id (user_id),
  INDEX idx_activity_type (activity_type),
  INDEX idx_created_at (created_at),
  INDEX idx_suspicious (is_suspicious)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- 11. SECURITY POLICIES TABLE (Optional - for configurable security)
-- ============================================================================
CREATE TABLE uh_ims_security_policies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy_name VARCHAR(100) NOT NULL UNIQUE,
  policy_value TEXT NOT NULL,
  description TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NULL,
  
  PRIMARY KEY (id),
  INDEX idx_policy_name (policy_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- ============================================================================
-- INSERT DEFAULT SECURITY POLICIES
-- ============================================================================
INSERT INTO uh_ims_security_policies (policy_name, policy_value, description) VALUES
('password_min_length', '12', 'Minimum password length'),
('password_require_uppercase', 'true', 'Require uppercase letters'),
('password_require_lowercase', 'true', 'Require lowercase letters'),
('password_require_numbers', 'true', 'Require numbers'),
('password_require_special', 'true', 'Require special characters'),
('password_expiry_days', '90', 'Password expires after N days'),
('password_history_count', '5', 'Number of previous passwords to check'),
('max_login_attempts', '5', 'Max failed login attempts before lockout'),
('account_lockout_duration', '30', 'Account lockout duration in minutes'),
('session_timeout', '30', 'Session timeout in minutes'),
('session_absolute_timeout', '480', 'Absolute session timeout in minutes (8 hours)'),
('mfa_grace_period', '7', 'Days before MFA is enforced for new users'),
('api_rate_limit', '1000', 'API requests per hour per key'),
('password_reset_token_expiry', '60', 'Password reset token expiry in minutes'),
('require_email_verification', 'true', 'Require email verification for new accounts'),
('enable_ip_whitelist', 'false', 'Enable IP whitelisting'),
('suspicious_login_threshold', '3', 'Failed attempts from different IPs to flag as suspicious');

-- ============================================================================
-- INSERT DEFAULT ROLES
-- ============================================================================
INSERT INTO uh_ims_roles (name, display_name, description, is_system_role, priority) VALUES
('super_admin', 'Super Administrator', 'Full system access with all permissions', TRUE, 1000),
('admin', 'Administrator', 'Administrative access to most features', TRUE, 900),
('manager', 'Manager', 'Can manage inventory, sales, and reports', TRUE, 700),
('accountant', 'Accountant', 'Access to financial data and reports', TRUE, 600),
('sales_staff', 'Sales Staff', 'Can create sales and manage customers', TRUE, 500),
('warehouse_staff', 'Warehouse Staff', 'Can manage inventory and stock', TRUE, 400),
('viewer', 'Viewer', 'Read-only access to reports and data', TRUE, 300);

-- ============================================================================
-- INSERT DEFAULT PERMISSIONS
-- ============================================================================
INSERT INTO uh_ims_permissions (name, display_name, module, action, is_system_permission) VALUES
-- User Management
('users.create', 'Create Users', 'users', 'create', TRUE),
('users.read', 'View Users', 'users', 'read', TRUE),
('users.update', 'Update Users', 'users', 'update', TRUE),
('users.delete', 'Delete Users', 'users', 'delete', TRUE),
('users.manage_roles', 'Manage User Roles', 'users', 'manage_roles', TRUE),

-- Sales
('sales.create', 'Create Sales', 'sales', 'create', TRUE),
('sales.read', 'View Sales', 'sales', 'read', TRUE),
('sales.update', 'Update Sales', 'sales', 'update', TRUE),
('sales.delete', 'Delete Sales', 'sales', 'delete', TRUE),
('sales.cancel', 'Cancel Sales', 'sales', 'cancel', TRUE),

-- Customers
('customers.create', 'Create Customers', 'customers', 'create', TRUE),
('customers.read', 'View Customers', 'customers', 'read', TRUE),
('customers.update', 'Update Customers', 'customers', 'update', TRUE),
('customers.delete', 'Delete Customers', 'customers', 'delete', TRUE),

-- Products
('products.create', 'Create Products', 'products', 'create', TRUE),
('products.read', 'View Products', 'products', 'read', TRUE),
('products.update', 'Update Products', 'products', 'update', TRUE),
('products.delete', 'Delete Products', 'products', 'delete', TRUE),
('products.adjust_stock', 'Adjust Stock', 'products', 'adjust_stock', TRUE),

-- Purchase Orders
('purchase_orders.create', 'Create Purchase Orders', 'purchase_orders', 'create', TRUE),
('purchase_orders.read', 'View Purchase Orders', 'purchase_orders', 'read', TRUE),
('purchase_orders.update', 'Update Purchase Orders', 'purchase_orders', 'update', TRUE),
('purchase_orders.delete', 'Delete Purchase Orders', 'purchase_orders', 'delete', TRUE),
('purchase_orders.receive', 'Receive Purchase Orders', 'purchase_orders', 'receive', TRUE),

-- Suppliers
('suppliers.create', 'Create Suppliers', 'suppliers', 'create', TRUE),
('suppliers.read', 'View Suppliers', 'suppliers', 'read', TRUE),
('suppliers.update', 'Update Suppliers', 'suppliers', 'update', TRUE),
('suppliers.delete', 'Delete Suppliers', 'suppliers', 'delete', TRUE),

-- Payments
('payments.create', 'Create Payments', 'payments', 'create', TRUE),
('payments.read', 'View Payments', 'payments', 'read', TRUE),
('payments.update', 'Update Payments', 'payments', 'update', TRUE),
('payments.delete', 'Delete Payments', 'payments', 'delete', TRUE),

-- Expenses
('expenses.create', 'Create Expenses', 'expenses', 'create', TRUE),
('expenses.read', 'View Expenses', 'expenses', 'read', TRUE),
('expenses.update', 'Update Expenses', 'expenses', 'update', TRUE),
('expenses.delete', 'Delete Expenses', 'expenses', 'delete', TRUE),

-- Reports
('reports.sales', 'View Sales Reports', 'reports', 'read', TRUE),
('reports.profit', 'View Profit Reports', 'reports', 'read', TRUE),
('reports.inventory', 'View Inventory Reports', 'reports', 'read', TRUE),
('reports.financial', 'View Financial Reports', 'reports', 'read', TRUE),
('reports.export', 'Export Reports', 'reports', 'export', TRUE),

-- Settings
('settings.read', 'View Settings', 'settings', 'read', TRUE),
('settings.update', 'Update Settings', 'settings', 'update', TRUE),

-- Audit
('audit.read', 'View Audit Logs', 'audit', 'read', TRUE),

-- System
('system.backup', 'Create Backups', 'system', 'backup', TRUE),
('system.restore', 'Restore Backups', 'system', 'restore', TRUE);

-- ============================================================================
-- ASSIGN PERMISSIONS TO ROLES
-- ============================================================================

-- Super Admin gets all permissions
INSERT INTO uh_ims_role_permissions (role_id, permission_id)
SELECT 
  (SELECT id FROM uh_ims_roles WHERE name = 'super_admin'),
  id
FROM uh_ims_permissions;

-- Admin gets most permissions (exclude system.restore)
INSERT INTO uh_ims_role_permissions (role_id, permission_id)
SELECT 
  (SELECT id FROM uh_ims_roles WHERE name = 'admin'),
  id
FROM uh_ims_permissions
WHERE name != 'system.restore' AND name != 'users.delete';

-- Manager permissions
INSERT INTO uh_ims_role_permissions (role_id, permission_id)
SELECT 
  (SELECT id FROM uh_ims_roles WHERE name = 'manager'),
  id
FROM uh_ims_permissions
WHERE name IN (
  'sales.create', 'sales.read', 'sales.update', 'sales.cancel',
  'customers.create', 'customers.read', 'customers.update',
  'products.create', 'products.read', 'products.update', 'products.adjust_stock',
  'purchase_orders.create', 'purchase_orders.read', 'purchase_orders.update', 'purchase_orders.receive',
  'suppliers.create', 'suppliers.read', 'suppliers.update',
  'payments.create', 'payments.read', 'payments.update',
  'reports.sales', 'reports.profit', 'reports.inventory'
);

-- Accountant permissions
INSERT INTO uh_ims_role_permissions (role_id, permission_id)
SELECT 
  (SELECT id FROM uh_ims_roles WHERE name = 'accountant'),
  id
FROM uh_ims_permissions
WHERE name IN (
  'sales.read', 'customers.read', 'products.read',
  'purchase_orders.read', 'suppliers.read',
  'payments.create', 'payments.read', 'payments.update',
  'expenses.create', 'expenses.read', 'expenses.update',
  'reports.sales', 'reports.profit', 'reports.financial', 'reports.export'
);

-- Sales Staff permissions
INSERT INTO uh_ims_role_permissions (role_id, permission_id)
SELECT 
  (SELECT id FROM uh_ims_roles WHERE name = 'sales_staff'),
  id
FROM uh_ims_permissions
WHERE name IN (
  'sales.create', 'sales.read', 'sales.update',
  'customers.create', 'customers.read', 'customers.update',
  'products.read',
  'payments.create', 'payments.read',
  'reports.sales'
);

-- Warehouse Staff permissions
INSERT INTO uh_ims_role_permissions (role_id, permission_id)
SELECT 
  (SELECT id FROM uh_ims_roles WHERE name = 'warehouse_staff'),
  id
FROM uh_ims_permissions
WHERE name IN (
  'products.read', 'products.update', 'products.adjust_stock',
  'purchase_orders.read', 'purchase_orders.receive',
  'suppliers.read',
  'reports.inventory'
);

-- Viewer permissions
INSERT INTO uh_ims_role_permissions (role_id, permission_id)
SELECT 
  (SELECT id FROM uh_ims_roles WHERE name = 'viewer'),
  id
FROM uh_ims_permissions
WHERE name LIKE '%.read' OR name LIKE 'reports.%';

-- ============================================================================
-- CREATE DEFAULT ADMIN USER
-- ============================================================================
-- Password: Admin@12345 (MUST BE CHANGED ON FIRST LOGIN)
-- Password hash generated using bcrypt with cost factor 12
INSERT INTO uh_users (
  username, email, password_hash, first_name, last_name,
  status, mfa_enforced, require_password_change, email_verified
) VALUES (
  'admin',
  'admin@usmanhardware.com',
  '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewY5GyijYvJ6CrL2',
  'System',
  'Administrator',
  'active',
  TRUE,
  TRUE,
  TRUE
);

-- Assign super_admin role to default admin
INSERT INTO uh_ims_user_roles (user_id, role_id)
VALUES (
  (SELECT id FROM uh_users WHERE username = 'admin'),
  (SELECT id FROM uh_ims_roles WHERE name = 'super_admin')
);

-- ============================================================================
-- STORED PROCEDURES FOR AUTHENTICATION
-- ============================================================================

DELIMITER $

-- Procedure: Check User Permissions
DROP PROCEDURE IF EXISTS sp_check_user_permission$
CREATE PROCEDURE sp_check_user_permission(
  IN p_user_id BIGINT,
  IN p_permission_name VARCHAR(100)
)
BEGIN
  SELECT 
    COUNT(*) > 0 AS has_permission
  FROM (
    -- Permissions from roles
    SELECT p.name
    FROM uh_ims_permissions p
    JOIN uh_ims_role_permissions rp ON p.id = rp.permission_id
    JOIN uh_ims_user_roles ur ON rp.role_id = ur.role_id
    WHERE ur.user_id = p_user_id
      AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
    
    UNION
    
    -- Direct user permissions (grants)
    SELECT p.name
    FROM uh_ims_permissions p
    JOIN uh_ims_user_permissions up ON p.id = up.permission_id
    WHERE up.user_id = p_user_id
      AND up.permission_type = 'grant'
      AND (up.expires_at IS NULL OR up.expires_at > NOW())
  ) AS all_permissions
  WHERE name = p_permission_name
    AND NOT EXISTS (
      -- Check if permission is explicitly revoked
      SELECT 1
      FROM uh_ims_user_permissions up
      JOIN uh_ims_permissions p ON up.permission_id = p.id
      WHERE up.user_id = p_user_id
        AND p.name = p_permission_name
        AND up.permission_type = 'revoke'
        AND (up.expires_at IS NULL OR up.expires_at > NOW())
    );
END$

-- Procedure: Log Login Attempt
DROP PROCEDURE IF EXISTS sp_log_login_attempt$
CREATE PROCEDURE sp_log_login_attempt(
  IN p_user_id BIGINT,
  IN p_username VARCHAR(100),
  IN p_email VARCHAR(100),
  IN p_success BOOLEAN,
  IN p_failure_reason VARCHAR(255),
  IN p_ip_address VARCHAR(45),
  IN p_user_agent TEXT
)
BEGIN
  DECLARE v_risk_score INT DEFAULT 0;
  DECLARE v_is_suspicious BOOLEAN DEFAULT FALSE;
  DECLARE v_recent_failures INT;
  
  -- Calculate risk score based on recent failures
  SELECT COUNT(*) INTO v_recent_failures
  FROM uh_ims_user_login_attempts
  WHERE (user_id = p_user_id OR ip_address = p_ip_address)
    AND success = FALSE
    AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);
  
  SET v_risk_score = LEAST(v_recent_failures * 20, 100);
  
  IF v_recent_failures >= 3 THEN
    SET v_is_suspicious = TRUE;
  END IF;
  
  INSERT INTO uh_ims_user_login_attempts (
    user_id, username_attempted, email_attempted, success,
    failure_reason, ip_address, user_agent, risk_score, is_suspicious
  ) VALUES (
    p_user_id, p_username, p_email, p_success,
    p_failure_reason, p_ip_address, p_user_agent, v_risk_score, v_is_suspicious
  );
  
  -- Update user's failed attempts counter
  IF NOT p_success AND p_user_id IS NOT NULL THEN
    UPDATE uh_users
    SET failed_login_attempts = failed_login_attempts + 1,
        last_failed_login = NOW()
    WHERE id = p_user_id;
  END IF;
END$

-- Procedure: Lock User Account
DROP PROCEDURE IF EXISTS sp_lock_user_account$
CREATE PROCEDURE sp_lock_user_account(
  IN p_user_id BIGINT,
  IN p_duration_minutes INT
)
BEGIN
  UPDATE uh_users
  SET status = 'locked',
      account_locked_until = DATE_ADD(NOW(), INTERVAL p_duration_minutes MINUTE)
  WHERE id = p_user_id;
  
  -- Terminate all active sessions
  UPDATE uh_ims_user_sessions
  SET is_active = FALSE,
      terminated_at = NOW()
  WHERE user_id = p_user_id
    AND is_active = TRUE;
END$

-- Procedure: Validate Session
DROP PROCEDURE IF EXISTS sp_validate_session$
CREATE PROCEDURE sp_validate_session(
  IN p_session_token VARCHAR(255)
)
BEGIN
  SELECT 
    s.id AS session_id,
    s.user_id,
    u.username,
    u.email,
    u.status AS user_status,
    s.is_active,
    s.expires_at,
    s.mfa_verified,
    CASE 
      WHEN u.status != 'active' THEN 'inactive_user'
      WHEN s.expires_at < NOW() THEN 'expired'
      WHEN NOT s.is_active THEN 'terminated'
      ELSE 'valid'
    END AS validation_status
  FROM uh_ims_user_sessions s
  JOIN uh_users u ON s.user_id = u.id
  WHERE s.session_token = p_session_token;
  
  -- Update last activity
  UPDATE uh_ims_user_sessions
  SET last_activity = NOW()
  WHERE session_token = p_session_token
    AND is_active = TRUE;
END$

-- Procedure: Clean Expired Sessions
DROP PROCEDURE IF EXISTS sp_clean_expired_sessions$
CREATE PROCEDURE sp_clean_expired_sessions()
BEGIN
  UPDATE uh_ims_user_sessions
  SET is_active = FALSE,
      terminated_at = NOW()
  WHERE is_active = TRUE
    AND expires_at < NOW();
END$

-- Procedure: Get User Permissions
DROP PROCEDURE IF EXISTS sp_get_user_permissions$
CREATE PROCEDURE sp_get_user_permissions(
  IN p_user_id BIGINT
)
BEGIN
  SELECT DISTINCT p.name, p.display_name, p.module, p.action
  FROM (
    -- Permissions from roles
    SELECT p.id, p.name, p.display_name, p.module, p.action
    FROM uh_ims_permissions p
    JOIN uh_ims_role_permissions rp ON p.id = rp.permission_id
    JOIN uh_ims_user_roles ur ON rp.role_id = ur.role_id
    WHERE ur.user_id = p_user_id
      AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
    
    UNION
    
    -- Direct user permissions (grants)
    SELECT p.id, p.name, p.display_name, p.module, p.action
    FROM uh_ims_permissions p
    JOIN uh_ims_user_permissions up ON p.id = up.permission_id
    WHERE up.user_id = p_user_id
      AND up.permission_type = 'grant'
      AND (up.expires_at IS NULL OR up.expires_at > NOW())
  ) AS p
  WHERE NOT EXISTS (
    -- Exclude revoked permissions
    SELECT 1
    FROM uh_ims_user_permissions up
    WHERE up.user_id = p_user_id
      AND up.permission_id = p.id
      AND up.permission_type = 'revoke'
      AND (up.expires_at IS NULL OR up.expires_at > NOW())
  )
  ORDER BY p.module, p.action;
END$

DELIMITER ;

-- ============================================================================
-- TRIGGERS FOR AUDIT INTEGRATION
-- ============================================================================

DELIMITER $

-- Trigger: After user insert
DROP TRIGGER IF EXISTS trg_after_user_insert$
CREATE TRIGGER trg_after_user_insert
AFTER INSERT ON uh_users
FOR EACH ROW
BEGIN
  INSERT INTO uh_ims_audit_log (
    table_name, record_id, action, user_id, user_login,
    new_data, ip_address, user_agent
  ) VALUES (
    'uh_users', NEW.id, 'INSERT',
    NEW.created_by, NEW.username,
    JSON_OBJECT(
      'id', NEW.id,
      'username', NEW.username,
      'email', NEW.email,
      'status', NEW.status
    ),
    fn_get_audit_ip_address(),
    fn_get_audit_user_agent()
  );
END$

-- Trigger: After user update
DROP TRIGGER IF EXISTS trg_after_user_update$
CREATE TRIGGER trg_after_user_update
AFTER UPDATE ON uh_users
FOR EACH ROW
BEGIN
  DECLARE v_changed_fields JSON;
  SET v_changed_fields = JSON_ARRAY();
  
  IF OLD.username != NEW.username THEN 
    SET v_changed_fields = JSON_ARRAY_APPEND(v_changed_fields, ', 'username');
  END IF;
  IF OLD.email != NEW.email THEN 
    SET v_changed_fields = JSON_ARRAY_APPEND(v_changed_fields, ', 'email');
  END IF;
  IF OLD.status != NEW.status THEN 
    SET v_changed_fields = JSON_ARRAY_APPEND(v_changed_fields, ', 'status');
  END IF;
  IF OLD.password_hash != NEW.password_hash THEN 
    SET v_changed_fields = JSON_ARRAY_APPEND(v_changed_fields, ', 'password');
  END IF;
  
  INSERT INTO uh_ims_audit_log (
    table_name, record_id, action, user_id, user_login,
    old_data, new_data, changed_fields, ip_address, user_agent
  ) VALUES (
    'uh_users', NEW.id, 'UPDATE',
    NEW.updated_by, NEW.username,
    JSON_OBJECT('id', OLD.id, 'username', OLD.username, 'email', OLD.email, 'status', OLD.status),
    JSON_OBJECT('id', NEW.id, 'username', NEW.username, 'email', NEW.email, 'status', NEW.status),
    v_changed_fields,
    fn_get_audit_ip_address(),
    fn_get_audit_user_agent()
  );
END$

-- Trigger: Store password history
DROP TRIGGER IF EXISTS trg_password_history$
CREATE TRIGGER trg_password_history
AFTER UPDATE ON uh_users
FOR EACH ROW
BEGIN
  IF OLD.password_hash != NEW.password_hash THEN
    INSERT INTO uh_ims_user_password_history (user_id, password_hash)
    VALUES (NEW.id, OLD.password_hash);
    
    -- Clean old password history (keep only last N)
    DELETE FROM uh_ims_user_password_history
    WHERE user_id = NEW.id
      AND id NOT IN (
        SELECT id FROM (
          SELECT id FROM uh_ims_user_password_history
          WHERE user_id = NEW.id
          ORDER BY created_at DESC
          LIMIT (SELECT CAST(policy_value AS UNSIGNED) 
                 FROM uh_ims_security_policies 
                 WHERE policy_name = 'password_history_count')
        ) AS recent
      );
  END IF;
END$

DELIMITER ;

-- ============================================================================
-- VIEWS FOR EASY QUERYING
-- ============================================================================

-- View: User with roles and permissions
CREATE OR REPLACE VIEW vw_user_access AS
SELECT 
  u.id,
  u.username,
  u.email,
  u.first_name,
  u.last_name,
  u.status,
  u.mfa_enabled,
  u.last_login,
  GROUP_CONCAT(DISTINCT r.name ORDER BY r.priority DESC) AS roles,
  GROUP_CONCAT(DISTINCT p.name ORDER BY p.name) AS permissions
FROM uh_users u
LEFT JOIN uh_ims_user_roles ur ON u.id = ur.user_id
LEFT JOIN uh_ims_roles r ON ur.role_id = r.id
LEFT JOIN uh_ims_role_permissions rp ON r.id = rp.role_id
LEFT JOIN uh_ims_permissions p ON rp.permission_id = p.id
WHERE u.deleted_at IS NULL
GROUP BY u.id;

-- View: Active sessions
CREATE OR REPLACE VIEW vw_active_sessions AS
SELECT 
  s.id AS session_id,
  s.user_id,
  u.username,
  u.email,
  s.ip_address,
  s.device_type,
  s.device_name,
  s.last_activity,
  s.created_at AS login_time,
  TIMESTAMPDIFF(MINUTE, s.last_activity, NOW()) AS idle_minutes,
  s.mfa_verified
FROM uh_ims_user_sessions s
JOIN uh_users u ON s.user_id = u.id
WHERE s.is_active = TRUE
  AND s.expires_at > NOW();

-- View: Security dashboard
CREATE OR REPLACE VIEW vw_security_dashboard AS
SELECT 
  (SELECT COUNT(*) FROM uh_users WHERE status = 'active') AS active_users,
  (SELECT COUNT(*) FROM uh_users WHERE status = 'locked') AS locked_users,
  (SELECT COUNT(*) FROM uh_ims_user_sessions WHERE is_active = TRUE) AS active_sessions,
  (SELECT COUNT(*) FROM uh_ims_user_login_attempts 
   WHERE success = FALSE AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS recent_failed_logins,
  (SELECT COUNT(*) FROM uh_ims_user_login_attempts 
   WHERE is_suspicious = TRUE AND attempted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS suspicious_activities,
  (SELECT COUNT(*) FROM uh_ims_ip_blacklist WHERE is_active = TRUE) AS blocked_ips;

-- ============================================================================
-- EVENTS FOR AUTOMATED MAINTENANCE
-- ============================================================================

-- Enable event scheduler
SET GLOBAL event_scheduler = ON;

-- Event: Clean expired sessions (every 15 minutes)
DELIMITER $
DROP EVENT IF EXISTS evt_clean_expired_sessions$
CREATE EVENT evt_clean_expired_sessions
ON SCHEDULE EVERY 15 MINUTE
DO BEGIN
  CALL sp_clean_expired_sessions();
END$

-- Event: Clean old login attempts (daily)
DROP EVENT IF EXISTS evt_clean_old_login_attempts$
CREATE EVENT evt_clean_old_login_attempts
ON SCHEDULE EVERY 1 DAY
DO BEGIN
  DELETE FROM uh_ims_user_login_attempts
  WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
END$

-- Event: Clean old activity logs (monthly)
DROP EVENT IF EXISTS evt_clean_old_activity_logs$
CREATE EVENT evt_clean_old_activity_logs
ON SCHEDULE EVERY 1 MONTH
DO BEGIN
  DELETE FROM uh_ims_user_activity_log
  WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
END$

-- Event: Unlock accounts after lockout period
DROP EVENT IF EXISTS evt_unlock_accounts$
CREATE EVENT evt_unlock_accounts
ON SCHEDULE EVERY 5 MINUTE
DO BEGIN
  UPDATE uh_users
  SET status = 'active',
      failed_login_attempts = 0,
      account_locked_until = NULL
  WHERE status = 'locked'
    AND account_locked_until IS NOT NULL
    AND account_locked_until < NOW();
END$

DELIMITER ;

-- Enable event scheduler
SET GLOBAL event_scheduler = ON;

-- Event: Clean expired sessions (every 15 minutes)
DELIMITER $
DROP EVENT IF EXISTS evt_clean_expired_sessions$
CREATE EVENT evt_clean_expired_sessions
ON SCHEDULE EVERY 15 MINUTE
DO BEGIN
  CALL sp_clean_expired_sessions();
END$

-- Event: Clean old login attempts (daily)
DROP EVENT IF EXISTS evt_clean_old_login_attempts$
CREATE EVENT evt_clean_old_login_attempts
ON SCHEDULE EVERY 1 DAY
DO BEGIN
  DELETE FROM uh_ims_user_login_attempts
  WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
END$

-- Event: Clean old activity logs (monthly)
DROP EVENT IF EXISTS evt_clean_old_activity_logs$
CREATE EVENT evt_clean_old_activity_logs
ON SCHEDULE EVERY 1 MONTH
DO BEGIN
  DELETE FROM uh_ims_user_activity_log
  WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
END$

-- Event: Unlock accounts after lockout period
DROP EVENT IF EXISTS evt_unlock_accounts$
CREATE EVENT evt_unlock_accounts
ON SCHEDULE EVERY 5 MINUTE
DO BEGIN
  UPDATE uh_users
  SET status = 'active',
      failed_login_attempts = 0,
      account_locked_until = NULL
  WHERE status = 'locked'
    AND account_locked_until IS NOT NULL
    AND account_locked_until < NOW();
END$

DELIMITER ;
DELIMITER $

-- Procedure: Check User Permissions
DROP PROCEDURE IF EXISTS sp_check_user_permission$
CREATE PROCEDURE sp_check_user_permission(
  IN p_user_id BIGINT,
  IN p_permission_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci
)
BEGIN
  SELECT 
    COUNT(*) > 0 AS has_permission
  FROM (
    -- Permissions from roles
    SELECT p.name
    FROM uh_ims_permissions p
    JOIN uh_ims_role_permissions rp ON p.id = rp.permission_id
    JOIN uh_ims_user_roles ur ON rp.role_id = ur.role_id
    WHERE ur.user_id = p_user_id
      AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
    
    UNION
    
    -- Direct user permissions (grants)
    SELECT p.name
    FROM uh_ims_permissions p
    JOIN uh_ims_user_permissions up ON p.id = up.permission_id
    WHERE up.user_id = p_user_id
      AND up.permission_type = 'grant'
      AND (up.expires_at IS NULL OR up.expires_at > NOW())
  ) AS all_permissions
  WHERE name = p_permission_name
    AND NOT EXISTS (
      -- Check if permission is explicitly revoked
      SELECT 1
      FROM uh_ims_user_permissions up
      JOIN uh_ims_permissions p ON up.permission_id = p.id
      WHERE up.user_id = p_user_id
        AND p.name = p_permission_name
        AND up.permission_type = 'revoke'
        AND (up.expires_at IS NULL OR up.expires_at > NOW())
    );
END$

-- Procedure: Log Login Attempt
DROP PROCEDURE IF EXISTS sp_log_login_attempt$
CREATE PROCEDURE sp_log_login_attempt(
  IN p_user_id BIGINT,
  IN p_username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_email VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_success BOOLEAN,
  IN p_failure_reason VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_ip_address VARCHAR(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_user_agent TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci
)
BEGIN
  DECLARE v_risk_score INT DEFAULT 0;
  DECLARE v_is_suspicious BOOLEAN DEFAULT FALSE;
  DECLARE v_recent_failures INT;
  
  -- Calculate risk score based on recent failures
  SELECT COUNT(*) INTO v_recent_failures
  FROM uh_ims_user_login_attempts
  WHERE (user_id = p_user_id OR ip_address = p_ip_address)
    AND success = FALSE
    AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);
  
  SET v_risk_score = LEAST(v_recent_failures * 20, 100);
  
  IF v_recent_failures >= 3 THEN
    SET v_is_suspicious = TRUE;
  END IF;
  
  INSERT INTO uh_ims_user_login_attempts (
    user_id, username_attempted, email_attempted, success,
    failure_reason, ip_address, user_agent, risk_score, is_suspicious
  ) VALUES (
    p_user_id, p_username, p_email, p_success,
    p_failure_reason, p_ip_address, p_user_agent, v_risk_score, v_is_suspicious
  );
  
  -- Update user's failed attempts counter
  IF NOT p_success AND p_user_id IS NOT NULL THEN
    UPDATE uh_users
    SET failed_login_attempts = failed_login_attempts + 1,
        last_failed_login = NOW()
    WHERE id = p_user_id;
  END IF;
END$

-- Procedure: Validate Session
DROP PROCEDURE IF EXISTS sp_validate_session$
CREATE PROCEDURE sp_validate_session(
  IN p_session_token VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci
)
BEGIN
  SELECT 
    s.id AS session_id,
    s.user_id,
    u.username,
    u.email,
    u.status AS user_status,
    s.is_active,
    s.expires_at,
    s.mfa_verified,
    CASE 
      WHEN u.status != 'active' THEN 'inactive_user'
      WHEN s.expires_at < NOW() THEN 'expired'
      WHEN NOT s.is_active THEN 'terminated'
      ELSE 'valid'
    END AS validation_status
  FROM uh_ims_user_sessions s
  JOIN uh_users u ON s.user_id = u.id
  WHERE s.session_token = p_session_token;
  
  -- Update last activity
  UPDATE uh_ims_user_sessions
  SET last_activity = NOW()
  WHERE session_token = p_session_token
    AND is_active = TRUE;
END$

DELIMITER ;
DELIMITER $

-- Procedure: Lock User Account
DROP PROCEDURE IF EXISTS sp_lock_user_account$
CREATE PROCEDURE sp_lock_user_account(
  IN p_user_id BIGINT,
  IN p_duration_minutes INT
)
BEGIN
  UPDATE uh_users
  SET status = 'locked',
      account_locked_until = DATE_ADD(NOW(), INTERVAL p_duration_minutes MINUTE)
  WHERE id = p_user_id;
  
  -- Terminate all active sessions
  UPDATE uh_ims_user_sessions
  SET is_active = FALSE,
      terminated_at = NOW()
  WHERE user_id = p_user_id
    AND is_active = TRUE;
END$

-- Procedure: Log Login Attempt
DROP PROCEDURE IF EXISTS sp_log_login_attempt$
CREATE PROCEDURE sp_log_login_attempt(
  IN p_user_id BIGINT,
  IN p_username VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_email VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_success BOOLEAN,
  IN p_failure_reason VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_ip_address VARCHAR(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci,
  IN p_user_agent TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci
)
BEGIN
  DECLARE v_risk_score INT DEFAULT 0;
  DECLARE v_is_suspicious BOOLEAN DEFAULT FALSE;
  DECLARE v_recent_failures INT;
  DECLARE v_max_attempts INT DEFAULT 5;
  DECLARE v_lockout_duration INT DEFAULT 30;
  
  -- Calculate risk score based on recent failures
  SELECT COUNT(*) INTO v_recent_failures
  FROM uh_ims_user_login_attempts
  WHERE (user_id = p_user_id OR ip_address = p_ip_address)
    AND success = FALSE
    AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);
  
  SET v_risk_score = LEAST(v_recent_failures * 20, 100);
  
  IF v_recent_failures >= 3 THEN
    SET v_is_suspicious = TRUE;
  END IF;
  
  INSERT INTO uh_ims_user_login_attempts (
    user_id, username_attempted, email_attempted, success,
    failure_reason, ip_address, user_agent, risk_score, is_suspicious
  ) VALUES (
    p_user_id, p_username, p_email, p_success,
    p_failure_reason, p_ip_address, p_user_agent, v_risk_score, v_is_suspicious
  );
  
  -- Handle Success: Reset counters
  IF p_success AND p_user_id IS NOT NULL THEN
    UPDATE uh_users
    SET failed_login_attempts = 0,
        last_failed_login = NULL,
        account_locked_until = NULL,
        status = IF(status = 'locked', 'active', status)
    WHERE id = p_user_id;
  END IF;

  -- Handle Failure: Increment and Check Lockout
  IF NOT p_success AND p_user_id IS NOT NULL THEN
    UPDATE uh_users
    SET failed_login_attempts = failed_login_attempts + 1,
        last_failed_login = NOW()
    WHERE id = p_user_id;

    -- Get Policy Values
    SELECT CAST(policy_value AS UNSIGNED) INTO v_max_attempts
    FROM uh_ims_security_policies 
    WHERE policy_name = 'max_login_attempts';

    SELECT CAST(policy_value AS UNSIGNED) INTO v_lockout_duration
    FROM uh_ims_security_policies 
    WHERE policy_name = 'account_lockout_duration';

    -- Check if we should lock
    IF (SELECT failed_login_attempts FROM uh_users WHERE id = p_user_id) >= IFNULL(v_max_attempts, 5) THEN
       CALL sp_lock_user_account(p_user_id, IFNULL(v_lockout_duration, 30));
    END IF;
  END IF;
END$

-- Procedure: Check User Permissions (Included to ensure collation fix is present)
DROP PROCEDURE IF EXISTS sp_check_user_permission$
CREATE PROCEDURE sp_check_user_permission(
  IN p_user_id BIGINT,
  IN p_permission_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci
)
BEGIN
  SELECT 
    COUNT(*) > 0 AS has_permission
  FROM (
    -- Permissions from roles
    SELECT p.name
    FROM uh_ims_permissions p
    JOIN uh_ims_role_permissions rp ON p.id = rp.permission_id
    JOIN uh_ims_user_roles ur ON rp.role_id = ur.role_id
    WHERE ur.user_id = p_user_id
      AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
    
    UNION
    
    -- Direct user permissions (grants)
    SELECT p.name
    FROM uh_ims_permissions p
    JOIN uh_ims_user_permissions up ON p.id = up.permission_id
    WHERE up.user_id = p_user_id
      AND up.permission_type = 'grant'
      AND (up.expires_at IS NULL OR up.expires_at > NOW())
  ) AS all_permissions
  WHERE name = p_permission_name
    AND NOT EXISTS (
      -- Check if permission is explicitly revoked
      SELECT 1
      FROM uh_ims_user_permissions up
      JOIN uh_ims_permissions p ON up.permission_id = p.id
      WHERE up.user_id = p_user_id
        AND p.name = p_permission_name
        AND up.permission_type = 'revoke'
        AND (up.expires_at IS NULL OR up.expires_at > NOW())
    );
END$

-- Procedure: Validate Session (Included to ensure collation fix is present)
DROP PROCEDURE IF EXISTS sp_validate_session$
CREATE PROCEDURE sp_validate_session(
  IN p_session_token VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci
)
BEGIN
  SELECT 
    s.id AS session_id,
    s.user_id,
    u.username,
    u.email,
    u.status AS user_status,
    s.is_active,
    s.expires_at,
    s.mfa_verified,
    CASE 
      WHEN u.status != 'active' THEN 'inactive_user'
      WHEN s.expires_at < NOW() THEN 'expired'
      WHEN NOT s.is_active THEN 'terminated'
      ELSE 'valid'
    END AS validation_status
  FROM uh_ims_user_sessions s
  JOIN uh_users u ON s.user_id = u.id
  WHERE s.session_token = p_session_token;
  
  -- Update last activity
  UPDATE uh_ims_user_sessions
  SET last_activity = NOW()
  WHERE session_token = p_session_token
    AND is_active = TRUE;
END$

DELIMITER ;



DROP FUNCTION IF EXISTS fn_get_audit_user_id;
DELIMITER $$
CREATE FUNCTION fn_get_audit_user_id()
RETURNS INT
DETERMINISTIC
READS SQL DATA
BEGIN
    RETURN @audit_user_id;
END$$
DELIMITER ;

DROP FUNCTION IF EXISTS fn_get_audit_user_login;
DELIMITER $$
CREATE FUNCTION fn_get_audit_user_login()
RETURNS VARCHAR(255)
DETERMINISTIC
READS SQL DATA
BEGIN
    RETURN @audit_user_login;
END$$
DELIMITER ;

DROP FUNCTION IF EXISTS fn_get_audit_ip_address;
DELIMITER $$
CREATE FUNCTION fn_get_audit_ip_address()
RETURNS VARCHAR(45)
DETERMINISTIC
READS SQL DATA
BEGIN
    RETURN @audit_ip_address;
END$$
DELIMITER ;

DROP FUNCTION IF EXISTS fn_get_audit_user_agent;
DELIMITER $$
CREATE FUNCTION fn_get_audit_user_agent()
RETURNS VARCHAR(255)
DETERMINISTIC
READS SQL DATA
BEGIN
    RETURN @audit_user_agent;
END$$
DELIMITER ;




CREATE TABLE IF NOT EXISTS uh_ims_expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);


INSERT IGNORE INTO uh_ims_expense_categories (name)
SELECT DISTINCT category
FROM uh_ims_expenses
WHERE category IS NOT NULL
  AND category != '';