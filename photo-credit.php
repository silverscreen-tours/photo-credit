<?php namespace silverscreen\plugins\photo_credit;
/*
 * Plugin Name:       Photo Credit
 * Plugin URI:        https://github.com/silverscreen-tours/photo-credit
 * Description:       Adds photo credit information to media assets.
 *
 * Text Domain:       photo-credit
 * Domain Path:       /languages
 *
 * Author:            Silverscreen Tours
 * Author URI:        https://www.silverscreen.tours/
 *
 * Version:           2.1.2
 * Stable tag:        2.1.2
 * Requires at least: 5.0
 * Tested up to:      5.7
 * Requires PHP:      7.0
 *
 * License:           MIT License
 * License URI:       https://opensource.org/licenses/MIT
 *
 * Copyright © Silverscreen Tours GmbH
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

/**
 * The Photo Credit plugin main class.
 *
 * @author Per Egil Roksvaag
 * @copyright Silverscreen Tours GmbH
 * @license MIT
 *
 * @version 2.1.2
 */
class Main
{
	/**
	 * @var string The plugin file.
	 */
	const FILE = __FILE__;

	/**
	 * Should be identical to "Plugin Name" in the plugin header comment above.
	 *
	 * @var string The plugin name.
	 * @todo Globally search and replace this with your own plugin name.
	 */
	const NAME = 'Photo Credit';

	/**
	 * Must be identical to "Domain Path" in the plugin header comment above.
	 * Use lowercase and hyphens as word breaker.
	 *
	 * @var string The plugin text domain (hyphen).
	 * @todo Globally search and replace this with your own unique text domain
	 */
	const DOMAIN = 'photo-credit';

	/**
	 * Should be similar to self::DOMAIN, only with underscores instead of hyphens.
	 * Use lowercase and underscores as word breaker.
	 *
	 * @var string The plugin prefix (underscore).
	 * @todo Replace this constant with your own unique plugin prefix.
	 */
	const PREFIX = 'photo_credit';

	/**
	 * Should contain the "Version" field in the plugin header comment above.
	 *
	 * @var string The plugin version.
	 * @todo Set your plugin version number.
	 */
	const VERSION = '2.1.2';

	/**
	 * Only requirement constants > '0' will be checked.
	 *
	 * @var string The system environment requirements.
	 * @todo Replace this with the system requirements of your owe plugin.
	 * @see Main::check() below and possibly add/remove system checks and constants.
	 */
	const REQUIRE_PHP = '7.0';	//	Required PHP version
	const REQUIRE_WP  = '5.0';	//	Required WordPress version

	/**
	 * @var string The plugin global action hooks.
	 */
	const ACTION_LOADED     = self::PREFIX . '_loaded';
	const ACTION_UPDATE     = self::PREFIX . '_update';
	const ACTION_ACTIVATE   = self::PREFIX . '_activate';
	const ACTION_DEACTIVATE = self::PREFIX . '_deactivate';
	const ACTION_DELETE     = self::PREFIX . '_delete';

	/**
	 * @var string The plugin global filter hooks.
	 */
	const FILTER_CLASS_CREATE  = self::PREFIX . '_class_create';
	const FILTER_CLASS_CREATED = self::PREFIX . '_class_created';
	const FILTER_CLASS_PATH    = self::PREFIX . '_class_path';
	const FILTER_SYSTEM_CHECK  = self::PREFIX . '_system_check';
	const FILTER_PLUGIN_PREFIX = self::PREFIX . '_plugin_prefix';
	const FILTER_PLUGIN_PATH   = self::PREFIX . '_plugin_path';
	const FILTER_PLUGIN_URL    = self::PREFIX . '_plugin_url';

	/**
	 * @var string The plugin global options.
	 */
	const OPTION_VERSION = self::PREFIX . '_version';

	/**
	 * @var object The class singleton.
	 */
	protected static $_instance;

	/**
	 * @return object The class singleton.
	 */
	public static function instance() {
		if ( is_null( static::$_instance ) && static::check() ) {
			static::$_instance = false;
			$class             = apply_filters( self::FILTER_CLASS_CREATE, static::class );
			static::$_instance = apply_filters( self::FILTER_CLASS_CREATED, new $class(), $class, static::class );
			do_action( self::ACTION_LOADED, static::$_instance );
		}
		return static::$_instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->autoload();
		$this->run();
		$this->update();
	}

