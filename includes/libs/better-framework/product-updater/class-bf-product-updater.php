<?php
/***
 *  BetterFramework is BetterStudio framework for themes and plugins.
 *
 *  ______      _   _             ______                                           _
 *  | ___ \    | | | |            |  ___|                                         | |
 *  | |_/ / ___| |_| |_ ___ _ __  | |_ _ __ __ _ _ __ ___   _____      _____  _ __| | __
 *  | ___ \/ _ \ __| __/ _ \ '__| |  _| '__/ _` | '_ ` _ \ / _ \ \ /\ / / _ \| '__| |/ /
 *  | |_/ /  __/ |_| ||  __/ |    | | | | | (_| | | | | | |  __/\ V  V / (_) | |  |   <
 *  \____/ \___|\__|\__\___|_|    \_| |_|  \__,_|_| |_| |_|\___| \_/\_/ \___/|_|  |_|\_\
 *
 *  Copyright © 2017 Better Studio
 *
 *
 *  Our portfolio is here: https://betterstudio.com/
 *
 *  \--> BetterStudio, 2018 <--/
 */


BF_Product_Updater::Run();

class BF_Product_Updater {

	public static $plugins_file;


	/**
	 * Initialize
	 */
	public static function Run() {

		global $bs_product_updater;

		if ( $bs_product_updater === false ) {
			return;
		}

		if ( ! $bs_product_updater instanceof self ) {
			$bs_product_updater = new self();
			$bs_product_updater->init();
		}

		return $bs_product_updater;
	}


	public function init() {

		add_action( 'wp_update_themes', array( $this, 'update_product_schedule' ) );
		add_action( 'load-themes.php', array( $this, 'update_product_schedule' ) );
		add_action( 'load-update.php', array( $this, 'update_product_schedule' ) );
		add_action( 'load-update-core.php', array( $this, 'update_product_schedule' ) );
		add_action( 'upgrader_process_complete', array( $this, 'update_product_schedule' ) );

		add_filter( 'site_transient_update_themes', array( $this, 'fetch_theme_download_link' ) );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_directory' ), 30, 4 );

		$this->plugin_compatibility();
		$this->auto_update();

	}

	/**
	 * Turn auto updater on.
	 *
	 * @since 3.11.0
	 */
	protected function auto_update() {

		if ( ! class_exists( 'BF_Product_Auto_Update' ) ) {

			require BS_PRODUCT_UPDATE_PATH . 'class-bf-product-auto-update.php';
		}

		( new BF_Product_Auto_Update )->init();
	}

	function fetch_theme_download_link( $value ) {

		if ( empty( $value->response ) || ! is_array( $value->response ) ) {

			return $value;
		}

		if ( ! $this->can_fetch_download_link() ) {

			return $value;
		}

		add_filter( 'http_request_args', 'bf_remove_reject_unsafe_urls', 99 );

		foreach ( $value->response as $idx => $product ) {
			if ( isset( $product['package'] ) && preg_match( '/^FETCH_FROM_BETTER_STUDIO\/(.+)/i', $product['package'], $matched ) ) {
				$r            = &$value->response[ $idx ];
				$dl_link      = $this->get_product_download_link( array_pop( $matched ), $product['slug'] );
				$r['package'] = $dl_link;
			}
		}

		set_site_transient( 'update_themes', $value );
		remove_filter( 'site_transient_update_themes', array( $this, 'fetch_theme_download_link' ) );

		return $value;
	}

	/**
	 * @since 3.11.1
	 * @return bool
	 */
	protected function can_fetch_download_link() {

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {

			return true;
		}

		if ( in_array( $GLOBALS['pagenow'], array( 'admin-ajax.php', 'update.php' ) ) ) {

			return isset( $_REQUEST['action'] ) &&
			       in_array( $_REQUEST['action'], array(
				       'upgrade-theme',
				       'update-selected-themes',
				       'update-theme',
			       ) );
		}

		return false;
	}

