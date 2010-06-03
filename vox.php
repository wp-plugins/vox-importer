<?php
/*
Plugin Name: Vox Importer
Plugin URI: http://wordpress.org/extend/plugins/vox-importer/
Description: Import posts, comments, tags, and attachments from a Vox.com blog. 
If you install this plugin on WordPress 2.9 you will need the WP_Importer base class. 
You can download it here: http://wordpress.org/extend/plugins/class-wp-importer/
Author: Automattic, Brian Colinger
Author URI: http://automattic.com/
Version: 0.7
Stable tag: 0.7
Requires at least: 2.9
Tested up to: 3.0
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * Vox Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
class Vox_Import extends WP_Importer {
	var $blog_id = 0;
	var $user_id = 0;
	var $hostname;
	var $auth = false;
	var $username = '';
	var $password = '';
	var $post_password = '';
	var $bid = '';
	var $start_page = 0;
	var $total_pages = 0;
	var $permalinks = array();
	var $comments = array();
	var $attachments = array();
	var $url_remap = array();

	/**
	 * Constructor
	 *
	 * @return void
	 */
	function __construct() {
		add_action( 'process_attachment', array( &$this, 'process_attachment' ), 10, 2 );
		add_action( 'process_comments', array( &$this, 'process_comments' ), 10, 2 );

		if ( isset( $_GET['import'] ) && 'vox' == $_GET['import'] ) {
			wp_enqueue_script( 'jquery' );
			add_action( 'admin_head', array ( &$this, 'admin_head' ) );
		}
	}

	/**
	 * PHP 4 Constructor
	 *
	 * @return void
	 */
	function Vox_Import(){
		$this->__construct();
	}

	function setup_auth() {
		// Try stored auth data first
		$data = get_option( 'vox_import' );
		if ( $data ) {
			$this->hostname = $this->sanitize_hostname( $data->hostname );
			$this->bid = md5( $this->hostname );
			$this->username = $data->username;
			$this->password = $data->password;
			$this->post_password = $data->post_password;
		}

		// Maybe we're running via CLI, try CLI args
		if ( empty( $this->username ) || empty( $this->password ) || empty( $this->hostname ) || empty( $this->bid ) ) {
			$this->hostname = get_cli_args( 'hostname', true );
			$this->bid = md5( $this->hostname );
			$this->username = get_cli_args( 'username' );
			$this->password = get_cli_args( 'password' );
			$this->post_password = get_cli_args( 'postpassword' );
			if ( !empty( $this->username ) && !empty( $this->password ) )
				$this->auth = true;
		}
	}

	/**
	 * Strip out protocol, check for proper domain.
	 *
	 * @param string $hostname
	 * @return string
	 */
	function sanitize_hostname( $hostname ) {
		$hostname = str_replace( array( 'http://', 'https://' ), '', trim( stripslashes( strtolower( $hostname ) ) ) );
		if ( !strstr( $hostname, '.vox.com' ) )
			$hostname = $hostname . '.vox.com';
		return $hostname;
	}

	/**
	 * Import calls each step of the process
	 *
	 * @return void
	 */
	function import() {
		define( 'WP_IMPORTING', true );
		do_action( 'import_start' );

		// Set time limit after import_start to avoid the 900 second limit
		set_time_limit( 0 );
		$this->set_page_count();
		$this->permalinks = $this->get_imported_posts( 'vox', $this->bid );
		$this->comments = $this->get_imported_comments();
		$this->attachments = $this->get_imported_attachments();
		$this->do_posts();
		$this->do_comments();
		$this->process_attachments();
		$this->cleanup();
	}

	/**
	 * Loop over each ATOM feed and process posts
	 *
	 * @return void
	 */
	function do_posts() {
		for ( $i = $this->start_page; $i <= $this->total_pages; $i++ ) {
			$url = 'http://' . $this->hostname . '/library/posts/page/' . $i . '/atom-full.xml';
			if ( $this->auth )
				$url = add_query_arg( 'auth', 'basic', $url );
			$this->process_posts( $url );
		}
	}

	/**
	 * Loop over each vox permalink and process comments
	 *
	 * @return void
	 */
	function do_comments() {
		// Extract comments from permalinks
		foreach ( $this->permalinks as $permalink => $post_id ) {
			do_action( 'process_comments', $permalink, $post_id );
		}
	}

	/**
	 * Extract XML from URL and import as posts
	 *
	 * @param string $url
	 * @return void
	 */
	function process_posts( $url ) {
		$data = $this->get_page( $url, $this->username, $this->password );
		if ( is_wp_error( $data ) ) {
			echo "Error:\n" . $data->get_error_message() . "\n";
			return;
		}

		$xml = simplexml_load_string( $data['body'] );
		if ( !$xml )
			return;

		foreach ( $xml->entry as $entry ) {
			$entry->title = (string) $entry->title;
			$permalink = $this->get_link_by_rel( $entry->link, 'alternate' );
			// service.post will not exist if this post is hidden from public view
			$service_post = $this->get_link_by_rel( $entry->link, 'service.post' );

			if ( isset( $this->permalinks[$permalink] ) ) {
				printf( "<em>%s</em><br />\n", __( 'Skipping' ) . ' ' . $entry->title );
				continue;
			}

			$post = array();
			$post['post_title'] = (string) $entry->title;
			$post['post_date'] = date( "Y-m-d H:i:s", strtotime( (string) $entry->published ) );
			$post['post_content'] = (string) $entry->content;
			$post['post_content'] = str_replace( "\n", ' ', $post['post_content'] );
			$post['post_status'] = 'publish';
			$post['post_password'] = $this->post_password;
			if ( !$service_post )
				$post['post_status'] = 'private';

			$post_id = wp_insert_post( $post );

			if ( is_wp_error( $post_id ) ) {
				printf( __( 'Error: %s' ) . "\n", htmlspecialchars( $post_id->get_error_message() ) );
				continue;
			}

			add_post_meta( $post_id, 'vox_' . $this->bid . '_post_id', $entry->id, true );
			add_post_meta( $post_id, 'vox_' . $this->bid . '_permalink', $permalink, true );

			printf( "<em>%s</em><br />\n", __( 'Importing' ) . ' ' . $entry->title );
			$this->permalinks[$permalink] = $post_id;

			$tags = $this->get_tags( $entry );
			if ( !empty( $tags ) )
				printf( "\t<em>%s</em><br />\n", __( 'Found tags:' ) . ' ' . implode( ', ', $tags ) );
			$this->add_post_tags( $post_id, $tags );
		}
	}

	/**
	 * Extract XML from URL, find and import comments
	 *
	 * @param string $url
	 * @return void
	 */
	function process_comments( $url, $post_id ) {
		$this->setup_auth();

		if ( $this->auth )
			$url = add_query_arg( 'auth', 'basic', $url );

		// Get Vox privacy setting for this post, transition post_status if different
		$post = get_post( $post_id );
		$post_status = $this->get_post_status( $body );
		if ( $post_status !==  $post->post_status )
			wp_transition_post_status( $post_status, $post->post_status, $post );
		unset( $post, $post_status );

		echo "<em>Checking post_id $post_id for comments...</em>";

		$data = $this->get_page( $url, $this->username, $this->password );
		if ( is_wp_error( $data ) ) {
			echo "Error:\n" . $data->get_error_message() . "\n";
			return;
		}

		// Remove this from comment, it confuses the regex for comment-body
		$body = str_replace( '<div class="comment-is-good">[this is good]</div>', '', $data['body'] );

		// Find all of the comment id's
		$ids_raw = array();
		$ids = array();
		preg_match_all( '!<div id="(comment-[0-9a-f]{32}.*?)"!is', $body, $ids_raw );
		if ( !empty( $ids_raw[1] ) ) {
			foreach ( $ids_raw[1] as $i => $text ) {
				$ids[$i] = $text;
			}
		}

		$authors_raw = array();
		preg_match_all( '!<li class="asset-meta-screenname item">(.*?)</li>!is', $body, $authors_raw );
		
		$authors = array();
		$author_urls = array();
		if ( !empty( $authors_raw[1] ) ) {
			foreach ( $authors_raw[1] as $i => $text ) {
				$matches = array();
				preg_match( '!<a href="(.*?)" .*?>(.*?)</a>!is', $text, $matches );
				$author_urls[$i] = isset( $matches[1] ) ? $matches[1] : '';
				$authors[$i] = isset( $matches[2] ) ? $matches[2] : '';
			}
		}

		$dates_raw = array();
		$dates = array();
		preg_match_all( '!at:asset-date="(.*?)"!is', $body, $dates_raw );
		if ( !empty( $dates_raw[1] ) ) {
			foreach ( $dates_raw[1] as $i => $text ) {
				$dates[$i] = gmdate( 'Y-m-d H:i:s', strtotime( str_replace( ' at ', '', $text ) ) );
			}
		}

		// Find all of the comments
		$matches = array();
		preg_match_all( '!<div class="comment-body">(.*?)</div>!is', $body, $matches );

		printf( "<em>%s</em><br />\n", __( 'Found' ) . ' ' . sizeof( $matches[1] ) );

		if ( !empty( $matches[1] ) ) {
			foreach ( $matches[1] as $i => $comment ) {
				$vox_comment_id = trim( strip_tags( $ids[$i] ) );
				// Skip existing comments
				if ( isset( $this->comments[$vox_comment_id] ) ) 
					continue;

				$comment_post_ID = $post_id;
				$comment_author = isset( $authors[$i] ) ? $authors[$i] : '';
				$comment_author_url = isset( $author_urls[$i] ) ? $author_urls[$i] : '';
				$comment_date = isset( $dates[$i] ) ? $dates[$i] : '';
				$comment_content = addslashes( trim( strip_tags( $comment ) ) );
				$comment = compact( 'comment_post_ID', 'comment_author', 'comment_author_url', 'comment_date', 'comment_content' );
				$comment = wp_filter_comment( $comment );

				if ( !comment_exists( $comment['comment_author'], $comment['comment_date'] ) ) {
					$comment_id = (int) wp_insert_comment( $comment );
				$meta_key = 'vox_' . $this->bid . '_comment_id';
				add_comment_meta( $comment_id, $meta_key, $vox_comment_id, true );
			}
		}
		}
	}

	/**
	 * Search for privacy settings strings
	 *
	 * @param string $body
	 * @return string
	 */
	function get_post_status( $body ) {
		$post_status = 'private';
		if ( stripos( $body, 'Viewable by neighborhood' ) )
			$post_status = 'private';
		if ( stripos( $body, 'Viewable by you' ) )
			$post_status = 'private';
		if ( stripos( $body, 'Viewable by anyone' ) )
			$post_status = 'public';

		unset( $body );
		return $post_status;
	}

	/**
	 * Add tags to post
	 *
	 * @param int $post_id
	 * @param array $tags
	 * @return void
	 */
	function add_post_tags( $post_id, $tags ) {
		if ( empty( $tags ) )
			return;
		global $wpdb;
		$post_tags = array();
		foreach ( $tags as $tag ) {
			$slug = sanitize_term_field( 'slug', $tag, 0, 'post_tag', 'db' );
			$tag_obj = get_term_by( 'slug', $slug, 'post_tag' );
			$tag_id = 0;
			if ( !empty( $tag_obj ) )
				$tag_id = $tag_obj->term_id;
			if ( $tag_id == 0 ) {
				$tag = $wpdb->escape( $tag );
				$tag_id = wp_insert_term( $tag, 'post_tag' );
				if ( is_wp_error( $tag_id ) )
					continue;
				$tag_id = $tag_id['term_id'];
			}
			$post_tags[] = (int) $tag_id;
		}
		if ( empty( $post_tags ) )
			return;
		wp_set_post_tags( $post_id, $post_tags );
	}

	/**
	 * Get tags from post
	 *
	 * @param object $entry
	 * @return array
	 */
	function get_tags( $entry ) {
		$tags = array();
		if ( isset( $entry->category ) ) {
			foreach ( $entry->category as $category ) {
				$attr = $category->attributes();
				$tags[] = (string) $attr['term'];
			}
		}

		return array_unique( $tags );
	}

	/**
	 * Scan all posts for attachments
	 *
	 * @return void
	 */
	function process_attachments() {
		if ( empty( $this->permalinks ) )
			return;

		// Loop over each post ID
		foreach ( $this->permalinks as $permalink => $post_id ) {
			// Get post data
			$post = get_post( $post_id );

			printf( "<em>%s</em>", __( 'Checking' ) . " '$post->post_title' " . __( 'for images...' ) );
			$attachments = $this->extract_post_images( $post->post_content );
			printf( "<em>%s</em><br />\n", ' ' . sizeof( $attachments['fullsize'] ) . ' ' . __( 'images found' ) );

			if ( !empty( $attachments['fullsize'] ) ) {
				do_action( 'process_attachment', $post, $attachments );
			}

			unset( $post, $attachments );

			$this->stop_the_insanity();
		}
	}

	/**
	 * Import and processes each attachment
	 *
	 * @param object $post
	 * @param array $attachments
	 * @return void
	 */
	function process_attachment( $post, $attachments ) {
		// Process attachments
		if ( !empty( $attachments['fullsize'] ) ) {
			foreach ( $attachments['fullsize'] as $id => $url ) {
				if( $this->is_user_over_quota() )
					return false;

				$thumb = $attachments['thumb'][$id];
				$href = $attachments['url'][$id];

				// Skip duplicates
				if ( isset( $this->attachments[$url] ) ) {
					$post_id = $this->attachments[$url];
					printf( "<em>%s</em><br />\n", __( 'Skipping duplicate' ) . ' ' . htmlspecialchars( $url ) );
					// Get new attachment URL
					$attachment_url = wp_get_attachment_url( $post_id );
		
					// Update url_remap array
					$this->url_remap[$url] = $attachment_url;
					$this->url_remap[$href] = $attachment_url;
					$sized = image_downsize( $post_id, 'medium' );
					if ( isset( $sized[0] ) ) {
						$this->url_remap[$thumb] = $sized[0];
					}
		
					continue;
				}

				echo '<em>Importing attachment ' . htmlspecialchars( $url ) . "...</em>";
				$upload = $this->fetch_remote_file( $post, $url );
		
				if ( is_wp_error( $upload ) ) {
					printf( "<em>%s</em><br />\n", __( 'Remote file error:' ) . ' ' . htmlspecialchars( $upload->get_error_message() ) );
					continue;
				} else {
					printf( "<em> (%s)</em><br />\n", size_format( filesize( $upload['file'] ) ) );
				}
		
				if ( 0 == filesize( $upload['file'] ) ) {
					print __( "Zero length file, deleting..." ) . "<br />\n";
					@unlink( $upload['file'] );
					continue;
				}
		
				$info = wp_check_filetype( $upload['file'] );
				if ( false === $info['ext'] ) {
					printf( "<em>%s</em><br />\n", $upload['file'] . __( 'has an invalid file type') );
					@unlink( $upload['file'] );
					continue;
				}
		
				// as per wp-admin/includes/upload.php
				$attachment = array ( 
					'post_title' => $post->post_title, 
					'post_content' => '', 
					'post_status' => 'inherit', 
					'guid' => $upload['url'], 
					'post_mime_type' => $info['type']
					);
		
				$post_id = (int) wp_insert_attachment( $attachment, $upload['file'], $post->ID );
				$attachment_meta = @wp_generate_attachment_metadata( $post_id, $upload['file'] );
				wp_update_attachment_metadata( $post_id, $attachment_meta );
		
				// Add remote_url to post_meta
				add_post_meta( $post_id, 'vox_attachment', $url, true );
				// Add remote_url to hash table
				$this->attachments[$url] = $post_id;
		
				// Get new attachment URL
				$attachment_url = wp_get_attachment_url( $post_id );
				// Update url_remap array
				$this->url_remap[$url] = $attachment_url;
				$this->url_remap[$href] = $attachment_url;
				$sized = image_downsize( $post_id, 'medium' );
				if ( isset( $sized[0] ) ) {
					$this->url_remap[$thumb] = $sized[0];
				}
			}
		}

		$this->backfill_attachment_urls( $post );
	}

	/**
	 * Update url references in post bodies to point to the new local files
	 *
	 * @return void
	 */
	function backfill_attachment_urls( $post = false ) {
		if ( false === $post )
			return;

		// make sure we do the longest urls first, in case one is a substring of another
		uksort( $this->url_remap, array( &$this, 'cmpr_strlen') );

		$from_urls = array_keys( $this->url_remap );
		$to_urls = array_values( $this->url_remap );

		$hash_1 = md5( $post->post_content );
		$post->post_content = str_replace( $from_urls, $to_urls, $post->post_content );
		$hash_2 = md5( $post->post_content );

		if ( $hash_1 !== $hash_2 )
			wp_update_post( $post );
	}

	/**
	 * Download remote file, keep track of URL map
	 *
	 * @param object $post
	 * @param string $url
	 * @return array
	 */
	function fetch_remote_file( $post, $url ) {
		// Increase the timeout
		add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );

		$head = $this->get_page( $url, $this->username, $this->password, true );
		if ( 200 !== $head['response']['code'] ) {
			return new WP_Error( 'import_file_error', sprintf( __( 'Remote file returned error response %d' ), $head['response']['code'] ) );
		}

		if ( strstr( $head['headers']['content-type'], 'image' ) ) {
			$cd = $head['headers']['content-disposition'];
			$filename = substr( $cd, strpos( $cd, 'filename=' ) + 9, strlen( $cd ) );

			// get placeholder file in the upload dir with a unique sanitized filename
			$upload = wp_upload_bits( $filename, 0, '', $post->post_date );
			if ( is_wp_error( $upload ) ) {
				return $upload;
			}

			if ( $upload['error'] ) {
				echo $upload['error'];
				return false;
			}

			// fetch the remote url and write it to the placeholder file
			$headers = wp_get_http( $url, $upload['file'] );

			// make sure the fetch was successful
			if ( 200 !== $headers['response'] ) {
				@unlink( $upload['file'] );
				return new WP_Error( 'import_file_error', sprintf( __( 'Remote file returned error response %d' ), $headers['response'] ) );
			}

			// keep track of the old and new urls so we can substitute them later
			$this->url_remap[$url] = $upload['url'];
			// if the remote url is redirected somewhere else, keep track of the destination too
			if ( isset( $headers['x-final-location'] ) && $headers['x-final-location'] != $url )
				$this->url_remap[$headers['x-final-location']] = $upload['url'];

			return apply_filters( 'wp_handle_upload', $upload );
		}
	}

	/**
	 * Return array of images from the post
	 *
	 * @param string $post_content
	 * @return array
	 */
	function extract_post_images( $post_content ) {
		$post_content = stripslashes( $post_content );
		$post_content = str_replace( "\n", '', $post_content );
		$post_content = $this->min_whitespace( $post_content );
		$image_links = array();
		$attachments = array();
		$attachments['url'] = array();
		$attachments['thumb'] = array();
		$attachments['fullsize'] = array();

		$matches = array();
		preg_match_all( '|<img.*?src=[\'"](.*?)[\'"].*?>|i', $post_content, $matches );

		if ( empty( $matches[1] ) )
			return;
		foreach ( $matches[1] as $url ) {
			$h = array();
			preg_match( '|.vox.com/([0-9a-f]+)-|i', $url, $h );
			if ( isset( $h[1] ) ) {
				$attachments['thumb'][] = $url;
				// http://hostname.vox.com/library/photo/6a00cdf3a41707cb8f00e398c44c460004.html
				$attachments['url'][] = 'http://' . $this->hostname . '/library/photo/' . $h[1] . '.html';
			}
		}

		foreach ( (array) $attachments['thumb'] as $thumb ) {
			// http://a6.vox.com/6a00f48cea0dc500020123ddeebd86860d-320pi
			if ( strstr( $thumb, 'vox.com' ) )
				$attachments['fullsize'][] = preg_replace( '|-(\d+)pi|', '-pi', $thumb );
		}

		unset( $post_content, $xml, $img, $href );

		return $attachments;
	}

	/**
	 * Set array with imported attachments from WordPress database
	 *
	 * @return array
	 */
	function get_imported_attachments() {
		global $wpdb;

		$hashtable = array ();

		// Get all vox attachments
		$sql = $wpdb->prepare( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '%s'", 'vox_attachment' );
		$results = $wpdb->get_results( $sql );

		if (! empty( $results )) {
			foreach ( $results as $r ) {
				// Set permalinks into array
				$hashtable[$r->meta_value] = (int) $r->post_id;
			}
		}

		// unset to save memory
		unset( $results, $r );

		return $hashtable;
	}

	/**
	 * Return hash table of Vox comment_id => WordPress comment_id
	 *
	 * @return array
	 */
	function get_imported_comments() {
		global $wpdb;

		$hashtable = array ();
		$limit = 100;
		$offset = 0;
		$meta_key = 'vox_' . $this->bid . '_comment_id';

		// Grab all comments in chunks
		do {
			$sql = $wpdb->prepare ( "SELECT comment_id, meta_value FROM $wpdb->commentmeta WHERE meta_key LIKE '%s' LIMIT %d,%d", $meta_key, $offset, $limit );
			$results = $wpdb->get_results ( $sql );

			// Increment offset
			$offset = ($limit + $offset);

			if (! empty ( $results )) {
				foreach ( $results as $r ) {
					$hashtable [$r->meta_value] = (int) $r->comment_id;
				}
			}
		} while ( count ( $results ) == $limit );

		// unset to save memory
		unset ( $results, $r );
		return $hashtable;
	}

	/**
	 * Set start_page and total_pages member variables
	 *
	 * @return void
	 */
	function set_page_count() {
		$url = 'http://' . $this->hostname . '/library/posts/page/1/atom-full.xml';
		if ( $this->auth )
			$url = add_query_arg( 'auth', 'basic', $url );

		$data = $this->get_page( $url, $this->username, $this->password );
		if ( is_wp_error( $data ) ) {
			echo "Error:\n" . $data->get_error_message() . "\n";
			return;
		}

		if ( 401 == (int) $data['response']['code'] ) {
			echo __( 'HTTP Error 401 Unauthorized' ) . "<br />\n";
			exit();
		}

		$xml = simplexml_load_string( $data['body'] );
		if ( !$xml )
			return;

		$start_url = $this->get_link_by_rel( $xml->link, 'self' );
		$this->start_page = $this->get_page_number( $start_url );

		$last_url = $this->get_link_by_rel( $xml->link, 'last' );
		$this->total_pages = $this->get_page_number( $last_url );
	}

	/**
	 * Extract page number from URL
	 *
	 * @param string $url
	 * @return int
	 */
	function get_page_number( $url ) {
		$path = parse_url( $url, PHP_URL_PATH );
		$parts = explode( '/', $path );
		$pos = ( sizeof( $parts ) - 2 );
		$num = $parts[$pos];
		if ( ! is_numeric( $num ) ) {
			return false;
		}

		return (int) $num;
	}

	/**
	 * Check links object for rel= , return url or false
	 *
	 * @param object $links
	 * @param string $rel
	 * @return mixed
	 */
	function get_link_by_rel( $links, $rel ) {
		foreach ( $links as $link ) {
			$attr = $link->attributes();
			$_rel = (string) $attr['rel'];
			$href = (string) $attr['href'];

			if ( $rel == $_rel ) {
				return $href;
			}
		}

		return false;
	}

	/**
	 * Perform cleanup operations
	 *
	 * @return void
	 */
	function cleanup() {
		delete_option( 'vox_import' );
		do_action( 'import_done', 'vox' );
		printf ( "<strong>%s</strong><br />\n", __( 'All Done!' ) );
	}

	function admin_head() {
		?>
<style type="text/css">
#vox_info label {
	display:inline;
	float:left;
	font-weight:bold;
	width:85px;
}
label#lbl_post_password {
	width:110px;
}
#auth_message {
	margin:10px;
	color:red;
}
#spinner {
	display:none;
}
</style>

