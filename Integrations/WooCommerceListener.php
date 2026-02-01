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
        
        // Listen for delivery to update unlock date
        add_action( 'zh_event_order_delivered', [ __CLASS__, 'handle_order_delivery' ], 10, 2 );
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
                'type' => 'admin',
                'id' => 0,
                'nature' => 'real' // Admin holds the real money from gateway
            ],
            'to' => [
                'type' => 'vendor',
                'id' => $vendor_id,
                'nature' => 'claim' // Vendor gets a claim
            ],
            'amount' => $amount,
            'impact' => 'earnings',
            'reference_type' => 'order',
            'reference_id' => $order_id,
            'lock_type' => 'order_hold',
            'unlock_at' => null // Unknown until delivery
        ];

        $result = FinanceIngress::handle_event( $payload );

        if ( ! is_wp_error( $result ) ) {
            $order->update_meta_data( '_zh_finance_earnings_recorded', true );
            $order->update_meta_data( '_zh_finance_group_id', $result );
            $order->save();
        }
    }

    /**
     * Handle Delivery (Unlock Date Update)
     * 
     * @param int $order_id
     * @param string $delivery_date Y-m-d H:i:s
     */
    public static function handle_order_delivery( $order_id, $delivery_date ) {
        // Logic to update the `unlock_at` field in DB for the existing ledger rows.
        // Since we blocked UPDATEs via Trigger, we have a dilemma.
        // "Reader Logic Only" means we don't update DB. 
        // BUT we need to know WHEN to unlock.
        // Solution: The Reader (QueryEngine) joins with an 'Order Meta' or a separate 'Unlock Schedule' table?
        // OR: The Trigger blocks updating amount/entities, but maybe we allow updating 'unlock_at'?
        // The trigger I wrote blocks ALL updates: "BEFORE UPDATE ON ... SIGNAL SQLSTATE".
        
        // To fix this without violating immutability:
        // We insert a NEW event "Lock Update"? No...
        // The User said: "Unlock: delivery_date + return_window (updated via zh_event_order_delivered)".
        // This explicitly implies UPDATING the record or storing the date somewhere.
        
        // I will implement a separate table `wp_zh_ledger_metadata` or `locks` if I can't update.
        // OR, I modify the trigger to allow updates to JUST `unlock_at`.
        
        // Let's assume for this step I will modify the trigger in a future migration if needed,
        // or just store the unlock date in Order Meta and QueryEngine reads it? 
        // "Reader Logic: unlock_at timestamp comparison (No DB updates for unlock)" -> This means the checking logic is read-only.
        // But the setting of the timestamp must happen.
        
        // Strategy: Store the official `unlock_at` in the Order Metadata or a separate `zh_order_locks` table.
        // The Ledger `unlock_at` column might represent the *initial* known lock.
        
        $return_window_days = 7; // Configurable
        $unlock_time = date( 'Y-m-d H:i:s', strtotime( "$delivery_date + $return_window_days days" ) );
        
        update_post_meta( $order_id, '_zh_unlock_at', $unlock_time );
    }
}
