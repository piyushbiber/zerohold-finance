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

    <?php
        /**
         *  dokan_dashboard_content_before hook
         *  @hooked get_dashboard_side_navigation
         */
        do_action( 'dokan_dashboard_content_before' );
    ?>

    <div class="dokan-dashboard-content">

        <?php do_action( 'dokan_finance_content_inside_before' ); ?>

        <article class="dokan-finance-area">
            <header class="dokan-dashboard-header">
                <h1 class="entry-title"><?php _e( 'Finance & Wallet', 'zerohold-finance' ); ?></h1>
            </header>

            <!-- dokan-dashboard-header-nav is for the big tabs like standard Pages -->
            <ul class="dokan-dashboard-header-nav">
                <li class="<?php echo $active_tab === 'overview' ? 'active' : ''; ?>">
                    <a href="<?php echo dokan_get_navigation_url( 'zh-finance' ); ?>">
                        <?php _e( 'Overview & Ledger', 'zerohold-finance' ); ?>
                    </a>
                </li>
                <li class="<?php echo $active_tab === 'statements' ? 'active' : ''; ?>">
                    <a href="<?php echo add_query_arg( 'tab', 'statements', dokan_get_navigation_url( 'zh-finance' ) ); ?>">
                        <?php _e( 'Statements', 'zerohold-finance' ); ?>
                    </a>
                </li>
            </ul>

            <?php if ( $active_tab === 'overview' ) : ?>
                <!-- Native Dokan Dashboard Widgets Logic -->
                <div class="dokan-w12">
                   <div class="dokan-dashboard-content-main">
                        <div class="dokan-w6">
                            <div class="dashboard-widget big-counter">
                                <ul class="list-inline">
                                    <li>
                                        <div class="title"><?php _e( 'Withdrawable Balance', 'zerohold-finance' ); ?></div>
                                        <div class="count"><?php echo wc_price( $withdrawable ); ?></div>
                                    </li>
                                </ul>
                                <a href="<?php echo dokan_get_navigation_url('withdraw'); ?>" class="dokan-btn dokan-btn-theme" style="margin-top: 10px;"><?php _e('Withdraw', 'dokan'); ?></a>
                            </div>
                        </div>
                        <div class="dokan-w6">
                            <div class="dashboard-widget big-counter">
                                <ul class="list-inline">
                                    <li>
                                        <div class="title"><?php _e( 'Locked / Reserved', 'zerohold-finance' ); ?></div>
                                        <div class="count" style="color: #f39c12;"><?php echo wc_price( $locked_balance ); ?></div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="dokan-clearfix"></div>
                   </div>
                </div>

                <!-- Ledger Table -->
                <div class="dokan-dashboard-content-main">
                    <header class="dokan-widget-header">
                        <h2>
                            <i class="fas fa-file-invoice-dollar" aria-hidden="true"></i>
                            <?php _e( 'Ledger History', 'zerohold-finance' ); ?>
                        </h2>
                    </header>
                    
                    <div class="dokan-table-wrap">
                        <table class="dokan-table dokan-table-striped">
                            <thead>
                                <tr>
                                    <th><?php _e( 'Date', 'zerohold-finance' ); ?></th>
                                    <th><?php _e( 'Impact', 'zerohold-finance' ); ?></th>
                                    <th><?php _e( 'Reference', 'zerohold-finance' ); ?></th>
                                    <th><?php _e( 'Type', 'zerohold-finance' ); ?></th>
                                    <th><?php _e( 'Amount', 'zerohold-finance' ); ?></th>
                                    <th><?php _e( 'Status', 'zerohold-finance' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( $events ) : ?>
                                    <?php foreach ( $events as $event ) : ?>
                                        <tr class="<?php echo $event->category === 'credit' ? 'zh-credit' : 'zh-debit'; ?>">
                                            <td class="dokan-order-date"><?php echo date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->created_at ) ); ?></td>
                                            <td>
                                                <span class="dokan-label dokan-label-default"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $event->impact ) ) ); ?></span>
                                            </td>
                                            <td>
                                                <?php 
                                                    // Simple link logic
                                                    if ( $event->reference_type === 'order' ) {
                                                        echo '<a href="' . wp_nonce_url( add_query_arg( [ 'order_id' => $event->reference_id ], dokan_get_navigation_url( 'orders' ) ), 'dokan_view_order' ) . '">Order #' . esc_html( $event->reference_id ) . '</a>';
                                                    } else {
                                                        echo esc_html( $event->reference_type . ' #' . $event->reference_id ); 
                                                    }
                                                ?>
                                            </td>
                                            <td><?php echo $event->category === 'credit' ? __('Credit', 'zerohold-finance') : __('Debit', 'zerohold-finance'); ?></td>
                                            <td style="font-weight: bold; color: <?php echo $event->category === 'credit' ? '#2ecc71' : '#e74c3c'; ?>;">
                                                <?php echo ( $event->category === 'debit' ? '-' : '+' ) . wc_price( abs( $event->amount ) ); ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    if ( $event->lock_type !== 'none' ) {
                                                        if ( $event->unlock_at && strtotime( $event->unlock_at ) > time() ) {
                                                            echo '<span class="dokan-label dokan-label-warning"><i class="fas fa-lock"></i> Locked</span>';
                                                        } else {
                                                            echo '<span class="dokan-label dokan-label-success"><i class="fas fa-unlock"></i> Unlocked</span>';
                                                        }
                                                    } else {
                                                        echo '<span class="dokan-label dokan-label-default">Settled</span>';
                                                    }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <tr>
                                        <td colspan="6"><?php _e( 'No transaction history found.', 'zerohold-finance' ); ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php elseif ( $active_tab === 'statements' ) : ?>
                <!-- Statements Tab -->
                <div class="dokan-dashboard-content-main">
                    <header class="dokan-widget-header">
                        <h2>
                             <i class="fas fa-file-pdf" aria-hidden="true"></i>
                             <?php _e( 'Monthly Statements', 'zerohold-finance' ); ?>
                        </h2>
                    </header>
                    <div style="padding: 50px; text-align: center; color: #999; background: #fff; border: 1px solid #eee;">
                        <p style="font-size: 1.2em;"><?php _e( 'No statements generated yet.', 'zerohold-finance' ); ?></p>
                        <p><?php _e( 'Statements will appear here after the month ends.', 'zerohold-finance' ); ?></p>
                    </div>
                </div>
            <?php endif; ?>

        </article>

    </div> <!-- .dokan-dashboard-content -->

</div> <!-- .dokan-dashboard-wrap -->

<?php do_action( 'dokan_dashboard_wrap_end' ); ?>