<script type="text/javascript">
var $ = jQuery.noConflict();
$( function() {
	var code = 0;
	$('#import_submit').click( function() {
		$('#import_submit').attr('value', 'Please Wait...');
		$('#import_submit').attr('disabled', 'disabled');
		$('#spinner').show();
		var dataString = $('#vox_info').serialize();
		$.ajax({
			type: 'POST',
			url: 'admin.php?import=vox&noheader=true&test_user_pass=true',
			data: dataString,
			success: function( data, status ) {
				code = data;
				//alert(code);
			if ( '401' == code ) {
				$('#spinner').hide();
				$('#auth_message').html("Please check your user name and password, Vox says it's incorrect.");
				$('#import_submit').attr('value', 'Submit');
				$('#import_submit').removeAttr('disabled');

				return false;
			}
		
			if ( '200' == code ) {
				$.ajax({
					type: 'POST',
					url: 'admin.php?import=vox&noheader=true&step=2',
					data: dataString,
					success: function( data, status ) {
						if ( 'ready' == data ) {
						  window.location = 'admin.php?import=vox&step=3';
						}
					}
				  });

				  return true;
			}

			}
		  });
	});
});
</script>

<script type="text/javascript">
/*
 * jQuery doTimeout: Like setTimeout, but better! - v0.4 - 7/15/2009
 * http://benalman.com/projects/jquery-dotimeout-plugin/
 * 
 * Copyright (c) 2009 "Cowboy" Ben Alman
 * Dual licensed under the MIT and GPL licenses.
 * http://benalman.com/about/license/
 */
