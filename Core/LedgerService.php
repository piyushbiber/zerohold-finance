<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LedgerService {

    /**
     * Record a transaction in the ledger.
     * Enforces double-entry bookkeeping.
     *
     * @param array $from_entity Originating entity.
     * @param array $to_entity Receiving entity.
     * @param float $amount Amount (positive).
     * @param string $impact Taxonomy slug.
     * @param string $reference_type
     * @param int $reference_id
     * @param string $lock_type
     * @param string $unlock_at (Y-m-d H:i:s)
     * @return string|false Entry Group ID on success, false on failure.
     */
    public static function record( $from_entity, $to_entity, $amount, $impact, $reference_type, $reference_id, $lock_type = 'none', $unlock_at = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        // Basic Validation
        if ( ! is_numeric( $amount ) || $amount <= 0 ) {
            return false;
        }

        // Generate Group ID
        $group_id = wp_generate_uuid4();

        // Start Transaction
        $wpdb->query( 'START TRANSACTION' );

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
                    'money_nature'   => $from_entity['nature'], // real vs claim
                    'lock_type'      => $lock_type,
                    'unlock_at'      => $unlock_at,
                    'reference_type' => $reference_type,
                    'reference_id'   => $reference_id,
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
                    'money_nature'   => $to_entity['nature'], // real vs claim
                    'lock_type'      => $lock_type,
                    'unlock_at'      => $unlock_at,
                    'reference_type' => $reference_type,
                    'reference_id'   => $reference_id,
                ]
            );

            if ( $debit_inserted === false || $credit_inserted === false ) {
                throw new \Exception( 'Failed to insert ledger rows.' );
            }

            $wpdb->query( 'COMMIT' );
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
