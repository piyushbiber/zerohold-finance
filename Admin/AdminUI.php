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
        
        // Handle Form Submission (Add Rule) - Simplified for Phase 5 MVP
        if ( isset( $_POST['zh_add_rule'] ) && check_admin_referer( 'zh_add_rule_nonce' ) ) {
            $wpdb->insert(
                $table_name,
                [
                    'name'             => sanitize_text_field( $_POST['rule_name'] ),
                    'trigger_event'    => sanitize_text_field( $_POST['trigger_event'] ),
                    'from_entity_type' => sanitize_text_field( $_POST['from_entity'] ),
                    'to_entity_type'   => sanitize_text_field( $_POST['to_entity'] ),
                    'impact_slug'      => sanitize_text_field( $_POST['impact'] ),
                    'amount_type'      => sanitize_text_field( $_POST['amount_type'] ),
                    'amount_value'     => floatval( $_POST['amount_value'] ),
                ]
            );
            echo '<div class="updated"><p>Rule added successfully.</p></div>';
        }

        // Fetch Rules
        $rules = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC" );

        ?>
        <div class="wrap">
            <h1><?php _e( 'Charge Rules Management', 'zerohold-finance' ); ?></h1>
            <p><?php _e( 'Define automated financial rules triggered by system events.', 'zerohold-finance' ); ?></p>

            <h2 class="title"><?php _e( 'Active Rules', 'zerohold-finance' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e( 'Name', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Trigger', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Flow', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Impact', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Value', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Status', 'zerohold-finance' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $rules ) : ?>
                        <?php foreach ( $rules as $rule ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $rule->name ); ?></strong></td>
                                <td><code><?php echo esc_html( $rule->trigger_event ); ?></code></td>
                                <td><?php echo esc_html( ucfirst($rule->from_entity_type) . ' &rarr; ' . ucfirst($rule->to_entity_type) ); ?></td>
                                <td><code><?php echo esc_html( $rule->impact_slug ); ?></code></td>
                                <td><?php echo $rule->amount_type === 'percentage' ? esc_html( $rule->amount_value ) . '%' : wc_price( $rule->amount_value ); ?></td>
                                <td><span class="dashicons dashicons-yes" style="color: green;"></span> <?php echo esc_html( ucfirst( $rule->status ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="6"><?php _e( 'No rules defined yet.', 'zerohold-finance' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <hr>

            <h2><?php _e( 'Add New Rule', 'zerohold-finance' ); ?></h2>
            <form method="post" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; max-width: 600px;">
                <?php wp_nonce_field( 'zh_add_rule_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label><?php _e( 'Rule Name', 'zerohold-finance' ); ?></label></th>
                        <td><input type="text" name="rule_name" class="regular-text" required placeholder="e.g. 10% Platform Commission"></td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'Trigger Event', 'zerohold-finance' ); ?></label></th>
                        <td>
                            <select name="trigger_event">
                                <option value="order_completed">order_completed</option>
                                <option value="shipping_purchased">shipping_purchased</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'From Entity', 'zerohold-finance' ); ?></label></th>
                        <td>
                            <select name="from_entity">
                                <option value="vendor">Vendor</option>
                                <option value="buyer">Buyer</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'To Entity', 'zerohold-finance' ); ?></label></th>
                        <td>
                            <select name="to_entity">
                                <option value="platform">Platform (System)</option>
                                <option value="admin">Admin (Personal)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'Impact (Taxonomy)', 'zerohold-finance' ); ?></label></th>
                        <td><input type="text" name="impact" class="regular-text" required placeholder="e.g. fee"></td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'Amount Type', 'zerohold-finance' ); ?></label></th>
                        <td>
                            <select name="amount_type">
                                <option value="percentage"><?php _e( 'Percentage (%)', 'zerohold-finance' ); ?></option>
                                <option value="fixed"><?php _e( 'Fixed Amount', 'zerohold-finance' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e( 'Value', 'zerohold-finance' ); ?></label></th>
                        <td><input type="number" step="0.01" name="amount_value" class="small-text" required value="10"></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="zh_add_rule" class="button button-primary" value="<?php _e( 'Create Rule', 'zerohold-finance' ); ?>">
                </p>
            </form>
        </div>
        <?php
    }
}