(function($){var a={},c="doTimeout",d=Array.prototype.slice;$[c]=function(){return b.apply(window,[0].concat(d.call(arguments)))};$.fn[c]=function(){var e=d.call(arguments),f=b.apply(this,[c+e[0]].concat(e));return typeof e[0]==="number"||typeof e[1]==="number"?this:f};function b(l){var m=this,h,k={},n=arguments,i=4,g=n[1],j=n[2],o=n[3];if(typeof g!=="string"){i--;g=l=0;j=n[1];o=n[2]}if(l){h=m.eq(0);h.data(l,k=h.data(l)||{})}else{if(g){k=a[g]||(a[g]={})}}k.id&&clearTimeout(k.id);delete k.id;function f(){if(l){h.removeData(l)}else{if(g){delete a[g]}}}function e(){k.id=setTimeout(function(){k.fn()},j)}if(o){k.fn=function(p){o.apply(m,d.call(n,i))&&!p?e():f()};e()}else{if(k.fn){j===undefined?f():k.fn(j===false);return true}else{f()}}}})(jQuery);
</script>

<script type="text/javascript">
var $ = jQuery.noConflict();
$( function() {
	$('#start_poll').hide();
	$('#stop_poll').hide();
	var elem = $('#polling_loop');
	$('#start_poll').click( function() {
		//$('#start_poll').hide();
		//$('#stop_poll').show();
		// Start a polling loop with an id of 'loop' and a counter.
		var i = 0;

		elem.doTimeout( 'loop', 3000, function() {
		$('#loop_count').html( ++i );
		var hostname = $('#hostname').val();
		if( $.trim( hostname ) == '') {
			return false;
		}

		var ajaxurl = 'admin.php?import=vox&noheader=true&status=true&hostname=' + $('#hostname').val();
		$.getJSON( ajaxurl, function( data ) { 
			var jo = eval( data );
			$('#posts_count').html( jo.posts );
			$('#comments_count').html( jo.comments );
			$('#attachments_count').html( jo.attachments );
		});

		return true;
		});
	});
	
	$('#stop_poll').click( function() {
		// Cancel the polling loop with id of 'loop'.
		elem.doTimeout( 'loop' );
		$('#start_poll').show();
		$('#stop_poll').hide();
	});

$('#start_poll').click();
});
</script>

