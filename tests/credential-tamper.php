<?php
/**
 * Tamper with a credential's signature in a way that is GUARANTEED to change
 * it.
 *
 * The failure this exists to prevent
 * ----------------------------------
 * The obvious tamper — prefixing a fixed character — is a NO-OP when the
 * signature already begins with that character, and a signature is a hex
 * string, so it begins with 'a' one time in sixteen. On those runs the
 * "tampered" credential was byte-identical to the valid credential, the
 * assertion tested nothing, and it FAILED.
 *
 * The failure looks exactly like a security defect — a tampered
 * published-preview credential being accepted — and it is invisible in
 * isolation: the assertion passes fifteen times out of sixteen and fails on
 * the sixteenth, in bursts, because consecutive runs inside the same second
 * mint the same signature and therefore share the same outcome.
 *
 * It is not only invisible; it is actively misleading. A suite that
 * intermittently fails a security assertion trains everyone to ignore that
 * suite's security assertions — which is how a real defect would be missed.
 *
 * A signature assertion has to be certain that it changed the thing it
 * claims to change. This helper replaces the first character with a
 * DIFFERENT one, so the tampered signature is always different from the
 * original, and returns the tampered credential's signature alongside it so
 * a failure can say what happened.
 *
 * The helper is shared by every suite that tampers with a signature, so a
 * fourth copy of this mistake cannot be written.
 *
 * @param array $credential The decoded credential blob.
 * @return array{credential:array, original:string, tampered:string}
 */
function agentshell_test_tamper_signature( array $credential ) {
    $key   = (string) ( $credential['key'] ?? '' );
    $first = substr( $key, 0, 1 );
    $credential['key'] = ( 'a' === $first ? 'b' : 'a' ) . substr( $key, 1 );
    return array(
        'credential' => $credential,
        'original'   => $key,
        'tampered'   => (string) $credential['key'],
    );
}

/**
 * A short description of a signature, for a failure line.
 *
 * @param string $signature
 * @return string
 */
function agentshell_test_signature_summary( $signature ) {
    $signature = (string) $signature;
    return substr( $signature, 0, 12 ) . '…(' . strlen( $signature ) . ' chars)';
}
