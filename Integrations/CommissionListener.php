<?php

namespace ZeroHold\Finance\Integrations;

use ZeroHold\Finance\Core\FinanceIngress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Commission Listener
 * 
 * Listens to commission events from the smart shipping plugin
 * and records them in the ZeroHold Finance ledger
 */
class CommissionListener {

    public static function init() {
        add_action( 'zerohold_commission_charged', [ __CLASS__, 'handle_commission_charge' ], 10, 1 );
    }

    /**
     * Handle commission charge event
     * 
     * @param array $data Commission data
     */
    public static function handle_commission_charge( $data ) {
        error_log( "ZH Finance: handle_commission_charge called with data: " . print_r( $data, true ) );

        // Validate required data
        if ( empty( $data['vendor_id'] ) || empty( $data['order_id'] ) || empty( $data['commission_amount'] ) ) {
            error_log( "ZH Finance: Missing required commission data" );
            return;
        }

        // Idempotency check
        $order = wc_get_order( $data['order_id'] );
        if ( ! $order ) {
            error_log( "ZH Finance: Order not found: " . $data['order_id'] );
            return;
        }

        if ( $order->get_meta( '_zh_finance_commission_recorded' ) ) {
            error_log( "ZH Finance: Commission already recorded for Order #" . $data['order_id'] );
            return;
        }

        // Mark as processed BEFORE recording
        $order->update_meta_data( '_zh_finance_commission_recorded', true );
        $order->save();

        // Record commission charge (Vendor Claim -> Admin Real)
        // This is LOCKED with the order - reduces Pending Balance
        error_log( "ZH Finance: Recording commission charge: ₹" . $data['commission_amount'] . " for Vendor #" . $data['vendor_id'] );

        $result = FinanceIngress::handle_event([
            'from' => [ 'type' => 'vendor', 'id' => $data['vendor_id'], 'nature' => 'claim' ],
            'to'   => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
            'amount' => $data['commission_amount'],
            'impact' => 'commission',
            'reference_type' => 'order',
            'reference_id' => $data['order_id'],
            'lock_type' => 'none' // IMMEDIATE - Reduces Available Balance right away
        ]);

        if ( is_wp_error( $result ) ) {
            error_log( "ZH Finance: ERROR recording commission: " . $result->get_error_message() );
        } else {
            error_log( "ZH Finance: Successfully recorded commission for Order #" . $data['order_id'] );
        }
    }
}
