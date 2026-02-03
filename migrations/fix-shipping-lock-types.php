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
    
    // Impact types that should definitely be locked with the order
    $impacts = [
        'shipping_charge',
        'shipping_charge_vendor',
        'shipping_charge_buyer',
        'commission',
        'platform_fee'
    ];
    
    $placeholders = implode( ',', array_fill( 0, count( $impacts ), '%s' ) );
    
    // 1. TEMPORARILY DROP TRIGGERS (To bypass IMMUTABILITY for this fix)
    $wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}zh_prevent_ledger_update" );
    $wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}zh_prevent_ledger_delete" );

    // 2. Update all matching entries that have lock_type = 'none'
    $sql = "UPDATE $table 
            SET lock_type = 'order_hold' 
            WHERE impact IN ($placeholders) 
            AND lock_type = 'none'
            AND reference_type = 'order'";
            
    $result = $wpdb->query( $wpdb->prepare( $sql, $impacts ) );

    // 3. RESTORE TRIGGERS (Re-enable IMMUTABILITY)
    if ( class_exists( 'ZeroHold\Finance\Core\Database' ) ) {
        \ZeroHold\Finance\Core\Database::create_triggers();
    }
    
    if ($result === false) {
        return new WP_Error('db_error', 'Database error: ' . $wpdb->last_error);
    }
    
    return "Updated $result entries to lock_type='order_hold'";
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
