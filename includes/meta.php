<?php namespace silverscreen\plugins\photo_credit;
use DOMDocument;
use DOMXPath;

/**
 * This class is the real work horse of this plugin.
 * It imports and handles all metadata related to photo credit and copyright.
 * GPS data is thrown in as an additional goodie.
 *
 * @author Per Egil Roksvaag
 * @copyright Silverscreen Tours GmbH
 * @license MIT
 */
class Meta
{
	use Singleton;

	/**
	 * @var string The class meta key.
	 */
	const META_CREDIT = Main::PREFIX . '_credit';

	/**
	 * @var string The class hooks.
	 */
	const FILTER_READ_META      = Main::PREFIX . '_read_meta';
	const FILTER_READ_XMP_META  = Main::PREFIX . '_read_xmp_meta';
	const FILTER_READ_IPTC_META = Main::PREFIX . '_read_iptc_meta';
	const FILTER_READ_EXIF_META = Main::PREFIX . '_read_exif_meta';
	const FILTER_FORM_FIELDS    = Main::PREFIX . '_form_fields';
	const FILTER_META_FIELDS    = Main::PREFIX . '_meta_fields';
	const FILTER_SET_META       = Main::PREFIX . '_set_meta';
	const FILTER_GET_META       = Main::PREFIX . '_get_meta';

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	public function init() {
		//	Adds WebP images to exif reading.
		add_filter( 'wp_read_image_metadata', array( $this, 'wp_read_image_metadata' ), 10, 4 );

		//	Adds EXIF GPS location and Source fields to image metadata on upload.
		add_filter( 'wp_read_image_metadata_types', array( $this, 'wp_read_image_metadata_types' ) );

		//	Add new image metadata to EMPTY post meta fields.
		add_filter( 'wp_update_attachment_metadata', array( $this, 'wp_update_attachment_metadata' ), 10, 2 );

		//	Add custom fields to the media uploader.
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields_to_edit' ), 10, 2 );

		//	Saves attachment metadata.
		add_filter( 'attachment_fields_to_save', array( $this, 'attachment_fields_to_save' ), 10, 2 );

		//	Include credit metadata in media search
		add_action( 'posts_clauses', array( $this, 'posts_clauses' ), 10, 2 );

		//	Deletes class metadata on plugin delete
		add_action( Main::ACTION_DELETE, array( $this, 'delete' ) );
	}

	/* -------------------------------------------------------------------------
	 * WordPress callbacks to add and modify image metadata fields.
	 * ---------------------------------------------------------------------- */

	/**
	 * Adds WebP images to exif reading.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/wp_read_image_metadata_types/
	 *
	 * @param array $types Image types to check for exif data.
	 * @return array The modified array.
	 */
	public function wp_read_image_metadata_types( $types ) {
		if ( ! in_array( IMAGETYPE_WEBP, $types ) ) {
			$types[] = IMAGETYPE_WEBP;
		}
		return $types;
	}