<?php
	}

	function print_header() {
		echo "<div class='wrap'>\n";
		screen_icon();
		echo "<h2>" . __( 'Import Vox' ) . "</h2>\n";
	}

	function print_footer() {
		echo "</div>\n";
	}

	function test_user_pass( $hostname, $username, $password ) {
		$hostname = $this->sanitize_hostname( $hostname );
		$username = strtolower( $username );

		$this->username = $username;
		$this->password = $password;
		$this->auth = true;
		$url = 'http://' . $hostname . '/library/posts/page/1/atom-full.xml';
		$url = add_query_arg( 'auth', 'basic', $url );
		$data = $this->get_page( $url, $this->username, $this->password );
		if ( is_wp_error( $data ) ) {
			echo "Error:\n" . $data->get_error_message() . "\n";
			return;
		}

		$code = (int) $data['response']['code'];
		unset( $data );
		echo $code;
	}

	function step_1() {
		$action = add_query_arg( 'step', 2, $_SERVER['REQUEST_URI'] );
		$hostname = $this->sanitize_hostname( get_option( 'vox_hostname' ) );
		$hostname = str_replace( '.vox.com', '', $hostname );
		$username = get_option( 'vox_username' );
?>

<p><?php echo __( 'Howdy! So, you want to import your Vox blog? No problem, we just need a little information.' ); ?></p>
<p><?php echo __( 'Please enter your Vox user name and password.' ); ?></p>
<p><?php echo __( 'WordPress will not permanently store your Vox password. After the import is finished, your password will be deleted immediately.' ); ?></p>

<form id="vox_info" name="vox_info" action="<?php echo $action; ?>" method="POST">
	<label id="lbl_hostname"><?php _e( 'Host name' ); ?></label> <input id="hostname" name="hostname" type="text" value="<?php echo $hostname; ?>" /> .vox.com<br />
	<label id="lbl_username"><?php _e( 'User name' ); ?></label> <input id="username" name="username" type="text" value="<?php echo $username; ?>" /><br />
	<label id="lbl_password"><?php _e( 'Password' ); ?></label> <input id="password" name="password" type="password" value="" /><br />
	<p><?php _e( 'If you have any posts on Vox which are marked as private, they will be marked private in WordPress as well.' ) ?></p>
	<p><?php _e( 'If you want to apply a password to ALL imported posts, enter it here:' ) ?></p>
	<label id="lbl_post_password"><?php _e( 'Post Password' ); ?></label><input id="post_password" name="post_password" type="text" value="" maxlength="20" /> <?php _e( '(Optional)' ); ?><br />

	<input class="button" id="import_submit" name="import_submit" type="button" value="<?php _e( 'Submit' ); ?>" /> <img id="spinner" src="/wp-admin/images/loading.gif" />
	<div id="auth_message"></div>
</form>
<hr />
<?php
	}

	function step_2() {
		$hostname = $this->sanitize_hostname( $_POST['hostname'] );
		$hostname = str_replace( '.vox.com', '', $hostname );
		update_option( 'vox_hostname', $hostname );
		$username = trim( stripslashes( strtolower( $_POST['username'] ) ) );
		update_option( 'vox_username', $username );
		$password = trim( stripslashes( $_POST['password'] ) );
		update_option( 'vox_password', $password );

		$data = new stdClass();
		$data->hostname = $hostname;
		$data->username = $username;
		$data->password = $password;
		$data->post_password = $_POST['post_password'];

		add_option( 'vox_import', $data );
		echo 'ready';
	}

	function step_3() {
		global $blog_id, $current_user, $current_blog;

		$data = get_option( 'vox_import' );
		if ( !is_object( $data ) )
			die();
	
		$this->hostname = $this->sanitize_hostname( $data->hostname );
		$this->bid = md5( $this->hostname );
		$this->username = $data->username;
		$this->password = $data->password;
		$this->post_password = $data->post_password;
		$this->auth = false;
		if ( !empty( $this->username ) && !empty( $this->password ) )
			$this->auth = true;
	
		$this->blog_id = $this->set_blog( $blog_id );
		$this->user_id = $this->set_user( $current_user->ID );

		$this->import();
	}

	function importer_status() {
		$hostname = $this->sanitize_hostname( get_option( 'vox_hostname' ) );
		$status = $this->get_importer_status( $hostname, 'array' );
?>
	<div id="polling_loop">
		<div id="importer_status">
			<h2><?php _e( 'Importer Status' ); ?></h2>
			<input type="hidden" id="hostname" name="hostname" value="<?php echo $hostname; ?>" />
			<strong><?php _e( 'Posts:' ); ?></strong> <span id="posts_count"><?php echo $status['posts']; ?></span><br />
			<strong><?php _e( 'Comments:' ); ?></strong> <span id="comments_count"><?php echo $status['comments']; ?></span><br />
			<strong><?php _e( 'Attachments:' ); ?></strong> <span id="attachments_count"><?php echo $status['attachments']; ?></span><br />
			<p><input type="button" class="button" id="start_poll" name="start_poll" value="<?php _e( 'Check Status' ); ?>" /><input type="button" class="button" id="stop_poll" name="stop_poll" value="<?php _e( 'Stop Checking' ); ?>" /></p>
		</div>
	</div>
<hr />
<?php
	}

	function get_importer_status( $hostname, $return = 'json' ) {
		$this->hostname = $this->sanitize_hostname( $hostname );
		$this->bid = md5( $this->hostname );
		$this->permalinks = $this->get_imported_posts( 'vox', $this->bid );
		$this->comments = $this->get_imported_comments();
		$this->attachments = $this->get_imported_attachments();

		$status = array();
		$status['posts'] = count( $this->permalinks );
		$status['comments'] = count( $this->comments );
		$status['attachments'] = count( $this->attachments );

		if ( 'json' == $return )
			return json_encode( $status );
		if ( 'array' == $return )
			return $status;
	}

	function dispatch() {
		// Set step
		$step = isset( $_GET['step'] ) ? (int) $_GET['step'] : 1;

		if ( isset( $_GET['hostname'] ) && isset( $_GET['status'] ) )
			die( $this->get_importer_status( $_GET['hostname'] ) );

		if ( isset( $_GET['test_user_pass'] ) )
			die( $this->test_user_pass( $_POST['hostname'], $_POST['username'], $_POST['password'] ) );

		if ( 2 === $step )
			die( $this->step_2() );

		$this->print_header();

		if ( 1 === $step )
			$this->step_1();
		if ( 3 === $step )
			$this->step_3();


		$this->importer_status();
		$this->print_footer();
	}

	/**
	 * Strip signatures from posts
	 *
	 * @param string $post_content
	 * @return string
	 */
	function strip_signatures( $post_content ) {
		$post_content = preg_replace( '|(<p style=[\'"].*?[\'"].*?>.*?<a href=[\'"].*?[\'"].*?>Read and post comments</a>.*?</p>)|is', '', $post_content );
		return $post_content;
	}
}

$vox = new Vox_Import();
register_importer( 'vox', __( 'Vox' ), __( 'Import posts, comments, tags, and attachments from a Vox.com blog.' ), array( $vox, 'dispatch' ) );
}