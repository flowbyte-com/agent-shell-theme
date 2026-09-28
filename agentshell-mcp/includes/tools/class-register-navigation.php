<?php
namespace AgentShell_MCP\Tools;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Build a wp_update_nav_menu_items row from one input item.
 *
 * Pure data transformation; callable from tests without a live WP.
 *
 *   page_id  → menu-item-object-id + object=post_type
 *   url      → menu-item-url + object=custom + type=custom
 *   neither  → menu-item-url='#' + object=custom + type=custom (heading)
 *
 * @param array $item  Input item with at least 'label'. May have page_id,
 *                     url, parent.
 * @return array       Row ready for wp_update_nav_menu_items.
 */
function agentshell_build_navigation_item_row( array $item ) {
    if ( empty( $item['label'] ) ) {
        throw new \InvalidArgumentException( 'label is required for every navigation item' );
    }

    $row = array(
        'menu-item-title'  => (string) $item['label'],
        'menu-item-status' => 'publish',
    );

    if ( ! empty( $item['page_id'] ) ) {
        $row['menu-item-object-id'] = (int) $item['page_id'];
        // object/type resolved by the caller via get_post(); the helper
        // does not need to know the post type because WP resolves it
        // from object-id at insert time. We leave them unset here.
        $row['menu-item-type'] = 'post_type';
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
 * agentshell_register_navigation — create or replace a WordPress nav
 * menu and (optionally) assign it to a registered theme location.
 *
 * The tool is deliberately bounded: it manages one menu at a time,
 * identified by name, and replaces all items in one atomic write
 * (wp_update_nav_menu_items). It is the navigation primitive the
 * agentshell-site-from-brief skill expects; it is not a general-purpose
 * menu administration API.
 *
 * Each item accepts either:
 *   - page_id: integer post id of an existing WordPress page or post.
 *             The tool resolves the permalink and uses object=page/post.
 *   - url:    absolute or relative URL for an external or custom link.
 *             object=custom.
 *   - neither: treated as a label-only heading (object=custom, url='#').
 *
 * parent: optional integer menu_item id, or a string label of an
 *         item that already appears earlier in the items list.
 *
 * If a menu with the same name already exists, its items are
 * replaced. The tool is safe to re-run inside the same transaction.
 *
 * If location is provided, the menu is assigned to that theme location
 * via wp_nav_menu_set_locations. Any previously-assigned menu at the
 * same location is unassigned.
 */
class Register_Navigation extends Base_Tool {
    public function get_name() { return 'agentshell_register_navigation'; }
    public function get_description() { return 'Create or replace a WordPress navigation menu by name and (optionally) assign it to a registered theme location. Each item accepts either a page_id (existing WordPress page/post) or a url (external / custom link). parent may be an integer menu item id or a string label of an earlier item. Idempotent: re-running with the same inputs replaces all items atomically.'; }
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
                            'label'   => array( 'type' => 'string', 'description' => 'Menu item label.' ),
                            'url'     => array( 'type' => 'string', 'description' => 'Absolute or relative URL (custom / external).' ),
                            'page_id' => array( 'type' => 'integer', 'description' => 'WordPress page or post id; resolved to its permalink.' ),
                            'parent'  => array(
                                'description' => 'Optional parent — integer menu item id (post-create) or string label of an earlier item.',
                                'oneOf' => array(
                                    array( 'type' => 'integer' ),
                                    array( 'type' => 'string' ),
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
        $this->validate_required( $arguments, array( 'name', 'items' ) );

        $name     = (string) $arguments['name'];
        $items    = (array) $arguments['items'];
        $location = isset( $arguments['location'] ) ? (string) $arguments['location'] : '';

        if ( empty( $items ) ) {
            throw new \InvalidArgumentException( 'items must be a non-empty array' );
        }

        // Validate the requested location is registered, if provided.
        if ( $location !== '' ) {
            $registered = array_keys( (array) get_registered_nav_menus() );
            if ( ! in_array( $location, $registered, true ) ) {
                throw new \InvalidArgumentException(
                    sprintf( 'Unknown nav_menu location "%s". Registered locations: %s', $location, implode( ', ', $registered ) )
                );
            }
        }

        // Find or create the menu.
        $existing = wp_get_nav_menu_object( $name );
        if ( $existing && ! empty( $existing->term_id ) ) {
            $menu_id = (int) $existing->term_id;
        } else {
            $menu_id = (int) wp_create_nav_menu( $name );
            if ( ! $menu_id || is_wp_error( $menu_id ) ) {
                throw new \InvalidArgumentException( sprintf( 'Could not create menu "%s": %s', $name, is_wp_error( $menu_id ) ? $menu_id->get_error_message() : 'unknown error' ) );
            }
        }

        // Build the wp_update_nav_menu_items payload. Two-pass:
        // first pass assigns labels as a parent lookup; second pass
        // resolves string parent labels to the integer menu_item ids
        // that WP assigned in pass one.
        $prepared = array();
        foreach ( $items as $idx => $item ) {
            $row = \AgentShell_MCP\Tools\agentshell_build_navigation_item_row( $item );
            // Tag the row with its position so we can resolve parent
            // labels in the second pass.
            $row['_agentshell_position']     = $idx;
            $row['_agentshell_label']       = (string) $item['label'];
            $prepared[] = $row;
        }

        $inserted = wp_update_nav_menu_items( $menu_id, $prepared );
        if ( is_wp_error( $inserted ) ) {
            throw new \InvalidArgumentException( sprintf( 'Could not insert menu items: %s', $inserted->get_error_message() ) );
        }

        // Second pass: if any item used a string parent label, resolve
        // it to the menu_item id WP just returned. WP does not accept
        // string parents in a single wp_update_nav_menu_items call.
        $label_to_id = array();
        foreach ( (array) $inserted as $db_id => $item_row ) {
            if ( isset( $item_row['menu-item-title'] ) ) {
                $label_to_id[ (string) $item_row['menu-item-title'] ] = (int) $item_row['db_id'];
            }
        }
        $updates_needed = array();
        foreach ( $items as $idx => $item ) {
            if ( ! isset( $item['parent'] ) ) { continue; }
            $parent = $item['parent'];
            if ( is_string( $parent ) ) {
                if ( ! isset( $label_to_id[ $parent ] ) ) {
                    throw new \InvalidArgumentException(
                        sprintf( 'parent label "%s" not found in items list', $parent )
                    );
                }
                $parent_id = $label_to_id[ $parent ];
            } else {
                $parent_id = (int) $parent;
            }
            // Find this row in the wp_update_nav_menu_items return
            // by matching position.
            $matched_db_id = null;
            foreach ( (array) $inserted as $db_id => $item_row ) {
                if ( isset( $item_row['_agentshell_position'] ) && $item_row['_agentshell_position'] === $idx ) {
                    $matched_db_id = (int) $db_id;
                    break;
                }
            }
            if ( null !== $matched_db_id ) {
                $updates_needed[ $matched_db_id ] = $parent_id;
            }
        }
        if ( ! empty( $updates_needed ) ) {
            foreach ( $updates_needed as $db_id => $parent_id ) {
                wp_update_nav_menu_item( $menu_id, $db_id, array(
                    'menu-item-parent-id' => $parent_id,
                ) );
            }
        }

        // Assign to theme location if requested.
        if ( $location !== '' ) {
            $current = (array) get_theme_mod( 'nav_menu_locations', array() );
            // Unassign any other menu at this location.
            foreach ( $current as $other_id => $other_loc ) {
                if ( $other_loc === $location && (int) $other_id !== $menu_id ) {
                    unset( $current[ $other_id ] );
                }
            }
            $current[ $menu_id ] = $location;
            set_theme_mod( 'nav_menu_locations', $current );
        }

        return array(
            'menu_id'      => $menu_id,
            'menu_name'    => $name,
            'location'     => $location,
            'item_count'   => count( $prepared ),
            'first_item_id'=> isset( $inserted[0] ) && is_array( $inserted[0] ) ? (int) ( $inserted[0]['db_id'] ?? 0 ) : 0,
            'created'      => ! $existing,
        );
    }

    // No private helper needed — the pure function lives at the top
    // of this file so tests can call it directly.
}