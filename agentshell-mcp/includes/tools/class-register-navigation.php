<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Build one `wp_update_nav_menu_item()` argument row from one input item.
 *
 * Pure data transformation; callable from tests without a live WP.
 *
 *   page_id  → menu-item-object-id + type=post_type
 *   url      → menu-item-url + object=custom + type=custom
 *   neither  → menu-item-url='#' + object=custom + type=custom (heading)
 *
 * @param array       $item    Input item with at least 'label'. May have
 *                             page_id, url.
 * @param string|null $object  Resolved object type for a page_id item (the
 *                             post type, e.g. 'page'). WordPress fills
 *                             `_menu_item_object` from this, and it is not
 *                             derivable without a database lookup, so the
 *                             caller resolves it and passes it in. Left unset
 *                             when null, which keeps this function pure and
 *                             callable from tests.
 * @return array              Row ready for wp_update_nav_menu_item().
 */
function agentshell_build_navigation_item_row( array $item, $object = null ) {
    if ( ! isset( $item['label'] ) || '' === trim( (string) $item['label'] ) ) {
        throw new \InvalidArgumentException( 'label is required for every navigation item' );
    }

    $row = array(
        'menu-item-title'  => (string) $item['label'],
        'menu-item-status' => 'publish',
    );

    if ( ! empty( $item['page_id'] ) ) {
        $row['menu-item-object-id'] = (int) $item['page_id'];
        // WordPress resolves the *title* from the object at insert time, but
        // `_menu_item_object` is written from this row — leave it unset here
        // and let the caller supply the resolved post type.
        $row['menu-item-type'] = 'post_type';
        if ( is_string( $object ) && '' !== $object ) {
            $row['menu-item-object'] = $object;
        }
    } elseif ( ! empty( $item['url'] ) ) {
        $row['menu-item-url']   = (string) $item['url'];
        $row['menu-item-object'] = 'custom';
        $row['menu-item-type']   = 'custom';
    } else {
        // Label-only heading.
        $row['menu-item-url']   = '#';
        $row['menu-item-object'] = 'custom';
        $row['menu-item-type']   = 'custom';
    }

    return $row;
}

/**
 * Validate and normalise a whole `items` list before anything is written.
 *
 * Every check that can reject the request lives here, so a bad item is
 * refused while the database is still untouched. Validating item-by-item at
 * write time is what allowed the previous implementation to create a menu
 * and then die half way through it.
 *
 * Labels are unique by rule, not by convention: string `parent` references
 * resolve by label, so two items sharing one would be ambiguous.
 *
 * @param array $items Raw input items.
 * @return array Normalised items, each carrying an integer
 *                `_agentshell_parent_index` (0 for "no parent").
 * @throws \InvalidArgumentException on the first invalid item.
 */
