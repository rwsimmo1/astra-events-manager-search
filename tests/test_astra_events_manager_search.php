<?php
/**
 * Tests for Astra Events Manager Search.
 *
 * @package Astra_Events_Manager_Search
 */

class test_astra_events_manager_search extends WP_UnitTestCase {

    /**
     * Set up the test environment.
     */
    public function setUp(): void {
        parent::setUp();

        // The plugin expects this constant to identify the Events Manager
        // event post type.
        if ( ! defined( 'EM_POST_TYPE_EVENT' ) ) {
            define( 'EM_POST_TYPE_EVENT', 'event' );
        }

        // Make sure the event post type exists for tests involving it.
        if ( ! post_type_exists( 'event' ) ) {
            register_post_type(
                'event',
                array(
                    'public'       => true,
                    'exclude_from_search' => false,
                    'show_in_rest' => false,
                )
            );
        }

        $event_post_type = get_post_type_object( 'event' );

        if ( $event_post_type ) {
            $event_post_type->public             = true;
            $event_post_type->publicly_queryable = true;
            $event_post_type->exclude_from_search = false;
        }
    }

    /**
     * Run a callback with the provided query as the global main query.
     *
     * @param WP_Query $query    Query instance to treat as main query.
     * @param callable $callback Callback to execute.
     */
    private function with_main_query( WP_Query $query, callable $callback ): void {

        $original_wp_the_query = $GLOBALS['wp_the_query'] ?? null;
        $original_wp_query     = $GLOBALS['wp_query'] ?? null;
        $original_current_screen = $GLOBALS['current_screen'] ?? null;

        if ( function_exists( 'set_current_screen' ) ) {
            set_current_screen( 'front' );
        }

        $GLOBALS['wp_the_query'] = $query;
        $GLOBALS['wp_query']     = $query;

        try {
            $callback( $query );
        } finally {
            $GLOBALS['wp_the_query'] = $original_wp_the_query;
            $GLOBALS['wp_query']     = $original_wp_query;
            $GLOBALS['current_screen'] = $original_current_screen;
        }
    }

    /**
     * Assert that post type arrays contain the same values regardless of order.
     *
     * @param array $expected Expected post types.
     * @param mixed $actual   Actual query post_type value.
     */
    private function assert_same_post_types( array $expected, $actual ): void {

        $this->assertIsArray( $actual );

        $expected_sorted = $expected;
        $actual_sorted   = $actual;

        sort( $expected_sorted );
        sort( $actual_sorted );

        $this->assertSame( $expected_sorted, $actual_sorted );
    }

    /**
     * Run a callback in frontend screen context.
     *
     * @param callable $callback Callback to execute.
     */
    private function with_frontend_screen( callable $callback ): void {

        $original_current_screen = $GLOBALS['current_screen'] ?? null;

        if ( function_exists( 'set_current_screen' ) ) {
            set_current_screen( 'front' );
        }

        try {
            $callback();
        } finally {
            $GLOBALS['current_screen'] = $original_current_screen;
        }
    }

    /**
     * Test that a normal search with no post_type gets post, page, and event.
     */
    public function test_modify_search_query_adds_event_to_default_post_types() {

        $query = new WP_Query(
            array(
                's' => 'test',
            )
        );

        // Force the query state needed by the function.
        $query->is_search = true;

        // In unit tests, WP_Query may normalize search post_type to 'any'.
        // Clear it so this test covers the "no explicit post_type" branch.
        $query->set( 'post_type', '' );

        $this->with_main_query(
            $query,
            function ( WP_Query $main_query ) {
                rws_astra_em_modify_search_query( $main_query );
            }
        );

        $this->assert_same_post_types(
            array( 'post', 'page', 'event' ),
            $query->get( 'post_type' )
        );
    }

    /**
     * Test that a scalar post_type is converted to an array and event added.
     */
    public function test_modify_search_query_handles_scalar_post_type() {

        $query = new WP_Query(
            array(
                's'         => 'test',
                'post_type' => 'post',
            )
        );

        $query->is_search = true;

        $this->with_main_query(
            $query,
            function ( WP_Query $main_query ) {
                rws_astra_em_modify_search_query( $main_query );
            }
        );

        $this->assert_same_post_types(
            array( 'post', 'event' ),
            $query->get( 'post_type' )
        );
    }

