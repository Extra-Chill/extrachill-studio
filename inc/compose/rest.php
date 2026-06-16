<?php
/**
 * Compose REST Proxy — cross-site bridge for the Studio Blog tab.
 *
 * The Studio Compose pane is an internal team tool that drafts blog articles
 * for editorial review and publishing on MAIN extrachill.com (blog 1). Studio
 * itself lives on blog 12. If the compose pane wrote posts to the local Studio
 * site, every submitted article would be stranded where no editor ever looks
 * (see Extra-Chill/extrachill-studio#75).
 *
 * Rather than draft on Studio and migrate to main later — which means
 * re-homing attachments, rewriting image URLs/IDs in block content, and
 * recreating terms — posts are BORN ON MAIN from the very first draft. Studio
 * stays a pure tool surface; the post lives on blog 1 its entire lifecycle
 * (draft → pending → publish).
 *
 * The compose pane is a frontend React/TS app, so it cannot call the PHP
 * `ec_cross_site_rest_request()` primitive directly. These thin Studio-local
 * REST routes are the server-side bridge: each one proxies to main via
 * `ec_cross_site_rest_request( 'main', ... )` (extrachill-multisite), which is
 * in-process by default (switch_to_blog + rest_do_request — no HTTP loopback)
 * and runs under the requesting user's auth, respecting WP core caps.
 *
 * Routes (all under the team-gated permission check):
 *   - GET  /extrachill/v1/studio/compose/posts                 List the user's drafts on main.
 *   - POST /extrachill/v1/studio/compose/posts                 Create a draft on main.
 *   - POST /extrachill/v1/studio/compose/posts/<id>            Update / submit-for-review on main.
 *   - GET  /extrachill/v1/studio/compose/posts/<id>/autosaves  List autosaves for a draft on main.
 *   - POST /extrachill/v1/studio/compose/posts/<id>/autosaves  Autosave a draft on main (status omitted).
 *   - POST /extrachill/v1/studio/compose/media                 Upload an image into main's media library.
 *
 * The frontend installs an apiFetch middleware that rewrites the compose
 * pane's `/wp/v2/posts*` and `/wp/v2/media*` calls onto these proxy routes,
 * which also captures the block editor's own inline media uploads so inserted
 * images land in main's library — not Studio's — avoiding the very migration
 * problem #75 set out to remove.
 *
 * @package    ExtraChillStudio
 * @subpackage Compose
 * @since      0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST namespace and route prefix for the compose proxy.
 */
const EC_STUDIO_COMPOSE_NAMESPACE = 'extrachill/v1';
const EC_STUDIO_COMPOSE_ROUTE     = 'studio/compose';

/**
 * Register the compose proxy REST routes.
 *
 * @since 0.16.0
 *
 * @return void
 */
function ec_studio_compose_register_routes(): void {
	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/posts',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_list_drafts',
				'permission_callback' => 'ec_studio_compose_permission_check',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_create_post',
				'permission_callback' => 'ec_studio_compose_permission_check',
			),
		)
	);

	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/posts/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_update_post',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);

	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/posts/(?P<id>\d+)/autosaves',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'ec_studio_compose_list_autosaves',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_create_autosave',
				'permission_callback' => 'ec_studio_compose_permission_check',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			),
		)
	);

	register_rest_route(
		EC_STUDIO_COMPOSE_NAMESPACE,
		'/' . EC_STUDIO_COMPOSE_ROUTE . '/media',
		array(
			array(
				'methods'             => 'POST',
				'callback'            => 'ec_studio_compose_upload_media',
				'permission_callback' => 'ec_studio_compose_permission_check',
			),
		)
	);
}
add_action( 'rest_api_init', 'ec_studio_compose_register_routes' );

/**
 * Permission check for the compose proxy routes.
 *
 * Mirrors the team-gating used by the compose editor surface itself
 * (compose-editor.php / render.php): the caller must be a logged-in
 * administrator or Extra Chill team member. The actual write on main is
 * still performed under the user's own auth via the cross-site primitive,
 * so main's core capability checks apply a second time.
 *
 * @since 0.16.0
 *
 * @return true|\WP_Error True when allowed, WP_Error otherwise.
 */
