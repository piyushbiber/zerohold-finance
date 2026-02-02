<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class QueryEngine {

    /**
     * Get the total raw wallet balance (Real + Claims).
     *
     * @param string $entity_type 'vendor', 'buyer', 'admin'
     * @param int $entity_id
     * @return float
     */
    public static function get_wallet_balance( $entity_type, $entity_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        $balance = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(amount) FROM $table WHERE entity_type = %s AND entity_id = %d",
            $entity_type,
            $entity_id
        ) );

        return $balance ? (float) $balance : 0.00;
    }

    /**
     * Get the currently LOCKED balance.
     * This follows the "Reader Logic" - no DB updates needed.
     * Funds are locked IF lock_type != 'none' AND (unlock_at IS NULL OR unlock_at > NOW())
     *
     * @param string $entity_type
     * @param int $entity_id
     * @return float Positive value representing locked liabilities.
     */
    public static function get_locked_balance( $entity_type, $entity_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        // We only care about positive amounts (credits) that are locked? 
        // Or do we sum everything? Usually we check credits that the user owns but can't withdraw.
        // But double entry means user has +100 locked. 
        
        $sql = "SELECT SUM(amount) FROM $table 
                WHERE entity_type = %s 
                AND entity_id = %d 
                AND lock_type != 'none' 
                AND (unlock_at IS NULL OR unlock_at > NOW())";

        $locked = $wpdb->get_var( $wpdb->prepare( $sql, $entity_type, $entity_id ) );

        return $locked ? (float) $locked : 0.00;
    }

    /**
     * Get Withdrawable Balance (Total - Locked).
     *
     * @param string $entity_type
     * @param int $entity_id
     * @return float
     */
    public static function get_withdrawable_balance( $entity_type, $entity_id ) {
        $total = self::get_wallet_balance( $entity_type, $entity_id );
        $locked = self::get_locked_balance( $entity_type, $entity_id );

        // If locked balance is positive (User has funds they can't touch), we subtract it.
        // Total = 100. Locked = 20. Withdrawable = 80.
        
        return $total - $locked;
    }

    /**
     * Get Profit & Loss Breakdown for Admin (Central Bank).
     * Grouped by Impact.
     *
     * @return array
     */
    public static function get_admin_pnl_breakdown() {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';
        
        // Admin entity is 'admin' with ID 0 usually, or we just sum EVERYTHING if we want system view.
        // But strictly, Admin P&L is the balance of the admin entity.
        
        $results = $wpdb->get_results( 
            "SELECT impact, SUM(amount) as total 
             FROM $table 
             WHERE entity_type = 'admin' 
             GROUP BY impact" 
        );

        $breakdown = [];
        foreach ( $results as $row ) {
            $breakdown[ $row->impact ] = (float) $row->total;
        }

        return $breakdown;
    }
    /**
     * Get Global Financial Metrics for the platform.
     * 
     * @return array
     */
    public static function get_global_metrics() {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        $metrics = [
            'total_real'      => 0.00, // Total actual money held (Bank Balance)
            'total_claims'    => 0.00, // Total liabilities (IOUs to vendors)
            'total_locked'    => 0.00, // Escrow
            'platform_profit' => 0.00, // Retained Platform Profit
        ];

        // 1. Total Real Money (The "Bank")
        $metrics['total_real'] = (float) $wpdb->get_var( 
            "SELECT SUM(amount) FROM $table WHERE money_nature = 'real'" 
        );

        // 2. Total Claims (Vendor/Buyer Liabilities)
        $metrics['total_claims'] = (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table WHERE money_nature = 'claim' AND entity_type IN ('vendor', 'buyer')"
        );

        // 3. Total Locked (Funds not yet withdrawable)
        $metrics['total_locked'] = (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table 
             WHERE lock_type != 'none' 
             AND (unlock_at IS NULL OR unlock_at > NOW())"
        );

        // 4. Platform Net Profit (Retained Equity)
        // This is the net balance of the 'admin' entity in the ledger
        $metrics['platform_profit'] = (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table WHERE entity_type = 'admin'"
        );

        return $metrics;
    }
}
