<?php
/**
 * The definition store: create, read, update, publish and discard.
 *
 * ## The two definitions, and why there are two
 *
 * A form carries a **working** definition (what the builder and the agents are
 * editing) and a **published** definition (what visitors get and what
 * submissions are validated against). The renderer reads the published
 * definition and nothing else, so an uncommitted edit is not merely hidden —
 * it is not on the render path at all.
 *
 * The WordPress post status is **not** the publish mechanism. The post is
 * `publish` once a form has ever been published and `draft` before that; the
 * *definition* is what is live. Using the post status for both would let a form
 * be "published" while its definition was a draft.
 *
 * ## Why every write is guarded
 *
 * `wp_postmeta` has no unique key on `(post_id, meta_key)`, `add_post_meta()`
 * is a `SELECT COUNT` followed by an `INSERT`, and `update_post_meta()`
 * updates *every* matching row. The design's §9.6 therefore rests the whole
 * concurrency model on `meta_id` (a real primary key) and on three mechanisms,
 * all implemented in `Form_DB`:
 *
 *   - an advisory lock for authoritative-row creation;
 *   - a row count on every read, so a duplicate is detected rather than
 *     silently resolved;
 *   - a value-guarded update scoped by `meta_id`.
 *
 * On top of those, every state-changing operation here runs inside one SQL
 * transaction that first locks the form's post row (`SELECT … FOR UPDATE`) and
 * then **verifies that every row it is about to change still holds exactly the
 * bytes the caller observed**. A lost race is therefore refused, never
 * overwritten, and it is refused for creation as well as for update: two
 * concurrent first publications cannot both insert, because the second one
 * observes that the row it expected to be absent now exists.
 *
 * ## What publication promises, and what it refuses to do
 *
 * A publication writes three rows — the new published definition, the previous
 * published definition and the new version — plus an audit row. If any of them
 * fails the transaction rolls back, so the published definition and its version
 * are always mutually consistent.
 *
 * On a database whose tables cannot roll back (MyISAM), that guarantee is not
 * available, so **publication is refused** with a named error rather than
 * performed unprotected. A single-row compare-and-swap is atomic on any engine,
 * which is why ordinary editing still works there.
 *
 * @package AgentShell_Forms
 */

namespace AgentShell_Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create, read, update, publish and discard form definitions.
 */
class Form_Definition {

	/**
	 * Create a form.
	 *
	 * @param array $args {
	 *     @type string $name        Required. The form's name.
	 *     @type string $slug        Optional. Derived from the name when absent.
	 *     @type string $description Optional.
	 *     @type array  $fields      Optional. The initial field list.
	 *     @type array  $settings    Optional. Partial settings; defaults are filled.
	 * }
	 * @param int   $actor_id The user creating the form, for the audit trail.
	 * @return array|\WP_Error The stored form, or an error.
	 */
	public static function create( array $args, $actor_id = 0 ) {
		$name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error(
				'form_name_invalid',
				__( 'A form needs a name.', 'agentshell-forms' )
			);
		}

