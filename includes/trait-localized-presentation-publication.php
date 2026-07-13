<?php
/**
 * Atomic localized presentation publication and menu identity.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Devenia_Workflow_Localized_Presentation_Publication {
	/**
	 * Verify the published translation on both origin and canonical cache surfaces.
	 *
	 * @param array<string,mixed> $input Verification arguments.
	 * @return array<string,mixed>
	 */
	private static function verify_live_translation( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$post           = get_post( $translation_id );
		if ( ! $post || ! self::is_translatable_post_type( (string) $post->post_type ) || ! self::is_translation_post( $translation_id ) ) {
			return array( 'success' => false, 'code' => 'translation_not_found', 'message' => 'Translation content not found.' );
		}
		$translation = self::translation_payload( $post );
		$language    = sanitize_key( (string) ( $translation['language'] ?? '' ) );
		$url         = esc_url_raw( (string) ( $translation['url'] ?? '' ) );
		if ( ! self::is_translation_language( $language ) ) {
			return array(
				'success'       => true,
				'passed'        => false,
				'issues'        => array( self::qa_item( 'missing_or_unknown_language', 'Translation language is missing or not configured.' ) ),
				'warnings'      => array(),
				'issue_count'   => 1,
				'warning_count' => 0,
				'translation'   => $translation,
			);
		}
		if ( 'publish' !== $post->post_status ) {
			return array( 'success' => true, 'passed' => false, 'issues' => array( self::qa_item( 'translation_not_published', 'Live verification requires a published translation.', array( 'status' => $post->post_status ) ) ), 'translation' => $translation );
		}

		$result = self::frontend_public_surface_integrity_for_url( $url, $language, absint( $input['timeout'] ?? 15 ), 'translation' );
		$result['success']     = true;
		$result['translation'] = $translation;

		return $result;
	}

	/**
	 * Publish content, project its menu, invalidate caches, and verify both public views.
	 *
	 * @param array<string,mixed> $input Publication arguments.
	 * @return array<string,mixed>
	 */
	private static function publish_localized_presentation( array $input ): array {
		$translation_id = absint( $input['translation_id'] ?? 0 );
		$language       = sanitize_key( (string) ( $input['language'] ?? '' ) );
		$source_id      = absint( $input['source_id'] ?? 0 );
		$job_id         = sanitize_text_field( (string) ( $input['job_id'] ?? '' ) );
		$transition     = self::apply_translation_publish_transition( $translation_id, $language, $source_id );
		if ( empty( $transition['success'] ) ) {
			return $transition;
		}

		$menu = null;
		$post = get_post( $translation_id );
		if ( $post instanceof WP_Post && 'page' === $post->post_type && ! empty( $input['sync_menu'] ) ) {
			$menu = self::sync_language_menu(
				array(
					'language'             => $language,
					'include_untranslated' => false,
					'include_custom_links' => ! array_key_exists( 'include_custom_links', $input ) || ! empty( $input['include_custom_links'] ),
				)
			);
			if ( empty( $menu['success'] ) ) {
				return array(
					'success'     => false,
					'code'        => 'localized_menu_projection_failed',
					'message'     => 'Content was published, but the localized menu projection was not activated.',
					'published'   => true,
					'transition'  => $transition,
					'menu'        => $menu,
				);
			}
		}

		$purge_urls = self::localized_presentation_purge_urls( $language, (array) ( $transition['purge_urls'] ?? array() ) );
		$context    = array(
			'event'          => 'localized_presentation_publication',
			'language'       => $language,
			'translation_id' => $translation_id,
			'job_id'         => $job_id,
		);
		$invalidation = apply_filters( 'devenia_workflow_frontend_cache_invalidation_result', null, $purge_urls, $context );
		if ( ! is_array( $invalidation ) ) {
			return array(
				'success'            => false,
				'code'               => 'frontend_cache_adapter_missing',
				'message'            => 'Content was published, but no Frontend Cache Adapter acknowledged invalidation.',
				'published'          => true,
				'transition'         => $transition,
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => null,
			);
		}
		if ( true !== ( $invalidation['success'] ?? null ) ) {
			return array(
				'success'            => false,
				'code'               => sanitize_key( (string) ( $invalidation['code'] ?? 'frontend_cache_invalidation_failed' ) ),
				'message'            => 'Content was published, but frontend cache invalidation failed.',
				'published'          => true,
				'transition'         => $transition,
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => $invalidation,
			);
		}

		// Publication is a fail-closed Module invariant. Callers cannot opt out of
		// verifying both the origin-bypassing and exact canonical cache surfaces.
		$live = self::verify_live_translation(
			array(
				'translation_id' => $translation_id,
				'timeout'        => absint( $input['live_verification_timeout'] ?? 15 ),
			)
		);
		if ( empty( $live['success'] ) || empty( $live['passed'] ) ) {
			return array(
				'success'            => false,
				'code'               => 'localized_presentation_verification_failed',
				'message'            => 'Content was published and caches were invalidated, but the public presentation failed verification.',
				'published'          => true,
				'transition'         => $transition,
				'menu'               => $menu,
				'purge_urls'         => $purge_urls,
				'cache_invalidation' => $invalidation,
				'live_verification'  => $live,
			);
		}

		return array(
			'success'            => true,
			'published'          => true,
			'transition'         => $transition,
			'menu'               => $menu,
			'purge_urls'         => $purge_urls,
			'cache_invalidation' => $invalidation,
			'live_verification'  => $live,
		);
	}

	/**
	 * Include the language root because every primary-menu projection affects it.
	 *
	 * @param string[] $transition_urls URLs produced by the content transition.
	 * @return string[]
	 */
	private static function localized_presentation_purge_urls( string $language, array $transition_urls ): array {
		$urls = array_merge( $transition_urls, array( self::localized_home_url_for_language( $language ) ) );
		$urls = apply_filters( 'devenia_workflow_localized_presentation_purge_urls', $urls, $language );
		if ( ! is_array( $urls ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
	}

	/**
	 * Resolve the authoritative menu term ID, migrating deterministic name-based state once.
	 */
	private static function localized_menu_id( string $language, bool $migrate = true ): int {
		$language  = sanitize_key( $language );
		$identities = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
		$identities = is_array( $identities ) ? $identities : array();
		$stored_id  = absint( $identities[ $language ]['menu_id'] ?? 0 );
		if ( $stored_id > 0 && wp_get_nav_menu_object( $stored_id ) ) {
			return $stored_id;
		}
		if ( ! $migrate ) {
			return 0;
		}

		$languages = self::languages();
		$name      = sanitize_text_field( (string) ( $languages[ $language ]['menu_name'] ?? '' ) );
		if ( '' === $name ) {
			return 0;
		}
		$matches = array_values(
			array_filter(
				wp_get_nav_menus(),
				static function ( $menu ) use ( $name ): bool {
					return is_object( $menu ) && $name === (string) $menu->name;
				}
			)
		);
		usort(
			$matches,
			static function ( $left, $right ): int {
				return (int) $left->term_id <=> (int) $right->term_id;
			}
		);
		if ( empty( $matches ) ) {
			return 0;
		}

		$selected_id = (int) $matches[0]->term_id;
		$locations   = get_nav_menu_locations();
		$primary_id  = absint( $locations['primary'] ?? 0 );
		foreach ( $matches as $match ) {
			if ( $primary_id > 0 && $primary_id === (int) $match->term_id ) {
				$selected_id = $primary_id;
				break;
			}
		}

		$identities[ $language ] = array(
			'menu_id'         => $selected_id,
			'configured_name' => $name,
			'migrated_at'     => gmdate( 'c' ),
			'duplicate_ids'   => array_values(
				array_map(
					static function ( $menu ): int {
						return (int) $menu->term_id;
					},
					$matches
				)
			),
		);
		update_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $identities, false );

		return $selected_id;
	}

	/**
	 * Persist one validated menu projection as the active identity.
	 */
	private static function activate_localized_menu_id( string $language, int $menu_id, string $configured_name, int $previous_id ): bool {
		$identities = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );
		$identities = is_array( $identities ) ? $identities : array();
		$before     = $identities;
		$identities[ $language ] = array(
			'menu_id'          => $menu_id,
			'configured_name'  => $configured_name,
			'previous_menu_id' => $previous_id,
			'activated_at'     => gmdate( 'c' ),
		);
		update_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $identities, false );
		$stored = get_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, array() );

		$activated = is_array( $stored ) && $menu_id === absint( $stored[ $language ]['menu_id'] ?? 0 );
		if ( ! $activated ) {
			update_option( self::OPTION_LOCALIZED_MENU_IDENTITIES, $before, false );
		}

		return $activated;
	}

	/**
	 * Retire only a projection explicitly created and marked by Workflow.
	 */
	private static function retire_managed_localized_menu( int $menu_id, int $active_id ): bool {
		if ( $menu_id < 1 || $menu_id === $active_id || '1' !== (string) get_term_meta( $menu_id, self::TERM_META_MENU_MANAGED, true ) ) {
			return false;
		}
		$result = wp_delete_nav_menu( $menu_id );

		return ! is_wp_error( $result ) && false !== $result;
	}

	/**
	 * Compare a staged menu against the complete expected projection.
	 *
	 * @param array<int,array<string,mixed>> $expected Expected rows keyed by source item ID.
	 * @param array<int,int>                 $id_map   Source item to new item ID.
	 * @return array<string,mixed>
	 */
	private static function validate_localized_menu_projection( int $menu_id, array $expected, array $id_map ): array {
		$items  = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		$issues = array();
		if ( count( $items ) !== count( $expected ) ) {
			$issues[] = array( 'code' => 'menu_projection_count_mismatch', 'expected' => count( $expected ), 'actual' => count( $items ) );
		}
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item->ID ] = $item;
		}
		foreach ( $expected as $source_item_id => $row ) {
			$item_id = absint( $id_map[ $source_item_id ] ?? 0 );
			$item    = $by_id[ $item_id ] ?? null;
			if ( ! is_object( $item ) ) {
				$issues[] = array( 'code' => 'menu_projection_item_missing', 'source_item_id' => $source_item_id );
				continue;
			}
			$expected_parent = absint( $row['parent_source_item_id'] ?? 0 );
			$expected_parent = $expected_parent > 0 ? absint( $id_map[ $expected_parent ] ?? 0 ) : 0;
			$expected_url    = untrailingslashit( (string) ( $row['url'] ?? '' ) );
			$actual_url      = untrailingslashit( (string) ( $item->url ?? '' ) );
			if ( sanitize_text_field( (string) $item->title ) !== sanitize_text_field( (string) $row['title'] ) || $expected_url !== $actual_url || $expected_parent !== absint( $item->menu_item_parent ?? 0 ) ) {
				$issues[] = array(
					'code'                   => 'menu_projection_item_mismatch',
					'source_item_id'         => $source_item_id,
					'expected_title'         => (string) $row['title'],
					'actual_title'           => (string) $item->title,
					'expected_url'           => $expected_url,
					'actual_url'             => $actual_url,
					'expected_parent_item_id'=> $expected_parent,
					'actual_parent_item_id'  => absint( $item->menu_item_parent ?? 0 ),
				);
			}
		}

		return array( 'passed' => empty( $issues ), 'issues' => $issues, 'expected_count' => count( $expected ), 'actual_count' => count( $items ) );
	}

	/**
	 * Expected primary navigation labels and URLs for integrity checks.
	 *
	 * @return array<int,array{title:string,url:string}>
	 */
	private static function expected_localized_primary_navigation( string $language ): array {
		$menu_id = self::localized_menu_id( $language );
		if ( $menu_id < 1 ) {
			return array();
		}
		$expected = array();
		$items = wp_get_nav_menu_items( $menu_id, array( 'orderby' => 'menu_order' ) ) ?: array();
		foreach ( self::localized_menu_items_in_render_order( $items ) as $item ) {
			$expected[] = array(
				'title' => trim( html_entity_decode( wp_strip_all_tags( (string) $item->title ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' ) ),
				'url'   => self::normalize_primary_navigation_url( (string) $item->url ),
			);
		}

		return $expected;
	}

	/**
	 * Flatten menu items in the same depth-first order used by WordPress walkers.
	 *
	 * The menu-item `menu_order` column orders siblings; it is not the rendered
	 * flat order when a later row is a child of an earlier root item.
	 *
	 * @param array<int,object> $items WordPress navigation menu items.
	 * @return array<int,object>
	 */
	private static function localized_menu_items_in_render_order( array $items ): array {
		$by_parent = array();
		$known_ids = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) {
				continue;
			}
			$known_ids[ (int) $item->ID ] = true;
		}
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || empty( $item->ID ) ) {
				continue;
			}
			$parent_id = absint( $item->menu_item_parent ?? 0 );
			if ( $parent_id > 0 && ! isset( $known_ids[ $parent_id ] ) ) {
				$parent_id = 0;
			}
			$by_parent[ $parent_id ][] = $item;
		}
		foreach ( $by_parent as &$siblings ) {
			usort(
				$siblings,
				static function ( object $left, object $right ): int {
					$order = absint( $left->menu_order ?? 0 ) <=> absint( $right->menu_order ?? 0 );
					return 0 !== $order ? $order : (int) $left->ID <=> (int) $right->ID;
				}
			);
		}
		unset( $siblings );

		$ordered = array();
		$visited = array();
		$append = static function ( int $parent_id ) use ( &$append, &$by_parent, &$ordered, &$visited ): void {
			foreach ( $by_parent[ $parent_id ] ?? array() as $item ) {
				$item_id = (int) $item->ID;
				if ( isset( $visited[ $item_id ] ) ) {
					continue;
				}
				$visited[ $item_id ] = true;
				$ordered[] = $item;
				$append( $item_id );
			}
		};
		$append( 0 );
		foreach ( $items as $item ) {
			if ( is_object( $item ) && ! empty( $item->ID ) && ! isset( $visited[ (int) $item->ID ] ) ) {
				$ordered[] = $item;
			}
		}

		return $ordered;
	}

	/**
	 * Normalize relative and absolute menu links to one canonical comparison form.
	 */
	private static function normalize_primary_navigation_url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return untrailingslashit( esc_url_raw( $url ) );
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
		$path   = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
		$query  = isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';
		$normalized = ( $scheme && $host ? $scheme . '://' . $host . $port : '' ) . untrailingslashit( $path ) . $query;

		return esc_url_raw( $normalized );
	}

	/**
	 * Validate both an origin-bypassing response and the exact canonical cacheable URL.
	 *
	 * @return array<string,mixed>
	 */
	private static function frontend_public_surface_integrity_for_url( string $url, string $language, int $timeout = 15, string $surface = 'public' ): array {
		$url       = esc_url_raw( $url );
		$language  = sanitize_key( $language );
		$issues    = array();
		$warnings  = array();
		$responses = array();

		if ( '' === $url ) {
			$issues[] = self::qa_item( 'frontend_url_missing', 'Frontend URL is missing.', array( 'language' => $language, 'surface' => $surface ) );
		} else {
			foreach ( array( 'origin', 'canonical' ) as $cache_surface ) {
				$response = self::fetch_frontend_cache_surface( $url, $timeout, $cache_surface );
				$body     = (string) ( $response['body'] ?? '' );
				$final_url = (string) ( $response['final_url'] ?? $url );
				$responses[ $cache_surface ] = array_diff_key( $response, array( 'body' => true ) );
				if ( empty( $response['success'] ) ) {
					$issues[] = self::qa_item( 'frontend_integrity_http_error', 'Frontend integrity could not fetch a required cache surface.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface, 'error' => (string) ( $response['error'] ?? '' ) ) );
					continue;
				}
				if ( 200 !== (int) ( $response['status_code'] ?? 0 ) ) {
					$issues[] = self::qa_item( 'frontend_integrity_http_status_not_ok', 'Frontend integrity did not receive HTTP 200 from a required cache surface.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface, 'status_code' => (int) ( $response['status_code'] ?? 0 ) ) );
				}
				if ( '' === trim( $body ) ) {
					$issues[] = self::qa_item( 'frontend_integrity_empty_body', 'Frontend integrity received an empty response from a required cache surface.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface ) );
					continue;
				}
				$issues = array_merge( $issues, self::frontend_public_surface_html_issues( $body, $language, $final_url, $surface . '_' . $cache_surface ) );
				$issues = array_merge( $issues, self::localized_primary_navigation_html_issues( $body, $language, $url, $cache_surface ) );
				$expected_hreflang = self::hreflang_for_language( $language );
				if ( $expected_hreflang && ! preg_match( '/<link\b[^>]*rel=["\']alternate["\'][^>]*hreflang=["\']' . preg_quote( $expected_hreflang, '/' ) . '["\']/i', $body ) ) {
					$warnings[] = self::qa_item(
						'frontend_hreflang_missing',
						'Live page does not expose the expected hreflang alternate for this language.',
						array( 'language' => $language, 'hreflang' => $expected_hreflang, 'cache_surface' => $cache_surface )
					);
				}
			}
		}

		$canonical = $responses['canonical'] ?? array();
		return array(
			'success'         => empty( $issues ),
			'surface'         => sanitize_key( $surface ),
			'language'        => $language,
			'url'             => $url,
			'final_url'       => (string) ( $canonical['final_url'] ?? $url ),
			'passed'          => empty( $issues ),
			'issues'          => $issues,
			'warnings'        => $warnings,
			'issue_count'     => count( $issues ),
			'warning_count'   => count( $warnings ),
			'status_code'     => (int) ( $canonical['status_code'] ?? 0 ),
			'cache_responses' => $responses,
			'checked_at'      => gmdate( 'c' ),
		);
	}

	/**
	 * Fetch one explicit cache surface and expose cache response evidence.
	 *
	 * @return array<string,mixed>
	 */
	private static function fetch_frontend_cache_surface( string $url, int $timeout, string $surface ): array {
		$canonical = 'canonical' === $surface;
		$fetch_url = $canonical ? $url : add_query_arg( 'devenia_frontend_integrity', wp_generate_uuid4(), $url );
		$args      = array(
			'timeout'     => max( 3, min( 30, $timeout ) ),
			'redirection' => 3,
		);
		if ( ! $canonical ) {
			$args['headers'] = array( 'Cache-Control' => 'no-cache, no-store, max-age=0' );
		}
		$response = wp_remote_get( $fetch_url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'success'         => false,
				'surface'         => $surface,
				'url'             => $fetch_url,
				'error'           => $response->get_error_message(),
				'status_code'     => 0,
				'body'            => '',
				'cf_cache_status' => '',
				'age'             => '',
			);
		}

		return array(
			'success'         => true,
			'surface'         => $surface,
			'url'             => $fetch_url,
			'final_url'       => self::wp_remote_final_url( $response, $fetch_url ),
			'status_code'     => (int) wp_remote_retrieve_response_code( $response ),
			'body'            => (string) wp_remote_retrieve_body( $response ),
			'cf_cache_status' => (string) wp_remote_retrieve_header( $response, 'cf-cache-status' ),
			'age'             => (string) wp_remote_retrieve_header( $response, 'age' ),
		);
	}

	/**
	 * Verify that rendered primary navigation is the authoritative localized menu.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function localized_primary_navigation_html_issues( string $html, string $language, string $url, string $cache_surface ): array {
		$expected = self::expected_localized_primary_navigation( $language );
		if ( empty( $expected ) ) {
			return array( self::qa_item( 'frontend_primary_menu_identity_missing', 'No authoritative localized primary-menu identity is configured.', array( 'language' => $language, 'url' => $url, 'cache_surface' => $cache_surface ) ) );
		}

		$actual = array();
		if ( class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$loaded = $dom->loadHTML( $html );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			if ( $loaded ) {
				$xpath = new DOMXPath( $dom );
				$expressions = apply_filters(
					'devenia_workflow_primary_navigation_xpaths',
					array( "//nav[@id='site-navigation']//a[@href]", "//nav[contains(concat(' ', normalize-space(@class), ' '), ' main-navigation ')]//a[@href]" ),
					$language
				);
				if ( is_array( $expressions ) ) {
					foreach ( $expressions as $expression ) {
						$nodes = $xpath->query( (string) $expression );
						if ( ! $nodes || 0 === $nodes->length ) {
							continue;
						}
						foreach ( $nodes as $node ) {
							$actual[] = array(
								'title' => trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) ?? '' ),
								'url'   => self::normalize_primary_navigation_url( (string) $node->getAttribute( 'href' ) ),
							);
						}
						break;
					}
				}
			}
		}

		$expected_offset = -1;
		$expected_count  = count( $expected );
		for ( $offset = 0; $offset <= count( $actual ) - $expected_count; $offset++ ) {
			if ( array_slice( $actual, $offset, $expected_count ) === $expected ) {
				$expected_offset = $offset;
				break;
			}
		}
		if ( $expected_offset >= 0 ) {
			return array();
		}

		return array(
			self::qa_item(
				'frontend_primary_menu_projection_mismatch',
				'Rendered primary navigation does not match the authoritative localized menu identity, labels, and URLs.',
				array(
					'language'       => $language,
					'url'            => $url,
					'cache_surface'  => $cache_surface,
					'expected_menu_id' => self::localized_menu_id( $language ),
					'expected'       => $expected,
					'actual'         => $actual,
				)
			),
		);
	}
}
