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
        
        if ( empty( $data['order_id'] ) || ! isset( $data['cost_actual'] ) ) {
            return;
        }

        // Idempotency check: prevent duplicate shipping charges
        $order = wc_get_order( $data['order_id'] );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( '_zh_finance_shipping_recorded' ) ) {
            return;
        }

        // Mark as recorded BEFORE processing
        $order->update_meta_data( '_zh_finance_shipping_recorded', true );
        $order->save();

        // 1. Vendor Charge (Vendor Claim -> Admin Real)
        // This deducts the shipping cost (including profit cap) from vendor's balance
        if ( $data['charge_vendor'] > 0 ) {
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'vendor', 'id' => $data['vendor_id'], 'nature' => 'claim' ],
                'to'   => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
                'amount' => $data['charge_vendor'],
                'impact' => 'shipping_charge',
                'reference_type' => 'order',
                'reference_id' => $data['order_id'],
                'lock_type' => 'order_hold' // Locked until order is finalized
            ]);
        }

        // 2. Actual Cost (Admin Real -> Outside External)
        // This records the actual cost paid to the shipping platform
        if ( $data['cost_actual'] > 0 ) {
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
                'to'   => [ 'type' => 'outside', 'id' => 0, 'nature' => 'external' ],
                'amount' => $data['cost_actual'],
                'impact' => 'shipping_cost_actual',
                'reference_type' => 'order',
                'reference_id' => $data['order_id']
            ]);
        }
    }
}
