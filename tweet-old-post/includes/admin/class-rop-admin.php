<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://themeisle.com/
 * @since      8.0.0
 *
 * @package    Rop
 * @subpackage Rop/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Rop
 * @subpackage Rop/admin
 * @author     ThemeIsle <friends@themeisle.com>
 */
class Rop_Admin {
	/**
	 * Allowed screen ids used for assets enqueue.
	 *
	 * @var array Array of script vs. page slugs. If page slugs is an array, then an exact match will occur.
	 */
	private $allowed_screens;

	const RN_LINK = 'https://revive.social/plugins/revive-network/';
	/**
	 * The ID of this plugin.
	 *
	 * @since    8.0.0
	 * @access   private
	 * @var      string $plugin_name The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    8.0.0
	 * @access   private
	 * @var      string $version The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param string $plugin_name The name of this plugin.
	 * @param string $version The version of this plugin.
	 *
	 * @since    8.0.0
	 */
	public function __construct( $plugin_name = '', $version = '' ) {

		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->set_allowed_screens();
		add_action( 'admin_notices', array( &$this, 'display_global_status_warning' ) );

		$global_settings = new Rop_Global_Settings();
		add_filter(
			'rop_pro_plan',
			function() use ( $global_settings ) {
				return $global_settings->license_type();
			}
		);

		add_filter( 'themeisle_sdk_blackfriday_data', array( $this, 'add_black_friday_data' ) );
	}


