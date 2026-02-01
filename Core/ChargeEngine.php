<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use ZeroHold\Finance\Core\LedgerService;
use Throwable;

class ChargeEngine {

    /**
     * Listen to Canonical Events and specific Rules
     */
    public static function init() {
        // Core Event Listener for Rule Processing
        // We listen to our own ingress events, so we can chain logic.
        add_action( 'zh_finance_event', [ __CLASS__, 'process_rules_for_event' ], 10, 3 );
    }

    /**
     * Process automated charge rules for a given event.
     * 
     * @param string $event_name The canonical event name (e.g., zh_event_order_created)
     * @param array $payload The validated payload from Ingress
     * @param string $event_id The unique UUID of the ingress event
     */
    public static function process_rules_for_event( $event_name, $payload, $event_id ) {
        // 1. Fetch Active Rules for this Trigger
        $rules = self::get_rules_by_trigger( $event_name );

        if ( empty( $rules ) ) {
            return;
        }

        foreach ( $rules as $rule ) {
            try {
                // 2. Validate Conditions
                if ( ! self::conditions_match( $rule, $payload ) ) {
                    continue;
                }

                // 3. Calculate Amount
                $amount = self::calculate_amount( $rule, $payload );

                if ( $amount <= 0 ) {
                    continue; // No charge if amount is zero
                }

                // 4. Apply to Ledger (Debit Vendor, Credit Platform/Bank)
                $impact      = $rule->impact_slug; // e.g., 'platform_commission'
                $description = $rule->description_template ?: 'Automated Charge: ' . $rule->name;

                // Replace vars in description
                $description = str_replace( '{order_id}', $payload['reference_id'] ?? '', $description );

                // Record Debit (Expense for Vendor)
                LedgerService::record_entry(
                    'vendor',
                    $payload['vendor_id'], // Assuming payload has vendor_id, standard for our events
                    $amount * -1, // Debit is negative
                    $impact,
                    $payload['reference_type'] ?? 'system',
                    $payload['reference_id'] ?? $event_id,
                    $description
                );

                // Record Credit (Income for Platform/Bank) - Double Entry
                // Usually credited to 'admin' or a specific internal account
                LedgerService::record_entry(
                    'admin',
                    1, // Main Admin ID or Platform ID
                    $amount,
                    $impact, // Same impact tag, or maybe 'commission_revenue'
                    $payload['reference_type'] ?? 'system',
                    $payload['reference_id'] ?? $event_id,
                    "Revenue from Vendor: " . $description
                );

            } catch ( Throwable $e ) {
                error_log( "ZH ChargeEngine Error [Rule {$rule->id}]: " . $e->getMessage() );
            }
        }
    }

    /**
     * Fetch rules from DB.
     * Placeholder: In Phase 5b we will add the UI to save these to DB.
     * For now, I will hardcode the 'Platform Commission' rule here to prove it works.
     */
    private static function get_rules_by_trigger( $trigger ) {
        // TODO: Replace with DB query: SELECT * FROM wp_zh_charge_rules WHERE trigger_event = %s AND is_active = 1
        
        $mock_rules = [];

        // Example Rule: 10% Platform Commission on Order Payment
        if ( $trigger === 'zh_event_order_paid' ) { // Listening to order paid, not just created
            $mock_rules[] = (object) [
                'id' => 999,
                'name' => 'Global Platform Commission',
                'trigger_event' => 'zh_event_order_paid',
                'impact_slug' => 'platform_commission',
                'calculation_type' => 'percentage', // percentage or fixed
                'calculation_value' => 10, // 10%
                'basis_field' => 'amount', // Payload field to calc against
                'description_template' => 'Platform Commission (10%) for Order #{order_id}'
            ];
        }

        return $mock_rules;
    }

    /**
     * Check if rule conditions (like category, vendor specific) match.
     */
    private static function conditions_match( $rule, $payload ) {
        // Placeholder for advanced conditions logic
        return true; 
    }

    /**
     * Calculate charge amount.
     */
    private static function calculate_amount( $rule, $payload ) {
        if ( $rule->calculation_type === 'fixed' ) {
            return (float) $rule->calculation_value;
        }

        if ( $rule->calculation_type === 'percentage' ) {
            $basis = isset( $payload[ $rule->basis_field ] ) ? (float) $payload[ $rule->basis_field ] : 0;
            return round( $basis * ( $rule->calculation_value / 100 ), 2 );
        }

        return 0;
    }
}
