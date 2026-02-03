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

    // Fetch ledger events
    $ledger_events = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE entity_type = 'vendor' AND entity_id = %d ORDER BY created_at DESC",
        $vendor_id
    ) );
    
    // Fetch shipping charges from order meta (not in ledger)
    $shipping_charges = $wpdb->get_results( $wpdb->prepare(
        "SELECT 
            pm1.post_id as order_id,
            pm1.meta_value as shipping_cost,
            pm2.meta_value as shipping_date,
            'forward' as shipping_type
        FROM {$wpdb->postmeta} pm1
        INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_zh_shipping_date'
        INNER JOIN {$wpdb->postmeta} pm3 ON pm1.post_id = pm3.post_id AND pm3.meta_key = '_dokan_vendor_id'
        WHERE pm1.meta_key = '_zh_shipping_cost'
        AND pm3.meta_value = %d
        ORDER BY pm2.meta_value DESC",
        $vendor_id
    ) );
    
    // Convert shipping charges to event format
    $shipping_events = [];
    foreach ( $shipping_charges as $ship ) {
        $shipping_events[] = (object) [
            'id' => 0,
            'entity_type' => 'vendor',
            'entity_id' => $vendor_id,
            'amount' => -1 * (float) $ship->shipping_cost, // Negative for display
            'money_nature' => 'claim',
            'impact' => 'shipping_charge',
            'reference_type' => 'order',
            'reference_id' => $ship->order_id,
            'lock_type' => 'settled',
            'unlock_at' => null,
            'reason' => null,
            'admin_id' => null,
            'group_id' => null,
            'created_at' => $ship->shipping_date,
            'is_shipping' => true // Flag to identify shipping charges
        ];
    }
    
    // Merge and sort by date
    $all_events = array_merge( $ledger_events, $shipping_events );
    usort( $all_events, function( $a, $b ) {
        return strtotime( $b->created_at ) - strtotime( $a->created_at );
    });
    
    $total_rows = count( $all_events );
    
    // Paginate
    $events = array_slice( $all_events, $offset, $limit );
}
?>

<?php do_action( 'dokan_dashboard_wrap_start' ); ?>

<div class="dokan-dashboard-wrap">
    <?php do_action( 'dokan_dashboard_content_before' ); ?>

    <div class="dokan-dashboard-content">
        <?php do_action( 'dokan_finance_content_inside_before' ); ?>

        <!-- CSS moved to assets/css/finance-dashboard.css -->

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
                                <th><?php _e( 'ID', 'zerohold-finance' ); ?></th>
                                <th><?php _e( 'Date', 'zerohold-finance' ); ?></th>
                                <th><?php _e( 'Type', 'zerohold-finance' ); ?></th>
                                <th><?php _e( 'Reference', 'zerohold-finance' ); ?></th>
                                <th style="text-align: right;"><?php _e( 'Amount', 'zerohold-finance' ); ?></th>
                                <th style="text-align: right;"><?php _e( 'Balance', 'zerohold-finance' ); ?></th>
                                <th style="text-align: right;"><?php _e( 'Status', 'zerohold-finance' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( $events ) : ?>
                                <?php 
                                    // Calculate running balance for display (Reverse logic from current total)
                                    // Note: This assumes we are on Page 1. For pagination, we'd need offset sum.
                                    $running_balance = $wallet_balance; 
                                    
                                    // If not on page 1, we might need to adjust (skip for now or show warning)
                                    if ( $page > 1 ) {
                                        // $running_balance = '...'; // TODO: precise calc for deep pages
                                    }
                                ?>
                                <?php foreach ( $events as $event ) : ?>
                                    <?php 
                                        $current_row_balance = $running_balance;
                                        // Prepare for next row (older row = current - amount)
                                        // If amount was +100, previous was (Current - 100).
                                        // If amount was -50, previous was (Current - (-50)) = Current + 50.
                                        $running_balance = $running_balance - $event->amount;
                                    ?>
                                    <tr>
                                        <td style="color: #a0aec0; font-family: monospace;">#<?php echo esc_html( $event->id ); ?></td>
                                        <td><?php echo get_date_from_gmt( $event->created_at, 'M j, Y' ); ?> <span style="color:#a0aec0; font-size:0.8em;"><?php echo get_date_from_gmt( $event->created_at, 'H:i' ); ?></span></td>
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
                                        <td style="text-align: right; font-weight: 600; color: #718096;">
                                            <?php echo wc_price( $current_row_balance ); ?>
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
                                    <td colspan="7" style="text-align: center; padding: 3rem; color: #a0aec0;">
                                        <i class="fas fa-file-invoice" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
                                        <?php _e( 'No transaction history found.', 'zerohold-finance' ); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ( $total_rows > 0 ) : ?>
                        <?php
                            $total_pages = ceil( $total_rows / 20 );
                            if ( $total_pages > 1 ) :
                        ?>
                            <div style="padding: 20px; text-align: center; border-top: 1px solid #e2e8f0;">
                                <div class="tablenav" style="display: inline-block;">
                                    <div class="tablenav-pages">
                                        <span class="displaying-num"><?php echo number_format_i18n( $total_rows ); ?> items</span>
                                        <?php
                                            $page_links = paginate_links( array(
                                                'base' => add_query_arg( 'pagenum', '%#%' ),
                                                'format' => '',
                                                'prev_text' => '&laquo;',
                                                'next_text' => '&raquo;',
                                                'total' => $total_pages,
                                                'current' => $page
                                            ) );
                                            if ( $page_links ) {
                                                echo '<span class="pagination-links">' . $page_links . '</span>';
                                            }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
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
