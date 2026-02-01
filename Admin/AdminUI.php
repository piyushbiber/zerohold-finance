<?php

namespace ZeroHold\Finance\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AdminUI
 * 
 * Handles the WordPress Admin Menu and UI for ZeroHold Finance.
 */
class AdminUI {

    /**
     * Initialize Admin UI
     */
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
    }

    /**
     * Register Admin Menus
     */
    public static function register_menus() {
        // Main Finance Menu
        add_menu_page(
            __( 'ZeroHold Finance', 'zerohold-finance' ),
            __( 'Finance', 'zerohold-finance' ),
            'manage_options',
            'zh-finance',
            [ __CLASS__, 'render_dashboard' ],
            'dashicons-bank',
            25
        );

        // Submenu: Charge Rules
        add_submenu_page(
            'zh-finance',
            __( 'Charge Rules', 'zerohold-finance' ),
            __( 'Charge Rules', 'zerohold-finance' ),
            'manage_options',
            'zh-charge-rules',
            [ __CLASS__, 'render_charge_rules' ]
        );
    }

    /**
     * Render the main Finance Dashboard (Admin View)
     */
    public static function render_dashboard() {
        ?>
        <div class="wrap">
            <h1><?php _e( 'ZeroHold Finance Dashboard', 'zerohold-finance' ); ?></h1>
            <p><?php _e( 'Global overview of the Central Bank ledger.', 'zerohold-finance' ); ?></p>
            <hr>
            <div class="notice notice-info">
                <p><?php _e( 'Admin Dashboard is under construction. Please use "Charge Rules" to manage automation.', 'zerohold-finance' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Charge Rules Management Page
     */
    public static function render_charge_rules() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_charge_rules';
        
        // Handle Form Submission (Add Rule)
        if ( isset( $_POST['zh_add_rule'] ) && check_admin_referer( 'zh_add_rule_nonce' ) ) {
            $wpdb->insert(
                $table_name,
                [
                    'name'              => sanitize_text_field( $_POST['rule_name'] ),
                    'trigger_event'     => sanitize_text_field( $_POST['trigger_event'] ),
                    'transaction_type'  => sanitize_text_field( $_POST['transaction_type'] ),
                    'from_entity_type'  => sanitize_text_field( $_POST['from_entity'] ),
                    'to_entity_type'    => sanitize_text_field( $_POST['to_entity'] ),
                    'impact_slug'       => sanitize_text_field( $_POST['impact'] ),
                    'condition_type'    => sanitize_text_field( $_POST['condition_type'] ),
                    'amount_type'       => sanitize_text_field( $_POST['amount_type'] ),
                    'amount_value'      => floatval( $_POST['amount_value'] ),
                    'lock_type'         => sanitize_text_field( $_POST['lock_type'] ),
                    'split_enabled'     => isset( $_POST['split_enabled'] ) ? 1 : 0,
                    'admin_profit_pct'  => ! empty( $_POST['admin_profit_pct'] ) ? floatval( $_POST['admin_profit_pct'] ) : NULL,
                    'external_cost_pct' => ! empty( $_POST['external_cost_pct'] ) ? floatval( $_POST['external_cost_pct'] ) : NULL,
                    'status'            => 'active',
                ]
            );
            echo '<div class="updated"><p>Rule added successfully.</p></div>';
        }

        // Handle Status Change (Disable Only Logic)
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'toggle' && isset( $_GET['rule_id'] ) ) {
            $rule_id = intval( $_GET['rule_id'] );
            $current_status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table_name WHERE id = %d", $rule_id ) );
            $new_status = ( $current_status === 'active' ) ? 'inactive' : 'active';
            
            $wpdb->update( $table_name, [ 'status' => $new_status ], [ 'id' => $rule_id ] );
            echo '<div class="updated"><p>Rule status updated.</p></div>';
        }

        // Fetch Rules
        $rules = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC" );

        ?>
        <div class="wrap">
            <h1><?php _e( 'Charge Rules Management', 'zerohold-finance' ); ?></h1>
            <p><?php _e( 'Define automated financial rules. Rules can only be disabled, never deleted.', 'zerohold-finance' ); ?></p>

            <h2 class="title"><?php _e( 'Active Rules', 'zerohold-finance' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e( 'Rule Name', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Trigger / Condition', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Type / Flow', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Amount / Split', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Lock Type', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Status', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Actions', 'zerohold-finance' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $rules ) : ?>
                        <?php foreach ( $rules as $rule ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $rule->name ); ?></strong><br/><small>ID: #<?php echo $rule->id; ?> | Impact: <?php echo esc_html( $rule->impact_slug ); ?></small></td>
                                <td>
                                    <code><?php echo esc_html( $rule->trigger_event ); ?></code><br/>
                                    <small><?php echo esc_html( str_replace('_', ' ', $rule->condition_type) ); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo esc_html( ucfirst($rule->transaction_type) ); ?></strong><br/>
                                    <small><?php echo esc_html( ucfirst($rule->from_entity_type) . ' &rarr; ' . ucfirst($rule->to_entity_type) ); ?></small>
                                </td>
                                <td>
                                    <?php echo $rule->amount_type === 'percentage' ? esc_html( $rule->amount_value ) . '%' : wc_price( $rule->amount_value ); ?>
                                    <?php if ( $rule->split_enabled ) : ?>
                                        <div style="font-size: 0.8em; color: #666;">
                                            Split: <?php echo $rule->admin_profit_pct; ?>% Profit / <?php echo $rule->external_cost_pct; ?>% Cost
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><code style="background: #f0f0f1;"><?php echo esc_html( $rule->lock_type ); ?></code></td>
                                <td>
                                    <span class="dashicons dashicons-<?php echo $rule->status === 'active' ? 'yes' : 'no'; ?>" style="color: <?php echo $rule->status === 'active' ? 'green' : 'red'; ?>;"></span> 
                                    <?php echo esc_html( ucfirst( $rule->status ) ); ?>
                                </td>
                                <td>
                                    <a href="<?php echo add_query_arg( [ 'action' => 'toggle', 'rule_id' => $rule->id ] ); ?>" class="button button-small">
                                        <?php echo $rule->status === 'active' ? __( 'Disable', 'zerohold-finance' ) : __( 'Enable', 'zerohold-finance' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="7"><?php _e( 'No rules defined yet.', 'zerohold-finance' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <hr>

            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; max-width: 800px;">
                <h2><?php _e( 'Add New Rule', 'zerohold-finance' ); ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'zh_add_rule_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label><?php _e( 'Rule Name', 'zerohold-finance' ); ?></label></th>
                            <td><input type="text" name="rule_name" class="regular-text" required placeholder="e.g. SMS Charge"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Transaction Type', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <select name="transaction_type">
                                    <option value="debit">Debit</option>
                                    <option value="credit">Credit</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Trigger Event', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <select name="trigger_event">
                                    <option value="order_created">Order Created</option>
                                    <option value="order_completed">Order Completed</option>
                                    <option value="order_delivered">Order Delivered</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'From Entity', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <select name="from_entity">
                                    <option value="vendor">Vendor</option>
                                    <option value="buyer">Buyer</option>
                                    <option value="platform">Platform</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'To Entity', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <select name="to_entity">
                                    <option value="admin">Admin (Revenue)</option>
                                    <option value="platform">Platform (Operating)</option>
                                    <option value="vendor">Vendor</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Impact (Taxonomy)', 'zerohold-finance' ); ?></label></th>
                            <td><input type="text" name="impact" class="regular-text" required placeholder="e.g. sms_fee"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Condition', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <select name="condition_type">
                                    <option value="every_order">Every Order</option>
                                    <option value="per_item">Per Item</option>
                                    <option value="per_box">Per Box</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Charge Amount Type', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <select name="amount_type">
                                    <option value="fixed"><?php _e( 'Fixed Amount (₹)', 'zerohold-finance' ); ?></option>
                                    <option value="percentage"><?php _e( 'Percentage (%)', 'zerohold-finance' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Charge Value', 'zerohold-finance' ); ?></label></th>
                            <td><input type="number" step="0.01" name="amount_value" class="small-text" required value="0"></td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Split Rule (Optional)', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <label><input type="checkbox" name="split_enabled" id="zh_split_toggle"> <?php _e( 'Enable Split (Admin Profit vs External Cost)', 'zerohold-finance' ); ?></label>
                                <div id="zh_split_fields" style="display:none; margin-top: 10px; background: #f9f9f9; padding: 10px;">
                                    <label>Admin Profit %: <input type="number" name="admin_profit_pct" class="small-text" value="40"></label>
                                    <label style="margin-left: 20px;">External Cost %: <input type="number" name="external_cost_pct" class="small-text" value="60"></label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php _e( 'Lock Type', 'zerohold-finance' ); ?></label></th>
                            <td>
                                <select name="lock_type">
                                    <option value="none">none (Immediate)</option>
                                    <option value="order_hold">order_hold (Until Settlement)</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="zh_add_rule" class="button button-primary" value="<?php _e( 'Create Rule', 'zerohold-finance' ); ?>">
                    </p>
                </form>
            </div>
            <script>
                document.getElementById('zh_split_toggle').addEventListener('change', function() {
                    document.getElementById('zh_split_fields').style.display = this.checked ? 'block' : 'none';
                });
            </script>
        </div>
        <?php
    }
}
