<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The theme's own abilities for the MCP server (see mcp-server.php).
 *
 * Grouped by namespace, which is also how the settings page groups them:
 *   snn-wp/...     posts, pages, custom post types, media, terms, menus, comments
 *   snn-acf/...    ACF field groups and field values
 *   snn-bricks/... Bricks page layouts (read-only)
 *   snn-seo/...    SEOPress global settings (titles, social, sitemaps)
 *
 * Every permission callback checks the real WordPress capability for the
 * exact object, so these abilities stay safe even when called outside MCP.
 * Nothing here deletes permanently: "delete" always means the trash.
 * show_in_rest is off -- they are only reachable through the MCP endpoint.
 */

// ----------------------------------------------------------------------
// Registration helpers
// ----------------------------------------------------------------------

function snn_mcp_register_ability_categories() {
    if ( ! function_exists( 'wp_register_ability_category' ) ) {
        return;
    }
    wp_register_ability_category( 'snn-content', array(
        'label'       => __( 'WordPress content', 'snn' ),
        'description' => __( 'Posts, pages, media, terms, menus and comments.', 'snn' ),
    ) );
    wp_register_ability_category( 'snn-acf', array(
        'label'       => __( 'ACF fields', 'snn' ),
        'description' => __( 'Advanced Custom Fields groups and values.', 'snn' ),
    ) );
    wp_register_ability_category( 'snn-bricks', array(
        'label'       => __( 'Bricks Builder', 'snn' ),
        'description' => __( 'Read-only access to Bricks page layouts.', 'snn' ),
    ) );
    wp_register_ability_category( 'snn-seo', array(
        'label'       => __( 'SEOPress settings', 'snn' ),
        'description' => __( 'Site-wide SEOPress titles, social and sitemap settings.', 'snn' ),
    ) );
}
add_action( 'wp_abilities_api_categories_init', 'snn_mcp_register_ability_categories' );

/**
 * @param string $kind 'read' | 'write' | 'trash'
 */
function snn_mcp_add_ability( $name, $category, $kind, $label, $description, array $properties, array $required, callable $execute, callable $permission ) {
    wp_register_ability( $name, array(
        'label'               => $label,
        'description'         => $description,
        'category'            => $category,
        'input_schema'        => array(
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
            'default'              => array(),
        ),
        'output_schema'       => array( 'type' => 'object' ),
        'execute_callback'    => $execute,
        'permission_callback' => $permission,
        'meta'                => array(
            'show_in_rest' => false,
            'annotations'  => array(
                'readonly'    => 'read' === $kind,
                'destructive' => 'trash' === $kind,
                'idempotent'  => 'read' === $kind,
            ),
        ),
    ) );
}

function snn_mcp_int_prop( $description, $min = 1, $max = null ) {
    $prop = array( 'type' => 'integer', 'description' => $description, 'minimum' => $min );
    if ( null !== $max ) {
        $prop['maximum'] = $max;
    }
    return $prop;
}

function snn_mcp_str_prop( $description, $enum = null ) {
    $prop = array( 'type' => 'string', 'description' => $description );
    if ( $enum ) {
        $prop['enum'] = $enum;
    }
    return $prop;
}

function snn_mcp_paging_props() {
    return array(
        'per_page' => snn_mcp_int_prop( 'Results per page (1-100, default 20).', 1, 100 ),
        'page'     => snn_mcp_int_prop( 'Page number, starting at 1.' ),
    );
}

function snn_mcp_paging( array $input ) {
    $per_page = isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 20;
    $page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
    return array( $per_page, $page );
}

// ----------------------------------------------------------------------
// Shared lookups
// ----------------------------------------------------------------------

/**
 * Post types the AI may touch. Code snippets are excluded on purpose (a
 * snippet is executable PHP), as are WordPress/plugin internals.
 */
function snn_mcp_allowed_post_types() {
    $blocked = array(
        'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
        'snn_code_snippet', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page',
    );
    $types = array_diff( get_post_types( array( 'show_ui' => true ) ), $blocked );
    /**
     * Filters the post types the MCP server may read and edit.
     *
     * @param string[] $types
     */
    return array_values( (array) apply_filters( 'snn_mcp_allowed_post_types', $types ) );
}

function snn_mcp_post_type_ok( $post_type ) {
    return in_array( (string) $post_type, snn_mcp_allowed_post_types(), true );
}

/**
 * @return WP_Post|null Only posts of an allowed type.
 */
function snn_mcp_get_allowed_post( $id ) {
    $post = get_post( absint( $id ) );
    return ( $post && snn_mcp_post_type_ok( $post->post_type ) ) ? $post : null;
}

function snn_mcp_allowed_taxonomies() {
    $blocked = array( 'nav_menu', 'link_category', 'post_format', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' );
    return array_values( array_diff( get_taxonomies( array( 'show_ui' => true ) ), $blocked ) );
}

function snn_mcp_bricks_meta_key() {
    return defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';
}

function snn_mcp_format_post( WP_Post $post, $full = false ) {
    $data = array(
        'id'       => $post->ID,
        'type'     => $post->post_type,
        'status'   => $post->post_status,
        'title'    => $post->post_title,
        'slug'     => $post->post_name,
        'link'     => get_permalink( $post ),
        'date'     => $post->post_date,
        'modified' => $post->post_modified,
        'author'   => get_the_author_meta( 'display_name', $post->post_author ),
        'excerpt'  => $post->post_excerpt,
    );
    if ( ! $full ) {
        return $data;
    }

    $terms = array();
    foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
        if ( ! in_array( $taxonomy, snn_mcp_allowed_taxonomies(), true ) ) {
            continue;
        }
        $assigned = wp_get_object_terms( $post->ID, $taxonomy );
        if ( is_wp_error( $assigned ) ) {
            continue;
        }
        $terms[ $taxonomy ] = array_map( function ( $term ) {
            return array( 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug );
        }, $assigned );
    }

    $data['content']        = $post->post_content;
    $data['parent']         = $post->post_parent;
    $data['menu_order']     = $post->menu_order;
    $data['featured_media'] = (int) get_post_thumbnail_id( $post );
    $data['terms']          = $terms;
    $data['uses_bricks']    = ! empty( get_post_meta( $post->ID, snn_mcp_bricks_meta_key(), true ) );
    if ( $data['uses_bricks'] ) {
        $data['note'] = 'This page is built with Bricks Builder: the visible layout is not in "content". Use snn-bricks/get-page-content to read it.';
    }
    return $data;
}

// ----------------------------------------------------------------------
// Posts, pages, custom post types
// ----------------------------------------------------------------------

function snn_mcp_list_post_types() {
    $items = array();
    foreach ( snn_mcp_allowed_post_types() as $type ) {
        $object = get_post_type_object( $type );
        if ( ! $object || ! current_user_can( $object->cap->edit_posts ) ) {
            continue;
        }
        $items[] = array(
            'name'         => $type,
            'label'        => $object->label,
            'hierarchical' => (bool) $object->hierarchical,
            'taxonomies'   => array_values( array_intersect( get_object_taxonomies( $type ), snn_mcp_allowed_taxonomies() ) ),
        );
    }
    return array( 'post_types' => $items );
}

function snn_mcp_can_edit_type( $post_type ) {
    $object = get_post_type_object( (string) $post_type );
    return $object && snn_mcp_post_type_ok( $post_type ) && current_user_can( $object->cap->edit_posts );
}

function snn_mcp_list_posts( $input ) {
    list( $per_page, $page ) = snn_mcp_paging( $input );
    $status                  = $input['status'] ?? 'any';
    $query                   = new WP_Query( array(
        'post_type'           => $input['post_type'] ?? 'post',
        'post_status'         => 'any' === $status ? array( 'publish', 'draft', 'pending', 'future', 'private' ) : $status,
        's'                   => isset( $input['search'] ) ? (string) $input['search'] : '',
        'posts_per_page'      => $per_page,
        'paged'               => $page,
        'orderby'             => $input['orderby'] ?? 'date',
        'order'               => $input['order'] ?? 'DESC',
        'ignore_sticky_posts' => true,
        'no_found_rows'       => false,
    ) );

    $items = array();
    foreach ( $query->posts as $post ) {
        if ( current_user_can( 'edit_post', $post->ID ) ) {
            $items[] = snn_mcp_format_post( $post );
        }
    }
    return array( 'items' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages );
}

