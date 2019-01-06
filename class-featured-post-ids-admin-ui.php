<?php
/**
 * Admin UI for selecting featured post IDs for term archives
 */
class Featured_Post_IDs_Admin_UI {

	/**
	 * Name to store the term meta under
	 *
	 * @var string
	 */
	private static $term_meta_key = 'featured-post-ids';

	/**
	 * Get an instance of this class
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new static();
			$instance->setup_actions();
		}
		return $instance;
	}

	/**
	 * Hook in to WordPress via Actions
	 */
	public function setup_actions() {
		add_action( 'init', array( $this, 'action_init' ) );
		add_action( 'wp_ajax_featured-post-ids-search', array( $this, 'action_wp_ajax_featured_post_ids_search' ) );

		$taxonomies = get_object_taxonomies( 'post', 'objects' );
		if ( ! empty( $taxonomies ) ) {
			foreach ( $taxonomies as $tax ) {
				if ( ! $tax->publicly_queryable ) {
					continue;
				}
				add_action( $tax->name . '_edit_form_fields', array( $this, 'action_taxonomy_add_form_fields' ), 10, 2 );
				add_action( 'edited_' . $tax->name, array( $this, 'action_edited_taxonomy' ) );
				add_action( 'pre_get_posts', array( $this, 'action_pre_get_posts' ) );
			}
		}
	}

	/**
	 * Register scripts and styles
	 */
	public function action_init() {
		wp_register_script(
			'featured-post-ids-autocomplete',
			get_template_directory_uri() . 'featured-post-ids-autocomplete.src.js',
			$deps      = array(
				'jquery-ui-autocomplete',
				'jquery-ui-sortable',
			),
			$ver       = null,
			$in_footer = true
		);

		wp_register_style(
			'featured-post-ids-admin',
			get_template_directory_uri() . '/featured-post-ids.min.css',
			$deps  = array(),
			$ver   = null,
			$media = 'all'
		);
	}

	/**
	 * Add form to the edit term screen for selecting featured posts
	 * that are associated with that term
	 */
	public function action_taxonomy_add_form_fields() {
		global $taxnow;
		if ( empty( $_REQUEST['tag_ID'] ) ) {
			return;
		}

		// Check to make sure we have a term to work with
		$term_id = absint( $_REQUEST['tag_ID'] );
		$term    = get_term( $term_id, $taxnow );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$results        = '';
		$featured_posts = self::get_featured_posts( $term->term_id );
		foreach ( $featured_posts as $post ) {
			$results .= $this->get_featured_post_input_field( $post );
		}
		wp_enqueue_script( 'featured-post-ids-autocomplete' );
		wp_enqueue_style( 'featured-post-ids-admin' );
		$context = array(
			'admin_url'   => get_admin_url(),
			'taxonomy'    => $term->taxonomy,
			'term_id'     => $term->term_id,
			'term_name'   => $term->name,
			'nonce_field' => wp_nonce_field( 'featured-post-ids', 'featured-post-ids-nonce', $referer = true, $echo = false ),
			'results'     => $results,
		);
		Sprig::out( 'admin-form-fields.twig', $context );
	}

