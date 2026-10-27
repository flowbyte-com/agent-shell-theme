<?php
/**
 * The two permission domains, and the per-user grant that keeps them separate.
 *
 * ## Two capabilities, and they are not interchangeable
 *
 *   - `manage_agentshell_forms` — create, edit, publish, discard, delete forms.
 *   - `manage_agentshell_forms_submissions` — read, export and delete
 *     submissions.
 *
 * WordPress custom capabilities are the MVP's **primary** authorisation
 * mechanism (owner decision Q11). There is no second credential system and no
 * human-approval workflow in this build.
 *
 * ## Why the submissions capability is not granted to any role
 *
 * The MCP transport requires `manage_options` for every call, so **every MCP
 * agent is an administrator**. If the submissions capability were granted to
 * the administrator role, every agent would hold it, and the separation
 * between "may edit a form" and "may read what people wrote into it" would
 * collapse.
 *
 * So activation grants `manage_agentshell_forms` to the administrator role and
 * grants `manage_agentshell_forms_submissions` to **no role at all**. A site
 * owner who needs the submissions interface has the capability granted to
 * their own user record through the procedure in the design's §8.3a, which is
 * implemented here as `grant_submissions()` and the two handlers below.
 *
 * ## Why the grant is not a REST route
 *
 * A REST route guarded by a capability check alone is reachable by any
 * administrator principal — including the MCP transport's application
 * password, which satisfies a capability check exactly as a cookie does. An
 * agent that could reach the grant would be an agent that could grant itself
 * the capability, and the separation would be decoration.
 *
 * The handlers are `admin_post` actions and require three things together: the
 * capability, a **cookie-authenticated session**, and a nonce minted by the
 * administrative screen. An application-password principal cannot render that
 * screen, so it cannot obtain the nonce.
 *
 * ## The limitation this does not fix
 *
 * An agent and a human who use **the same WordPress account** share that
 * account's capabilities. If the owner grants themselves the submissions
 * capability and runs the agent under that same account, the agent inherits
 * it. That is a property of the deployment, not a defect in this mechanism:
 * principal-level isolation requires separate WordPress accounts.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability definitions, checks and the per-user grant.
 */
class Form_Capabilities {

	/**
	 * Create, edit, publish, discard and delete forms.
	 */
	const CAP_FORMS = 'manage_agentshell_forms';

	/**
	 * Read, export and delete submissions.
	 */
	const CAP_SUBMISSIONS = 'manage_agentshell_forms_submissions';

	/**
	 * User meta recording when the submissions capability was granted.
	 */
	const USER_META_GRANTED_AT = '_agentshell_forms_submissions_granted_at';

	/**
	 * User meta recording who performed the grant.
	 */
	const USER_META_GRANTED_BY = '_agentshell_forms_submissions_granted_by';

	/**
	 * Register the capabilities.
	 *
	 * Called on activation. Idempotent: `add_cap()` on a role that already has
	 * the capability is a no-op that rewrites the same value.
	 *
	 * @return void
	 */
	public static function register() {
		$admin = get_role( 'administrator' );
		if ( $admin instanceof \WP_Role ) {
			$admin->add_cap( self::CAP_FORMS, true );
			// Deliberately NOT granted (§8.3). If a future change adds this
			// line, every MCP agent becomes a submission reader.
		}
	}

	/**
	 * May the user manage forms?
	 *
	 * @param int|null $user_id User id, or null for the current user.
	 * @return bool
	 */
	public static function can_manage_forms( $user_id = null ) {
		if ( null === $user_id ) {
			return (bool) current_user_can( self::CAP_FORMS );
		}
		return user_can( (int) $user_id, self::CAP_FORMS );
	}

	/**
	 * May the user reach submission contents?
	 *
	 * `manage_options` is **not** accepted as a substitute. That is the whole
	 * point: the agent transport always has it, so accepting it would grant
	 * every agent the capability.
	 *
	 * @param int|null $user_id User id, or null for the current user.
	 * @return bool
	 */
	public static function can_access_submissions( $user_id = null ) {
		if ( null === $user_id ) {
			return (bool) current_user_can( self::CAP_SUBMISSIONS );
		}
		return user_can( (int) $user_id, self::CAP_SUBMISSIONS );
	}

	/**
	 * Grant the submissions capability to one user.
	 *
	 * This is the mechanism, not the authorisation: the caller is responsible
	 * for having established that the grant is allowed. The permission-checked
	 * procedure is `handle_grant()`.
	 *
	 * The capability is written to the **user's own** capability set
	 * (`WP_User::add_cap()` writes the user's `caps` user meta), not to a role,
	 * so it does not propagate to any other user of the same role.
	 *
	 * @param int $user_id The user to grant to.
	 * @param int $actor_id Who is granting, for the audit trail.
	 * @return bool True when the user holds the capability afterwards.
	 */
	public static function grant_submissions( $user_id, $actor_id = 0 ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$user->add_cap( self::CAP_SUBMISSIONS, true );
		$user->get_role_caps();

		update_user_meta( $user->ID, self::USER_META_GRANTED_AT, gmdate( 'c' ) );
		update_user_meta( $user->ID, self::USER_META_GRANTED_BY, (int) $actor_id );

		Form_Audit::record(
			'form_submission_access_granted',
			array(
				'subject_user' => (int) $user->ID,
				'capability'   => self::CAP_SUBMISSIONS,
			),
			(int) $actor_id
		);

		return self::can_access_submissions( $user->ID );
	}