function snn_mcp_post_write_props() {
    return array(
        'title'          => snn_mcp_str_prop( 'Title.' ),
        'content'        => snn_mcp_str_prop( 'Content (HTML or block markup). Scripts and other unsafe HTML are removed.' ),
        'excerpt'        => snn_mcp_str_prop( 'Excerpt.' ),
        'status'         => snn_mcp_str_prop( 'Status. "future" needs a date in the future.', array( 'draft', 'pending', 'publish', 'future', 'private' ) ),
        'slug'           => snn_mcp_str_prop( 'URL slug.' ),
        'date'           => snn_mcp_str_prop( 'Publish date in site time, e.g. 2026-11-01 09:00:00.' ),
        'parent'         => snn_mcp_int_prop( 'Parent post ID (hierarchical types), 0 for none.', 0 ),
        'menu_order'     => snn_mcp_int_prop( 'Menu order.', 0 ),
        'featured_media' => snn_mcp_int_prop( 'Attachment ID of the featured image, 0 to remove.', 0 ),
        'terms'          => array(
            'type'                 => 'object',
            'description'          => 'Terms per taxonomy, e.g. {"category": [3, "News"]}. Replaces the existing terms of each listed taxonomy. Names must already exist.',
            'additionalProperties' => array(
                'type'  => 'array',
                'items' => array( 'type' => array( 'integer', 'string' ) ),
            ),
        ),
    );
}

/**
 * Turns the shared write props into a wp_insert_post()/wp_update_post() array.
 *
 * @return array|WP_Error
 */
function snn_mcp_build_postarr( array $input, $post_type ) {
    $postarr = array();
    $map     = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name' );
    foreach ( $map as $key => $field ) {
        if ( isset( $input[ $key ] ) ) {
            $postarr[ $field ] = (string) $input[ $key ];
        }
    }
    if ( isset( $input['status'] ) ) {
        $object = get_post_type_object( $post_type );
        if ( in_array( $input['status'], array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $object->cap->publish_posts ) ) {
            return new WP_Error( 'snn_mcp_forbidden', 'You may not publish this post type.' );
        }
        $postarr['post_status'] = $input['status'];
    }
    if ( isset( $input['date'] ) ) {
        $time = strtotime( (string) $input['date'] );
        if ( false === $time ) {
            return new WP_Error( 'snn_mcp_invalid_date', 'Could not read the date.' );
        }
        $postarr['post_date']     = gmdate( 'Y-m-d H:i:s', $time );
        $postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
        $postarr['edit_date']     = true;
    }
    if ( isset( $input['parent'] ) ) {
        $postarr['post_parent'] = absint( $input['parent'] );
    }
    if ( isset( $input['menu_order'] ) ) {
        $postarr['menu_order'] = (int) $input['menu_order'];
    }
    return $postarr;
}

/**
 * Applies featured image + terms after the post exists.
 *
 * @return string[] Warnings for anything that was skipped.
 */
function snn_mcp_apply_post_extras( $post_id, array $input ) {
    $warnings = array();

    if ( isset( $input['featured_media'] ) ) {
        $media_id = absint( $input['featured_media'] );
        if ( 0 === $media_id ) {
            delete_post_thumbnail( $post_id );
        } elseif ( 'attachment' === get_post_type( $media_id ) && wp_attachment_is_image( $media_id ) ) {
            set_post_thumbnail( $post_id, $media_id );
        } else {
            $warnings[] = 'featured_media is not an image attachment -- skipped.';
        }
    }

    if ( ! empty( $input['terms'] ) && is_array( $input['terms'] ) ) {
        $post_type = get_post_type( $post_id );
        foreach ( $input['terms'] as $taxonomy => $values ) {
            $tax = get_taxonomy( $taxonomy );
            if ( ! $tax || ! in_array( $taxonomy, snn_mcp_allowed_taxonomies(), true ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
                $warnings[] = "Taxonomy {$taxonomy} does not belong to this post type -- skipped.";
                continue;
            }
            if ( ! current_user_can( $tax->cap->assign_terms ) ) {
                $warnings[] = "You may not assign {$taxonomy} terms -- skipped.";
                continue;
            }
            $ids = array();
            foreach ( (array) $values as $value ) {
                $term = is_int( $value ) || ctype_digit( (string) $value )
                    ? get_term( (int) $value, $taxonomy )
                    : ( get_term_by( 'name', (string) $value, $taxonomy ) ?: get_term_by( 'slug', (string) $value, $taxonomy ) );
                if ( $term && ! is_wp_error( $term ) ) {
                    $ids[] = (int) $term->term_id;
                } else {
                    $warnings[] = "Term \"{$value}\" not found in {$taxonomy} -- skipped.";
                }
            }
            wp_set_object_terms( $post_id, $ids, $taxonomy, false );
        }
    }

    return $warnings;
}

function snn_mcp_create_post( $input ) {
    $post_type = $input['post_type'];
    $postarr   = snn_mcp_build_postarr( $input, $post_type );
    if ( is_wp_error( $postarr ) ) {
        return $postarr;
    }
    $postarr['post_type']   = $post_type;
    $postarr['post_status'] = $postarr['post_status'] ?? 'draft';
    $postarr['post_author'] = get_current_user_id();

    $post_id = wp_insert_post( wp_slash( $postarr ), true );
    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }
    $warnings = snn_mcp_apply_post_extras( $post_id, $input );
    return array( 'post' => snn_mcp_format_post( get_post( $post_id ), true ), 'warnings' => $warnings );
}

function snn_mcp_update_post( $input ) {
    $post    = snn_mcp_get_allowed_post( $input['id'] );
    $postarr = snn_mcp_build_postarr( $input, $post->post_type );
    if ( is_wp_error( $postarr ) ) {
        return $postarr;
    }
    if ( ! empty( $postarr ) ) {
        $postarr['ID'] = $post->ID;
        $result        = wp_update_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }
    $warnings = snn_mcp_apply_post_extras( $post->ID, $input );
    return array( 'post' => snn_mcp_format_post( get_post( $post->ID ), true ), 'warnings' => $warnings );
}

function snn_mcp_trash_post( $input ) {
    if ( ! EMPTY_TRASH_DAYS ) {
        return new WP_Error( 'snn_mcp_no_trash', 'The trash is disabled on this site (EMPTY_TRASH_DAYS = 0), so moving to trash would delete permanently. Refused.' );
    }
    $post = snn_mcp_get_allowed_post( $input['id'] );
    if ( 'trash' === $post->post_status ) {
        return new WP_Error( 'snn_mcp_already_trashed', 'This item is already in the trash.' );
    }
    if ( ! wp_trash_post( $post->ID ) ) {
        return new WP_Error( 'snn_mcp_trash_failed', 'Could not move the item to the trash.' );
    }
    return array( 'trashed' => true, 'id' => $post->ID, 'title' => $post->post_title );
}

// ----------------------------------------------------------------------
// Media
// ----------------------------------------------------------------------

function snn_mcp_format_media( WP_Post $post ) {
    $meta = wp_get_attachment_metadata( $post->ID );
    return array(
        'id'          => $post->ID,
        'title'       => $post->post_title,
        'url'         => wp_get_attachment_url( $post->ID ),
        'mime_type'   => $post->post_mime_type,
        'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
        'caption'     => $post->post_excerpt,
        'description' => $post->post_content,
        'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
        'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
        'filesize'    => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : null,
        'date'        => $post->post_date,
        'attached_to' => $post->post_parent,
        'status'      => $post->post_status,
    );
}

function snn_mcp_get_attachment( $id ) {
    $post = get_post( absint( $id ) );
    return ( $post && 'attachment' === $post->post_type ) ? $post : null;
}

