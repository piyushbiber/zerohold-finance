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

        // Basic Validation
        if ( ! is_numeric( $amount ) || $amount <= 0 ) {
            return false;
        }

        // Generate Group ID
        $group_id = wp_generate_uuid4();

        /**
         * ⚖️ ARCHITECTURAL INVARIANT (PHASE 15):
         * Buyer accounting is EXCLUSIVELY managed by TeraWallet.
         * The ZeroHold Finance Ledger must NEVER store buyer balances or recharges.
         * Buyer data is informational only and never part of platform equity math.
         */
        if ( $from_entity['type'] === 'buyer' || $to_entity['type'] === 'buyer' ) {
            error_log( "ZH Finance Block: Attempted to record ledger entry for 'buyer' entity. Blocked for decoupling integrity." );
            return false;
        }

        // Start Transaction
        $wpdb->query( 'START TRANSACTION' );

        try {
            // 1. Debit the Sender (From)
            // DEBITS are never locked (Sender has already parted with the value)
            $debit_inserted = $wpdb->insert(
                $table,
                [
                    'entry_group_id' => $group_id,
                    'entity_type'    => $from_entity['type'],
                    'entity_id'      => $from_entity['id'],
                    'amount'         => -1 * abs( $amount ), // Signed Negative
                    'category'       => 'debit',
                    'impact'         => $impact,
                    'money_nature'   => $from_entity['nature'], // real vs claim
                    'lock_type'      => 'none',
                    'unlock_at'      => null,
                    'reference_type' => $reference_type,
                    'reference_id'   => $reference_id,
                    'reason'         => $reason,
                    'admin_id'       => $admin_id,
                ]
            );

            // 2. Credit the Receiver (To)
            // CREDITS are locked IF requested AND NOT a buyer (Buyers never sit in escrow)
            $final_lock   = ( $to_entity['type'] === 'buyer' ) ? 'none' : $lock_type;
            $final_unlock = ( $to_entity['type'] === 'buyer' ) ? null : $unlock_at;

            $credit_inserted = $wpdb->insert(
                $table,
                [
                    'entry_group_id' => $group_id,
                    'entity_type'    => $to_entity['type'],
                    'entity_id'      => $to_entity['id'],
                    'amount'         => abs( $amount ), // Positive
                    'category'       => 'credit',
                    'impact'         => $impact,
                    'money_nature'   => $to_entity['nature'], // real vs claim
                    'lock_type'      => $final_lock,
                    'unlock_at'      => $final_unlock,
                    'reference_type' => $reference_type,
                    'reference_id'   => $reference_id,
                    'reason'         => $reason,
                    'admin_id'       => $admin_id,
                ]
            );

            if ( $debit_inserted === false || $credit_inserted === false ) {
                throw new \Exception( 'Failed to insert ledger rows.' );
            }

            $wpdb->query( 'COMMIT' );

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
