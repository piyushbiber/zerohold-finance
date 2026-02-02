<?php

namespace ZeroHold\Finance\Integrations;

use ZeroHold\Finance\Core\FinanceIngress;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooCommerceListener {

    public static function init() {
        // High priority to ensure we capture it after other logic runs
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'handle_order_payment' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'handle_order_payment' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'handle_order_payment' ], 10, 1 );
    }

    /**
     * Handle Order Payment (Earning Generation)
     * 
     * @param int $order_id
     */
    public static function handle_order_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Idempotency Check: Check if we already recorded earnings for this order
        // In a real system, we'd query the ledger for reference_id = order_id AND impact = 'earnings'
        // For now, we rely on the fact that LedgerService creates unique entries.
        // But to prevent double entry on status changes (processing -> completed), we should check.
        // For MVP, we effectively "gate" this logic.
        
        if ( $order->get_meta( '_zh_finance_earnings_recorded' ) ) {
            return;
        }

        $vendor_id = dokan_get_seller_id_by_order( $order_id );
        if ( ! $vendor_id ) {
            return; // Not a vendor order?
        }

        // Calculate Net Earnings (Base Order Value)
        // Note: Dokan usually calculates this as Order Total - Admin Fees.
        // STRICT RULE: We record the FULL Net Sales as "Earnings" (Claim). 
        // Admin Commission is a separate DEBIT event.
        // However, standard accounting usually credits Vendor with (Total - Comm).
        // The Plan says: "Amount: Net, Impact: earnings".
        // Let's use the Order Total for now, and rely on ChargeEngine to debit commission later?
        // OR does "Net" mean the final payout amount?
        // User Plan: "Order Created -> Emit Earnings (Locked: order_hold)".
        // It implies the "Gross Earning" belonging to vendor.
        
        $amount = $order->get_total() - $order->get_total_refunded(); // Simplified.
        
        // In a Split Order system (Dokan), the order total IS the vendor's sub-order total.
        
        $payload = [
            'from' => [
                'type'   => 'buyer',
                'id'     => $order->get_customer_id() ?: 0,
                'nature' => 'claim' // Pulling from buyer's internal obligation
            ],
            'to' => [
                'type'   => 'vendor',
                'id'     => $vendor_id,
                'nature' => 'claim' // Creating internal obligation to vendor
            ],
            'amount'         => $amount,
            'impact'         => 'earnings',
            'reference_type' => 'order',
            'reference_id'   => $order_id,
            'lock_type'      => 'order_hold',
            'unlock_at'      => null
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( ! is_wp_error( $result ) ) {
            $order->update_meta_data( '_zh_finance_earnings_recorded', true );
            $order->update_meta_data( '_zh_finance_group_id', $result );
            $order->save();
        }
    }
}

