<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The per-original exclusive claim for Stage 2c published-content editing.
 *
 * ## Why this exists
 *
 * Two AgentShell transactions must not be able to stage the same published
 * original at the same time. The journal op is the in-process record of who
 * is staging, but it is not a cross-process lock: a second PHP process
 * holding no journal row but beginning to stage the same original is
 * invisible to the journal. The claim is the cross-process barrier.
 *
 * The claim is a per-original `wp_options` row that names the holder
 * (`(tx_id, actor)`), captures the baseline timestamp, and is bound by a
 * TTL. Every mutation of the row is a value-guarded SQL CAS so the row
 * never moves under a caller without that caller learning about it.
 *
 * ## Why value-guarded SQL CAS, and not `add_option` / `update_option`
 *
 * `add_option()` and `update_option()` are unguarded. `update_option()` is
 * a `UPDATE wp_options SET option_value = ? WHERE option_name = ?` whose
 * only guard is the row's primary key; a caller whose `get_option()`
 * returned a stale value can silently overwrite a row that has since
 * moved. The claim has to be one row, owned by exactly one holder, and a
 * second holder landing a `cas_renew_row` must never silently clobber a
 * first holder's refresh. The three CAS primitives on this class are the
 * only mutators.
 *
 * ## Why a per-original claim, and not a per-tx one
 *
 * The brief required that a second transaction for the *same original*
 * (different `tx_id`, possibly same or different actor) be refused while
 * the first is still holding. A per-tx lock would not catch that: two
 * different transactions are two different locks. A per-original lock
 * does. The cross-process exclusion at the *publication* step is the
 * per-tx MySQL named advisory lock, which `agentshell_publish_edit`
 * acquires separately; the claim is the per-original staging lock.
 *
 * ## What this class does not do
 *
 * It does not settle the journal, it does not write any audit row, and
 * it does not coordinate with the journal's op state. The journal's
 * `state` field is the source of truth for "who is staging this
 * original"; the claim is the cross-process lock that prevents two
 * transactions from racing for the same original. A recovered claim
 * (after the holder crashed) is decided by consulting the journal, not
 * by trusting the claim's `tx_id`.
 */
class Published_Claim {

    /**
     * Default claim TTL in seconds.
     *
     * Matches the design's 120-second window so a refresh keeps the
     * claim alive across a long shadow edit, and an abandoned claim
     * becomes reclaimable on the same order of time. Renewal via
     * `cas_renew_row` is the orderly extension; a competing
     * transaction that finds the row expired may reclaim it only
     * after consulting the journal (the per-original safety rule).
     */
    const TTL_SECONDS = 120;

    /** Per-original `wp_options` key prefix. */
    const KEY_PREFIX = 'agentshell_published_claim_';

    /**
     * The well-formed claim row.
     *
     * `baseline_ts` is the unix timestamp at which the original's
     * strong after-image was captured (baseline-A). Every later
     * commit revalidates against this exact timestamp to detect
     * an external editor's intervening update. `claimed_at` is
     * the unix timestamp at which the claim was created or last
     * refreshed. `claim_ttl` is the TTL applied to `claimed_at`.
     */
    public static function shape( $tx_id, $original_id, $shadow_id, $actor, $baseline_ts, $claimed_at, $claim_ttl ) {
        return array(
            'tx_id'       => (string) $tx_id,
            'original_id' => (int) $original_id,
            'shadow_id'   => (int) $shadow_id,
            'actor'       => (string) $actor,
            'baseline_ts' => (int) $baseline_ts,
            'claimed_at'  => (int) $claimed_at,
            'claim_ttl'   => (int) $claim_ttl,
        );
    }

    /** The wp_options key for a given original id. */
    public static function key( $original_id ) {
        return self::KEY_PREFIX . (int) $original_id;
    }

    /**
     * Read the current claim row for an original, or null when no
     * claim exists. Uses `get_option()` so the read is the same one
     * every other caller in the process would see.
     */
    public static function read( $original_id ) {
        $row = get_option( self::key( $original_id ), null );
        if ( ! is_array( $row ) ) { return null; }
        // Defensive: any non-claim-shaped value is reported as
        // "no claim" so a corrupt or unrelated row never looks
        // like a held claim to the staging path.
        if ( ! isset( $row['tx_id'], $row['original_id'], $row['actor'] ) ) {
            return null;
        }
        return $row;
    }

    /**
     * Is the claim row currently within its TTL?
     *
     * A claim is fresh when `now < claimed_at + claim_ttl`. A
     * claim past its TTL is *eligible for reclaim* — the caller
     * must additionally consult the journal to determine whether
     * to refuse (the journal holds unpublished work for this
     * original) or to proceed.
     */
    public static function is_fresh( $row, $now = null ) {
        if ( ! is_array( $row ) ) { return false; }
        $now = ( null === $now ) ? Transaction_Manager::lease_now() : (int) $now;
        $expires = (int) ( $row['claimed_at'] ?? 0 ) + (int) ( $row['claim_ttl'] ?? 0 );
        return $now < $expires;
    }

