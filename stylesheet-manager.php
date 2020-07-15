<?php
/**
 * Plugin Name: Stylesheet Manager
 * Plugin URI: https://github.com/liudmyla-zaviriukha/stylesheet-manager
 * Description: Plugin for front-end devs to take control of all styles enqueued on their site and allows to print inline them in head section of the document (only for not logged in users).
 * Version: 0.0.1
 * Author: Liudmyla Zaviriukha
 * Author URI: https://github.com/liudmyla-zaviriukha
 * License:     GNU General Public License v2.0 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Text Domain: stylesheet-manager
 * Domain Path: /languages/
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of the GNU
 * General Public License as published by the Free Software Foundation; either version 2 of the License,
 * or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */
if ( ! defined( 'ABSPATH' ) )
	exit;

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( !class_exists( 'smInit' ) ) {

class smInit {

	/**
	 * The single instance of this class
	 */
	private static $instance;

	/**
	 * Path to the plugin directory
	 */
	static $plugin_dir;

	/**
	 * URL to the plugin
	 */
	static $plugin_url;

	/**
	 * Array of assets to be managed
	 */
	public $assets;

	/**
	 * Create or retrieve the single instance of the class
	 *
	 * @since 0.1
	 */
	public static function instance() {

		if ( !isset( self::$instance ) ) {

			self::$instance = new smInit;

			self::$plugin_dir = untrailingslashit( plugin_dir_path( __FILE__ ) );
			self::$plugin_url = untrailingslashit( plugin_dir_url( __FILE__ ) );

			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Initialize the plugin
	 */
	public function init() {

		// Textdomain
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Handle queue management requests via Ajax
		add_action( 'wp_ajax_nopriv_sm-modify-asset' , array( $this , 'ajax_nopriv_default' ) );
		add_action( 'wp_ajax_sm-modify-asset', array( $this, 'ajax_modify_asset' ) );

		// Process an emergency restore request
		add_action( 'init', array( $this, 'restore_queue' ) );

		// Add the rest of the hooks which are only needed when the
		// admin bar is showing
		add_action( 'admin_bar_init', array( $this, 'admin_bar_init' ) );

		// Deregister assets
		add_action( 'wp_head', array( $this, 'deregister_assets' ), 7 );
		add_action( 'wp_footer', array( $this, 'deregister_assets' ) );

	}

	/**
	 * Add the hooks to display the asset panel in the admin bar
	 * @since 0.0.1
	 */
	public function admin_bar_init() {

		if ( !is_super_admin() || !is_admin_bar_showing() || $this->is_wp_login() ) {
			return;
		}

		// Add links to the plugin listing on the installed plugins page
		add_filter( 'plugin_action_links', array( $this, 'plugin_action_links' ), 10, 2);

		// Don't bother showing the panel in the admin area
		if ( is_admin() ) {
			return;
		}

		// Enqueue assets for the control panel
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Store all assets enqueued in the head
		add_action( 'wp_head', array( $this, 'store_head_assets' ), 1000 );

		// Store any new assets enqueued in the footer
		add_action( 'wp_footer', array( $this, 'store_footer_assets' ), 1000 );

		// Add the Assets item to the admin bar
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ) );

		// Print the assets panel in the footer
		add_action( 'wp_footer', array( $this, 'print_assets_panel' ), 1000 );

	}

	/**
	 * Load the plugin textdomain for localistion
	 * @since 0.0.1
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'stylesheet-manager', false, plugin_basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Check if we're on the login page, because the admin bar isn't
	 * shown there. Thanks to debug-bar for the heads-up.
	 * https://wordpress.org/plugins/debug-bar/
	 *
	 * @since 0.0.1
	 */
	public function is_wp_login() {
		return 'wp-login.php' == basename( $_SERVER['SCRIPT_NAME'] );
	}

	/**
	 * Enqueue the front-end CSS and Javascript for the control panel
	 * @since 0.0.1
	 */
	public function register_assets() {

		wp_enqueue_style( 'stylesheet-manager', self::$plugin_url . '/assets/css/stylesheet-manager.css' );
        wp_enqueue_script( 'stylesheet-manager', self::$plugin_url . '/assets/js/stylesheet-manager.js', array( 'jquery' ), '', true );

		// Add translateable strings, nonce, and URLs for ajax requests
		wp_localize_script(
			'stylesheet-manager',
			'sm',
			array(
				'nonce'		=> wp_create_nonce( 'stylesheet-manager' ),
				'siteurl'	=> get_bloginfo( 'url' ),
				'ajaxurl'	=> admin_url('admin-ajax.php'),
				'strings'	=> array(
                    'page_caption'      => __( 'Stylesheet Manager' ),
					'inline_printed_styles'	=> __( 'Inline Printed Styles', 'stylesheet-manager' ),
					'no_src'			=> __( 'This asset handle calls its dependent assets but loads no source files itself.', 'stylesheet-manager' ),
					'requeued'			=> __( 'This asset is no longer being inline printed. Reload the page to view where it is enqueued.', 'stylesheet-manager' ),
					'deps'				=> __( 'Dependencies:', 'stylesheet-manager' ),
					'dequeue'			=> __( 'To inline', 'stylesheet-manager' ),
					'enqueue'			=> __( 'Stop Inline printing', 'stylesheet-manager' ),
					'view'				=> __( 'View Asset', 'stylesheet-manager' ),
					'sending'			=> __( 'Sending Request', 'stylesheet-manager' ),
					'unknown_error' 	=> __( 'There was an unknown error with this request. Sorry.', 'stylesheet-manager' )
				),
			)
		);
	}

	/**
	 * Store assets found in the list of enqueued assets
	 * @since 0.0.1
	 */
	public function store_asset_list( $enqueued_slugs, $asset_data, $location, $type ) {

		foreach( $enqueued_slugs as $slug ) {
			$this->store_asset( $slug, $asset_data[ $slug ], $location, $type );
		}
	}

	/**
	 * Store a single asset's data
	 * @since 0.0.1
	 */
	public function store_asset( $slug, $data, $location, $type ) {

		if ( !isset( $this->assets[ $location ] ) ) {
			$this->assets[ $location ] = array();
		}

		if ( !isset( $this->assets[ $location ][ $type ] ) ) {
			$this->assets[ $location ][ $type ] = array();
		}

		if ( $this->is_asset_stored( $slug, $location, $type ) ) {
			return;
		}

		$this->assets[ $location ][ $type ][ $slug ] = $data;
	}

	/**
	 * Check if an asset has already been added to our list
	 * @since 0.0.1
	 */
	public function is_asset_stored( $slug, $location, $type ) {

		// Only check in the footer
		if ( $location !== 'footer' ) {
			return false;
		}

		if ( isset( $this->assets[ 'head' ] ) && isset( $this->assets[ 'head' ][ $type ] ) && isset( $this->assets[ 'head' ][ $type ][ $slug ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Store assets enqueued in the head
	 * @since 0.0.1
	 */
	public function store_head_assets() {
		global $wp_styles;
		$this->store_asset_list( $wp_styles->done, $wp_styles->registered, 'head', 'styles' );
	}

	/**
	 * Store assets enqueued in the footer
	 * @since 0.0.1
	 */
	public function store_footer_assets() {
		global $wp_styles;
		$this->store_asset_list( $wp_styles->done, $wp_styles->registered, 'footer', 'styles' );
	}

	/**
	 * Retrieve assets dequeued by this plugin
	 * @since 0.0.1
	 */
	public function get_dequeued_assets() {

		if ( !isset( $this->assets['dequeued'] ) ) {
			$this->assets['dequeued'] = get_option( 'sm-dequeued' );
		}

		return $this->assets['dequeued'];
	}

	/**
	 * Deregister all dequeued assets. This should be called before
	 * wp_head and wp_footer.
	 * @since 0.0.1
	 */
	public function deregister_assets() {

		$this->get_dequeued_assets();

		if ( !empty( $this->assets['dequeued']['styles'] ) ) {
			foreach( $this->assets['dequeued']['styles'] as $handle => $asset ) {

				if( !is_user_logged_in() ) {

				    //Check asset src
                    $src = $asset['src'];
					$parsed_url = parse_url($src);

					if(!$parsed_url['scheme'])
						$src = get_site_url() . $src;

					$css_content = $this->get_local_fonts($src);

					wp_deregister_style( $handle );

					wp_register_style( 'dummy-' . $handle, false );
					wp_enqueue_style( 'dummy-' . $handle );
					wp_add_inline_style( 'dummy-' . $handle, $css_content );
				}
			}
		}
	}

	public function get_local_fonts($src) {
		$content = file_get_contents($src);

		// find all @font-face
        $font_faces = preg_match_all('/(\@font-face)([^}]+)(\})/', $content, $matches);

        // cut matches from file content
		for ($i = 0; $i < $font_faces; $i++) {
			$content = trim(str_replace($matches[0][$i], '', $content));
		}

		if (!empty($matches[0])) {
			foreach ( $matches[0] as $font){

			    // get font name and file path
				preg_match('/(font-family:)([^;]*)/', $font, $font_family);
				preg_match_all('/(url)([^;]*)/', $font, $font_src);

                foreach ($font_src[0] as $url){
	                preg_match('/\((.*?)\)/', $url, $font_src);
                }


				$font_src = str_replace('\'','', $font_src[1]);
				$font_src = str_replace('..','', $font_src);

				if(!empty($font_src)) {

					$font_url = $this->get_file_url( $font_src );

					$font_family = str_replace( "'", "", $font_family[2] );
					$font_family = str_replace( "\"", "", $font_family );
					$font_family = trim( stripslashes( $font_family ) );

					$this->insert_font_link( $font_family, $font_url );
				}
			}
        }

		return $content;
	}

	public function get_file_url($src){

        $path = '';

		// Get all files in current theme directory
		$theme_list_files = list_files(
			get_template_directory(),
			100,
			array('templates', 'template-parts', 'classes', 'inc')
		);

		// Get all files in plugin directory
		$plugins_list_files = list_files(
			plugin_dir_path(__DIR__)
		);

		$list_files = array_merge($theme_list_files,$plugins_list_files);

		foreach ($list_files as $file){
			if(strpos($file, $src) !== false){
			    $path = $file;
            }
        }

        return $this->abs_path_to_url($path);
	}

	public function abs_path_to_url( $path = '' ) {
		$url = str_replace(
			wp_normalize_path( untrailingslashit( ABSPATH ) ),
			site_url(),
			wp_normalize_path( $path )
		);
		return esc_url_raw( $url );
	}

	/**
	 * Insert Font link to <head> with Preload attribute
	 * @since 0.0.1
	 */
	public function insert_font_link($name, $url){

		wp_register_style( 'inserted_font-'.$name, $url );
		wp_enqueue_style( 'inserted_font-'.$name );

		add_filter('style_loader_tag', array( $this, 'preload_font_tag') );
    }

	public function preload_font_tag($html) {

		if(strpos($html, 'inserted_font-') !== false){
			$html = str_replace( "rel='stylesheet'", "rel='preload' crossorigin='anonymous'", $html );
			$html = str_replace( "media='all'", "", $html );
		}
		return $html;
	}

	/**
	 * Add an Assets item to the admin bar menu
	 * @since 0.0.1
	 */
	public function admin_bar_menu() {

		if ( is_admin() ) {
			return;
		}

		global $wp_admin_bar;

		$recovery_message = sprintf( __( 'The Stylesheet Manager panel did not load. This can happen if jQuery is not being loaded on the page. If you have encountered this error after dequeuing an asset by mistake, you can %srestore all assets%s dequeued by Stylesheet Manager. This message is only shown to administrators.', 'stylesheet-manager' ), '<a href="' . admin_url() . '?sm=restore">', '</a>' );

		$wp_admin_bar->add_node(
			array(
				'id'     	=> 'stylesheet-manager',
				'parent'	=> 'top-secondary',
				'title'  	=> __( 'Assets', 'stylesheet-manager' ),
				'meta'		=> array(
					'html'	=> '<div class="inactive"><p>' . $recovery_message . '</p></div>'
				)
			)
		);
	}

	/**
	 * Print the assets panel and pass the assets array to the script
	 * for loading. We can't use wp_localize_script() because this has
	 * to come after the last enqueue opportunity.
	 * @since 0.0.1
	 */
	public function print_assets_panel() {
		// Add dequeued assets to the $assets array
		$this->get_dequeued_assets();

		$data = array(
			'assets'	=> $this->assets,
			'notices'	=> $this->get_notices(),
		);

		?>

<div id="sm-panel" class="inactive"></div>
<script type='text/javascript'>
	/* <![CDATA[ */
	var smData = <?php echo json_encode( $data );  ?>
	/* ]]> */
</script>

		<?php


	}

	/**
	 * Define the notices and warnings to display for special assets
	 * @since 0.0.1
	 */
	public function get_notices() {

		$notices = array(
			'core'	=> array(
				'msg'		=> __( 'This asset is part of WordPress core.', 'stylesheet-manager' ),
				'handles'	=> array(
					'jquery',
					'jquery-core',
					'jquery-migrate',
				),
			),
			'adminbar'	=> array(
				'msg'		=> __( 'This asset is commonly loaded with the admin bar for logged in users. It may not be loaded when logged-out users visit this page.', 'stylesheet-manager' ),
				'handles'	=> array(
					'open-sans',
					'dashicons',
					'admin-bar',
				),
			),
			'self'		=> array(
				'msg'		=> __( 'This asset is loaded by Stylesheet Manager. It will only be loaded for admin users.', 'stylesheet-manager' ),
				'handles'	=> array(
					'stylesheet-manager',
				)
			),
		);

		return apply_filters( 'sm_notices', $notices );
	}

	/**
	 * Handle all ajax requests from logged out users
	 * @since 0.0.1
	 */
	public function ajax_nopriv_default() {

		wp_send_json_error(
			array(
				'error' => 'loggedout',
				'msg' => __( 'You have been logged out. Please login again to perform this request.', 'stylesheet-manager' ),
			)
		);
	}

	/**
	 * Handle ajax request to dequeue or re-enqueue an asset
	 * @since 0.0.1
	 */
	public function ajax_modify_asset() {

		if ( !check_ajax_referer( 'stylesheet-manager', 'nonce' ) ||  !is_super_admin() ) {
			$this->ajax_nopriv_default();
		}
		if ( empty( $_POST['handle'] ) || empty( $_POST['type'] ) || empty( $_POST['asset_data'] ) ) {
			wp_send_json_error(
				array(
					'error' => 'noasset',
					'msg' => __( 'There was an error with this print request. No asset information was passed.', 'stylesheet-manager' ),
					'post'	=> $_POST
				)
			);
		}


		if ( $_POST['type'] !== 'styles' ) {
			wp_send_json_error(
				array(
					'error' => 'badtype',
					'msg' => __( 'There was an error with this dequeue request. The asset type was not recognized.', 'stylesheet-manager' ),
					'post'	=> $_POST
				)
			);
		}

		$handle = sanitize_key( $_POST['handle'] );
		$type = sanitize_key( $_POST['type'] );

		$this->get_dequeued_assets();

		// Initialize the array if nothing's been dequeued yet
		if ( empty( $this->assets['dequeued'][ $type ] ) ) {
			$this->assets['dequeued'][ $type ] = array();
		}

		// Handle dequeue request
		if ( $_POST['dequeue'] === 'true' ) {

			if ( in_array( $handle, $this->assets['dequeued'][ $type ] ) ) {
				wp_send_json_error(
					array(
						'error' => 'alreadydequeued',
						'msg' => __( 'This asset has already been dequeued. If the asset is still being loaded, the author may not have properly enqueued the asset using the wp_enqueue_* functions.', 'stylesheet-manager' ),
					)
				);
			}

			$this->assets['dequeued'][ $type ][ $handle ] = $_POST['asset_data'];

			update_option( 'sm-dequeued', $this->assets['dequeued'] );

			wp_send_json_success(
				array(
					'type' => $type,
					'handle' => $handle,
					'option' => $this->assets['dequeued'],
					'dequeue' => true
				)
			);

		// Handle enqueue request
		} else {

			unset( $this->assets['dequeued'][ $type ][ $handle ] );

			update_option( 'sm-dequeued', $this->assets['dequeued'] );

			wp_send_json_success(
				array(
					'type' => $type,
					'handle' => $handle,
					'option' => $this->assets['dequeued'],
					'dequeue' => false
				)
			);
		}
	}

	/**
	 * Delete dequeue option so that no assets are being blocked
	 *
	 * This is an emergency restore function in case people get
	 * themselves into a bit of a bind. Don't want them to have to get
	 * into the database to do this.
	 *
	 * @since 0.0.1
	 */
	public function restore_queue() {

		if ( empty( $_REQUEST['sm'] ) || $_REQUEST['sm'] !== 'restore' || !is_super_admin() ) {
			return;
		}

		delete_option( 'sm-dequeued' );
	}

	/**
	 * Add links to the plugin listing on the installed plugins page
	 * @since 0.0.1
	 */
	public function plugin_action_links( $links, $plugin ) {

		if ( $plugin == plugin_basename( __FILE__ ) ) {

			$links['restore'] = '<a href="' . admin_url() . '?sm=restore" title="' . __( 'Restore any assets dequeued by this plugin.', 'stylesheet-manager' ) . '">' . __( 'Restore Dequeued Assets', 'stylesheet-manager' ) . '</a>';
		}

		return $links;
	}

}
} // endif;

/**
 * This function returns one smInit instance everywhere
 * and can be used like a global, without needing to declare the global.
 *
 * Example: $sm = smInit();
 */
if ( !function_exists( 'smInit' ) ) {
function smInit() {
	return smInit::instance();
}
add_action( 'plugins_loaded', 'smInit' );

} // endif;