    /**
     * Test that an existing array of post types is preserved.
     */
    public function test_modify_search_query_preserves_existing_post_types() {

        $query = new WP_Query(
            array(
                's'         => 'test',
                'post_type' => array( 'post', 'page' ),
            )
        );

        $query->is_search = true;

        $this->with_main_query(
            $query,
            function ( WP_Query $main_query ) {
                rws_astra_em_modify_search_query( $main_query );
            }
        );

        $this->assert_same_post_types(
            array( 'post', 'page', 'event' ),
            $query->get( 'post_type' )
        );
    }

    /**
     * Test that event is not added twice.
     */
    public function test_modify_search_query_removes_duplicate_event() {

        $query = new WP_Query(
            array(
                's'         => 'test',
                'post_type' => array( 'post', 'event' ),
            )
        );

        $query->is_search = true;

        $this->with_main_query(
            $query,
            function ( WP_Query $main_query ) {
                rws_astra_em_modify_search_query( $main_query );
            }
        );

        $this->assert_same_post_types(
            array( 'post', 'event' ),
            $query->get( 'post_type' )
        );
    }

    /**
     * Test that non-search queries are ignored.
     */
    public function test_modify_search_query_ignores_non_search() {

        $query = new WP_Query(
            array(
                'post_type' => 'post',
            )
        );

        $this->with_main_query(
            $query,
            function ( WP_Query $main_query ) {
                rws_astra_em_modify_search_query( $main_query );
            }
        );

        $this->assertSame(
            'post',
            $query->get( 'post_type' )
        );
    }

    /**
     * Test that the past-event filter adds the expected meta query.
     */
    public function test_exclude_past_events_adds_end_date_filter() {

        $query = new WP_Query(
            array(
                's' => 'test',
            )
        );

        $query->is_search = true;

         // In unit tests, WP_Query may normalize search post_type to 'any'.
        // Clear it so this test covers the "no explicit post_type" branch.
        $query->set( 'post_type', '' );

        // Make the query an event search.
        $query->set( 'post_type', ['post', 'page', 'event']);

        rws_exclude_past_events_from_search( $query );

        $meta_query = $query->get( 'meta_query' );

        $this->assertCount( 1, $meta_query );

        $filter = $meta_query[0];

        $this->assertSame( 'OR', $filter['relation'] );

        $this->assertSame(
            array(
                'key'     => '_event_end_date',
                'compare' => 'NOT EXISTS',
            ),
            $filter[0]
        );

        $this->assertSame(
            array(
                'key'     => '_event_end_date',
                'value'   => current_time( 'Y-m-d' ),
                'compare' => '>=',
                'type'    => 'DATE',
            ),
            $filter[1]
        );
    }

    /**
     * Test that an existing meta query is preserved.
     */
    public function test_exclude_past_events_preserves_existing_meta_query() {

        $existing_meta_query = array(
            array(
                'key'     => '_some_meta',
                'value'   => 'test',
                'compare' => '=',
            ),
        );

        $query = new WP_Query(
            array(
                's'         => 'test',
                'meta_query' => $existing_meta_query,
            )
        );

        $query->is_search = true;

        rws_exclude_past_events_from_search( $query );

        $meta_query = $query->get( 'meta_query' );

        $this->assertCount( 2, $meta_query );

        $this->assertSame(
            $existing_meta_query[0],
            $meta_query[0]
        );
    }

    /**
     * Test that a query explicitly excluding event is ignored.
     */
    public function test_exclude_past_events_ignores_non_event_post_type() {

        $query = new WP_Query(
            array(
                's'         => 'test',
                'post_type' => array( 'post', 'page' ),
            )
        );

        $query->is_search = true;

        rws_exclude_past_events_from_search( $query );

        $this->assertEmpty(
            $query->get( 'meta_query' )
        );
    }

    /**
     * Test that a scalar non-event post type is ignored.
     */
    public function test_exclude_past_events_ignores_scalar_non_event_post_type() {

        $query = new WP_Query(
            array(
                's'         => 'test',
                'post_type' => 'post',
            )
        );

        $query->is_search = true;

        rws_exclude_past_events_from_search( $query );

        $this->assertEmpty(
            $query->get( 'meta_query' )
        );
    }

    /**
     * Test that an event post type receives the filter.
     */
    public function test_exclude_past_events_accepts_event_post_type() {

        $query = new WP_Query(
            array(
                's'         => 'test',
                'post_type' => array( 'post', 'page', 'event' ),
            )
        );

        $query->is_search = true;

        rws_exclude_past_events_from_search( $query );

        $this->assertNotEmpty(
            $query->get( 'meta_query' )
        );
    }