	/**
	 * Handle AJAX request for searching for posts
	 */
	public function action_wp_ajax_featured_post_ids_search() {
		if ( empty( $_REQUEST['q'] ) ) {
			wp_send_json_error( array(
				'label' => 'No search query provided!',
				'value' => 0,
			) );
			exit;
		}
		$search_query = sanitize_text_field( wp_unslash( $_REQUEST['q'] ) );

		if ( empty( $_REQUEST['taxonomy'] ) ) {
			wp_send_json_error( array(
				'label' => 'No taxonomy provided!',
				'value' => 0,
			) );
			exit;
		}
		$taxonomy = sanitize_text_field( wp_unslash( $_REQUEST['taxonomy'] ) );

		if ( empty( $_REQUEST['term_id'] ) ) {
			wp_send_json_error( array(
				'label' => 'No term ID provided!',
				'value' => 0,
			) );
			exit;
		}
		$term_id = absint( $_REQUEST['term_id'] );

		if (
			empty( $_REQUEST['nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'featured-post-ids' )
		) {
			wp_send_json_error( array(
				'label' => 'Bad nonce!',
				'value' => 0,
			) );
			exit;
		}

		if ( ! empty( $_REQUEST['excluded_ids'] ) ) {
			$excluded_ids = sanitize_text_field( wp_unslash( $_REQUEST['excluded_ids'] ) );
			$excluded_ids = explode( ',', $excluded_ids );
			$excluded_ids = array_map( 'absint', $excluded_ids );
		}
		if ( empty( $excluded_ids ) || ! is_array( $excluded_ids ) ) {
			$excluded_ids = array();
		}

		add_filter( 'posts_search', array( $this, 'filter_posts_search_only_search_post_titles' ) );

		$args = array(
			'posts_per_page' => 10,
			'post_type'      => array( 'post' ),
			's'              => '"' . $search_query . '"', // Force the search term in quotes so it is more accurate
			'post_status'    => array( 'publish' ),
			'post_parent'    => 0,
			'tax_query'      => array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		);
		if ( ! empty( $excluded_ids ) ) {
			$args['post__not_in'] = $excluded_ids;
		}
		$query = new WP_Query( $args );
		$posts = $query->posts;
		if ( empty( $posts ) ) {
			$error_message = sprintf(
				'No posts found with "%s" in the title',
				esc_html( $search_query )
			);
			wp_send_json_error( array(
				'label' => $error_message,
				'value' => 0,
			) );
			exit;
		}

		$output = array();
		foreach ( $posts as $p ) {
			$output[] = array(
				'label' => apply_filters( 'the_title', $p->post_title ),
				'value' => absint( $p->ID ),
				'html'  => $this->get_featured_post_input_field( $p ),
			);
		}
		wp_send_json_success( $output );
		exit;
	}

	/**
	 * Save featured post data to term meta as a comma separated string of ids
	 *
	 * Example: 1,2,3
	 */
	public function action_edited_taxonomy() {
		if ( empty( $_POST['tag_ID'] ) ) {
			return;
		}
		$term_id = absint( $_POST['tag_ID'] );

		if (
			empty( $_POST['featured-post-ids-nonce'] ) ||
			! check_admin_referer( 'featured-post-ids', 'featured-post-ids-nonce' )
		) {
			return;
		}

		if ( empty( $_POST['featured-post-ids-ids'] ) ) {
			delete_term_meta( $term_id, self::$term_meta_key );
			return;
		}

		$featured_ids_to_save = array_map( 'absint', wp_unslash( $_POST['featured-post-ids-ids'] ) );
		$featured_ids_to_save = implode( ',', $featured_ids_to_save );

		update_term_meta( $term_id, self::$term_meta_key, $featured_ids_to_save );
	}

	/**
	 * Filter out posts that are featured from the main query so they don't show twice
	 *
	 * @param  WP_Query $query The query object
	 */
	public function action_pre_get_posts( $query ) {
		if ( ! $query->is_main_query() ) {
			return;
		}

		// post__not_in is ignored when post__in is set
		if ( ! empty( $query->get( 'post__in' ) ) ) {
			return;
		}

		$queried_object = get_queried_object();
		if ( empty( $queried_object->term_id ) ) {
			return;
		}
		$term_id           = absint( $queried_object->term_id );
		$featured_post_ids = self::get_featured_post_ids( $term_id );
		if ( ! empty( $featured_post_ids ) ) {
			$query->set( 'post__not_in', $featured_post_ids );
		}
	}

	/**
	 * Only search post titles for a search query
	 *
	 * @param  string $where WHERE clause of a SQL query
	 * @return string        Modified WHERE clause
	 */
	public function filter_posts_search_only_search_post_titles( $where = '' ) {
		// Find the OR in the sql auery and return everything before OR
		$parts = explode( 'OR', $where );
		return rtrim( $parts[0] ) . '))';
	}

	/**
	 * Get the featured posts IDs
	 *
	 * @param  integer $term_id Term to get the featured post IDs for
	 * @return array            Featured post IDs or empty array
	 */
	public static function get_featured_post_ids( $term_id = 0 ) {
		$term_id  = absint( $term_id );
		$post_ids = get_term_meta( $term_id, self::$term_meta_key, true );
		if ( ! $post_ids || ! is_string( $post_ids ) ) {
			return array();
		}
		$post_ids = explode( ',', $post_ids );
		$post_ids = array_map( 'absint', $post_ids );
		return $post_ids;
	}

	/**
	 * Get featured posts for the featured post IDs
	 *
	 * @param  integer $term_id Term to get the featured posts for
	 * @return array            Array of WP_Post objects
	 */
	public static function get_featured_posts( $term_id = 0 ) {
		$post_ids = self::get_featured_post_ids( $term_id );
		if ( empty( $post_ids ) ) {
			return array();
		}
		$args  = array(
			'post__in' => $post_ids,
			'orderby'  => 'post__in',
		);
		$posts = new WP_Query( $args );
		if ( ! empty( $posts->posts ) ) {
			return $posts->posts;
		}
		return array();
	}

	/**
	 * Get the input field of a featured post ID used for displaying in the admin form
	 *
	 * @param  Object|int $post Post or ID to get the post for
	 * @return string           HTML of the input field
	 */
	public function get_featured_post_input_field( $post ) {
		$post    = get_post( $post );
		$context = array(
			'title'       => apply_filters( 'the_title', $post->post_title ),
			'id'          => $post->ID,
			'delete_icon' => '<span class="dashicons dashicons-trash"></span>', // See https://developer.wordpress.org/resource/dashicons/#trash
		);
		return Sprig::render( 'input-field.twig', $context );
	}
}

Featured_Post_IDs_Admin_UI::get_instance();
