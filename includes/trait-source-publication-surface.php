<?php
/**
 * Canonical source publication-surface identity for Devenia Workflow.
 *
 * @package Devenia_Workflow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the complete source-side surface revision consumed by Inventory and Translation Jobs.
 */
trait Devenia_Workflow_Source_Publication_Surface {
	/**
	 * Return the canonical identity of one featured image as WordPress renders it.
	 *
	 * The attachment identity deliberately contains no language- or site-specific
	 * values. Attachment metadata and the file-byte digest make an in-place
	 * replacement observable even when attachment and post IDs remain stable.
	 * Modified time remains diagnostic only and cannot change publication authority.
	 *
	 * @param int|WP_Post $post Source or translated post.
	 * @return array<string,mixed>
	 */
	private static function publication_featured_image_identity( $post ): array {
		$post_id       = $post instanceof WP_Post ? (int) $post->ID : absint( $post );
		$attachment_id = self::featured_image_id_for_post( $post_id );
		if ( ! $attachment_id ) {
			$identity = array(
				'attachment_id' => 0,
				'url' => '',
				'attached_file' => '',
				'attachment_revision_diagnostic' => '',
				'metadata_digest' => '',
				'source_alt' => '',
				'file_identity' => array( 'available' => true, 'size' => 0, 'mtime' => 0, 'sha256' => '', 'unavailable_reason' => '' ),
			);
			$identity['identity_revision'] = 'mi_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( self::publication_featured_image_revision_fields( $identity ) ) ) ?: '' ), 0, 40 );
			return $identity;
		}

		$attachment = get_post( $attachment_id );
		$metadata   = wp_get_attachment_metadata( $attachment_id );
		$file_path  = get_attached_file( $attachment_id, true );
		$file_identity = self::publication_file_identity( is_string( $file_path ) ? $file_path : '' );
		$identity   = array(
			'attachment_id'       => $attachment_id,
			'url'                 => esc_url_raw( (string) wp_get_attachment_image_url( $attachment_id, 'full' ) ),
			'attached_file'       => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'attachment_revision_diagnostic' => $attachment instanceof WP_Post ? (string) $attachment->post_modified_gmt : '',
			'metadata_digest'     => hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( is_array( $metadata ) ? $metadata : array() ) ) ?: '[]' ),
			'source_alt'          => trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			'file_identity'       => $file_identity,
		);
		$identity['identity_revision'] = 'mi_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( self::publication_featured_image_revision_fields( $identity ) ) ) ?: '' ), 0, 40 );
		return $identity;
	}

	/** Sample one attachment file consistently before accepting its byte digest. */
	private static function publication_file_identity( string $file_path ): array {
		$unavailable = array( 'available' => false, 'size' => 0, 'mtime' => 0, 'sha256' => '', 'unavailable_reason' => 'attachment_file_unreadable' );
		if ( '' === $file_path || ! is_readable( $file_path ) ) { return $unavailable; }
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			clearstatcache( true, $file_path );
			$before = stat( $file_path );
			$digest = hash_file( 'sha256', $file_path );
			clearstatcache( true, $file_path );
			$after = stat( $file_path );
			if ( is_array( $before ) && is_array( $after ) && is_string( $digest ) && '' !== $digest && self::publication_file_sample_is_stable( $before, $after ) ) {
				return array( 'available' => true, 'size' => (int) $after['size'], 'mtime' => (int) $after['mtime'], 'sha256' => $digest, 'unavailable_reason' => '' );
			}
			$unavailable['unavailable_reason'] = 'attachment_file_changed_during_hash';
		}
		return $unavailable;
	}

	/** Compare every stat field that can expose replacement during hashing. */
	private static function publication_file_sample_is_stable( array $before, array $after ): bool {
		foreach ( array( 'dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'rdev', 'size', 'mtime', 'ctime' ) as $field ) {
			if ( ! array_key_exists( $field, $before ) || ! array_key_exists( $field, $after ) || (string) $before[ $field ] !== (string) $after[ $field ] ) { return false; }
		}
		return true;
	}

	/**
	 * Keep operational timestamps as diagnostics, outside publication authority.
	 *
	 * @return array<string,mixed>
	 */
	private static function publication_featured_image_revision_fields( array $identity ): array {
		$file = isset( $identity['file_identity'] ) && is_array( $identity['file_identity'] ) ? $identity['file_identity'] : array();
		return array(
			'attachment_id'    => absint( $identity['attachment_id'] ?? 0 ),
			'url'              => esc_url_raw( (string) ( $identity['url'] ?? '' ) ),
			'attached_file'    => (string) ( $identity['attached_file'] ?? '' ),
			'metadata_digest'  => (string) ( $identity['metadata_digest'] ?? '' ),
			'source_alt'       => (string) ( $identity['source_alt'] ?? '' ),
			'file_identity'    => array(
				'available'          => ! empty( $file['available'] ),
				'size'               => (int) ( $file['size'] ?? 0 ),
				'sha256'             => (string) ( $file['sha256'] ?? '' ),
				'unavailable_reason' => sanitize_key( (string) ( $file['unavailable_reason'] ?? '' ) ),
			),
		);
	}

	/** Return only content-addressed featured-media authority fields. */
	private static function publication_featured_image_revision_identity( $post ): array {
		$identity = self::publication_featured_image_identity( $post );
		$revision = self::publication_featured_image_revision_fields( $identity );
		$revision['identity_revision'] = (string) ( $identity['identity_revision'] ?? '' );
		return $revision;
	}

	/**
	 * Build the complete, data-driven public source surface.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_publication_surface_manifest( WP_Post $source ): array {
		$canonical_route = self::json_post_meta_value( (int) $source->ID, self::META_CANONICAL_ROUTE );
		return array(
			'schema_version'   => 1,
			'source_id'        => (int) $source->ID,
			'post_type'        => (string) $source->post_type,
			'content_revision' => self::source_hash( $source ),
			'route'            => array(
				'post_name'       => (string) $source->post_name,
				'post_parent'     => (int) $source->post_parent,
				'canonical_route' => self::translation_job_canonicalize( $canonical_route ),
			),
			'taxonomies'       => self::translation_job_canonicalize( self::post_taxonomy_payload( $source ) ),
			'design_revision'  => (string) ( self::source_design_contract( $source )['design_hash'] ?? '' ),
			'media'            => array(
				'featured_image' => self::publication_featured_image_revision_identity( $source ),
			),
		);
	}

	/**
	 * Content-address the full source publication surface.
	 */
	private static function source_publication_surface_revision( WP_Post $source ): string {
		return 'ssr_' . substr( hash( 'sha256', wp_json_encode( self::translation_job_canonicalize( self::source_publication_surface_manifest( $source ) ) ) ?: '' ), 0, 40 );
	}

	/**
	 * Mark Source Inventory dirty when a source featured-image relation changes.
	 *
	 * @param mixed  $meta_id Meta ID or list of IDs, depending on the WordPress hook.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function mark_source_inventory_dirty_on_media_meta( $meta_id, int $object_id, string $meta_key, $meta_value = null ): void {
		unset( $meta_id, $meta_value );
		$post = get_post( $object_id );
		if ( $post instanceof WP_Post && self::is_translatable_post_type( (string) $post->post_type ) ) {
			self::mark_source_inventory_dirty( $object_id );
			return;
		}
		if ( '_thumbnail_id' === $meta_key ) {
			self::mark_source_inventory_dirty( $object_id );
			return;
		}
		if ( in_array( $meta_key, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' ), true ) ) {
			self::mark_source_inventory_dirty_for_attachment( $object_id );
		}
	}

	/** Mark Inventory dirty when an attachment used by any source mutates. */
	public static function mark_source_inventory_dirty_for_attachment( int $attachment_id ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		$source_ids = get_posts(
			array(
				'post_type'      => self::translatable_post_types(),
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded invalidation lookup at an attachment mutation seam.
				'meta_value'     => (string) $attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded invalidation lookup at an attachment mutation seam.
			)
		);
		if ( $source_ids ) {
			self::mark_source_inventory_dirty();
		}
	}
}