function snn_mcp_list_media( $input ) {
    list( $per_page, $page ) = snn_mcp_paging( $input );
    $query                   = new WP_Query( array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => isset( $input['mime_type'] ) ? (string) $input['mime_type'] : '',
        's'              => isset( $input['search'] ) ? (string) $input['search'] : '',
        'posts_per_page' => $per_page,
        'paged'          => $page,
    ) );
    return array(
        'items' => array_map( 'snn_mcp_format_media', $query->posts ),
        'total' => (int) $query->found_posts,
        'pages' => (int) $query->max_num_pages,
    );
}

function snn_mcp_update_media( $input ) {
    $post    = snn_mcp_get_attachment( $input['id'] );
    $postarr = array();
    foreach ( array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ) as $key => $field ) {
        if ( isset( $input[ $key ] ) ) {
            $postarr[ $field ] = (string) $input[ $key ];
        }
    }
    if ( $postarr ) {
        $postarr['ID'] = $post->ID;
        $result        = wp_update_post( wp_slash( $postarr ), true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
    }
    if ( isset( $input['alt'] ) ) {
        update_post_meta( $post->ID, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
    }
    return array( 'media' => snn_mcp_format_media( get_post( $post->ID ) ) );
}

function snn_mcp_upload_media_from_url( $input ) {
    $url = esc_url_raw( (string) $input['url'], array( 'http', 'https' ) );
    if ( '' === $url ) {
        return new WP_Error( 'snn_mcp_invalid_url', 'Only http and https URLs are allowed.' );
    }

    $filename  = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
    $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    $allowed   = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf' );
    if ( ! in_array( $extension, $allowed, true ) ) {
        return new WP_Error( 'snn_mcp_invalid_type', 'Only these file types can be uploaded: ' . implode( ', ', $allowed ) . '. The URL must end in the file name.' );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $max_bytes = 20 * MB_IN_BYTES;
    $tmp       = wp_tempnam( $filename );
    // wp_safe_remote_get() refuses private/loopback addresses, so this can't
    // be abused to fetch from inside the server's network.
    $response = wp_safe_remote_get( $url, array(
        'timeout'             => 30,
        'redirection'         => 3,
        'stream'              => true,
        'filename'            => $tmp,
        'limit_response_size' => $max_bytes + 1,
    ) );
    if ( is_wp_error( $response ) ) {
        wp_delete_file( $tmp );
        return $response;
    }
    if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        wp_delete_file( $tmp );
        return new WP_Error( 'snn_mcp_download_failed', 'Download failed with HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . '.' );
    }
    if ( filesize( $tmp ) > $max_bytes ) {
        wp_delete_file( $tmp );
        return new WP_Error( 'snn_mcp_too_large', 'The file is larger than 20 MB.' );
    }

    $attach_to = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
    $title     = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : null;
    // media_handle_sideload() re-checks the real file type against the
    // site's allowed upload types (unfiltered_upload is denied during MCP).
    $attachment_id = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp ), $attach_to, $title );
    if ( is_wp_error( $attachment_id ) ) {
        wp_delete_file( $tmp );
        return $attachment_id;
    }
    if ( isset( $input['alt'] ) ) {
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
    }
    return array( 'media' => snn_mcp_format_media( get_post( $attachment_id ) ) );
}

function snn_mcp_trash_media( $input ) {
    if ( ! MEDIA_TRASH || ! EMPTY_TRASH_DAYS ) {
        return new WP_Error(
            'snn_mcp_no_media_trash',
            'Media has no trash on this site, so deleting would be permanent. Refused. An administrator can add define( \'MEDIA_TRASH\', true ); to wp-config.php to enable it.'
        );
    }
    $post = snn_mcp_get_attachment( $input['id'] );
    if ( 'trash' === $post->post_status ) {
        return new WP_Error( 'snn_mcp_already_trashed', 'This file is already in the trash.' );
    }
    if ( ! wp_trash_post( $post->ID ) ) {
        return new WP_Error( 'snn_mcp_trash_failed', 'Could not move the file to the trash.' );
    }
    return array( 'trashed' => true, 'id' => $post->ID, 'title' => $post->post_title );
}

// ----------------------------------------------------------------------
// Taxonomies + terms
// ----------------------------------------------------------------------

function snn_mcp_format_term( WP_Term $term ) {
    return array(
        'id'          => $term->term_id,
        'taxonomy'    => $term->taxonomy,
        'name'        => $term->name,
        'slug'        => $term->slug,
        'description' => $term->description,
        'parent'      => $term->parent,
        'count'       => $term->count,
    );
}

function snn_mcp_taxonomy_ok( $taxonomy ) {
    return in_array( (string) $taxonomy, snn_mcp_allowed_taxonomies(), true );
}

function snn_mcp_list_taxonomies() {
    $items = array();
    foreach ( snn_mcp_allowed_taxonomies() as $name ) {
        $tax     = get_taxonomy( $name );
        $items[] = array(
            'name'         => $name,
            'label'        => $tax->label,
            'hierarchical' => (bool) $tax->hierarchical,
            'post_types'   => array_values( (array) $tax->object_type ),
        );
    }
    return array( 'taxonomies' => $items );
}

function snn_mcp_list_terms( $input ) {
    list( $per_page, $page ) = snn_mcp_paging( $input );
    $args                    = array(
        'taxonomy'   => $input['taxonomy'],
        'hide_empty' => false,
        'number'     => $per_page,
        'offset'     => ( $page - 1 ) * $per_page,
        'search'     => isset( $input['search'] ) ? (string) $input['search'] : '',
    );
    if ( isset( $input['parent'] ) ) {
        $args['parent'] = absint( $input['parent'] );
    }
    $terms = get_terms( $args );
    if ( is_wp_error( $terms ) ) {
        return $terms;
    }
    return array(
        'items' => array_map( 'snn_mcp_format_term', $terms ),
        'total' => (int) wp_count_terms( array( 'taxonomy' => $input['taxonomy'], 'hide_empty' => false ) ),
    );
}

function snn_mcp_term_args( array $input ) {
    $args = array();
    foreach ( array( 'name', 'slug', 'description' ) as $key ) {
        if ( isset( $input[ $key ] ) ) {
            $args[ $key ] = (string) $input[ $key ];
        }
    }
    if ( isset( $input['parent'] ) ) {
        $args['parent'] = absint( $input['parent'] );
    }
    return wp_slash( $args );
}

function snn_mcp_create_term( $input ) {
    $args   = snn_mcp_term_args( $input );
    $result = wp_insert_term( $args['name'], $input['taxonomy'], $args );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    return array( 'term' => snn_mcp_format_term( get_term( $result['term_id'], $input['taxonomy'] ) ) );
}

function snn_mcp_update_term( $input ) {
    $result = wp_update_term( absint( $input['id'] ), $input['taxonomy'], snn_mcp_term_args( $input ) );
    if ( is_wp_error( $result ) ) {
        return $result;
    }
    return array( 'term' => snn_mcp_format_term( get_term( $result['term_id'], $input['taxonomy'] ) ) );
}

function snn_mcp_term_permission( $input, $cap ) {
    if ( ! snn_mcp_taxonomy_ok( $input['taxonomy'] ?? '' ) ) {
        return false;
    }
    if ( 'edit_term' === $cap ) {
        $term = get_term( absint( $input['id'] ?? 0 ), $input['taxonomy'] );
        return $term && ! is_wp_error( $term ) && current_user_can( 'edit_term', $term->term_id );
    }
    return current_user_can( get_taxonomy( $input['taxonomy'] )->cap->$cap );
}

// ----------------------------------------------------------------------
// Menus
// ----------------------------------------------------------------------

function snn_mcp_format_menu_item( $item ) {
    return array(
        'id'        => (int) $item->ID,
        'title'     => $item->title,
        'url'       => $item->url,
        'type'      => $item->type,
        'object'    => $item->object,
        'object_id' => (int) $item->object_id,
        'parent_id' => (int) $item->menu_item_parent,
        'position'  => (int) $item->menu_order,
        'target'    => $item->target,
    );
}

