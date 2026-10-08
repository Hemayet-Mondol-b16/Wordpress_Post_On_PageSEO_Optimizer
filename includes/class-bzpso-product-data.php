<?php
/**
 * Reading, updating, backing up and restoring product content.
 *
 * @package PostSeoOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product content access. Core product fields are written through the WooCommerce
 * CRUD API so caches, lookup tables and hooks behave exactly like a normal save.
 */
class BZPSO_Product_Data {

	const META_BACKUP    = '_bzpso_original';
	const META_OPTIMIZED = '_bzpso_optimized';
	const META_USER      = '_bzpso_optimized_by';

	/**
	 * Fields the plugin can optimize, in review-screen order.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'focus_keyphrase'   => __( 'Focus keyphrase', 'post-seo-optimizer' ),
			'title'             => __( 'Product title', 'post-seo-optimizer' ),
			'seo_title'         => __( 'SEO title', 'post-seo-optimizer' ),
			'meta_description'  => __( 'Meta description', 'post-seo-optimizer' ),
			'slug'              => __( 'URL slug', 'post-seo-optimizer' ),
			'short_description' => __( 'Short description', 'post-seo-optimizer' ),
			'description'       => __( 'Description', 'post-seo-optimizer' ),
			'image_alt'         => __( 'Image alt text', 'post-seo-optimizer' ),
			'tags'              => __( 'Product tags', 'post-seo-optimizer' ),
		);
	}

	/**
	 * Compact product data sent to the AI. Long texts are converted to plain text,
	 * repeated supplier lines are removed and the result is truncated, which keeps
	 * input tokens low without losing facts.
	 *
	 * @param WC_Product $product Product.
	 * @param string[]   $fields  Fields being generated (empty = all).
	 * @return array
	 */
	public static function source_data( WC_Product $product, array $fields = array() ) {
		$id          = $product->get_id();
		$description = self::plain_text( $product->get_description( 'edit' ), 6000, true );
		$short       = self::plain_text( $product->get_short_description( 'edit' ), 1500, true );

		// Many suppliers repeat the short description inside the long one.
		if ( '' !== $short && false !== mb_stripos( $description, $short ) ) {
			$short = '';
		}

		$data = array(
			'title'             => self::plain_text( $product->get_name( 'edit' ), 300 ),
			'short_description' => $short,
			'description'       => $description,
			'categories'        => self::term_names( $id, 'product_cat' ),
			'attributes'        => self::attributes( $product ),
		);

		// Existing tags only matter when tags are being suggested.
		if ( ! $fields || in_array( 'tags', $fields, true ) ) {
			$data['existing_tags'] = self::term_names( $id, 'product_tag' );
		}

		if ( taxonomy_exists( 'product_brand' ) ) {
			$data['brand'] = self::term_names( $id, 'product_brand' );
		}

		$weight = $product->get_weight( 'edit' );
		if ( '' !== (string) $weight ) {
			$data['weight'] = $weight . ' ' . get_option( 'woocommerce_weight_unit' );
		}

		$dimensions = $product->get_dimensions( false );
		if ( is_array( $dimensions ) && array_filter( $dimensions ) ) {
			$data['dimensions'] = html_entity_decode( wp_strip_all_tags( wc_format_dimensions( $dimensions ) ), ENT_QUOTES, 'UTF-8' );
		}

		return array_filter(
			$data,
			static function ( $value ) {
				return '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * Current values of every optimizable field.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function current_values( WC_Product $product ) {
		$id       = $product->get_id();
		$image_id = (int) $product->get_image_id( 'edit' );

		return array(
			'focus_keyphrase'   => BZPSO_SEO_Meta::get( $id, 'focus_keyphrase' ),
			'title'             => $product->get_name( 'edit' ),
			'seo_title'         => BZPSO_SEO_Meta::get( $id, 'seo_title' ),
			'meta_description'  => BZPSO_SEO_Meta::get( $id, 'meta_description' ),
			'slug'              => $product->get_slug( 'edit' ),
			'short_description' => $product->get_short_description( 'edit' ),
			'description'       => $product->get_description( 'edit' ),
			'image_alt'         => $image_id ? (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '',
			'tags'              => implode( ', ', self::term_names( $id, 'product_tag' ) ),
		);
	}

	/**
	 * Current values for display (descriptions as plain text).
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function display_values( WC_Product $product ) {
		$values = self::current_values( $product );
		foreach ( array( 'short_description', 'description' ) as $key ) {
			$values[ $key ] = self::plain_text( $values[ $key ], 1500 );
		}
		return $values;
	}

	/**
	 * Apply reviewed values. The original content is backed up the first time.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $fields  Raw (unslashed) field values from the review screen.
	 * @return string[]|WP_Error Warnings, or an error.
	 */
	public static function apply( WC_Product $product, array $fields ) {
		$values = BZPSO_Sanitizer::for_save( array_intersect_key( $fields, self::fields() ) );
		if ( empty( $values ) ) {
			return new WP_Error( 'bzpso_nothing', __( 'Select at least one field with a value to apply.', 'post-seo-optimizer' ) );
		}

		$id = $product->get_id();

		if ( ! metadata_exists( 'post', $id, self::META_BACKUP ) ) {
			add_post_meta( $id, self::META_BACKUP, wp_slash( self::snapshot( $product ) ), true );
		}

		$warnings = array();

		// SEO meta first, so the indexable Yoast builds during the product save is current.
		foreach ( array_keys( BZPSO_SEO_Meta::KEYS ) as $field ) {
			if ( isset( $values[ $field ] ) ) {
				BZPSO_SEO_Meta::set( $id, $field, $values[ $field ] );
			}
		}

		if ( isset( $values['image_alt'] ) ) {
			$warnings = array_merge( $warnings, self::update_image_alts( $product, $values['image_alt'] ) );
		}
		if ( isset( $values['tags'] ) ) {
			$warnings = array_merge( $warnings, self::add_tags( $id, $values['tags'] ) );
		}

		if ( isset( $values['title'] ) ) {
			$product->set_name( $values['title'] );
		}
		if ( isset( $values['short_description'] ) ) {
			$product->set_short_description( $values['short_description'] );
		}
		if ( isset( $values['description'] ) ) {
			$product->set_description( $values['description'] );
		}
		if ( isset( $values['slug'] ) ) {
			$product->set_slug( $values['slug'] );
		}
		$product->save();

		update_post_meta( $id, self::META_OPTIMIZED, time() );
		update_post_meta( $id, self::META_USER, get_current_user_id() );

		return $warnings;
	}

	/**
	 * Restore the content saved before the first optimization.
	 *
	 * @param WC_Product $product Product.
	 * @return string[]|WP_Error Warnings, or an error.
	 */
	public static function restore( WC_Product $product ) {
		$id     = $product->get_id();
		$backup = get_post_meta( $id, self::META_BACKUP, true );
		if ( ! is_array( $backup ) ) {
			return new WP_Error( 'bzpso_no_backup', __( 'No original content backup was found for this product.', 'post-seo-optimizer' ) );
		}

		$warnings = array();
		$text     = static function ( $key ) use ( $backup ) {
			return isset( $backup[ $key ] ) && is_scalar( $backup[ $key ] ) ? (string) $backup[ $key ] : '';
		};

		foreach ( array_keys( BZPSO_SEO_Meta::KEYS ) as $field ) {
			BZPSO_SEO_Meta::set( $id, $field, isset( $backup['seo'][ $field ] ) ? (string) $backup['seo'][ $field ] : '' );
		}

		if ( ! empty( $backup['image_alts'] ) && is_array( $backup['image_alts'] ) ) {
			foreach ( $backup['image_alts'] as $attachment_id => $alt ) {
				$attachment_id = (int) $attachment_id;
				if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
					continue;
				}
				if ( '' === (string) $alt ) {
					delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
				} else {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( (string) $alt ) );
				}
			}
		}

		if ( isset( $backup['tags'] ) && is_array( $backup['tags'] ) ) {
			if ( current_user_can( 'assign_product_terms' ) ) {
				wp_set_object_terms( $id, array_map( 'intval', $backup['tags'] ), 'product_tag', false );
			} else {
				$warnings[] = __( 'Tags were not restored because you are not allowed to assign product tags.', 'post-seo-optimizer' );
			}
		}

		if ( '' !== $text( 'title' ) ) {
			$product->set_name( $text( 'title' ) );
		}
		$product->set_short_description( $text( 'short_description' ) );
		$product->set_description( $text( 'description' ) );
		if ( '' !== $text( 'slug' ) ) {
			$product->set_slug( $text( 'slug' ) );
		}
		$product->save();

		delete_post_meta( $id, self::META_BACKUP );
		delete_post_meta( $id, self::META_OPTIMIZED );
		delete_post_meta( $id, self::META_USER );

		return $warnings;
	}

	/**
	 * Whether a backup exists.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function has_backup( $product_id ) {
		return metadata_exists( 'post', $product_id, self::META_BACKUP );
	}

	/**
	 * Snapshot of everything the plugin may change.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function snapshot( WC_Product $product ) {
		$id = $product->get_id();

		$seo = array();
		foreach ( array_keys( BZPSO_SEO_Meta::KEYS ) as $field ) {
			$seo[ $field ] = BZPSO_SEO_Meta::get( $id, $field );
		}

		$alts = array();
		foreach ( self::image_ids( $product ) as $attachment_id ) {
			$alts[ $attachment_id ] = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		}

		$tags = wp_get_object_terms( $id, 'product_tag', array( 'fields' => 'ids' ) );

		return array(
			'version'           => 1,
			'time'              => time(),
			'user'              => get_current_user_id(),
			'title'             => $product->get_name( 'edit' ),
			'short_description' => $product->get_short_description( 'edit' ),
			'description'       => $product->get_description( 'edit' ),
			'slug'              => $product->get_slug( 'edit' ),
			'seo'               => $seo,
			'image_alts'        => $alts,
			'tags'              => is_wp_error( $tags ) ? array() : array_map( 'intval', $tags ),
		);
	}

	/**
	 * Set alt text on the main image; gallery images get a numbered variant.
	 *
	 * @param WC_Product $product Product.
	 * @param string     $alt     Alt text.
	 * @return string[] Warnings.
	 */
	private static function update_image_alts( WC_Product $product, $alt ) {
		$ids = self::image_ids( $product );
		if ( ! $ids ) {
			return array( __( 'Image alt text was not applied because the product has no images.', 'post-seo-optimizer' ) );
		}

		$skipped  = 0;
		$position = 0;
		foreach ( $ids as $attachment_id ) {
			++$position;
			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				++$skipped;
				continue;
			}

			$text = $alt;
			if ( '' !== $alt && $position > 1 ) {
				/* translators: 1: image alt text, 2: image number. */
				$text = sprintf( _x( '%1$s - view %2$d', 'gallery image alt text', 'post-seo-optimizer' ), $alt, $position );
			}

			if ( '' === $text ) {
				delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
			} else {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $text ) );
			}
		}

		if ( $skipped ) {
			/* translators: %d: number of images. */
			return array( sprintf( _n( 'Alt text was not updated on %d image you are not allowed to edit.', 'Alt text was not updated on %d images you are not allowed to edit.', $skipped, 'post-seo-optimizer' ), $skipped ) );
		}
		return array();
	}

	/**
	 * Add tags to the product (existing tags are kept).
	 *
	 * @param int      $product_id Product id.
	 * @param string[] $names      Tag names.
	 * @return string[] Warnings.
	 */
	private static function add_tags( $product_id, array $names ) {
		if ( ! $names ) {
			return array();
		}
		if ( ! current_user_can( 'assign_product_terms' ) ) {
			return array( __( 'Tags were not applied because you are not allowed to assign product tags.', 'post-seo-optimizer' ) );
		}

		$ids     = array();
		$skipped = 0;
		foreach ( $names as $name ) {
			$term = term_exists( $name, 'product_tag' );
			if ( $term ) {
				$ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				continue;
			}
			if ( ! current_user_can( 'manage_product_terms' ) ) {
				++$skipped;
				continue;
			}
			$created = wp_insert_term( $name, 'product_tag' );
			if ( is_wp_error( $created ) ) {
				if ( 'term_exists' === $created->get_error_code() ) {
					$ids[] = (int) $created->get_error_data();
				} else {
					++$skipped;
				}
				continue;
			}
			$ids[] = (int) $created['term_id'];
		}

		if ( $ids ) {
			wp_set_object_terms( $product_id, array_values( array_unique( array_filter( $ids ) ) ), 'product_tag', true );
		}

		if ( $skipped ) {
			/* translators: %d: number of tags. */
			return array( sprintf( _n( '%d new tag was skipped because you are not allowed to create tags.', '%d new tags were skipped because you are not allowed to create tags.', $skipped, 'post-seo-optimizer' ), $skipped ) );
		}
		return array();
	}

	/**
	 * Featured image first, then gallery images.
	 *
	 * @param WC_Product $product Product.
	 * @return int[]
	 */
	private static function image_ids( WC_Product $product ) {
		$ids = array_merge( array( (int) $product->get_image_id( 'edit' ) ), array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) ) );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Term names for a product (uses the object term cache).
	 *
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @return string[]
	 */
	private static function term_names( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_map(
			static function ( $term ) {
				return html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			},
			$terms
		);
	}

	/**
	 * Product attributes as "Label" => "value, value".
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function attributes( WC_Product $product ) {
		$out = array();
		foreach ( $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$values = $attribute->is_taxonomy()
				? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
				: $attribute->get_options();

			$values = array_filter( array_map( 'wp_strip_all_tags', array_map( 'strval', (array) $values ) ) );
			if ( $values ) {
				$label         = html_entity_decode( wc_attribute_label( $attribute->get_name(), $product ), ENT_QUOTES, 'UTF-8' );
				$out[ $label ] = self::plain_text( implode( ', ', array_slice( $values, 0, 30 ) ), 500 );
			}
		}
		return $out;
	}

	/**
	 * Convert HTML to compact plain text, keeping line structure.
	 *
	 * @param string $html   HTML.
	 * @param int    $limit  Max characters.
	 * @param bool   $unique Drop repeated lines (case-insensitive) before truncating.
	 * @return string
	 */
	public static function plain_text( $html, $limit, $unique = false ) {
		$text = wp_check_invalid_utf8( (string) $html, true );
		$text = strip_shortcodes( $text );
		$text = (string) preg_replace( '#<\s*(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = trim( (string) preg_replace( '/\s*\n\s*/u', "\n", $text ) );

		if ( $unique ) {
			$seen  = array();
			$lines = array();
			foreach ( explode( "\n", $text ) as $line ) {
				$key = mb_strtolower( $line );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$lines[]      = $line;
				}
			}
			$text = implode( "\n", $lines );
		}

		if ( mb_strlen( $text ) > $limit ) {
			$text = rtrim( mb_substr( $text, 0, $limit ) ) . '…';
		}
		return $text;
	}
}