	/**
	 * Registers autoloading.
	 *
	 * @todo Add your own plugin classes and their file system paths here.
	 */
	protected function autoload() {
		$classes = apply_filters( self::FILTER_CLASS_PATH, array(
			__NAMESPACE__ . '\Setup' => static::plugin_path( 'includes/setup.php' ),
			__NAMESPACE__ . '\Meta'  => static::plugin_path( 'includes/meta.php' ),
			__NAMESPACE__ . '\Admin' => static::plugin_path( 'includes/admin.php' ),

			__NAMESPACE__ . '\Singleton'  => static::plugin_path( 'includes/tools/singleton.php' ),
			__NAMESPACE__ . '\Asset'      => static::plugin_path( 'includes/tools/asset.php' ),
			__NAMESPACE__ . '\Repository' => static::plugin_path( 'includes/tools/repository.php' ),
		) );

		spl_autoload_register( function ( $name ) use ( $classes ) {
			if ( array_key_exists( $name, $classes ) ) {
				include $classes[ $name ];
			}
		} );
	}

	/**
	 * Loads and runs the plugin classes.
	 *
	 * You must register your classes for autoloading (above) before you can run them here.
	 *
	 * @todo Add your own plugin classes.
	 */
	protected function run() {
		Setup::instance();

		if ( is_admin() ) {
			Admin::instance();
			Meta::instance();
			Repository::instance();
		}
	}

	/* =========================================================================
	 * Everything below this line is just plugin management and some very
	 * basic path and url handlers. You'll find the real action in the classes
	 * loaded above.
	 * ====================================================================== */

	/* -------------------------------------------------------------------------
	 * System environment checks
	 * ---------------------------------------------------------------------- */

	/**
	 * Checks if the system environment is supported.
	 *
	 * @todo Add/remove system environment checks for your plugin.
	 * @return bool True if the system environment is supported, false otherwise.
	 */
	protected static function check() {
		$error = false;

		if ( defined( 'self::REQUIRE_PHP' ) && self::REQUIRE_PHP ) {
			if ( version_compare( PHP_VERSION, self::REQUIRE_PHP ) < 0 ) {
				$error = static::error( 'PHP', self::REQUIRE_PHP ) || $error;
			}
		}

		if ( defined( 'self::REQUIRE_WP' ) && self::REQUIRE_WP ) {
			if ( version_compare( get_bloginfo( 'version' ), self::REQUIRE_WP ) < 0 ) {
				$error = static::error( 'WordPress', self::REQUIRE_WP ) || $error;
			}
		}

		return empty( $error );
	}

	/**
	 * Logs and outputs missing system requirements.
	 *
	 * @param string $require The name of the required component.
	 * @param string $version The minimum version required.
	 * @return bool True, except when overridden by filter.
	 */
	protected static function error( $require, $version ) {
		if ( apply_filters( self::FILTER_SYSTEM_CHECK, true, $require, $version ) ) {
			if ( is_admin() ) {

				//	Error message
				$message = __( '%1$s requires %2$s version %3$s or higher, the plugin is NOT RUNNING.', 'photo-credit' );
				$message = sprintf( $message, self::NAME, $require, $version );

				//	Admin notice output
				$notice = function () use ( $message ) {
					vprintf( '<div class="notice notice-error"><p><strong>%s: </strong>%s</p></div>', array(
						esc_html__( 'Error', 'photo-credit' ),
						esc_html( $message ),
					) );
				};

				//	Write error message to log and create admin notice.
				error_log( $message );
				add_action( 'admin_notices', $notice );
			}
			return true;
		}
		return false;
	}

	/* -------------------------------------------------------------------------
	 * Update, activate, deactivate and uninstall plugin.
	 * ---------------------------------------------------------------------- */

	/**
	 * Checks if the plugin was updated.
	 *
	 * Notifies plugin classes to update and flushes rewrite rules.
	 *
	 * @return bool True if the plugin was updated, false otherwise.
	 */
	protected function update() {
		$version = get_option( self::OPTION_VERSION );

		if ( self::VERSION !== $version ) {
			do_action( self::ACTION_UPDATE, $this, self::VERSION, $version );
			update_option( self::OPTION_VERSION, self::VERSION );

			add_action( 'wp_loaded', 'flush_rewrite_rules' );
			add_action( 'admin_notices', function () {
				$notice = __( '%s has been updated to version %s', 'photo-credit' );
				$notice = sprintf( $notice, self::NAME, self::VERSION );
				printf( '<div class="notice notice-success is-dismissible"><p>%s.</p></div>', esc_html( $notice ) );
				error_log( $notice );
			} );
			return true;
		}
		return false;
	}

	/**
	 * Registers plugin activation, deactivation and uninstall hooks.
	 */
	public static function register() {
		if ( is_admin() ) {
			register_activation_hook( self::FILE, array( static::class, 'activate' ) );
			register_deactivation_hook( self::FILE, array( static::class, 'deactivate' ) );
			register_uninstall_hook( self::FILE, array( static::class, 'uninstall' ) );
		}
	}

