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
        
        // --- DYNAMIC ESCROW PROTECTION ---
        $escrow_value = get_option( 'zh_finance_escrow_value', 7 );
        $escrow_unit  = get_option( 'zh_finance_escrow_unit', 'days' );
        $unlock_at    = date( 'Y-m-d H:i:s', strtotime( "+$escrow_value $escrow_unit" ) );

        // Persist the canonical unlock time for synchronization
        $order->update_meta_data( '_zh_order_unlock_at', $unlock_at );
        $order->save();

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
            'lock_type'      => 'order_hold', // Keeps it in "Locked" balance
            'unlock_at'      => $unlock_at,   // Moves to "Available" after 7 days
            'reason'         => 'Order Completed - 7 day escrow period started'
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( ! is_wp_error( $result ) ) {
            // --- ATOMIC RETROACTIVE SYNC ---
            // Find all previously recorded charges for this order (e.g. shipping in processing)
            // and anchor them to the same release time as the earnings.
            $wpdb->update(
                $table,
                [ 'unlock_at' => $unlock_at ],
                [ 
                    'reference_type' => 'order',
                    'reference_id'   => $order_id
                ]
            );

            // Trigger automation (e.g., Platform Commissions)
            do_action( 'zh_finance_event', 'zh_event_order_completed', [
                'order_id'    => $order_id,
                'vendor_id'   => $vendor_id,
                'customer_id' => $order->get_customer_id() ?: 0
            ] );
        }
    }

    /**
     * Handle order refund/cancellation - Reverse vendor earnings
     * 
     * @param int $order_id
     */
    public static function handle_order_refund( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if earnings were ever recorded
        if ( ! $order->get_meta( '_zh_finance_earnings_recorded' ) ) {
            return; // No earnings to reverse
        }

        // Idempotency: Check if refund already processed
        if ( $order->get_meta( '_zh_finance_refund_recorded' ) ) {
            return;
        }

        $vendor_id = dokan_get_seller_id_by_order( $order_id );
        if ( ! $vendor_id ) {
            return;
        }

        // Get original earnings amount from ledger
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';
        $original_amount = $wpdb->get_var( $wpdb->prepare(
            "SELECT amount FROM $table WHERE reference_type = 'order' AND reference_id = %d AND impact = 'earnings' LIMIT 1",
            $order_id
        ) );

        if ( ! $original_amount || $original_amount <= 0 ) {
            return; // No earnings found
        }

        // Mark as processed BEFORE creating reversal
        $order->update_meta_data( '_zh_finance_refund_recorded', true );
        $order->save();

        // Create earnings reversal entry
        // This reverses the vendor's earnings (reduces their balance)
        $payload = [
            'from' => [
                'type'   => 'vendor',
                'id'     => $vendor_id,
                'nature' => 'claim'
            ],
            'to' => [
                'type'   => 'outside',
                'id'     => 0,
                'nature' => 'real'
            ],
            'amount'         => $original_amount, // Positive amount (will be negative in ledger due to from/to)
            'impact'         => 'earnings_reversal',
            'reference_type' => 'order',
            'reference_id'   => $order_id,
            'lock_type'      => 'order_hold', // Same lock type as original
            'unlock_at'      => null
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( is_wp_error( $result ) ) {
            error_log( "ZH Finance: ERROR reversing earnings for Order #$order_id: " . $result->get_error_message() );
        } else {
            error_log( "ZH Finance: Successfully reversed earnings for Order #$order_id (₹$original_amount)" );
        }
    }
}