    /**
     * Test that a non-search query is ignored.
     */
    public function test_exclude_past_events_ignores_non_search() {

        $query = new WP_Query(
            array(
                'post_type' => array( 'post', 'event' ),
            )
        );

        rws_exclude_past_events_from_search( $query );

        $this->assertEmpty(
            $query->get( 'meta_query' )
        );
    }

    /**
     * Integration test: a past event should be excluded.
     */
    public function test_past_event_is_excluded_from_search() {

        $event_id = self::factory()->post->create(
            array(
                'post_type' => 'event',
                'post_title' => 'Past Test Event',
                'post_status' => 'publish',
            )
        );

        update_post_meta(
            $event_id,
            '_event_end_date',
            '2026-01-01'
        );

        $this->with_frontend_screen(
            function () use ( $event_id ) {
                $query = new WP_Query(
                    array(
                        's'              => 'Past Test Event',
                        'post_type'      => array( 'event' ),
                        'posts_per_page' => -1,
                    )
                );

                $this->assertNotContains(
                    $event_id,
                    wp_list_pluck( $query->posts, 'ID' )
                );
            }
        );
    }

    /**
     * Integration test: a future event should remain in search.
     */
    public function test_future_event_is_included_in_search() {

        $event_id = self::factory()->post->create(
            array(
                'post_type'  => 'event',
                'post_title' => 'Future Test Event',
                'post_status' => 'publish',
            )
        );

        update_post_meta(
            $event_id,
            '_event_end_date',
            '2099-01-01'
        );

        $this->with_frontend_screen(
            function () use ( $event_id ) {
                $query = new WP_Query(
                    array(
                        's'              => 'Future Test Event',
                        'post_type'      => array( 'event' ),
                        'posts_per_page' => -1,
                    )
                );

                $this->assertContains(
                    $event_id,
                    wp_list_pluck( $query->posts, 'ID' )
                );
            }
        );
    }

    /**
     * Integration test: an event ending today should remain in search.
     */
    public function test_event_ending_today_is_included() {

        $event_id = self::factory()->post->create(
            array(
                'post_type'  => 'event',
                'post_title' => 'Today Test Event',
                'post_status' => 'publish',
            )
        );

        update_post_meta(
            $event_id,
            '_event_end_date',
            current_time( 'Y-m-d' )
        );

        $this->with_frontend_screen(
            function () use ( $event_id ) {
                $query = new WP_Query(
                    array(
                        's'              => 'Today Test Event',
                        'post_type'      => array( 'event' ),
                        'posts_per_page' => -1,
                    )
                );

                $this->assertContains(
                    $event_id,
                    wp_list_pluck( $query->posts, 'ID' )
                );
            }
        );
    }

    /**
     * Integration test: a multi-day event that started before today
     * but ends today or later should remain in search.
     */
    public function test_event_started_before_today_but_ends_today_is_included() {

        $event_id = self::factory()->post->create(
            array(
                'post_type'  => 'event',
                'post_title' => 'Multi Day Test Event',
                'post_status' => 'publish',
            )
        );

        $today = current_time( 'Y-m-d' );

        update_post_meta(
            $event_id,
            '_event_start_date',
            '2026-01-01'
        );

        update_post_meta(
            $event_id,
            '_event_end_date',
            $today
        );

        $this->with_frontend_screen(
            function () use ( $event_id ) {
                $query = new WP_Query(
                    array(
                        's'              => 'Multi Day Test Event',
                        'post_type'      => array( 'event' ),
                        'posts_per_page' => -1,
                    )
                );

                $this->assertContains(
                    $event_id,
                    wp_list_pluck( $query->posts, 'ID' )
                );
            }
        );
    }

    /**
     * Integration test: normal posts without event metadata remain searchable.
     */
    public function test_normal_post_without_event_metadata_is_included() {

        $post_id = self::factory()->post->create(
            array(
                'post_title' => 'Normal Test Post',
                'post_status' => 'publish',
            )
        );

        $this->with_frontend_screen(
            function () use ( $post_id ) {
                $query = new WP_Query(
                    array(
                        's'              => 'Normal Test Post',
                        'post_type'      => array( 'post', 'event' ),
                        'posts_per_page' => -1,
                    )
                );

                $this->assertContains(
                    $post_id,
                    wp_list_pluck( $query->posts, 'ID' )
                );
            }
        );
    }
}