	/**
	 * Will display an admin notice if there are ROP_STATUS_ALERT consecutive errors.
	 *
	 * @since 8.4.4
	 * @access public
	 */
	public function display_global_status_warning() {
		$log                  = new Rop_Logger();
		$is_status_logs_alert = $log->is_status_error_necessary(); // true | false
		if ( $is_status_logs_alert && current_user_can( 'manage_options' ) ) {
			?>
			<div id="rop-status-error" class="notice notice-error is-dismissible">
				<p>
					<strong><?php echo esc_html( Rop_I18n::get_labels( 'general.plugin_name' ) ); ?></strong>:
					<?php echo Rop_I18n::get_labels( 'general.status_error_global' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    8.0.0
	 */
	public function enqueue_styles() {

		$page = $this->get_current_page();

		if ( empty( $page ) ) {
			// Always enqueue notices style
			wp_enqueue_style( $this->plugin_name . '_admin_notices', ROP_LITE_URL . 'assets/css/admin-notices.css', '', $this->version, 'all' );
			return;
		}

		$deps = array();
		if ( 'publish_now' !== $page ) {
			wp_enqueue_style( $this->plugin_name . '_core', ROP_LITE_URL . 'assets/css/rop_core.css', array(), $this->version, 'all' );
			$deps = array( $this->plugin_name . '_core' );
		}

		wp_enqueue_style( $this->plugin_name, ROP_LITE_URL . 'assets/css/rop.css', $deps, $this->version, 'all' );
		wp_enqueue_style( $this->plugin_name . '_fa', ROP_LITE_URL . 'assets/css/font-awesome.min.css', array(), $this->version );

	}

	/**
	 * Check if a shortener is in use.
	 *
	 * @param string $shortener The shortener to check.
	 *
	 * @return bool If shortener is in use.
	 * @since    8.1.5
	 */
	public function check_shortener_service( $shortener ) {

		$model       = new Rop_Post_Format_Model;
		$post_format = $model->get_post_format();

		$shorteners = array();

		foreach ( $post_format as $account_id => $option ) {
			$shorteners[] = $option['short_url_service'];
		}

		return ( in_array( $shortener, $shorteners ) ) ? true : false;
	}

	/**
	 * Show notice to upgrade bitly.
	 *
	 * @since    8.1.5
	 */
	public function bitly_shortener_upgrade_notice() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->check_shortener_service( 'bit.ly' ) ) {
			return;
		}

		$bitly = get_option( 'rop_shortners_bitly' );

		if ( ! is_array( $bitly ) ) {
			return;
		}

		if ( array_key_exists( 'generic_access_token', $bitly['bitly_credentials'] ) ) {
			return;
		}
		?>
		<div class="notice notice-error is-dismissible">
			<?php echo sprintf( __( '%1$s%2$sRevive Social:%3$s Please upgrade your Bit.ly keys. See this %4$sarticle for instructions.%5$s%6$s', 'tweet-old-post' ), '<p>', '<b>', '</b>', '<a href="https://docs.revive.social/article/976-how-to-connect-bit-ly-to-revive-old-posts" target="_blank">', '</a>', '</p>' ); ?>
		</div>
		<?php
	}

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    8.1.5
	 */
	private function set_allowed_screens() {

		$general_settings = new Rop_Settings_Model;

		$post_types           = wp_list_pluck( $general_settings->get_selected_post_types(), 'value' );
		$attachment_post_type = array_search( 'attachment', $post_types );

		if ( ! empty( $attachment_post_type ) ) {
			unset( $post_types[ $attachment_post_type ] );
		}

		$this->allowed_screens = array(
			'dashboard'   => 'TweetOldPost',
			'exclude'     => 'rop_content_filters',
			'publish_now' => $post_types,
		);

	}

	/**
	 * Return current ROP admin page.
	 *
	 * @return bool|string Page slug.
	 */
	private function get_current_page() {
		$screen = get_current_screen();

		if ( ! isset( $screen->id ) ) {
			return false;
		}
		$page = false;
		foreach ( $this->allowed_screens as $script => $id ) {
			if ( is_array( $id ) ) {
				foreach ( $id as $page_id ) {
					if ( $screen->id === $page_id ) {
						$page = $script;
						break;
					}
				}
			} else {
				if ( strpos( $screen->id, $id ) !== false ) {
					$page = $script;
					continue;
				}
			}
		}

		return $page;
	}

	/**
	 * Whether we will display the toast message related to facebook
	 *
	 * @return mixed
	 * @since 8.4.3
	 */
	private function facebook_exception_toast_display() {
		$show_the_toast = get_option( 'rop_facebook_domain_toast', 'no' );
		// Will comment this return for now, might be of use later on.
		// return filter_var( $show_the_toast, FILTER_VALIDATE_BOOLEAN );
		return false;
	}

	/**
	 * Method used to decide whether or not to limit taxonomy select
	 *
	 * @return  bool
	 * @since   8.5.0
	 * @access  public
	 */
	public function limit_tax_dropdown_list() {
		$installed_at_version = get_option( 'rop_first_install_version' );
		if ( empty( $installed_at_version ) ) {
			return 0;
		}
		if ( version_compare( $installed_at_version, '8.5.3', '>=' ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Method used to decide whether or not to limit taxonomy select
	 *
	 * @return  bool
	 * @since   8.6.0
	 * @access  public
	 */
	public function limit_remote_cron_system() {
		$installed_at_version = get_option( 'rop_first_install_version' );
		if ( empty( $installed_at_version ) ) {
			return 0;
		}
		if ( version_compare( $installed_at_version, '8.6.0', '>=' ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Method used to decide whether or not to limit exclude posts.
	 *
	 * @return  bool
	 * @since   8.5.4
	 * @access  public
	 */
	public function limit_exclude_list() {
		$installed_at_version = get_option( 'rop_first_install_version' );
		if ( empty( $installed_at_version ) ) {
			return 0;
		}
		if ( version_compare( $installed_at_version, '8.5.4', '>=' ) ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    8.0.0
	 */
	public function enqueue_scripts() {

		$page = $this->get_current_page();
		if ( empty( $page ) ) {
			return;
		}

		wp_enqueue_media();
		wp_register_script( $this->plugin_name . '-dashboard', ROP_LITE_URL . 'assets/js/build/dashboard.js', array('wp-url'), ( ROP_DEBUG ) ? time() : $this->version, false );
		wp_register_script( $this->plugin_name . '-exclude', ROP_LITE_URL . 'assets/js/build/exclude.js', array(), ( ROP_DEBUG ) ? time() : $this->version, false );

		$rop_api_settings = array(
			'root'      => esc_url_raw( rest_url( '/tweet-old-post/v8/api/' ) ),
			'dashboard' => admin_url( 'admin.php?page=TweetOldPost' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$rop_api_settings = array(
				'root'       => esc_url_raw( rest_url( '/tweet-old-post/v8/api/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'dashboard' => admin_url( 'admin.php?page=TweetOldPost' ),
			);
		}

		$services        = new Rop_Services_Model();
		$li_service      = new Rop_Linkedin_Service();
		$tmblr_service   = new Rop_Tumblr_Service();
		$active_accounts = $services->get_active_accounts();

		$added_services = $services->get_authenticated_services();
		$added_networks = 0;
		$accounts_count = count( $added_services );
		if ( $added_services ) {

			$uniq_auth_accounts = array();

			foreach ( $added_services as $key => $service ) {
				if ( isset( $service['service'] ) && ! in_array( $service['service'], $uniq_auth_accounts, true ) ) {
					$uniq_auth_accounts[] = $service['service'];
				}
			}

			$added_networks = count( $uniq_auth_accounts );
		}

		$global_settings = new Rop_Global_Settings();
		$settings        = new Rop_Settings_Model();

		$rop_api_settings['license_data_view']               = $global_settings->get_license_data_view();
		$rop_api_settings['license_type']                    = $global_settings->license_type();
		$rop_api_settings['fb_domain_toast_display']         = $this->facebook_exception_toast_display();
		$rop_api_settings['labels']                          = Rop_I18n::get_labels_frontend();
		$rop_api_settings['upsell_link']                     = Rop_I18n::UPSELL_LINK;
		$rop_api_settings['pro_installed']                   = ( defined( 'ROP_PRO_VERSION' ) ) ? true : false;
		$rop_api_settings['show_li_app_btn']                 = $li_service->rop_show_li_app_btn();
		$rop_api_settings['show_tmblr_app_btn']              = $tmblr_service->rop_show_tmblr_app_btn();
		$rop_api_settings['staging']                         = $this->rop_site_is_staging();
		$rop_api_settings['rop_get_wpml_active_status']      = $this->rop_get_wpml_active_status();
		$rop_api_settings['rop_get_yoast_seo_active_status'] = $this->rop_get_yoast_seo_active_status();
		$rop_api_settings['rop_is_edit_post_screen']         = $this->rop_is_edit_post_screen();
		$rop_api_settings['rop_get_wpml_languages']          = $this->rop_get_wpml_languages();
		$rop_api_settings['hide_own_app_option']             = $this->rop_hide_add_own_app_option();
		$rop_api_settings['debug']                           = ( ( ROP_DEBUG ) ? 'yes' : 'no' );
		$rop_api_settings['tax_apply_limit']                 = $this->limit_tax_dropdown_list();
		$rop_api_settings['remote_cron_type_limit']          = $this->limit_remote_cron_system();
		$rop_api_settings['exclude_apply_limit']             = $this->limit_exclude_list();
		$rop_api_settings['publish_now']                     = array(
			'instant_share_enabled' => $settings->get_instant_sharing(),
			'instant_share_by_default' => $settings->get_instant_sharing_default(),
			'accounts' => $active_accounts,
		);
		$rop_api_settings['custom_messages']                 = $settings->get_custom_messages();
		$rop_api_settings['added_networks']                  = $added_networks;
		$rop_api_settings['rop_cron_remote']                 = filter_var( get_option( 'rop_use_remote_cron', false ), FILTER_VALIDATE_BOOLEAN );
		$rop_api_settings['rop_cron_remote_agreement']       = filter_var( get_option( 'rop_remote_cron_terms_agree', false ), FILTER_VALIDATE_BOOLEAN );

		$admin_url = get_admin_url( get_current_blog_id(), 'admin.php?page=TweetOldPost' );
		$token     = get_option( ROP_INSTALL_TOKEN_OPTION );
		$signature = md5( $admin_url . $token );

		$rop_auth_app_data = array(
			'adminEmail'          => rawurlencode( base64_encode( get_option( 'admin_email' ) ) ),
			'authAppUrl'          => ROP_AUTH_APP_URL,
			'authAppFacebookPath' => ROP_APP_FACEBOOK_PATH,
			'authAppTwitterPath'  => ROP_APP_TWITTER_PATH,
			'authAppLinkedInPath' => ROP_APP_LINKEDIN_PATH,
			'authAppTumblrPath'   => ROP_APP_TUMBLR_PATH,
			'authAppGmbPath'      => ROP_APP_GMB_PATH,
			'authAppVkPath'       => ROP_APP_VK_PATH,
			'authToken'           => $token,
			'adminUrl'            => urlencode( $admin_url ),
			'authSignature'       => $signature,
			'pluginVersion'       => ROP_LITE_VERSION,
		);

		if ( 'publish_now' === $page ) {
			$rop_api_settings['publish_now'] = apply_filters( 'rop_publish_now_attributes', $rop_api_settings['publish_now'] );
			if ( self::is_classic_editor() ) {
				wp_register_script( $this->plugin_name . '-publish_now', ROP_LITE_URL . 'assets/js/build/publish_now.js', array(), ( ROP_DEBUG ) ? time() : $this->version, false );
			} else {
				$asset_file = include ROP_LITE_PATH . '/assets/js/react/build/index.asset.php';
				wp_register_script(
					$this->plugin_name . '-publish_now',
					ROP_LITE_URL . 'assets/js/react/build/index.js',
					$asset_file['dependencies'],
					$asset_file['version'],
					false
				);
			}
		}

		$rop_api_settings['tracking']           = 'yes' === get_option( 'tweet_old_post_logger_flag', 'no' );
		$rop_api_settings['tracking_info_link'] = sanitize_url( 'https://docs.revive.social/article/2008-revive-old-posts-usage-tracking' );

		$is_new_user  = (int) get_option( 'rop_is_new_user', 0 );
		$install_time = ! $is_new_user ? (int) get_option( 'rop_first_install_date', 0 ) : 0;

		if ( ! $is_new_user && ( $install_time && $install_time >= strtotime( '-1 hour' ) ) ) {
			$is_new_user = update_option( 'rop_is_new_user', 1 );
		}

		$rop_api_settings['is_new_user']           = $is_new_user;
		$rop_api_settings['webhook_pro_available'] = defined( 'ROP_PRO_VERSION' ) && version_compare( ROP_PRO_VERSION, '3.1.0', '>=' ) ? true : false;

		wp_localize_script( $this->plugin_name . '-' . $page, 'ropApiSettings', $rop_api_settings );
		wp_localize_script( $this->plugin_name . '-' . $page, 'ROP_ASSETS_URL', array( ROP_LITE_URL . 'assets/' ) );
		wp_localize_script( $this->plugin_name . '-' . $page, 'ropAuthAppData', $rop_auth_app_data );
		wp_enqueue_script( $this->plugin_name . '-' . $page );

		// Deregister the LMS vue-libs script for the ROP dashboard and exclude the page.
		if ( function_exists( 'learn_press_get_current_version' ) && wp_script_is( $this->plugin_name . '-' . $page ) ) {
			wp_deregister_script( 'vue-libs' );
		}

		$is_post_sharing_active = ( new Rop_Cron_Helper() )->get_status() ? 'yes' : 'no';

		if ( ! defined( 'TI_E2E_TESTING' ) || ! TI_E2E_TESTING ) {
			add_filter(
				'themeisle-sdk/survey/' . ROP_PRODUCT_SLUG,
				function( $data, $page_slug ) use ( $accounts_count, $is_post_sharing_active ) {
					$data = $this->get_survey_metadata();

					$extra_attributes = array(
						'accounts_number'      => min( 20, $accounts_count ),
						'post_sharing_enabled' => $is_post_sharing_active,
					);

					$data['attributes'] = array_merge( $data['attributes'], $extra_attributes );

					return $data;
				},
				10,
				2
			);
		}
		do_action( 'themeisle_internal_page', ROP_PRODUCT_SLUG, 'dashboard' );
	}

	/**
	 * Set our supported mime types.
	 *
	 * @return array
	 * @since   8.1.0
	 * @access  public
	 */
	public function rop_supported_mime_types() {

		$accepted_mime_types = array();

		$image_mime_types = apply_filters(
			'rop_accepted_image_mime_types',
			array(
				'image/jpeg',
				'image/png',
				'image/gif',
			)
		);

		$video_mime_types = apply_filters(
			'rop_accepted_video_mime_types',
			array(
				'video/mp4',
				'video/x-m4v',
				'video/quicktime',
				'video/x-ms-asf',
				'video/x-ms-wmv',
				'video/avi',
			)
		);

		$accepted_mime_types['image'] = $image_mime_types;

		$accepted_mime_types['video'] = $video_mime_types;
		// We use empty for non-attachament posts query.
		$accepted_mime_types['all'] = array_merge( $image_mime_types, $video_mime_types, array( '' ) );

		return $accepted_mime_types;

	}

	/**
	 * Detects if is a staging environment
	 *
	 * @return    bool   true/false
	 * @since     8.0.4
	 */
	public static function rop_site_is_staging( $post_id = '' ) {

		if ( get_post_type( $post_id ) === 'revive-network-share' ) {
			return apply_filters( 'rop_dont_work_on_staging', false ); // Allow Revive Network shares to go through by default
		}

		// This would also cover local wp installations
		if ( function_exists( 'wp_get_environment_type' ) ) {
			if ( wp_get_environment_type() !== 'production' ) {
				return apply_filters( 'rop_dont_work_on_staging', true );
			}
		}

		$rop_known_staging = array(
			'IS_WPE_SNAPSHOT',
			'KINSTA_DEV_ENV',
			'WPSTAGECOACH_STAGING',
		);

		foreach ( $rop_known_staging as $rop_staging_const ) {
			if ( defined( $rop_staging_const ) ) {

				return apply_filters( 'rop_dont_work_on_staging', true );

			}
		}
		// wp engine staging function
		if ( function_exists( 'is_wpe_snapshot' ) ) {
			if ( is_wpe_snapshot() ) {

				return apply_filters( 'rop_dont_work_on_staging', true );

			}
		}
		// JETPACK_STAGING_MODE if jetpack is installed and picks up on a staging environment we're not aware of
		if ( defined( 'JETPACK_STAGING_MODE' ) && JETPACK_STAGING_MODE == true ) {
			return apply_filters( 'rop_dont_work_on_staging', true );
		}

		return false;

	}

	/**
	 * Legacy auth callback.
	 */
	public function legacy_auth() {
		// TODO Remove this method if we're only going to allow simple
		$code    = sanitize_text_field( isset( $_GET['code'] ) ? $_GET['code'] : '' );
		$state   = sanitize_text_field( isset( $_GET['state'] ) ? $_GET['state'] : '' );
		$network = sanitize_text_field( isset( $_GET['network'] ) ? $_GET['network'] : '' );
		/**
		 * For twitter we don't have code/state params.
		 */
		if ( ( empty( $code ) && empty( $state ) ) && $network !== 'twitter' ) {
			return;
		}

		$oauth_token    = sanitize_text_field( isset( $_GET['oauth_token'] ) ? $_GET['oauth_token'] : '' );
		$oauth_verifier = sanitize_text_field( isset( $_GET['oauth_verifier'] ) ? $_GET['oauth_verifier'] : '' );
		/**
		 * For twitter we don't have code/state params.
		 */
		if ( ( empty( $oauth_token ) || empty( $oauth_verifier ) ) && $network === 'twitter' ) {
			return;
		}

		/**
		 * For mastodon code/state params.
		 */
		if ( ( empty( $oauth_token ) || empty( $oauth_verifier ) ) && $state === 'mastodon' ) {
			$network = $state;
		}
		switch ( $network ) {
			case 'linkedin':
				$lk_service = new Rop_Linkedin_Service();
				$lk_service->authorize();
				break;
			case 'twitter':
				$twitter_service = new Rop_Twitter_Service();
				$twitter_service->authorize();
				break;
			case 'pinterest':
				$pinterest_service = new Rop_Pinterest_Service();
				$pinterest_service->authorize();
				break;
			case 'mastodon':
				$mastodon_service = new Rop_Mastodon_Service();
				$mastodon_service->authorize();
				break;
			default:
				$fb_service = new Rop_Facebook_Service();
				$fb_service->authorize();
		}
	}

	/**
	 * The display method for the main dashboard of ROP.
	 *
	 * @since   8.0.0
	 * @access  public
	 */
	public function rop_main_page() {
		$this->wrong_pro_version();
		?>
		<div id="rop_core" style="margin: 20px 20px 40px 0;">
			<main-page-panel></main-page-panel>
		</div>
		<?php
	}


	/**
	 * Notice for wrong pro version usage.
	 */
	private function wrong_pro_version() {
		if ( defined( 'ROP_PRO_VERSION' ) && ( - 1 === version_compare( ROP_PRO_VERSION, '2.0.0' ) ) ) {
			?>
			<div class="error">
				<p>In order to use the premium features for <b>v8.0</b> of Revive Social you will need to update the
					Premium addon to at least 2.0. In case that you don't see the update, please download from your <a
							href="https://revive.social/your-purchases/" target="_blank">purchase history</a></p>
			</div>
			<?php
		}
	}

	/**
	 * The display method for the main page.
	 *
	 * @since   8.0.0
	 * @access  public
	 */
	public function content_filters() {
		$this->wrong_pro_version();
		?>
		<div id="rop_content_filters" style="margin: 20px 20px 40px 0;">
			<exclude-posts-page></exclude-posts-page>
		</div>
		<?php
	}

	/**
	 * Add admin menu items for plugin.
	 *
	 * @since   8.0.0
	 * @access  public
	 */
	public function menu_pages() {
		add_menu_page(
			__( 'Revive Social', 'tweet-old-post' ),
			__( 'Revive Social', 'tweet-old-post' ),
			'manage_options',
			'TweetOldPost',
			array(
				$this,
				'rop_main_page',
			),
			'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjIuMyAxMjIuMyI+PGRlZnM+PHN0eWxlPi5he2ZpbGw6I2U2ZTdlODt9PC9zdHlsZT48L2RlZnM+PHRpdGxlPkFzc2V0IDI8L3RpdGxlPjxwYXRoIGNsYXNzPSJhIiBkPSJNNjEuMTUsMEE2MS4xNSw2MS4xNSwwLDEsMCwxMjIuMyw2MS4xNSw2MS4yMiw2MS4yMiwwLDAsMCw2MS4xNSwwWm00MC41NCw2MC4xMUw4Ni41Nyw3NS42Miw0Ny45MywzMi4zOWwtMzMuMDcsMjdIMTJhNDkuMTksNDkuMTksMCwwLDEsOTguMzUsMS4yNFpNMTA5LjM1LDcxYTQ5LjIsNDkuMiwwLDAsMS05Ni42My0xLjJoNS44NEw0Ni44LDQ2Ljc0LDg2LjI0LDkwLjg2bDE5LjU3LTIwLjA3WiIvPjwvc3ZnPg=='
		);
		add_submenu_page(
			'TweetOldPost',
			__( 'Dashboard', 'tweet-old-post' ),
			__( 'Dashboard', 'tweet-old-post' ),
			'manage_options',
			'TweetOldPost',
			array(
				$this,
				'rop_main_page',
			),
			0
		);
		add_submenu_page(
			'TweetOldPost',
			__( 'Exclude Posts', 'tweet-old-post' ),
			__( 'Exclude Posts', 'tweet-old-post' ),
			'manage_options',
			'rop_content_filters',
			array(
				$this,
				'content_filters',
			)
		);
		if ( ! defined( 'REVIVE_NETWORK_VERSION' ) ) {
			$rss_to_social = __( 'RSS to Social', 'tweet-old-post' ) . '<span id="rop-rn-menu" class="dashicons dashicons-external" style="font-size:initial;"></span>';
			add_action(
				'admin_footer',
				function () {
					?>
				<script type="text/javascript">
					jQuery(document).ready(function ($) {
						$('.tsdk-upg-menu-item').parent().attr('target', '_blank');
						$('#rop-rn-menu').parent().attr('target', '_blank');
					});
				</script>
					<?php
				}
			);

			global $submenu;
			if ( isset( $submenu['TweetOldPost'] ) ) {
				$submenu['TweetOldPost'][2] = array(
					$rss_to_social,
					'manage_options',
					tsdk_utmify( self::RN_LINK, 'admin', 'admin_menu' ),
				);
			}
		}
	}

	/**
	 * Publish now upsell
	 *
	 * @since   8.1.0
	 * @access  public
	 */
	public function publish_now_upsell() {
		$page = $this->get_current_page();
		if ( empty( $page ) ) {
			return;
		}
		$global_settings = new Rop_Global_Settings;
		$settings        = new Rop_Settings_Model;

		$services        = new Rop_Services_Model();
		$active_accounts = $services->get_active_accounts();

		if ( $settings->get_instant_sharing() && count( $active_accounts ) >= 2 && ! defined( 'ROP_PRO_VERSION' ) ) {
			echo '<div class="misc-pub-section  " style="font-size: 11px;text-align: center;line-height: 1.7em;color: #888;"><span class="dashicons dashicons-lock"></span>' .
				__(
					'Share to more accounts by upgrading to the extended version for ',
					'tweet-old-post'
				) . '<a href="' . tsdk_utmify( Rop_I18n::UPSELL_LINK, 'editor', 'publish_now' ) . '" target="_blank">Revive Social </a>
						</div>';
		}
	}

	/**
	 * Check if we are using the classic editor.
	 *
	 * This is quite complex as it needs to check various conditions:
	 * - If the Classic Editor plugin is active.
	 * - If the post is saved with the Classic Editor.
	 * - If the user has selected the Classic Editor in their profile.
	 * - If the post is a new post (post_id is 0).
	 * - If the Classic Editor is set to replace the block editor.
	 * - If the user has the option to switch editors.
	 * Some edge cases might still exist, but this should cover most scenarios.
	 *
	 * @return bool
	 * @since 8.0.0
	 */
	public static function is_classic_editor() {
		if ( ! class_exists( 'Classic_Editor' ) ) {
			return false;
		}

		$post_id = ! empty( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		$allow_users_to_switch_editors = ( 'allow' === get_option( 'classic-editor-allow-users' ) );

		if ( $post_id && $allow_users_to_switch_editors && ! isset( $_GET['classic-editor__forget'] ) ) {
			$was_saved_with_classic_editor = ( 'classic-editor' === get_post_meta( $post_id, 'classic-editor-remember', true ) );
			if ( $was_saved_with_classic_editor ) {
				return true;
			}
		}

		if ( isset( $_GET['classic-editor'] ) ) {
			return true;
		}

		$option = get_option( 'classic-editor-replace' );

		$use_classic_editor = ( empty( $option ) || $option === 'classic' || $option === 'replace' );

		$user_classic_editor = get_user_meta( get_current_user_id(), 'wp_classic-editor-settings', true );

		if ( ! $allow_users_to_switch_editors && $use_classic_editor ) {
			return true;
		}

		// if user has selected the classic editor, we will use it.
		if ( $allow_users_to_switch_editors && ! empty( $user_classic_editor ) && $user_classic_editor === 'classic' ) {
			return true;
		}

		// if post_id is zero, we are on the new post screen.
		if ( $post_id === 0 && $use_classic_editor && $user_classic_editor !== 'block' ) {
			return true;
		}

		return false;
	}

	/**
	 * Creates publish now metabox.
	 *
	 * @since   8.5.0
	 * @access  public
	 */
	public function rop_publish_now_metabox( $screen ) {
		if ( ! self::is_classic_editor() ) {
			return;
		}

		$settings_model = new Rop_Settings_Model();

		// Get selected post types from General settings
		$screens = wp_list_pluck( $settings_model->get_selected_post_types(), 'value' );

		if ( empty( $screens ) ) {
			return;
		}

		if ( ! $settings_model->get_instant_sharing() ) {
			return;
		}

		$revive_network_post_type_key = array_search( 'revive-network-share', $screens, true );
		// Remove Revive Network post type. Publish now feature not available for RSS feed items.

		if ( ! empty( $revive_network_post_type_key ) ) {
			unset( $screens[ $revive_network_post_type_key ] );
		}

		foreach ( $screens as $screen ) {
			add_meta_box(
				'rop_publish_now_metabox',
				'Revive Social',
				array( $this, 'rop_publish_now_metabox_html' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Publish now metabox html.
	 *
	 * @since   8.5.0
	 * @access  public
	 */
	public function rop_publish_now_metabox_html() {

		wp_nonce_field( 'rop_publish_now_nonce', 'rop_publish_now_nonce' );
		include_once ROP_LITE_PATH . '/includes/admin/views/publish_now.php';

		$this->publish_now_upsell();

	}

	/**
	 * Publish now attributes to be provided to the javascript.
	 *
	 * @param array $default The default attributes.
	 */
	public function publish_now_attributes( $default ) {
		global $post;

		if ( in_array( $post->post_status, array( 'future', 'publish' ), true ) ) {
			$default['action'] = 'yes' === get_post_meta( $post->ID, 'rop_publish_now', true );
		}
		$default['page_active_accounts'] = get_post_meta( $post->ID, 'rop_publish_now_accounts', true );

		return $default;
	}

	/**
	 * Publish now, if enabled.
	 *
	 * This is hooked to the `save_post` action.
	 * The values from the Publish Now metabox are saved to the post meta.
	 *
	 * @param int  $post_id The post ID.
	 * @param bool $force Whether to force the action.
	 */
	public function maybe_publish_now( $post_id, $force = false ) {
		$post_status = get_post_status( $post_id );

		if ( ! in_array( $post_status, array( 'publish' ), true ) ) {
			return;
		}

		// To prevent multiple calls.
		if ( false === $force && false !== get_transient( 'rop_maybe_publish_now_' . $post_id ) ) {
			return;
		}

		set_transient( 'rop_maybe_publish_now_' . $post_id, true, MINUTE_IN_SECONDS );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['publish_now'] ) && ! empty( $_POST['publish_now'] ) ) {
			$publish = sanitize_text_field( $_POST['publish_now'] ) === '1' ? 'yes' : 'no';
		} else {
			$publish = get_post_meta( $post_id, 'rop_publish_now', true );
		}

		if ( empty( $publish ) || 'yes' !== $publish ) {
			return;
		}

		if ( isset( $_POST['publish_now_accounts'] ) && ! empty( $_POST['publish_now_accounts'] ) ) {
			$publish_now_active_accounts_settings = $_POST['publish_now_accounts'];

			$enabled_accounts = array();

			foreach ( $publish_now_active_accounts_settings as $account_id ) {
				$custom_message = ! empty( $_POST[ $account_id ] ) ? $_POST[ $account_id ] : '';
				$enabled_accounts[ $account_id ] = $custom_message;
			}
		} else {
			$enabled_accounts = get_post_meta( $post_id, 'rop_publish_now_accounts', true );
		}

		if ( ! is_array( $enabled_accounts ) ) {
			$enabled_accounts = array();
		}

		$services = new Rop_Services_Model();

		$accounts = $services->get_active_accounts();
		$active   = array_keys( $accounts );

		// has something been added extra?
		$extra = array_diff( array_keys( $enabled_accounts ), $active );

		// reject the extra.
		$enabled = array_diff( array_keys( $enabled_accounts ), $extra );

		if ( empty( $enabled ) ) {
			return;
		}

		foreach ( $enabled as $account_id ) {
			$this->update_publish_now_history(
				$post_id,
				array(
					'account'   => $account_id,
					'service'   => $accounts[ $account_id ]['service'],
					'timestamp' => time(),
					'status'    => 'queued',
				)
			);
		}

		// We update the existing publish now meta due to some Block Editor issues where the defaults are returned
		// when we make get_post_meta calls but the values are not saved in the database.
		update_post_meta( $post_id, 'rop_publish_now', $publish );
		update_post_meta( $post_id, 'rop_publish_now_accounts', $enabled_accounts );
		update_post_meta( $post_id, 'rop_publish_now_status', 'queued' );

		$cron = new Rop_Cron_Helper();
		$cron->manage_cron( array( 'action' => 'publish-now' ) );
	}

	/**
	 * Update the publish now history for a post.
	 *
	 * @access  public
	 * @param int   $post_id The Post ID.
	 * @param array $new_item The new item to add to the history.
	 *
	 * @return void
	 */
	public function update_publish_now_history( $post_id, $new_item ) {
		$meta_key = 'rop_publish_now_history';

		$history = get_post_meta( $post_id, $meta_key, true );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$updated = false;

		foreach ( $history as $index => $item ) {
			if (
				isset( $item['account'], $item['service'], $item['status'] ) &&
				$item['account'] === $new_item['account'] &&
				$item['service'] === $new_item['service'] &&
				$item['status'] === 'queued'
			) {
				$history[ $index ] = array_merge( $item, $new_item );
				$updated = true;
				break;
			}
		}

		if ( ! $updated ) {
			$history[] = $new_item;
		}

		update_post_meta( $post_id, $meta_key, $history );

		// If there are no more items in the history with status 'queued', we set the status to 'done'.
		$statuses = wp_list_pluck( $history, 'status' );
		if ( ! in_array( 'queued', $statuses, true ) ) {
			update_post_meta( $post_id, 'rop_publish_now_status', 'done' );
		}
	}


	/**
	 * The publish now Cron Job for the plugin.
	 *
	 * @since   8.1.0
	 * @access  public
	 */
	public function rop_cron_job_publish_now() {
		$queue           = new Rop_Queue_Model();
		$services_model  = new Rop_Services_Model();
		$logger          = new Rop_Logger();
		$service_factory = new Rop_Services_Factory();
		$pro_format_helper = false;

		if ( class_exists( 'Rop_Pro_Post_Format_Helper' ) && 0 < apply_filters( 'rop_pro_plan', -1 ) ) {
			$pro_format_helper = new Rop_Pro_Post_Format_Helper;
			if ( method_exists( $pro_format_helper, 'set_content_helper' ) ) {
				$pro_format_helper->set_content_helper( new Rop_Content_Helper() );
			}
		}

		$queue_stack = $queue->build_queue_publish_now();

		if ( empty( $queue_stack ) ) {
			$logger->info( 'Publish now queue stack is empty.' );
		} else {
			$logger->info( 'Fetching publish now queue: ' . print_r( $queue_stack, true ) );
		}
		foreach ( $queue_stack as $account_id => $events ) {
			foreach ( $events as $index => $event ) {
				$post    = $event['post'];
				$message = ! empty( $event['custom_instant_share_message'] ) ? $event['custom_instant_share_message'] : '';
				$message = apply_filters( 'rop_instant_share_message', stripslashes( $message ), $event );
				$account_data = $services_model->find_account( $account_id );
				try {
					$service = $service_factory->build( $account_data['service'] );
					$service->set_credentials( $account_data['credentials'] );
					foreach ( $post as $post_id ) {
						$post_data = $queue->prepare_post_object( $post_id, $account_id );
						$custom_instant_share_message = $message;
						if ( ! empty( $custom_instant_share_message ) ) {
							if ( $pro_format_helper !== false ) {
								if ( method_exists( $pro_format_helper, 'set_post_format' ) ) {
									$pro_format_helper->set_post_format( array() ); // Reset to not get data from previous post.
									if ( ! empty( $account_id ) ) {
										$format_helper = new Rop_Post_Format_Helper();
										$format_helper->set_post_format( $account_id );
										$pro_format_helper->set_post_format( $format_helper->get_post_format() );
									}
								}

								$post_data['content'] = $pro_format_helper->rop_replace_magic_tags( $custom_instant_share_message, $post_id );
							} else {
								$post_data['content'] = $custom_instant_share_message;
							}
						}
						$logger->info( 'Posting', array( 'extra' => $post_data ) );

						$response = $service->share( $post_data, $account_data );

						if ( $response ) {
							$this->update_publish_now_history(
								$post_id,
								array(
									'account'   => $account_id,
									'service'   => $account_data['service'],
									'timestamp' => time(),
									'status'    => 'success',
								)
							);
						} else {
							$this->update_publish_now_history(
								$post_id,
								array(
									'account'   => $account_id,
									'service'   => $account_data['service'],
									'timestamp' => time(),
									'status'    => 'error',
								)
							);
						}
					}
				} catch ( Exception $exception ) {
					$this->update_publish_now_history(
						$post_id,
						array(
							'account'   => $account_id,
							'service'   => $account_data['service'],
							'timestamp' => time(),
							'status'    => 'error',
						)
					);
					$error_message = sprintf( Rop_I18n::get_labels( 'accounts.service_error' ), $account_data['service'] );
					$logger->alert_error( $error_message . ' Error: ' . print_r( $exception->getMessage(), true ) );
				}
			}
		}
	}

	/**
	 * Used for Cron Job sharing that will run once.
	 *
	 * @since 8.5.0
	 */
	public function rop_cron_job_once() {
		$this->rop_cron_job();
	}

	/**
	 * The Cron Job for the plugin.
	 *
	 * @since   8.0.0
	 * @access  public
	 */
	public function rop_cron_job() {
		$queue           = new Rop_Queue_Model();
		$queue_stack     = $queue->build_queue();
		$services_model  = new Rop_Services_Model();
		$logger          = new Rop_Logger();
		$service_factory = new Rop_Services_Factory();
		$posts_selector_model = new Rop_Posts_Selector_Model();
		$refresh_rop_data = false;
		$revive_network_active = false;

		if ( class_exists( 'Revive_Network_Rop_Post_Helper' ) ) {
			$revive_network_active = true;
		}

		$cron = new Rop_Cron_Helper();
		$cron->create_cron( false );

		foreach ( $queue_stack as $account => $events ) {

			if ( strpos( json_encode( $queue_stack ), 'gmb_' ) !== false ) {
				$refresh_rop_data = true;
			}

			foreach ( $events as $index => $event ) {
				/**
				 * Trigger share if we have an event in the past, and the timestamp of that event is in the last 15mins.
				 */
				if ( $event['time'] <= Rop_Scheduler_Model::get_current_time() ) {
					$posts = $event['posts'];
					// If current account is not Google My Business, but GMB is active, refresh options data in instance; in case GMB updated it's options(access token)
					if ( $refresh_rop_data && ( strpos( $account, 'gmb_' ) === false ) ) {
						$queue->remove_from_queue( $event['time'], $account, true );
					} else {
						$queue->remove_from_queue( $event['time'], $account );
					}

					if ( ( Rop_Scheduler_Model::get_current_time() - $event['time'] ) < ( 15 * MINUTE_IN_SECONDS ) ) {
						$account_data = $services_model->find_account( $account );
						try {
							$service = $service_factory->build( $account_data['service'] );
							$service->set_credentials( $account_data['credentials'] );

							foreach ( $posts as $post ) {
								$post_shared = $account . '_post_id_' . $post;
								if ( get_option( 'rop_last_post_shared' ) === $post_shared && ROP_DEBUG !== true ) {
									$logger->info( ucfirst( $account_data['service'] ) . ': ' . Rop_I18n::get_labels( 'sharing.post_already_shared' ) );
									// help prevent duplicate posts on some systems
									continue;
								}

								do_action( 'rop_before_prepare_post', $post );
								$post_data = $queue->prepare_post_object( $post, $account );

								if ( $revive_network_active ) {

									if ( Revive_Network_Rop_Post_Helper::revive_network_is_revive_network_share( $post_data['post_id'] ) ) {

										$revive_network_settings = Revive_Network_Rop_Post_Helper::revive_network_get_plugin_settings();
										$delete_post_after_share = $revive_network_settings['delete_rss_item_after_share'];

										// Adjust post data to suit Revive Network
										$post_data = Revive_Network_Rop_Post_Helper::revive_network_prepare_revive_network_share( $post_data );
									}
								}

								$response = false;
								$logger->info( 'Posting', array( 'extra' => $post_data ) );

								/*
								 * Extra check to make sure the post isn't already in the buffer for the given account.
								 * If it is then don't share it again until the buffer is cleared.
								 */
								$duplicate = $posts_selector_model->buffer_has_post_id( $account, $post );

								if ( $duplicate === false ) {
									do_action( 'rop_before_share', $post_data );
									$response = $service->share( $post_data, $account_data );
								} else {
									$logger->info( Rop_I18n::get_labels( 'sharing.post_already_shared' ), array( 'extra' => $post_data ) );
								}

								if ( $revive_network_active ) {

									if ( Revive_Network_Rop_Post_Helper::revive_network_is_revive_network_share( $post_data['post_id'] ) ) {
										// Delete Feed post after it has been shared if the option is checked in RN settings.
										if ( $response === true && ! empty( $delete_post_after_share ) ) {

											Revive_Network_Rop_Post_Helper::revive_network_delete_revive_network_feed_post( $post, $account, $queue );

										}
									}
								}

								$posts_selector_model->update_buffer( $account, $post_data['post_id'] );

								if ( $response === true ) {
									update_option( 'rop_last_post_shared', $post_shared );
									do_action( 'rop_after_share', $post_data );
								}
							}
						} catch ( Exception $exception ) {
							$error_message = sprintf( Rop_I18n::get_labels( 'accounts.service_error' ), $account_data['service'] );
							$logger->alert_error( $error_message . ' Error: ' . $exception->getMessage() );
						}
					}
				}
			}
		}
		$cron->create_cron( false );
	}

	/**
	 * Linkedin API upgrade notice.
	 *
	 * @since   8.2.3
	 * @access  public
	 */
	public function rop_linkedin_api_v2_notice() {

		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		// This option was introduced the same time we updated Linkedin API to v2.
		// Gets created on plugin activation hook, old installs would not have this option.
		// So we return in case this is a brand new install.
		if ( ! empty( get_option( 'rop_first_install_version' ) ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'rop-linkedin-api-notice-dismissed' ) ) {
			return;
		}

		$show_notice = false;

		$services_model = new Rop_Services_Model();

		$services = $services_model->get_authenticated_services();

		foreach ( $services as $key => $value ) {

			if ( $value['service'] == 'linkedin' ) {
				$show_notice = true;
				break;
			}
		}

		if ( $show_notice === false ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<?php echo sprintf( __( '%1$s%2$sRevive Social:%3$s The Linkedin API Has been updated. You need to reconnect your LinkedIn account to continue posting to LinkedIn. Please see %4$sthis article for instructions.%5$s%6$s%7$s', 'tweet-old-post' ), '<p>', '<b>', '</b>', '<a href="https://docs.revive.social/article/1040-how-to-move-to-linkedin-api-v2" target="_blank">', '</a>', '<a style="float: right;" href="?rop-linkedin-api-notice-dismissed">Dismiss</a>', '</p>' ); ?>

		</div>
		<?php

	}

	/**
	 * Dismiss Linkedin API upgrade notice.
	 *
	 * @since   8.2.3
	 * @access  public
	 */
	public function rop_dismiss_linkedin_api_v2_notice() {
		$user_id = get_current_user_id();
		if ( isset( $_GET['rop-linkedin-api-notice-dismissed'] ) ) {
			add_user_meta( $user_id, 'rop-linkedin-api-notice-dismissed', 'true', true );
		}

	}

	/**
	 * If the option "rop_is_sharing_cron_active" value is off/false/no then the WP Cron Jobs will be cleared.
	 *
	 * @since 8.5.0
	 */
	public function check_cron_status() {
		$key             = 'rop_is_sharing_cron_active';
		$should_cron_run = get_option( $key, 'yes' );
		$should_cron_run = filter_var( $should_cron_run, FILTER_VALIDATE_BOOLEAN );
		if ( false === $should_cron_run ) {
			$cron = new Rop_Cron_Helper();
			$cron->clear_scheduled_hook( Rop_Cron_Helper::CRON_NAMESPACE );
			$cron->clear_scheduled_hook( Rop_Cron_Helper::CRON_NAMESPACE_ONCE );
		}
	}

	/**
	 * WordPress Cron disabled notice.
	 *
	 * @since   8.2.5
	 * @access  public
	 */
	public function rop_wp_cron_notice() {
		// TODO - we need to rework this as the constant is not saying that cron is not working only that the default scheduling is, the user can still use server cron instead.
		return;
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'rop-wp-cron-notice-dismissed' ) ) {
			return;
		}

		if ( DISABLE_WP_CRON && ROP_DEBUG ) {

			?>
			<div class="notice notice-error">
				<?php echo sprintf( __( '%1$s%2$sRevive Social:%3$s The WordPress Cron seems is disabled on your website. This can cause sharing issues with Revive Social. If sharing is not working, then see %4$shere for solutions.%5$s%6$s%7$s', 'tweet-old-post' ), '<p>', '<b>', '</b>', '<a href="https://docs.revive.social/article/686-fix-revive-old-post-not-posting" target="_blank">', '</a>', '<a style="float: right;" href="?rop-wp-cron-notice-dismissed">Dismiss</a>', '</p>' ); ?>

			</div>
			<?php

		}

	}

	/**
	 * Dismiss WordPress Cron disabled notice.
	 *
	 * @since   8.2.5
	 * @access  public
	 */
	public function rop_dismiss_cron_disabled_notice() {

		$user_id = get_current_user_id();
		if ( isset( $_GET['rop-wp-cron-notice-dismissed'] ) ) {
			add_user_meta( $user_id, 'rop-wp-cron-notice-dismissed', 'true', true );
		}

	}

	/**
	 * Migrate the taxonomies from General Settings to Post Format for Pro users.
	 *
	 * @since 8.5.4
	 */
	public function migrate_taxonomies_to_post_format() {

		// Fetch the plugin global settings.
		$global_settings = new Rop_Global_Settings();

		// If there is no pro license, cut process early.
		if ( $global_settings->license_type() < 1 ) {
			return;
		}

		// If any type of Pro is installed and active.
		if ( $global_settings->license_type() > 0 && $global_settings->license_type() !== 7 ) {
			// Get the current plugin options.
			$option = get_option( 'rop_data' );

			// Get the custom options.
			// If this option exists, then the migration took place, and it will not happen again.
			// Should return false the first time as it does not exist.
			$update_took_place = get_option( 'rop_data_migrated_tax' );

			// If the update already took place and the general settings array value does not exist, cut process early.
			if ( ! empty( $update_took_place ) && ! isset( $option['general_settings'] ) ) {
				return;
			}

			$general_settings = array();
			// Making sure the option we need, exists.
			if ( empty( $update_took_place ) && isset( $option['general_settings'] ) ) {
				$general_settings = $option['general_settings'];

				$selected_taxonomies = array();
				$exclude_taxonomies  = '';
				if ( isset( $general_settings['selected_taxonomies'] ) ) {
					// Get the selected Taxonomies from General Settings tab.
					$selected_taxonomies = $general_settings['selected_taxonomies'];
				}

				// Making sure to check "Excluded" if the main General Tab ahs it checked.
				if ( isset( $general_settings['exclude_taxonomies'] ) && ! empty( $general_settings['exclude_taxonomies'] ) ) {
					$exclude_taxonomies = $general_settings['exclude_taxonomies'];
				}

				// If there are any taxonomies selected in the general tab.
				if ( ! empty( $selected_taxonomies ) ) {

					if ( isset( $option['post_format'] ) && ! empty( $option['post_format'] ) ) {

						foreach ( $option['post_format'] as &$social_media_account_data ) {
							// If the options exists in Post Format but it's empty or,
							// If the option does not exist at all.
							if (
								! isset( $social_media_account_data['taxonomy_filter'] ) ||
								(
									isset( $social_media_account_data['taxonomy_filter'] ) &&
									empty( $social_media_account_data['taxonomy_filter'] )
								)
							) {
								// Add the taxonomies to all social media accounts.
								$social_media_account_data['taxonomy_filter'] = $selected_taxonomies;

								// If excluded is checked, we also add it to post format.
								$social_media_account_data['exclude_taxonomies'] = $exclude_taxonomies;

							}

							// inform that the update took place.
							$update_took_place = true;
						}
					}
				}

				if ( true === $update_took_place ) {
					// Create the option so that the migrate code will not run again.
					add_option( 'rop_data_migrated_tax', 'yes', null, 'no' );
					// Update the plugin data containing the changes.
					update_option( 'rop_data', $option );
				}
			}
		}
	}

	/**
	 * Checks to see if the cron schedule is firing.
	 *
	 * @since   8.4.3
	 * @access  public
	 */
	public function rop_cron_event_status_notice() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( get_user_meta( $user_id, 'rop-cron-event-status-notice-dismissed' ) ) {
			return;
		}

		$rop_next_task_hit = wp_next_scheduled( 'rop_cron_job' );
		$rop_current_time  = time();

		// if sharing not started cron event will not be present
		if ( ! $rop_next_task_hit ) {
			return;
		}

		$rop_cron_elapsed_time = ( $rop_current_time - $rop_next_task_hit ) / 60;
		$rop_cron_elapsed_time = absint( $rop_cron_elapsed_time );

		// default: 60 minutes
		$rop_cron_event_excess_elapsed_time = apply_filters( 'rop_cron_event_excess_elapsed_time', 60 );

		if ( $rop_cron_elapsed_time >= $rop_cron_event_excess_elapsed_time ) {

			?>
			<div class="notice notice-error">
				<?php echo sprintf( __( '%1$s%2$sRevive Social:%3$s There might be an issue preventing Revive Social from sharing to your connected accounts. If sharing is not working, then see %4$shere for solutions.%5$s%6$s%7$s', 'tweet-old-post' ), '<p>', '<b>', '</b>', '<a href="https://docs.revive.social/article/686-fix-revive-old-post-not-posting" target="_blank">', '</a>', '<a style="float: right;" href="?rop-cron-event-status-notice-dismissed">Dismiss</a>', '</p>' ); ?>

			</div>
			<?php

		}

	}

	/**
	 * Dismiss rop_cron_job not firing notice.
	 *
	 * @since   8.4.3
	 * @access  public
	 */
	public function rop_dismiss_rop_event_not_firing_notice() {

		$user_id = get_current_user_id();
		if ( isset( $_GET['rop-cron-event-status-notice-dismissed'] ) ) {
			add_user_meta( $user_id, 'rop-cron-event-status-notice-dismissed', 'true', true );
		}

	}

	/**
	 * Clears the array of account IDs.
	 *
	 * Delete the db option holding the account IDs used to determine when to send an email
	 * To website admin, letting them know that all posts have been shared; when the share more than once option is unchecked.
	 *
	 * @since   8.3.3
	 * @access  public
	 */
	public function rop_clear_one_time_share_accounts() {

		$settings = new Rop_Settings_Model();

		if ( ! $settings->get_more_than_once() ) {
			delete_option( 'rop_one_time_share_accounts' );
		}

	}

	/**
	 * Hides the own app option from the account modal
	 *
	 * This method hides the own app option for installs after v8.6.0 as a way to ease the transition
	 * to only the quick sign on method.
	 *
	 * @since   8.6.0
	 * @access  public
	 */
	private function rop_hide_add_own_app_option() {

		$installed_at_version = get_option( 'rop_first_install_version' );
		if ( empty( $installed_at_version ) ) {
			return false;
		}
		if ( version_compare( $installed_at_version, '8.6.0', '>=' ) ) {
			return true;
		}

		return false;

	}

	/**
	 * Check if WPML is active on the website.
	 *
	 * @since   8.5.8
	 * @access  public
	 * @return bool Whether or not the WPML plugin is active.
	 */
	public function rop_get_wpml_active_status() {

		if ( function_exists( 'icl_object_id' ) || class_exists( 'TRP_Translate_Press' ) ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Check YoastSEO is active on the website.
	 *
	 * @since   9.0.2
	 * @access  public
	 * @return bool Whether or not the YoastSEO plugin is active.
	 */
	public function rop_get_yoast_seo_active_status() {

		if ( function_exists( 'YoastSEO' ) ) {
			return true;
		} else {
			return false;
		}

	}

	/**
	 * Get WPML active languages.
	 *
	 * @since   8.5.8
	 * @access  public
	 * @return array Returns an array of active lanuages set in the WPML settings. NOTE: Though 'skip_missing' flag is set, WPML still returns all language codes, regardless if there are no posts using that translation on the website.
	 */
	public function rop_get_wpml_languages() {

		if ( $this->rop_get_wpml_active_status() === false ) {
			return;
		}

		$languages       = $this->get_languages();
		$languages_array = array();

		foreach ( $languages as $key => $value ) {
			$languages_array[] = array( 'code' => $key, 'label' => $value['native_name'] );
		}
		return $languages_array;
	}


	/**
	 * Filter an array accounts by the WPML language set for the account.
	 *
	 * @since   8.5.8
	 * @access  public
	 * @param int   $post_id The post ID.
	 * @param array $share_to_accounts The accounts to share to.
	 * @return array Returns an array of the accounts that WPML should share to based on the language user has chosen in Post Format Settings
	 */
	public function rop_wpml_filter_accounts( $post_id, $share_to_accounts ) {

		if ( ! is_array( $share_to_accounts ) ) {
			return '';
		}

		$post_format_model = new Rop_Post_Format_Model();
		$filtered_share_to_accounts = array();

		$post_lang_code = '';
		if ( function_exists( 'icl_object_id' ) ) {
			$post_lang_code = apply_filters( 'wpml_post_language_details', '', $post_id )['language_code'];
		}

		foreach ( $share_to_accounts as $account_id ) {

			$rop_account_post_format = $post_format_model->get_post_format( $account_id );

			if ( empty( $rop_account_post_format['wpml_language'] ) ) {
				continue;
			};

			$rop_account_lang_code = $rop_account_post_format['wpml_language'];
			if ( class_exists( 'TRP_Translate_Press' ) ) {
				$filtered_share_to_accounts[] = $account_id;
			} elseif ( $post_lang_code === $rop_account_lang_code ) {
				$filtered_share_to_accounts[] = $account_id;
			}
		}

		return empty( $filtered_share_to_accounts ) ? $share_to_accounts : $filtered_share_to_accounts;

	}

	/**
	 * Hides the pinterest account button
	 *
	 * Pinterest changed API and has no ETA on when they'll start reviewing developer apps.
	 * Disable this for now
	 *
	 * @since   8.6.0
	 * @access  public
	 */
	public function rop_hide_pinterest_network_btn() {

		$installed_at_version = get_option( 'rop_first_install_version' );
		if ( empty( $installed_at_version ) ) {
			return false;
		}
		if ( version_compare( $installed_at_version, '8.6.0', '>=' ) ) {
			echo '<style>
			
			#rop_core .btn.btn-pinterest{
				display: none;
			}
			
			</style>';
		}

		return false;

	}

	/**
	 * Hides the pinterest account button
	 *
	 * Pinterest changed API and has no ETA on when they'll start reviewing developer apps.
	 * Disable this for now
	 *
	 * @since   9.0.1
	 * @access  public
	 */
	public function rop_is_edit_post_screen() {

		// Can't use get_current_screen here because it wouldn't be populated with all the data needed
		if ( ! empty( $_GET['action'] ) && $_GET['action'] === 'edit' ) {
			return apply_filters( 'rop_is_edit_post_screen', true, get_the_ID() );
		}

		return false;

	}



	/**
	 * Check the post sharing limit before sharing the post.
	 *
	 * @param string $sharing_type Post sharing type.
	 * @return bool
	 */
	public static function rop_check_reached_sharing_limit( $sharing_type = 'tw' ) {
		$license_key = '';
		$plan_id     = 0;
		if ( 'valid' === apply_filters( 'product_rop_license_status', 'invalid' ) ) {
			$license_key = apply_filters( 'product_rop_license_key', '' );
			$plan_id     = apply_filters( 'product_rop_license_plan', 0 );
		}
		// Send API request.
		$response = wp_remote_post(
			ROP_POST_SHARING_CONTROL_API,
			apply_filters(
				'rop_post_sharing_limit_api_args',
				array(
					'timeout' => 100,
					'body'    => array_merge(
						array(
							'sharing_type' => $sharing_type,
							'license'      => $license_key,
							'plan_id'      => $plan_id,
							'site_url'     => get_site_url(),
						)
					),
				)
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body          = json_decode( wp_remote_retrieve_body( $response ) );
			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $response_code ) {
				return $body;
			}
		}
		return false;
	}

	/**
	 * Get the data used for the survey.
	 *
	 * @return array The survey metadata.
	 */
	public function get_survey_metadata() {
		$license_status = apply_filters( 'product_rop_license_status', 'invalid' );
		$license_plan   = apply_filters( 'product_rop_license_plan', false );
		$license_key    = apply_filters( 'product_rop_license_key', false );

		$install_days_number = intval( ( time() - get_option( 'rop_first_install_date', time() ) ) / DAY_IN_SECONDS );

		$data = array(
			'environmentId' => 'clwgcs7ia03df11mgz7gh15od',
			'attributes'    => array(
				'license_status'      => $license_status,
				'free_version'        => $this->version,
				'install_days_number' => $install_days_number,
			),
		);

		if ( ! empty( $license_plan ) ) {
			$data['attributes']['plan'] = strval( $license_plan );
		}

		if ( ! empty( $license_key ) ) {
			$data['attributes']['license_key'] = apply_filters( 'themeisle_sdk_secret_masking', $license_key );
		}

		if ( defined( 'ROP_PRO_VERSION' ) ) {
			$data['attributes']['pro_version'] = ROP_PRO_VERSION;
		}

		return $data;
	}

	/**
	 * Add upgrade to pro plugin action link.
	 *
	 * @param array  $actions Plugin actions.
	 * @param string $plugin_file Path to the plugin file relative to the plugins directory.
	 *
	 * @return array
	 */
	public function rop_upgrade_to_pro_plugin_action( $actions, $plugin_file ) {
		$global_settings     = new \Rop_Global_Settings();
		$actions['settings'] = '<a href="' . admin_url( 'admin.php?page=TweetOldPost' ) . '">' . __( 'Settings', 'tweet-old-post' ) . '</a>';
		if ( $global_settings->license_type() < 1 ) {
			return array_merge(
				array(
					'upgrade_link' => '<a href="' . add_query_arg(
						array(
							'utm_source'   => 'wpadmin',
							'utm_medium'   => 'plugins',
							'utm_campaign' => 'rowaction',
						),
						Rop_I18n::UPSELL_LINK
					) . '" title="' . __( 'More Features', 'tweet-old-post' ) . '"  target="_blank" rel="noopener noreferrer" style="color: #009E29; font-weight: 700;" onmouseover="this.style.color=\'#008a20\';" onmouseout="this.style.color=\'#009528\';" >' . __( 'Get Revive Social Pro', 'tweet-old-post' ) . '</a>',
				),
				$actions
			);
		}

		return $actions;
	}

	/**
	 * Get available languages.
	 *
	 * @return array
	 */
	public function get_languages() {
		// Get TranslatePress publish plugin languages.
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			$trp_settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
			if ( $trp_settings ) {
				$trp_languages     = TRP_Translate_Press::get_trp_instance()->get_component( 'languages' );
				$publish_languages = ! empty( $trp_settings['publish-languages'] ) ? $trp_settings['publish-languages'] : array();
				$publish_languages = $trp_languages->get_language_names( $publish_languages, 'native_name' );
				$languages         = array();
				foreach ( $publish_languages as $key => $publish_language ) {
					$languages[ $key ] = array(
						'native_name' => $publish_language,
					);
				}
				return $languages;
			}
		}
		return apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 1 ) );
	}

	/**
	 * Add the Black Friday configuration.
	 *
	 * @param array $configs An array of configurations.
	 *
	 * @return array The configurations.
	 */
	public static function add_black_friday_data( $configs ) {
		$config = $configs['default'];

		// translators: %1$s - HTML tag, %2$s - discount, %3$s - HTML tag, %4$s - product name.
		$message_template = __( 'Our biggest sale of the year: %1$sup to %2$s OFF%3$s on %4$s. Don\'t miss this limited-time offer.', 'tweet-old-post' );
		$product_label    = __( 'Revive Social', 'tweet-old-post' );
		$discount         = '50%';

		$plan    = apply_filters( 'product_rop_license_plan', 0 );
		$license = apply_filters( 'product_rop_license_key', false );
		$is_pro  = 0 < $plan;

		if ( $is_pro ) {
			// translators: %1$s - HTML tag, %2$s - discount, %3$s - HTML tag, %4$s - product name.
			$message_template = __( 'Get %1$sup to %2$s off%3$s when you upgrade your %4$s plan or renew early.', 'tweet-old-post' );
			$product_label    = __( 'Revive Social Pro', 'tweet-old-post' );
			$discount         = '20%';
		}

		$product_label = sprintf( '<strong>%s</strong>', $product_label );
		$url_params    = array(
			'utm_term' => $is_pro ? 'plan-' . $plan : 'free',
			'lkey'     => ! empty( $license ) ? $license : false,
		);

		$config['message']  = sprintf( $message_template, '<strong>', $discount, '</strong>', $product_label );
		$config['sale_url'] = add_query_arg(
			$url_params,
			tsdk_translate_link( tsdk_utmify( 'https://themeisle.com/rs-bf', 'bfcm', 'revive' ) )
		);

		$configs[ ROP_PRODUCT_SLUG ] = $config;

		return $configs;
	}

	/**
	 * Check if the current screen is the classic editor screen.
	 *
	 * @return bool True if it's the classic editor screen, false otherwise.
	 */
	public function is_classic_editor_screen() {
		if ( ! class_exists( 'Classic_Editor' ) ) {
			return false;
		}

		$current_screen = get_current_screen();
		return method_exists( $current_screen, 'is_block_editor' ) && $current_screen->is_block_editor();
	}

	/**
	 * Register meta for the plugin.
	 *
	 * @return void
	 */
	public function register_meta() {
		$auth_can_edit_posts = function () {
			return current_user_can( 'edit_posts' );
		};

		// JSON-encoded automatically by WP
		$sanitize_passthrough = function ( $value ) {
			return $value;
		};

		register_post_meta(
			'',
			'rop_custom_images_group',
			array(
				'single'            => true,
				'type'              => 'object',
				'sanitize_callback' => $sanitize_passthrough,
				'auth_callback'     => $auth_can_edit_posts,
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(), // Leave blank to allow dynamic keys, or define expected keys
						'additionalProperties' => array(
							'type'       => 'object',
							'properties' => array(
								'rop_custom_image' => array(
									'type' => 'integer',
								),
							),
						),
					),
				),
			)
		);

		register_post_meta(
			'',
			'rop_custom_messages_group',
			array(
				'single'            => true,
				'type'              => 'array',
				'sanitize_callback' => $sanitize_passthrough,
				'auth_callback'     => $auth_can_edit_posts,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'rop_custom_description' => array(
									'type' => 'string',
								),
							),
						),
					),
				),
			)
		);

		register_post_meta(
			'',
			'rop_publish_now',
			array(
				'single'            => true,
				'type'              => 'string',
				'default'           => 'initial', // Weird Gutenberg behavior that sends the default before sending the actual value, so we send a default that does not conflict with the actual values.
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth_can_edit_posts,
				'show_in_rest'      => true,
			)
		);

		$services = new Rop_Services_Model();
		$active   = array_keys( $services->get_active_accounts() );
		$accounts = array_fill_keys( $active, '' );

		register_post_meta(
			'',
			'rop_publish_now_accounts',
			array(
				'single'            => true,
				'type'              => 'object',
				'default'           => $accounts,
				'sanitize_callback' => $sanitize_passthrough,
				'auth_callback'     => $auth_can_edit_posts,
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_post_meta(
			'',
			'rop_publish_now_history',
			array(
				'single'            => true,
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => $sanitize_passthrough,
				'auth_callback'     => $auth_can_edit_posts,
				'show_in_rest' => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'account'   => array( 'type' => 'string' ),
								'service'   => array( 'type' => 'string' ),
								'timestamp' => array( 'type' => 'integer' ),
								'status'    => array( 'type' => 'string' ),
							),
							'required' => array( 'account', 'service', 'timestamp', 'status' ),
						),
					),
				),
			)
		);

		register_post_meta(
			'',
			'rop_publish_now_status',
			array(
				'single'            => true,
				'type'              => 'string',
				'default'           => 'pending',
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $auth_can_edit_posts,
				'show_in_rest'      => true,
			)
		);
	}
}
