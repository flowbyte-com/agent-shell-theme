<?php
/**
 * The submission administration screens.
 *
 * ## Two capabilities, two different reads
 *
 * Reaching the screen needs `manage_agentshell_forms`. Reading a submission's
 * **values**, exporting them or deleting a record needs
 * `manage_agentshell_forms_submissions`, which the administrator role does
 * **not** hold (§8.3). The metadata listing — id, received-at, version, spam
 * status, notification status — is not personal data, so it is visible to a
 * form manager; the values are not.
 *
 * The owner grants themselves the capability from this screen, through the
 * Phase 1 per-user mechanism. The grant is an `admin_post` handler guarded by a
 * cookie session and a nonce, never a REST route, so the MCP transport (an
 * application password) cannot reach it (§8.3a).
 *
 * ## Export is a download, not a page
 *
 * It is an `admin_post` handler with a nonce and the submissions capability,
 * and it requires an explicit `authorize_personal_data` acknowledgement. The
 * CSV applies spreadsheet-formula-injection protection to every cell; the JSON
 * is encoded, not executed.
 *
 * ## Every act is audited
 *
 * Reading a submission names the acknowledgement and the form; exporting names
 * the form and the row count; trashing, restoring and erasing are recorded by
 * the store. A refusal is a decision, not an absence.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The forms menu, the submissions screens and the access screen.
 */
class Form_Admin {

	/**
	 * The submissions screen slug.
	 */
	const PAGE_SUBMISSIONS = 'agentshell-forms';
	const PAGE_BUILDER     = 'agentshell-forms-builder';

	/**
	 * The single-submission screen slug.
	 */
	const PAGE_SUBMISSION = 'agentshell-forms-submission';

	/**
	 * The access screen slug.
	 */
	const PAGE_ACCESS = 'agentshell-forms-access';

	/**
	 * Register the menu and the handlers.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_agentshell_forms_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_agentshell_forms_submission_action', array( __CLASS__, 'handle_submission_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_builder' ) );
	}

	/**
	 * The menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'AgentShell Forms', 'agentshell-forms' ),
			__( 'AgentShell Forms', 'agentshell-forms' ),
			Form_Capabilities::CAP_FORMS,
			self::PAGE_SUBMISSIONS,
			array( __CLASS__, 'render_submissions' ),
			'dashicons-feedback',
			58
		);

		add_submenu_page(
			self::PAGE_SUBMISSIONS,
			__( 'Forms', 'agentshell-forms' ),
			__( 'Forms', 'agentshell-forms' ),
			Form_Capabilities::CAP_FORMS,
			self::PAGE_BUILDER,
			array( __CLASS__, 'render_builder' )
		);

		add_submenu_page(
			self::PAGE_SUBMISSIONS,
			__( 'Submissions', 'agentshell-forms' ),
			__( 'Submissions', 'agentshell-forms' ),
			Form_Capabilities::CAP_FORMS,
			self::PAGE_SUBMISSIONS,
			array( __CLASS__, 'render_submissions' )
		);

		add_submenu_page(
			// Reached from a row's View button. A visitor who lands here without
			// an id is sent back to the list rather than shown an empty page.
			self::PAGE_SUBMISSIONS,
			__( 'Submission', 'agentshell-forms' ),
			__( 'Submission', 'agentshell-forms' ),
			Form_Capabilities::CAP_SUBMISSIONS,
			self::PAGE_SUBMISSION,
			array( __CLASS__, 'render_submission' )
		);

		add_submenu_page(
			self::PAGE_SUBMISSIONS,
			__( 'Access', 'agentshell-forms' ),
			__( 'Access', 'agentshell-forms' ),
			Form_Capabilities::CAP_FORMS,
			self::PAGE_ACCESS,
			array( __CLASS__, 'render_access' )
		);
	}

	/**
	 * The form builder.
	 *
	 * @return void
	 */
	public static function render_builder() {
		if ( ! Form_Capabilities::can_manage_forms() ) {
			wp_die( esc_html__( 'You are not allowed to manage forms.', 'agentshell-forms' ), 403 );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AgentShell Forms', 'agentshell-forms' ) . '</h1>';
		echo '<div id="agentshell-forms-builder" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-rest="' . esc_attr( rest_url( 'wp/v2/agentshell-forms/v1' ) ) . '" data-rest-root="' . esc_attr( rest_url() ) . '"></div>';
		echo '</div>';
	}

	/**
	 * Enqueue the builder's modules and stylesheet.
	 *
	 * @param string $hook The admin hook.
	 * @return void
	 */
	public static function enqueue_builder( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_BUILDER ) ) {
			return;
		}

