<?php

namespace ZeroHold\Finance\Integrations;

use ZeroHold\Finance\Core\FinanceIngress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ZSSListener {

    public static function init() {
        // Custom hook fired by ZSS when a label is successfully generated and charged
        add_action( 'zerohold_shipping_label_charged', [ __CLASS__, 'handle_shipping_charge' ], 10, 1 );
    }

    /**
     * Handle Shipping Split Charge
     * 
     * @param array $data Payload from ZSS
     * [
     *   'order_id' => 123,
     *   'vendor_id' => 5,
     *   'buyer_id' => 2,
     *   'cost_actual' => 100,
     *   'charge_buyer' => 60,
     *   'charge_vendor' => 60,
     *   'label_id' => 'abc-123'
     * ]
     */
    public static function handle_shipping_charge( $data ) {
        
        error_log( "ZH Finance: handle_shipping_charge called with data: " . print_r( $data, true ) );
        
        if ( empty( $data['order_id'] ) || ! isset( $data['cost_actual'] ) ) {
            error_log( "ZH Finance: Missing order_id or cost_actual, aborting" );
            return;
        }

        // Idempotency check: prevent duplicate shipping charges
        $order = wc_get_order( $data['order_id'] );
        if ( ! $order ) {
            error_log( "ZH Finance: Order not found for ID: " . $data['order_id'] );
            return;
        }

        if ( $order->get_meta( '_zh_finance_shipping_recorded' ) ) {
            error_log( "ZH Finance: Shipping already recorded for Order #" . $data['order_id'] );
            return;
        }

        // Mark as recorded BEFORE processing
        $order->update_meta_data( '_zh_finance_shipping_recorded', true );
        $order->save();
        error_log( "ZH Finance: Marked Order #" . $data['order_id'] . " as shipping recorded" );

        // 1. Vendor Charge (Vendor Claim -> Admin Real)
        // Shipping is LOCKED with the order - reduces Pending Balance
        if ( $data['charge_vendor'] > 0 ) {
            error_log( "ZH Finance: Recording vendor shipping charge: ₹" . $data['charge_vendor'] . " for Vendor #" . $data['vendor_id'] );
            
            $result = FinanceIngress::handle_event([
                'from' => [ 'type' => 'vendor', 'id' => $data['vendor_id'], 'nature' => 'claim' ],
                'to'   => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
                'amount' => $data['charge_vendor'],
                'impact' => 'shipping_charge',
                'reference_type' => 'order',
                'reference_id' => $data['order_id'],
                'lock_type' => 'order_hold' // LOCKED - reduces Pending Balance
            ]);
            
            if ( is_wp_error( $result ) ) {
                error_log( "ZH Finance: ERROR recording vendor shipping charge: " . $result->get_error_message() );
            } else {
                error_log( "ZH Finance: Successfully recorded vendor shipping charge" );
            }
        }

        // 2. Actual Cost (Admin Real -> Outside External)
        // This records the actual cost paid to the shipping platform
        if ( $data['cost_actual'] > 0 ) {
            error_log( "ZH Finance: Recording actual shipping cost: ₹" . $data['cost_actual'] );
            
            $result = FinanceIngress::handle_event([
                'from' => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
                'to'   => [ 'type' => 'outside', 'id' => 0, 'nature' => 'external' ],
                'amount' => $data['cost_actual'],
                'impact' => 'shipping_cost_actual',
                'reference_type' => 'order',
                'reference_id' => $data['order_id']
            ]);
            
            if ( is_wp_error( $result ) ) {
                error_log( "ZH Finance: ERROR recording actual shipping cost: " . $result->get_error_message() );
            } else {
                error_log( "ZH Finance: Successfully recorded actual shipping cost" );
            }
        }
    }
}