	/**
	 * Revoke the submissions capability from one user.
	 *
	 * @param int $user_id The user to revoke from.
	 * @param int $actor_id Who is revoking, for the audit trail.
	 * @return bool True when the user no longer holds the capability.
	 */
	public static function revoke_submissions( $user_id, $actor_id = 0 ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		$user->remove_cap( self::CAP_SUBMISSIONS );
		$user->get_role_caps();

		delete_user_meta( $user->ID, self::USER_META_GRANTED_AT );
		delete_user_meta( $user->ID, self::USER_META_GRANTED_BY );

		Form_Audit::record(
			'form_submission_access_revoked',
			array(
				'subject_user' => (int) $user->ID,
				'capability'   => self::CAP_SUBMISSIONS,
			),
			(int) $actor_id
		);

		return ! self::can_access_submissions( $user->ID );
	}

	/**
	 * Every user who holds the submissions capability.
	 *
	 * @return array<int,\WP_User>
	 */
	public static function users_with_submissions_access() {
		$users = get_users(
			array(
				'capability' => self::CAP_SUBMISSIONS,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'fields'     => 'all',
			)
		);

		return is_array( $users ) ? $users : array();
	}

	/**
	 * When the submissions capability was granted to a user.
	 *
	 * @param int $user_id User id.
	 * @return string ISO-8601 timestamp, or '' when not granted through this plugin.
	 */
	public static function granted_at( $user_id ) {
		$value = get_user_meta( (int) $user_id, self::USER_META_GRANTED_AT, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Is this request authenticated by a cookie session?
	 *
	 * An MCP transport call is authenticated by an application password or a
	 * static token, not a cookie, so this is false for it — which is what makes
	 * the grant action unreachable by an agent.
	 *
	 * @return bool
	 */
	public static function is_cookie_authenticated() {
		if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
			return false;
		}
		return false !== wp_validate_auth_cookie( '', 'logged_in' );
	}

	/**
	 * The nonce action for a grant or revocation.
	 *
	 * @param string $which `grant` or `revoke`.
	 * @return string
	 */
	public static function nonce_action( $which ) {
		return 'agentshell_forms_' . ( 'revoke' === $which ? 'revoke' : 'grant' ) . '_submissions';
	}

	/**
	 * `admin_post` handler: grant the submissions capability to the signed-in
	 * user.
	 *
	 * Three conditions, all necessary:
	 *
	 *   1. the actor holds `manage_agentshell_forms`;
	 *   2. the request carries a cookie-authenticated session;
	 *   3. the request carries a valid nonce for this action.
	 *
	 * @return void
	 */
	public static function handle_grant() {
		self::assert_grant_preconditions( 'grant' );

		$actor_id = get_current_user_id();
		self::grant_submissions( $actor_id, $actor_id );

		self::redirect_back( 'granted' );
	}

	/**
	 * `admin_post` handler: revoke the submissions capability from a user.
	 *
	 * Revoking somebody else's access is the same authority as granting your
	 * own: the form-management capability, a cookie session and a nonce.
	 *
	 * @return void
	 */
	public static function handle_revoke() {
		self::assert_grant_preconditions( 'revoke' );

		$actor_id  = get_current_user_id();
		$subject   = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

		if ( $subject < 1 ) {
			self::refuse( __( 'A user id is required to revoke access.', 'agentshell-forms' ) );
		}

		self::revoke_submissions( $subject, $actor_id );

		self::redirect_back( 'revoked' );
	}

	/**
	 * Refuse a grant or revocation that fails any precondition.
	 *
	 * Refusals are audited so a refused attempt is distinguishable from one
	 * that never happened.
	 *
	 * @param string $which `grant` or `revoke`.
	 * @return void
	 */
	private static function assert_grant_preconditions( $which ) {
		$actor_id = get_current_user_id();

		if ( ! self::is_cookie_authenticated() ) {
			self::audit_refusal( $which, $actor_id, 'not_cookie_authenticated' );
			self::refuse( __( 'This action requires a signed-in browser session.', 'agentshell-forms' ) );
		}

		if ( ! self::can_manage_forms() ) {
			self::audit_refusal( $which, $actor_id, 'missing_manage_forms' );
			self::refuse( __( 'You are not allowed to manage forms.', 'agentshell-forms' ) );
		}

		check_admin_referer( self::nonce_action( $which ) );
	}

	/**
	 * Record a refused grant or revocation.
	 *
	 * @param string $which  `grant` or `revoke`.
	 * @param int    $actor  Acting user id.
	 * @param string $reason Machine-readable reason.
	 * @return void
	 */
	private static function audit_refusal( $which, $actor, $reason ) {
		Form_Audit::record(
			'form_submission_access_refused',
			array(
				'action' => (string) $which,
				'reason' => (string) $reason,
			),
			(int) $actor
		);
	}

	/**
	 * Stop the request with a 403.
	 *
	 * @param string $message Human message.
	 * @return void
	 */
	private static function refuse( $message ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Submission access', 'agentshell-forms' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Redirect back to the forms screen with a result flag.
	 *
	 * @param string $result `granted` or `revoked`.
	 * @return void
	 */
	private static function redirect_back( $result ) {
		$url = add_query_arg(
			array( 'agentshell_forms_access' => rawurlencode( $result ) ),
			admin_url( 'admin.php?page=agentshell-forms' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
