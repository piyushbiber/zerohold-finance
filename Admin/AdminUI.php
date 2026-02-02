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
        add_action( 'admin_init', [ __CLASS__, 'handle_actions' ] );
        add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
    }

    /**
     * Handle Admin Actions (POST and GET Toggles)
     */
    public static function handle_actions() {
        global $wpdb;
        $table_rules = $wpdb->prefix . 'zh_charge_rules';

        // 1. Handle Status Toggle
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'toggle' && isset( $_GET['rule_id'] ) ) {
            check_admin_referer( 'zh_toggle_rule_' . $_GET['rule_id'] );
            $rule_id = intval( $_GET['rule_id'] );
            $current = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table_rules WHERE id = %d", $rule_id ) );
            $wpdb->update( $table_rules, [ 'status' => ( $current === 'active' ? 'inactive' : 'active' ) ], [ 'id' => $rule_id ] );
            
            wp_safe_redirect( remove_query_arg( [ 'action', 'rule_id', '_wpnonce' ] ) );
            exit;
        }

        // 2. Handle Save Charge Rule
        if ( isset( $_POST['zh_save_rule'] ) && check_admin_referer( 'zh_save_rule_nonce' ) ) {
            $condition = sanitize_text_field( $_POST['condition_type'] );
            $from_entity = sanitize_text_field( $_POST['from_entity'] );
            $to_entity = sanitize_text_field( $_POST['to_entity'] );

            // Validation
            $allowed_from = [ 'vendor', 'buyer' ];
            $allowed_to   = [ 'admin', 'platform' ];

            if ( in_array( $from_entity, $allowed_from ) && in_array( $to_entity, $allowed_to ) ) {
                $data = [
                    'name'              => sanitize_text_field( $_POST['rule_name'] ),
                    'condition_type'    => $condition,
                    'trigger_event'     => ( $condition === 'order' ) ? 'zh_event_order_completed' : NULL,
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

                $wpdb->insert( $table_rules, $data );
                wp_safe_redirect( add_query_arg( 'zh_msg', 'rule_saved' ) );
                exit;
            }
        }

        // 3. Handle Manual Transaction
        if ( isset( $_POST['zh_apply_manual'] ) && check_admin_referer( 'zh_manual_trans_nonce' ) ) {
            $type        = sanitize_text_field( $_POST['trans_type'] );
            $entity_type = sanitize_text_field( $_POST['entity_type'] );
            $scope       = sanitize_text_field( $_POST['target_scope'] );
            $impact      = sanitize_text_field( $_POST['impact'] );
            $amount      = floatval( $_POST['amount'] );
            $reason      = sanitize_textarea_field( $_POST['reason'] );
            $lock_type   = sanitize_text_field( $_POST['lock_type'] );
            $admin_id    = get_current_user_id();

            if ( ! empty( $reason ) ) {
                $targets = [];
                if ( $scope === 'single' ) {
                    $targets[] = intval( $_POST['target_user_id'] );
                } else {
                    $targets = get_users( [ 'role' => 'seller', 'fields' => 'ID' ] );
                }

                $success_count = 0;
                foreach ( $targets as $user_id ) {
                    if ( $type === 'debit' ) {
                        $from = [ 'type' => $entity_type, 'id' => $user_id, 'nature' => 'claim' ];
                        $to   = [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ];
                    } else {
                        $from = [ 'type' => 'admin', 'id' => 0, 'nature' => 'real' ];
                        $to   = [ 'type' => $entity_type, 'id' => $user_id, 'nature' => 'claim' ];
                    }

                    $res = \ZeroHold\Finance\Core\LedgerService::record(
                        $from, $to, $amount, $impact, 'manual', 0, $lock_type, null, $reason, $admin_id
                    );
                    if ( $res ) $success_count++;
                }

                wp_safe_redirect( add_query_arg( [ 'zh_msg' => 'trans_applied', 'count' => $success_count ] ) );
                exit;
            }
        }

        // 4. Handle System Reset (DEVELOPER ONLY - Protected by constant)
        if ( defined('ZH_FINANCE_ALLOW_RESET') && ZH_FINANCE_ALLOW_RESET === true ) {
            if ( isset( $_POST['zh_reset_system'] ) && check_admin_referer( 'zh_reset_system_nonce' ) ) {
                if ( sanitize_text_field( $_POST['reset_confirmation'] ) === 'RESET ALL DATA' ) {
                    \ZeroHold\Finance\Core\Database::reset_all_data();
                    wp_safe_redirect( add_query_arg( 'zh_msg', 'system_reset' ) );
                    exit;
                } else {
                    wp_safe_redirect( add_query_arg( 'zh_error', 'wrong_confirmation' ) );
                    exit;
                }
            }
        }
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
        $metrics = \ZeroHold\Finance\Core\QueryEngine::get_global_metrics();
        $pnl     = \ZeroHold\Finance\Core\QueryEngine::get_admin_pnl_breakdown();
        
        // Messages
        if ( isset( $_GET['zh_msg'] ) && $_GET['zh_msg'] === 'system_reset' ) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>SUCCESS:</strong> All financial data has been wiped and the system has been reset for production.</p></div>';
        }
        if ( isset( $_GET['zh_error'] ) && $_GET['zh_error'] === 'wrong_confirmation' ) {
            echo '<div class="error"><p><strong>FAILED:</strong> Reset confirmation string was incorrect. No data was cleared.</p></div>';
        }
        ?>
        <div class="wrap zh-finance-admin">
            <style>
                .zh-dashboard-grid { margin-bottom: 40px; }
                .zh-grid-row { display: grid; gap: 20px; margin-bottom: 25px; }
                .zh-row-primary { grid-template-columns: repeat(4, 1fr); }
                .zh-row-sub { grid-template-columns: repeat(4, 1fr); }
                
                .zh-stat-card { 
                    background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; position: relative;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: transform 0.2s;
                }
                .zh-stat-card:hover { transform: translateY(-2px); border-color: #c3c4c7; }
                
                .zh-card-primary { border-top: 4px solid #2271b1; }
                .zh-card-profit { border-top: 4px solid #1a7f37; background: #fafffb; }
                .zh-card-liability { border-top: 4px solid #d63638; }
                .zh-card-escrow { border-top: 4px solid #72aee6; }
                
                .zh-stat-label { font-size: 13px; font-weight: 600; color: #50575e; display: flex; align-items: center; gap: 6px; }
                .zh-stat-value { font-size: 26px; font-weight: 700; color: #1d2327; margin: 12px 0 4px; }
                .zh-stat-desc { font-size: 12px; color: #646970; }
                
                .zh-sub-card { background: #f6f7f7; border: 1px solid #dcdcde; padding: 15px; border-radius: 6px; }
                .zh-sub-card .zh-stat-value { font-size: 20px; margin: 8px 0 2px; }
                
                .zh-row-title { font-size: 14px; font-weight: 700; color: #1d2327; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 15px; display: block; border-bottom: 1px solid #dcdcde; padding-bottom: 8px; }
                
                /* Tooltip logic */
                .zh-info-icon { color: #2271b1; cursor: help; font-size: 16px; width: 16px; height: 16px; }
                .wp-admin .zh-stat-card [title] { text-decoration: none; border-bottom: none; }
            </style>

            <h1><?php _e( 'ZeroHold Finance - Central Bank', 'zerohold-finance' ); ?></h1>
            <p><?php _e( 'Real-time financial health of the platform derived from the immutable ledger.', 'zerohold-finance' ); ?></p>
            
            <div class="zh-dashboard-grid">
                
                <!-- ROW 1: PRIMARY CARDS -->
                <span class="zh-row-title"><?php _e( 'PRIMARY LIQUIDITY & PERFORMANCE', 'zerohold-finance' ); ?></span>
                <div class="zh-grid-row zh-row-primary">
                    <!-- Bank Pool -->
                    <div class="zh-stat-card zh-card-primary" title="Actual cash held in bank accounts and payment gateways.">
                        <span class="zh-stat-label">🏦 <?php _e( 'Bank Pool (Real Money)', 'zerohold-finance' ); ?> <span class="dashicons dashicons-editor-help zh-info-icon"></span></span>
                        <div class="zh-stat-value"><?php echo wc_price($metrics['total_real']); ?></div>
                        <span class="zh-stat-desc"><?php _e( 'Total platform liquidity', 'zerohold-finance' ); ?></span>
                    </div>

                    <!-- Platform Liabilities -->
                    <div class="zh-stat-card zh-card-liability" title="Total money owed by the platform to vendors and buyers.">
                        <span class="zh-stat-label">📌 <?php _e( 'Platform Liabilities', 'zerohold-finance' ); ?> <span class="dashicons dashicons-editor-help zh-info-icon"></span></span>
                        <div class="zh-stat-value"><?php echo wc_price(abs($metrics['total_liabilities'])); ?></div>
                        <span class="zh-stat-desc"><?php _e( 'Total owed to Others', 'zerohold-finance' ); ?></span>
                    </div>

                    <!-- Escrow -->
                    <div class="zh-stat-card zh-card-escrow" title="Portion of platform liabilities temporarily locked due to return or payment hold.">
                        <span class="zh-stat-label">🔒 <?php _e( 'Escrow (Locked Funds)', 'zerohold-finance' ); ?> <span class="dashicons dashicons-editor-help zh-info-icon"></span></span>
                        <div class="zh-stat-value"><?php echo wc_price($metrics['total_escrow']); ?></div>
                        <span class="zh-stat-desc"><?php _e( 'Total currently in-escrow', 'zerohold-finance' ); ?></span>
                    </div>

                    <!-- Net Profit -->
                    <div class="zh-stat-card zh-card-profit" title="Net earnings retained by the platform after liabilities.">
                        <span class="zh-stat-label">💰 <?php _e( 'Platform Net Profit', 'zerohold-finance' ); ?> <span class="dashicons dashicons-editor-help zh-info-icon"></span></span>
                        <div class="zh-stat-value" style="color: <?php echo $metrics['platform_profit'] >= 0 ? '#1a7f37' : '#d63638'; ?>;">
                            <?php echo wc_price($metrics['platform_profit']); ?>
                        </div>
                        <span class="zh-stat-desc"><?php _e( 'Total platform equity', 'zerohold-finance' ); ?></span>
                    </div>
                </div>

                <!-- ROW 2: LIABILITY BREAKDOWN -->
                <span class="zh-row-title"><?php _e( 'LIABILITY BREAKDOWN', 'zerohold-finance' ); ?></span>
                <div class="zh-grid-row zh-row-sub">
                    <div class="zh-sub-card" title="Funds owed to vendors (withdrawable + locked).">
                        <span class="zh-stat-label">🧾 <?php _e( 'Vendor Liabilities', 'zerohold-finance' ); ?></span>
                        <div class="zh-stat-value"><?php echo wc_price($metrics['vendor_liabilities']); ?></div>
                    </div>
                    <div class="zh-sub-card" title="Buyer wallet balances, refunds, and credits owed.">
                        <span class="zh-stat-label">👤 <?php _e( 'Buyer Liabilities', 'zerohold-finance' ); ?></span>
                        <div class="zh-stat-value"><?php echo wc_price($metrics['buyer_liabilities']); ?></div>
                    </div>
                    <div></div> <!-- Spacer -->
                    <div></div> <!-- Spacer -->
                </div>

                <!-- ROW 3: ESCROW BREAKDOWN -->
                <span class="zh-row-title"><?php _e( 'ESCROW (LOCK) BREAKDOWN', 'zerohold-finance' ); ?></span>
                <div class="zh-grid-row zh-row-sub">
                    <div class="zh-sub-card" title="Vendor funds locked under return policy.">
                        <span class="zh-stat-label">⏳ <?php _e( 'Vendor Escrow', 'zerohold-finance' ); ?></span>
                        <div class="zh-stat-value"><?php echo wc_price($metrics['vendor_escrow']); ?></div>
                    </div>
                    <div class="zh-sub-card" title="Buyer funds locked due to refunds or disputes.">
                        <span class="zh-stat-label">⏳ <?php _e( 'Buyer Escrow', 'zerohold-finance' ); ?></span>
                        <div class="zh-stat-value"><?php echo wc_price($metrics['buyer_escrow']); ?></div>
                    </div>
                    <div></div> <!-- Spacer -->
                    <div></div> <!-- Spacer -->
                </div>

            </div>

            <div class="zh-section">
                <h2 title="Net platform profit or loss grouped by impact type."><?php _e( 'Profit & Loss Breakdown (by Impact)', 'zerohold-finance' ); ?> <span class="dashicons dashicons-editor-help zh-info-icon" style="font-size: 18px;"></span></h2>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th><?php _e( 'Impact Type', 'zerohold-finance' ); ?></th>
                            <th><?php _e( 'Description', 'zerohold-finance' ); ?></th>
                            <th style="text-align: right;"><?php _e( 'Net Platform Profit', 'zerohold-finance' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $pnl ) ) : foreach ( $pnl as $impact => $amount ) : ?>
                            <tr>
                                <td><code><?php echo esc_html($impact); ?></code></td>
                                <td>
                                    <?php 
                                        $desc = [
                                            'earnings' => 'Order Commissions & Sales Share',
                                            'shipping' => 'Shipping Cost Reconciliation',
                                            'sms_fee'  => 'Automated SMS Charges',
                                            'manual'   => 'Manual Adjustments & Penalties'
                                        ];
                                        echo isset($desc[$impact]) ? esc_html($desc[$impact]) : 'Automated Rule/Charge';
                                    ?>
                                </td>
                                <td style="text-align: right; font-weight: bold; <?php echo $amount >= 0 ? 'color:green;' : 'color:red;'; ?>">
                                    <?php echo wc_price($amount); ?>
                                </td>
                            </tr>
                        <?php endforeach; else : ?>
                            <tr><td colspan="3"><?php _e( 'No revenue data found.', 'zerohold-finance' ); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Dangerous Area: System Reset (Only shown if constant is set) -->
            <?php if ( defined('ZH_FINANCE_ALLOW_RESET') && ZH_FINANCE_ALLOW_RESET === true ) : ?>
            <div style="margin-top: 100px; padding: 30px; border: 1px dashed #d63638; border-radius: 8px; background: #fffcfc;">
                <h3 style="color: #d63638; margin-top: 0;"><?php _e( '☢️ System Reset (Developer Mode Only)', 'zerohold-finance' ); ?></h3>
                <p><?php _e( 'Use this only BEFORE going to production. This will permanently DELETE all transactions, charge rules, and history.', 'zerohold-finance' ); ?></p>
                
                <form method="post" id="zh_reset_form" style="display: flex; gap: 15px; align-items: center;">
                    <?php wp_nonce_field( 'zh_reset_system_nonce' ); ?>
                    <input type="text" name="reset_confirmation" placeholder="Type 'RESET ALL DATA' to confirm" style="width: 250px;">
                    <input type="submit" name="zh_reset_system" class="button button-link-delete" value="WIPE & RESET FINANCE SYSTEM" onclick="return confirm('EXTREMELY DANGEROUS: Are you absolutely sure?')">
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render the Charge Rules Management Page
     */
    public static function render_charge_rules() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_charge_rules';
        
        // Success Messages
        if ( isset( $_GET['zh_msg'] ) && $_GET['zh_msg'] === 'rule_saved' ) {
            echo '<div class="updated"><p>Charge rule saved successfully.</p></div>';
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
                            <td>
                                <a href="<?php echo wp_nonce_url( add_query_arg(['action'=>'toggle', 'rule_id'=>$r->id]), 'zh_toggle_rule_' . $r->id ); ?>" class="button button-small">
                                    <?php echo $r->status==='active'?'Disable':'Enable'; ?>
                                </a>
                            </td>
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
        $table_events = $wpdb->prefix . 'zh_wallet_events';
        
        // Success Messages
        if ( isset( $_GET['zh_msg'] ) && $_GET['zh_msg'] === 'trans_applied' ) {
            $count = isset( $_GET['count'] ) ? intval( $_GET['count'] ) : 0;
            echo '<div class="updated"><p>Successfully applied ' . $count . ' manual transaction(s).</p></div>';
        }

        // Fetch History (Target-perspective filter to avoid double rows)
        $history = $wpdb->get_results( "SELECT * FROM $table_events WHERE reference_type = 'manual' AND entity_type IN ('vendor', 'buyer') ORDER BY created_at DESC LIMIT 100" );
        
        // Fetch Vendors for Single Selection
        $vendors = get_users( [ 'role' => 'seller', 'fields' => [ 'ID', 'display_name' ] ] );
        $buyers  = get_users( [ 'role' => 'customer', 'fields' => [ 'ID', 'display_name' ] ] );
        ?>
        <div class="wrap zh-finance-admin">
            <style>
                .zh-form-section { background: #fff; padding: 30px; border: 1px solid #ccd0d4; border-radius: 8px; margin-bottom: 30px; max-width: 900px; }
                .zh-tooltip { color: #2271b1; font-style: italic; font-size: 12px; display: block; margin-top: 4px; }
                .zh-required { color: #d63638; }
                .zh-danger-zone { border-left: 4px solid #d63638; }
            </style>

            <h1><?php _e( 'Manual Transactions', 'zerohold-finance' ); ?></h1>
            <p><?php _e( 'Apply ad-hoc debits or credits. These are one-time actions and are recorded immediately.', 'zerohold-finance' ); ?></p>
            
            <div class="zh-form-section">
                <h2><?php _e( 'New Manual Transaction', 'zerohold-finance' ); ?></h2>
                <form method="post" id="zh_manual_form">
                    <?php wp_nonce_field( 'zh_manual_trans_nonce' ); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label>Transaction Direction</label></th>
                            <td>
                                <select name="trans_type" style="width: 120px;">
                                    <option value="debit">Debit (Take)</option>
                                    <option value="credit">Credit (Give)</option>
                                </select>
                                &nbsp; Type: 
                                <select name="entity_type" id="zh_entity_type">
                                    <option value="vendor">Vendor</option>
                                    <option value="buyer">Buyer</option>
                                </select>
                                &nbsp; Target:
                                <select name="target_scope" id="zh_target_scope">
                                    <option value="single">Single Entity</option>
                                    <option value="all">All Vendors (Bulk)</option>
                                </select>
                                <span class="zh-tooltip">Manual transactions are applied immediately and are not reusable.</span>
                            </td>
                        </tr>

                        <tr id="zh_single_target_row">
                            <th><label>Select Target <span class="zh-required">*</span></label></th>
                            <td>
                                <select name="target_user_id" class="regular-text" id="zh_user_select">
                                    <optgroup label="Vendors" class="zh-vendor-group">
                                        <?php foreach($vendors as $v) echo '<option value="'.$v->ID.'">'.esc_html($v->display_name).' (ID: '.$v->ID.')</option>'; ?>
                                    </optgroup>
                                    <optgroup label="Buyers" class="zh-buyer-group" style="display:none;">
                                        <?php foreach($buyers as $b) echo '<option value="'.$b->ID.'">'.esc_html($b->display_name).' (ID: '.$b->ID.')</option>'; ?>
                                    </optgroup>
                                </select>
                            </td>
                        </tr>

                        <tr>
                            <th><label>Transaction Details</label></th>
                            <td>
                                Impact Label: <input type="text" name="impact" placeholder="e.g. penalty" required>
                                &nbsp; Amount: ₹<input type="number" step="0.01" name="amount" style="width: 100px;" required>
                            </td>
                        </tr>

                        <tr>
                            <th><label>Audit Reason <span class="zh-required">*</span></label></th>
                            <td>
                                <textarea name="reason" rows="3" class="large-text" required placeholder="Describe why this transaction is being applied..."></textarea>
                                <span class="zh-tooltip">Mandatory audit gold. Why are you doing this?</span>
                            </td>
                        </tr>

                        <tr>
                            <th><label>Lock Type</label></th>
                            <td>
                                <select name="lock_type">
                                    <option value="none">none (Immediate)</option>
                                    <option value="manual_hold">manual_hold (Locked for review)</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <input type="submit" name="zh_apply_manual" class="button button-primary button-large" value="Apply Transaction">
                    </p>
                </form>
            </div>

            <h2><?php _e( 'Manual Transaction History', 'zerohold-finance' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 15%;"><?php _e( 'Date', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Target', 'zerohold-finance' ); ?></th>
                        <th style="width: 10%;"><?php _e( 'Type', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Impact / Amount', 'zerohold-finance' ); ?></th>
                        <th style="width: 25%;"><?php _e( 'Reason', 'zerohold-finance' ); ?></th>
                        <th><?php _e( 'Applied By', 'zerohold-finance' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $history ) : foreach ( $history as $h ) : 
                        $user = get_userdata($h->entity_id);
                        $admin = get_userdata($h->admin_id);
                        ?>
                        <tr>
                            <td><?php echo esc_html($h->created_at); ?></td>
                            <td><?php echo esc_html($user ? $user->display_name : 'All Vendors'); ?><br/><small><?php echo esc_html(ucfirst($h->entity_type)); ?></small></td>
                            <td><?php echo $h->amount > 0 ? '<span style="color:green;">Credit</span>' : '<span style="color:red;">Debit</span>'; ?></td>
                            <td>
                                <code><?php echo esc_html($h->impact); ?></code><br/>
                                <strong><?php echo wc_price(abs($h->amount)); ?></strong>
                            </td>
                            <td><small><?php echo esc_html($h->reason); ?></small></td>
                            <td><?php echo esc_html($admin ? $admin->display_name : 'System'); ?></td>
                        </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="6"><?php _e( 'No manual transactions recorded.', 'zerohold-finance' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <script>
                document.getElementById('zh_entity_type').addEventListener('change', function() {
                    const isVendor = this.value === 'vendor';
                    document.querySelector('.zh-vendor-group').style.display = isVendor ? '' : 'none';
                    document.querySelector('.zh-buyer-group').style.display = isVendor ? 'none' : '';
                    if (!isVendor) {
                        document.getElementById('zh_target_scope').value = 'single';
                        document.getElementById('zh_target_scope').options[1].disabled = true;
                    } else {
                        document.getElementById('zh_target_scope').options[1].disabled = false;
                    }
                });

                document.getElementById('zh_target_scope').addEventListener('change', function() {
                    document.getElementById('zh_single_target_row').style.display = (this.value === 'single' ? 'table-row' : 'none');
                    if (this.value === 'all') {
                        document.getElementById('zh_manual_form').classList.add('zh-danger-zone');
                    } else {
                        document.getElementById('zh_manual_form').classList.remove('zh-danger-zone');
                    }
                });

                document.getElementById('zh_manual_form').addEventListener('submit', function(e) {
                    const scope = document.getElementById('zh_target_scope').value;
                    if (scope === 'all') {
                        if (!confirm('⚠️ WARNING: This will apply a transaction to ALL active vendors. Are you sure?')) {
                            e.preventDefault();
                        }
                    }
                });
            </script>
        </div>
        <?php
    }
}