    /**
     * Atomic create. Returns 'created' on a fresh row, 'exists' when
     * a row already exists (the caller must read it to determine
     * ownership), and 'database_error' when the SQL call failed.
     *
     * The implementation is `INSERT ... ON DUPLICATE KEY UPDATE option_value = option_value`
     * followed by a row-count check, so the create is genuinely
     * conditional: a second caller that races against an existing
     * row does not overwrite it. This is the `atomic_create_row`
     * primitive the design calls for; `add_option()` would *not*
     * behave the same way.
     */
    public static function create( $original_id, array $row ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) ) { return 'database_error'; }

        $key  = self::key( $original_id );
        $blob = maybe_serialize( $row );

        // ON DUPLICATE KEY UPDATE option_value = option_value is the
        // INSERT-conditional idiom: the duplicate key path is a
        // no-op that returns 0 affected rows, so a concurrent
        // caller that lands a duplicate observes 0 and learns the
        // row already exists without overwriting it. A fresh
        // insert returns 1.
        $sql = $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) "
            . "ON DUPLICATE KEY UPDATE option_name = option_name",
            $key, $blob, 'no'
        );
        $affected = $wpdb->query( $sql );
        if ( false === $affected ) { return 'database_error'; }
        // 1 == created; 0 == duplicate key path, the row already
        // existed.
        if ( (int) $affected === 1 ) {
            wp_cache_delete( $key, 'options' );
            return 'created';
        }
        return 'exists';
    }

    /**
     * Value-guarded renewal. Returns 'renewed' on success, 'moved'
     * when the row no longer holds $expected (a competing caller
     * has swapped the claim), 'gone' when the row is no longer
     * present, and 'database_error' on a SQL failure.
     *
     * The conditional is on the full row bytes: a row that has
     * been swapped by another caller is detected and the renewal
     * does not overwrite the new holder.
     */
    public static function renew( $original_id, array $expected, array $next ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) ) { return 'database_error'; }

        $key = self::key( $original_id );

        $pres = self::read( $original_id );
        if ( null === $pres ) { return 'gone'; }
        if ( ! self::rows_equal( $pres, $expected ) ) { return 'moved'; }

        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            maybe_serialize( $next ),
            $key,
            maybe_serialize( $pres )
        );
        $affected = $wpdb->query( $sql );
        if ( false === $affected ) { return 'database_error'; }
        if ( (int) $affected === 0 ) { return 'moved'; }

        wp_cache_delete( $key, 'options' );
        return 'renewed';
    }

    /**
     * Value-guarded release. Returns 'released' on success, 'moved'
     * when the row no longer holds $expected (a competing caller
     * has swapped the claim and our release would be wrong),
     * 'gone' when the row is no longer present, and
     * 'database_error' on a SQL failure.
     *
     * A release that returns 'moved' is treated by the caller as
     * "the claim is no longer ours"; the post-commit reconciliation
     * audit row records the move rather than masking it.
     */
    public static function release( $original_id, array $expected ) {
        global $wpdb;
        if ( ! isset( $wpdb ) || empty( $wpdb->options ) ) { return 'database_error'; }

        $key = self::key( $original_id );

        $pres = self::read( $original_id );
        if ( null === $pres ) { return 'gone'; }
        if ( ! self::rows_equal( $pres, $expected ) ) { return 'moved'; }

        $sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $key,
            maybe_serialize( $pres )
        );
        $affected = $wpdb->query( $sql );
        if ( false === $affected ) { return 'database_error'; }
        if ( (int) $affected === 0 ) { return 'moved'; }

        wp_cache_delete( $key, 'options' );
        return 'released';
    }

    /**
     * Two claim rows compared field-by-field.
     *
     * Byte-equality on the serialised form is fragile across
     * re-reads of fields WP normalises (e.g. a string that came
     * back as an int); structural equality is what the design
     * intends. A row that has changed in any field is "moved".
     */
    public static function rows_equal( array $a, array $b ) {
        foreach ( array( 'tx_id', 'original_id', 'shadow_id', 'actor', 'baseline_ts', 'claimed_at', 'claim_ttl' ) as $field ) {
            $av = isset( $a[ $field ] ) ? (string) $a[ $field ] : '';
            $bv = isset( $b[ $field ] ) ? (string) $b[ $field ] : '';
            if ( $av !== $bv ) { return false; }
        }
        return true;
    }
}
