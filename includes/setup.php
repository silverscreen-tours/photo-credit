<?php namespace silverscreen\plugins\photo_credit;
/**
 * Plugin setup.
 *
 * @author Per Egil Roksvaag
 * @copyright Silverscreen Tours GmbH
 * @license MIT
 */
class Setup
{
	use Singleton;

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		//	add_action( 'widgets_init', array( $this, 'widgets_init' ) );

		if ( empty( is_admin() ) ) {
			//	add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_styles' ) );
			//	add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
		}
	}

	/* -------------------------------------------------------------------------
	 * Plugin setup
	 * ---------------------------------------------------------------------- */

	/**
	 * Loads the translated strings (if any).
	 */
	public function init() {
		$base = dirname( plugin_basename( Main::FILE ) ) . '/languages';
		load_plugin_textdomain( Main::DOMAIN, false, $base );
	}

	/**
	 * Register widgets.
	 */
	public function widgets_init() {
	}

	/**
	 * Enqueues styles.
	 */
	public function wp_enqueue_styles() {
		$args = array( 'inline' => true );
		Asset::instance()->enqueue_style( 'assets/css/photo-credit.min.css', array(), $args );
	}

	/**
	 * Enqueues scripts.
	 */
	public function wp_enqueue_scripts() {
		$args = array( 'async' => true );
		Asset::instance()->enqueue_script( 'assets/js/photo-credit.min.js', array(), $args );
	}
}