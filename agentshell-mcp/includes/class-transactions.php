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

    /**
     * Query arguments that authorise rendering of the staged config.
     *
     * A preview URL is a bearer capability: anyone holding it can see the
     * uncommitted design. So it is scoped to one transaction, one actor, and
     * a short window, and it stops working the moment the transaction closes.
     */
    const PREVIEW_TX_ARG  = 'agentshell_tx';
    const PREVIEW_KEY_ARG = 'agentshell_tx_key';
    const PREVIEW_EXP_ARG = 'agentshell_tx_exp';

    /**
     * The existing theme-profile preview key. Listed here so the same scrubber
     * covers every bearer capability a preview URL can carry.
     */
    const PREVIEW_PROFILE_KEY_ARG = 'agentshell_preview_key';

    /**
     * Preview credential lifetime.
     *
     * Long enough for an agent to capture and inspect several viewports in
     * one turn, short enough that a leaked URL is not a standing capability.
     * Expiry is enforced at render time, not merely baked into the signature,
     * so it fails closed rather than depending on the client honouring it.
     */
    const PREVIEW_TTL_SECONDS = 900;

    private static $instance;

    /**
     * Whether the agentshell_config write hook is already attached.
     *
     * Per request, not per instance — see the constructor for why an instance
     * bound hook is not interchangeable with a correctly dispatched one.
     */
    private static $config_hook_registered = false;
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
        //
        // Registered once per request against a static dispatcher rather than
        // against $this. Two reasons, and the second is the one that bites:
        //
        //   - a filter chain is not the same as one filter. apply_filters()
        //     threads each callback's return value into the next, so N
        //     registered callbacks each see the previous one's answer, not the
        //     value that was passed in.
        //   - the manager memoises its payload in $this->active and only
        //     re-reads the option when that is null. An instance that has been
        //     replaced therefore believes a transaction is still open forever,
        //     and keeps cancelling writes that were meant to land.
        //
        // The dispatcher always resolves the current singleton, so a replaced
        // instance cannot be reached through the hook.
        if ( ! self::$config_hook_registered ) {
            self::$config_hook_registered = true;
            add_filter( 'pre_update_option_' . Store::CONFIG_KEY, array( self::class, 'filter_config_update' ), 10, 2 );
        }
    }

    /**
     * Static entry point for the agentshell_config write hook.
     *
     * @param mixed $value     New value about to be written.
     * @param mixed $old_value Currently stored value.
     * @return mixed The value WordPress should store.
     */
    public static function filter_config_update( $value, $old_value ) {
        return self::instance()->on_config_update( $value, $old_value );
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
            $existing = $this->payload();
            $actor    = Store::actor();
            if ( $existing['actor'] !== $actor ) {
                throw new \InvalidArgumentException(
                    'Another actor (' . $existing['actor'] . ') has an open transaction (' . $existing['id']
                    . ', started ' . gmdate( 'Y-m-d H:i:s', (int) $existing['timestamp'] ) . '). '
                    . 'Transactions are locked to the actor that opened them — wait for them to commit or roll back, '
                    . 'or coordinate directly.'
                );
            }
            // Same actor re-begins: return the open transaction untouched (idempotent).
            return $existing;
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
     * Assert the calling actor owns the open transaction (multi-agent guard).
     * Read-only operations (preview/validate) may inspect any actor's staged
     * changes; mutations (stage/commit/rollback) are owner-only.
     */
    private function assert_owner() {
        $tx = $this->payload();
        $actor = Store::actor();
        if ( $tx['actor'] !== $actor ) {
            throw new \InvalidArgumentException(
                'Transaction ' . $tx['id'] . ' was opened by ' . $tx['actor'] . '; you are ' . $actor
                . '. Commit/rollback is locked to the opening actor — wait for them to finish, or coordinate directly.'
            );
        }
    }

    /**
     * Stage a full config array. Returns true if staged, false if no
     * transaction is open (caller falls back to a direct write).
     */
    public function stage( array $config ) {
        if ( ! $this->is_active() ) {
            return false;
        }
        $this->assert_owner();
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
     * Cancellation is done by returning $old_value, not false. WordPress's
     * pre_update_option_{$option} is a *transform* filter, not a veto: core
     * assigns whatever comes back to $value and then compares it to the stored
     * value, skipping the write only when they match (wp-includes/option.php,
     * the "If the new and old values are the same" check). Returning false
     * therefore did not cancel anything — it replaced the configuration with
     * the boolean, which agentshell_get_config() then quietly re-seeded from
     * default-config.json, so the agent's committed site silently reverted to
     * defaults. Returning the old value is what actually cancels.
     *
     * @param mixed $value     New value about to be written
     * @param mixed $old_value Currently stored value
     * @return mixed
     */
    public function on_config_update( $value, $old_value ) {
        if ( $this->is_active() ) {
            $this->stage( $value );
            return $old_value; // unchanged — WordPress skips the write
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
        $this->assert_owner();
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
        $this->assert_owner();
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

    // ------------------------------------------------------- preview access

    /**
     * Secret used to sign preview credentials.
     *
     * AGENTSHELL_REST_TOKEN is preferred — it is already the agent-facing
     * secret and never leaves the server. AUTH_SALT is the documented
     * fallback, and is what wp_hash() itself uses for the same purpose.
     *
     * Returns '' when neither is defined, and every caller must fail closed
     * on that rather than falling back to something guessable.
     */
    public static function preview_secret() {
        if ( defined( 'AGENTSHELL_REST_TOKEN' ) && is_string( constant( 'AGENTSHELL_REST_TOKEN' ) )
            && '' !== constant( 'AGENTSHELL_REST_TOKEN' ) ) {
            return (string) constant( 'AGENTSHELL_REST_TOKEN' );
        }
        if ( defined( 'AUTH_SALT' ) && is_string( constant( 'AUTH_SALT' ) ) && '' !== constant( 'AUTH_SALT' ) ) {
            return (string) constant( 'AUTH_SALT' );
        }
        return '';
    }

    /**
     * Query arguments authorising a preview of the currently open transaction.
     *
     * @throws \InvalidArgumentException when no transaction is open, or when
     *         no signing secret exists. Refusing here is deliberate: minting a
     *         credential from a guessable secret would be worse than not
     *         offering the preview at all.
     */
    public static function preview_args() {
        $tx = self::instance()->payload();
        if ( ! is_array( $tx ) ) {
            throw new \InvalidArgumentException(
                'No transaction is open. Call agentshell_begin_transaction before previewing one.'
            );
        }
        $secret = self::preview_secret();
        if ( '' === $secret ) {
            throw new \InvalidArgumentException(
                'Cannot authorise a transaction preview: neither AGENTSHELL_REST_TOKEN nor AUTH_SALT is defined.'
            );
        }

        $expires = time() + self::PREVIEW_TTL_SECONDS;
        return array(
            self::PREVIEW_TX_ARG  => (string) $tx['id'],
            self::PREVIEW_EXP_ARG => (string) $expires,
            self::PREVIEW_KEY_ARG => self::sign_preview( $tx['id'], $tx['actor'], $expires, $secret ),
        );
    }

    /**
     * HMAC over the transaction identity and the expiry window.
     *
     * Bound to the transaction id and its owner, so a credential issued for
     * one transaction cannot be replayed against another — including against
     * a later transaction that happens to reuse the id. Bound to the expiry,
     * so shortening the window changes the signature and an old credential
     * cannot be stretched by editing the timestamp parameter.
     */
    private static function sign_preview( $tx_id, $actor, $expires, $secret ) {
        return hash_hmac( 'sha256', 'agentshell-tx-preview|' . $tx_id . '|' . $actor . '|' . (int) $expires, $secret );
    }

    /**
     * True when these preview credentials may render the staged config now.
     *
     * Fails closed on every path: no open transaction, wrong transaction,
     * expired window, missing or non-numeric expiry, malformed id, or a
     * signature mismatch. A closed transaction fails because rollback and
     * commit both delete the option this reads, so a revoked preview stops
     * working on the very next request with no separate revocation list.
     */
    public static function authorize_preview( $tx_id, $key, $expires ) {
        $tx = self::instance()->payload();
        if ( ! is_array( $tx ) || empty( $tx['id'] ) ) {
            return false;
        }
        $secret = self::preview_secret();
        if ( '' === $secret || ! is_string( $key ) || '' === $key || ! is_string( $tx_id ) || '' === $tx_id ) {
            return false;
        }
        // Reject anything that is not a plain positive integer before it is
        // used in arithmetic or string comparison.
        if ( ! is_string( $expires ) && ! is_int( $expires ) ) {
            return false;
        }
        $expires = (int) $expires;
        // A zero or non-numeric expiry casts to 0, which is always in the
        // past — so one comparison covers "expired", "zero", and "not a
        // number" alike. That is not an accident; adding a separate
        // `$expires <= 0` arm is a branch that can never fail differently,
        // and mutation testing showed as much.
        if ( $expires < time() ) {
            return false;
        }
        // Bind to the transaction that is actually open.
        if ( ! hash_equals( (string) $tx['id'], $tx_id ) ) {
            return false;
        }

        return hash_equals(
            self::sign_preview( $tx['id'], $tx['actor'], $expires, $secret ),
            $key
        );
    }

    /**
     * Strip preview credentials from a URL before it is shown or logged.
     *
     * The returned summary and the audit log must never carry a bearer
     * capability. The transaction id and expiry are kept — they are useless
     * without the signature — because they make the URL diagnosable.
     *
     * Both preview key parameters are removed, not just the transaction one:
     * the profile preview key has the same properties and the same exposure
     * path, and a scrubber that only knows about one of them is a scrubber
     * that eventually leaks the other.
     */
    public static function redact_preview_url( $url ) {
        return remove_query_arg(
            array( self::PREVIEW_KEY_ARG, self::PREVIEW_PROFILE_KEY_ARG ),
            (string) $url
        );
    }

    /**
     * Remove every preview parameter from a URL supplied by a caller.
     *
     * The screenshot tool mints its own credentials, so anything arriving in
     * a `url` argument is either stale or borrowed — and the audit log records
     * arguments verbatim. Stripping first means a capability cannot be
     * persisted there by being pasted into a URL.
     */
    public static function strip_preview_args( $url ) {
        return remove_query_arg(
            array(
                self::PREVIEW_TX_ARG,
                self::PREVIEW_KEY_ARG,
                self::PREVIEW_EXP_ARG,
                self::PREVIEW_PROFILE_KEY_ARG,
            ),
            (string) $url
        );
    }
}