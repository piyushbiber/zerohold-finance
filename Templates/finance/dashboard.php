<?php
/**
 * ZeroHold Finance Dashboard Template (Content Only)
 * Location: Templates/finance/dashboard.php
 * 
 * Loaded via dokan_get_template inside Dokan Dashboard Shortcode.
 */

use ZeroHold\Finance\Core\QueryEngine;

$vendor_id = dokan_get_current_user_id();

// Fetch Data
// ... data fetching logic ...
$wallet_balance = QueryEngine::get_wallet_balance( 'vendor', $vendor_id );
$locked_balance = QueryEngine::get_locked_balance( 'vendor', $vendor_id );
$withdrawable   = QueryEngine::get_withdrawable_balance( 'vendor', $vendor_id );

// Tab Handling
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'overview';

// Fetch Ledger Events (Simple Pagination) - Only if Overview
$events = [];
$total_rows = 0;
$page = 1;

if ( $active_tab === 'overview' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'zh_wallet_events';
    $page = isset( $_GET['pagenum'] ) ? absint( $_GET['pagenum'] ) : 1;
    $limit = 20;
    $offset = ( $page - 1 ) * $limit;

    $events = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE entity_type = 'vendor' AND entity_id = %d ORDER BY created_at DESC LIMIT %d, %d",
        $vendor_id, 
        $offset, 
        $limit
    ) );
    
    $total_rows = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE entity_type = 'vendor' AND entity_id = %d",
        $vendor_id
    ) );
}
?>

<?php do_action( 'dokan_dashboard_wrap_start' ); ?>

