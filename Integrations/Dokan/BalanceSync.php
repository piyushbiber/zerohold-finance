<?php

namespace ZeroHold\Finance\Integrations\Dokan;

use ZeroHold\Finance\Core\QueryEngine;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Balance Synchronization
 * 
 * Synchronizes ZeroHold Finance balances with Dokan's native balance system.
 * This ensures that Dokan's withdrawal page and native dashboard display
 * the "Safe/Available" amount calculated by ZeroHold.
 */
class BalanceSync {

    public static function init() {
        // 1. Authoritative Balance Filter (ZeroHold Master) - Withdrawable
        add_filter( 'dokan_get_seller_balance', [ __CLASS__, 'synchronize_dokan_balance' ], 999, 2 );
        add_filter( 'dokan_get_formatted_seller_balance', [ __CLASS__, 'synchronize_dokan_formatted_balance' ], 999, 2 );

        // 2. Authoritative Earnings Filter (ZeroHold Master) - Accrued Net
        add_filter( 'dokan_get_seller_earnings', [ __CLASS__, 'synchronize_dokan_earnings' ], 999, 2 );
        add_filter( 'dokan_get_formatted_seller_earnings', [ __CLASS__, 'synchronize_dokan_formatted_earnings' ], 999, 2 );
    }

    /**
     * Overrides Dokan's native balance calculation with ZeroHold's safe withdrawable balance.
     * 
     * @param float $balance Dokan's calculated balance
     * @param int   $seller_id The vendor ID
     * @return float
     */
    public static function synchronize_dokan_balance( $balance, $seller_id ) {
        // We use the ZeroHold QueryEngine as the single source of truth.
        // This balance is already "Net" (Minus Locked Earnings, Plus Locked Debts).
        $zh_available = QueryEngine::get_withdrawable_balance( 'vendor', $seller_id );
        
        return (float) $zh_available;
    }

    /**
     * Overrides Dokan's formatted balance to match the raw synchronized balance.
     * 
     * @param string $formatted_balance
     * @param int    $seller_id
     * @return string
     */
    public static function synchronize_dokan_formatted_balance( $formatted_balance, $seller_id ) {
        $zh_available = QueryEngine::get_withdrawable_balance( 'vendor', $seller_id );
        
        return wc_price( $zh_available );
    }

    /**
     * Overrides Dokan's native earnings calculation with ZeroHold's absolute net position (Accrued).
     * 
     * @param float $earnings Dokan's calculated earnings
     * @param int   $seller_id The vendor ID
     * @return float
     */
    public static function synchronize_dokan_earnings( $earnings, $seller_id ) {
        // Net Position = Total Assets - Total Liabilities.
        // This includes locked earnings, showing the vendor what they HAVE EARNED in total.
        return (float) QueryEngine::get_wallet_balance( 'vendor', $seller_id );
    }

    /**
     * Overrides Dokan's formatted earnings.
     * 
     * @param string $formatted_earnings
     * @param int    $seller_id
     * @return string
     */
    public static function synchronize_dokan_formatted_earnings( $formatted_earnings, $seller_id ) {
        $zh_net = QueryEngine::get_wallet_balance( 'vendor', $seller_id );
        
        return wc_price( $zh_net );
    }
}