		if ( ! Form_Capabilities::can_manage_forms() ) {
			return;
		}

		wp_enqueue_style(
			'agentshell-forms-admin',
			AGENTSHELL_FORMS_PLUGIN_URL . 'admin/css/forms-admin.css',
			array(),
			AGENTSHELL_FORMS_VERSION
		);

		$modules = array( 'forms-model', 'forms-api', 'forms-ui', 'forms-app' );

		foreach ( $modules as $module ) {
			wp_enqueue_script(
				'agentshell-forms-admin-' . $module,
				AGENTSHELL_FORMS_PLUGIN_URL . 'admin/js/' . $module . '.js',
				array(),
				AGENTSHELL_FORMS_VERSION,
				true
			);
		}
	}

	/**
	 * The submissions list.
	 *
	 * @return void
	 */
	public static function render_submissions() {
		if ( ! Form_Capabilities::can_manage_forms() ) {
			wp_die( esc_html__( 'You are not allowed to manage forms.', 'agentshell-forms' ), 403 );
		}

		$forms = self::forms();
		$form  = isset( $_GET['form'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $form && ! empty( $forms ) ) {
			$form = (string) $forms[0]['form_id'];
		}

		$status = isset( $_GET['status'] ) && 'trash' === $_GET['status'] ? 'trash' : 'publish'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$can_read = Form_Capabilities::can_access_submissions();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Form submissions', 'agentshell-forms' ) . '</h1>';

		self::notices();

		if ( empty( $forms ) ) {
			echo '<p>' . esc_html__( 'No forms exist yet. A form is created and published before it can receive submissions.', 'agentshell-forms' ) . '</p>';
			echo '</div>';
			return;
		}

		self::form_picker( $forms, $form, $status );

		if ( ! $can_read ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Submission contents are protected. You can see when submissions arrived, but not what they contain.', 'agentshell-forms' )
				. '</p>';

			$action = admin_url( 'admin-post.php' );

			echo '<form method="post" action="' . esc_url( $action ) . '">';
			wp_nonce_field( Form_Capabilities::nonce_action( 'grant' ) );
			echo '<input type="hidden" name="action" value="agentshell_forms_grant_submissions">';
			submit_button( __( 'Grant me access to form submissions', 'agentshell-forms' ), 'primary', 'submit', false );
			echo '</form></div>';
		}

		$records = Submission_Store::list_for_form( $form, array( 'status' => $status, 'limit' => 200, 'with_values' => false ) );

		echo '<p>' . esc_html( self::retention_text( $forms, $form ) ) . '</p>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Received (UTC)', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Version', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Spam', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Notification', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'agentshell-forms' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $records ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No submissions in this view.', 'agentshell-forms' ) . '</td></tr>';
		}

		foreach ( $records as $record ) {
			echo '<tr>';
			echo '<td>' . (int) $record['id'] . '</td>';
			echo '<td>' . esc_html( (string) $record['received_at'] ) . '</td>';
			echo '<td>' . (int) $record['form_version'] . '</td>';
			echo '<td>' . esc_html( (string) $record['spam_status'] ) . '</td>';
			echo '<td>' . esc_html( self::notification_label( $record ) ) . '</td>';
			echo '<td>';

			if ( $can_read ) {
				$view = add_query_arg(
					array(
						'page' => self::PAGE_SUBMISSION,
						'id'   => (int) $record['id'],
					),
					admin_url( 'admin.php' )
				);

				echo '<a class="button button-small" href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'agentshell-forms' ) . '</a> ';
			}

			if ( $can_read ) {
				if ( 'trash' === $status ) {
					echo self::action_button( (int) $record['id'], 'restore', __( 'Restore', 'agentshell-forms' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_* inside.
				} else {
					echo self::action_button( (int) $record['id'], 'trash', __( 'Move to trash', 'agentshell-forms' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}

				echo ' ' . self::action_button( (int) $record['id'], 'delete', __( 'Delete permanently', 'agentshell-forms' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $can_read ) {
			self::export_form( $form );
		}

		echo '</div>';
	}

	/**
	 * The single-submission screen.
	 *
	 * @return void
	 */
	public static function render_submission() {
		if ( ! Form_Capabilities::can_access_submissions() ) {
			wp_die(
				esc_html__( 'Reading a submission’s contents requires access to form submissions.', 'agentshell-forms' ),
				esc_html__( 'Submission access', 'agentshell-forms' ),
				array( 'response' => 403 )
			);
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $id < 1 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SUBMISSIONS ) );
			exit;
		}

		$record = Submission_Store::get( $id, true );

		if ( is_wp_error( $record ) ) {
			wp_die( esc_html( $record->get_error_message() ), 404 );
		}

		$definition = self::definition_for( (string) $record['form_id'] );
		$labels     = self::labels( $definition );

		Form_Audit::record(
			'form_submission_inspected',
			array(
				'submission'              => (int) $record['id'],
				'form_id'                 => (string) $record['form_id'],
				'authorize_personal_data' => true,
				'policy'                  => 'admin_screen',
			),
			get_current_user_id()
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Submission', 'agentshell-forms' ) . '</h1>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SUBMISSIONS . '&form=' . rawurlencode( (string) $record['form_id'] ) ) ) . '">'
			. esc_html__( 'Back to submissions', 'agentshell-forms' ) . '</a></p>';

		echo '<table class="form-table" role="presentation">';

		$facts = array(
			__( 'Form', 'agentshell-forms' )          => self::form_label( (string) $record['form_id'] ),
			__( 'Received (UTC)', 'agentshell-forms' ) => (string) $record['received_at'],
			__( 'Form version', 'agentshell-forms' )  => (string) $record['form_version'],
			__( 'Spam status', 'agentshell-forms' )   => (string) $record['spam_status'],
			__( 'Status', 'agentshell-forms' )        => (string) $record['status'],
			__( 'Integrity hash', 'agentshell-forms' ) => (string) $record['hash'],
			__( 'Notification', 'agentshell-forms' )  => self::notification_label( $record ),
		);

		foreach ( $facts as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>';
		}

		echo '</table>';

		// The confirmation record, when one exists, is shown with the same
		// vocabulary as the administrator notification: accepted, never sent.
		if ( is_array( $record['confirmation'] ) ) {
			echo '<h2>' . esc_html__( 'Confirmation to the submitter', 'agentshell-forms' ) . '</h2>';
			echo '<p><code>' . esc_html( self::describe_record( $record['confirmation'] ) ) . '</code></p>';
		}

		echo '<h2>' . esc_html__( 'Submitted values', 'agentshell-forms' ) . '</h2>';
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Field', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Value', 'agentshell-forms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( (array) $record['values'] as $key => $value ) {
			$label = isset( $labels[ $key ] ) ? $labels[ $key ] : (string) $key;

			if ( is_array( $value ) ) {
				$value = implode( '; ', array_map( 'strval', $value ) );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? __( 'Yes', 'agentshell-forms' ) : __( 'No', 'agentshell-forms' );
			}

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * The access screen.
	 *
	 * @return void
	 */
	public static function render_access() {
		if ( ! Form_Capabilities::can_manage_forms() ) {
			wp_die( esc_html__( 'You are not allowed to manage forms.', 'agentshell-forms' ), 403 );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Submission access', 'agentshell-forms' ) . '</h1>';

		self::notices();

		echo '<p>' . esc_html__( 'Submission contents are available only to the accounts listed here. The administrator role does not hold this access; it is granted per user.', 'agentshell-forms' ) . '</p>';

		$users = Form_Capabilities::users_with_submissions_access();

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'User', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Granted at', 'agentshell-forms' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'agentshell-forms' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $users ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'Nobody holds submission access yet.', 'agentshell-forms' ) . '</td></tr>';
		}

		foreach ( $users as $user ) {
			echo '<tr>';
			echo '<td>' . esc_html( $user->display_name . ' (' . $user->user_login . ')' ) . '</td>';
			echo '<td>' . esc_html( Form_Capabilities::granted_at( $user->ID ) ) . '</td>';
			echo '<td>';

			$action = admin_url( 'admin-post.php' );

			echo '<form method="post" action="' . esc_url( $action ) . '" style="display:inline">';
			wp_nonce_field( Form_Capabilities::nonce_action( 'revoke' ) );
			echo '<input type="hidden" name="action" value="agentshell_forms_revoke_submissions">';
			echo '<input type="hidden" name="user_id" value="' . (int) $user->ID . '">';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Revoke', 'agentshell-forms' ) . '</button>';
			echo '</form>';

			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Stream an export.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! Form_Capabilities::can_access_submissions() ) {
			Form_Audit::record(
				'form_submission_access_refused',
				array(
					'action' => 'export',
					'reason' => 'missing_submissions_capability',
				),
				get_current_user_id()
			);

			wp_die(
				esc_html__( 'Exporting submissions requires access to form submissions.', 'agentshell-forms' ),
				esc_html__( 'Submission access', 'agentshell-forms' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'agentshell_forms_export' );

		$form   = isset( $_REQUEST['form'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['form'] ) ) : '';
		$format = isset( $_REQUEST['format'] ) && 'json' === $_REQUEST['format'] ? 'json' : 'csv';
		$ack    = isset( $_REQUEST['authorize_personal_data'] ) && '1' === (string) $_REQUEST['authorize_personal_data'];

		if ( ! $ack ) {
			wp_die(
				esc_html__( 'An export of submission contents requires the personal-data acknowledgement.', 'agentshell-forms' ),
				esc_html__( 'Submission access', 'agentshell-forms' ),
				array( 'response' => 400 )
			);
		}

		if ( ! preg_match( Form_Schema::FORM_ID_PATTERN, $form ) ) {
			wp_die( esc_html__( 'A form identifier is required.', 'agentshell-forms' ), 400 );
		}

		$payload = self::export_payload( $form, $format, get_current_user_id() );

		if ( is_wp_error( $payload ) ) {
			wp_die( esc_html( $payload->get_error_message() ), 400 );
		}

		nocache_headers();
		header( 'Content-Type: ' . $payload['content_type'] . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $payload['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $payload['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV/JSON built by the store, escaped for its format.
		exit;
	}

	/**
	 * Build an export without streaming it, so it can be tested.
	 *
	 * @param string $form   Form id.
	 * @param string $format `csv` or `json`.
	 * @param int    $actor  The actor, for the audit row.
	 * @return array{filename:string,content_type:string,body:string,count:int}|\WP_Error
	 */
	public static function export_payload( $form, $format = 'csv', $actor = 0 ) {
		// The screen's handler checks this too, and it is checked here as well:
		// this method is the thing that turns stored personal data into bytes,
		// and a helper that is safe only when its caller remembers to check is
		// one refactor away from an export nobody authorised.
		if ( ! Form_Capabilities::can_access_submissions( $actor > 0 ? (int) $actor : null ) ) {
			Form_Audit::record(
				'form_submission_access_refused',
				array(
					'action' => 'export',
					'reason' => 'missing_submissions_capability',
				),
				(int) $actor
			);

			return new \WP_Error(
				'form_export_forbidden',
				__( 'Exporting submissions requires access to form submissions.', 'agentshell-forms' )
			);
		}

		$post_id = Form_Definition::resolve_post_id( (string) $form );

		if ( $post_id < 1 ) {
			return new \WP_Error( 'form_export_unknown_form', __( 'That form does not exist.', 'agentshell-forms' ) );
		}

		$state = Form_Definition::get_by_post_id( $post_id );

		if ( is_wp_error( $state ) || ! is_array( $state['published'] ) ) {
			return new \WP_Error( 'form_export_unpublished', __( 'That form has no published definition to export against.', 'agentshell-forms' ) );
		}

		$table = Submission_Store::export_table( (string) $form, $state['published'] );

		if ( 'json' === $format ) {
			$body = Submission_Store::to_json( $table );
			$type = 'application/json';
			$ext  = 'json';
		} else {
			$body = Submission_Store::to_csv( $table );
			$type = 'text/csv';
			$ext  = 'csv';
		}

		Form_Audit::record(
			'form_submission_exported',
			array(
				'form_id'                 => (string) $form,
				'format'                  => (string) $format,
				'rows'                    => count( (array) $table['rows'] ),
				'authorize_personal_data' => true,
			),
			(int) $actor
		);

		return array(
			'filename'     => 'agentshell-form-' . substr( (string) $form, 0, 8 ) . '-' . gmdate( 'Ymd-His' ) . '.' . $ext,
			'content_type' => $type,
			'body'         => $body,
			'count'        => count( (array) $table['rows'] ),
		);
	}

	/**
	 * Trash, restore or permanently delete one submission.
	 *
	 * @return void
	 */
	public static function handle_submission_action() {
		if ( ! Form_Capabilities::can_access_submissions() ) {
			Form_Audit::record(
				'form_submission_access_refused',
				array(
					'action' => 'submission_action',
					'reason' => 'missing_submissions_capability',
				),
				get_current_user_id()
			);

			wp_die(
				esc_html__( 'Changing a submission requires access to form submissions.', 'agentshell-forms' ),
				esc_html__( 'Submission access', 'agentshell-forms' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'agentshell_forms_submission_action' );

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$action = isset( $_POST['submission_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['submission_action'] ) ) : '';
		$actor  = get_current_user_id();

		$record = Submission_Store::get( $id, false );

		if ( is_wp_error( $record ) ) {
			wp_die( esc_html( $record->get_error_message() ), 404 );
		}

		$result = null;

		switch ( $action ) {
			case 'trash':
				$result = Submission_Store::trash( $id, $actor );
				$flag   = 'trashed';
				break;

			case 'restore':
				$result = Submission_Store::restore( $id, $actor );
				$flag   = 'restored';
				break;

			case 'delete':
				$confirmed = isset( $_POST['confirm'] ) && '1' === (string) $_POST['confirm'];

				if ( ! $confirmed ) {
					wp_die(
						esc_html__( 'A permanent deletion requires an explicit confirmation.', 'agentshell-forms' ),
						esc_html__( 'Submission deletion', 'agentshell-forms' ),
						array( 'response' => 400 )
					);
				}

				$result = Submission_Store::delete_permanently( $id, $actor, true );
				$flag   = 'deleted';
				break;

			default:
				wp_die( esc_html__( 'Unknown submission action.', 'agentshell-forms' ), 400 );
		}

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 400 );
		}

		$url = add_query_arg(
			array(
				'page'                    => self::PAGE_SUBMISSIONS,
				'form'                    => rawurlencode( (string) $record['form_id'] ),
				'agentshell_forms_notice' => $flag,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The export form.
	 *
	 * @param string $form Form id.
	 * @return void
	 */
	private static function export_form( $form ) {
		echo '<h2>' . esc_html__( 'Export', 'agentshell-forms' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'agentshell_forms_export' );
		echo '<input type="hidden" name="action" value="agentshell_forms_export">';
		echo '<input type="hidden" name="form" value="' . esc_attr( $form ) . '">';

		echo '<p><label><input type="checkbox" name="authorize_personal_data" value="1"> '
			. esc_html__( 'I am exporting personal data for a lawful purpose, and I have recorded that decision.', 'agentshell-forms' )
			. '</label></p>';

		echo '<p><select name="format">'
			. '<option value="csv">' . esc_html__( 'CSV', 'agentshell-forms' ) . '</option>'
			. '<option value="json">' . esc_html__( 'JSON', 'agentshell-forms' ) . '</option>'
			. '</select> '
			. '<button type="submit" class="button">' . esc_html__( 'Export submissions', 'agentshell-forms' ) . '</button></p>';

		echo '</form>';
	}

	/**
	 * A trash/restore/delete button.
	 *
	 * @param int    $id      Submission id.
	 * @param string $action  `trash`, `restore` or `delete`.
	 * @param string $label   Button label.
	 * @param bool   $confirm Whether the button asks for confirmation.
	 * @return string
	 */
	private static function action_button( $id, $action, $label, $confirm ) {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		$html .= wp_nonce_field( 'agentshell_forms_submission_action', '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="agentshell_forms_submission_action">';
		$html .= '<input type="hidden" name="submission_action" value="' . esc_attr( $action ) . '">';
		$html .= '<input type="hidden" name="id" value="' . (int) $id . '">';

		if ( $confirm ) {
			$html .= '<input type="hidden" name="confirm" value="1">';
			$html .= '<button type="submit" class="button button-small button-link-delete"'
				. ' onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Delete this submission permanently? This cannot be undone.', 'agentshell-forms' ) ) ) . ');">'
				. esc_html( $label ) . '</button>';
		} else {
			$html .= '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
		}

		$html .= '</form>';

		return $html;
	}

	/**
	 * The form selector and status tabs.
	 *
	 * @param array  $forms  Forms.
	 * @param string $form   Selected id.
	 * @param string $status Status.
	 * @return void
	 */
	private static function form_picker( array $forms, $form, $status ) {
		echo '<p>';

		foreach ( $forms as $index => $item ) {
			$url = add_query_arg(
				array(
					'page'   => self::PAGE_SUBMISSIONS,
					'form'   => rawurlencode( (string) $item['form_id'] ),
					'status' => $status,
				),
				admin_url( 'admin.php' )
			);

			if ( $index > 0 ) {
				echo ' | ';
			}

			echo '<a href="' . esc_url( $url ) . '"' . ( $item['form_id'] === $form ? ' aria-current="page"' : '' ) . '>'
				. esc_html( $item['name'] ) . '</a>';
		}

		echo '</p><p>';

		$published = add_query_arg(
			array(
				'page'   => self::PAGE_SUBMISSIONS,
				'form'   => rawurlencode( $form ),
				'status' => 'publish',
			),
			admin_url( 'admin.php' )
		);

		$trash = add_query_arg(
			array(
				'page'   => self::PAGE_SUBMISSIONS,
				'form'   => rawurlencode( $form ),
				'status' => 'trash',
			),
			admin_url( 'admin.php' )
		);

		echo '<a class="button' . ( 'trash' === $status ? '' : ' button-primary' ) . '" href="' . esc_url( $published ) . '">'
			. esc_html__( 'Live', 'agentshell-forms' ) . '</a> ';
		echo '<a class="button' . ( 'trash' === $status ? ' button-primary' : '' ) . '" href="' . esc_url( $trash ) . '">'
			. esc_html__( 'Trash', 'agentshell-forms' ) . '</a>';

		echo '</p>';
	}

	/**
	 * The notices for a completed action.
	 *
	 * @return void
	 */
	private static function notices() {
		$notice = isset( $_GET['agentshell_forms_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['agentshell_forms_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$access = isset( $_GET['agentshell_forms_access'] ) ? sanitize_key( wp_unslash( (string) $_GET['agentshell_forms_access'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'trashed'  => __( 'The submission was moved to the trash.', 'agentshell-forms' ),
			'restored' => __( 'The submission was restored.', 'agentshell-forms' ),
			'deleted'  => __( 'The submission was permanently deleted.', 'agentshell-forms' ),
			'granted'  => __( 'Submission access was granted to your account.', 'agentshell-forms' ),
			'revoked'  => __( 'Submission access was revoked.', 'agentshell-forms' ),
		);

		$key = '' !== $notice ? $notice : $access;

		if ( '' !== $key && isset( $messages[ $key ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
		}

		$rejected = Form_Rejections::recent( 5 );

		if ( ! empty( $rejected ) ) {
			echo '<div class="notice notice-info"><p>'
				. esc_html__( 'Recent refused attempts (no submitted value is recorded):', 'agentshell-forms' )
				. '</p><ul>';

			foreach ( $rejected as $entry ) {
				echo '<li><code>'
					. esc_html( (string) ( $entry['reason'] ?? '' ) )
					. '</code> — ' . esc_html( (string) ( $entry['at'] ?? '' ) )
					. ( ! empty( $entry['form_id'] ) ? ' — ' . esc_html( (string) $entry['form_id'] ) : '' )
					. '</li>';
			}

			echo '</ul></div>';
		}
	}

	/**
	 * A label for a record's notification state.
	 *
	 * @param array $record Submission record.
	 * @return string
	 */
	private static function notification_label( array $record ) {
		$record_notification = isset( $record['notification'] ) && is_array( $record['notification'] ) ? $record['notification'] : null;

		if ( null === $record_notification ) {
			return __( '—', 'agentshell-forms' );
		}

		return self::describe_record( $record_notification );
	}

	/**
	 * Describe a notification record in the operator's words.
	 *
	 * `accepted` is deliberately the strongest word used: it means `wp_mail()`
	 * accepted the message, never that it was delivered.
	 *
	 * @param array $record Notification record.
	 * @return string
	 */
	private static function describe_record( array $record ) {
		$status   = isset( $record['status'] ) ? (string) $record['status'] : '';
		$attempts = isset( $record['attempts'] ) ? (int) $record['attempts'] : 0;

		$labels = array(
			'pending'   => __( 'pending', 'agentshell-forms' ),
			'claimed'   => __( 'being sent', 'agentshell-forms' ),
			'accepted'  => __( 'accepted by the mail transport (not a delivery receipt)', 'agentshell-forms' ),
			'failed'    => __( 'failed', 'agentshell-forms' ),
			'uncertain' => __( 'outcome unknown', 'agentshell-forms' ),
			'exhausted' => __( 'gave up after the final attempt', 'agentshell-forms' ),
		);

		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;

		$text = sprintf(
			/* translators: 1: status, 2: attempt count */
			__( '%1$s (%2$d attempts)', 'agentshell-forms' ),
			$label,
			$attempts
		);

		if ( ! empty( $record['last_error'] ) ) {
			$text .= ' — ' . (string) $record['last_error'];
		}

		return $text;
	}

	/**
	 * Every form, for the picker.
	 *
	 * @return array<int,array{form_id:string,name:string,post_id:int}>
	 */
	private static function forms() {
		$posts = get_posts(
			array(
				'post_type'      => Form_Post_Types::FORM,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$out = array();

		foreach ( (array) $posts as $post ) {
			$state = Form_Definition::get_by_post_id( (int) $post->ID );

			if ( is_wp_error( $state ) || '' === (string) $state['form_id'] ) {
				continue;
			}

			$out[] = array(
				'form_id' => (string) $state['form_id'],
				'name'    => (string) $post->post_title,
				'post_id' => (int) $post->ID,
			);
		}

		return $out;
	}

	/**
	 * The retention sentence for a form.
	 *
	 * @param array  $forms Forms.
	 * @param string $form  Selected id.
	 * @return string
	 */
	private static function retention_text( array $forms, $form ) {
		$days = Submission_Store::site_retention_days();

		foreach ( $forms as $item ) {
			if ( $item['form_id'] !== $form ) {
				continue;
			}

			$state = Form_Definition::get_by_post_id( (int) $item['post_id'] );

			if ( ! is_wp_error( $state ) && is_array( $state['published'] ) ) {
				$declared = isset( $state['published']['settings']['storage']['retain_days'] )
					? (int) $state['published']['settings']['storage']['retain_days']
					: 0;

				if ( $declared > 0 ) {
					$days = $declared;
				}
			}
		}

		return sprintf(
			/* translators: %d: number of days */
			__( 'Submissions of this form are deleted %d days after they arrive.', 'agentshell-forms' ),
			(int) $days
		);
	}

	/**
	 * A form's published definition, or an empty array.
	 *
	 * @param string $form_id Form id.
	 * @return array
	 */
	private static function definition_for( $form_id ) {
		$post_id = Form_Definition::resolve_post_id( (string) $form_id );

		if ( $post_id < 1 ) {
			return array();
		}

		$state = Form_Definition::get_by_post_id( $post_id );

		if ( is_wp_error( $state ) || ! is_array( $state['published'] ) ) {
			return array();
		}

		return $state['published'];
	}

	/**
	 * Field key => label, from a definition.
	 *
	 * @param array $definition Definition.
	 * @return array<string,string>
	 */
	private static function labels( array $definition ) {
		$out = array();

		foreach ( (array) ( $definition['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['key'] ) ) {
				$out[ (string) $field['key'] ] = isset( $field['label'] ) && '' !== (string) $field['label']
					? (string) $field['label']
					: (string) $field['key'];
			}
		}

		return $out;
	}

	/**
	 * A form's name, or its id.
	 *
	 * @param string $form_id Form id.
	 * @return string
	 */
	private static function form_label( $form_id ) {
		$post_id = Form_Definition::resolve_post_id( (string) $form_id );

		if ( $post_id < 1 ) {
			return (string) $form_id;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? (string) $post->post_title : (string) $form_id;
	}
}
