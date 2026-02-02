<?php

namespace ZeroHold\Finance\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Database {

    public static function migrate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $tables = [
            self::get_schema_wallet_events( $charset_collate ),
            self::get_schema_vendor_statements( $charset_collate ),
            self::get_schema_statement_attachment( $charset_collate ),
            self::get_schema_charge_rules( $charset_collate ),
            self::get_schema_recurring_log( $charset_collate )
        ];

        foreach ( $tables as $sql ) {
            dbDelta( $sql );
        }
        
        self::create_triggers();
    }

    private static function get_schema_wallet_events( $charset_collate ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_wallet_events';

        return "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_group_id varchar(36) NOT NULL,
            entity_type varchar(20) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            amount decimal(14,2) NOT NULL,
            category varchar(10) NOT NULL,
            impact varchar(64) NOT NULL,
            money_nature varchar(10) NOT NULL,
            lock_type varchar(20) DEFAULT 'none' NOT NULL,
            unlock_at datetime DEFAULT NULL,
            reference_type varchar(64) NOT NULL,
            reference_id bigint(20) unsigned NOT NULL,
            statement_id bigint(20) unsigned DEFAULT NULL,
            reason text DEFAULT NULL,
            admin_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY entity_idx (entity_type, entity_id),
            KEY reference_idx (reference_type, reference_id),
            KEY group_idx (entry_group_id)
        ) $charset_collate;";
    }

    private static function get_schema_vendor_statements( $charset_collate ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_vendor_statements';

        return "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(20) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            period_start datetime NOT NULL,
            period_end datetime NOT NULL,
            opening_balance decimal(14,2) NOT NULL DEFAULT 0.00,
            total_credit decimal(14,2) NOT NULL DEFAULT 0.00,
            total_debit decimal(14,2) NOT NULL DEFAULT 0.00,
            closing_balance decimal(14,2) NOT NULL DEFAULT 0.00,
            liabilities_locked decimal(14,2) NOT NULL DEFAULT 0.00,
            breakdown longtext DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'locked',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY period_entity_idx (entity_type, entity_id, period_start, period_end)
        ) $charset_collate;";
    }

    private static function get_schema_statement_attachment( $charset_collate ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_statement_attachment';

        return "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            statement_id bigint(20) unsigned NOT NULL,
            wallet_event_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY statement_idx (statement_id),
            UNIQUE KEY attach_idx (statement_id, wallet_event_id)
        ) $charset_collate;";
    }
    
    private static function get_schema_charge_rules( $charset_collate ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_charge_rules';
        
        return "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            condition_type varchar(20) NOT NULL DEFAULT 'order',
            recurrence_type varchar(20) DEFAULT NULL,
            billing_day int(2) DEFAULT NULL,
            billing_month int(2) DEFAULT NULL,
            transaction_type varchar(20) NOT NULL DEFAULT 'debit',
            from_entity_type varchar(20) NOT NULL,
            to_entity_type varchar(20) NOT NULL,
            impact_slug varchar(64) NOT NULL,
            amount_type varchar(20) NOT NULL DEFAULT 'fixed',
            amount_value decimal(14,2) NOT NULL,
            split_enabled tinyint(1) NOT NULL DEFAULT 0,
            admin_profit_pct decimal(5,2) DEFAULT NULL,
            external_cost_pct decimal(5,2) DEFAULT NULL,
            lock_type varchar(20) NOT NULL DEFAULT 'none',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";
    }

    private static function get_schema_recurring_log( $charset_collate ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'zh_recurring_log';

        return "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            rule_id bigint(20) unsigned NOT NULL,
            entity_type varchar(20) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            billing_period varchar(20) NOT NULL, -- e.g. '2026-02' or '2026'
            charged_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_charge (rule_id, entity_id, billing_period)
        ) $charset_collate;";
    }

    private static function create_triggers() {
        global $wpdb;
        $table = $wpdb->prefix . 'zh_wallet_events';
        
        // Drop existing triggers if re-running
        $wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}zh_prevent_ledger_update" );
        $wpdb->query( "DROP TRIGGER IF EXISTS {$wpdb->prefix}zh_prevent_ledger_delete" );

        // BEFORE UPDATE Trigger
        $sql_update = "CREATE TRIGGER {$wpdb->prefix}zh_prevent_ledger_update
            BEFORE UPDATE ON $table
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ZeroHold Finance Ledger is IMMUTABLE. Updates are forbidden.';
            END;";
            
        // BEFORE DELETE Trigger
        $sql_delete = "CREATE TRIGGER {$wpdb->prefix}zh_prevent_ledger_delete
            BEFORE DELETE ON $table
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ZeroHold Finance Ledger is IMMUTABLE. Deletes are forbidden.';
            END;";

        // Triggers need to be executed via raw query
        // dbDelta doesn't handle triggers well
        $wpdb->query( $sql_update );
        $wpdb->query( $sql_delete );
    }
}