	/**
	 * Adds EXIF GPS location and Source fields to image metadata on upload.
	 *
	 * @see https://codex.wordpress.org/Function_Reference/wp_read_image_metadata
	 * @see http://metadatadeluxe.pbworks.com/w/page/47662311/Top%2010%20List%20of%20Embedded%20Metadata%20Properties
	 *
	 * @param array $meta Image metadata.
	 * @param string $file Path to image file.
	 * @param int $sourceImageType Type of image.
	 * @param array $iptc IPTC data.
	 * @return array The modified metadata.
	 */
	public function wp_read_image_metadata( $meta, $file, $sourceImageType, $iptc ) {
		$data = array_keys( $this->get_meta_fields( 'all' ) );
		$data = array_fill_keys( $data, array() );

		$xpath = $this->read_xmp_meta( $data, $file, $sourceImageType );
		$exif  = $this->read_exif_meta( $data, $file, $sourceImageType );
		$iptc  = $this->read_iptc_meta( $data, $iptc, $file, $sourceImageType );

		foreach ( $data as $key => $collection ) {
			switch ( $key ) {
				case 'keywords':
					$collection = array_map( array( $this, 'clean' ), $collection );
					$value      = array_filter( array_unique( $collection ) );
					break;
				case 'created_timestamp':
					$collection = array_filter( array_unique( $collection ) );
					sort( $collection, SORT_NUMERIC );
					$value = $collection[0] ?? 0;
					break;
				case 'position':
					$collection = array_filter( $collection );
					$value      = $collection[0] ?? array();
					break;
				default:
					$collection = array_map( array( $this, 'clean' ), $collection );
					$collection = array_filter( array_unique( $collection ) );
					$value      = implode( ' / ', $collection );
					break;
			}
			$value      = apply_filters( self::FILTER_READ_META . '_' . $key, $value, $meta, $data, $key );
			$meta[$key] = $value;
		}
		$meta = apply_filters( self::FILTER_READ_META, $meta, $file, $sourceImageType, $iptc, $exif, $xpath );
		return wp_kses_post_deep( $meta );
	}

	/**
	 * Add new image metadata to EMPTY post meta fields.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/wp_update_attachment_metadata/
	 *
	 * @param array $meta Image metadata.
	 * @param int The Post ID.
	 * @return array The Image metadata (unchanged).
	 */
	public function wp_update_attachment_metadata( $meta, $post_id ) {
		if ( isset( $meta['image_meta'] ) && is_array( $meta['image_meta'] ) ) {
			$data = $this->get_metadata( $post_id );
			$data = array_merge( $meta['image_meta'], $data );
			$this->set_metadata( $post_id, $data );
		}
		return $meta;
	}

	/**
	 * Add custom fields to the media uploader.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/attachment_fields_to_edit/
	 * @see https://code.tutsplus.com/articles/creating-custom-fields-for-attachments-in-wordpress--net-13076
	 *
	 * @param array $form_fields Fields to include in attachment form.
	 * @param WP_Post $post Attachment record in database.
	 * @return array Modified form fields.
	 */
	public function attachment_fields_to_edit( $form_fields, $post ) {
		$meta   = wp_get_attachment_metadata( $post->ID );
		$data   = $this->get_metadata( $post->ID );
		$fields = $this->get_meta_fields();

		foreach ( $fields as $key => $label ) {
			switch ( $key ) {
				case 'keywords':
					$value = implode( ', ', $data[$key] ?? array() );
					break;
				case 'created_timestamp':
					$value = date( 'Y-m-d H:i:s', $data[$key] ?? 0 );
					break;
				case 'position':
					$value = implode( ', ', $data[$key] ?? array() );
					break;
				default:
					$value = $data[$key] ?? '';
					break;
			}
			$field = array( 'label' => $label, 'value' => $value );
			if ( ! empty( $meta['image_meta'][$key] ) ) {
				if ( empty( $data[$key] ) || $data[$key] != $meta['image_meta'][$key] ) {
					$field['helps'] = $meta['image_meta'][$key];
				}
			}
			$form_fields[$key] = apply_filters( self::FILTER_FORM_FIELDS . '_' . $key, $field, $data, $meta, $key );
		}
		$form_fields = apply_filters( self::FILTER_FORM_FIELDS, $form_fields, $data, $post->ID );
		return wp_kses_post_deep( $form_fields );
	}