<div class="dokan-dashboard-wrap">
    <?php do_action( 'dokan_dashboard_content_before' ); ?>

    <div class="dokan-dashboard-content">
        <?php do_action( 'dokan_finance_content_inside_before' ); ?>

        <style>
            .zh-finance-dashboard {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                color: #2d3748;
            }
            .zh-header-area {
                margin-bottom: 2rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #e2e8f0;
                padding-bottom: 1rem;
            }
            .zh-title {
                font-size: 1.5rem;
                font-weight: 700;
                color: #1a202c;
                margin: 0;
            }
            .zh-nav ul {
                display: flex;
                gap: 1.5rem;
                margin: 0;
                padding: 0;
                list-style: none;
            }
            .zh-nav a {
                text-decoration: none;
                color: #718096;
                font-weight: 500;
                padding: 0.5rem 0;
                border-bottom: 2px solid transparent;
                transition: all 0.2s;
            }
            .zh-nav li.active a {
                color: #3182ce;
                border-bottom-color: #3182ce;
            }
            
            /* Stats Grid */
            .zh-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 1.5rem;
                margin-bottom: 3rem;
            }
            .zh-card {
                background: #fff;
                border-radius: 12px;
                padding: 1.5rem;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
                border: 1px solid #edf2f7;
                transition: transform 0.2s;
            }
            .zh-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            }
            .zh-card-label {
                font-size: 0.875rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #a0aec0;
                margin-bottom: 0.5rem;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }
            .zh-card-value {
                font-size: 2rem;
                font-weight: 700;
                color: #2d3748;
            }
            .zh-card-sub {
                font-size: 0.85rem;
                color: #718096;
                margin-top: 0.5rem;
            }
            .zh-btn-withdraw {
                display: inline-block;
                background: #3182ce;
                color: #fff;
                font-weight: 600;
                padding: 0.5rem 1rem;
                border-radius: 6px;
                text-decoration: none;
                margin-top: 1rem;
                transition: background 0.2s;
            }
            .zh-btn-withdraw:hover {
                background: #2b6cb0;
                color: #fff;
            }

            /* Table Styles */
            .zh-table-container {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                border: 1px solid #edf2f7;
                overflow: hidden;
            }
            .zh-table-header {
                padding: 1.5rem;
                border-bottom: 1px solid #edf2f7;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .zh-table-title {
                font-size: 1.1rem;
                font-weight: 700;
                color: #2d3748;
            }
            table.zh-table {
                width: 100%;
                border-collapse: collapse;
            }
            table.zh-table th {
                background: #f7fafc;
                text-align: left;
                padding: 1rem 1.5rem;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #718096;
                font-weight: 600;
            }
            table.zh-table td {
                padding: 1rem 1.5rem;
                border-bottom: 1px solid #edf2f7;
                color: #4a5568;
                font-size: 0.95rem;
            }
            table.zh-table tr:last-child td {
                border-bottom: none;
            }
            table.zh-table tr:hover td {
                background: #fcfcfc;
            }
            
            /* Badges */
            .zh-badge {
                padding: 0.25rem 0.5rem;
                border-radius: 9999px;
                font-size: 0.75rem;
                font-weight: 600;
            }
            .zh-badge-green { background: #c6f6d5; color: #22543d; }
            .zh-badge-red { background: #fed7d7; color: #822727; }
            .zh-badge-gray { background: #e2e8f0; color: #4a5568; }
            .zh-badge-orange { background: #feebc8; color: #7b341e; }
        </style>

        <div class="zh-finance-dashboard">
            
            <div class="zh-header-area">
                <h1 class="zh-title"><?php _e( 'Finance & Wallet', 'zerohold-finance' ); ?></h1>
                <nav class="zh-nav">
                    <ul>
                        <li class="<?php echo $active_tab === 'overview' ? 'active' : ''; ?>">
                            <a href="<?php echo dokan_get_navigation_url( 'zh-finance' ); ?>"><?php _e( 'Overview', 'zerohold-finance' ); ?></a>
                        </li>
                        <li class="<?php echo $active_tab === 'statements' ? 'active' : ''; ?>">
                            <a href="<?php echo add_query_arg( 'tab', 'statements', dokan_get_navigation_url( 'zh-finance' ) ); ?>"><?php _e( 'Statements', 'zerohold-finance' ); ?></a>
                        </li>
                    </ul>
                </nav>
            </div>

            <?php if ( $active_tab === 'overview' ) : ?>
                
                <div class="zh-stats-grid">
                    <!-- Withdrawable Card -->
                    <div class="zh-card">
                        <div class="zh-card-label">
                            <i class="fas fa-wallet"></i> <?php _e( 'Withdrawable Balance', 'zerohold-finance' ); ?>
                        </div>
                        <div class="zh-card-value" style="color: #48bb78;">
                            <?php echo wc_price( $withdrawable ); ?>
                        </div>
                        <div class="zh-card-sub"><?php _e( 'Available for immediate payout', 'zerohold-finance' ); ?></div>
                        <a href="<?php echo dokan_get_navigation_url('withdraw'); ?>" class="zh-btn-withdraw">
                            <?php _e('Request Withdrawal', 'zerohold-finance'); ?>
                        </a>
                    </div>

                    <!-- Locked Card -->
                    <div class="zh-card">
                        <div class="zh-card-label">
                            <i class="fas fa-lock"></i> <?php _e( 'Locked Reserve', 'zerohold-finance' ); ?>
                        </div>
                        <div class="zh-card-value" style="color: #ed8936;">
                            <?php echo wc_price( $locked_balance ); ?>
                        </div>
                        <div class="zh-card-sub"><?php _e( 'Held until return period ends', 'zerohold-finance' ); ?></div>
                    </div>
                </div>

                <div class="zh-table-container">
                    <div class="zh-table-header">
                        <div class="zh-table-title"><?php _e( 'Recent Transactions', 'zerohold-finance' ); ?></div>
                    </div>
                    <table class="zh-table">
                        <thead>
                            <tr>
                                <th><?php _e( 'Date', 'zerohold-finance' ); ?></th>
                                <th><?php _e( 'Type', 'zerohold-finance' ); ?></th>
                                <th><?php _e( 'Reference', 'zerohold-finance' ); ?></th>
                                <th style="text-align: right;"><?php _e( 'Amount', 'zerohold-finance' ); ?></th>
                                <th style="text-align: right;"><?php _e( 'Status', 'zerohold-finance' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( $events ) : ?>
                                <?php foreach ( $events as $event ) : ?>
                                    <tr>
                                        <td><?php echo date_i18n( 'M j, Y', strtotime( $event->created_at ) ); ?> <span style="color:#a0aec0; font-size:0.8em;"><?php echo date_i18n( 'H:i', strtotime( $event->created_at ) ); ?></span></td>
                                        <td>
                                            <span class="zh-badge <?php echo $event->category === 'credit' ? 'zh-badge-green' : 'zh-badge-red'; ?>">
                                                <?php echo esc_html( ucfirst( str_replace( '_', ' ', $event->impact ) ) ); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ( $event->reference_type === 'order' ) : ?>
                                                <a href="<?php echo wp_nonce_url( add_query_arg( [ 'order_id' => $event->reference_id ], dokan_get_navigation_url( 'orders' ) ), 'dokan_view_order' ); ?>" style="color: #3182ce; text-decoration: none; font-weight: 500;">
                                                    Order #<?php echo esc_html( $event->reference_id ); ?>
                                                </a>
                                            <?php else : ?>
                                                <?php echo esc_html( ucfirst($event->reference_type) . ' #' . $event->reference_id ); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; font-family: monospace; font-size: 1rem; font-weight: 600; color: <?php echo $event->category === 'credit' ? '#48bb78' : '#e53e3e'; ?>;">
                                            <?php echo ( $event->category === 'debit' ? '-' : '+' ) . wc_price( abs( $event->amount ) ); ?>
                                        </td>
                                        <td style="text-align: right;">
                                             <?php 
                                                if ( $event->lock_type !== 'none' ) {
                                                    if ( $event->unlock_at && strtotime( $event->unlock_at ) > time() ) {
                                                        echo '<span class="zh-badge zh-badge-orange"><i class="fas fa-clock" style="margin-right:4px;"></i>Locked</span>';
                                                    } else {
                                                        echo '<span class="zh-badge zh-badge-green">Unlocked</span>';
                                                    }
                                                } else {
                                                    echo '<span class="zh-badge zh-badge-gray">Settled</span>';
                                                }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 3rem; color: #a0aec0;">
                                        <i class="fas fa-file-invoice" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                                        <?php _e( 'No transaction history found.', 'zerohold-finance' ); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ( $active_tab === 'statements' ) : ?>
                <div class="zh-table-container" style="padding: 4rem; text-align: center;">
                    <i class="fas fa-file-pdf" style="font-size: 3rem; color: #a0aec0; margin-bottom: 1.5rem;"></i>
                    <h3 class="zh-title" style="font-size: 1.25rem; margin-bottom: 0.5rem;"><?php _e( 'Monthly Statements', 'zerohold-finance' ); ?></h3>
                    <p style="color: #718096;"><?php _e( 'Statements will ensure transparency. They will appear here at the end of each month.', 'zerohold-finance' ); ?></p>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php do_action( 'dokan_dashboard_wrap_end' ); ?>