	protected function get_product_download_link( $item_id ) {

		if ( $purchase_info = get_option( 'bf-product-updater-items' ) ) {
			if ( isset( $purchase_info[ $item_id ] ) ) {
				$purchase_code = &$purchase_info[ $item_id ];

				$product_data = $this->api_request( 'download-latest-version', array(), compact( 'item_id', 'purchase_code' ) );
				if ( ! empty( $product_data->success ) && ! empty( $product_data->download_link ) ) {
					return $product_data->download_link;
				}
			}
		}
	}


	protected function get_products_info() {

		$results = array();
		$info    = apply_filters( 'better-framework/product-updater/product-info', array() );

		if ( $info ) {

			$cache_data = array();
			foreach ( $info as $d ) {

				if ( isset( $d['item_id'] ) && isset( $d['purchase_code'] ) ) {
					$cache_data[ $d['item_id'] ] = $d['purchase_code'];
				}

				$results[ $d['item_id'] ] = $d;
			}
			update_option( 'bf-product-updater-items', $cache_data, 'no' );
		}

		return $results;
	}

	/**
	 * Get update_themes transient.
	 *
	 * @since 3.11.1
	 * @return object
	 */
	protected function update_themes() {

		if ( ! ( $themes_update = get_site_transient( 'update_themes' ) ) ) {
			$themes_update = new stdClass();
		}

		if ( ! isset( $themes_update->no_update ) ) {
			$themes_update->no_update = [];
		}

		if ( empty( $themes_update->response ) ) {
			$themes_update->response = [];
		}

		return $themes_update;
	}

	/**
	 * Get update_plugins transient.
	 *
	 * @since 3.11.1
	 * @return object
	 */
	protected function update_plugins() {

		if ( ! ( $plugins_update = get_site_transient( 'update_plugins' ) ) ) {
			$plugins_update = new stdClass();
		}

		if ( empty( $plugins_update->response ) ) {
			$plugins_update->response = [];
		}

		if ( empty( $plugins_update->no_update ) ) {
			$plugins_update->no_update = [];
		}

		return $plugins_update;
	}

