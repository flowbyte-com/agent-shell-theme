<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Transaction manager: inspect → change → validate → preview → commit → rollback.
 *
 * While a transaction is open, every write to the agentshell_config option is
 * diverted to a staging buffer instead of the database. Committing validates
 * the staged config and applies it in one write; rolling back discards the
 * buffer, leaving the live site untouched. The transaction itself persists in
 * wp_options so it survives across MCP requests (each tool call is a separate
 * HTTP request).
 *
 * Reads inside an open transaction are answered from the staging buffer, so a
 * sequence of mutation tools composes correctly before anything is persisted.
 */
class Transaction_Manager {
    const KEY = 'agentshell_transaction';

    private static $instance;
    private $active = null; // cached option payload

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Observe every write to agentshell_config: defer to staging while a
        // transaction is open, otherwise record history.
        add_filter( 'pre_update_option_' . Store::CONFIG_KEY, array( $this, 'on_config_update' ), 10, 2 );
    }

    public function payload() {
        if ( null === $this->active ) {
            $this->active = get_option( self::KEY, null );
        }
        return $this->active;
    }

    public function is_active() {
        return is_array( $this->payload() );
    }

    public function begin( $label = '' ) {
        if ( $this->is_active() ) {
            throw new \InvalidArgumentException(
                'A transaction is already open (' . $this->payload()['id'] . '). Commit or roll back first.'
            );
        }
        $tx = array(
            'id'        => 'tx_' . time(),
            'label'     => sanitize_text_field( $label ),
            'actor'     => Store::actor(),
            'timestamp' => time(),
            'before'    => get_option( Store::CONFIG_KEY, array() ),
            'staged'    => null,
        );
        update_option( self::KEY, $tx, false );
        $this->active = $tx;
        return $tx;
    }

    /**
     * Stage a full config array. Returns true if staged, false if no
     * transaction is open (caller falls back to a direct write).
     */
    public function stage( array $config ) {
        if ( ! $this->is_active() ) {
            return false;
        }
        $tx            = $this->payload();
        $tx['staged']  = $config;
        update_option( self::KEY, $tx, false );
        $this->active  = $tx;
        return true;
    }

    /**
     * Config that tools should operate on: staged (if open) else persisted.
     */
    public function effective_config() {
        if ( $this->is_active() && is_array( $this->payload()['staged'] ) ) {
            return $this->payload()['staged'];
        }
        return get_option( Store::CONFIG_KEY, array() );
    }

    public function staged_config() {
        return $this->is_active() ? $this->payload()['staged'] : null;
    }

    /**
     * Interception point for ALL writes to agentshell_config (MCP tools, theme
     * REST endpoint, configurator). While a transaction is open, defer to
     * staging; otherwise record the mutation into history.
     *
     * @param mixed $value     New value about to be written
     * @param mixed $old_value Currently stored value
     * @return mixed|false
     */
    public function on_config_update( $value, $old_value ) {
        if ( $this->is_active() ) {
            $this->stage( $value );
            return false; // cancel the database write
        }
        if ( $value !== $old_value && ! Store::$suppress_history ) {
            Store::record_mutation( $old_value, $value, 'config_update', Store::$operation_label );
            Store::$operation_label = '';
        }
        return $value;
    }

    /**
     * Diff staged vs before. Throws if no transaction is open.
     */
    public function preview() {
        if ( ! $this->is_active() ) {
            throw new \InvalidArgumentException( 'No transaction is open. Call agentshell_begin_transaction first.' );
        }
        $tx = $this->payload();
        return Store::diff_config( $tx['before'], $tx['staged'] ?: $tx['before'] );
    }

    /**
     * Validate the staged config. Throws on hard errors; warnings are returned.
     */
    public function validate() {
        if ( ! $this->is_active() ) {
            throw new \InvalidArgumentException( 'No transaction is open. Call agentshell_begin_transaction first.' );
        }
        $tx     = $this->payload();
        $issues = Doctor::check( $tx['staged'] ?: $tx['before'] );
        if ( ! empty( $issues['errors'] ) ) {
            throw new \InvalidArgumentException( 'Transaction has validation errors: ' . wp_json_encode( $issues['errors'] ) );
        }
        return $issues;
    }

    /**
     * Apply staged config in one write and close the transaction.
     *
     * @return array Closed transaction record
     */
    public function commit() {
        if ( ! $this->is_active() ) {
            throw new \InvalidArgumentException( 'No transaction is open. Call agentshell_begin_transaction first.' );
        }
        $tx = $this->payload();
        if ( ! is_array( $tx['staged'] ) ) {
            throw new \InvalidArgumentException( 'Transaction has no staged changes. Call a mutation tool first.' );
        }
        $issues = Doctor::check( $tx['staged'] );
        if ( ! empty( $issues['errors'] ) ) {
            throw new \InvalidArgumentException( 'Commit blocked by validation errors: ' . wp_json_encode( $issues['errors'] ) );
        }

        // Close the transaction BEFORE writing so on_config_update records
        // history with the transaction label instead of re-staging.
        delete_option( self::KEY );
        $this->active = null;

        Store::$operation_label = $tx['label'] ? 'transaction: ' . $tx['label'] : 'transaction_commit';
        update_option( Store::CONFIG_KEY, $tx['staged'] ); // hook records the mutation
        Store::$operation_label = '';

        // The pre_update_option hook above recorded the mutation; stamp the
        // revision with the transaction id for traceability.
        self::stamp_last_revision( $tx['id'] );

        return array(
            'id'            => $tx['id'],
            'label'         => $tx['label'],
            'committed'     => true,
            'changed_tokens' => Store::diff_token_keys( $tx['before'], $tx['staged'] ),
        );
    }

    /**
     * Discard staged changes. The database was never touched, so there is
     * nothing to restore.
     *
     * @return array
     */
    public function rollback() {
        if ( ! $this->is_active() ) {
            throw new \InvalidArgumentException( 'No transaction is open. Call agentshell_begin_transaction first.' );
        }
        $tx = $this->payload();
        delete_option( self::KEY );
        $this->active = null;
        return array(
            'id'        => $tx['id'],
            'label'     => $tx['label'],
            'rolled_back' => true,
            'discarded_staged_changes' => is_array( $tx['staged'] ),
        );
    }

    private static function stamp_last_revision( $tx_id ) {
        $data = Store::revisions();
        if ( ! empty( $data['items'][0] ) ) {
            $data['items'][0]['transaction_id'] = $tx_id;
            update_option( Store::REVISIONS_KEY, $data, false );
        }
    }
}