function snn_mcp_get_menu_or_error( $menu_id ) {
    $menu = wp_get_nav_menu_object( absint( $menu_id ) );
    return $menu ? $menu : new WP_Error( 'snn_mcp_no_menu', 'Menu not found.' );
}

function snn_mcp_list_menus() {
    $locations = get_nav_menu_locations();
    $items     = array();
    foreach ( wp_get_nav_menus() as $menu ) {
        $items[] = array(
            'id'        => $menu->term_id,
            'name'      => $menu->name,
            'count'     => $menu->count,
            'locations' => array_keys( $locations, $menu->term_id, true ),
        );
    }
    return array( 'menus' => $items );
}

function snn_mcp_get_menu( $input ) {
    $menu = snn_mcp_get_menu_or_error( $input['menu_id'] );
    if ( is_wp_error( $menu ) ) {
        return $menu;
    }
    $items = wp_get_nav_menu_items( $menu->term_id );
    return array(
        'id'    => $menu->term_id,
        'name'  => $menu->name,
        'items' => array_map( 'snn_mcp_format_menu_item', (array) $items ),
    );
}

function snn_mcp_menu_item_props() {
    return array(
        'title'       => snn_mcp_str_prop( 'Label shown in the menu.' ),
        'url'         => snn_mcp_str_prop( 'Link URL (only for object_type "custom").' ),
        'object_type' => snn_mcp_str_prop( 'What the item links to.', array( 'custom', 'post_type', 'taxonomy' ) ),
        'object'      => snn_mcp_str_prop( 'Post type or taxonomy name, e.g. "page" or "category".' ),
        'object_id'   => snn_mcp_int_prop( 'ID of the linked post or term.' ),
        'parent_id'   => snn_mcp_int_prop( 'Parent menu item ID, 0 for top level.', 0 ),
        'position'    => snn_mcp_int_prop( 'Position in the menu (1 = first).' ),
        'target'      => snn_mcp_str_prop( 'Open in new tab?', array( '', '_blank' ) ),
    );
}

/**
 * wp_update_nav_menu_item() resets every field it isn't given, so updates
 * start from the existing item's full data.
 */
function snn_mcp_menu_item_args( array $input, $existing = null ) {
    $args = array(
        'menu-item-title'     => $existing ? $existing->title : '',
        'menu-item-url'       => $existing ? $existing->url : '',
        'menu-item-type'      => $existing ? $existing->type : 'custom',
        'menu-item-object'    => $existing ? $existing->object : 'custom',
        'menu-item-object-id' => $existing ? (int) $existing->object_id : 0,
        'menu-item-parent-id' => $existing ? (int) $existing->menu_item_parent : 0,
        'menu-item-position'  => $existing ? (int) $existing->menu_order : 0,
        'menu-item-target'    => $existing ? $existing->target : '',
        'menu-item-attr-title' => $existing ? $existing->attr_title : '',
        'menu-item-description' => $existing ? $existing->description : '',
        'menu-item-classes'   => $existing ? implode( ' ', (array) $existing->classes ) : '',
        'menu-item-xfn'       => $existing ? $existing->xfn : '',
        'menu-item-status'    => 'publish',
    );

    if ( isset( $input['title'] ) ) {
        $args['menu-item-title'] = sanitize_text_field( (string) $input['title'] );
    }
    if ( isset( $input['object_type'] ) ) {
        $args['menu-item-type'] = $input['object_type'];
        if ( 'custom' === $input['object_type'] ) {
            $args['menu-item-object']    = 'custom';
            $args['menu-item-object-id'] = 0;
        }
    }
    if ( isset( $input['object'] ) ) {
        $args['menu-item-object'] = sanitize_key( (string) $input['object'] );
    }
    if ( isset( $input['object_id'] ) ) {
        $args['menu-item-object-id'] = absint( $input['object_id'] );
    }
    if ( isset( $input['url'] ) ) {
        $args['menu-item-url'] = esc_url_raw( (string) $input['url'] );
    }
    if ( isset( $input['parent_id'] ) ) {
        $args['menu-item-parent-id'] = absint( $input['parent_id'] );
    }
    if ( isset( $input['position'] ) ) {
        $args['menu-item-position'] = absint( $input['position'] );
    }
    if ( isset( $input['target'] ) ) {
        $args['menu-item-target'] = '_blank' === $input['target'] ? '_blank' : '';
    }

    if ( 'post_type' === $args['menu-item-type'] && ! snn_mcp_get_allowed_post( $args['menu-item-object-id'] ) ) {
        return new WP_Error( 'snn_mcp_invalid_menu_target', 'object_id is not a post of an allowed type.' );
    }
    if ( 'taxonomy' === $args['menu-item-type'] && ( ! snn_mcp_taxonomy_ok( $args['menu-item-object'] ) || ! get_term( $args['menu-item-object-id'], $args['menu-item-object'] ) ) ) {
        return new WP_Error( 'snn_mcp_invalid_menu_target', 'object_id is not a term of an allowed taxonomy.' );
    }
    if ( 'custom' === $args['menu-item-type'] && '' === $args['menu-item-url'] ) {
        return new WP_Error( 'snn_mcp_invalid_menu_target', 'A custom link needs a url.' );
    }
    // Core expects slashed data here (it is normally fed straight from $_POST).
    return wp_slash( $args );
}

function snn_mcp_add_menu_item( $input ) {
    $menu = snn_mcp_get_menu_or_error( $input['menu_id'] );
    if ( is_wp_error( $menu ) ) {
        return $menu;
    }
    $args = snn_mcp_menu_item_args( $input );
    if ( is_wp_error( $args ) ) {
        return $args;
    }
    $item_id = wp_update_nav_menu_item( $menu->term_id, 0, $args );
    if ( is_wp_error( $item_id ) ) {
        return $item_id;
    }
    return array( 'item' => snn_mcp_format_menu_item( wp_setup_nav_menu_item( get_post( $item_id ) ) ) );
}

function snn_mcp_update_menu_item( $input ) {
    $menu = snn_mcp_get_menu_or_error( $input['menu_id'] );
    if ( is_wp_error( $menu ) ) {
        return $menu;
    }
    $post = get_post( absint( $input['item_id'] ) );
    if ( ! $post || ! is_nav_menu_item( $post->ID ) || ! has_term( $menu->term_id, 'nav_menu', $post ) ) {
        return new WP_Error( 'snn_mcp_no_menu_item', 'This item is not part of the given menu.' );
    }
    $args = snn_mcp_menu_item_args( $input, wp_setup_nav_menu_item( $post ) );
    if ( is_wp_error( $args ) ) {
        return $args;
    }
    $item_id = wp_update_nav_menu_item( $menu->term_id, $post->ID, $args );
    if ( is_wp_error( $item_id ) ) {
        return $item_id;
    }
    return array( 'item' => snn_mcp_format_menu_item( wp_setup_nav_menu_item( get_post( $item_id ) ) ) );
}

// ----------------------------------------------------------------------
// Comments
// ----------------------------------------------------------------------

function snn_mcp_format_comment( WP_Comment $comment ) {
    return array(
        'id'         => (int) $comment->comment_ID,
        'post_id'    => (int) $comment->comment_post_ID,
        'post_title' => get_the_title( $comment->comment_post_ID ),
        'parent'     => (int) $comment->comment_parent,
        'author'     => $comment->comment_author,
        'author_url' => $comment->comment_author_url,
        'date'       => $comment->comment_date,
        'status'     => wp_get_comment_status( $comment ),
        'content'    => $comment->comment_content,
    );
}

function snn_mcp_list_comments( $input ) {
    list( $per_page, $page ) = snn_mcp_paging( $input );
    $args                    = array(
        'status' => $input['status'] ?? 'all',
        'number' => $per_page,
        'offset' => ( $page - 1 ) * $per_page,
        'search' => isset( $input['search'] ) ? (string) $input['search'] : '',
        'type'   => 'comment',
    );
    if ( isset( $input['post_id'] ) ) {
        $args['post_id'] = absint( $input['post_id'] );
    }
    return array( 'items' => array_map( 'snn_mcp_format_comment', get_comments( $args ) ) );
}

