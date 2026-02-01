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
     * Get active rules for a specific Trigger from DB
     */
    private static function get_rules_for_trigger( $trigger ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_charge_rules';
        
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE trigger_event = %s AND status = 'active'",
            $trigger
        ) );
    }

    /**
     * Process a single rule and creating Ledger Entries
     */
    private static function process_rule( $rule, $payload ) {
        if ( empty( $payload['order_id'] ) ) {
            return;
        }

        $order = wc_get_order( $payload['order_id'] );
        if ( ! $order ) {
            return;
        }

        // 1. Calculate Amount
        $basis_amount = $order->get_subtotal();
        $charge_amount = 0;

        if ( $rule->amount_type === 'percentage' ) {
            $charge_amount = ( $basis_amount * $rule->amount_value ) / 100;
        } else {
            // Fixed amount logic
            // TODO: In future, multiply by quantity if condition_type is 'per_item/per_box'
            $charge_amount = $rule->amount_value;
        }

        if ( $charge_amount <= 0 ) {
            return;
        }

        // 2. Determine Receiver(s) - Handle Split
        $receivers = [];

        if ( $rule->split_enabled ) {
            // Split into two credits
            $receivers[] = [
                'entity_type' => 'admin',
                'amount'      => ( $charge_amount * $rule->admin_profit_pct ) / 100,
                'impact'      => $rule->impact_slug . '_profit'
            ];
            $receivers[] = [
                'entity_type' => 'platform',
                'amount'      => ( $charge_amount * $rule->external_cost_pct ) / 100,
                'impact'      => $rule->impact_slug . '_cost'
            ];
        } else {
            // Single Receiver
            $receivers[] = [
                'entity_type' => $rule->to_entity_type,
                'amount'      => $charge_amount,
                'impact'      => $rule->impact_slug
            ];
        }

        // 3. Record in Ledger
        $from_id = ( $rule->from_entity_type === 'buyer' ) ? $payload['customer_id'] : $payload['vendor_id'];
        
        $from_entity = [
            'type'   => $rule->from_entity_type,
            'id'     => $from_id,
            'nature' => 'claim'
        ];

        foreach ( $receivers as $receiver ) {
            $to_entity = [
                'type'   => $receiver['entity_type'],
                'id'     => 0, // System entities
                'nature' => ( $receiver['entity_type'] === 'admin' ) ? 'real' : 'claim'
            ];

            LedgerService::record(
                $from_entity,
                $to_entity,
                $receiver['amount'],
                $receiver['impact'],
                'order',
                $payload['order_id'],
                $rule->lock_type
            );
        }
    }
}
