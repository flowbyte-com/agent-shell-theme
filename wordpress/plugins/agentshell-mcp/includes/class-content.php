<?php
namespace AgentShell_MCP;

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-content-write-guard.php';

/**
 * Content primitives — intent-level wrappers over post/page CRUD for agents.
 *
 * Content is passed as raw HTML; WordPress auto-formatting (wpautop, kses)
 * is not applied because these tools run as administrators (unfiltered_html).
 * This matches the theme's REST API behaviour, where raw HTML is preserved.
 *
 * Every write below runs inside `Content_Write_Guard`, because WordPress can
 * report a failed `wp_insert_post()` after it has already written the post,
 * its terms and its metadata — and then never tell the caller which content it
 * wrote. See that class for the measurement and the limits.
 */
class Content_Primitives {
    const TYPES = array( 'post', 'page' );
    const STATUSES = array( 'draft', 'publish', 'pending', 'private', 'future' );

    /**
     * Create a post or page.
     *
     * @param string $type    'post' | 'page'
     * @param string $title   Post title
     * @param string $content Raw HTML content
     * @param string $status  draft|publish|pending|private|future
     * @param array  $extra   Optional: post_name, parent (pages), comment_status,
     *                        ping_status, post_date, include_content
     * @return array Created record { id, type, title, status, edit_url, atomicity }
     * @throws \InvalidArgumentException
     */
    public static function create( $type, $title, $content, $status = 'draft', array $extra = array() ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }
        if ( ! in_array( $status, self::STATUSES, true ) ) {
            throw new \InvalidArgumentException( "Unsupported status '{$status}'. Use " . implode( ', ', self::STATUSES ) . '.' );
        }

        // WordPress will happily create an untitled, empty draft and hand back
        // an id for it, which is not a page anyone can find, edit or preview.
        // Refusing here costs nothing — nothing has been written yet — and
        // stops the tool reporting success for content that was never asked
        // for. This mirrors core's own `empty_content` rule: title and
        // content both empty, with no excerpt to stand in for either.
        if ( '' === trim( (string) $title ) && '' === trim( (string) $content ) ) {
            throw new \InvalidArgumentException(
                "Cannot create {$type}: a title and content are both empty, which WordPress stores as an untitled draft rather than a page. Pass a title, content, or both."
            );
        }

        $postarr = array(
            'post_type'    => $type,
            'post_status'  => $status,
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => (string) $content,
        );

        foreach ( array( 'post_name', 'comment_status', 'ping_status', 'post_date' ) as $key ) {
            if ( isset( $extra[ $key ] ) ) {
                $postarr[ $key ] = $extra[ $key ];
            }
        }
        if ( $type === 'page' && ! empty( $extra['parent'] ) ) {
            $postarr['post_parent'] = (int) $extra['parent'];
        }

        // describe() is inside the guard on purpose: if reading the post back
        // fails, the tool must not report a failure over a post that exists.
        $include_content = ! array_key_exists( 'include_content', $extra ) || (bool) $extra['include_content'];
        $record = Content_Write_Guard::run( function () use ( $postarr, $type, $include_content ) {
            $id = wp_insert_post( wp_slash( $postarr ), true );
            if ( is_wp_error( $id ) ) {
                throw new \InvalidArgumentException( 'Could not create ' . $type . ': ' . $id->get_error_message() );
            }
            if ( ! $id ) {
                throw new \InvalidArgumentException( 'Could not create ' . $type . '. WordPress returned no post ID.' );
            }

            return self::describe( $id, $type, $include_content );
        } );