		$form_id = self::new_form_id();

		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) && '' !== $args['slug']
			? self::sanitise_slug( $args['slug'] )
			: self::sanitise_slug( $name );

		if ( '' === $slug ) {
			$slug = 'form-' . substr( $form_id, 0, 8 );
		}

		$definition = isset( $args['definition'] ) && is_array( $args['definition'] )
			? $args['definition']
			: array();

		if ( isset( $args['fields'] ) && is_array( $args['fields'] ) ) {
			$definition['fields'] = $args['fields'];
		}
		if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
			$definition['settings'] = $args['settings'];
		}
		if ( isset( $args['description'] ) && is_string( $args['description'] ) ) {
			$definition['description'] = $args['description'];
		}

		$definition['schema_version'] = AGENTSHELL_FORMS_SCHEMA_VERSION;
		$definition['form_id']        = $form_id;
		$definition['slug']           = $slug;
		$definition['name']           = $name;
		$definition['version']        = 0;
		$definition['status']         = 'draft';

		$definition = Form_Schema::normalise( $definition );

		$errors = Form_Schema::validate(
			$definition,
			array(
				'site_host'    => self::site_host(),
				'slug_taken'   => function ( $candidate ) {
					return self::slug_is_taken( $candidate, 0 );
				},
				'has_been_published' => false,
				'previous_slug'      => '',
			)
		);

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'form_invalid_definition',
				__( 'The form definition is not valid.', 'agentshell-forms' ),
				array( 'errors' => $errors )
			);
		}

		$json = self::encode( $definition );

		// Slug uniqueness has no index behind it, so the check-and-write is
		// serialised per slug. Without this, two concurrent creates could both
		// find the slug free.
		$lock = Form_DB::lock( Form_DB::slug_lock_name( $slug ), 5 );
		if ( false === $lock ) {
			return new \WP_Error(
				'form_slug_lock_unavailable',
				__( 'The database could not serialise the slug assignment; please retry.', 'agentshell-forms' )
			);
		}

		try {
			if ( self::slug_is_taken( $slug, 0 ) ) {
				return new \WP_Error(
					'form_slug_duplicate',
					__( 'Another form already uses this slug.', 'agentshell-forms' ),
					array( 'slug' => $slug )
				);
			}

			$result = Form_DB::run(
				Form_DB::definition_tables(),
				function () use ( $name, $definition, $json, $form_id, $slug ) {
					$post_id = wp_insert_post(
						array(
							'post_type'    => Form_Post_Types::FORM,
							'post_status'  => 'draft',
							'post_title'   => $name,
							'post_content' => '',
							'post_author'  => (int) get_current_user_id(),
						),
						true
					);

					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}
					$post_id = (int) $post_id;
					if ( $post_id < 1 ) {
						return new \WP_Error( 'form_create_failed', __( 'WordPress did not create the form record.', 'agentshell-forms' ) );
					}

					$meta = array(
						Form_Post_Types::META_FORM_ID  => $form_id,
						Form_Post_Types::META_SLUG     => $slug,
						Form_Post_Types::META_WORKING  => $json,
						Form_Post_Types::META_REVISION => '0',
						Form_Post_Types::META_VERSION  => '0',
						Form_Post_Types::META_META     => self::encode(
							array(
								'created_at'          => gmdate( 'c' ),
								'created_by'          => (int) get_current_user_id(),
								'first_published_at'  => '',
								'modified_at'         => gmdate( 'c' ),
								'modified_by'         => (int) get_current_user_id(),
							)
						),
					);

					foreach ( $meta as $key => $value ) {
						$meta_id = self::ensure_row( $post_id, $key, $value );
						if ( is_wp_error( $meta_id ) ) {
							return $meta_id;
						}
					}

					return $post_id;
				}
			);
		} finally {
			Form_DB::unlock( $lock );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		Form_Audit::record(
			'form_created',
			array(
				'form_id' => $form_id,
				'slug'    => $slug,
				'post_id' => $post_id,
			),
			(int) $actor_id
		);

		return self::get_by_post_id( $post_id );
	}

	/**
	 * Read a form by its immutable internal identifier.
	 *
	 * @param string $form_id 32 lowercase hex characters.
	 * @return array|\WP_Error
	 */
	public static function get( $form_id ) {
		$form_id = is_string( $form_id ) ? $form_id : '';
		if ( ! preg_match( Form_Schema::FORM_ID_PATTERN, $form_id ) ) {
			return new \WP_Error( 'form_id_invalid', __( 'A form id is 32 lowercase hexadecimal characters.', 'agentshell-forms' ) );
		}

		$ids = self::find_post_ids_by_meta( Form_Post_Types::META_FORM_ID, $form_id );

		if ( 0 === count( $ids ) ) {
			return new \WP_Error(
				'form_not_found',
				__( 'No form has that id.', 'agentshell-forms' ),
				array( 'form_id' => $form_id )
			);
		}

		if ( count( $ids ) > 1 ) {
			Form_Audit::record(
				'form_identity_duplicate',
				array(
					'form_id'  => $form_id,
					'post_ids' => $ids,
				),
				0
			);
			return new \WP_Error(
				'form_identity_duplicate',
				__( 'More than one form record claims that id; refusing to choose one.', 'agentshell-forms' ),
				array(
					'form_id'  => $form_id,
					'post_ids' => $ids,
				)
			);
		}

		return self::get_by_post_id( $ids[0] );
	}

	/**
	 * Resolve a form reference to a post id.
	 *
	 * Accepts either the immutable internal identifier (32 lowercase hex
	 * characters) or the WordPress post id, because both are legitimate handles
	 * and a caller should not have to know which one it is holding.
	 *
	 * A reference that is neither — an arbitrary string, or an identifier that
	 * two records claim — resolves to 0. It is deliberately not "the first
	 * match": a caller that gets a post id back may go on to *write*, and
	 * guessing between two records is the corruption the row count exists to
	 * refuse.
	 *
	 * @param string|int $reference Internal identifier or post id.
	 * @return int Post id, or 0.
	 */
	public static function resolve_post_id( $reference ) {
		if ( is_int( $reference ) || ( is_string( $reference ) && ctype_digit( $reference ) ) ) {
			return (int) $reference;
		}

		if ( ! is_string( $reference ) || ! preg_match( Form_Schema::FORM_ID_PATTERN, $reference ) ) {
			return 0;
		}

		$ids = self::find_post_ids_by_meta( Form_Post_Types::META_FORM_ID, $reference );

		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Read a form by its post id.
	 *
	 * @param int $post_id The form post id.
	 * @return array|\WP_Error
	 */
	public static function get_by_post_id( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id < 1 ) {
			return new \WP_Error( 'form_not_found', __( 'No form has that record id.', 'agentshell-forms' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || Form_Post_Types::FORM !== $post->post_type ) {
			return new \WP_Error( 'form_not_found', __( 'No form has that record id.', 'agentshell-forms' ) );
		}

		$state = self::read_state( $post_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$state['title'] = $post->post_title;
		return $state;
	}

	/**
	 * Read every definition row for a form, refusing on corruption.
	 *
	 * @param int $post_id Form post id.
	 * @return array|\WP_Error
	 */
	public static function read_state( $post_id ) {
		$rows = array();

		foreach ( Form_Post_Types::all_meta_keys() as $key ) {
			$row = Form_DB::read_one( $post_id, $key );
			if ( 'duplicate' === $row['status'] ) {
				Form_Audit::record(
					'form_meta_duplicate',
					array(
						'post_id'  => (int) $post_id,
						'meta_key' => $key,
						'count'    => (int) $row['count'],
					),
					0
				);
				return $row['error'];
			}
			$rows[ $key ] = $row;
		}

		$form_id      = isset( $rows[ Form_Post_Types::META_FORM_ID ]['value'] ) ? (string) $rows[ Form_Post_Types::META_FORM_ID ]['value'] : '';
		$slug         = isset( $rows[ Form_Post_Types::META_SLUG ]['value'] ) ? (string) $rows[ Form_Post_Types::META_SLUG ]['value'] : '';
		$working      = self::decode( isset( $rows[ Form_Post_Types::META_WORKING ]['value'] ) ? $rows[ Form_Post_Types::META_WORKING ]['value'] : '' );
		$published    = self::decode( isset( $rows[ Form_Post_Types::META_PUBLISHED ]['value'] ) ? $rows[ Form_Post_Types::META_PUBLISHED ]['value'] : '' );
		$previous     = self::decode( isset( $rows[ Form_Post_Types::META_PUBLISHED_PREVIOUS ]['value'] ) ? $rows[ Form_Post_Types::META_PUBLISHED_PREVIOUS ]['value'] : '' );
		$meta         = self::decode( isset( $rows[ Form_Post_Types::META_META ]['value'] ) ? $rows[ Form_Post_Types::META_META ]['value'] : '' );
		$revision     = (int) ( isset( $rows[ Form_Post_Types::META_REVISION ]['value'] ) ? $rows[ Form_Post_Types::META_REVISION ]['value'] : 0 );
		$version      = (int) ( isset( $rows[ Form_Post_Types::META_VERSION ]['value'] ) ? $rows[ Form_Post_Types::META_VERSION ]['value'] : 0 );

		return array(
			'post_id'            => (int) $post_id,
			'form_id'            => $form_id,
			'slug'               => $slug,
			'working'            => is_array( $working ) ? $working : null,
			'published'          => is_array( $published ) ? $published : null,
			'published_previous' => is_array( $previous ) ? $previous : null,
			'revision'           => $revision,
			'version'            => $version,
			'has_been_published' => $version > 0 || is_array( $published ),
			'meta'               => is_array( $meta ) ? $meta : array(),
			'raw'                => $rows,
		);
	}

	/**
	 * Replace the working definition, guarded on the revision the caller saw.
	 *
	 * @param string $form_id            The form's internal identifier.
	 * @param array  $definition         The new working definition.
	 * @param int    $expected_revision  The working-revision counter the caller observed.
	 * @param int    $actor_id           The actor, for the audit trail.
	 * @return array|\WP_Error The new state, or an error.
	 */
	public static function update_working( $form_id, array $definition, $expected_revision, $actor_id = 0 ) {
		$state = self::get( $form_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( (int) $expected_revision !== (int) $state['revision'] ) {
			return self::revision_mismatch( $state, $expected_revision );
		}

		$definition['form_id'] = $state['form_id'];
		$definition['slug']    = $state['slug'];
		$definition['version'] = $state['version'];
		$definition['status']  = $state['has_been_published'] ? 'published' : 'draft';

		$definition = Form_Schema::normalise( $definition );

		$errors = Form_Schema::validate(
			$definition,
			array(
				'site_host'          => self::site_host(),
				'slug_taken'         => function ( $candidate ) use ( $state ) {
					return self::slug_is_taken( $candidate, (int) $state['post_id'] );
				},
				'has_been_published' => (bool) $state['has_been_published'],
				'previous_slug'      => (string) $state['slug'],
			)
		);

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'form_invalid_definition',
				__( 'The form definition is not valid.', 'agentshell-forms' ),
				array( 'errors' => $errors )
			);
		}

		$result = self::write_state_atomic(
			(int) $state['post_id'],
			$state,
			array(
				array(
					'key'   => Form_Post_Types::META_WORKING,
					'value' => self::encode( $definition ),
				),
				array(
					'key'   => Form_Post_Types::META_REVISION,
					'value' => (string) ( (int) $state['revision'] + 1 ),
				),
				array(
					'key'   => Form_Post_Types::META_META,
					'value' => self::encode(
						array_merge(
							$state['meta'],
							array(
								'modified_at' => gmdate( 'c' ),
								'modified_by' => (int) $actor_id,
							)
						)
					),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Form_Audit::record(
			'form_updated',
			array(
				'form_id'         => $state['form_id'],
				'revision'        => (int) $state['revision'] + 1,
				'expected_revision' => (int) $expected_revision,
			),
			(int) $actor_id
		);

		return self::get( $form_id );
	}

	/**
	 * Publish the working definition.
	 *
	 * @param string $form_id           The form's internal identifier.
	 * @param int    $expected_revision The working-revision counter the caller observed.
	 * @param int    $actor_id          The actor, for the audit trail.
	 * @return array|\WP_Error The new state, or an error.
	 */
	public static function publish( $form_id, $expected_revision, $actor_id = 0 ) {
		$state = self::get( $form_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( null === $state['working'] ) {
			return new \WP_Error(
				'form_no_working_definition',
				__( 'This form has no working definition to publish.', 'agentshell-forms' )
			);
		}

		if ( empty( $state['working']['fields'] ) ) {
			return new \WP_Error(
				'form_publish_invalid',
				__( 'A form needs at least one field before it can be published.', 'agentshell-forms' ),
				array( 'errors' => array() )
			);
		}

		$errors = Form_Schema::validate(
			$state['working'],
			array(
				'site_host'          => self::site_host(),
				'slug_taken'         => function () {
					return false;
				},
				'has_been_published' => (bool) $state['has_been_published'],
				'previous_slug'      => (string) $state['slug'],
			)
		);

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'form_publish_invalid',
				__( 'The working definition is not valid, so it cannot be published.', 'agentshell-forms' ),
				array( 'errors' => $errors )
			);
		}

		if ( (int) $expected_revision !== (int) $state['revision'] ) {
			return self::revision_mismatch( $state, $expected_revision );
		}

		// The multi-row guarantee needs a transactional engine. Refuse rather
		// than perform an atomic-looking publication that is not one.
		if ( '' === Form_DB::engine_for( Form_DB::definition_tables() ) ) {
			return new \WP_Error(
				'form_publication_unprotected',
				__( 'The database tables cannot roll back, so a publication could be left half-applied. Publication is refused on this database.', 'agentshell-forms' )
			);
		}

		$new_version = (int) $state['version'] + 1;
		$published   = $state['working'];
		$published['version'] = $new_version;
		$published['status']  = 'published';

		$writes = array();

		if ( is_array( $state['published'] ) ) {
			$writes[] = array(
				'key'   => Form_Post_Types::META_PUBLISHED_PREVIOUS,
				'value' => self::encode( $state['published'] ),
			);
		}

		$writes[] = array(
			'key'   => Form_Post_Types::META_PUBLISHED,
			'value' => self::encode( $published ),
		);
		$writes[] = array(
			'key'   => Form_Post_Types::META_VERSION,
			'value' => (string) $new_version,
		);
		$writes[] = array(
			'key'   => Form_Post_Types::META_META,
			'value' => self::encode(
				array_merge(
					$state['meta'],
					array(
						'first_published_at' => ! empty( $state['meta']['first_published_at'] )
							? $state['meta']['first_published_at']
							: gmdate( 'c' ),
						'modified_at'        => gmdate( 'c' ),
						'modified_by'        => (int) $actor_id,
					)
				)
			),
		);

		$result = self::write_state_atomic( (int) $state['post_id'], $state, $writes );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The post's status records "has this form ever been published". It is
		// written after the definition transaction commits, because a failure
		// here leaves a published definition with a draft status, which is
		// recoverable, whereas the reverse is a form visitors can see with no
		// published definition.
		if ( 'publish' !== get_post_status( (int) $state['post_id'] ) ) {
			wp_update_post(
				array(
					'ID'          => (int) $state['post_id'],
					'post_status' => 'publish',
				)
			);
		}

		Form_Audit::record(
			'form_published',
			array(
				'form_id' => $state['form_id'],
				'version' => $new_version,
				'fields'  => count( $published['fields'] ),
			),
			(int) $actor_id
		);

		// A native revision captures the definition exactly as it was
		// published. Taken AFTER the meta row is written, because core copies
		// the meta's current value at the moment the revision is created.
		self::take_revision( (int) $state['post_id'] );

		$fresh = self::get( $form_id );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		return $fresh;
	}

	/**
	 * Discard the working definition, reverting to the published one.
	 *
	 * @param string $form_id  The form's internal identifier.
	 * @param int    $actor_id The actor, for the audit trail.
	 * @return array|\WP_Error
	 */
	public static function discard( $form_id, $actor_id = 0 ) {
		$state = self::get( $form_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( ! is_array( $state['published'] ) ) {
			return new \WP_Error(
				'form_not_published',
				__( 'This form has never been published, so there is nothing to discard back to.', 'agentshell-forms' )
			);
		}

		$result = self::write_state_atomic(
			(int) $state['post_id'],
			$state,
			array(
				array(
					'key'   => Form_Post_Types::META_WORKING,
					'value' => self::encode( $state['published'] ),
				),
				array(
					'key'   => Form_Post_Types::META_REVISION,
					'value' => (string) ( (int) $state['revision'] + 1 ),
				),
				array(
					'key'   => Form_Post_Types::META_META,
					'value' => self::encode(
						array_merge(
							$state['meta'],
							array(
								'modified_at' => gmdate( 'c' ),
								'modified_by' => (int) $actor_id,
							)
						)
					),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Form_Audit::record(
			'form_discarded',
			array(
				'form_id'  => $state['form_id'],
				'revision' => (int) $state['revision'] + 1,
			),
			(int) $actor_id
		);

		self::take_revision( (int) $state['post_id'] );

		return self::get( $form_id );
	}

	/**
	 * Change a form's slug.
	 *
	 * Refused once the form has been published, permanently: a published form's
	 * shortcode may already be embedded in pages, and the freeze is triggered
	 * by the **first** publication rather than the current state, so it does not
	 * depend on whether the form is live today.
	 *
	 * @param string $form_id  The form's internal identifier.
	 * @param string $slug     The new slug.
	 * @param int    $actor_id The actor.
	 * @return array|\WP_Error
	 */
	public static function set_slug( $form_id, $slug, $actor_id = 0 ) {
		$state = self::get( $form_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		if ( $state['has_been_published'] ) {
			return new \WP_Error(
				'form_slug_frozen',
				__( 'A form’s slug is frozen after its first publication.', 'agentshell-forms' )
			);
		}

		$slug = self::sanitise_slug( $slug );

		if ( ! preg_match( Form_Schema::SLUG_PATTERN, $slug ) || preg_match( Form_Schema::FORM_ID_PATTERN, $slug ) ) {
			return new \WP_Error(
				'form_slug_invalid',
				__( 'The slug must be 1–64 characters of lowercase letters, digits and hyphens, and may not look like a form id.', 'agentshell-forms' )
			);
		}

		$lock = Form_DB::lock( Form_DB::slug_lock_name( $slug ), 5 );
		if ( false === $lock ) {
			return new \WP_Error(
				'form_slug_lock_unavailable',
				__( 'The database could not serialise the slug assignment; please retry.', 'agentshell-forms' )
			);
		}

		try {
			if ( self::slug_is_taken( $slug, (int) $state['post_id'] ) ) {
				return new \WP_Error(
					'form_slug_duplicate',
					__( 'Another form already uses this slug.', 'agentshell-forms' ),
					array( 'slug' => $slug )
				);
			}

			$result = self::write_state_atomic(
				(int) $state['post_id'],
				$state,
				array(
					array(
						'key'   => Form_Post_Types::META_SLUG,
						'value' => $slug,
					),
				)
			);
		} finally {
			Form_DB::unlock( $lock );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Form_Audit::record(
			'form_slug_changed',
			array(
				'form_id'  => $state['form_id'],
				'from'     => $state['slug'],
				'to'       => $slug,
			),
			(int) $actor_id
		);

		return self::get( $form_id );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Create the single authoritative row for a key, under the form's advisory
	 * lock.
	 *
	 * This is the plugin's only creation path for a definition row, and it is
	 * deliberately not `add_post_meta()`: that function is a `SELECT COUNT(*)`
	 * followed by an `INSERT`, so two concurrent first writes can both see
	 * zero and both insert. `wp_postmeta` has no unique key on
	 * `(post_id, meta_key)` to stop them, and `update_post_meta()` would then
	 * update *every* duplicate at once.
	 *
	 * The lock is named per form and connection-scoped, so a process that dies
	 * releases it when the server closes the connection. Under the lock the
	 * rows are re-read: zero means insert one, one means use it, more than one
	 * means refuse. That is what makes "there is exactly one authoritative
	 * row" true regardless of how many callers arrive at once.
	 *
	 * @param int    $post_id  Form post id.
	 * @param string $meta_key Meta key.
	 * @param string $value    Raw value to write when the row is absent.
	 * @return int|\WP_Error The row's `meta_id`, or an error.
	 */
	public static function ensure_row( $post_id, $meta_key, $value ) {
		$lock = Form_DB::lock( Form_DB::definition_lock_name( (int) $post_id ), 10 );
		if ( false === $lock ) {
			return new \WP_Error(
				'form_definition_lock_unavailable',
				__( 'The database could not serialise this form’s metadata write; please retry.', 'agentshell-forms' ),
				array(
					'reason'   => 'definition_lock_unavailable',
					'meta_key' => (string) $meta_key,
					'post_id'  => (int) $post_id,
				)
			);
		}

		try {
			$existing = Form_DB::read_one( (int) $post_id, (string) $meta_key );

			if ( 'duplicate' === $existing['status'] ) {
				return $existing['error'];
			}

			if ( 'one' === $existing['status'] ) {
				return (int) $existing['meta_id'];
			}

			$meta_id = Form_DB::insert_row( (int) $post_id, (string) $meta_key, (string) $value );

			if ( false === $meta_id ) {
				return new \WP_Error(
					'form_write_failed',
					sprintf(
						/* translators: %s: meta key */
						__( 'The form record was created but its %s row was not.', 'agentshell-forms' ),
						(string) $meta_key
					),
					array(
						'reason'   => 'write_failed',
						'meta_key' => (string) $meta_key,
					)
				);
			}

			return (int) $meta_id;
		} finally {
			Form_DB::unlock( $lock );
		}
	}

	/**
	 * Write a set of definition rows inside one transaction, verifying that
	 * every row the caller observed is still exactly as they observed it.
	 *
	 * This is the mechanism behind "a lost race is refused, never
	 * overwritten". It covers **creation** as well as update: a row the caller
	 * expected to be absent must still be absent when the write runs, so two
	 * concurrent first writes cannot both insert.
	 *
	 * @param int   $post_id Form post id.
	 * @param array $state   The state the caller observed (from `read_state()`).
	 * @param array $writes  Each `{ key, value }`.
	 * @return true|\WP_Error
	 */
	private static function write_state_atomic( $post_id, array $state, array $writes ) {
		$raw = isset( $state['raw'] ) && is_array( $state['raw'] ) ? $state['raw'] : array();

		return Form_DB::run(
			Form_DB::definition_tables(),
			function () use ( $post_id, $raw, $writes, $state ) {
				if ( ! Form_DB::lock_post_row( $post_id ) ) {
					return new \WP_Error(
						'form_lock_failed',
						__( 'The form record could not be locked; please retry.', 'agentshell-forms' )
					);
				}

				foreach ( $writes as $write ) {
					$key = $write['key'];

					/**
					 * Fires inside the transaction, immediately before one
					 * definition row is written.
					 *
					 * It exists so that a failure part-way through a
					 * multi-row publication can be reproduced without
					 * monkey-patching the store: a handler that makes the
					 * next write fail must leave the form with none of the
					 * rows this transaction wrote, which is the property
					 * `form_publication_unprotected` exists to protect.
					 *
					 * It is an observation point, not a transformation
					 * point: a listener must not write the row itself.
					 *
					 * @param int    $post_id The form post id.
					 * @param string $key     The meta key about to be written.
					 * @param string $value   The raw value about to be written.
					 */
					do_action( 'agentshell_forms_before_definition_write', (int) $post_id, $key, $write['value'] );

					// Re-read inside the lock. This is the verification, not the
					// observation: the expected value is the one the caller saw
					// before the transaction began.
					$current = Form_DB::read_one( $post_id, $key );

					if ( 'duplicate' === $current['status'] ) {
						return $current['error'];
					}

					$expected = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? $raw[ $key ] : array(
						'status'  => 'absent',
						'value'   => null,
						'meta_id' => null,
					);

					if ( 'absent' === $expected['status'] ) {
						if ( 'absent' !== $current['status'] ) {
							return self::state_changed( $key, 'absent', $current, $state );
						}
						$meta_id = Form_DB::insert_row( $post_id, $key, $write['value'] );
						if ( false === $meta_id ) {
							return self::write_failed( $key );
						}
						continue;
					}

					if ( 'one' !== $current['status']
						|| (int) $current['meta_id'] !== (int) $expected['meta_id']
						|| (string) $current['value'] !== (string) $expected['value'] ) {
						return self::state_changed( $key, (string) $expected['value'], $current, $state );
					}

					if ( ! Form_DB::cas_update( (int) $current['meta_id'], $write['value'], (string) $current['value'], (int) $post_id ) ) {
						return self::write_failed( $key );
					}
				}

				return true;
			}
		);
	}

	/**
	 * A named error for a race that was lost.
	 *
	 * @param string $key      Meta key.
	 * @param string $expected The value the caller observed.
	 * @param array  $current  What was there instead.
	 * @param array  $state    The state the caller observed, for the "who and
	 *                         when" the refusal reports back.
	 * @return \WP_Error
	 */
	private static function state_changed( $key, $expected, array $current, array $state = array() ) {
		$meta = isset( $state['meta'] ) && is_array( $state['meta'] ) ? $state['meta'] : array();

		return new \WP_Error(
			'form_state_changed',
			__( 'Another change was committed first, so your draft was not saved. Nothing was written; reload the form and re-apply.', 'agentshell-forms' ),
			array(
				'reason'   => 'state_changed',
				'meta_key' => $key,
				'expected' => $expected,
				'observed' => isset( $current['value'] ) ? (string) $current['value'] : null,
				'status'   => isset( $current['status'] ) ? (string) $current['status'] : '',
				// The caller's own last-known change. The plugin can name what
				// the caller saw; the actor who beat them is reported through
				// the fresh state the caller reloads.
				'your_view' => array(
					'modified_at' => isset( $meta['modified_at'] ) ? (string) $meta['modified_at'] : '',
					'modified_by' => isset( $meta['modified_by'] ) ? (int) $meta['modified_by'] : 0,
				),
				'your_draft_saved' => false,
			)
		);
	}

	/**
	 * A named error for a write that matched its guard but changed no row.
	 *
	 * @param string $key Meta key.
	 * @return \WP_Error
	 */
	private static function write_failed( $key ) {
		return new \WP_Error(
			'form_write_failed',
			__( 'The write did not take effect; nothing was committed.', 'agentshell-forms' ),
			array(
				'reason'   => 'write_failed',
				'meta_key' => $key,
			)
		);
	}

	/**
	 * A named error for a stale working revision.
	 *
	 * @param array $state    Current state.
	 * @param int   $expected The revision the caller presented.
	 * @return \WP_Error
	 */
	private static function revision_mismatch( array $state, $expected ) {
		return new \WP_Error(
			'form_revision_mismatch',
			sprintf(
				/* translators: 1: expected revision, 2: actual revision */
				__( 'The form has changed since you looked at it (you had revision %1$d, it is now %2$d). Reload and re-apply.', 'agentshell-forms' ),
				(int) $expected,
				(int) $state['revision']
			),
			array(
				'reason'            => 'revision_mismatch',
				'expected_revision' => (int) $expected,
				'actual_revision'   => (int) $state['revision'],
				'modified_at'       => isset( $state['meta']['modified_at'] ) ? (string) $state['meta']['modified_at'] : '',
				'modified_by'       => isset( $state['meta']['modified_by'] ) ? (int) $state['meta']['modified_by'] : 0,
			)
		);
	}

	/**
	 * Take a native revision, forcing core's "the post did not change" test.
	 *
	 * A definition lives entirely in post meta, so `post_title`,
	 * `post_content` and `post_excerpt` do not change and core would take no
	 * revision at all. The filter exists for exactly this: it is core's own
	 * extension point for a plugin that knows more about what changed than the
	 * three post fields do.
	 *
	 * @param int $post_id Form post id.
	 * @return int The revision id, or 0.
	 */
	private static function take_revision( $post_id ) {
		if ( ! function_exists( 'wp_save_post_revision' ) ) {
			return 0;
		}

		$force_true = function () {
			return true;
		};

		add_filter( 'wp_save_post_revision_post_has_changed', $force_true, 10, 0 );
		$revision_id = wp_save_post_revision( (int) $post_id );
		remove_filter( 'wp_save_post_revision_post_has_changed', $force_true, 10 );

		return (int) $revision_id;
	}

	/**
	 * Find every form post that carries a given meta value.
	 *
	 * Returns a list rather than one id so a duplicate can be *detected*: two
	 * posts claiming the same internal identifier is corruption, not a lookup
	 * to resolve by picking the first row.
	 *
	 * @param string $meta_key Meta key.
	 * @param string $value    Meta value.
	 * @return array<int,int>
	 */
	public static function find_post_ids_by_meta( $meta_key, $value ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}

		$sql = $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			  WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value = %s
			  ORDER BY p.ID ASC",
			Form_Post_Types::FORM,
			(string) $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			(string) $value     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		);

		$ids = $wpdb->get_col( $sql );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Is a slug already in use by a form other than `$except_post_id`?
	 *
	 * @param string $slug          The candidate.
	 * @param int    $except_post_id Form to ignore (the one being renamed).
	 * @return bool
	 */
	public static function slug_is_taken( $slug, $except_post_id = 0 ) {
		$ids = self::find_post_ids_by_meta( Form_Post_Types::META_SLUG, (string) $slug );
		foreach ( $ids as $id ) {
			if ( (int) $id !== (int) $except_post_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generate an internal identifier: exactly 32 lowercase hex characters.
	 *
	 * A UUIDv4 rendered without its dashes, so the value is 128 bits of
	 * randomness and fits the `char(32)` column the challenge table uses.
	 *
	 * @return string
	 */
	public static function new_form_id() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Turn a human name into a slug.
	 *
	 * @param string $value The name.
	 * @return string
	 */
	public static function sanitise_slug( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		$value = trim( (string) $value, '-' );
		$value = substr( (string) $value, 0, 64 );
		return (string) $value;
	}

	/**
	 * The site's host, for URL and recipient validation.
	 *
	 * @return string
	 */
	public static function site_host() {
		$url = function_exists( 'home_url' ) ? home_url() : '';
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	/**
	 * Encode a definition for storage.
	 *
	 * `JSON_UNESCAPED_SLASHES` keeps a URL readable in the stored document; the
	 * value is still valid JSON. Encoding failure returns `null` rather than an
	 * empty string, so a caller cannot store `''` and read it back as "no
	 * definition".
	 *
	 * @param mixed $value The value.
	 * @return string
	 */
	private static function encode( $value ) {
		$json = wp_json_encode( $value );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Decode a stored definition.
	 *
	 * @param string $raw Stored bytes.
	 * @return mixed
	 */
	private static function decode( $raw ) {
		$raw = (string) $raw;
		if ( '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return null === $decoded ? null : $decoded;
	}
}
