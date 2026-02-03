<?php
/**
 * Migration: Fix Shipping Charge Lock Types
 * 
 * Updates old shipping_charge entries from lock_type='none' to 'order_hold'
 * so they correctly reduce Pending Balance instead of Available Balance
 */

// Run this once via WordPress admin or WP-CLI
function zh_finance_fix_shipping_lock_types() {
    global $wpdb;
    $table = $wpdb->prefix . 'zh_wallet_events';
    
    // Update all shipping_charge entries that have lock_type='none'
    $result = $wpdb->query(
        "UPDATE $table 
        SET lock_type = 'order_hold' 
        WHERE impact IN ('shipping_charge', 'shipping_charge_vendor') 
        AND lock_type = 'none'"
    );
    
    if ($result === false) {
        return new WP_Error('db_error', 'Failed to update shipping lock types');
    }
    
    return "Updated $result shipping charge entries to lock_type='order_hold'";
}

// Add admin action to run migration
add_action('admin_init', function() {
    if (isset($_GET['zh_fix_shipping_locks']) && current_user_can('manage_options')) {
        $result = zh_finance_fix_shipping_lock_types();
        
        if (is_wp_error($result)) {
            wp_die('Error: ' . $result->get_error_message());
        }
        
        wp_die('Success! ' . $result . '<br><br><a href="' . admin_url('admin.php?page=zh-finance-debug') . '">View Finance Debug</a>');
    }
});