	/**
	 * Saves attachment metadata.
	 *
	 * @param array $post The current attachment post
	 * @param array $attachment Array of metadata values to save
	 * @return array The current attachment post
	 */
	public function attachment_fields_to_save( $post, $attachment ) {
		$data       = $this->get_metadata( $post['ID'] );
		$attachment = array_map( 'trim', $attachment );

		foreach ( $attachment as $key => $value ) {
			switch ( $key ) {
				case 'keywords':
					$value = explode( ',', $value );
					$value = array_filter( array_map( array( $this, 'clean' ), $value ) );
					break;
				case 'created_timestamp':
					$value = strtotime( $value );
					break;
				case 'position':
					$value  = explode( ',', $value );
					$value  = array_slice( array_map( 'floatval', array_filter( $value, 'is_numeric' ) ), 0, 3 );
					$fields = array_slice( array( 'latitude', 'longitude', 'altitude' ), 0, count( $value ) );
					$value  = count( $value ) >= 2 ? array_combine( $fields, $value ) : array();
					break;
			}
			$value      = apply_filters( self::FILTER_SET_META . '_' . $key, $value, $attachment, $data, $key );
			$data[$key] = $value;
		}
		$this->set_metadata( $post['ID'], $data );
		return $post;
	}

	/**
	 * Includes credit metadata in search.
	 *
	 * @see _filter_query_attachment_filenames in wp-includes/post.php
	 *
	 * @param string[] $clauses Associative array of the clauses for the query.
	 * @param WP_Query $query The WP_Query instance (passed by reference).
	 */
	public function posts_clauses( $clauses, $query ) {
		global $wpdb;

		if ( $query->is_search() && 'attachment' == $query->get( 'post_type' ) ) {
			$meta_key = self::META_CREDIT;
			remove_filter( __FUNCTION__, array( $this, __FUNCTION__ ) );

			$clauses['join'] .= ' ' . join( ' ', array(
				"LEFT JOIN {$wpdb->postmeta} AS credit",
				"ON ( {$wpdb->posts}.ID = credit.post_id AND credit.meta_key = '{$meta_key}' )",
			) );

			$clauses['where'] = preg_replace(
				"/\\({$wpdb->posts}.post_content (NOT LIKE|LIKE) (\\'[^']+\\')\\)/",
				'$0 OR ( credit.meta_value $1 $2 )',
				$clauses['where']
			);
		}
		return $clauses;
	}

	/* -------------------------------------------------------------------------
	 * Core methods
	 * ---------------------------------------------------------------------- */

	/**
	 * Gets the metadata fields to edit and display.
	 *
	 * @param string $mode The fields to get: all, header, fields.
	 * @return array The metadata fields and labels.
	 */
	public function get_meta_fields( $mode = 'fields' ) {
		$header = array(
			'title'   => __( 'Title', 'wordpress' ),
			'caption' => __( 'Caption', 'wordpress' ),
		);
		$fields = array(
			'credit'            => __( 'Credit', 'photo-credit' ),
			'copyright'         => __( 'Copyright', 'photo-credit' ),
			'source'            => __( 'Source', 'photo-credit' ),
			'license'           => __( 'Usage terms', 'photo-credit' ),
			'keywords'          => __( 'Keywords', 'photo-credit' ),
			'created_timestamp' => __( 'Created', 'photo-credit' ),
			'position'          => __( 'Position', 'photo-credit' ),
		);
		switch ( $mode ) {
			case 'all':
				$result = array_merge( $header, $fields );
				break;
			case 'header':
				$result = $header;
				break;
			case 'fields':
				$result = $fields;
				break;
			default:
				$result = $fields;
				break;
		}
		return apply_filters( self::FILTER_META_FIELDS, $result, $mode );
	}

	/**
	 * Gets image metadata.
	 *
	 * @param int $post_id A Post ID.
	 * @return array The metadata
	 */
	public function get_metadata( $post_id, $meta_key = self::META_CREDIT ) {
		$data = get_post_meta( $post_id, $meta_key, true );
		$data = is_array( $data ) ? array_filter( $data ) : array();
		$data = array_intersect_key( $data, $this->get_meta_fields() );
		return apply_filters( self::FILTER_GET_META, $data, $post_id );
	}

