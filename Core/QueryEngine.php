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

        // Wallet balance = SUM of ALL amounts
        // This includes earnings AND deductions (shipping, commission, etc.)
        // Because Pending/Available represent NET earnings (what vendor will receive)
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

        // Locked balance = SUM of ALL locked amounts
        // This includes locked earnings AND locked deductions (shipping with order_hold)
        // Because Pending Balance represents NET locked earnings
        $sql = "SELECT SUM(amount) FROM $table 
                WHERE entity_type = %s 
                AND entity_id = %d 
                AND lock_type != 'none' 
                AND (unlock_at IS NULL OR unlock_at > NOW())";

        $locked = $wpdb->get_var( $wpdb->prepare( $sql, $entity_type, $entity_id ) );

        return $locked ? (float) $locked : 0.00;
    }

    /**
     * Get ONLY the positive locked amounts (Earnings/Credits).
     * This is used for the "Available Balance" math to prevent debts
     * from acting as credits when they are locked.
     *
     * @param string $entity_type
     * @param int $entity_id
     * @return float Always positive or 0.
     */
    public static function get_locked_credits( $entity_type, $entity_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        $sql = "SELECT SUM(amount) FROM $table 
                WHERE entity_type = %s 
                AND entity_id = %d 
                AND amount > 0 
                AND lock_type != 'none' 
                AND (unlock_at IS NULL OR unlock_at > NOW())";

        $credits = $wpdb->get_var( $wpdb->prepare( $sql, $entity_type, $entity_id ) );

        return $credits ? (float) $credits : 0.00;
    }

    /**
     * Get Withdrawable Balance (Total - Locked Credits).
     *
     * @param string $entity_type
     * @param int $entity_id
     * @return float
     */
    public static function get_withdrawable_balance( $entity_type, $entity_id ) {
        $total = self::get_wallet_balance( $entity_type, $entity_id );
        
        // ADMIN SAFETY GUARD:
        // We only subtract POSITIVE locked amounts (Credits).
        // Negative locked amounts (Debits like shipping/fees) should 
        // ALWAYS reduce the available balance immediately.
        $locked_credits = self::get_locked_credits( $entity_type, $entity_id );
        
        $available = $total - $locked_credits;

        return $available > 0 ? (float) $available : 0.00;
    }

    /**
     * Get Net Position (Profit/Loss).
     * 
     * This shows the vendor's OVERALL position including platform charges.
     * Unlike withdrawable/pending balances, this CAN be negative.
     * 
     * Formula: Total Earnings - Shipping - Commission - Penalties - Returns
     * 
     * This is for VISIBILITY only, NOT for withdrawals.
     *
     * @param string $entity_type
     * @param int $entity_id
     * @return float Can be negative
     */
    public static function get_net_position( $entity_type, $entity_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        // Net Position = SUM of ALL amounts (including platform charges)
        // This shows the true profit/loss picture
        $net = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(amount) FROM $table WHERE entity_type = %s AND entity_id = %d",
            $entity_type,
            $entity_id
        ) );

        return $net ? (float) $net : 0.00;
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
            'total_liabilities'  => 0.00, // Total Owed (Vendor only per Phase 15)
            'total_escrow'       => 0.00, // Total Locked
            'platform_profit'    => 0.00, // Retained Profit
            'vendor_liabilities' => 0.00, // Sub-card: Vendor Owed
            'vendor_escrow'      => 0.00, // Sub-card: Vendor Locked
        ];

        /**
         * ⚖️ ARCHITECTURAL INVARIANT (PHASE 15):
         * External Buyer balances (TeraWallet) must NEVER influence platform accounting.
         * Platform Liabilities = Vendor Liabilities ONLY.
         * Platform Net Profit = Bank Pool - Vendor Liabilities.
         */

        // 1. Bank Pool (Real Money belonging to the platform)
        $metrics['total_real'] = (float) $wpdb->get_var( 
            "SELECT SUM(amount) FROM $table WHERE entity_type = 'admin' AND money_nature = 'real'" 
        );

        // --- SUB-CARDS (Always Non-Negative for Display) ---

        // Vendor Liabilities
        $metrics['vendor_liabilities'] = max( 0, (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table WHERE entity_type = 'vendor' AND money_nature = 'claim'"
        ) );

        // Source of Truth (RECONCILIATION ONLY)
        // This is strictly informational. It does NOT enter platform math.
        $metrics['buyer_wallet_total'] = self::get_terawallet_global_total();

        // Vendor Escrow
        // Strictly only Vendor funds sit in escrow.
        $metrics['vendor_escrow'] = abs( (float) $wpdb->get_var(
            "SELECT SUM(amount) FROM $table 
             WHERE entity_type = 'vendor' 
             AND lock_type != 'none' 
             AND (unlock_at IS NULL OR unlock_at > NOW())"
        ) );

        // --- CALCULATION REFINEMENT (Buyer-less Ledger) ---
        $metrics['total_liabilities'] = $metrics['vendor_liabilities'];
        $metrics['total_escrow']      = $metrics['vendor_escrow'];

        // 4. Platform Net Profit = Real Cash - Vendor Debt
        $metrics['platform_profit'] = $metrics['total_real'] - $metrics['total_liabilities'];

        return $metrics;
    }

    /**
     * Get the absolute total of all TeraWallet balances in the system.
     * This is the "True" liability of the system according to the wallet plugin.
     * 
     * @return float
     */
    public static function get_terawallet_global_total() {
        global $wpdb;
        $total = $wpdb->get_var( 
            "SELECT SUM(meta_value + 0) 
             FROM {$wpdb->usermeta} 
             WHERE meta_key = 'woo_wallet_balance'" 
        );
        return $total ? (float) $total : 0.00;
    }
}
