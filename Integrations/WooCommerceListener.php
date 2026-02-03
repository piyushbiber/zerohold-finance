<?php

namespace ZeroHold\Finance\Integrations;

use ZeroHold\Finance\Core\FinanceIngress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooCommerceListener {

    public static function init() {
        // High priority to ensure we capture it after other logic runs
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'handle_order_payment' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'handle_order_payment' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'handle_order_payment' ], 10, 1 );
    }

    public static function handle_order_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Idempotency Check: Prevent duplicate entries
        if ( $order->get_meta( '_zh_finance_earnings_recorded' ) ) {
            return;
        }

        $vendor_id = dokan_get_seller_id_by_order( $order_id );
        if ( ! $vendor_id ) {
            return; // Not a vendor order?
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

        // In a multi-vendor marketplace:
        // - Vendor receives ONLY the product subtotal (their earnings)
        // - Shipping is handled by admin/platform, NOT credited to vendor
        $vendor_earnings = $order->get_subtotal() - $order->get_total_refunded();
        
        if ( $vendor_earnings <= 0 ) {
            return;
        }
        
        $payload = [
            'from' => [
                'type'   => 'outside',
                'id'     => 0,
                'nature' => 'real'
            ],
            'to' => [
                'type'   => 'vendor',
                'id'     => $vendor_id,
                'nature' => 'claim'
            ],
            'amount'         => $vendor_earnings,
            'impact'         => 'earnings',
            'reference_type' => 'order',
            'reference_id'   => $order_id,
            'lock_type'      => 'order_hold',
            'unlock_at'      => null
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( ! is_wp_error( $result ) ) {
            // Trigger automation (e.g., Platform Commissions)
            do_action( 'zh_finance_event', 'zh_event_order_completed', [
                'order_id'    => $order_id,
                'vendor_id'   => $vendor_id,
                'customer_id' => $order->get_customer_id() ?: 0
            ] );
        }
    }
}
