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

        // 1. Buyer Charge (Buyer Claim -> Admin Real)
        if ( $data['charge_buyer'] > 0 ) {
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'buyer', 'id' => $data['buyer_id'], 'nature' => 'claim' ],
                'to'   => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
                'amount' => $data['charge_buyer'],
                'impact' => 'shipping_charge_buyer',
                'reference_type' => 'order',
                'reference_id' => $data['order_id']
            ]);
        }

        // 2. Vendor Charge (Vendor Claim -> Admin Real)
        if ( $data['charge_vendor'] > 0 ) {
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'vendor', 'id' => $data['vendor_id'], 'nature' => 'claim' ],
                'to'   => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
                'amount' => $data['charge_vendor'],
                'impact' => 'shipping_charge_vendor',
                'reference_type' => 'order',
                'reference_id' => $data['order_id'],
                'lock_type' => 'order_hold' // Shipping charge is locked same as earnings until finalized
            ]);
        }

        // 3. Actual Cost (Admin Real -> Bank External)
        if ( $data['cost_actual'] > 0 ) {
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
                'to'   => [ 'type' => 'bank', 'id' => 0, 'nature' => 'external' ], // Money leaves system
                'amount' => $data['cost_actual'],
                'impact' => 'shipping_cost_actual',
                'reference_type' => 'shipping_label',
                'reference_id' => is_numeric($data['label_id']) ? $data['label_id'] : $data['order_id'] 
                // Note: reference_id must be int. If label_id is string, we map or use order_id.
            ]);
        }
    }
}