	/**
	 * Runs when the plugin is activated.
	 *
	 * Notifies plugin classes to activate and flushes rewrite rules.
	 * This hook is called AFTER all other hooks (except 'shutdown').
	 * WP redirects the request immediately after this hook, so we can't register any hooks to be executed later.
	 */
	public static function activate() {
		if ( is_admin() && current_user_can( 'activate_plugins' ) ) {
			do_action( self::ACTION_ACTIVATE, static::instance(), self::VERSION, get_option( self::OPTION_VERSION ) );
			update_option( self::OPTION_VERSION, self::VERSION );
			$message = __( '%s version %s has been activated', 'photo-credit' );
			error_log( sprintf( $message, self::NAME, self::VERSION ) );
			flush_rewrite_rules();
		}
	}

	/**
	 * Runs when the plugin is deactivated.
	 *
	 * Notifies plugin classes to deactivate and flushes rewrite rules.
	 */
	public static function deactivate() {
		if ( is_admin() && current_user_can( 'activate_plugins' ) ) {
			do_action( self::ACTION_DEACTIVATE, static::instance(), self::VERSION, get_option( self::OPTION_VERSION ) );
			$message = __( '%s version %s has been deactivated', 'photo-credit' );
			error_log( sprintf( $message, self::NAME, self::VERSION ) );
			flush_rewrite_rules();
		}
	}

	/**
	 * Runs when the plugin is deleted.
	 *
	 * Notifies plugin classes to delete all plugin settings and flushes rewrite rules.
	 */
	public static function uninstall() {
		if ( is_admin() && current_user_can( 'delete_plugins' ) ) {
			do_action( self::ACTION_DELETE, static::instance(), self::VERSION, get_option( self::OPTION_VERSION ) );
			delete_option( self::OPTION_VERSION );
			$message = __( '%s version %s has been removed', 'photo-credit' );
			error_log( sprintf( $message, self::NAME, self::VERSION ) );
			flush_rewrite_rules();
		}
	}

	/* -------------------------------------------------------------------------
	 * Basic prefix, path and url utils
	 * ---------------------------------------------------------------------- */

	/**
	 * Gets a prefixed identifier.
	 *
	 * @param string $name The identifier to prefix.
	 * @param string $sep The prefix separator.
	 * @return string The prefixed identifier.
	 */
	public function plugin_prefix( $name = '', $sep = '_' ) {
		$result = str_replace( '_', $sep, self::PREFIX . $sep . $name );
		return apply_filters( self::FILTER_PLUGIN_PREFIX, $result, $name, $sep );
	}

	/**
	 * Gets a full filesystem path from a local path.
	 *
	 * @param string $path The local path relative to this plugin's root directory.
	 * @return string The full filesystem path.
	 */
	public static function plugin_path( $path = '' ) {
		$path = ltrim( trim( $path ), '/' );
		$full = plugin_dir_path( self::FILE ) . $path;
		return apply_filters( self::FILTER_PLUGIN_PATH, $full, $path );
	}

	/**
	 * Gets the URL to the given local path.
	 *
	 * @param string $path The local path relative to this plugin's root directory.
	 * @return string The URL.
	 */
	public static function plugin_url( $path = '' ) {
		$path = ltrim( trim( $path ), '/' );
		$url  = plugins_url( $path, self::FILE );
		return apply_filters( self::FILTER_PLUGIN_URL, $url, $path );
	}

	/* -------------------------------------------------------------------------
	 * Debugging
	 * ---------------------------------------------------------------------- */

	/**
	 * Writes an entry to the php log and adds context information.
	 *
	 * @param string $log Log entry.
	 */
	public static function log( $log = '' ) {
		$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
		$file   = empty( $caller[0]['file'] ) ? '' : ' in ' . $caller[0]['file'];
		$line   = empty( $caller[0]['line'] ) ? '' : ' on line ' . $caller[0]['line'];
		$type   = empty( $caller[1]['function'] ) ? '#Debug: ' : '#' . $caller[1]['function'] . ': ';
		$entry  = str_replace( "\n", ' ', var_export( $log, true ) );
		error_log( $type . gettype( $log ) . ': ' . $entry . $file . $line );
	}

	/**
	 * Writes the backtrace to the php log.
	 */
	public static function trace() {
		static::log( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) );
	}
}

//	Registers and runs the main plugin class
if ( defined( 'ABSPATH' ) ) {
	Main::register();
	add_action( 'plugins_loaded', array( Main::class, 'instance' ), 5 );
}