	public function update_product_schedule() {

		static $loaded = false;
		remove_action( 'wp_update_themes', array( $this, 'update_product_schedule' ) );
		if ( $loaded ) {
			return;
		}

		// Don't check update while updating another item!
		if (
			( isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], [
					'do-theme-upgrade',
					'do-plugin-upgrade',
					'update-selected',
				], true ) )
		) {
			return;
		}

		if ( ! $items_info = $this->get_products_info() ) {

			return;
		}

		$status = $this->check_for_update( $items_info, true );

		{ # Update Plugins List

			$update_list               = isset( $status->plugins ) ? $status->plugins : [];
			$plugins_update            = $this->update_plugins();
			$plugins_update->no_update = array_merge( $plugins_update->no_update, $this->no_update_list( $update_list, $items_info, 'plugin' ) );


			$r = &$plugins_update->response;
			foreach ( $update_list as $plugin_data ) {
				$p_file = self::plugin_slug_to_file_path( $plugin_data['slug'] );

				$r[ $p_file ]          = (object) $plugin_data;
				$r[ $p_file ]->plugin  = $p_file;
				$r[ $p_file ]->package = 'FETCH_FROM_BETTER_STUDIO/' . $plugin_data['slug'];
			}

			set_site_transient( 'update_plugins', $plugins_update );
		}

		{ # Update Themes List

			$themes_update = $this->update_themes();
			$update_list   = isset( $status->themes ) ? $status->themes : [];

			$themes_update->no_update = array_merge( $themes_update->no_update, $this->no_update_list( $update_list, $items_info, 'theme' ) );

			$r = &$themes_update->response;
			foreach ( $update_list as $item_id => $theme_data ) {

				$slug = &$theme_data['slug'];

				$r[ $slug ] = bf_merge_args( $theme_data, array(
					'package' => 'FETCH_FROM_BETTER_STUDIO/' . $item_id,
					//todo link to readme file
					'url'     => 'https://betterstudio.com/'
				) );
			}

			set_site_transient( 'update_themes', $themes_update );
		}

		$loaded = true;
	}


	/**
	 * @since 3.11.1
	 * @return array
	 */
	protected function no_update_list( $update_items, $all_items, $context = 'plugin' ) {

		$no_update        = [];
		$registered_theme = $this->registered_theme();

		foreach ( $all_items as $item_id => $item ) {

			if ( isset( $update_items[ $item_id ] ) ) {

				continue;
			}

			if ( 'theme' === $context ) {

				$theme_dir = $item['product_folder'];

				if ( $registered_theme === $theme_dir ) {

					$theme_dir = get_template();
				}
				/// Add support for renamed theme directory


				$no_update[ $theme_dir ] = [
					'theme'       => $theme_dir,
					'new_version' => $item['version'],
					'url'         => 'https://betterstudio.com/',
					'package'     => 'FETCH_FROM_BETTER_STUDIO/' . $item_id,
				];
			} else {

				$plugin_file = self::plugin_slug_to_file_path( $item['product_folder'] );

				$no_update[ $plugin_file ] = (object) [
					'id'          => $plugin_file,
					'url'         => 'https://betterstudio.com/',
					'slug'        => $item['product_folder'],
					'plugin'      => $plugin_file,
					'package'     => 'FETCH_FROM_BETTER_STUDIO/' . $item_id,
					'new_version' => $item['version'],
				];
			}
		}

		return $no_update;
	}

	/**
	 * Get registered product name if it's a theme.
	 *
	 * @since 3.11.1
	 * @return string
	 */
	protected function registered_theme() {

		if ( ! function_exists( 'bf_register_product_get_info' ) ) {

			return '';
		}

		$info = bf_register_product_get_info();

		if ( empty( $info['product_type'] ) || 'theme' !== $info['product_type'] ) {

			return '';
		}

		return $info['product_folder'];
	}

	/**
	 * Check group of items update
	 *
	 * @param array $items
	 * @param bool  $force
	 *
	 * @return bool|object object on success
	 */
	protected function check_for_update( $items, $force = false ) {

		global $wp_version, $pagenow;

		// Don't check update while updating another item!

		if (
			( isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], [
					'do-theme-upgrade',
					'do-plugin-upgrade'
				], true ) )
			||
			(
				isset( $_REQUEST['action'] ) &&
				in_array( $pagenow, array( 'admin-ajax.php', 'update.php' ) ) &&
				in_array( $_REQUEST['action'], array(
					'upgrade-theme',
					'update-selected-themes',
					'update-theme',
					'update-selected',
				) )
			)
		) {
			return false;
		}

		if ( empty( $items ) || ! is_array( $items ) ) {
			return false;
		}

		include ABSPATH . WPINC . '/version.php';

		$update_status               = new stdClass();
		$update_status->last_checked = time();
		$update_status->themes       = array();
		$update_status->plugins      = array();
		$update_status->misc         = array();

		if ( ! $force ) {
			$prev_status = get_option( 'bf-product-items-status' );

			if ( ! is_object( $prev_status ) ) {
				$prev_status               = new stdClass();
				$prev_status->last_checked = time();
				$skip_update               = false;
			} else {
				$skip_update = $this->check_update_duration > ( time() - $prev_status->last_checked );
			}

			if ( $skip_update ) {

				return $prev_status;
			}
		}

		/**
		 * check bundled plugins update
		 */

		$check_update = $this->api_request( 'check-products-update', compact( 'items' ) );

		if ( ! empty( $check_update->success ) && ! empty( $check_update->response ) ) {
			foreach ( $check_update->response as $item_id => $update_info ) {

				$ver       = &$update_info->version;
				$type      = &$update_info->type;
				$slug      = &$update_info->slug;
				$readme    = $update_info->readme ? $update_info->readme : false;
				$changelog = isset( $update_info->changelog ) ? $update_info->changelog : false;

				// Set active theme folder name instead of original folder name
				//  to handle changed folder names
				if ( ! empty( $items[ $item_id ]['active_theme'] ) ) {
					$slug = get_template();
				}

				if ( $ver !== 'latest' ) {

					$info_array = array(
						'slug'        => $slug,
						'new_version' => $ver,
						'url'         => $readme,
						'changelog'   => $changelog,
					);

					if ( $type === 'theme' ) {
						$info_array['theme']               = $slug;
						$update_status->themes[ $item_id ] = $info_array;
					} elseif ( $type === 'plugin' ) {
						$update_status->plugins[ $item_id ] = $info_array;
					} else {
						$update_status->misc[ $item_id ] = $info_array;
					}
				}
			}
		}

		do_action( 'better-framework/product-pages/product-update-check', $update_status, $check_update );

		update_option( 'bf-product-items-status', $update_status, 'no' );

		return $update_status;
	}


	/**
	 * Get plugin file path by plugin slug
	 *
	 * Ex: plugin_slug_to_file_path('js_composer') ==> js_composer/js_composer.php
	 *
	 * @param string $slug plugin slug (plugin directory)
	 *
	 * @return bool|string plugin file path on success or false on error
	 */
	public static function plugin_slug_to_file_path( $slug ) {

		if ( ! isset( self::$plugins_file ) ) {

			self::$plugins_file = array();

			foreach ( get_plugins() as $file => $info ) {

				self::$plugins_file[ dirname( $file ) ] = $file;
			}
		}

		if ( isset( self::$plugins_file[ $slug ] ) ) {
			return self::$plugins_file[ $slug ];
		}

		return false;
	} // plugin_slug_to_file_path


	/**
	 * handle api request
	 *
	 * @see \BetterFramework_Oculus::request
	 *
	 * @param string $action
	 * @param array  $data
	 * @param array  $auth
	 * @param bool   $use_wp_error
	 *
	 * @return array|bool|object|WP_Error
	 */
	protected function api_request( $action, $data = array(), $auth = array(), $use_wp_error = false ) {

		if ( ! class_exists( 'BetterFramework_Oculus' ) ) {
			return false;
		}

		return BetterFramework_Oculus::request( $action, compact( 'auth', 'data', 'use_wp_error' ) );
	} //api_request


	/**
	 * Rename downloaded package folder to user-defined directory name
	 * for support renamed product folders while upgrading process.
	 *
	 * @param string      $source        File source location.
	 * @param string      $remote_source Remote file source location.
	 * @param WP_Upgrader $WP_Upgrader   WP_Upgrader instance. unused
	 * @param array       $hook_extra    Extra arguments passed to hooked filters.
	 *
	 * @hooked upgrader_source_selection
	 *
	 * @since  3.7.0
	 * @return string
	 */
	public function fix_source_directory( $source, $remote_source, $WP_Upgrader, $hook_extra ) {

		if ( ! $source ) {
			return $source;
		}

		if ( ! empty( $hook_extra['theme'] ) ) {

			$product_type        = 'theme';
			$current_folder_name = $hook_extra['theme'];

		} elseif ( ! empty( $hook_extra['plugin'] ) ) {

			$product_type        = 'plugin';
			$current_folder_name = $hook_extra['plugin'];

		} else {

			return $source;
		}

		$check = array(
			'product_type'   => $product_type,
			'product_folder' => basename( $source ),
		);

		$original_folder_name = &$check['product_folder'];

		// Dose user changed original product folder name?

		if ( $current_folder_name === $original_folder_name ) {

			return $source;
		}

		/// Is this a betterstudio product?
		$is_better_product = false;

		foreach ( apply_filters( 'better-framework/product-updater/product-info', array() ) as $info ) {

			if ( ! array_diff_assoc( $check, $info ) ) {

				$is_better_product = true;
				break;
			}
		}

		if ( ! $is_better_product ) {
			// Do not touch none betterstudio themes or plugins
			return $source;
		}

		$file_system   = bf_file_system_instance();
		$renamed_path  = $remote_source . '/' . $current_folder_name;
		$original_path = $remote_source . '/' . $original_folder_name;

		$file_system->delete( $renamed_path, true );

		if ( $file_system->move( $original_path, $renamed_path ) ) {

			return $renamed_path;
		}

		return $source;
	}


	/**
	 * Fix third-party plugin conflicts with our product updater.
	 */
	public function plugin_compatibility() {

		// Disable licensed visual composer update feature
		if ( function_exists( 'vc_manager' ) ) {

			if ( apply_filters( 'better-framework/product-updater/disable-vc-updater', true ) ) {

				vc_manager()->disableUpdater();
			}
		}
	}
}
