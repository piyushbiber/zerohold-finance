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
             AND impact NOT IN ('wallet_recharge')
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
            'total_real'         => 0.00, // Bank Pool
            'total_liabilities'  => 0.00, // Total Owed (Vendor + Buyer)
            'total_escrow'       => 0.00, // Total Locked
            'platform_profit'    => 0.00, // Retained Profit
            'vendor_liabilities' => 0.00, // Sub-card: Vendor Owed
            'buyer_liabilities'  => 0.00, // Sub-card: Buyer Owed
            'vendor_escrow'      => 0.00, // Sub-card: Vendor Locked
            'buyer_escrow'       => 0.00, // Sub-card: Buyer Locked
        ];

        // 1. Bank Pool (Real Money belonging to the platform)
        $metrics['total_real'] = (float) $wpdb->get_var( 
            "SELECT SUM(amount) FROM $table WHERE entity_type = 'admin' AND money_nature = 'real'" 
        );

        // --- SUB-CARDS (Always Non-Negative for Display) ---

        // Vendor Liabilities
        $metrics['vendor_liabilities'] = max( 0, (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table WHERE entity_type = 'vendor' AND money_nature = 'claim'"
        ) );

        // Buyer Liabilities
        $metrics['buyer_liabilities'] = max( 0, (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table WHERE entity_type = 'buyer' AND money_nature = 'claim'"
        ) );

        // Vendor Escrow
        // Strictly only Vendor funds sit in escrow.
        $metrics['vendor_escrow'] = abs( (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table 
             WHERE entity_type = 'vendor' 
             AND lock_type != 'none' 
             AND (unlock_at IS NULL OR unlock_at > NOW())"
        ) );

        // Buyer Escrow
        // Hard-enforce zero for Buyers per Phase 9 Governance.
        $metrics['buyer_escrow'] = 0.00;

        // --- CALCULATION REFINEMENT ---

        // Recalculate Total Liabilities & Escrow
        $metrics['total_liabilities'] = $metrics['vendor_liabilities'] + $metrics['buyer_liabilities'];
        $metrics['total_escrow']      = $metrics['vendor_escrow']; // Strictly vendor-holdings.

        // 4. Platform Net Profit = Bank Pool - Total Liabilities
        $metrics['platform_profit'] = $metrics['total_real'] - $metrics['total_liabilities'];

        return $metrics;
    }
}
