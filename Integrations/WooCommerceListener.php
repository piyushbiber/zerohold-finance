<?php

namespace ZeroHold\Finance\Integrations;

use ZeroHold\Finance\Core\FinanceIngress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooCommerceListener {

    public static function init() {
        // Earnings are now only recorded when the order is FULLY completed
        // This prevents phantom earnings for rejected or cancelled orders
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'handle_order_payment' ], 10, 1 );
        
        // Refund/Return listeners
        add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'handle_order_refund' ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'handle_order_refund' ], 10, 1 );
        
        // Generic Status Change Listener (Safety Net)
        add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'handle_status_transition' ], 10, 3 );
    }

    public static function handle_status_transition( $order_id, $from, $to ) {
        error_log( "ZH Finance DEBUG: Order #$order_id status changed from '$from' to '$to'" );
        
        // If the new status is 'return-delivered', trigger the deduction
        if ( $to === 'return-delivered' || $to === 'wc-return-delivered' ) {
            error_log( "ZH Finance DEBUG: Status matches return-delivered, calling handle_return_delivered" );
            self::handle_return_delivered( $order_id );
        }
    }

    public static function handle_order_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // --- DEFERRED EARNINGS GATE ---
        // Earnings only show up when the order reaches 'completed'
        if ( $order->get_status() !== 'completed' ) {
            return;
        }

        // Idempotency Check: Prevent duplicate entries
        if ( $order->get_meta( '_zh_finance_earnings_recorded' ) ) {
            return;
        }

        $vendor_id = dokan_get_seller_id_by_order( $order_id );
        if ( ! $vendor_id ) {
            return;
        }

        // Double-check in ledger to prevent race conditions
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE reference_type = 'order' AND reference_id = %d AND impact = 'earnings'",
            $order_id
        ) );
        
        if ( $exists > 0 ) {
            // Already recorded, mark the order and exit
            $order->update_meta_data( '_zh_finance_earnings_recorded', true );
            $order->save();
            return;
        }

        // Set flag BEFORE processing to prevent race condition
        $order->update_meta_data( '_zh_finance_earnings_recorded', true );
        $order->save();

        // Vendor receives ONLY the product subtotal
        $vendor_earnings = $order->get_subtotal() - $order->get_total_refunded();
        
        if ( $vendor_earnings <= 0 ) {
            return;
        }
        
        // --- DYNAMIC ESCROW PROTECTION (THE WAITING ROOM) ---
        $escrow_value = get_option( 'zh_finance_escrow_value', 7 );
        $escrow_unit  = get_option( 'zh_finance_escrow_unit', 'days' );
        $mature_at    = date( 'Y-m-d H:i:s', strtotime( "+$escrow_value $escrow_unit" ) );

        // 1. Set Maturity Timestamp (Timer starts now)
        $order->update_meta_data( '_zh_finance_mature_at', $mature_at );
        $order->save();

        error_log( "ZH Finance: Deferred Earnings timer started for Order #$order_id. Matures at: $mature_at" );

        // 🚀 TRIGGER AUTOMATION (Commission & Fees)
        // We trigger this NOW so fees are recorded immediately (as Debits).
        // WE DO NOT RECORD EARNINGS YET. The Sweeper will do that when timer ends.
        do_action( 'zh_finance_event', 'zh_event_order_completed', [
            'order_id'    => $order_id,
            'vendor_id'   => $vendor_id,
            'customer_id' => $order->get_customer_id() ?: 0
        ] );
    }

    /**
     * Handle Return Delivered status
     * 
     * Deducts return shipping cost from Vendor Balance (Creates negative if empty)
     * 
     * @param int $order_id
     */
    public static function handle_return_delivered( $order_id ) {
        error_log( "ZH Finance DEBUG: handle_return_delivered triggered for Order #$order_id" );
        
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Idempotency: Prevent duplicate return shipping charges
        if ( get_post_meta( $order_id, '_zh_finance_return_shipping_recorded', true ) ) {
            return;
        }

        // Use direct WP cache/DB instead of WC object cache for custom meta
        $cost = get_post_meta( $order_id, '_zh_return_shipping_total_actual', true );
        
        if ( ! $cost || $cost <= 0 ) {
            error_log( "ZH Finance DEBUG: No return shipping cost found for Order #$order_id, skipping deduction." );
            return;
        }

        $vendor_id = dokan_get_seller_id_by_order( $order_id );
        
        if ( ! $vendor_id ) {
            return;
        }

        // Mark as recorded
        $order->update_meta_data( '_zh_finance_return_shipping_recorded', true );
        $order->save();

        // Record deduction from Vendor Available Balance (since no locked balance exists yet)
        $payload = [
            'from' => [
                'type'   => 'vendor',
                'id'     => $vendor_id,
                'nature' => 'claim'
            ],
            'to' => [
                'type'   => 'admin',
                'id'     => 0,
                'nature' => 'real'
            ],
            'amount'         => (float) $cost,
            'impact'         => 'return_shipping',
            'reference_type' => 'order',
            'reference_id'   => $order_id,
            'lock_type'      => 'none', // Direct deduction
            'reason'         => 'Return Delivered - Shipping Fee Deducted'
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( is_wp_error( $result ) ) {
            error_log( "ZH Finance: ERROR recording return shipping deduction for Order #$order_id: " . $result->get_error_message() );
        } else {
            error_log( "ZH Finance: Successfully recorded return shipping deduction for Order #$order_id (₹$cost)" );
        }
    }
}
