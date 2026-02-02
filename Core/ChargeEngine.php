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
        // Listen for the generic finance event trigger (for Orders)
        add_action( 'zh_finance_event', [ __CLASS__, 'handle_event' ], 10, 2 );

        // Register Daily Cron for Recurring Charges
        if ( ! wp_next_scheduled( 'zh_daily_recurring_charge' ) ) {
            wp_schedule_event( time(), 'daily', 'zh_daily_recurring_charge' );
        }
        add_action( 'zh_daily_recurring_charge', [ __CLASS__, 'process_recurring_charges' ] );
    }

    /**
     * Handle generic finance events (mainly for Orders)
     * 
     * @param string $event_name (e.g., 'order_completed')
     * @param array  $payload    (e.g., ['order_id' => 123, 'vendor_id' => 5])
     */
    public static function handle_event( $event_name, $payload ) {
        if ( empty( $payload['vendor_id'] ) ) {
            return;
        }

        // 1. Get applicable rules for this event
        $rules = self::get_rules_for_trigger( $event_name );

        foreach ( $rules as $rule ) {
            self::process_order_rule( $rule, $payload );
        }
    }

    /**
     * Scan and apply Recurring Charges (Monthly/Yearly)
     * Target: All Active Vendors
     */
    public static function process_recurring_charges() {
        global $wpdb;
        $day_of_month = (int) date( 'j' ); // 1-31
        $month_of_year = (int) date( 'n' ); // 1-12
        $rule_table = $wpdb->prefix . 'zh_charge_rules';

        // 1. Get applicable rules for today
        // Monthly: billing_day matches
        // Yearly: billing_day AND billing_month matches
        $rules = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $rule_table 
             WHERE condition_type = 'recurring' 
             AND status = 'active'
             AND (
                (recurrence_type = 'monthly' AND billing_day = %d) OR 
                (recurrence_type = 'yearly' AND billing_day = %d AND billing_month = %d)
             )",
            $day_of_month, $day_of_month, $month_of_year
        ) );

        if ( empty( $rules ) ) {
            return;
        }

        // 2. Fetch All Vendors
        $vendors = get_users( [ 'role' => 'seller', 'fields' => 'ID' ] );
        if ( empty( $vendors ) ) {
            return;
        }

        foreach ( $rules as $rule ) {
            $period = ( $rule->recurrence_type === 'monthly' ) ? date( 'Y-m' ) : date( 'Y' );
            
            foreach ( $vendors as $vendor_id ) {
                self::apply_recurring_charge( $rule, $vendor_id, $period );
            }
        }
    }

    /**
     * Apply a recurring charge to a specific vendor with deduplication
     */
    private static function apply_recurring_charge( $rule, $vendor_id, $period ) {
        global $wpdb;
        $log_table = $wpdb->prefix . 'zh_recurring_log';

        // 1. DEDUPLICATION: Attempt to log the execution target
        // Relying on UNIQUE KEY (rule_id, entity_id, billing_period)
        $inserted = $wpdb->insert(
            $log_table,
            [
                'rule_id'        => $rule->id,
                'entity_type'    => 'vendor',
                'entity_id'      => $vendor_id,
                'billing_period' => $period
            ]
        );

        if ( ! $inserted ) {
            // Already charged for this period
            return;
        }

        // 2. Execute Ledger Entry
        $amount = $rule->amount_value; // Fixed amount for recurring
        $impact = $rule->impact_slug;

        $from_entity = [ 'type' => 'vendor', 'id' => $vendor_id, 'nature' => 'claim' ];
        $to_entity   = [ 'type' => 'admin',  'id' => 0,          'nature' => 'real'  ];

        LedgerService::record(
            $from_entity,
            $to_entity,
            $amount,
            $impact,
            'recurring',
            $rule->id,
            $rule->lock_type,
            null,
            sprintf( 'Automated Recurring Charge: %s (%s)', $rule->name, $period )
        );
    }

    /**
     * Process an Order-based rule
     */
    private static function process_order_rule( $rule, $payload ) {
        if ( empty( $payload['order_id'] ) ) {
            return;
        }

        $order = wc_get_order( $payload['order_id'] );
        if ( ! $order ) {
            return;
        }

        $basis_amount = $order->get_subtotal();
        $charge_amount = ( $rule->amount_type === 'percentage' ) 
            ? ( $basis_amount * $rule->amount_value ) / 100 
            : $rule->amount_value;

        if ( $charge_amount <= 0 ) {
            return;
        }

        // Handle Split
        $receivers = [];
        if ( $rule->split_enabled ) {
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
            $receivers[] = [
                'entity_type' => $rule->to_entity_type,
                'amount'      => $charge_amount,
                'impact'      => $rule->impact_slug
            ];
        }

        $from_id = ( $rule->from_entity_type === 'buyer' ) ? $payload['customer_id'] : $payload['vendor_id'];
        $from_entity = [ 'type' => $rule->from_entity_type, 'id' => $from_id, 'nature' => 'claim' ];

        foreach ( $receivers as $receiver ) {
            $to_entity = [
                'type'   => $receiver['entity_type'],
                'id'     => 0,
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
}
