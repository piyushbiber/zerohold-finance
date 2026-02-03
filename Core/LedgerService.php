<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LedgerService {

    /**
     * Prevent infinite loops during wallet sync
     */
    public static $is_syncing = false;

    /**
     * Record a transaction in the ledger.
     * Enforces double-entry bookkeeping.
     *
     * @param array $from_entity Originating entity.
     * @param array $to_entity Receiving entity.
     * @param float $amount Amount (positive).
     * @param string $impact Taxonomy slug.
     * @param string $reference_type
     * @param int    $reference_id
     * @param string $lock_type
     * @param string $unlock_at (Y-m-d H:i:s)
     * @param string $reason Audit note
     * @param int    $admin_id Admin user who applied the charge
     * @return string|false Entry Group ID on success, false on failure.
     */
    public static function record( $from_entity, $to_entity, $amount, $impact, $reference_type, $reference_id, $lock_type = 'none', $unlock_at = null, $reason = null, $admin_id = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        error_log( sprintf( 
            "ZH Finance Debug: Recording %s impact for Order #%d. Amount: %f, Lock: %s, From: %s:%d, To: %s:%d",
            $impact, $reference_id, $amount, $lock_type, $from_entity['type'], $from_entity['id'], $to_entity['type'], $to_entity['id']
        ) );

        // Basic Validation
        if ( ! is_numeric( $amount ) || $amount <= 0 ) {
            error_log( "ZH Finance Error: Invalid amount ($amount)" );
            return false;
        }

        // Generate Group ID
        $group_id = wp_generate_uuid4();

        // ⚖️ Decoupling Guard (Phase 15)
        if ( $from_entity['type'] === 'buyer' || $to_entity['type'] === 'buyer' ) {
            error_log( "ZH Finance Block: Buyer entity blocked for decoupling integrity." );
            return false;
        }

        // Start Transaction
        $wpdb->query( 'START TRANSACTION' );

        // --- FINAL PRODUCTION GUARD: Force order_hold for known Order-based charges ---
        $locked_impacts = [ 'shipping_charge', 'shipping_charge_vendor', 'shipping_charge_buyer', 'commission', 'platform_fee', 'sms' ];
        if ( $reference_type === 'order' && in_array( $impact, $locked_impacts ) ) {
            $lock_type = 'order_hold';
        }

        try {
            // 1. Debit the Sender (From)
            $debit_inserted = $wpdb->insert(
                $table,
                [
                    'entry_group_id' => $group_id,
                    'entity_type'    => $from_entity['type'],
                    'entity_id'      => $from_entity['id'],
                    'amount'         => -1 * abs( $amount ), // Signed Negative
                    'category'       => 'debit',
                    'impact'         => $impact,
                    'money_nature'   => $from_entity['nature'],
                    'lock_type'      => $lock_type,
                    'unlock_at'      => $unlock_at,
                    'reference_type' => $reference_type,
                    'reference_id'   => $reference_id,
                    'reason'         => $reason,
                    'admin_id'       => $admin_id,
                ]
            );

            // 2. Credit the Receiver (To)
            $credit_inserted = $wpdb->insert(
                $table,
                [
                    'entry_group_id' => $group_id,
                    'entity_type'    => $to_entity['type'],
                    'entity_id'      => $to_entity['id'],
                    'amount'         => abs( $amount ), // Positive
                    'category'       => 'credit',
                    'impact'         => $impact,
                    'money_nature'   => $to_entity['nature'],
                    'lock_type'      => $lock_type,
                    'unlock_at'      => $unlock_at,
                    'reference_type' => $reference_type,
                    'reference_id'   => $reference_id,
                    'reason'         => $reason,
                    'admin_id'       => $admin_id,
                ]
            );

            if ( $debit_inserted === false || $credit_inserted === false ) {
                throw new \Exception( 'DB Insert Error: ' . $wpdb->last_error );
            }

            $wpdb->query( 'COMMIT' );
            error_log( "ZH Finance Success: Ledger recorded successfully (Group: $group_id)" );

            // --- Post-Commit Hooks ---
            do_action( 'zh_finance_ledger_recorded', $group_id, [
                'from'           => $from_entity,
                'to'             => $to_entity,
                'amount'         => $amount,
                'impact'         => $impact,
                'reference_type' => $reference_type,
                'reference_id'   => $reference_id,
                'reason'         => $reason
            ]);

            return $group_id;

        } catch ( \Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            error_log( 'ZH Finance Ledger Error: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Unlock funds by updating lock_type (This is allowed as it's not changing AMOUNT or ID)
     * However, strictly speaking, our trigger blocks UPDATES on the ledger table.
     * So we might need to bypass the trigger or use a separate lock table if updates are blocked.
     * 
     * CORRECTION: The user mandated "Reader Logic" for unlocking in Phase 3.
     * "No DB updates for unlock".
     * So we strictly DO NOT update the row. Reference implementations will just check NOW() > unlock_at.
     */
}
