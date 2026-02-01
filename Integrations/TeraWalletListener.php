<?php

namespace ZeroHold\Finance\Integrations;

use ZeroHold\Finance\Core\FinanceIngress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TeraWalletListener {

    public static function init() {
        // Hook into TeraWallet (WooWallet) transaction
        add_action( 'woo_wallet_payment_processed', [ __CLASS__, 'handle_wallet_payment' ], 10, 2 );
        add_action( 'woo_wallet_transaction_recorded', [ __CLASS__, 'handle_transaction' ], 10, 1 );
    }

    /**
     * Handle Wallet Recharge (Credit)
     */
    public static function handle_transaction( $transaction_id ) {
        // This is a stub logic. We need to query the transaction details.
        // Assuming we get the transaction object or ID.
        
        // $transaction = get_wallet_transaction( $transaction_id );
        // if ( $transaction->type == 'credit' ) { ... }
        
        // For Proof of Concept, we define the method.
        // Real implementation requires checking TeraWallet's specific data structure.
    }
    
    /**
     * Handle Payment via Wallet (Usage)
     * This is effectively the "Buyer Charge" for the Order itself (not shipping).
     * But our Finance system tracks Buyer Claims.
     * When Buyer pays for Order #123 using Wallet:
     * From: Buyer (Claim) -> To: Admin (Real)
     * Impact: 'order_payment'
     */
    public static function handle_wallet_payment( $order_id, $amount ) {
         $order = wc_get_order( $order_id );
         $user_id = $order->get_user_id();
         
         if ( ! $user_id ) return;

         FinanceIngress::handle_event([
            'from' => [ 'type' => 'buyer', 'id' => $user_id, 'nature' => 'claim' ],
            'to'   => [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ],
            'amount' => $amount,
            'impact' => 'shipping_charge_buyer', // Or generalized 'order_payment' if covering products too
            'reference_type' => 'order',
            'reference_id' => $order_id
         ]);
    }
}
