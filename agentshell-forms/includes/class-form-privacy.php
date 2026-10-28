<?php
/**
 * WordPress's privacy exporter and eraser, for submissions.
 *
 * ## Why WordPress's registries rather than a second mechanism
 *
 * A subject access request handled through **Settings → Privacy** must answer
 * correctly without the operator remembering that this plugin also holds data.
 * WordPress already has the registry, the request workflow, the confirmation
 * emails and the audit trail; the plugin's job is to answer the two callbacks.
 * Building a parallel mechanism would be more code and less trustworthy.
 *
 * ## What the exporter returns
 *
 * Every submission whose stored values contain the requested address, with the
 * form, the version, the received-at time and each stored value. The match is
 * an exact, case-insensitive comparison against every scalar value, because a
 * submission was validated against the definition published *then* and the
 * current definition may name different fields.
 *
 * ## What the eraser does
 *
 * It **hard-deletes** the matching submissions. Trashing would leave the data
 * recoverable, which is not erasure. A deletion that fails is reported as
 * retained and the request is left open, so WordPress's own retry can finish
 * it rather than the operator being told a request completed when it did not.
 *
 * Every export and every erasure writes an audit row with a count — never a
 * submitted value.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Personal-data exporter and eraser.
 */
class Form_Privacy {

	/**
	 * The exporter/eraser key.
	 */
	const KEY = 'agentshell-forms';

	/**
	 * How many submissions one page of a request handles.
	 */
	const PER_PAGE = 50;

	/**
	 * Register both callbacks.
	 *
	 * @return void
	 */
	public static function register() {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		}
	}

	/**
	 * Add the exporter to WordPress's registry.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'AgentShell Forms submissions', 'agentshell-forms' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the eraser to WordPress's registry.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'AgentShell Forms submissions', 'agentshell-forms' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export one page of a subject's submissions.
	 *
	 * @param string $email_address The address.
	 * @param int    $page          Page number, 1-based.
	 * @return array{data:array<int,array>,done:bool}
	 */
	public static function export( $email_address, $page = 1 ) {
		$page = max( 1, (int) $page );
		$all  = Submission_Store::find_by_email( (string) $email_address, 1000 );

		$slice = array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$data  = array();

		foreach ( $slice as $record ) {
			$items = array(
				array(
					'name'  => __( 'Form', 'agentshell-forms' ),
					'value' => self::form_name( (string) $record['form_id'] ),
				),
				array(
					'name'  => __( 'Form identifier', 'agentshell-forms' ),
					'value' => (string) $record['form_id'],
				),
				array(
					'name'  => __( 'Form version', 'agentshell-forms' ),
					'value' => (string) $record['form_version'],
				),
				array(
					'name'  => __( 'Received at (UTC)', 'agentshell-forms' ),
					'value' => (string) $record['received_at'],
				),
			);

			foreach ( (array) $record['values'] as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = implode( '; ', array_map( 'strval', $value ) );
				} elseif ( is_bool( $value ) ) {
					$value = $value ? __( 'Yes', 'agentshell-forms' ) : __( 'No', 'agentshell-forms' );
				}

				$items[] = array(
					'name'  => (string) $key,
					'value' => (string) $value,
				);
			}

			$data[] = array(
				'group_id'    => 'agentshell-forms',
				'group_label' => __( 'Form submissions', 'agentshell-forms' ),
				'item_id'     => 'agentshell-entry-' . (int) $record['id'],
				'data'        => $items,
			);
		}

		Form_Audit::record(
			'form_submissions_exported_for_privacy',
			array(
				'page'  => $page,
				'count' => count( $data ),
			),
			0
		);

		return array(
			'data' => $data,
			'done' => count( $slice ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase one page of a subject's submissions.
	 *
	 * @param string $email_address The address.
	 * @param int    $page          Page number, 1-based.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public static function erase( $email_address, $page = 1 ) {
		$page  = max( 1, (int) $page );
		$all   = Submission_Store::find_by_email( (string) $email_address, 1000 );
		$slice = array_slice( $all, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$removed  = 0;
		$retained = 0;
		$messages = array();

		foreach ( $slice as $record ) {
			$result = Submission_Store::delete_permanently( (int) $record['id'], 0, true );

			if ( is_wp_error( $result ) ) {
				$retained++;
				$messages[] = sprintf(
					/* translators: 1: submission id, 2: error message */
					__( 'Submission %1$d could not be erased: %2$s', 'agentshell-forms' ),
					(int) $record['id'],
					$result->get_error_message()
				);
				continue;
			}

			$removed++;
		}

		Form_Audit::record(
			'form_submissions_erased_for_privacy',
			array(
				'page'     => $page,
				'removed'  => $removed,
				'retained' => $retained,
			),
			0
		);

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained > 0,
			'messages'       => $messages,
			'done'           => count( $slice ) < self::PER_PAGE,
		);
	}

	/**
	 * A form's name, for the export.
	 *
	 * @param string $form_id Internal identifier.
	 * @return string
	 */
	private static function form_name( $form_id ) {
		$post_id = Form_Definition::resolve_post_id( $form_id );

		if ( $post_id < 1 ) {
			return $form_id;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post && '' !== (string) $post->post_title
			? (string) $post->post_title
			: (string) $form_id;
	}
}
