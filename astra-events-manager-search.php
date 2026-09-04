
<?php
/**
 * Plugin Name: Astra Events Manager Search
 * Description: Adds Events Manager events to Astra normal and live search.
 * Version: 2.0.0
 * Author: Rob
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the plugin.
 *
 * We use 'init' because Events Manager needs to have registered
 * its event post type before we can modify it.
 */
function rws_astra_em_search_init() {

	/*
	 * Make sure Astra is the active parent theme.
	 *
	 * get_template() returns the parent theme even when an
	 * Astra child theme is active.
	 */
	if ( 'astra' !== get_template() ) {
		return;
	}

	/*
	 * Make sure Events Manager is active.
	 */
	if ( ! defined( 'EM_POST_TYPE_EVENT' ) ) {
		return;
	}

	/*
	 * Get the Events Manager event post type object.
	 */
	$post_type = get_post_type_object( EM_POST_TYPE_EVENT );

	if ( ! $post_type ) {
		return;
	}

    /*
	 * Normal WordPress search.
	 *
	 * This handles searches where the visitor enters a search term
	 * and presses Enter.
	 */
	add_action( 'pre_get_posts', 'rws_astra_em_modify_search_query' );

	/*
	 * Astra's Live Search uses the WordPress REST API.
	 *
	 * WordPress's REST Posts Controller will only return a post
	 * if its post type has show_in_rest enabled.
	 *
	 * Events Manager does not enable REST support for its event
	 * post type, so enable it here.
	 */
	$post_type->show_in_rest = true;
}

/**
 * Add Events Manager events to the normal WordPress search.
 *
 * @param WP_Query $query The WordPress query.
 */
function rws_astra_em_modify_search_query( $query ) {

	/*
	 * Only affect the main front-end search query.
	 */
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}

	/*
	 * Get the existing post types.
	 *
	 * WordPress normally searches posts/pages depending on the
	 * site's configuration. Preserve those and add Events Manager.
	 */
	$post_types = $query->get( 'post_type' );

	if ( empty( $post_types ) ) {
		/*
		 * If no post_type was explicitly specified, let WordPress's
		 * normal search behavior remain intact by adding "event"
		 * to the default searchable post types.
		 */
		$post_types = array( 'post', 'page' );
	} elseif ( ! is_array( $post_types ) ) {
		$post_types = array( $post_types );
	}

	$post_types[] = EM_POST_TYPE_EVENT;

	/*
	 * Remove duplicates while preserving the existing post types.
	 */
	$post_types = array_values( array_unique( $post_types ) );

	$query->set( 'post_type', $post_types );
}

add_action( 'init', 'rws_astra_em_search_init', 20 );

/*
add_action( 'wp_footer', function() {
    // Debugging event meta values.

    $event_id = 12; // Change this to a real event ID.

    if ( get_post_type( $event_id ) !== 'event' ) {
        return;
    }

    $start_date = get_post_meta( $event_id, '_event_start_date', true );
    $end_date   = get_post_meta( $event_id, '_event_end_date', true );

    echo '<pre>';
    echo 'Event ID: ' . $event_id . "\n";
    echo '_event_start_date: ' . var_export( $start_date, true ) . "\n";
    echo '_event_end_date:   ' . var_export( $end_date, true ) . "\n";
    echo '</pre>';

} );
*/

add_action( 'pre_get_posts', 'rws_exclude_past_events_from_search' );

function rws_exclude_past_events_from_search( $query ) {

    // Only affect frontend searches.
    if ( is_admin() || ! $query->is_search() ) {
        return;
    }

    $post_types = $query->get( 'post_type' );

    // Normalize post_type to an array when it is specified.
    if ( ! empty( $post_types ) && ! is_array( $post_types ) ) {
        $post_types = array( $post_types );
    }

    /*
     * If post_type is explicitly specified and does not include
     * Events Manager's 'event' post type, leave the query alone.
     *
     * If post_type is empty, this is a normal WordPress search.
     * Events Manager events can still be included in that search,
     * so we need to apply the filter.
     */
    if (
        ! empty( $post_types )
        && ! in_array( 'event', $post_types, true )
    ) {
        return;
    }

	$today = current_time( 'Y-m-d' );

	$meta_query = $query->get( 'meta_query' );

	if ( empty( $meta_query ) || ! is_array( $meta_query ) ) {
		$meta_query = array();
	}

	$event_filter = array(
        'relation' => 'OR',

        // Keep non-Events Manager posts/pages.
        array(
            'key'     => '_event_end_date',
            'compare' => 'NOT EXISTS',
        ),

        // Keep Events Manager events that have not ended.
        array(
            'key'     => '_event_end_date',
            'value'   => $today,
            'compare' => '>=',
            'type'    => 'DATE',
        ),
    );

	// Avoid adding the same filter multiple times if this callback runs twice.
	foreach ( $meta_query as $existing_clause ) {
		if ( is_array( $existing_clause ) && $existing_clause === $event_filter ) {
			$query->set( 'meta_query', $meta_query );
			return;
		}
	}

	$meta_query[] = $event_filter;

    $query->set( 'meta_query', $meta_query );
}