function agentshell_normalise_navigation_items( array $items ) {
    $normalised = array();
    $label_seen = array();

    foreach ( array_values( $items ) as $index => $item ) {
        if ( ! is_array( $item ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'items[%d] must be an object', $index )
            );
        }

        if ( ! isset( $item['label'] ) || '' === trim( (string) $item['label'] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'items[%d]: label is required for every navigation item', $index )
            );
        }
        $label = trim( (string) $item['label'] );

        if ( isset( $label_seen[ $label ] ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'items[%d]: duplicate label "%s" (already used by items[%d]); '
                    . 'labels must be unique because they resolve parent references',
                    $index,
                    $label,
                    $label_seen[ $label ]
                )
            );
        }
        $label_seen[ $label ] = $index;

        if ( isset( $item['page_id'] ) && '' !== $item['page_id']
            && ( ! is_numeric( $item['page_id'] ) || (int) $item['page_id'] <= 0 ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'items[%d]: page_id must be a positive integer', $index )
            );
        }
        if ( isset( $item['url'] ) && '' !== $item['url'] && ! is_string( $item['url'] ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'items[%d]: url must be a string', $index )
            );
        }

        $normalised[] = array(
            'label'   => $label,
            'url'     => isset( $item['url'] ) ? trim( (string) $item['url'] ) : '',
            'page_id' => ( isset( $item['page_id'] ) && '' !== $item['page_id'] )
                ? (int) $item['page_id']
                : 0,
            'parent'  => isset( $item['parent'] ) ? $item['parent'] : null,
        );
    }

    // Second pass: resolve string parents to indexes. A parent may only refer
    // to an *earlier* item, which is what lets the tool resolve every parent
    // in the same forward pass that inserts the items.
    //
    // null means "no parent", which is distinct from 0 meaning "parented under
    // the first item" — conflating the two silently nests a flat menu under
    // its own first entry.
    foreach ( $normalised as $index => &$item ) {
        $parent = $item['parent'];
        if ( null === $parent || '' === $parent ) {
            $item['_agentshell_parent_index'] = null;
            continue;
        }

        if ( is_int( $parent ) || ( is_string( $parent ) && ctype_digit( $parent ) ) ) {
            $item['_agentshell_parent_index'] = max( 0, (int) $parent );
            continue;
        }

        if ( ! is_string( $parent ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'items[%d]: parent must be an integer menu item id or a string label', $index )
            );
        }

        $parent_label = trim( $parent );
        $found        = null;
        foreach ( $normalised as $earlier => $candidate ) {
            if ( $earlier >= $index ) {
                break;
            }
            if ( $candidate['label'] === $parent_label ) {
                $found = $earlier;
                break;
            }
        }
        if ( null === $found ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'items[%d]: parent "%s" does not match the label of any earlier item',
                    $index,
                    $parent_label
                )
            );
        }
        $item['_agentshell_parent_index'] = $found;
    }
    unset( $item );

    return $normalised;
}

/**
 * Capture everything needed to put one menu item back the way it was.
 *
 * @param int $item_id Menu item post id.
 * @return array|null Snapshot, or null if the item could not be read.
 */
function agentshell_snapshot_nav_menu_item( $item_id ) {
    $post = get_post( $item_id );
    if ( ! $post ) {
        return null;
    }

    $meta = array();
    foreach ( array(
        '_menu_item_type',
        '_menu_item_menu_item_parent',
        '_menu_item_object_id',
        '_menu_item_object',
        '_menu_item_target',
        '_menu_item_url',
        '_menu_item_classes',
        '_menu_item_xfn',
    ) as $key ) {
        $meta[ $key ] = get_post_meta( $item_id, $key, true );
    }

    return array(
        'post' => array(
            'post_title'   => $post->post_title,
            'post_status'  => $post->post_status,
            'post_parent'  => $post->post_parent,
            'menu_order'   => $post->menu_order,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
        ),
        'meta' => $meta,
    );
}

/**
 * Restore a menu item captured by agentshell_snapshot_nav_menu_item().
 *
 * Handles both cases compensation can hit: an item this operation mutated in
 * place, which is updated back, and an item it has already hard-deleted,
 * which has to be recreated because there is no post left to update. Without
 * the recreate branch a surplus item deleted just before a failure would be
 * silently lost, since wp_update_nav_menu_item() rejects a dead id.
 *
 * @param int   $menu_id  Owning menu id.
 * @param int   $item_id  Menu item post id; 0 or a deleted id to recreate.
 * @param array $snapshot As returned by the snapshot helper.
 * @return bool True when the item exists afterwards.
 */
function agentshell_restore_nav_menu_item( $menu_id, $item_id, array $snapshot ) {
    $post = $snapshot['post'];
    $meta = $snapshot['meta'];

    $db_id = is_nav_menu_item( $item_id ) ? (int) $item_id : 0;

    $result = wp_update_nav_menu_item( $menu_id, $db_id, array(
        // WordPress reads a zero position as "append to the end", so an item
        // whose original position was 0 is restored to the first slot rather
        // than jumped to the bottom. max( 1, ... ) is the closest faithful
        // restore the API allows.
        'menu-item-position'    => max( 1, (int) $post['menu_order'] ),
        'menu-item-title'       => $post['post_title'],
        'menu-item-description' => $post['post_content'],
        'menu-item-attr-title'  => $post['post_excerpt'],
        'menu-item-status'      => 'publish' === $post['post_status'] ? 'publish' : 'draft',
        'menu-item-type'        => $meta['_menu_item_type'],
        'menu-item-object-id'   => (int) $meta['_menu_item_object_id'],
        'menu-item-object'      => $meta['_menu_item_object'],
        'menu-item-parent-id'   => $meta['_menu_item_menu_item_parent'],
        'menu-item-url'         => $meta['_menu_item_url'],
        'menu-item-target'      => $meta['_menu_item_target'],
        'menu-item-classes'     => is_array( $meta['_menu_item_classes'] )
            ? implode( ' ', $meta['_menu_item_classes'] )
            : (string) $meta['_menu_item_classes'],
        'menu-item-xfn'         => $meta['_menu_item_xfn'],
    ) );

    return ! is_wp_error( $result );
}