function ec_studio_compose_permission_check() {
	if ( ! is_user_logged_in() ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'You must be logged in to use the Studio compose tools.', 'extrachill-studio' ),
			array( 'status' => 401 )
		);
	}

	if ( ! current_user_can( 'manage_options' ) && function_exists( 'ec_is_team_member' ) && ! ec_is_team_member() ) {
		return new \WP_Error(
			'rest_forbidden',
			__( 'Studio is available to Extra Chill team members only.', 'extrachill-studio' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Guard that extrachill-multisite's cross-site primitive is available.
 *
 * @since 0.16.0
 *
 * @return true|\WP_Error True when available, WP_Error otherwise.
 */
function ec_studio_compose_require_cross_site() {
	if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site compose.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	return true;
}

/**
 * Normalize a cross-site response into a REST response, preserving WP_Error.
 *
 * @since 0.16.0
 *
 * @param array|\WP_Error $response Cross-site request result.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_relay_response( $response ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return rest_ensure_response( $response );
}

/**
 * List the current user's drafts on main extrachill.com.
 *
 * Author-scoped so users with edit_others_posts (editors, admins) only see
 * their own Studio drafts in the compose picker — matching the frontend's
 * author filter intent.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_list_drafts( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();

	$query = array(
		'status'   => 'draft',
		'per_page' => 20,
		'orderby'  => 'modified',
		'order'    => 'desc',
		'context'  => 'edit',
		'author'   => $user_id,
	);

	$response = ec_cross_site_rest_request(
		'main',
		'GET',
		'/wp/v2/posts',
		array(
			'query'   => $query,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Create a post on main extrachill.com.
 *
 * Used for the initial draft create and for explicit "Save Draft" /
 * "Submit for Review" actions when no post id exists yet. The post is born on
 * main under the requesting user's authorship.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_create_post( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$params  = ec_studio_compose_sanitize_post_params( $request->get_json_params() );

	// Always attribute the post to the requesting user on main.
	$params['author'] = $user_id;

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts',
		array(
			'body'    => $params,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Update an existing post on main extrachill.com.
 *
 * Handles "Update Draft" (status=draft) and "Submit for Review"
 * (status=pending). The status the frontend sends is forwarded verbatim —
 * the proxy does not demote or override it.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_update_post( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$post_id = (int) $request['id'];
	$params  = ec_studio_compose_sanitize_post_params( $request->get_json_params() );

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts/' . $post_id,
		array(
			'body'    => $params,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * List autosaves for a draft on main extrachill.com.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_list_autosaves( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$post_id = (int) $request['id'];

	$response = ec_cross_site_rest_request(
		'main',
		'GET',
		'/wp/v2/posts/' . $post_id . '/autosaves',
		array(
			'query'   => array( 'context' => 'edit' ),
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Create an autosave for a draft on main extrachill.com.
 *
 * Deliberately omits `status` so the parent post's status is preserved — an
 * out-of-band transition to pending/publish on main is never demoted back to
 * draft by an in-flight autosave. This mirrors the frontend autosave
 * contract.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_create_autosave( \WP_REST_Request $request ) {
	$guard = ec_studio_compose_require_cross_site();
	if ( is_wp_error( $guard ) ) {
		return $guard;
	}

	$user_id = (int) get_current_user_id();
	$post_id = (int) $request['id'];

	// Only title/content are forwarded — status is intentionally never sent on
	// autosave (see docblock).
	$raw  = $request->get_json_params();
	$body = array();
	if ( is_array( $raw ) ) {
		if ( isset( $raw['title'] ) ) {
			$body['title'] = sanitize_text_field( wp_unslash( (string) $raw['title'] ) );
		}
		if ( isset( $raw['content'] ) ) {
			$body['content'] = (string) $raw['content'];
		}
	}

	$response = ec_cross_site_rest_request(
		'main',
		'POST',
		'/wp/v2/posts/' . $post_id . '/autosaves',
		array(
			'body'    => $body,
			'user_id' => $user_id,
		)
	);

	return ec_studio_compose_relay_response( $response );
}

/**
 * Sanitize the post params accepted from the compose pane.
 *
 * Whitelists the small set of fields the compose pane sends — title, content,
 * status — so the proxy never forwards arbitrary REST fields cross-site. The
 * `status` value is validated against the lifecycle states the compose pane
 * is allowed to set (draft, pending). Content is left unescaped because the
 * block editor produces post HTML that main's `/wp/v2/posts` controller
 * sanitizes with the author's capabilities (post caps re-checked on main).
 *
 * @since 0.16.0
 *
 * @param mixed $raw Raw JSON params.
 * @return array Sanitized post params.
 */
function ec_studio_compose_sanitize_post_params( $raw ): array {
	$params = array();

	if ( ! is_array( $raw ) ) {
		return $params;
	}

	if ( isset( $raw['title'] ) ) {
		$params['title'] = sanitize_text_field( wp_unslash( (string) $raw['title'] ) );
	}

	if ( isset( $raw['content'] ) ) {
		$params['content'] = (string) $raw['content'];
	}

	if ( isset( $raw['status'] ) ) {
		$status = sanitize_key( (string) $raw['status'] );
		if ( in_array( $status, array( 'draft', 'pending' ), true ) ) {
			$params['status'] = $status;
		}
	}

	return $params;
}

/**
 * Upload an inserted editor image into main extrachill.com's media library.
 *
 * The block editor's media-utils `uploadMedia()` POSTs files to
 * `/wp/v2/media` via apiFetch. The frontend middleware rewrites that onto
 * this route so the attachment is created on MAIN (blog 1) — not Studio
 * (blog 12). If it landed on Studio, the block content would reference
 * blog-12 attachment URLs/IDs and recreate the cross-site migration problem
 * #75 set out to eliminate.
 *
 * The multipart upload (`$_FILES`) does not survive an in-process
 * rest_do_request dispatch cleanly, so this handler performs the upload
 * directly inside `switch_to_blog( main )` — the same approach
 * extrachill-api uses for product images that must land on the shop site.
 * The response is shaped like core's `/wp/v2/media` create response
 * (`prepare_item_for_response`) so the editor consumes it unchanged.
 *
 * @since 0.16.0
 *
 * @param \WP_REST_Request $request REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function ec_studio_compose_upload_media( \WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new \WP_Error(
			'multisite_helper_missing',
			__( 'extrachill-multisite is required for cross-site media upload.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$files = $request->get_file_params();
	if ( empty( $files['file'] ) && isset( $_FILES['file'] ) ) {
		$files['file'] = $_FILES['file']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST cookie nonce already validated by the auth layer; file params are validated below.
	}

	if ( empty( $files['file']['name'] ) ) {
		return new \WP_Error(
			'no_file',
			__( 'No file was uploaded.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$uploaded_file = $files['file'];

	$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	$file_type     = wp_check_filetype_and_ext( $uploaded_file['tmp_name'], $uploaded_file['name'] );

	if ( ! in_array( $file_type['type'], $allowed_types, true ) ) {
		return new \WP_Error(
			'invalid_file_type',
			__( 'Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.', 'extrachill-studio' ),
			array( 'status' => 400 )
		);
	}

	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return new \WP_Error(
			'main_blog_unresolved',
			__( 'Could not resolve the main site for media upload.', 'extrachill-studio' ),
			array( 'status' => 500 )
		);
	}

	$user_id = (int) get_current_user_id();

	// Optional caption/alt/title forwarded from the editor upload form.
	$caption = $request->get_param( 'caption' );
	$alt     = $request->get_param( 'alt_text' );
	$title   = $request->get_param( 'title' );

	$result = null;

	switch_to_blog( $main_blog_id );
	try {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload_result = wp_handle_upload( $uploaded_file, array( 'test_form' => false ) );

		if ( ! $upload_result || isset( $upload_result['error'] ) ) {
			$result = new \WP_Error(
				'upload_failed',
				isset( $upload_result['error'] ) ? $upload_result['error'] : __( 'Upload failed.', 'extrachill-studio' ),
				array( 'status' => 500 )
			);
		} else {
			$attachment = array(
				'guid'           => $upload_result['url'],
				'post_author'    => $user_id,
				'post_mime_type' => $upload_result['type'],
				'post_title'     => $title ? sanitize_text_field( $title ) : preg_replace( '/\.[^.]+$/', '', basename( $upload_result['file'] ) ),
				'post_content'   => '',
				'post_excerpt'   => $caption ? sanitize_textarea_field( $caption ) : '',
				'post_status'    => 'inherit',
			);

			$attachment_id = wp_insert_attachment( $attachment, $upload_result['file'], 0, true );

			if ( is_wp_error( $attachment_id ) ) {
				$result = $attachment_id;
			} else {
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload_result['file'] );
				wp_update_attachment_metadata( $attachment_id, $attach_data );

				if ( $alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
				}

				$result = ec_studio_compose_prepare_media_response( $attachment_id );
			}
		}
	} finally {
		restore_current_blog();
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Build a `/wp/v2/media`-shaped response for a freshly uploaded attachment.
 *
 * Delegates to the core media REST controller's prepare method so the block
 * editor receives exactly the shape it expects from a normal media upload.
 * Must be called inside the target blog context.
 *
 * @since 0.16.0
 *
 * @param int $attachment_id Attachment id on the current (switched) blog.
 * @return array Prepared media response data.
 */
function ec_studio_compose_prepare_media_response( int $attachment_id ): array {
	$controller = new \WP_REST_Attachments_Controller( 'attachment' );
	$request    = new \WP_REST_Request( 'GET', '/wp/v2/media/' . $attachment_id );
	$request->set_param( 'context', 'edit' );

	$response = $controller->prepare_item_for_response( get_post( $attachment_id ), $request );

	if ( is_wp_error( $response ) ) {
		return array( 'id' => $attachment_id );
	}

	$data = $response->get_data();

	return is_array( $data ) ? $data : array( 'id' => $attachment_id );
}
