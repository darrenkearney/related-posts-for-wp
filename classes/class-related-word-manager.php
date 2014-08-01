<?php

if ( !defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class SRP_Related_Word_Manager {

	const DB_TABLE = 'srp_cache';

	/**
	 * Get the database table
	 *
	 * @return string
	 */
	public static function get_database_table() {
		global $wpdb;

		return $wpdb->prefix . self::DB_TABLE;
	}

	/**
	 * Internal method that formats and outputs the $ignored_words array to screen
	 */
	public function dedupe_and_order_ignored_words( $lang ) {
		$output = '$ignored_words = array(';

		$ignored_words = $this->get_ignored_words( $lang );

		$temp_words = array();
		foreach ( $ignored_words as $word ) {

			// Only add word if it's not already added
			if ( !in_array( $word, $temp_words ) ) {
				if ( false !== strpos( $word, "Ã" ) ) {
					continue;
				}
				$temp_words[] = str_ireplace( "'", "", $word );
			}

		}

		sort( $temp_words );


		foreach ( $temp_words as $word ) {
			$output .= " '{$word}',";
		}


		$output .= ");";

		echo $output;
		die();
	}

	/**
	 * Get the ignored words
	 *
	 * @param string $lang
	 *
	 * @return array
	 */
	private function get_ignored_words( $lang = '' ) {

		// Set the language
		if ( '' == $lang ) {
			$lang = get_locale();
		}

		// Require the lang file
		$relative_path = '/ignored-words/' . $lang . '.php';

		// Validate the file path to prevent traversal attacks
		if ( 0 !== validate_file( $relative_path ) ) {
			return array();
		}

		$filename = dirname( __FILE__ ) . $relative_path;

		// Check if file exists
		if ( !file_exists( $filename ) ) {
			return array();
		}

		// Require the file
		$ignored_words = require( $filename );

		// Check if the the $ignored_words are set
		if ( is_null( $ignored_words ) || !is_array( $ignored_words ) ) {
			return array();
		}

		// Words to ignore
		return apply_filters( 'pc_ignored_words', $ignored_words );
	}

	/**
	 * Get the words from the post content
	 *
	 * @param $post
	 *
	 * @return array $words
	 */
	private function get_content_words( $post ) {

		$content = $post->post_content;

		// Remove all line break
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		// Array to store the linked words
		$linked_words = array();

		// Find all links in the content
		if ( true == preg_match_all( '`<a[^>]*href="([^"]+)">[^<]*</a>`si', $content, $matches ) ) {
			if ( count( $matches[1] ) > 0 ) {

				// Loop
				foreach ( $matches[1] as $url ) {

					// Get the post Id
					$link_post_id = url_to_postid( $url );

					if ( 0 == $link_post_id ) {
						continue;
					}

					// Get the post
					$link_post = get_post( $link_post_id );

					// Check if we found a linked post
					if ( $link_post != null ) {
						// Get words of title
						$title_words = explode( ' ', $link_post->post_title );

						// Check, Loop
						if ( is_array( $title_words ) && count( $title_words ) > 0 ) {
							foreach ( $title_words as $title_word ) {

								$title_word_multiplied = array_fill( 0, 20, $title_word );
								$linked_words          = array_merge( $linked_words, $title_word_multiplied );

							}
						}
					}

				}

			}
		}

		// Remove all html tags
		$content = strip_tags( $content );

		// Remove all shortcodes
		$content = strip_shortcodes( $content );

		// Remove the <!--more--> tag
		$content = str_ireplace( '<!--more-->', '', $content );

		// Remove everything but letters and numbers
		// $content = preg_replace( '/[^a-z0-9]+/i', ' ', $content );

		// Split string into words
		$words = explode( ' ', $content );

		// Add the $linked_words
		$words = array_merge( $words, $linked_words );

		// Return the $words
		return $words;
	}

	/**
	 * Add words from an array to the "base" words array, multiplied by their weight
	 *
	 * @param array $base_words
	 * @param array $words
	 * @param int   $weight
	 *
	 * @return array
	 */
	private function add_words_from_array( array $base_words, $words, $weight = 1 ) {

		if ( !is_array( $words ) ) {
			return $base_words;
		}

		foreach ( $words as $word ) {
			$word_multiplied_by_weight = array_fill( 0, $weight, $word );
			$base_words                = array_merge( $base_words, $word_multiplied_by_weight );
		}

		return $base_words;
	}

	/**
	 * Get the words of a post
	 *
	 * @param     int $post_id
	 *
	 * @return    array  $words
	 */
	public function get_words_of_post( $post_id ) {

		$post = get_post( $post_id );

		$title_weight = apply_filters( 'srp_weight_title', 80 );
		$tag_weight   = apply_filters( 'srp_weight_tag', 10 );
		$cat_weight   = apply_filters( 'srp_weight_cat', 20 );

		// Get words from content
		$raw_words = $this->get_content_words( $post );

		// Get words from title
		$title_words = explode( ' ', $post->post_title );
		$raw_words   = $this->add_words_from_array( $raw_words, $title_words, $title_weight );

		// Get tags and add them to list
		$tags = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

		if ( is_array( $tags ) && count( $tags ) > 0 ) {
			foreach ( $tags as $tag ) {
				$tag_words = explode( ' ', $tag );
				$raw_words = $this->add_words_from_array( $raw_words, $tag_words, $tag_weight );
			}
		}

		// Get categories and add them to list
		$categories = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		if ( is_array( $categories ) && count( $categories ) > 0 ) {
			foreach ( $categories as $category ) {
				$cat_words = explode( ' ', $category );
				$raw_words = $this->add_words_from_array( $raw_words, $cat_words, $cat_weight );
			}
		}

		// Count words and store them in array
		$words = array();

		if ( is_array( $raw_words ) && count( $raw_words ) > 0 ) {

			$ignored_words = $this->get_ignored_words();

			foreach ( $raw_words as $word ) {

				// Trim word
				$word = strtolower( trim( $word ) );

				// Skip empty words
				if ( '' == $word ) {
					continue;
				}

				// Only use words longer than 1 charecter
				if ( strlen( $word ) < 2 ) {
					continue;
				}

				// Skip ignored words
				if ( in_array( $word, $ignored_words ) ) {
					continue;
				}

				// Add word
				if ( isset( $words[$word] ) ) {
					$words[$word] += 1;
				} else {
					$words[$word] = 1;
				}

			}
		}

		// Sort words
		arsort( $words );

		// Only return words that occur more than X(3)

		$new_words       = array();
		$total_raw_words = count( $raw_words );
		$length_weight   = 0.6;

		foreach ( $words as $word => $amount ) {

			if ( $amount < 3 ) {
				break; // We can break because the array is already sorted
			}

			// Add word and turn amount into weight (make it relative)
			$new_words[$word] = ( $amount / ( $length_weight * $total_raw_words ) );

		}

		// Replace $words
		$words = $new_words;

		// Return words
		return $words;

	}

	/**
	 * Save words of given post
	 *
	 * @param $post_id
	 */
	public function save_words_of_post( $post_id ) {
		global $wpdb;

		// Get words
		$words = $this->get_words_of_post( $post_id );

		// Check words
		if ( is_array( $words ) && count( $words ) > 0 ) {

			// Get post type
			$post_type = get_post_type( $post_id );

			// Delete all currents words of post
			$this->delete_words( $post_id );

			// Loop words
			foreach ( $words as $word => $amount ) {

				// Insert word row
				$wpdb->insert(
					self::get_database_table(),
					array(
						'post_id'   => $post_id,
						'word'      => $word,
						'weight'    => $amount,
						'post_type' => $post_type
					),
					array(
						'%d',
						'%s',
						'%f',
						'%s',
					)
				);

			}

		}

		// Update this post as cached
		update_post_meta( $post_id, SRP_Constants::PM_CACHED, 1 );

	}

	/**
	 * Get uncached posts
	 *
	 * @param int $limit
	 *
	 * @return array
	 */
	public function get_uncached_posts( $limit = - 1 ) {
		// Get Posts without 'cached' PM
		return get_posts( array(
			'post_type'      => 'post',
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'     => SRP_Constants::PM_CACHED,
					'compare' => 'NOT EXISTS',
					'value'   => ''
				),
			)
		) );
	}

	/**
	 * Save all words of posts
	 */
	public function save_all_words( $limit = - 1 ) {
		global $wpdb;

		// Get uncached posts
		$posts = $this->get_uncached_posts( $limit );

		// Check & Loop
		if ( count( $posts ) > 0 ) {
			foreach ( $posts as $post ) {
				$this->save_words_of_post( $post->ID );
			}
		}

		// Done
		return true;
	}

	/**
	 * Get the amount of words of a post
	 *
	 *
	 * @return int
	 */
	public function get_word_count() {
		global $wpdb;

		return $wpdb->get_var( "SELECT COUNT(word) FROM `" . self::get_database_table() . "` WHERE `post_type` = 'post'" );
	}

	/**
	 * Delete words by post ID
	 *
	 * @param $post_id
	 */
	public function delete_words( $post_id ) {
		global $wpdb;

		$wpdb->delete( self::get_database_table(), array( 'post_id' => $post_id ), array( '%d' ) );
	}

}