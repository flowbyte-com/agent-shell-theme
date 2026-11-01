<?php
/**
 * The shared authority, refusal and shaping layer for the agent tool surface.
 *
 * ## The two permission domains, enforced where the agent cannot supply them
 *
 * Editing a form and reading what people wrote into it are different acts.
 * The first needs `manage_agentshell_forms`; the second needs the separate
 * `manage_agentshell_forms_submissions` capability **and** the site's
 * personal-data policy to permit the surface **and** the caller's explicit
 * `authorize_personal_data` acknowledgement. `manage_options` is never
 * accepted as a substitute for either capability: the MCP transport holds it
 * for every call, so accepting it would grant every agent everything.
 *
 * The acknowledgement is a deliberation and audit mechanism, not a grant: a
 * caller that already holds the capability can simply supply it, and the
 * audit row records that the moment was stated, not that permission was
 * manufactured.
 *
 * ## The refusal contract
 *
 * Every refusal is a returned value, never a thrown one: a refusal is a
 * **decision**, recorded in the audit log, not a failure of the transport.
 * Under the default `off` policy the four entry tools refuse with the named
 * `forms_personal_data_disabled` error (§8.2a). Every personal-data refusal —
 * policy, capability, acknowledgement, confirmation — writes a
 * `form_submission_access_refused` row carrying identifiers and the reason,
 * never a submitted value.
 *
 * ## What this layer never does
 *
 * It never grants a capability, never changes the policy, and never returns
 * a secret. The Turnstile secret lives in its own option (or constant) and
 * no tool result carries it; the tools return definitions and metadata whose
 * shape is fixed below, and the unit suite asserts the secret appears in
 * none of them.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards, refusals and result shaping shared by the twelve form tools.
 */
class Form_Tools {

	/**
	 * The acting user.
	 *
	 * The MCP transport authenticates the principal and sets the current
	 * user before the tool runs, so this is the agent's user, not the
	 * site owner in any general sense.
	 *
	 * @return int
	 */
	public static function actor_id() {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Require the form-management capability.
	 *
	 * @return null|array Null when permitted, otherwise the refusal result.
	 */
	public static function require_forms_capability() {
		if ( Form_Capabilities::can_manage_forms() ) {
			return null;
		}

		return array(
			'ok'      => false,
			'code'    => 'forms_capability_forbidden',
			'message' => 'Managing forms requires the manage_agentshell_forms capability.',
		);
	}

	/**
	 * The personal-data gate for the four entry tools.
	 *
	 * Evaluated in order: the policy first (so the default `off` refuses
	 * before any principal is examined), then the capability the principal
	 * may not hold (`manage_options` is not consulted), then the explicit
	 * acknowledgement. The first failure wins, is audited, and is returned.
	 *
	 * @param string $tool    Tool name, for the audit row.
	 * @param array  $args    Tool arguments.
	 * @param array  $context Identifiers for the audit row (form_id and/or submission), never values.
	 * @return null|array Null when the call may proceed, otherwise the refusal result.
	 */
	public static function guard_personal_data( $tool, array $args, array $context = array() ) {
		$policy = Form_Policy::get();

		if ( Form_Policy::OFF === $policy ) {
			self::audit_refusal( $tool, 'policy_off', $policy, $context );

			return array(
				'ok'      => false,
				'code'    => 'forms_personal_data_disabled',
				'message' => 'Submission contents are not available through the agent surface. The site\'s personal-data policy is \'off\'.',
				'policy'  => Form_Policy::OFF,
			);
		}

		if ( ! Form_Capabilities::can_access_submissions() ) {
			self::audit_refusal( $tool, 'missing_submissions_capability', $policy, $context );

			return array(
				'ok'      => false,
				'code'    => 'forms_submissions_capability_required',
				'message' => 'Reading submission contents requires the manage_agentshell_forms_submissions capability, granted to this principal — manage_options is not a substitute.',
				'policy'  => $policy,
			);
		}

		if ( empty( $args['authorize_personal_data'] ) ) {
			self::audit_refusal( $tool, 'missing_acknowledgement', $policy, $context );

			return array(
				'ok'      => false,
				'code'    => 'forms_personal_data_acknowledgement_required',
				'message' => 'Pass authorize_personal_data: true to state that this access to personal data is deliberate. The flag is recorded in the audit log; it does not grant anything.',
				'policy'  => $policy,
			);
		}

		return null;
	}

	/**
	 * Require the explicit confirmation for a destructive tool.
	 *
	 * The requirement is not waived for the owner: the owner is precisely
	 * the actor most likely to reach for a destructive tool after a
	 * confusing moment.
	 *
	 * @param string $tool    Tool name, for the audit row.
	 * @param array  $args    Tool arguments.
	 * @param string $policy  Effective policy, for the audit row.
	 * @param array  $context Identifiers for the audit row, never values.
	 * @return null|array Null when confirmed, otherwise the refusal result.
	 */
	public static function require_confirmation( $tool, array $args, $policy, array $context = array() ) {
		if ( ! empty( $args['confirm'] ) ) {
			return null;
		}

		self::audit_refusal( $tool, 'missing_confirmation', $policy, $context );

		return array(
			'ok'      => false,
			'code'    => 'forms_confirmation_required',
			'message' => 'This operation permanently changes submission data. Pass confirm: true to state that the destruction is deliberate.',
			'policy'  => $policy,
		);
	}

	/**
	 * Record a personal-data refusal.
	 *
	 * Best effort by construction — the refusal stands whether or not the
	 * row lands, because the refused call changed nothing and there is no
	 * state to keep in step with the record. The payload carries identifiers
	 * and the reason, never a submitted value.
	 *
	 * @param string $tool    Tool name.
	 * @param string $reason  Machine-readable reason.
	 * @param string $policy  Effective policy.
	 * @param array  $context Identifiers (form_id and/or submission), never values.
	 * @return void
	 */
	public static function audit_refusal( $tool, $reason, $policy, array $context = array() ) {
		$data = array(
			'tool'   => (string) $tool,
			'reason' => (string) $reason,
			'policy' => (string) $policy,
		);

		if ( isset( $context['form_id'] ) ) {
			$data['form_id'] = (string) $context['form_id'];
		}

		if ( isset( $context['submission'] ) ) {
			$data['submission'] = (int) $context['submission'];
		}

		Form_Audit::record( 'form_submission_access_refused', $data, self::actor_id() );
	}

	/**
	 * A storage-layer failure as a tool result.
	 *
	 * Only whitelisted fields cross the boundary: the code, the message,
	 * field-level validation errors, and the revision counters of a
	 * compare-and-swap refusal. Anything else the error carries stays with
	 * the error.
	 *
	 * @param \WP_Error $error The failure.
	 * @return array
	 */
	public static function error_result( $error ) {
		$result = array(
			'ok'      => false,
			'code'    => $error->get_error_code() ? (string) $error->get_error_code() : 'forms_error',
			'message' => $error->get_error_message() ? (string) $error->get_error_message() : 'The operation failed.',
		);

		$data = $error->get_error_data();

		if ( is_array( $data ) ) {
			if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$result['errors'] = array_values( $data['errors'] );
			}

			foreach ( array( 'expected_revision', 'actual_revision' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$result[ $key ] = (int) $data[ $key ];
				}
			}
		}

		return $result;
	}

