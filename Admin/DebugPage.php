<?php

namespace ZeroHold\Finance\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugPage {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 99 );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Finance Debug',
            'Finance Debug',
            'manage_options',
            'zh-finance-debug',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function render_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';

        $vendor_id = isset($_GET['vid']) ? intval($_GET['vid']) : 0;

        echo '<div class="wrap">';
        echo '<h1>ZeroHold Finance Debug</h1>';

        // If no vendor ID, show all vendors
        if (!$vendor_id) {
            echo '<h2>All Vendors with Finance Activity</h2>';
            
            $vendors_raw = $wpdb->get_results(
                "SELECT DISTINCT entity_id FROM $table WHERE entity_type = 'vendor' ORDER BY entity_id"
            );
            
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>Vendor ID</th><th>Total</th><th>Locked (Pending)</th><th>Available</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($vendors_raw as $v) {
                $vid = $v->entity_id;
                $total = \ZeroHold\Finance\Core\QueryEngine::get_wallet_balance('vendor', $vid);
                $locked = \ZeroHold\Finance\Core\QueryEngine::get_locked_balance('vendor', $vid);
                $available = \ZeroHold\Finance\Core\QueryEngine::get_withdrawable_balance('vendor', $vid);
                
                $color = $available < 0 ? 'red' : 'green';
                
                echo '<tr>';
                echo '<td><strong>' . $vid . '</strong></td>';
                echo '<td>₹' . number_format($total, 2) . '</td>';
                echo '<td>₹' . number_format($locked, 2) . '</td>';
                echo '<td style="color: ' . $color . '"><strong>₹' . number_format($available, 2) . '</strong></td>';
                echo '<td><a href="?page=zh-finance-debug&vid=' . $vid . '" class="button">View Details</a></td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            // Show specific vendor details
            echo '<h2>Finance Ledger for Vendor #' . $vendor_id . '</h2>';
            echo '<p><a href="?page=zh-finance-debug" class="button">← Back to all vendors</a></p>';

            // Get all entries
            $entries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE entity_type = 'vendor' AND entity_id = %d ORDER BY id DESC LIMIT 50",
                $vendor_id
            ));

            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Amount</th><th>Impact</th><th>Lock Type</th><th>Unlock At</th><th>Reference</th><th>Created</th></tr></thead>';
            echo '<tbody>';

            foreach ($entries as $entry) {
                $is_locked = ($entry->lock_type != 'none' && (is_null($entry->unlock_at) || strtotime($entry->unlock_at) > time()));
                $row_style = $is_locked ? 'background: #fff3cd;' : '';
                
                echo '<tr style="' . $row_style . '">';
                echo '<td>' . $entry->id . '</td>';
                echo '<td style="color: ' . ($entry->amount < 0 ? 'red' : 'green') . '"><strong>₹' . number_format($entry->amount, 2) . '</strong></td>';
                echo '<td><code>' . $entry->impact . '</code></td>';
                echo '<td><strong>' . $entry->lock_type . '</strong></td>';
                echo '<td>' . ($entry->unlock_at ?: 'NULL') . '</td>';
                echo '<td>' . $entry->reference_type . ' #' . $entry->reference_id . '</td>';
                echo '<td>' . $entry->created_at . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';

            // Use QueryEngine for consistency
            $total = \ZeroHold\Finance\Core\QueryEngine::get_wallet_balance('vendor', $vendor_id);
            $locked = \ZeroHold\Finance\Core\QueryEngine::get_locked_balance('vendor', $vendor_id);
            $available = \ZeroHold\Finance\Core\QueryEngine::get_withdrawable_balance('vendor', $vendor_id);

            echo '<div class="notice notice-info" style="margin-top: 20px; padding: 15px;">';
            echo '<h3>Balance Summary</h3>';
            echo '<p><strong>Total Balance:</strong> ₹' . number_format($total ?: 0, 2) . '</p>';
            echo '<p><strong>Locked Balance (Pending):</strong> ₹' . number_format($locked ?: 0, 2) . '</p>';
            echo '<p style="color: ' . ($available < 0 ? 'red' : 'green') . '; font-size: 16px;"><strong>Available Balance: ₹' . number_format($available, 2) . '</strong></p>';
            echo '</div>';
        }

        echo '</div>';
    }
}
