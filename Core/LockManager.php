<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LockManager
 * 
 * Handles the calculation and application of 'unlock_at' timestamps.
 * Coordinates with delivery events to move funds from Escrow to Available.
 */
class LockManager {

    public static function init() {
        // Listen for delivery events to set maturity date
        add_action( 'zh_event_order_delivered', [ __CLASS__, 'on_order_delivered' ], 10, 2 );
    }

    /**
     * Triggered when an order is marked as delivered by a logistics provider.
     * 
     * @param int $order_id
     * @param string $delivery_date Y-m-d H:i:s
     */
    public static function on_order_delivered( $order_id, $delivery_date = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        if ( ! $delivery_date ) {
            $delivery_date = current_time( 'mysql' );
        }

        // 1. Calculate Unlock Date (Delivery + Return Window)
        $return_window_days = get_option( 'zh_finance_return_window', 7 );
        $unlock_at = date( 'Y-m-d H:i:s', strtotime( "$delivery_date + $return_window_days days" ) );

        // 2. Update Ledger Rows for this Order
        // Note: Our trigger allows updating 'unlock_at' specifically.
        $updated = $wpdb->update(
            $table,
            [ 'unlock_at' => $unlock_at ],
            [ 
                'reference_type' => 'order',
                'reference_id'   => $order_id,
                'lock_type'      => 'order_hold' // Only update funds specifically held for this order flow
            ]
        );

        // 3. Log the event in Order Metadata for traceability
        if ( $updated ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->add_order_note( sprintf( 
                    __( 'ZeroHold Finance: Order Delivered. Funds maturity set to %s (Return window: %d days).', 'zerohold-finance' ),
                    $unlock_at,
                    $return_window_days
                ) );
                $order->update_meta_data( '_zh_finance_unlock_at', $unlock_at );
                $order->save();
            }
        }
        
        return $updated;
    }

    /**
     * Helper to get return window
     */
    public static function get_return_window() {
        return (int) get_option( 'zh_finance_return_window', 7 );
    }
}
