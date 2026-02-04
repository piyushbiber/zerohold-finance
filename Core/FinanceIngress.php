<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FinanceIngress {

    // STRICT GOVERNANCE: Impact Allowlist
    const ALLOWED_IMPACTS = [
        'earnings',                 // Order Net Earnings
        'earnings_reversal',        // Order Refund/Return
        'commission',               // Platform Commission
        'shipping_charge',          // Vendor pays shipping (generic)
        'shipping_charge_buyer',    // Buyer pays shipping
        'shipping_charge_vendor',   // Vendor pays shipping
        'shipping_cost_actual',     // Real cost to courier
        'wallet_recharge',          // Buyer adds funds
        'withdrawal',               // Payout
        'tax_deduction',            // TCS/TDS if needed
        'sms_fee',                   // Automated charges
        'correction_credit',
        'correction_debit',
        'return_shipping'
    ];

    /**
     * Handle incoming financial event.
     * 
     * @param array $payload
     * @return string|WP_Error Group ID or Error.
     */
    public static function handle_event( $payload ) {
        
        // 1. Validate Structure
        if ( empty( $payload['from'] ) || empty( $payload['to'] ) || empty( $payload['amount'] ) || empty( $payload['impact'] ) ) {
            return new \WP_Error( 'zh_finance_invalid_payload', 'Missing required fields' );
        }

        // 2. Validate Impact (Governance)
        if ( ! in_array( $payload['impact'], self::ALLOWED_IMPACTS ) ) {
            return new \WP_Error( 'zh_finance_invalid_impact', 'Impact not in allowlist: ' . $payload['impact'] );
        }

        // 3. Validate Entities
        $from = $payload['from'];
        $to   = $payload['to'];

        if ( ! isset( $from['type'], $from['id'], $from['nature'] ) || ! isset( $to['type'], $to['id'], $to['nature'] ) ) {
             return new \WP_Error( 'zh_finance_invalid_entity', 'Invalid entity structure' );
        }
        
        // 4. Record to Ledger
        $group_id = LedgerService::record(
            $from,
            $to,
            $payload['amount'],
            $payload['impact'],
            $payload['reference_type'] ?? 'system',
            $payload['reference_id'] ?? 0,
            $payload['lock_type'] ?? 'none',
            $payload['unlock_at'] ?? null
        );

        if ( ! $group_id ) {
            return new \WP_Error( 'zh_finance_ledger_fail', 'Failed to record transaction' );
        }

        return $group_id;
    }
}
