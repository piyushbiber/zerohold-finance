<?php
/**
 * ZeroHold Finance Dashboard Template
 * Location: Templates/finance/dashboard.php
 * 
 * @var $current_user
 */

use ZeroHold\Finance\Core\QueryEngine;

// Add Theme Header (Required since we use exit in the loader)
get_header();

$vendor_id = dokan_get_current_user_id();

// Fetch Data
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

// Dokan Dashboard Header (This should load the nav menu if Dokan isn't doing it automatically)
dokan_get_template( 'dashboard/header.php', [ 'active_menu' => 'zh-finance' ] );
?>

<div class="dokan-dashboard-wrap">
    <?php 
        // Dokan Sidebar
        dokan_get_template( 'dashboard/sidebar.php', [ 'active_menu' => 'zh-finance' ] ); 
    ?>

    <div class="dokan-dashboard-content">
        <article class="dashboard-content-area">
            <header class="dokan-dashboard-header">
                <h1 class="entry-title"><?php _e( 'Finance & Wallet', 'zerohold-finance' ); ?></h1>
            </header>

            <!-- Finance Tabs -->
            <ul class="dokan-dashboard-header-nav" style="margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                <li class="<?php echo $active_tab === 'overview' ? 'active' : ''; ?>" style="display: inline-block; margin-right: 20px;">
                    <a href="<?php echo dokan_get_navigation_url( 'zh-finance' ); ?>" style="text-decoration: none; font-weight: bold; color: <?php echo $active_tab === 'overview' ? '#000' : '#666'; ?>;">
                        <?php _e( 'Overview & Ledger', 'zerohold-finance' ); ?>
                    </a>
                </li>
                <li class="<?php echo $active_tab === 'statements' ? 'active' : ''; ?>" style="display: inline-block;">
                    <a href="<?php echo add_query_arg( 'tab', 'statements', dokan_get_navigation_url( 'zh-finance' ) ); ?>" style="text-decoration: none; font-weight: bold; color: <?php echo $active_tab === 'statements' ? '#000' : '#666'; ?>;">
                        <?php _e( 'Statements', 'zerohold-finance' ); ?>
                    </a>
                </li>
            </ul>

            <?php if ( $active_tab === 'overview' ) : ?>
                <!-- Wallet Overview Cards -->
                <div class="dokan-w6">
                    <div class="dashboard-widget dokan-segment" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div class="widget-title">
                            <i class="fas fa-wallet"></i> <?php _e( 'Withdrawable Balance', 'zerohold-finance' ); ?>
                        </div>
                        <div class="widget-content" style="font-size: 2em; font-weight: bold; color: #2ecc71; margin-top: 10px;">
                            <?php echo wc_price( $withdrawable ); ?>
                        </div>
                        <p style="color: #666; font-size: 0.9em; margin-top: 5px;">
                            <?php _e( 'Available for immediate payout', 'zerohold-finance' ); ?>
                        </p>
                        <a href="<?php echo dokan_get_navigation_url('withdraw'); ?>" class="dokan-btn dokan-btn-theme"><?php _e('Withdraw', 'dokan'); ?></a>
                    </div>
                </div>

                <div class="dokan-w6">
                    <div class="dashboard-widget dokan-segment" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div class="widget-title">
                            <i class="fas fa-lock"></i> <?php _e( 'Locked / Reserved', 'zerohold-finance' ); ?>
                        </div>
                        <div class="widget-content" style="font-size: 2em; font-weight: bold; color: #f39c12; margin-top: 10px;">
                            <?php echo wc_price( $locked_balance ); ?>
                        </div>
                        <p style="color: #666; font-size: 0.9em; margin-top: 5px;">
                            <?php _e( 'Funds held for return period', 'zerohold-finance' ); ?>
                        </p>
                    </div>
                </div>
                
                <div class="dokan-clearfix"></div>

                <!-- Ledger Table -->
                <div class="dokan-segment" style="margin-top: 30px;">
                    <header class="dokan-widget-header">
                        <h2><?php _e( 'Ledger History', 'zerohold-finance' ); ?></h2>
                    </header>
                    
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
                                        <td><?php echo date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->created_at ) ); ?></td>
                                        <td>
                                            <span class="zh-impact-tag"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $event->impact ) ) ); ?></span>
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
                                                        echo '<span style="color: #f39c12;"><i class="fas fa-lock"></i> Locked until ' . date_i18n( 'M j', strtotime( $event->unlock_at ) ) . '</span>';
                                                    } else {
                                                        echo '<span style="color: #2ecc71;"><i class="fas fa-unlock"></i> Unlocked</span>';
                                                    }
                                                } else {
                                                    echo '<span style="color: #95a5a6;">Settled</span>';
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

            <?php elseif ( $active_tab === 'statements' ) : ?>
                <!-- Statements Tab -->
                <div class="dokan-segment">
                    <header class="dokan-widget-header">
                        <h2><?php _e( 'Monthly Statements', 'zerohold-finance' ); ?></h2>
                    </header>
                    <div style="padding: 20px; text-align: center; color: #999;">
                        <p><?php _e( 'No statements generated yet.', 'zerohold-finance' ); ?></p>
                        <p><?php _e( 'Statements will appear here after the month ends.', 'zerohold-finance' ); ?></p>
                    </div>
                </div>
            <?php endif; ?>

        </article>
    </div>
</div>

<?php 
// Add Theme Footer
get_footer(); 
?>