function snn_mcp_moderate_comment( $input ) {
    $id = absint( $input['id'] );
    switch ( $input['action'] ) {
        case 'approve':
            $ok = wp_set_comment_status( $id, 'approve' );
            break;
        case 'hold':
            $ok = wp_set_comment_status( $id, 'hold' );
            break;
        case 'spam':
            $ok = wp_spam_comment( $id );
            break;
        case 'trash':
            if ( ! EMPTY_TRASH_DAYS ) {
                return new WP_Error( 'snn_mcp_no_trash', 'The trash is disabled on this site, so this would delete permanently. Refused.' );
            }
            $ok = wp_trash_comment( $id );
            break;
        default:
            $ok = false;
    }
    if ( ! $ok || is_wp_error( $ok ) ) {
        return is_wp_error( $ok ) ? $ok : new WP_Error( 'snn_mcp_comment_failed', 'Could not change the comment.' );
    }
    return array( 'comment' => snn_mcp_format_comment( get_comment( $id ) ) );
}

function snn_mcp_reply_comment( $input ) {
    $parent = get_comment( absint( $input['id'] ) );
    $user   = wp_get_current_user();
    $id     = wp_insert_comment( wp_slash( array(
        'comment_post_ID'      => (int) $parent->comment_post_ID,
        'comment_parent'       => (int) $parent->comment_ID,
        'comment_content'      => wp_kses_post( (string) $input['content'] ),
        'comment_approved'     => 1,
        'comment_type'         => 'comment',
        'user_id'              => $user->ID,
        'comment_author'       => $user->display_name,
        'comment_author_email' => $user->user_email,
        'comment_author_url'   => $user->user_url,
    ) ) );
    if ( ! $id ) {
        return new WP_Error( 'snn_mcp_comment_failed', 'Could not save the reply.' );
    }
    return array( 'comment' => snn_mcp_format_comment( get_comment( $id ) ) );
}

// ----------------------------------------------------------------------
// ACF
// ----------------------------------------------------------------------

function snn_mcp_acf_field_summary( array $field ) {
    $summary = array(
        'name'  => $field['name'],
        'label' => $field['label'],
        'type'  => $field['type'],
        'key'   => $field['key'],
    );
    if ( ! empty( $field['instructions'] ) ) {
        $summary['instructions'] = $field['instructions'];
    }
    if ( ! empty( $field['choices'] ) ) {
        $summary['choices'] = $field['choices'];
    }
    if ( ! empty( $field['sub_fields'] ) ) {
        $summary['sub_fields'] = array_map( 'snn_mcp_acf_field_summary', $field['sub_fields'] );
    }
    if ( ! empty( $field['layouts'] ) ) {
        $summary['layouts'] = array();
        foreach ( $field['layouts'] as $layout ) {
            $summary['layouts'][] = array(
                'name'       => $layout['name'],
                'label'      => $layout['label'],
                'sub_fields' => array_map( 'snn_mcp_acf_field_summary', (array) ( $layout['sub_fields'] ?? array() ) ),
            );
        }
    }
    return $summary;
}

function snn_mcp_acf_list_field_groups() {
    $groups = array();
    foreach ( acf_get_field_groups() as $group ) {
        $groups[] = array(
            'key'      => $group['key'],
            'title'    => $group['title'],
            'active'   => (bool) $group['active'],
            'location' => $group['location'],
            'fields'   => array_map( 'snn_mcp_acf_field_summary', (array) acf_get_fields( $group ) ),
        );
    }
    return array( 'field_groups' => $groups );
}

/**
 * @return string|WP_Error ACF object id ("123" or "term_45").
 */
function snn_mcp_acf_object_id( array $input ) {
    if ( 'term' === ( $input['object_type'] ?? 'post' ) ) {
        $term = get_term( absint( $input['id'] ), (string) ( $input['taxonomy'] ?? '' ) );
        if ( ! $term || is_wp_error( $term ) || ! snn_mcp_taxonomy_ok( $term->taxonomy ) ) {
            return new WP_Error( 'snn_mcp_no_term', 'Term not found (taxonomy is required for terms).' );
        }
        return 'term_' . $term->term_id;
    }
    $post = snn_mcp_get_allowed_post( $input['id'] );
    return $post ? (string) $post->ID : new WP_Error( 'snn_mcp_no_post', 'Post not found or not an allowed type.' );
}

function snn_mcp_acf_permission( $input ) {
    if ( ! function_exists( 'acf_get_field_groups' ) ) {
        return false;
    }
    if ( 'term' === ( $input['object_type'] ?? 'post' ) ) {
        $term = get_term( absint( $input['id'] ?? 0 ), (string) ( $input['taxonomy'] ?? '' ) );
        return $term && ! is_wp_error( $term ) && snn_mcp_taxonomy_ok( $term->taxonomy ) && current_user_can( 'edit_term', $term->term_id );
    }
    $post = snn_mcp_get_allowed_post( $input['id'] ?? 0 );
    return $post && current_user_can( 'edit_post', $post->ID );
}

function snn_mcp_acf_get_fields( $input ) {
    $acf_id = snn_mcp_acf_object_id( $input );
    if ( is_wp_error( $acf_id ) ) {
        return $acf_id;
    }
    $fields = array();
    foreach ( (array) get_field_objects( $acf_id, false ) as $name => $field ) {
        $fields[ $name ] = array(
            'label' => $field['label'],
            'type'  => $field['type'],
            'value' => $field['value'],
        );
    }
    return array( 'object' => $acf_id, 'fields' => $fields );
}

/**
 * Only fields from groups that actually apply to this object can be written.
 *
 * @return array name => field
 */
function snn_mcp_acf_writable_fields( $acf_id, array $input ) {
    $filter = ( 0 === strpos( $acf_id, 'term_' ) )
        ? array( 'taxonomy' => (string) $input['taxonomy'] )
        : array( 'post_id' => (int) $acf_id );
    $fields = array();
    foreach ( acf_get_field_groups( $filter ) as $group ) {
        foreach ( (array) acf_get_fields( $group ) as $field ) {
            $fields[ $field['name'] ] = $field;
        }
    }
    return $fields;
}

function snn_mcp_kses_deep( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'snn_mcp_kses_deep', $value );
    }
    return is_string( $value ) ? wp_kses_post( $value ) : $value;
}

function snn_mcp_acf_sanitize_value( array $field, $value ) {
    switch ( $field['type'] ) {
        case 'email':
            return sanitize_email( (string) $value );
        case 'url':
            return esc_url_raw( (string) $value );
        case 'number':
        case 'range':
            return is_numeric( $value ) ? $value + 0 : '';
        case 'true_false':
            return $value ? 1 : 0;
        default:
            // ACF stores raw; no script tags in any text, however deep.
            return snn_mcp_kses_deep( $value );
    }
}

function snn_mcp_acf_update_fields( $input ) {
    $acf_id = snn_mcp_acf_object_id( $input );
    if ( is_wp_error( $acf_id ) ) {
        return $acf_id;
    }
    $writable = snn_mcp_acf_writable_fields( $acf_id, $input );
    $updated  = array();
    $skipped  = array();
    foreach ( (array) $input['fields'] as $name => $value ) {
        if ( ! isset( $writable[ $name ] ) ) {
            $skipped[] = "{$name}: not a field of this object";
            continue;
        }
        $field = $writable[ $name ];
        if ( update_field( $field['key'], snn_mcp_acf_sanitize_value( $field, $value ), $acf_id ) ) {
            $updated[] = $name;
        } else {
            // update_field() also returns false when the value is unchanged.
            $skipped[] = "{$name}: not changed";
        }
    }
    return array( 'object' => $acf_id, 'updated' => $updated, 'skipped' => $skipped );
}

// ----------------------------------------------------------------------
// Bricks (read-only)
// ----------------------------------------------------------------------

