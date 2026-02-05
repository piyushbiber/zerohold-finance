<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the release of "Deferred" earnings.
 * Runs on cron to unlock funds after the return window expires.
 */
class EarningsSweeper {

    public static function init() {
        // Register Cron Event
        add_action( 'zh_finance_release_earnings', [ __CLASS__, 'process_mature_orders' ] );
        
        // Register Manual Trigger (Admin-Post)
        add_action( 'admin_post_zh_run_sweeper', [ __CLASS__, 'manual_trigger' ] );
        
        // Add Custom intervals
        add_filter( 'cron_schedules', [ __CLASS__, 'add_custom_intervals' ] );

        // Self-Scheduling Logic
        if ( ! wp_next_scheduled( 'zh_finance_release_earnings' ) ) {
            $freq_option = get_option( 'zh_finance_gatekeeper_freq', 'hourly' );
            $interval = 'hourly';

            switch ( $freq_option ) {
                case 'test_1min': $interval = 'zh_1min'; break;
                case '5min':      $interval = 'zh_5min'; break;
                case '15min':     $interval = 'zh_15min'; break;
                default:          $interval = 'hourly'; break;
            }

            wp_schedule_event( time(), $interval, 'zh_finance_release_earnings' );
        }
    }

    public static function add_custom_intervals( $schedules ) {
        $schedules['zh_1min'] = [ 'interval' => 60, 'display' => 'Every Minute (ZH)' ];
        $schedules['zh_5min'] = [ 'interval' => 300, 'display' => 'Every 5 Minutes (ZH)' ];
        $schedules['zh_15min'] = [ 'interval' => 900, 'display' => 'Every 15 Minutes (ZH)' ];
        return $schedules;
    }

    /**
     * Manual Trigger for Admin Testing
     */
    public static function manual_trigger() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access Denied' );
        }

        self::process_mature_orders();

        $url = add_query_arg( [
            'page' => 'zh-finance',
            'zh_msg' => 'sweeper_run'
        ], admin_url( 'admin.php' ) );

        wp_redirect( $url );
        exit;
    }

    /**
     * Main Sweeper Logic (The Gatekeeper)
     * Checks Timer + Status
     */
    public static function process_mature_orders() {
        error_log( "ZH Finance Sweeper: Starting gatekeeper check..." );
        
        $limit = 50;
        $processed = 0;

        // Query: Completed + No Earnings Yet + Timer Done
        $orders = wc_get_orders( [
            'limit'      => $limit,
            'status'     => 'completed',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'     => '_zh_finance_earnings_recorded',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key'     => '_zh_finance_mature_at',
                    'value'   => current_time( 'mysql' ),
                    'compare' => '<',
                    'type'    => 'DATETIME'
                ]
            ]
        ] );

        if ( empty( $orders ) ) {
            return;
        }

        foreach ( $orders as $order ) {
            $order_id = $order->get_id();
            $status   = $order->get_status();

            // --- THE GATEKEEPER LOGIC (Status Check) ---
            // We only pay if status is clean.
            
            // Dangerous Statuses (Currently in Return flow)
            if ( in_array( $status, [ 'wc-return-requested', 'return-requested', 'wc-return-approved', 'return-approved' ] ) ) {
                 // WAIT. Do not pay.
                 error_log( "ZH Finance Sweeper: Order #$order_id is in return status '$status'. Holding pay." );
                 continue;
            }

            // Dead Statuses (Already Returned/Refunded)
            // Note: If full refund happened, status might be refunded.
            // If partial return (return-delivered), we ALREADY deducted shipping.
            // But we NEVER paid earnings. So we just mark as skipped.
            if ( in_array( $status, [ 'wc-return-delivered', 'return-delivered', 'refunded', 'cancelled' ] ) ) {
                $order->update_meta_data( '_zh_finance_earnings_recorded', 'skipped_dead_status' );
                $order->save();
                error_log( "ZH Finance Sweeper: Order #$order_id is '$status'. Skipped earnings." );
                continue;
            }

            // If we are here, status is 'completed' (or 'return-rejected'/'return-cancelled' which revert to completed often, or we treat them as safe).
            
            // PAYOUT
            self::pay_vendor_earnings( $order );
            $processed++;
        }

        if ( $processed > 0 ) {
            error_log( "ZH Finance Sweeper: Gatekeeper released $processed orders." );
        }
    }

    /**
     * Record the earnings transaction
     */
    private static function pay_vendor_earnings( $order ) {
        $order_id = $order->get_id();
        $vendor_id = dokan_get_seller_id_by_order( $order_id );

        if ( ! $vendor_id ) {
            return;
        }

        $vendor_earnings = $order->get_subtotal() - $order->get_total_refunded();

        if ( $vendor_earnings <= 0 ) {
            $order->update_meta_data( '_zh_finance_earnings_recorded', 'skipped_zero' );
            $order->save();
            return;
        }

        // Record directly as 'Available'
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
            'amount'         => (float) $vendor_earnings,
            'impact'         => 'earnings',
            'reference_type' => 'order',
            'reference_id'   => $order_id,
            'lock_type'      => 'none', // IMMEDIATE RELEASE
            'reason'         => 'Order Matured & Status Verified'
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( is_wp_error( $result ) ) {
            error_log( "ZH Finance Sweeper: ERROR paying Order #$order_id: " . $result->get_error_message() );
        } else {
            $order->update_meta_data( '_zh_finance_earnings_recorded', true );
            $order->save();
            
            // Automation hook (Commissions etc) - NO! 
            // Commissions are triggered at order completion now (Step 4792 plan). 
            // Wait, implementation plan said "CRITICAL: Ensure Platform Fees are still triggered immediately".
            // So we do NOT trigger them here to avoid double fees.
        }
    }
}
