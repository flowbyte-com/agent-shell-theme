<?php
/**
 * The personal-data policy switch for the agent tool surface.
 *
 * ## What this switch is, and what it is not
 *
 * This switch governs **the plugin's own agent tool surface, and nothing
 * else** (§8.3a, Limitation 2 of the design). It does not govern the
 * administrative screens (governed by the capability alone), WordPress's
 * privacy exporter and eraser, any other plugin's direct queries, or the
 * post type's REST surface. A reader who believes `off` protects the data
 * everywhere would be mistaken.
 *
 * ## The default, and why invalid values fail closed
 *
 * The default is `off`: submission contents are not reachable through the
 * agent surface at all, and the four entry tools refuse with a named error
 * that is audited. A value that is not on the allowlist — including the
 * deferred `credential` mode, which names a trust domain that does not exist
 * yet — is read as `off`. A switch that failed open on a typo would be a
 * switch that grants access when the operator misspells the word that
 * withholds it.
 *
 * ## How the switch is changed, and what cannot change it
 *
 * The switch is a plain option, changed by the operator outside the agent
 * surface (for example with `wp option update
 * agentshell_forms_personal_data_policy capability`). There is deliberately
 * **no tool that changes it** (§8.7): an agent that could flip the policy
 * would be an agent that could grant itself everything the policy withholds.
 * The tool surface treats this class as read-only; it exposes `get()` and
 * the constants the tools compare against, and no setter.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read access to the personal-data policy switch.
 */
class Form_Policy {

	/**
	 * The option holding the switch.
	 */
	const OPTION = 'agentshell_forms_personal_data_policy';

	/**
	 * Submission contents are not reachable through the agent surface.
	 */
	const OFF = 'off';

	/**
	 * Reachable only by a principal holding the submissions capability,
	 * and only with the explicit acknowledgement.
	 */
	const CAPABILITY = 'capability';

	/**
	 * Reachable only with the dedicated data credential. Deferred (§8.3b):
	 * recognised here so the switch is not reshaped when it arrives, but
	 * never granted — a stored `credential` value reads as `off` until the
	 * credential exists.
	 */
	const CREDENTIAL = 'credential';

	/**
	 * The effective policy.
	 *
	 * @return string `off` or `capability`. Anything else on record reads as `off`.
	 */
	public static function get() {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, self::OFF ) : self::OFF;

		if ( ! is_string( $value ) ) {
			return self::OFF;
		}

		$value = strtolower( trim( $value ) );

		if ( self::CAPABILITY === $value ) {
			return self::CAPABILITY;
		}

		return self::OFF;
	}
}
