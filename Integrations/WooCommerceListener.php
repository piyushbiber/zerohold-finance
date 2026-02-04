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
            // Trigger automation (e.g., Platform Commissions)
            // This records additional charges like commissions and fees.
            do_action( 'zh_finance_event', 'zh_event_order_completed', [
                'order_id'    => $order_id,
                'vendor_id'   => $vendor_id,
                'customer_id' => $order->get_customer_id() ?: 0
            ] );

            // --- ATOMIC RETROACTIVE SYNC (Catch-All) ---
            // Find all previously recorded charges for this order (including commissions just recorded)
            // and anchor them to the same release time as the earnings.
            $wpdb->update(
                $table,
                [ 'unlock_at' => $unlock_at ],
                [ 
                    'reference_type' => 'order',
                    'reference_id'   => $order_id
                ]
            );
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

    /**
     * Handle Return Delivered status
     * 
     * Deducts return shipping cost from Vendor Locked Balance
     * 
     * @param int $order_id
     */
    public static function handle_return_delivered( $order_id ) {
        error_log( "ZH Finance DEBUG: handle_return_delivered triggered for Order #$order_id" );
        
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            error_log( "ZH Finance DEBUG: Order #$order_id not found." );
            return;
        }

        // Idempotency: Prevent duplicate return shipping charges
        if ( get_post_meta( $order_id, '_zh_finance_return_shipping_recorded', true ) ) {
            error_log( "ZH Finance DEBUG: Return shipping already recorded for Order #$order_id" );
            return;
        }

        // Use direct WP cache/DB instead of WC object cache for custom meta
        $cost = get_post_meta( $order_id, '_zh_return_shipping_total_actual', true );
        error_log( "ZH Finance DEBUG: Order #$order_id return cost (raw db): " . print_r($cost, true) );
        
        if ( ! $cost || $cost <= 0 ) {
            error_log( "ZH Finance DEBUG: No return shipping cost found for Order #$order_id, skipping deduction." );
            return;
        }

        $vendor_id = dokan_get_seller_id_by_order( $order_id );
        error_log( "ZH Finance DEBUG: Order #$order_id vendor ID: $vendor_id" );
        
        if ( ! $vendor_id ) {
            error_log( "ZH Finance DEBUG: Vendor not found for Order #$order_id, skipping return shipping deduction." );
            return;
        }

        // Mark as recorded
        $order->update_meta_data( '_zh_finance_return_shipping_recorded', true );
        $order->save();

        // Record deduction from Vendor Locked (Claim) balance
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
            'lock_type'      => 'order_hold', // Stay locked with order earnings
            'reason'         => 'Return Delivered - Shipping Fee Deducted (Locked)'
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( is_wp_error( $result ) ) {
            error_log( "ZH Finance: ERROR recording return shipping deduction for Order #$order_id: " . $result->get_error_message() );
        } else {
            error_log( "ZH Finance: Successfully recorded return shipping deduction for Order #$order_id (₹$cost)" );
            
            // 🚀 Chain Trigger: Also reverse the original product earnings
            error_log( "ZH Finance DEBUG: Triggering earnings reversal for Order #$order_id via handle_order_refund" );
            self::handle_order_refund( $order_id );
        }
    }
}