        $record['atomicity'] = Content_Write_Guard::report();
        return $record;
    }

    /**
     * Update a post or page (title / content / status / slug).
     *
     * @param int    $id      Post ID
     * @param string $type    'post' | 'page'
     * @param array  $changes Keys: title, content, status, slug, parent
     * @return array Updated record, including an `atomicity` block
     * @throws \InvalidArgumentException
     */
    public static function update( $id, $type, array $changes ) {
        $existing = self::require_post( $id, $type );

        // The body is echoed back by default, which is the existing
        // contract; `include_content => false` returns the length instead.
        $include_content = ! array_key_exists( 'include_content', $changes ) || (bool) $changes['include_content'];
        unset( $changes['include_content'] );

        $postarr = array( 'ID' => (int) $id );
        foreach ( $changes as $key => $value ) {
            switch ( $key ) {
                case 'title':
                    $postarr['post_title'] = sanitize_text_field( $value );
                    break;
                case 'content':
                    $postarr['post_content'] = (string) $value;
                    break;
                case 'status':
                    if ( ! in_array( $value, self::STATUSES, true ) ) {
                        throw new \InvalidArgumentException( "Unsupported status '{$value}'. Use " . implode( ', ', self::STATUSES ) . '.' );
                    }
                    $postarr['post_status'] = $value;
                    break;
                case 'slug':
                    $postarr['post_name'] = sanitize_title( $value );
                    break;
                case 'parent':
                    if ( $type !== 'page' ) {
                        throw new \InvalidArgumentException( 'parent only applies to pages.' );
                    }
                    $postarr['post_parent'] = (int) $value;
                    break;
                default:
                    throw new \InvalidArgumentException( "Unknown field '{$key}'. Supported: title, content, status, slug, parent." );
            }
        }

        $record = Content_Write_Guard::run( function () use ( $postarr, $type, $id, $include_content ) {
            $result = wp_update_post( wp_slash( $postarr ), true );
            if ( is_wp_error( $result ) ) {
                throw new \InvalidArgumentException( 'Could not update ' . $type . ' #' . $id . ': ' . $result->get_error_message() );
            }
            if ( ! $result ) {
                throw new \InvalidArgumentException( 'Could not update ' . $type . ' #' . $id . '. WordPress returned no post ID.' );
            }

            return self::describe( (int) $id, $type, $include_content );
        } );

        $record['atomicity'] = Content_Write_Guard::report();
        // Which fields this call actually changed.
        //
        // The record carries the post's *current* state, so a title the
        // caller never mentioned comes back as the post's title — which,
        // for a draft with no title, is an empty string. That is
        // accurate, and it read as misleading: the response looked like
        // it had set the title to nothing. Naming the fields that were
        // written removes the ambiguity without changing the record's
        // shape.
        $record['updated_fields'] = array_values( array_map( 'strval', array_keys( $changes ) ) );
        return $record;
    }

    /**
     * Set a post's status (publish/unpublish/draft).
     *
     * @return array Updated record
     */
    public static function set_status( $id, $type, $status, $include_content = true ) {
        return self::update(
            $id,
            $type,
            array( 'status' => $status, 'include_content' => (bool) $include_content )
        );
    }

    /**
     * Search posts/pages. Returns compact records.
     *
     * @param string $query  Search term (optional; empty lists recent)
     * @param string $type   post|page|both
     * @param string $status any|draft|publish|pending|private|future
     * @param int    $limit  1-50
     * @return array { total, items: [ { id, title, type, status, date, modified, excerpt } ] }
     */
    public static function search( $query, $type = 'both', $status = 'any', $limit = 10 ) {
        $post_type = $type === 'both' ? array( 'post', 'page' ) : $type;
        if ( ! in_array( $type, array( 'both', 'post', 'page' ), true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post, page or both." );
        }
        $args = array(
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => max( 1, min( 50, (int) $limit ) ),
            'no_found_rows'  => false,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        );
        if ( $query !== '' ) {
            $args['s'] = $query;
        }

        $query_obj = new \WP_Query( $args );
        $items     = array();
        foreach ( $query_obj->posts as $post ) {
            $items[] = self::compact( $post );
        }
        wp_reset_postdata();

        return array(
            'total' => (int) $query_obj->found_posts,
            'items' => $items,
        );
    }

    /**
     * Fetch one post/page.
     *
     * @return array Record incl. raw content (rendered and raw variants)
     * @throws \InvalidArgumentException
     */
    public static function get( $id, $type, $include_content = true ) {
        $post = self::require_post( $id, $type );

        if ( ! $include_content ) {
            // The metadata-only read: the lengths are reported so the
            // caller knows how big the body is without carrying it.
            return array_merge( self::compact( $post ), array(
                'content' => array(
                    'length'  => strlen( (string) $post->post_content ),
                    'omitted' => true,
                ),
                'slug'    => $post->post_name,
                'link'    => get_permalink( $post ),
            ) );
        }

        return array_merge( self::compact( $post ), array(
            'content' => array(
                'raw'     => $post->post_content,
                'rendered' => apply_filters( 'the_content', $post->post_content ),
            ),
            'slug'    => $post->post_name,
            'link'    => get_permalink( $post ),
        ) );
    }

    /**
     * Full record for create/update responses.
     */
    private static function describe( $id, $type, $include_content = true ) {
        $post = self::require_post( $id, $type );
        $record = self::compact( $post );
        if ( $include_content ) {
            $record['content'] = array(
                'raw'       => $post->post_content,
                'length'    => strlen( $post->post_content ),
            );
        } else {
            // The length is reported, the body is not: a create or an
            // update that echoes the whole body back carries it into every
            // transcript that quotes the response.
            $record['content'] = array(
                'length'  => strlen( (string) $post->post_content ),
                'omitted' => true,
            );
        }
        $record['link'] = get_permalink( $post );
        $record['edit_url'] = admin_url( 'post.php?post=' . $id . '&action=edit' );
        return $record;
    }

    private static function require_post( $id, $type ) {
        if ( ! in_array( $type, self::TYPES, true ) ) {
            throw new \InvalidArgumentException( "Unsupported type '{$type}'. Use post or page." );
        }
        $post = get_post( (int) $id );
        if ( ! $post || $post->post_type !== $type ) {
            throw new \InvalidArgumentException( $type . ' #' . (int) $id . ' not found.' );
        }
        return $post;
    }

    private static function compact( $post ) {
        return array(
            'id'       => (int) $post->ID,
            'title'    => get_the_title( $post ),
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'date'     => get_the_date( 'Y-m-d H:i:s', $post ),
            'modified' => get_the_modified_date( 'Y-m-d H:i:s', $post ),
            'excerpt'  => trim( wp_strip_all_tags( get_the_excerpt( $post ) ) ),
        );
    }
}