	/**
	 * Resolve a form reference to its state.
	 *
	 * Accepts the immutable internal identifier or the WordPress post id,
	 * because a caller should not have to know which handle it is holding.
	 *
	 * @param string|int $reference Internal identifier or post id.
	 * @return array|\WP_Error The state, or `form_not_found`.
	 */
	public static function resolve_form( $reference ) {
		$post_id = Form_Definition::resolve_post_id( $reference );

		if ( $post_id < 1 ) {
			return new \WP_Error( 'form_not_found', 'No form has that id.' );
		}

		return Form_Definition::get_by_post_id( $post_id );
	}

	/**
	 * The agent-visible summary of a form.
	 *
	 * Identifiers, counters and the definitions' metadata — never a
	 * submitted value, because a definition is operator-authored
	 * configuration and carries none.
	 *
	 * @param array $state The state from Form_Definition.
	 * @return array
	 */
	public static function summarize( array $state ) {
		$working = isset( $state['working'] ) && is_array( $state['working'] ) ? $state['working'] : array();

		return array(
			'form_id'            => (string) $state['form_id'],
			'post_id'            => (int) $state['post_id'],
			'slug'               => (string) $state['slug'],
			'name'               => isset( $working['name'] ) && '' !== (string) $working['name'] ? (string) $working['name'] : (string) $state['title'],
			'status'             => isset( $working['status'] ) && '' !== (string) $working['status'] ? (string) $working['status'] : ( $state['has_been_published'] ? 'published' : 'draft' ),
			'version'            => (int) $state['version'],
			'revision'           => (int) $state['revision'],
			'has_been_published' => (bool) $state['has_been_published'],
			'field_count'        => isset( $working['fields'] ) && is_array( $working['fields'] ) ? count( $working['fields'] ) : 0,
			'entry_count'        => Submission_Store::count_for_form( (string) $state['form_id'] ),
		);
	}

	/**
	 * A stored submission reduced to its metadata.
	 *
	 * By construction this carries no submitted value: the listing stays
	 * available in every policy precisely because there is nothing personal
	 * in it.
	 *
	 * @param array $record The record from Submission_Store::get().
	 * @return array
	 */
	public static function submission_metadata( array $record ) {
		$notification = isset( $record['notification'] ) && is_array( $record['notification'] ) ? $record['notification'] : array();

		return array(
			'id'                  => (int) $record['id'],
			'form_id'             => (string) $record['form_id'],
			'form_version'        => (int) $record['form_version'],
			'received_at'         => (string) $record['received_at'],
			'spam_status'         => (string) $record['spam_status'],
			'notification_status' => isset( $notification['status'] ) ? (string) $notification['status'] : '',
			'field_count'         => (int) $record['field_count'],
		);
	}
}
