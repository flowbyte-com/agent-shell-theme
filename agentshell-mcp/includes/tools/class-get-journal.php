<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * agentshell_get_journal — read-only inspection of a transaction's content journal.
 *
 * ## Why a tool rather than a field on get_transaction
 *
 * A journal is not a summary; it is a list of objects that may be destroyed,
 * each with the evidence recovery would use before destroying it. Folding that
 * into `agentshell_get_transaction` would put it in front of every caller who
 * wants to know whether a transaction is open, and the answer they would get
 * would depend on them not reading carefully.
 *
 * ## Read-only, and read-only in a way that means something
 *
 * This tool never writes. It does not settle the lease, so it cannot reclaim
 * an expired transaction; it does not renew, so it cannot keep somebody else's
 * work alive; it does not touch a post. That is the security property the
 * design needs: being able to *look* at a problem must never be a way to
 * change it. `Content_Journal::inspect()` takes a row and returns a
 * description, so there is no code path from this tool to a write at all.
 *
 * Reading is not gated on being allowed to resolve. Inspection is open to any
 * authenticated actor because it is free of consequences, and because the
 * actor most likely to need it after a crash — somebody other than the owner —
 * is exactly the one a write-capability check would shut out.
 */
class Get_Journal extends Base_Tool {
    public function get_name() { return 'agentshell_get_journal'; }
    public function get_description() {
        return 'Inspect a transaction\'s durable content journal without changing anything: which operations it '
            . 'recorded, which WordPress post each one concerns, what state each is in, whether the journal is '
            . 'intact, and whether it can be recovered. Read-only: it never reclaims, renews, or touches content.';
    }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(),
        );
    }

    public function execute( array $arguments ) {
        return \AgentShell_MCP\Content_Journal::inspect();
    }
}