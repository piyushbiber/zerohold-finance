<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ChargeEngine
 * 
 * Responsible for calculating and applying platform fees/commissions
 * based on active rules when financial events occur (e.g. Order Completion).
 */
class ChargeEngine {

    /**
     * Initialize the Charge Engine
     */
    public static function init() {
        // Listen for the generic finance event trigger
        add_action( 'zh_finance_event', [ __CLASS__, 'handle_event' ], 10, 2 );
    }

    /**
     * Handle generic finance events
     * 
     * @param string $event_name (e.g., 'order_completed', 'shipping_purchased')
     * @param array  $payload    (e.g., ['order_id' => 123, 'vendor_id' => 5])
     */
    public static function handle_event( $event_name, $payload ) {
        if ( empty( $payload['vendor_id'] ) ) {
            return;
        }

        // 1. Get applicable rules for this event
        $rules = self::get_rules_for_trigger( $event_name );

        foreach ( $rules as $rule ) {
            self::process_rule( $rule, $payload );
        }
    }

    /**
     * Get active rules for a specific Trigger
     * (Currently HARDCODED for Phase 0 MVP)
     */
    private static function get_rules_for_trigger( $trigger ) {
        $rules = [];

        // HARDCODED RULE: 10% Platform Commission on Order Complete
        if ( $trigger === 'order_completed' ) {
            $rules[] = [
                'id'          => 'rule_platform_comm_10',
                'title'       => 'Platform Commission',
                'type'        => 'percentage',
                'value'       => 10.00, // 10%
                'recipient'   => 'admin', // Goes to platform
                'description' => 'Standard platform fee',
            ];
        }

        return $rules;
    }

    /**
     * Process a single rule and creating Ledger Entries
     */
    private static function process_rule( $rule, $payload ) {
        // We need an Order object to calculate amounts
        if ( empty( $payload['order_id'] ) ) {
            return;
        }

        $order = wc_get_order( $payload['order_id'] );
        if ( ! $order ) {
            return;
        }

        // 1. Calculate Amount
        // TODO: This should be Net Sales (Product Total), likely excluding tax/shipping for commission
        // For MVP, lets use order total or subtotal of the vendor's items.
        // But $order might be a sub-order (Dokan) or parent.
        // In Dokan, vendors have sub-orders. We assume $order is the sub-order.
        
        $basis_amount = $order->get_subtotal(); // Commission usually on product price
        $charge_amount = 0;

        if ( $rule['type'] === 'percentage' ) {
            $charge_amount = ( $basis_amount * $rule['value'] ) / 100;
        }

        // 2. Prepare Double-Entry Entities
        // Vendor (Paying the fee)
        $from_entity = [
            'type'   => 'vendor',
            'id'     => $payload['vendor_id'],
            'nature' => 'claim' // Vendor balance is a liability/claim
        ];

        // Platform (Receiving the fee)
        $to_entity = [
            'type'   => 'platform',
            'id'     => 0, // System/Admin
            'nature' => 'real' // Revenue
        ];

        // 3. Record in Ledger (Debit Vendor, Credit Platform)
        if ( $charge_amount > 0 ) {
            LedgerService::record(
                $from_entity,
                $to_entity,
                $charge_amount,
                'fee',   // Impact/Taxonomy
                'order', // Reference Type
                $payload['order_id'], // Reference ID
                'none'   // Lock Type (Fees are usually immediate/settled)
            );

            // 3. Credit the Admin (Optional: if we tracked Admin Wallet, we'd do it here)
            // error_log( "Charged Vendor #{$payload['vendor_id']} $charge_amount for {$rule['title']}" );
        }
    }
}
