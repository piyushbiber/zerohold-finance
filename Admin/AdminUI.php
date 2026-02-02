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

        // Submenu: Manual Transactions
        add_submenu_page(
            'zh-finance',
            __( 'Manual Transactions', 'zerohold-finance' ),
            __( 'Manual Transactions', 'zerohold-finance' ),
            'manage_options',
            'zh-manual-transactions',
            [ __CLASS__, 'render_manual_transactions' ]
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
        
        // Handle Form Submission
        if ( isset( $_POST['zh_save_rule'] ) && check_admin_referer( 'zh_save_rule_nonce' ) ) {
            $condition = sanitize_text_field( $_POST['condition_type'] );
            $from_entity = sanitize_text_field( $_POST['from_entity'] );
            $to_entity = sanitize_text_field( $_POST['to_entity'] );

            // SERVER-SIDE VALIDATION: Safety Guards
            $allowed_from = [ 'vendor', 'buyer' ];
            $allowed_to   = [ 'admin', 'platform' ];

            if ( ! in_array( $from_entity, $allowed_from ) || ! in_array( $to_entity, $allowed_to ) ) {
                echo '<div class="error"><p>Invalid Transaction Flow. Only Vendor/Buyer → Admin/Platform flows are allowed for Charge Rules.</p></div>';
            } else {
                $data = [
                    'name'              => sanitize_text_field( $_POST['rule_name'] ),
                    'condition_type'    => $condition,
                    'transaction_type'  => sanitize_text_field( $_POST['transaction_type'] ),
                    'from_entity_type'  => $from_entity,
                    'to_entity_type'    => $to_entity,
                    'impact_slug'       => sanitize_text_field( $_POST['impact'] ),
                    'amount_type'       => sanitize_text_field( $_POST['amount_type'] ),
                    'amount_value'      => floatval( $_POST['amount_value'] ),
                    'lock_type'         => sanitize_text_field( $_POST['lock_type'] ),
                    'split_enabled'     => isset( $_POST['split_enabled'] ) ? 1 : 0,
                    'admin_profit_pct'  => ! empty( $_POST['admin_profit_pct'] ) ? floatval( $_POST['admin_profit_pct'] ) : NULL,
                    'external_cost_pct' => ! empty( $_POST['external_cost_pct'] ) ? floatval( $_POST['external_cost_pct'] ) : NULL,
                    'status'            => 'active',
                ];

                if ( $condition === 'recurring' ) {
                    $data['recurrence_type'] = sanitize_text_field( $_POST['recurrence_type'] );
                    $data['billing_day']     = intval( $_POST['billing_day'] );
                    $data['billing_month']   = ! empty( $_POST['billing_month'] ) ? intval( $_POST['billing_month'] ) : NULL;
                }

                $wpdb->insert( $table_name, $data );
                echo '<div class="updated"><p>Rule created successfully.</p></div>';
            }
        }

        // Handle Status Toggle
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'toggle' && isset( $_GET['rule_id'] ) ) {
            $rule_id = intval( $_GET['rule_id'] );
            $current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table_name WHERE id = %d", $rule_id ) );
            $wpdb->update( $table_name, [ 'status' => ( $current === 'active' ? 'inactive' : 'active' ) ], [ 'id' => $rule_id ] );
        }

        $rules = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC" );
        ?>
        <div class="wrap zh-finance-admin">
            <style>
                .zh-condition-cards { display: flex; gap: 20px; margin: 20px 0; }
                .zh-card { 
                    flex: 1; padding: 20px; background: #fff; border: 2px solid #ccd0d4; cursor: pointer; border-radius: 8px; transition: all 0.2s; 
                    text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                }
                .zh-card:hover { border-color: #2271b1; }
                .zh-card.active { border-color: #2271b1; background: #f0f6fa; box-shadow: 0 4px 12px rgba(34,113,177,0.15); }
                .zh-card h3 { margin-top: 0; color: #2271b1; }
                .zh-card p { font-size: 13px; color: #666; margin-bottom: 0; }
                .zh-form-container { display: none; background: #fff; padding: 30px; border: 1px solid #ccd0d4; border-radius: 8px; margin-top: 20px; max-width: 900px; }
                .zh-tooltip { color: #2271b1; cursor: help; font-style: italic; font-size: 12px; display: block; margin-top: 4px; }
                .zh-required { color: #d63638; }
                .zh-split-config { background: #f9f9f9; padding: 15px; border: 1px dashed #ccd0d4; margin-top: 10px; border-radius: 4px; }
            </style>

            <h1><?php _e( 'Charge Rules Management', 'zerohold-finance' ); ?></h1>
            <p><?php _e( 'Automate platform fees, commissions, and subscriptions. Rules can be disabled but never deleted for auditing.', 'zerohold-finance' ); ?></p>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width: 25%;">Rule Name</th>
                        <th>Condition</th>
                        <th>Flow</th>
                        <th>Impact</th>
                        <th>Value / Split</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $rules ) : foreach ( $rules as $r ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($r->name); ?></strong></td>
                            <td>
                                <code><?php echo esc_html(str_replace('_', ' ', $r->condition_type)); ?></code>
                                <?php if($r->condition_type === 'recurring') echo '<br/><small>'.esc_html($r->recurrence_type).' (Day '.$r->billing_day.')</small>'; ?>
                            </td>
                            <td><?php echo esc_html(ucfirst($r->from_entity_type) . ' → ' . ucfirst($r->to_entity_type)); ?></td>
                            <td><code><?php echo esc_html($r->impact_slug); ?></code></td>
                            <td>
                                <?php echo $r->amount_type === 'percentage' ? $r->amount_value.'%' : wc_price($r->amount_value); ?>
                                <?php if ( $r->split_enabled ) : ?>
                                    <div style="font-size: 11px; color: #666; margin-top: 5px;">
                                        Split: <?php echo (float)$r->admin_profit_pct; ?>% Profit / <?php echo (float)$r->external_cost_pct; ?>% Cost
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="dashicons dashicons-<?php echo $r->status==='active'?'yes':'no'; ?>" style="color:<?php echo $r->status==='active'?'green':'red'; ?>"></span></td>
                            <td><a href="<?php echo add_query_arg(['action'=>'toggle', 'rule_id'=>$r->id]); ?>" class="button button-small"><?php echo $r->status==='active'?'Disable':'Enable'; ?></a></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7">No rules defined.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <hr style="margin: 40px 0;">

            <h2><?php _e( 'Add New Rule', 'zerohold-finance' ); ?></h2>
            <div class="zh-condition-cards">
                <div class="zh-card" data-type="order">
                    <h3>🏦 Every Order</h3>
                    <p>Apply charge on each order transaction (Commissions, SMS, Shipping)</p>
                </div>
                <div class="zh-card" data-type="recurring">
                    <h3>📅 Recurring</h3>
                    <p>Fixed schedule charges (Monthly subscriptions, Annual fees)</p>
                </div>
            </div>

            <form method="post" id="zh_rule_form" class="zh-form-container">
                <?php wp_nonce_field( 'zh_save_rule_nonce' ); ?>
                <input type="hidden" name="condition_type" id="zh_hidden_condition">
                
                <h2 id="zh_form_title" style="border-bottom: 2px solid #f0f0f1; padding-bottom: 15px; margin-bottom: 25px;"></h2>

                <table class="form-table">
                    <tr>
                        <th><label>Rule Name <span class="zh-required">*</span></label></th>
                        <td>
                            <input type="text" name="rule_name" class="regular-text" required>
                            <span class="zh-tooltip">Internal name for admin reference (e.g. SMS Charge)</span>
                        </td>
                    </tr>

                    <tr class="zh-recurring-only" style="display:none;">
                        <th><label>Recurrence Type</label></th>
                        <td>
                            <select name="recurrence_type" id="zh_recurrence_select">
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                            <span class="zh-tooltip">Monthly = apply every month. Yearly = once a year.</span>
                        </td>
                    </tr>

                    <tr class="zh-recurring-only" style="display:none;">
                        <th><label>Billing Schedule</label></th>
                        <td>
                            <span>Day: </span><input type="number" min="1" max="28" name="billing_day" value="1" style="width: 60px;">
                            <span id="zh_billing_month_wrap" style="display:none; margin-left: 20px;">
                                Month: 
                                <select name="billing_month">
                                    <option value="1">January</option><option value="2">February</option><option value="3">March</option>
                                    <option value="4">April</option><option value="5">May</option><option value="6">June</option>
                                    <option value="7">July</option><option value="8">August</option><option value="9">September</option>
                                    <option value="10">October</option><option value="11">November</option><option value="12">December</option>
                                </select>
                            </span>
                            <span class="zh-tooltip">Select the day (and month for yearly) when charge applies. 1-28 for safety.</span>
                        </td>
                    </tr>

                    <tr>
                        <th><label>Transaction Flow</label></th>
                        <td>
                            <select name="transaction_type" style="width: 100px;">
                                <option value="debit">Debit</option>
                                <option value="credit">Credit</option>
                            </select>
                            &nbsp; From: 
                            <select name="from_entity">
                                <option value="vendor">Vendor</option>
                                <option value="buyer">Buyer</option>
                            </select>
                            &nbsp; To:
                            <select name="to_entity">
                                <option value="admin">Admin</option>
                                <option value="platform">Platform</option>
                            </select>
                            <span class="zh-tooltip">Who pays and who receives. Only flows to Admin/Platform are allowed for Charges.</span>
                        </td>
                    </tr>

                    <tr>
                        <th><label>Impact <span class="zh-required">*</span></label></th>
                        <td>
                            <input type="text" name="impact" class="regular-text" required placeholder="e.g. sms_fee">
                            <span class="zh-tooltip">Ledger label used in statements & reports. Cannot be changed later.</span>
                        </td>
                    </tr>

                    <tr>
                        <th><label>Amount Type</label></th>
                        <td>
                            <select name="amount_type" id="zh_amount_type">
                                <option value="fixed">Fixed Amount (₹)</option>
                                <option value="percentage">Percentage (%)</option>
                            </select>
                            <span class="zh-tooltip" id="zh_amount_tooltip">Fixed = flat fee.</span>
                        </td>
                    </tr>

                    <tr>
                        <th><label>Value <span class="zh-required">*</span></label></th>
                        <td>
                            <input type="number" step="0.01" name="amount_value" class="small-text" required>
                            <span class="zh-tooltip">Example: 2 for ₹2 or 1 for 1%</span>
                        </td>
                    </tr>

                    <tr>
                        <th><label>Optional Cost Split</label></th>
                        <td>
                            <label><input type="checkbox" name="split_enabled" id="zh_split_toggle"> This charge has external cost</label>
                            <div id="zh_split_fields" class="zh-split-config" style="display:none;">
                                <div style="display: flex; gap: 20px;">
                                    <div>
                                        <label>Admin Profit %</label><br/>
                                        <input type="number" step="0.01" name="admin_profit_pct" class="small-text" value="40">
                                        <span class="zh-tooltip">% platform keeps</span>
                                    </div>
                                    <div>
                                        <label>External Cost %</label><br/>
                                        <input type="number" step="0.01" name="external_cost_pct" class="small-text" value="60">
                                        <span class="zh-tooltip">% paid to provider</span>
                                    </div>
                                </div>
                                <span class="zh-tooltip" style="margin-top: 10px; color: #666;">Use this when part of the charge is paid to an external service (SMS, courier, API). Percentages must total 100%.</span>
                            </div>
                        </td>
                    </tr>

                    <tr class="zh-order-only">
                        <th><label>Lock Type</label></th>
                        <td>
                            <select name="lock_type">
                                <option value="none">none (Immediate)</option>
                                <option value="order_hold">order_hold (Locked until return window ends)</option>
                            </select>
                            <span class="zh-tooltip">Keep money locked if transaction follows return policy.</span>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="zh_save_rule" class="button button-primary button-large" value="Save Charge Rule">
                    <a href="#" id="zh_cancel" style="margin-left: 15px; text-decoration: none;">Cancel</a>
                </p>
            </form>

            <script>
                document.querySelectorAll('.zh-card').forEach(card => {
                    card.addEventListener('click', function() {
                        const type = this.dataset.type;
                        
                        // UI states
                        document.querySelectorAll('.zh-card').forEach(c => c.classList.remove('active'));
                        this.classList.add('active');
                        
                        document.getElementById('zh_hidden_condition').value = type;
                        document.getElementById('zh_rule_form').style.display = 'block';
                        
                        // Form Dynamic content
                        const titles = {
                            'order': 'Every Order Configuration',
                            'recurring': 'Recurring Schedule Configuration'
                        };
                        document.getElementById('zh_form_title').innerText = titles[type];
                        
                        // Toggle sections
                        document.querySelectorAll('.zh-recurring-only').forEach(el => el.style.display = (type === 'recurring' ? 'table-row' : 'none'));
                        document.querySelectorAll('.zh-order-only').forEach(el => el.style.display = (type === 'order' ? 'table-row' : 'none'));
                        
                        if(type === 'manual') {
                            document.getElementById('zh_amount_type').value = 'fixed';
                            document.getElementById('zh_amount_type').disabled = true;
                        } else {
                            document.getElementById('zh_amount_type').disabled = false;
                        }

                        this.scrollIntoView({ behavior: 'smooth' });
                    });
                });

                document.getElementById('zh_recurrence_select').addEventListener('change', function() {
                    document.getElementById('zh_billing_month_wrap').style.display = (this.value === 'yearly' ? 'inline' : 'none');
                });

                document.getElementById('zh_amount_type').addEventListener('change', function() {
                    const tooltip = document.getElementById('zh_amount_tooltip');
                    if (this.value === 'percentage') {
                        tooltip.innerText = 'Percentage is calculated on order subtotal (excluding tax & shipping).';
                    } else {
                        tooltip.innerText = 'Fixed = flat fee.';
                    }
                });

                document.getElementById('zh_split_toggle').addEventListener('change', function() {
                    document.getElementById('zh_split_fields').style.display = this.checked ? 'block' : 'none';
                });

                document.getElementById('zh_cancel').addEventListener('click', function(e) {
                    e.preventDefault();
                    document.getElementById('zh_rule_form').style.display = 'none';
                    document.querySelectorAll('.zh-card').forEach(c => c.classList.remove('active'));
                    window.scrollTo(0, 0);
                });
            </script>
        </div>
        <?php
    }

    /**
     * Render the Manual Transactions Page
     */
    public static function render_manual_transactions() {
        global $wpdb;
        ?>
        <div class="wrap zh-finance-admin">
            <h1><?php _e( 'Manual Transactions', 'zerohold-finance' ); ?></h1>
            <p><?php _e( 'Apply ad-hoc debits or credits to specific entities. These are one-time actions and are recorded immediately.', 'zerohold-finance' ); ?></p>
            
            <div class="notice notice-info">
                <p><?php _e( 'Manual Transactions UI is under development.', 'zerohold-finance' ); ?></p>
            </div>
        </div>
        <?php
    }
}