function snn_mcp_bricks_get_page_content( $input ) {
    $post     = snn_mcp_get_allowed_post( $input['id'] );
    $elements = get_post_meta( $post->ID, snn_mcp_bricks_meta_key(), true );
    if ( empty( $elements ) || ! is_array( $elements ) ) {
        return array( 'id' => $post->ID, 'uses_bricks' => false, 'elements' => array() );
    }

    $summary = array();
    foreach ( $elements as $element ) {
        $texts = array();
        foreach ( (array) ( $element['settings'] ?? array() ) as $key => $value ) {
            // Keys starting with "_" are Bricks style settings, not content.
            if ( is_string( $value ) && '' !== trim( $value ) && 0 !== strpos( (string) $key, '_' ) ) {
                $texts[ $key ] = mb_substr( $value, 0, 1000 );
            }
        }
        $summary[] = array(
            'id'     => $element['id'] ?? '',
            'name'   => $element['name'] ?? '',
            'parent' => $element['parent'] ?? 0,
            'label'  => $element['label'] ?? '',
            'text'   => $texts,
        );
    }

    $result = array( 'id' => $post->ID, 'uses_bricks' => true, 'elements' => $summary );
    if ( ! empty( $input['include_raw'] ) ) {
        $result['raw'] = $elements;
    }
    return $result;
}

// ----------------------------------------------------------------------
// SEOPress global settings (titles, social, sitemaps)
// ----------------------------------------------------------------------

/**
 * The SEOPress options the AI may change, keyed by the ability suffix. Each
 * maps to its option name and the prefix every key in it must carry.
 * Left out on purpose: Instant Indexing (API keys), Analytics (tracking
 * scripts end up in the page unescaped), Advanced and the Pro/license options.
 */
function snn_mcp_seopress_option_map() {
    return array(
        'titles'  => array( 'option' => 'seopress_titles_option_name', 'prefix' => 'seopress_titles_' ),
        'social'  => array( 'option' => 'seopress_social_option_name', 'prefix' => 'seopress_social_' ),
        'sitemap' => array( 'option' => 'seopress_xml_sitemap_option_name', 'prefix' => 'seopress_xml_sitemap_' ),
    );
}

/**
 * Plain text only: tags stripped, max 2000 chars. Not sanitize_text_field(),
 * which strips "%xx" sequences and would break templates like %%date%%.
 * true -> "1" (SEOPress checkboxes), null/false -> remove the key (= off).
 *
 * @return array|string|null|WP_Error
 */
function snn_mcp_seopress_clean_value( $value, $depth = 0 ) {
    if ( null === $value || false === $value ) {
        return null;
    }
    if ( true === $value ) {
        return '1';
    }
    if ( is_int( $value ) || is_float( $value ) ) {
        return (string) $value;
    }
    if ( is_string( $value ) ) {
        $value = wp_check_invalid_utf8( $value );
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', wp_strip_all_tags( $value ) );
        return mb_substr( trim( $value ), 0, 2000 );
    }
    if ( is_array( $value ) ) {
        if ( $depth >= 2 ) {
            return new WP_Error( 'snn_mcp_too_deep', 'Settings are nested too deeply.' );
        }
        $clean = array();
        foreach ( $value as $key => $item ) {
            if ( ! preg_match( '/^[a-z0-9_-]{1,64}$/', (string) $key ) ) {
                return new WP_Error( 'snn_mcp_bad_key', sprintf( 'Invalid key "%s".', mb_substr( (string) $key, 0, 64 ) ) );
            }
            $item = snn_mcp_seopress_clean_value( $item, $depth + 1 );
            if ( is_wp_error( $item ) ) {
                return $item;
            }
            $clean[ $key ] = $item;
        }
        return $clean;
    }
    return new WP_Error( 'snn_mcp_bad_value', 'Unsupported value type.' );
}

/**
 * Merges $changes into $current. Nested arrays are merged key by key, so
 * changing one post type's title leaves the others alone; null removes a key.
 */
function snn_mcp_seopress_merge( array $current, array $changes ) {
    foreach ( $changes as $key => $value ) {
        if ( null === $value ) {
            unset( $current[ $key ] );
        } elseif ( is_array( $value ) ) {
            $current[ $key ] = snn_mcp_seopress_merge( is_array( $current[ $key ] ?? null ) ? $current[ $key ] : array(), $value );
        } else {
            $current[ $key ] = $value;
        }
    }
    return $current;
}

function snn_mcp_seopress_update_settings( $which, array $input ) {
    $map = snn_mcp_seopress_option_map()[ $which ];
    if ( empty( $input['settings'] ) || ! is_array( $input['settings'] ) ) {
        return new WP_Error( 'snn_mcp_empty', 'Pass at least one setting to change.' );
    }
    if ( count( $input['settings'] ) > 100 ) {
        return new WP_Error( 'snn_mcp_too_many', 'At most 100 settings per call.' );
    }

    $changes = array();
    foreach ( $input['settings'] as $key => $value ) {
        $key = (string) $key;
        if ( 0 !== strpos( $key, $map['prefix'] ) || ! preg_match( '/^[a-z0-9_]{1,100}$/', $key ) ) {
            return new WP_Error( 'snn_mcp_bad_key', sprintf( 'Key "%1$s" is not allowed here. Keys must start with "%2$s".', mb_substr( $key, 0, 100 ), $map['prefix'] ) );
        }
        $value = snn_mcp_seopress_clean_value( $value );
        if ( is_wp_error( $value ) ) {
            return $value;
        }
        $changes[ $key ] = $value;
    }

    $current = get_option( $map['option'], array() );
    $current = is_array( $current ) ? $current : array();
    $updated = snn_mcp_seopress_merge( $current, $changes );
    update_option( $map['option'], $updated );

    if ( 'sitemap' === $which ) {
        // SEOPress adds its sitemap rewrite rules on init from this option;
        // dropping the cached rules makes the next request rebuild them.
        delete_option( 'rewrite_rules' );
    }

    $result = array();
    foreach ( array_keys( $changes ) as $key ) {
        $result[ $key ] = $updated[ $key ] ?? null;
    }
    return array( 'updated' => $result );
}

/**
 * The guard denies manage_options during MCP requests, so current_user_can()
 * would always say no here. These abilities are the one deliberate exception:
 * they check the user's own stored capability instead -- only someone who may
 * change SEOPress settings in wp-admin may let the AI do it.
 */
function snn_mcp_seopress_settings_permission() {
    $user = wp_get_current_user();
    return $user->exists() && ( ! empty( $user->allcaps['manage_options'] ) || is_super_admin( $user->ID ) );
}

// ----------------------------------------------------------------------
// Register everything
// ----------------------------------------------------------------------