/**
 * agentshell_register_navigation — create or replace a WordPress navigation
 * menu and (optionally) assign it to a registered theme location.
 *
 * The tool is deliberately bounded: it manages one menu at a time,
 * identified by name, and reconciles that menu's items against the requested
 * list. It is the navigation primitive the agentshell-site-from-brief skill
 * expects; it is not a general-purpose menu administration API.
 *
 * Each item accepts either:
 *   - page_id: integer post id of an existing WordPress page or post.
 *             object=page/post as WordPress resolves it.
 *   - url:    absolute or relative URL for an external or custom link.
 *             object=custom.
 *   - neither: treated as a label-only heading (object=custom, url='#').
 *
 * parent: optional string label of an item that appears *earlier* in the
 *         items list, or an integer index. An integer menu_item id cannot be
 *         known before the item exists, so it is not accepted — that was the
 *         reason the old implementation needed a second pass.
 *
 * Item order is the order of the items list; each item is written with an
 * explicit position.
 *
 * On an existing menu, items are reconciled in place: existing items are
 * updated to match the request, further items are created, and any surplus
 * existing items are deleted — and only after every create and update has
 * succeeded. Item database ids are therefore stable across repeated runs
 * with the same inputs.
 *
 * TRANSACTIONS: this tool writes through to the database immediately. It is
 * NOT staged, NOT rolled back by agentshell_rollback_transaction, and NOT
 * captured by agentshell_preview_transaction. Its own compensation runs only
 * if the operation fails part-way. Transaction support for navigation is
 * deferred to the content-transaction work; see docs/PRODUCT-ISSUES.md
 * issue 3.
 */
