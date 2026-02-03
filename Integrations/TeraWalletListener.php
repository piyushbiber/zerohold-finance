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

        $amount = (float) $transaction->amount;
        $user_id = $transaction->user_id;

        if ( $transaction->type === 'credit' ) {
            // PULL: TeraWallet Credit (Recharge) 
            // 1. Record Real Cash entering the Admin Bank Pool
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'outside', 'id' => 0, 'nature' => 'real' ],
                'to'   => [ 'type' => 'admin',   'id' => 0, 'nature' => 'real' ],
                'amount' => $amount,
                'impact' => 'wallet_recharge',
                'reference_type' => 'terawallet',
                'reference_id'   => $transaction_id,
                'reason' => 'Cash Received: ' . ($transaction->details ?: 'Recharge')
            ]);

            // 2. Record the liability to the buyer (Claim issued)
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'outside', 'id' => 0, 'nature' => 'claim' ],
                'to'   => [ 'type' => 'buyer',   'id' => $user_id, 'nature' => 'claim' ],
                'amount' => $amount,
                'impact' => 'wallet_recharge',
                'reference_type' => 'terawallet',
                'reference_id'   => $transaction_id,
                'reason' => 'User Credit: ' . ($transaction->details ?: 'Recharge')
            ]);
        } else {
            // PULL: TeraWallet Debit
            // If it's for an order, WooCommerceListener handles the move from Buyer -> Vendor.
            // We detect this by checking if the details contain an order ID or known WooCommerce pattern.
            // Standard TeraWallet order payment description: "For order #123 payment" or similar.
            if ( preg_match( '/order #?\d+/i', $transaction->details ) ) {
                \ZeroHold\Finance\Core\LedgerService::$is_syncing = false;
                return;
            }

            // If it's a general debit, we move from Buyer Claim to Admin Claim (reducing liabilities).
            FinanceIngress::handle_event([
                'from' => [ 'type' => 'buyer', 'id' => $user_id, 'nature' => 'claim' ],
                'to'   => [ 'type' => 'admin', 'id' => 0,        'nature' => 'claim' ],
                'amount' => $amount,
                'impact' => 'wallet_payment',
                'reference_type' => 'terawallet',
                'reference_id'   => $transaction_id,
                'reason' => $transaction->details ?: 'TeraWallet Payment'
            ]);
        }

        \ZeroHold\Finance\Core\LedgerService::$is_syncing = false;
    }
}