	/**
	 * Saves the image metadata.
	 *
	 * @param int $post_id A Post ID.
	 * @param array The metadata to save.
	 * @return bool True is successful, False otherwise.
	 */
	public function set_metadata( $post_id, $data ) {
		$data = is_array( $data ) ? array_filter( $data ) : array();
		$data = array_intersect_key( $data, $this->get_meta_fields() );
		$data = apply_filters( self::FILTER_SET_META, $data, $post_id );
		return (bool) update_post_meta( $post_id, self::META_CREDIT, wp_kses_post_deep( $data ) );
	}

	/* -------------------------------------------------------------------------
	 * Metadata Readers
	 * ---------------------------------------------------------------------- */

	/**
	 * Reads XMP metadata.
	 *
	 * @see http://metadatadeluxe.pbworks.com/w/page/47662311/Top%2010%20List%20of%20Embedded%20Metadata%20Properties
	 *
	 * @param array $meta Image metadata to modify.
	 * @param string $file Path to image file.
	 * @param int $sourceImageType Type of image.
	 * @return DOMXPath|null
	 */
	public function read_xmp_meta( &$meta, $file, $sourceImageType ) {
		if ( class_exists( 'DOMDocument' ) && $this->read_xmp_data( $file, $xmp ) ) {
			$doc = new DOMDocument( '1.0', 'UTF-8' );
			$doc->loadXML( $xmp );
			$doc->encoding = 'UTF-8';

			$xpath = new DOMXPath( $doc );
			$xpath->registerNamespace( 'rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' );
			$xpath->registerNamespace( 'xap', 'http://ns.adobe.com/xap/1.0/' );
			$xpath->registerNamespace( 'exif', 'http://ns.adobe.com/tiff/1.0/' );
			$xpath->registerNamespace( 'tiff', 'http://ns.adobe.com/tiff/1.0/' );
			$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );
			$xpath->registerNamespace( 'xmpRights', 'http://ns.adobe.com/xap/1.0/rights/' );
			$xpath->registerNamespace( 'photoshop', 'http://ns.adobe.com/photoshop/1.0/' );

			foreach ( $xpath->query( '//dc:title/rdf:Alt/rdf:li' ) as $item ) {                 // GIMP: Document Title
				$meta['title'][] = trim( $item->textContent );
			}
			foreach ( $xpath->query( '//dc:description/rdf:Alt/rdf:li' ) as $item ) {           // GIMP: Description
				$meta['caption'][] = trim( $item->textContent );
			}
			foreach ( $xpath->query( '//dc:creator/rdf:Seq/rdf:li' ) as $item ) {               // GIMP: Author
				$meta['credit'][] = trim( $item->textContent );
			}
			foreach ( $xpath->query( '//rdf:Description/@photoshop:Credit' ) as $item ) {       // GIMP: Credit line
				$meta['credit'][] = trim( $item->nodeValue );
			}
			foreach ( $xpath->query( '//dc:rights/rdf:Alt/rdf:li' ) as $item ) {                // GIMP: Copyright Notice
				$meta['copyright'][] = trim( $item->textContent );
			}
			foreach ( $xpath->query( '//rdf:Description/@photoshop:Source' ) as $item ) {       // GIMP: Source
				$meta['source'][] = trim( $item->nodeValue );
			}
			foreach ( $xpath->query( '//xmpRights:UsageTerms/rdf:Alt/rdf:li' ) as $item ) {     // GIMP: Usage Terms
				$meta['license'][] = trim( $item->textContent );
			}
			foreach ( $xpath->query( '//dc:subject/rdf:Bag/rdf:li' ) as $item ) {               // GIMP: Keywords
				$meta['keywords'][] = trim( $item->textContent );
			}
			foreach ( $xpath->query( '//rdf:Description/@xap:CreateDate' ) as $item ) {
				$meta['created_timestamp'][] = strtotime( $item->nodeValue );
			}
			foreach ( $xpath->query( '//rdf:Description/@exif:DateTimeOriginal' ) as $item ) {
				$meta['created_timestamp'][] = strtotime( $item->nodeValue );
			}
			foreach ( $xpath->query( '//rdf:Description/@photoshop:DateCreated' ) as $item ) {  // GIMP: Creation Date
				$meta['created_timestamp'][] = strtotime( $item->nodeValue );
			}
			$meta['position'];
			$meta = apply_filters( self::FILTER_READ_XMP_META, $meta, $xpath, $xmp, $file, $sourceImageType );
			return $xpath;
		}
	}

	/**
	 * Reads IPTC metadata.
	 *
	 * @param array $meta Image metadata to modify.
	 * @param array $iptc IPTC data.
	 * @param string $file Path to image file.
	 * @param int $sourceImageType Type of image.
	 * @return array IPTC data.
	 */
	public function read_iptc_meta( &$meta, $iptc, $file, $sourceImageType ) {
		$meta['title'][]     = trim( $iptc['2#005'][0] ?? '' );    // ObjectName
		$meta['title'][]     = trim( $iptc['2#105'][0] ?? '' );    // Headline
		$meta['caption'][]   = trim( $iptc['2#120'][0] ?? '' );    // Caption
		$meta['credit'][]    = trim( $iptc['2#080'][0] ?? '' );    // ByLine
		$meta['credit'][]    = trim( $iptc['2#110'][0] ?? '' );    // Credits
		$meta['copyright'][] = trim( $iptc['2#116'][0] ?? '' );    // Copyright
		$meta['source'][]    = trim( $iptc['2#115'][0] ?? '' );    // Source
		$meta['license'];

		foreach ( ( $iptc['2#025'] ?? array() ) as $keyword ) {      // Keywords
			$meta['keywords'][] = trim( $keyword );
		}
		$meta['created_timestamp'][] = strtotime( ( $iptc['2#055'][0] ?? '' ) . ' ' . ( $iptc['2#060'][0] ?? '' ) ); // DateCreated, TimeCreated
		$meta['created_timestamp'][] = strtotime( ( $iptc['2#062'][0] ?? '' ) . ' ' . ( $iptc['2#063'][0] ?? '' ) ); // DigitalCreationDate, DigitalCreationTime
		$meta['position'];

		$meta = apply_filters( self::FILTER_READ_IPTC_META, $meta, $iptc, $file, $sourceImageType );
		return $iptc;
	}

	/**
	 * Reads Exif metadata.
	 *
	 * @param array $meta Image metadata to modify.
	 * @param string $file Path to image file.
	 * @param int $sourceImageType Type of image.
	 * @return array Exif data.
	 */
	public function read_exif_meta( &$meta, $file, $sourceImageType ) {
		$types = array( IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM );
		$types = apply_filters( 'wp_read_image_metadata_types', $types );

		if ( is_callable( 'exif_read_data' ) && in_array( $sourceImageType, $types ) ) {
			$exif = @exif_read_data( $file );

			$meta['title'];
			$meta['caption'][]   = trim( $exif['ImageDescription'] ?? '' );
			$meta['credit'][]    = trim( $exif['Artist'] ?? '' );
			$meta['credit'][]    = trim( $exif['Author'] ?? '' );
			$meta['copyright'][] = trim( $exif['Copyright'] ?? '' );
			$meta['source'];
			$meta['license'];
			$meta['keywords'];
			$meta['position'][] = $this->read_exif_gps( $exif );

			if ( ! empty( $exif['DateTimeOriginal'] ) && strtotime( $exif['DateTimeOriginal'] ) ) {
				$meta['created_timestamp'][] = wp_exif_date2ts( $exif['DateTimeOriginal'] );
			}
			if ( ! empty( $exif['DateTimeDigitized'] ) && strtotime( $exif['DateTimeDigitized'] ) ) {
				$meta['created_timestamp'][] = wp_exif_date2ts( $exif['DateTimeDigitized'] );
			}

			$meta = apply_filters( self::FILTER_READ_EXIF_META, $meta, $exif, $file, $sourceImageType );
			return $exif;
		}

		return array();
	}

	/**
	 * Reads EXIF GPS metadata.
	 *
	 * @param array $exif EXIF metadata returned from exif_read_data().
	 * @return array If found, contains the fields latitude, longitude and altitude as signed decimal values.
	 */
	public function read_exif_gps( $exif ) {
		$position = array();

		if ( ! empty( $exif['GPSLatitude'] ) ) {
			$position['latitude'] = wp_exif_frac2dec( $exif['GPSLatitude'][0] ) + wp_exif_frac2dec( $exif['GPSLatitude'][1] ) / 60 + wp_exif_frac2dec( $exif['GPSLatitude'][2] ) / 3600;
		}
		if ( ! empty( $exif['GPSLongitude'] ) ) {
			$position['longitude'] = wp_exif_frac2dec( $exif['GPSLongitude'][0] ) + wp_exif_frac2dec( $exif['GPSLongitude'][1] ) / 60 + wp_exif_frac2dec( $exif['GPSLongitude'][2] ) / 3600;
		}
		if ( ! empty( $exif['GPSAltitude'] ) ) {
			$position['altitude'] = wp_exif_frac2dec( $exif['GPSAltitude'] );
		}
		if ( ! empty( $exif['GPSLatitudeRef'] ) && 'S' == $exif['GPSLatitudeRef'] ) {
			$position['latitude'] *= -1;
		}
		if ( ! empty( $exif['GPSLongitudeRef'] ) && 'W' == $exif['GPSLongitudeRef'] ) {
			$position['longitude'] *= -1;
		}
		if ( ! empty( $exif['GPSAltitudeRef'] ) && 1 == $exif['GPSAltitudeRef'] ) {
			$position['altitude'] *= -1;
		}
		return $position;
	}

	/* -------------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Reads rav XMP data from an image file.
	 *
	 * @param string $file Path to image file.
	 * @param string $xmp The raw XMP data is written to this param if found.
	 * @return bool true if XMP metadata was found, false otherwise.
	 */
	public function read_xmp_data( $file, &$xmp = '' ) {
		$chunk_size = 65536;            // Read 64k at a time.
		$max_size   = 1048576;          // Search max 1mb for metadata.
		$start_tag  = '<x:xmpmeta';
		$end_tag    = '</x:xmpmeta>';
		$buffer     = '';

		if ( is_readable( $file ) && $handle = fopen( $file, 'rb' ) ) {
			while ( ( feof( $handle ) == false ) && ( ftell( $handle ) < $max_size ) ) {
				$buffer .= fread( $handle, $chunk_size );
				if ( is_int( $end_pos = strrpos( $buffer, $end_tag ) ) ) {
					if ( is_int( $start_pos = strrpos( $buffer, $start_tag ) ) ) {
						$xmp = substr( $buffer, $start_pos, $end_pos - $start_pos + strlen( $end_tag ) );
					}
					break;
				}
			}
			fclose( $handle );
			return ! empty( $xmp );
		}
		return false;
	}

	/**
	 * Trims and sanitizes strings before saving.
	 *
	 * @param $value
	 * @return string
	 */
	public function clean( $value ) {
		$value = trim( $value );
		return ! seems_utf8( $value ) ? utf8_encode( $value ) : $value;
	}

	/**
	 * Removes custom metadata on plugin deletion.
	 */
	public function delete() {
		if ( is_admin() && current_user_can( 'delete_plugins' ) ) {
			if ( get_option( Admin::OPTION_DELETE_SETTINGS ) ) {
				delete_metadata( 'post', 0, self::META_CREDIT, '', true );
			}
			delete_metadata( 'post', 0, self::META_CREDIT . '_credit', '', true );
			delete_metadata( 'post', 0, self::META_CREDIT . '_copyright', '', true );
		}
	}
}