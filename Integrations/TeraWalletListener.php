<?php

namespace ZeroHold\Finance\Integrations;

use ZeroHold\Finance\Core\FinanceIngress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeraWalletListener {

    public static function init() {
        // 1. Listen for changes in ZeroHold Ledger -> Push to TeraWallet
        add_action( 'zh_finance_ledger_recorded', [ __CLASS__, 'sync_ledger_to_terawallet' ], 10, 2 );

        // 2. Listen for changes in TeraWallet -> Pull into ZeroHold Ledger
        add_action( 'woo_wallet_transaction_recorded', [ __CLASS__, 'sync_terawallet_to_ledger' ], 10, 1 );
    }

    /**
     * PUSH: ZeroHold Ledger -> TeraWallet
     */
    public static function sync_ledger_to_terawallet( $group_id, $data ) {
        if ( \ZeroHold\Finance\Core\LedgerService::$is_syncing ) {
            return;
        }

        // Phase 10: Sync Border
        // Skip pushing back to TeraWallet if the source was already a TeraWallet transaction
        // or if it's a WooCommerce order (handled natively by WooCommerce/TeraWallet checkout).
        $ref_type = $data['reference_type'] ?? '';
        if ( in_array( $ref_type, [ 'order', 'terawallet' ] ) ) {
            return;
        }

        $entities = [ 'from', 'to' ];
        foreach ( $entities as $dir ) {
            $entity = $data[$dir];
            if ( $entity['type'] === 'buyer' ) {
                $user_id = $entity['id'];
                $amount  = $data['amount'];
                $reason  = $data['reason'] ?: "ZeroHold Finance: {$data['impact']}";

                // Prevent infinite loop
                \ZeroHold\Finance\Core\LedgerService::$is_syncing = true;

                if ( $dir === 'from' ) {
                    // DEBIT Buyer in TeraWallet
                    if ( function_exists( 'woo_wallet' ) ) {
                        woo_wallet()->wallet->debit( $user_id, $amount, $reason );
                    }
                } else {
                    // CREDIT Buyer in TeraWallet
                    if ( function_exists( 'woo_wallet' ) ) {
                        woo_wallet()->wallet->credit( $user_id, $amount, $reason );
                    }
                }

                \ZeroHold\Finance\Core\LedgerService::$is_syncing = false;
            }
        }
    }

    /**
     * PULL: TeraWallet -> ZeroHold Ledger
     */
    public static function sync_terawallet_to_ledger( $transaction_id ) {
        if ( \ZeroHold\Finance\Core\LedgerService::$is_syncing ) {
            return;
        }

        global $wpdb;
        $transaction = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woo_wallet_transactions WHERE id = %d", $transaction_id ) );

        if ( ! $transaction ) {
            return;
        }

        // Avoid double-processing if the description matches our own push
        if ( strpos( $transaction->details, 'ZeroHold Finance' ) !== false ) {
            return;
        }

        \ZeroHold\Finance\Core\LedgerService::$is_syncing = true;

        /**
         * ⚖️ ARCHITECTURAL INVARIANT (PHASE 15):
         * Pull-sync for recharges is DISABLED.
         * Recharges in TeraWallet are external cash flows.
         * We no longer record them in the internal ledger to prevent "Phantom Profit".
         */
        \ZeroHold\Finance\Core\LedgerService::$is_syncing = false;
        return;

        \ZeroHold\Finance\Core\LedgerService::$is_syncing = false;
    }
}