function snn_mcp_register_abilities() {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }

    $id_prop      = array( 'id' => snn_mcp_int_prop( 'ID.' ) );
    $can_edit     = function ( $input ) {
        $post = snn_mcp_get_allowed_post( $input['id'] ?? 0 );
        return $post && current_user_can( 'edit_post', $post->ID );
    };
    $can_media    = function ( $input, $cap = 'edit_post' ) {
        $post = snn_mcp_get_attachment( $input['id'] ?? 0 );
        return $post && current_user_can( $cap, $post->ID );
    };
    $can_menus    = function () {
        return current_user_can( 'edit_theme_options' );
    };
    $can_comments = function ( $input ) {
        if ( isset( $input['id'] ) ) {
            return get_comment( absint( $input['id'] ) ) && current_user_can( 'edit_comment', absint( $input['id'] ) );
        }
        return current_user_can( 'moderate_comments' );
    };

    // --- Posts, pages, custom post types ---

    snn_mcp_add_ability( 'snn-wp/list-post-types', 'snn-content', 'read',
        __( 'List post types', 'snn' ),
        'Lists the post types (posts, pages, custom post types) that can be read and edited, with their taxonomies.',
        array(), array(), 'snn_mcp_list_post_types',
        function () {
            return current_user_can( 'edit_posts' );
        }
    );

    snn_mcp_add_ability( 'snn-wp/list-posts', 'snn-content', 'read',
        __( 'List posts', 'snn' ),
        'Lists posts of one post type (post, page or a custom post type), newest first. Returns no content -- use snn-wp/get-post for that.',
        array_merge( array(
            'post_type' => snn_mcp_str_prop( 'Post type, default "post".' ),
            'status'    => snn_mcp_str_prop( 'Status filter, default "any" (everything except trash).', array( 'any', 'publish', 'draft', 'pending', 'future', 'private', 'trash' ) ),
            'search'    => snn_mcp_str_prop( 'Search term.' ),
            'orderby'   => snn_mcp_str_prop( 'Sort field.', array( 'date', 'modified', 'title', 'menu_order' ) ),
            'order'     => snn_mcp_str_prop( 'Sort direction.', array( 'ASC', 'DESC' ) ),
        ), snn_mcp_paging_props() ),
        array(), 'snn_mcp_list_posts',
        function ( $input ) {
            return snn_mcp_can_edit_type( $input['post_type'] ?? 'post' );
        }
    );

    snn_mcp_add_ability( 'snn-wp/get-post', 'snn-content', 'read',
        __( 'Get post', 'snn' ),
        'Gets one post/page/custom post with its full content, terms and featured image.',
        $id_prop, array( 'id' ),
        function ( $input ) {
            return array( 'post' => snn_mcp_format_post( snn_mcp_get_allowed_post( $input['id'] ), true ) );
        },
        $can_edit
    );

    snn_mcp_add_ability( 'snn-wp/create-post', 'snn-content', 'write',
        __( 'Create post', 'snn' ),
        'Creates a post, page or custom post. Saved as draft unless a status is given.',
        array_merge( array( 'post_type' => snn_mcp_str_prop( 'Post type, e.g. "post" or "page".' ) ), snn_mcp_post_write_props() ),
        array( 'post_type', 'title' ), 'snn_mcp_create_post',
        function ( $input ) {
            return snn_mcp_can_edit_type( $input['post_type'] ?? '' );
        }
    );

    snn_mcp_add_ability( 'snn-wp/update-post', 'snn-content', 'write',
        __( 'Update post', 'snn' ),
        'Changes a post, page or custom post. Only the given fields are changed. Bricks layouts are not affected.',
        array_merge( $id_prop, snn_mcp_post_write_props() ),
        array( 'id' ), 'snn_mcp_update_post', $can_edit
    );

    snn_mcp_add_ability( 'snn-wp/trash-post', 'snn-content', 'trash',
        __( 'Move post to trash', 'snn' ),
        'Moves a post, page or custom post to the trash. It can be restored in wp-admin. Nothing is deleted permanently.',
        $id_prop, array( 'id' ), 'snn_mcp_trash_post',
        function ( $input ) {
            $post = snn_mcp_get_allowed_post( $input['id'] ?? 0 );
            return $post && current_user_can( 'delete_post', $post->ID );
        }
    );

    // --- Media ---

    snn_mcp_add_ability( 'snn-wp/list-media', 'snn-content', 'read',
        __( 'List media', 'snn' ),
        'Lists files in the media library, newest first.',
        array_merge( array(
            'search'    => snn_mcp_str_prop( 'Search term.' ),
            'mime_type' => snn_mcp_str_prop( 'MIME filter, e.g. "image" or "application/pdf".' ),
        ), snn_mcp_paging_props() ),
        array(), 'snn_mcp_list_media',
        function () {
            return current_user_can( 'upload_files' );
        }
    );

    snn_mcp_add_ability( 'snn-wp/get-media', 'snn-content', 'read',
        __( 'Get media', 'snn' ),
        'Gets one media file with URL, alt text, caption and size.',
        $id_prop, array( 'id' ),
        function ( $input ) {
            return array( 'media' => snn_mcp_format_media( snn_mcp_get_attachment( $input['id'] ) ) );
        },
        $can_media
    );

    snn_mcp_add_ability( 'snn-wp/update-media', 'snn-content', 'write',
        __( 'Update media', 'snn' ),
        'Changes title, alt text, caption or description of a media file.',
        array_merge( $id_prop, array(
            'title'       => snn_mcp_str_prop( 'Title.' ),
            'alt'         => snn_mcp_str_prop( 'Alt text.' ),
            'caption'     => snn_mcp_str_prop( 'Caption.' ),
            'description' => snn_mcp_str_prop( 'Description.' ),
        ) ),
        array( 'id' ), 'snn_mcp_update_media', $can_media
    );

    snn_mcp_add_ability( 'snn-wp/upload-media-from-url', 'snn-content', 'write',
        __( 'Upload media from URL', 'snn' ),
        'Downloads an image or PDF from a public URL (max 20 MB) into the media library.',
        array(
            'url'     => snn_mcp_str_prop( 'Public http(s) URL ending in the file name, e.g. https://example.com/photo.jpg.' ),
            'title'   => snn_mcp_str_prop( 'Title.' ),
            'alt'     => snn_mcp_str_prop( 'Alt text.' ),
            'post_id' => snn_mcp_int_prop( 'Attach the file to this post.' ),
        ),
        array( 'url' ), 'snn_mcp_upload_media_from_url',
        function ( $input ) {
            if ( ! current_user_can( 'upload_files' ) ) {
                return false;
            }
            if ( ! empty( $input['post_id'] ) ) {
                $post = snn_mcp_get_allowed_post( $input['post_id'] );
                return $post && current_user_can( 'edit_post', $post->ID );
            }
            return true;
        }
    );

    snn_mcp_add_ability( 'snn-wp/trash-media', 'snn-content', 'trash',
        __( 'Move media to trash', 'snn' ),
        'Moves a media file to the trash. Only works when the media trash is enabled (MEDIA_TRASH); otherwise it refuses, because deleting would be permanent.',
        $id_prop, array( 'id' ), 'snn_mcp_trash_media',
        function ( $input ) use ( $can_media ) {
            return $can_media( $input, 'delete_post' );
        }
    );

    // --- Taxonomies ---

    snn_mcp_add_ability( 'snn-wp/list-taxonomies', 'snn-content', 'read',
        __( 'List taxonomies', 'snn' ),
        'Lists taxonomies (categories, tags, custom taxonomies) and which post types use them.',
        array(), array(), 'snn_mcp_list_taxonomies',
        function () {
            return current_user_can( 'edit_posts' );
        }
    );

    snn_mcp_add_ability( 'snn-wp/list-terms', 'snn-content', 'read',
        __( 'List terms', 'snn' ),
        'Lists the terms of one taxonomy, e.g. all categories.',
        array_merge( array(
            'taxonomy' => snn_mcp_str_prop( 'Taxonomy name, e.g. "category" or "post_tag".' ),
            'search'   => snn_mcp_str_prop( 'Search term.' ),
            'parent'   => snn_mcp_int_prop( 'Only children of this term ID (0 = top level).', 0 ),
        ), snn_mcp_paging_props() ),
        array( 'taxonomy' ), 'snn_mcp_list_terms',
        function ( $input ) {
            return snn_mcp_taxonomy_ok( $input['taxonomy'] ?? '' ) && current_user_can( 'edit_posts' );
        }
    );

    $term_props = array(
        'taxonomy'    => snn_mcp_str_prop( 'Taxonomy name.' ),
        'name'        => snn_mcp_str_prop( 'Name.' ),
        'slug'        => snn_mcp_str_prop( 'Slug.' ),
        'description' => snn_mcp_str_prop( 'Description.' ),
        'parent'      => snn_mcp_int_prop( 'Parent term ID (hierarchical taxonomies), 0 for none.', 0 ),
    );

    snn_mcp_add_ability( 'snn-wp/create-term', 'snn-content', 'write',
        __( 'Create term', 'snn' ),
        'Creates a category, tag or custom taxonomy term.',
        $term_props, array( 'taxonomy', 'name' ), 'snn_mcp_create_term',
        function ( $input ) {
            return snn_mcp_term_permission( $input, 'edit_terms' );
        }
    );

    snn_mcp_add_ability( 'snn-wp/update-term', 'snn-content', 'write',
        __( 'Update term', 'snn' ),
        'Changes name, slug, description or parent of a term.',
        array_merge( $id_prop, $term_props ), array( 'id', 'taxonomy' ), 'snn_mcp_update_term',
        function ( $input ) {
            return snn_mcp_term_permission( $input, 'edit_term' );
        }
    );

    // --- Menus ---

    snn_mcp_add_ability( 'snn-wp/list-menus', 'snn-content', 'read',
        __( 'List menus', 'snn' ),
        'Lists navigation menus and the theme locations they are assigned to.',
        array(), array(), 'snn_mcp_list_menus', $can_menus
    );

    snn_mcp_add_ability( 'snn-wp/get-menu', 'snn-content', 'read',
        __( 'Get menu', 'snn' ),
        'Gets all items of one navigation menu.',
        array( 'menu_id' => snn_mcp_int_prop( 'Menu ID.' ) ), array( 'menu_id' ), 'snn_mcp_get_menu', $can_menus
    );

    snn_mcp_add_ability( 'snn-wp/add-menu-item', 'snn-content', 'write',
        __( 'Add menu item', 'snn' ),
        'Adds a link to a navigation menu: a custom URL, a post/page or a term.',
        array_merge( array( 'menu_id' => snn_mcp_int_prop( 'Menu ID.' ) ), snn_mcp_menu_item_props() ),
        array( 'menu_id', 'object_type' ), 'snn_mcp_add_menu_item', $can_menus
    );

    snn_mcp_add_ability( 'snn-wp/update-menu-item', 'snn-content', 'write',
        __( 'Update menu item', 'snn' ),
        'Changes a navigation menu item (label, link, parent, position). Only the given fields are changed.',
        array_merge( array(
            'menu_id' => snn_mcp_int_prop( 'Menu ID.' ),
            'item_id' => snn_mcp_int_prop( 'Menu item ID.' ),
        ), snn_mcp_menu_item_props() ),
        array( 'menu_id', 'item_id' ), 'snn_mcp_update_menu_item', $can_menus
    );

    // --- Comments ---

    snn_mcp_add_ability( 'snn-wp/list-comments', 'snn-content', 'read',
        __( 'List comments', 'snn' ),
        'Lists comments, newest first. Filter by status or post.',
        array_merge( array(
            'status'  => snn_mcp_str_prop( 'Status filter, default "all".', array( 'all', 'hold', 'approve', 'spam', 'trash' ) ),
            'post_id' => snn_mcp_int_prop( 'Only comments of this post.' ),
            'search'  => snn_mcp_str_prop( 'Search term.' ),
        ), snn_mcp_paging_props() ),
        array(), 'snn_mcp_list_comments', $can_comments
    );

    snn_mcp_add_ability( 'snn-wp/moderate-comment', 'snn-content', 'trash',
        __( 'Moderate comment', 'snn' ),
        'Approves a comment, holds it, marks it as spam, or moves it to the trash.',
        array_merge( $id_prop, array( 'action' => snn_mcp_str_prop( 'What to do.', array( 'approve', 'hold', 'spam', 'trash' ) ) ) ),
        array( 'id', 'action' ), 'snn_mcp_moderate_comment', $can_comments
    );

    snn_mcp_add_ability( 'snn-wp/reply-comment', 'snn-content', 'write',
        __( 'Reply to comment', 'snn' ),
        'Posts an approved reply to a comment, as the connected user.',
        array_merge( $id_prop, array( 'content' => snn_mcp_str_prop( 'Reply text.' ) ) ),
        array( 'id', 'content' ), 'snn_mcp_reply_comment',
        function ( $input ) use ( $can_comments ) {
            return $can_comments( $input ) && current_user_can( 'moderate_comments' );
        }
    );

    // --- ACF ---

    if ( function_exists( 'acf_get_field_groups' ) ) {
        $acf_target = array(
            'object_type' => snn_mcp_str_prop( 'Where the fields live, default "post".', array( 'post', 'term' ) ),
            'id'          => snn_mcp_int_prop( 'Post ID or term ID.' ),
            'taxonomy'    => snn_mcp_str_prop( 'Taxonomy name (required when object_type is "term").' ),
        );

        snn_mcp_add_ability( 'snn-acf/list-field-groups', 'snn-acf', 'read',
            __( 'List ACF field groups', 'snn' ),
            'Lists all ACF field groups with their fields (name, type, choices) and location rules.',
            array(), array(), 'snn_mcp_acf_list_field_groups',
            function () {
                return current_user_can( 'edit_posts' );
            }
        );

        snn_mcp_add_ability( 'snn-acf/get-fields', 'snn-acf', 'read',
            __( 'Get ACF field values', 'snn' ),
            'Gets all ACF field values (raw, unformatted) of a post or term.',
            $acf_target, array( 'id' ), 'snn_mcp_acf_get_fields', 'snn_mcp_acf_permission'
        );

        snn_mcp_add_ability( 'snn-acf/update-fields', 'snn-acf', 'write',
            __( 'Update ACF field values', 'snn' ),
            'Sets ACF field values on a post or term. Only fields from field groups assigned to that object can be written. Pass raw values (e.g. image = attachment ID).',
            array_merge( $acf_target, array(
                'fields' => array(
                    'type'        => 'object',
                    'description' => 'Field name => new value, e.g. {"subtitle": "Hello", "rating": 4}.',
                ),
            ) ),
            array( 'id', 'fields' ), 'snn_mcp_acf_update_fields', 'snn_mcp_acf_permission'
        );
    }

    // --- Bricks ---

    snn_mcp_add_ability( 'snn-bricks/get-page-content', 'snn-bricks', 'read',
        __( 'Read Bricks layout', 'snn' ),
        'Reads the Bricks Builder layout of a page: every element with its text content. Read-only -- layouts cannot be changed through MCP.',
        array_merge( $id_prop, array(
            'include_raw' => array( 'type' => 'boolean', 'description' => 'Also return the raw Bricks element data (large).' ),
        ) ),
        array( 'id' ), 'snn_mcp_bricks_get_page_content', $can_edit
    );

    // --- SEOPress global settings ---

    if ( defined( 'SEOPRESS_VERSION' ) ) {
        $seopress = array(
            'titles'  => array(
                __( 'Change SEOPress titles & metas', 'snn' ),
                'Changes the site-wide SEOPress title and meta description settings (option seopress_titles_option_name): separator, home page title/description, title/description templates per post type (seopress_titles_single_titles), archive, taxonomy (seopress_titles_tax_titles) and special pages, and their noindex flags. Read the current values with seopress/get-global-titles-settings first.',
            ),
            'social'  => array(
                __( 'Change SEOPress social settings', 'snn' ),
                'Changes the site-wide SEOPress social settings (option seopress_social_option_name): Knowledge Graph identity (type, name, logo, contact), social profile URLs, default Open Graph and X/Twitter card settings. Read the current values with seopress/get-global-social-settings first.',
            ),
            'sitemap' => array(
                __( 'Change SEOPress sitemap settings', 'snn' ),
                'Changes the SEOPress XML/HTML sitemap settings (option seopress_xml_sitemap_option_name): enabling sitemaps, which post types and taxonomies are included, HTML sitemap options. Read the current values with seopress/get-sitemap-settings first.',
            ),
        );
        foreach ( $seopress as $which => $texts ) {
            $prefix = snn_mcp_seopress_option_map()[ $which ]['prefix'];
            snn_mcp_add_ability( 'snn-seo/update-' . $which . '-settings', 'snn-seo', 'write',
                $texts[0],
                $texts[1] . ' Only the keys you pass change; nested objects are merged key by key. Checkboxes: "1" = on, null = off (removes the key).',
                array(
                    'settings' => array(
                        'type'        => 'object',
                        'description' => 'Setting key => new value. Keys must start with "' . $prefix . '", e.g. {"' . $prefix . '...": "value"}. Values: text, "1", null, or an object for nested settings (max 2 levels).',
                    ),
                ),
                array( 'settings' ),
                function ( $input ) use ( $which ) {
                    return snn_mcp_seopress_update_settings( $which, (array) $input );
                },
                'snn_mcp_seopress_settings_permission'
            );
        }
    }
}
add_action( 'wp_abilities_api_init', 'snn_mcp_register_abilities' );