class Register_Navigation extends Base_Tool {
    public function get_name() { return 'agentshell_register_navigation'; }
    public function get_description() { return 'Create or replace a WordPress navigation menu by name and (optionally) assign it to a registered theme location. Each item accepts either a page_id (existing WordPress page/post) or a url (external / custom link), or neither for a label-only heading. parent may be the label of an earlier item. Items are written in the given order with stable database ids across repeated runs. Writes take effect immediately; this tool is not transaction-staged.'; }
    public function get_input_schema() {
        return array(
            'type'       => 'object',
            'properties' => array(
                'name'     => array( 'type' => 'string', 'description' => 'Menu name. Used to find or create the menu.' ),
                'location' => array( 'type' => 'string', 'description' => 'Optional theme location slug (must be a registered nav_menu location).' ),
                'items'    => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'label'   => array( 'type' => 'string', 'description' => 'Menu item label. Must be unique within the menu.' ),
                            'url'     => array( 'type' => 'string', 'description' => 'Absolute or relative URL (custom / external). Ignored when page_id is set.' ),
                            'page_id' => array( 'type' => 'integer', 'description' => 'WordPress page or post id.' ),
                            'parent'  => array(
                                'description' => 'Optional parent — the label of an item that appears earlier in this list.',
                                'oneOf' => array(
                                    array( 'type' => 'string' ),
                                    array( 'type' => 'integer' ),
                                ),
                            ),
                        ),
                        'required'   => array( 'label' ),
                    ),
                ),
            ),
            'required'   => array( 'name', 'items' ),
        );
    }

    public function execute( array $arguments ) {
        if ( \AgentShell_MCP\Transaction_Manager::instance()->is_active() ) {
            throw new \InvalidArgumentException(
                'agentshell_register_navigation refuses inside a transaction. Navigation writes take effect '
                . 'immediately and are not transaction-staged; rollback will not undo them. The one content '
                . 'tool that may be called inside an open transaction is agentshell_create_draft. Either close '
                . 'the transaction (commit or rollback) and call agentshell_register_navigation outside, or '
                . 'call agentshell_create_draft if you want a new journal-aware draft.'
            );
        }
        $this->validate_required( $arguments, array( 'name', 'items' ) );

        $name     = trim( (string) $arguments['name'] );
        $items    = (array) $arguments['items'];
        $location = isset( $arguments['location'] ) ? trim( (string) $arguments['location'] ) : '';

        if ( '' === $name ) {
            throw new \InvalidArgumentException( 'name must not be empty' );
        }
        if ( empty( $items ) ) {
            throw new \InvalidArgumentException( 'items must be a non-empty array' );
        }

        // Validate the requested location is registered, if provided. Checked
        // before any write: a bad slug must not leave a half-built menu.
        if ( '' !== $location ) {
            $registered = array_keys( (array) get_registered_nav_menus() );
            if ( ! in_array( $location, $registered, true ) ) {
                throw new \InvalidArgumentException(
                    sprintf( 'Unknown nav_menu location "%s". Registered locations: %s', $location, implode( ', ', $registered ) )
                );
            }
        }

        // And validate every item, so an invalid item cannot leave a menu
        // behind either.
        $normalised = agentshell_normalise_navigation_items( $items );

        // Resolve page_id targets before touching the menu, so a bad id does
        // not either. This also yields the object type WordPress wants.
        foreach ( $normalised as $i => $item ) {
            if ( $item['page_id'] <= 0 ) {
                $normalised[ $i ]['object_type'] = '';
                continue;
            }
            $post = get_post( $item['page_id'] );
            if ( ! $post || 'publish' !== $post->post_status ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'items[%d]: page_id %d is not an existing published WordPress post or page',
                        $i,
                        $item['page_id']
                    )
                );
            }
            $normalised[ $i ]['object_type'] = $post->post_type;
        }

        // Snapshot the location map up front. wp_delete_nav_menu() zeroes a
        // location rather than restoring whatever was there before, so the
        // pre-operation value has to be captured by us to be put back.
        $locations_before = get_nav_menu_locations();

        $existing      = wp_get_nav_menu_object( $name );
        $created_menu  = false;
        $created_items = array();   // item ids this call inserted
        $restores      = array();   // [item_id => snapshot] for items mutated in place

        try {
            if ( $existing && ! empty( $existing->term_id ) ) {
                $menu_id = (int) $existing->term_id;
            } else {
                $menu_id = wp_create_nav_menu( $name );
                if ( is_wp_error( $menu_id ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'Could not create menu "%s": %s', $name, $menu_id->get_error_message() )
                    );
                }
                $menu_id      = (int) $menu_id;
                $created_menu = true;
            }

            // Reconcile against what is already in the menu.
            $current = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish,draft' ) );
            $current = is_array( $current ) ? array_values( $current ) : array();

            $item_ids = array();
            foreach ( $normalised as $index => $item ) {
                $row = agentshell_build_navigation_item_row(
                    array(
                        'label'   => $item['label'],
                        'url'     => $item['url'],
                        'page_id' => $item['page_id'],
                    ),
                    $item['object_type']
                );

                // Positions are 1-based and must never be 0: WordPress reads a
                // zero position as "append to the end", so asking for the
                // first slot with 0 silently moves an existing item to the
                // bottom of the menu. Verified against WordPress 7.1.3.
                $row['menu-item-position'] = $index + 1;

                $parent_index = $item['_agentshell_parent_index'];
                $row['menu-item-parent-id'] = ( null === $parent_index )
                    ? 0
                    : (int) ( $item_ids[ $parent_index ] ?? 0 );

                if ( isset( $current[ $index ] ) ) {
                    $db_id               = (int) $current[ $index ]->ID;
                    $restores[ $db_id ] = agentshell_snapshot_nav_menu_item( $db_id );
                    $result              = wp_update_nav_menu_item( $menu_id, $db_id, $row );
                } else {
                    $result = wp_update_nav_menu_item( $menu_id, 0, $row );
                    $db_id  = 0;
                }

                if ( is_wp_error( $result ) ) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'items[%d] ("%s"): %s',
                            $index,
                            $item['label'],
                            $result->get_error_message()
                        )
                    );
                }

                if ( $db_id ) {
                    // Existing item, updated in place.
                } else {
                    $db_id = (int) $result;
                    if ( $db_id ) {
                        $created_items[] = $db_id;
                    }
                }

                $item_ids[ $index ] = $db_id;
            }

            // Every requested item exists. Only now is it safe to drop the
            // surplus: deleting before this point could lose existing items
            // if a later item then failed.
            for ( $i = count( $normalised ); $i < count( $current ); $i++ ) {
                $surplus = (int) $current[ $i ]->ID;
                $restores[ $surplus ] = agentshell_snapshot_nav_menu_item( $surplus );
                wp_delete_post( $surplus, true );
            }

            // Assign the location last, once the menu structure is known good.
            if ( '' !== $location ) {
                $locations = $locations_before;
                // nav_menu_locations is keyed by *location slug*, not by menu
                // id. get_nav_menu_locations()'s docblock claims the
                // opposite, but has_nav_menu(), wp_delete_nav_menu(), the REST
                // menu-locations controller and wp-admin/nav-menus.php all
                // read it as location => menu id — and it is has_nav_menu()
                // that decides whether wp_nav_menu() renders the menu at all.
                // Writing it the documented-but-wrong way stores an assignment
                // that nothing will ever find, and the frontend quietly falls
                // back to the automatic page menu.
                unset( $locations[ $location ] );
                $locations[ $location ] = $menu_id;
                set_theme_mod( 'nav_menu_locations', $locations );
            }

            $reported = array();
            foreach ( $normalised as $index => $item ) {
                $reported[] = array(
                    'index'        => $index,
                    'menu_item_id' => (int) $item_ids[ $index ],
                    'label'        => $item['label'],
                    'type'         => $item['page_id'] > 0 ? 'post_type' : 'custom',
                    'object_id'    => $item['page_id'],
                    'parent_id'    => null === $item['_agentshell_parent_index']
                        ? 0
                        : (int) ( $item_ids[ $item['_agentshell_parent_index'] ] ?? 0 ),
                    'position'     => $index + 1,
                );
            }

            return array(
                'menu_id'       => $menu_id,
                'menu_name'     => $name,
                'location'      => $location,
                'item_count'    => count( $reported ),
                'items'         => $reported,
                'created'       => $created_menu,
                'staged'        => false,
                'transaction_note' => 'Navigation writes take effect immediately. '
                    . 'They are not staged and agentshell_rollback_transaction will not undo them.',
            );
        } catch ( \Throwable $e ) {
            $this->compensate(
                $menu_id,
                $created_menu,
                $created_items,
                $restores,
                $locations_before
            );
            throw $e;
        }
    }

    /**
     * Undo exactly what this operation created or changed.
     *
     * Scoped on purpose: only the menu term this call created, only the item
     * ids this call inserted, only the items whose prior state was captured,
     * and only the location map as it stood before the call.
     *
     * @param int   $menu_id         Menu id.
     * @param bool  $created_menu    Whether this call created the menu term.
     * @param array $created_items   Item ids inserted by this call.
     * @param array $restores        [item_id => snapshot] for items mutated.
     * @param array $locations_before Location map from before the call.
     */
    protected function compensate( $menu_id, $created_menu, array $created_items, array $restores, array $locations_before ) {
        foreach ( array_reverse( $created_items ) as $item_id ) {
            wp_delete_post( $item_id, true );
        }
        foreach ( $restores as $item_id => $snapshot ) {
            if ( is_array( $snapshot ) ) {
                agentshell_restore_nav_menu_item( $menu_id, $item_id, $snapshot );
            }
        }
        if ( $created_menu && $menu_id ) {
            // Also removes the item posts still attached to the menu, and
            // zeroes the location — which is why the location map is restored
            // after this rather than before.
            wp_delete_nav_menu( $menu_id );
        }
        set_theme_mod( 'nav_menu_locations', $locations_before );
    }
}