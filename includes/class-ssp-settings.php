<?php
/**
 * MPP Settings
 *
 * @package Media_Portfolios_Podcasting
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class
 *
 * Handles plugin settings page
 *
 * @author      Hugh Lashbrooke
 * @category    Class
 * @package     SeriouslySimplePodcasting/Classes
 * @since       2.0
 */
class MPP_Settings {
	/**
	 * Directory
	 *
	 * @var string
	 */
	private $dir;
	/**
	 * File
	 *
	 * @var string
	 */
	private $file;
	/**
	 * Assets Directory
	 *
	 * @var string
	 */
	private $assets_dir;
	/**
	 * Assets URI
	 *
	 * @var string
	 */
	private $assets_url;
	/**
	 * Home Url
	 *
	 * @var string
	 */
	private $home_url;

	/**
	 * Templates Directory
	 *
	 * @var string
	 */
	private $templates_dir;
	/**
	 * Token
	 *
	 * @var string
	 */
	private $token;
	/**
	 * Settings Base
	 *
	 * @var string
	 */
	private $settings_base;
	/**
	 * Settings
	 *
	 * @var mixed
	 */
	private $settings;
	/**
	 * Version
	 *
	 * @var string version.
	 */
	private $version;

	/**
	 * Constructor
	 *
	 * @param string $file Plugin base file.
	 * @param string $version Plugin version
	 */
	public function __construct( $file, $version ) {
		$this->version       = $version;
		$this->file          = $file;
		$this->dir           = dirname( $this->file );
		$this->assets_dir    = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url    = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );
		$this->home_url      = trailingslashit( home_url() );
		$this->templates_dir = trailingslashit( $this->dir ) . 'templates';
		$this->token         = 'podcast';
		$this->settings_base = 'ss_podcasting_';

		add_action( 'init', array( $this, 'load_settings' ), 11 );

		add_action( 'init', array( $this, 'maybe_feed_saved' ), 11 );

		// Register podcast settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Add settings page to menu.
		add_action( 'admin_menu', array( $this, 'add_menu_item' ) );

		// Add settings link to plugins page.
		add_filter( 'plugin_action_links_' . plugin_basename( $this->file ), array( $this, 'add_plugin_links' ) );

		// Load scripts and styles for settings page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ), 10 );

		// Mark date on which feed redirection was activated.
		add_action( 'update_option', array( $this, 'mark_feed_redirect_date' ), 10, 3 );

		// Add ajax action for plugin rating.
		add_action( 'wp_ajax_validate_podmotor_api_credentials', array( $this, 'validate_podmotor_api_credentials' ) );

		// New caps for editors and above.
		add_action( 'admin_init', array( $this, 'add_caps' ), 1 );

		// process the import form submission
		add_action( 'admin_init', array( $this, 'submit_import_form' ) );

		// Trigger the disconnect action
		add_action( 'update_option_' . $this->settings_base . 'podmotor_disconnect', array( $this, 'maybe_disconnect_from_castos' ), 10, 2 );

		// Quick and dirty colour picker implementation
		// If we do not have the WordPress core colour picker field, then we don't break anything
		add_action( 'admin_footer', function () {
			?>
            <script>
                jQuery(document).ready(function ($) {
                    if ("function" === typeof $.fn.wpColorPicker) {
                        $('.ssp-color-picker').wpColorPicker();
                    }
                });
            </script>
			<?php
		}, 99 );

	}

	/**
	 * Load settings
	 */
	public function load_settings() {
		$this->settings = $this->settings_fields();
	}

	/**
	 * Triggers after a feed is saved, pushes the data to Castos
	 */
	public function maybe_feed_saved() {
		// Only do this if this is a Castos Customer
		if ( ! ssp_is_connected_to_podcastmotor() ) {
			return;
		}

		ssp_debug( 'About to update series', $_GET );

		if ( ! isset( $_GET['page'] ) || 'podcast_settings' !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['tab'] ) || 'feed-details' !== $_GET['tab'] ) {
			return;
		}
		if ( ! isset( $_GET['settings-updated'] ) || 'true' !== $_GET['settings-updated'] ) {
			return;
		}

		if ( isset( $_GET['feed-series'] ) ) {
			$feed_series_slug = ( isset( $_GET['feed-series'] ) ? filter_var( $_GET['feed-series'], FILTER_SANITIZE_STRING ) : '' );
			if ( empty( $feed_series_slug ) ) {
				return;
			}
			$series                   = get_term_by( 'slug', $feed_series_slug, 'series' );
			$series_data              = get_series_data_for_castos( $series->term_id );
			$series_data['series_id'] = $series->term_id;
		} else {
			$series_data              = get_series_data_for_castos( 0 );
			$series_data['series_id'] = 0;
		}

		$podmotor_handler = new Podmotor_Handler();
		$response = $podmotor_handler->upload_series_to_podmotor( $series_data );

		ssp_debug( 'Series Update', $response );

	}

	/**
	 * Add settings page to menu
	 *
	 * @return void
	 */
	public function add_menu_item() {
		add_submenu_page( 'edit.php?post_type=podcast', __( 'Podcast Settings', 'mps-podcasts' ), __( 'Settings', 'mps-podcasts' ), 'manage_podcast', 'podcast_settings', array(
			$this,
			'settings_page',
		) );

	}

	/**
	 * Show the upgrade page
	 */
	public function show_upgrade_page() {
		$ssp_redirect = ( isset( $_GET['ssp_redirect'] ) ? filter_var( $_GET['ssp_redirect'], FILTER_SANITIZE_STRING ) : '' );
		$ssp_dismiss_url = add_query_arg( array( 'ssp_dismiss_upgrade' => 'dismiss', 'ssp_redirect' => rawurlencode( $ssp_redirect ) ), admin_url( 'index.php' ) );
		include( $this->templates_dir . DIRECTORY_SEPARATOR . 'settings-upgrade-page.php' );
	}

	/**
	 * Add cabilities to edit podcast settings to admins, and editors.
	 */
	public function add_caps() {

		// Roles you'd like to have administer the podcast settings page.
		// Admin and Editor, as default.
		$roles = apply_filters( 'ssp_manage_podcast', array( 'administrator', 'editor' ) );

		// Loop through each role and assign capabilities.
		foreach ( $roles as $the_role ) {

			$role = get_role( $the_role );
			$caps = array(
				'manage_podcast',
			);

			// Add the caps.
			foreach ( $caps as $cap ) {
				$this->maybe_add_cap( $role, $cap );
			}
		}
	}

	/**
	 * Check to see if the given role has a cap, and add if it doesn't exist.
	 *
	 * @param  object $role User Cap object, part of WP_User.
	 * @param  string $cap Cap to test against.
	 *
	 * @return void
	 */
	public function maybe_add_cap( $role, $cap ) {
		// Update the roles, if needed.
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}

	/**
	 * Add links to plugin list table
	 *
	 * @param  array $links Default links.
	 *
	 * @return array $links Modified links
	 */
	public function add_plugin_links( $links ) {
		$settings_link = '<a href="edit.php?post_type=podcast&page=podcast_settings">' . __( 'Settings', 'mps-podcasts' ) . '</a>';
		array_push( $links, $settings_link );

		return $links;
	}

	/**
	 * Load admin javascript
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		global $pagenow;
		$page = ( isset( $_GET['page'] ) ? filter_var( $_GET['page'], FILTER_SANITIZE_STRING ) : '' );
		$pages = array( 'post-new.php', 'post.php' );
		if ( in_array( $pagenow, $pages, true ) || ( ! empty( $page ) && 'podcast_settings' === $page ) ) {
			wp_enqueue_media();
		}

		// // @todo add back for analytics launch
		// wp_enqueue_script( 'jquery-ui-datepicker' );
		// wp_register_style( 'jquery-ui', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css' );
		// wp_enqueue_style( 'jquery-ui' );

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// wp_enqueue_script( 'plotly', 'https://cdn.plot.ly/plotly-latest.min.js', MPP_VERSION, true );

	}

	/**
	 * Enqueue Styles
	 */
	public function enqueue_styles() {
		wp_register_style( 'ssp-settings', esc_url( $this->assets_url . 'css/settings.css' ), array(), $this->version );
		wp_enqueue_style( 'ssp-settings' );
	}

	/**
	 * Build settings fields
	 *
	 * @return array Fields to be displayed on settings page.
	 */
	private function settings_fields() {
		global $wp_post_types;

		$post_type_options = array();

		// Set options for post type selection.
		foreach ( $wp_post_types as $post_type => $data ) {

			if ( in_array( $post_type, array(
				'page',
				'attachment',
				'revision',
				'nav_menu_item',
				'wooframework',
				'podcast',
			), true ) ) {
				continue;
			}

			$post_type_options[ $post_type ] = $data->labels->name;
		}

		// Set up available category options.
		$category_options = array(
			''                           => __( '-- None --', 'mps-podcasts' ),
			'Arts'                       => __( 'Arts', 'mps-podcasts' ),
			'Business'                   => __( 'Business', 'mps-podcasts' ),
			'Comedy'                     => __( 'Comedy', 'mps-podcasts' ),
			'Education'                  => __( 'Education', 'mps-podcasts' ),
			'Games & Hobbies'            => __( 'Games & Hobbies', 'mps-podcasts' ),
			'Government & Organizations' => __( 'Government & Organizations', 'mps-podcasts' ),
			'Health'                     => __( 'Health', 'mps-podcasts' ),
			'Kids & Family'              => __( 'Kids & Family', 'mps-podcasts' ),
			'Music'                      => __( 'Music', 'mps-podcasts' ),
			'News & Politics'            => __( 'News & Politics', 'mps-podcasts' ),
			'Religion & Spirituality'    => __( 'Religion & Spirituality', 'mps-podcasts' ),
			'Science & Medicine'         => __( 'Science & Medicine', 'mps-podcasts' ),
			'Society & Culture'          => __( 'Society & Culture', 'mps-podcasts' ),
			'Sports & Recreation'        => __( 'Sports & Recreation', 'mps-podcasts' ),
			'Technology'                 => __( 'Technology', 'mps-podcasts' ),
			'TV & Film'                  => __( 'TV & Film', 'mps-podcasts' ),
		);

		// Set up available sub-category options.
		$subcategory_options = array(

			'' => __( '-- None --', 'mps-podcasts' ),

			'Design'           => array(
				'label' => __( 'Design', 'mps-podcasts' ),
				'group' => __( 'Arts', 'mps-podcasts' ),
			),
			'Fashion & Beauty' => array(
				'label' => __( 'Fashion & Beauty', 'mps-podcasts' ),
				'group' => __( 'Arts', 'mps-podcasts' ),
			),
			'Food'             => array(
				'label' => __( 'Food', 'mps-podcasts' ),
				'group' => __( 'Arts', 'mps-podcasts' ),
			),
			'Literature'       => array(
				'label' => __( 'Literature', 'mps-podcasts' ),
				'group' => __( 'Arts', 'mps-podcasts' ),
			),
			'Performing Arts'  => array(
				'label' => __( 'Performing Arts', 'mps-podcasts' ),
				'group' => __( 'Arts', 'mps-podcasts' ),
			),
			'Visual Arts'      => array(
				'label' => __( 'Visual Arts', 'mps-podcasts' ),
				'group' => __( 'Arts', 'mps-podcasts' ),
			),

			'Business News'          => array(
				'label' => __( 'Business News', 'mps-podcasts' ),
				'group' => __( 'Business', 'mps-podcasts' ),
			),
			'Careers'                => array(
				'label' => __( 'Careers', 'mps-podcasts' ),
				'group' => __( 'Business', 'mps-podcasts' ),
			),
			'Investing'              => array(
				'label' => __( 'Investing', 'mps-podcasts' ),
				'group' => __( 'Business', 'mps-podcasts' ),
			),
			'Management & Marketing' => array(
				'label' => __( 'Management & Marketing', 'mps-podcasts' ),
				'group' => __( 'Business', 'mps-podcasts' ),
			),
			'Shopping'               => array(
				'label' => __( 'Shopping', 'mps-podcasts' ),
				'group' => __( 'Business', 'mps-podcasts' ),
			),

			'Education'            => array(
				'label' => __( 'Education', 'mps-podcasts' ),
				'group' => __( 'Education', 'mps-podcasts' ),
			),
			'Education Technology' => array(
				'label' => __( 'Education Technology', 'mps-podcasts' ),
				'group' => __( 'Education', 'mps-podcasts' ),
			),
			'Higher Education'     => array(
				'label' => __( 'Higher Education', 'mps-podcasts' ),
				'group' => __( 'Education', 'mps-podcasts' ),
			),
			'K-12'                 => array(
				'label' => __( 'K-12', 'mps-podcasts' ),
				'group' => __( 'Education', 'mps-podcasts' ),
			),
			'Language Courses'     => array(
				'label' => __( 'Language Courses', 'mps-podcasts' ),
				'group' => __( 'Education', 'mps-podcasts' ),
			),
			'Training'             => array(
				'label' => __( 'Training', 'mps-podcasts' ),
				'group' => __( 'Education', 'mps-podcasts' ),
			),

			'Automotive'  => array(
				'label' => __( 'Automotive', 'mps-podcasts' ),
				'group' => __( 'Games & Hobbies', 'mps-podcasts' ),
			),
			'Aviation'    => array(
				'label' => __( 'Aviation', 'mps-podcasts' ),
				'group' => __( 'Games & Hobbies', 'mps-podcasts' ),
			),
			'Hobbies'     => array(
				'label' => __( 'Hobbies', 'mps-podcasts' ),
				'group' => __( 'Games & Hobbies', 'mps-podcasts' ),
			),
			'Other Games' => array(
				'label' => __( 'Other Games', 'mps-podcasts' ),
				'group' => __( 'Games & Hobbies', 'mps-podcasts' ),
			),
			'Video Games' => array(
				'label' => __( 'Video Games', 'mps-podcasts' ),
				'group' => __( 'Games & Hobbies', 'mps-podcasts' ),
			),

			'Local'      => array(
				'label' => __( 'Local', 'mps-podcasts' ),
				'group' => __( 'Government & Organizations', 'mps-podcasts' ),
			),
			'National'   => array(
				'label' => __( 'National', 'mps-podcasts' ),
				'group' => __( 'Government & Organizations', 'mps-podcasts' ),
			),
			'Non-Profit' => array(
				'label' => __( 'Non-Profit', 'mps-podcasts' ),
				'group' => __( 'Government & Organizations', 'mps-podcasts' ),
			),
			'Regional'   => array(
				'label' => __( 'Regional', 'mps-podcasts' ),
				'group' => __( 'Government & Organizations', 'mps-podcasts' ),
			),

			'Alternative Health'  => array(
				'label' => __( 'Alternative Health', 'mps-podcasts' ),
				'group' => __( 'Health', 'mps-podcasts' ),
			),
			'Fitness & Nutrition' => array(
				'label' => __( 'Fitness & Nutrition', 'mps-podcasts' ),
				'group' => __( 'Health', 'mps-podcasts' ),
			),
			'Self-Help'           => array(
				'label' => __( 'Self-Help', 'mps-podcasts' ),
				'group' => __( 'Health', 'mps-podcasts' ),
			),
			'Sexuality'           => array(
				'label' => __( 'Sexuality', 'mps-podcasts' ),
				'group' => __( 'Health', 'mps-podcasts' ),
			),

			'Buddhism'     => array(
				'label' => __( 'Buddhism', 'mps-podcasts' ),
				'group' => __( 'Religion & Spirituality', 'mps-podcasts' ),
			),
			'Christianity' => array(
				'label' => __( 'Christianity', 'mps-podcasts' ),
				'group' => __( 'Religion & Spirituality', 'mps-podcasts' ),
			),
			'Hinduism'     => array(
				'label' => __( 'Hinduism', 'mps-podcasts' ),
				'group' => __( 'Religion & Spirituality', 'mps-podcasts' ),
			),
			'Islam'        => array(
				'label' => __( 'Islam', 'mps-podcasts' ),
				'group' => __( 'Religion & Spirituality', 'mps-podcasts' ),
			),
			'Judaism'      => array(
				'label' => __( 'Judaism', 'mps-podcasts' ),
				'group' => __( 'Religion & Spirituality', 'mps-podcasts' ),
			),
			'Other'        => array(
				'label' => __( 'Other', 'mps-podcasts' ),
				'group' => __( 'Religion & Spirituality', 'mps-podcasts' ),
			),
			'Spirituality' => array(
				'label' => __( 'Spirituality', 'mps-podcasts' ),
				'group' => __( 'Religion & Spirituality', 'mps-podcasts' ),
			),

			'Medicine'         => array(
				'label' => __( 'Medicine', 'mps-podcasts' ),
				'group' => __( 'Science & Medicine', 'mps-podcasts' ),
			),
			'Natural Sciences' => array(
				'label' => __( 'Natural Sciences', 'mps-podcasts' ),
				'group' => __( 'Science & Medicine', 'mps-podcasts' ),
			),
			'Social Sciences'  => array(
				'label' => __( 'Social Sciences', 'mps-podcasts' ),
				'group' => __( 'Science & Medicine', 'mps-podcasts' ),
			),

			'History'           => array(
				'label' => __( 'History', 'mps-podcasts' ),
				'group' => __( 'Society & Culture', 'mps-podcasts' ),
			),
			'Personal Journals' => array(
				'label' => __( 'Personal Journals', 'mps-podcasts' ),
				'group' => __( 'Society & Culture', 'mps-podcasts' ),
			),
			'Philosophy'        => array(
				'label' => __( 'Philosophy', 'mps-podcasts' ),
				'group' => __( 'Society & Culture', 'mps-podcasts' ),
			),
			'Places & Travel'   => array(
				'label' => __( 'Places & Travel', 'mps-podcasts' ),
				'group' => __( 'Society & Culture', 'mps-podcasts' ),
			),

			'Amateur'               => array(
				'label' => __( 'Amateur', 'mps-podcasts' ),
				'group' => __( 'Sports & Recreation', 'mps-podcasts' ),
			),
			'College & High School' => array(
				'label' => __( 'College & High School', 'mps-podcasts' ),
				'group' => __( 'Sports & Recreation', 'mps-podcasts' ),
			),
			'Outdoor'               => array(
				'label' => __( 'Outdoor', 'mps-podcasts' ),
				'group' => __( 'Sports & Recreation', 'mps-podcasts' ),
			),
			'Professional'          => array(
				'label' => __( 'Professional', 'mps-podcasts' ),
				'group' => __( 'Sports & Recreation', 'mps-podcasts' ),
			),

			'Gadgets'         => array(
				'label' => __( 'Gadgets', 'mps-podcasts' ),
				'group' => __( 'Technology', 'mps-podcasts' ),
			),
			'Tech News'       => array(
				'label' => __( 'Tech News', 'mps-podcasts' ),
				'group' => __( 'Technology', 'mps-podcasts' ),
			),
			'Podcasting'      => array(
				'label' => __( 'Podcasting', 'mps-podcasts' ),
				'group' => __( 'Technology', 'mps-podcasts' ),
			),
			'Software How-To' => array(
				'label' => __( 'Software How-To', 'mps-podcasts' ),
				'group' => __( 'Technology', 'mps-podcasts' ),
			),
		);

		$settings = array();

		$settings['general'] = array(
			'title'       => __( 'General', 'mps-podcasts' ),
			'description' => __( 'General Settings', 'mps-podcasts' ),
			'fields'      => array(
				array(
					'id'          => 'use_post_types',
					'label'       => __( 'Podcast post types', 'mps-podcasts' ),
					'description' => __( 'Use this setting to enable podcast functions on any post type - this will add all podcast posts from the specified types to your podcast feed.', 'mps-podcasts' ),
					'type'        => 'checkbox_multi',
					'options'     => $post_type_options,
					'default'     => array(),
				),
				array(
					'id'          => 'include_in_main_query',
					'label'       => __( 'Include podcast in main blog', 'mps-podcasts' ),
					'description' => __( 'This setting may behave differently in each theme, so test it carefully after activation - it will add the \'podcast\' post type to your site\'s main query so that your podcast episodes appear on your home page along with your blog posts.', 'mps-podcasts' ),
					'type'        => 'checkbox',
					'default'     => '',
				),
				array(
					'id'          => 'player_locations',
					'label'       => __( 'Media player locations', 'mps-podcasts' ),
					'description' => __( 'Select where to show the podcast media player along with the episode data (download link, duration and file size)', 'mps-podcasts' ),
					'type'        => 'checkbox_multi',
					'options'     => array(
						'content'       => __( 'Full content', 'mps-podcasts' ),
						'excerpt'       => __( 'Excerpt', 'mps-podcasts' ),
						'excerpt_embed' => __( 'oEmbed Excerpt', 'mps-podcasts' ),
					),
					'default'     => array(),
				),
				array(
					'id'          => 'player_content_location',
					'label'       => __( 'Media player position', 'mps-podcasts' ),
					'description' => __( 'Select whether to display the media player above or below the full post content.', 'mps-podcasts' ),
					'type'        => 'radio',
					'options'     => array(
						'above' => __( 'Above content', 'mps-podcasts' ),
						'below' => __( 'Below content', 'mps-podcasts' ),
					),
					'default'     => 'above',
				),
				array(
					'id'          => 'player_content_visibility',
					'label'       => __( 'Media player visibility', 'mps-podcasts' ),
					'description' => __( 'Select whether to display the media player to everybody or only logged in users.', 'mps-podcasts' ),
					'type'        => 'radio',
					'options'     => array(
						'all'         => __( 'Everybody', 'mps-podcasts' ),
						'membersonly' => __( 'Only logged in users', 'mps-podcasts' ),
					),
					'default'     => 'all',
				),
				array(
					'id'          => 'itunes_fields_enabled',
					'label'       => __( 'Enable iTunes fields ', 'mps-podcasts' ),
					'description' => __( 'Turn this on to enable the iTunes iOS11 specific fields on each episode.', 'mps-podcasts' ),
					'type'        => 'checkbox',
					'default'     => '',
				),
				array(
					'id'          => 'player_meta_data_enabled',
					'label'       => __( 'Enable Player meta data ', 'mps-podcasts' ),
					'description' => __( 'Turn this on to enable player meta data underneath the player. (download link, episode duration and date recorded).', 'mps-podcasts' ),
					'type'        => 'checkbox',
					'default'     => 'on',
				),
				array(
					'id'          => 'player_style',
					'label'       => __( 'Media player style', 'mps-podcasts' ),
					'description' => __( 'Select the style of media player you wish to display on your site.', 'mps-podcasts' ),
					'type'        => 'radio',
					'options'     => array(
						'standard' => __( 'Standard Compact Player', 'mps-podcasts' ),
						'larger'   => __( 'HTML5 Player With Album Art', 'mps-podcasts' ),
					),
					'default'     => 'all',
				),
				array(
					'id'          => 'player_background_skin_colour',
					'label'       => __( 'Background skin colour', 'mps-podcasts' ),
					'description' => '<br>' . __( 'Only applicable if using the new HTML5 player', 'mps-podcasts' ),
					'type'        => 'colour-picker',
					'default'     => '#222222',
					'class'       => 'ssp-color-picker'
				),
				array(
					'id'          => 'player_wave_form_colour',
					'label'       => __( 'Player progress bar colour', 'mps-podcasts' ),
					'description' => '<br>' . __( 'Only applicable if using the new HTML5 player', 'mps-podcasts' ),
					'type'        => 'colour-picker',
					'default'     => '#fff',
					'class'       => 'ssp-color-picker'
				),
				array(
					'id'          => 'player_wave_form_progress_colour',
					'label'       => __( 'Player progress bar progress colour', 'mps-podcasts' ),
					'description' => '<br>' . __( 'Only applicable if using the new HTML5 player', 'mps-podcasts' ),
					'type'        => 'colour-picker',
					'default'     => '#00d4f7',
					'class'       => 'ssp-color-picker'
				),
			),
		);

		$settings['feed-details'] = array(
			'title'       => __( 'Feed details', 'mps-podcasts' ),
			'description' => sprintf( __( 'This data will be used in the feed for your podcast so your listeners will know more about it before they subscribe.%1$sAll of these fields are optional, but it is recommended that you fill in as many of them as possible. Blank fields will use the assigned defaults in the feed.%2$s', 'mps-podcasts' ), '<br/><em>', '</em>' ),
			'fields'      => array(
				array(
					'id'          => 'data_title',
					'label'       => __( 'Title', 'mps-podcasts' ),
					'description' => __( 'Your podcast title.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => get_bloginfo( 'name' ),
					'placeholder' => get_bloginfo( 'name' ),
					'class'       => 'large-text',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_subtitle',
					'label'       => __( 'Subtitle', 'mps-podcasts' ),
					'description' => __( 'Your podcast subtitle.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => get_bloginfo( 'description' ),
					'placeholder' => get_bloginfo( 'description' ),
					'class'       => 'large-text',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_author',
					'label'       => __( 'Author', 'mps-podcasts' ),
					'description' => __( 'Your podcast author.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => get_bloginfo( 'name' ),
					'placeholder' => get_bloginfo( 'name' ),
					'class'       => 'large-text',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_category',
					'label'       => __( 'Primary Category', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s primary category.', 'mps-podcasts' ),
					'type'        => 'select',
					'options'     => $category_options,
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_subcategory',
					'label'       => __( 'Primary Sub-Category', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s primary sub-category (if available) - must be a sub-category of the primary category selected above.', 'mps-podcasts' ),
					'type'        => 'select',
					'options'     => $subcategory_options,
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_category2',
					'label'       => __( 'Secondary Category', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s secondary category.', 'mps-podcasts' ),
					'type'        => 'select',
					'options'     => $category_options,
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_subcategory2',
					'label'       => __( 'Secondary Sub-Category', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s secondary sub-category (if available) - must be a sub-category of the secondary category selected above.', 'mps-podcasts' ),
					'type'        => 'select',
					'options'     => $subcategory_options,
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_category3',
					'label'       => __( 'Tertiary Category', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s tertiary category.', 'mps-podcasts' ),
					'type'        => 'select',
					'options'     => $category_options,
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_subcategory3',
					'label'       => __( 'Tertiary Sub-Category', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s tertiary sub-category (if available) - must be a sub-category of the tertiary category selected above.', 'mps-podcasts' ),
					'type'        => 'select',
					'options'     => $subcategory_options,
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_description',
					'label'       => __( 'Description/Summary', 'mps-podcasts' ),
					'description' => __( 'A description/summary of your podcast - no HTML allowed.', 'mps-podcasts' ),
					'type'        => 'textarea',
					'default'     => get_bloginfo( 'description' ),
					'placeholder' => get_bloginfo( 'description' ),
					'callback'    => 'wp_strip_all_tags',
					'class'       => 'large-text',
				),
				array(
					'id'          => 'data_image',
					'label'       => __( 'Cover Image', 'mps-podcasts' ),
					'description' => __( 'Your podcast cover image - must have a minimum size of 1400x1400 px.', 'mps-podcasts' ),
					'type'        => 'image',
					'default'     => '',
					'placeholder' => '',
					'callback'    => 'esc_url_raw',
				),
				array(
					'id'          => 'data_owner_name',
					'label'       => __( 'Owner name', 'mps-podcasts' ),
					'description' => __( 'Podcast owner\'s name.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => get_bloginfo( 'name' ),
					'placeholder' => get_bloginfo( 'name' ),
					'class'       => 'large-text',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_owner_email',
					'label'       => __( 'Owner email address', 'mps-podcasts' ),
					'description' => __( 'Podcast owner\'s email address.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => get_bloginfo( 'admin_email' ),
					'placeholder' => get_bloginfo( 'admin_email' ),
					'class'       => 'large-text',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_language',
					'label'       => __( 'Language', 'mps-podcasts' ),
					'description' => sprintf( __( 'Your podcast\'s language in %1$sISO-639-1 format%2$s.', 'mps-podcasts' ), '<a href="' . esc_url( 'http://www.loc.gov/standards/iso639-2/php/code_list.php' ) . '" target="' . wp_strip_all_tags( '_blank' ) . '">', '</a>' ),
					'type'        => 'text',
					'default'     => get_bloginfo( 'language' ),
					'placeholder' => get_bloginfo( 'language' ),
					'class'       => 'all-options',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'data_copyright',
					'label'       => __( 'Copyright', 'mps-podcasts' ),
					'description' => __( 'Copyright line for your podcast.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '&#xA9; ' . date( 'Y' ) . ' ' . get_bloginfo( 'name' ),
					'placeholder' => '&#xA9; ' . date( 'Y' ) . ' ' . get_bloginfo( 'name' ),
					'class'       => 'large-text',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'explicit',
					'label'       => __( 'Explicit', 'mps-podcasts' ),
					'description' => sprintf(__( 'To mark this podcast as an explicit podcast, check this box. Explicit content rules can be found %s.', 'mps-podcasts' ), '<a href="https://discussions.apple.com/thread/1079151">here</a>'),
					'type'        => 'checkbox',
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'complete',
					'label'       => __( 'Complete', 'mps-podcasts' ),
					'description' => __( 'Mark if this podcast is complete or not. Only do this if no more episodes are going to be added to this feed.', 'mps-podcasts' ),
					'type'        => 'checkbox',
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'publish_date',
					'label'       => __( 'Source for publish date', 'mps-podcasts' ),
					'description' => __( 'Use the "Published date" of the post or use "Date recorded" from the Podcast episode details.', 'mps-podcasts' ),
					'type'        => 'radio',
					'options'     => array( 'published' => __( 'Published date', 'mps-podcasts' ), 'recorded' => __( 'Recorded date', 'mps-podcasts' ) ),
					'default'     => 'published',
				),
				/**
				 * New iTunes Tag Announced At WWDC 2017
				 */
				array(
					'id'          => 'consume_order',
					'label'       => __( 'Show Type', 'mps-podcasts' ),
					'description' => sprintf( __( 'The order your podcast episodes will be listed. %1$sMore details here.%2$s', 'mps-podcasts' ), '<a href="' . esc_url( 'https://www.seriouslysimplepodcasting.com/ios-11-podcast-tags/' ) . '" target="' . wp_strip_all_tags( '_blank' ) . '">', '</a>' ),
					'type'        => 'select',
					'options'     => array(
						''         => __( 'Please Select', 'mps-podcasts' ),
						'episodic' => __( 'Episodic', 'mps-podcasts' ),
						'serial'   => __( 'Serial', 'mps-podcasts' )
					),
					'default'     => '',
				),
				array(
					'id'          => 'redirect_feed',
					'label'       => __( 'Redirect this feed to new URL', 'mps-podcasts' ),
					'description' => sprintf( __( 'Redirect your feed to a new URL (specified below).', 'mps-podcasts' ), '<br/>' ),
					'type'        => 'checkbox',
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'new_feed_url',
					'label'       => __( 'New podcast feed URL', 'mps-podcasts' ),
					'description' => __( 'Your podcast feed\'s new URL.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'New feed URL', 'mps-podcasts' ),
					'callback'    => 'esc_url_raw',
					'class'       => 'regular-text',
				),
				array(
					'id'          => 'itunes_url',
					'label'       => __( 'iTunes URL', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s iTunes URL.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'iTunes URL', 'mps-podcasts' ),
					'callback'    => 'esc_url_raw',
					'class'       => 'regular-text',
				),
				array(
					'id'          => 'stitcher_url',
					'label'       => __( 'Stitcher URL', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s Stitcher URL.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'Stitcher URL', 'mps-podcasts' ),
					'callback'    => 'esc_url_raw',
					'class'       => 'regular-text',
				),
				array(
					'id'          => 'google_play_url',
					'label'       => __( 'Google Play URL', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s Google Play URL.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'Google Play URL', 'mps-podcasts' ),
					'callback'    => 'esc_url_raw',
					'class'       => 'regular-text',
				),
				array(
					'id'          => 'spotify_url',
					'label'       => __( 'Spotify URL', 'mps-podcasts' ),
					'description' => __( 'Your podcast\'s Spotify URL.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'Spotify URL', 'mps-podcasts' ),
					'callback'    => 'esc_url_raw',
					'class'       => 'regular-text',
				),
			),
		);

		$settings['security'] = array(
			'title'       => __( 'Security', 'mps-podcasts' ),
			'description' => __( 'Change these settings to ensure that your podcast feed remains private. This will block feed readers (including iTunes) from accessing your feed.', 'mps-podcasts' ),
			'fields'      => array(
				array(
					'id'          => 'protect',
					'label'       => __( 'Password protect your podcast feed', 'mps-podcasts' ),
					'description' => __( 'Mark if you would like to password protect your podcast feed - you can set the username and password below. This will block all feed readers (including iTunes) from accessing your feed.', 'mps-podcasts' ),
					'type'        => 'checkbox',
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'protection_username',
					'label'       => __( 'Username', 'mps-podcasts' ),
					'description' => __( 'Username for your podcast feed.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'Feed username', 'mps-podcasts' ),
					'class'       => 'regular-text',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'protection_password',
					'label'       => __( 'Password', 'mps-podcasts' ),
					'description' => __( 'Password for your podcast feed. Once saved, the password is encoded and secured so it will not be visible on this page again.', 'mps-podcasts' ),
					'type'        => 'text_secret',
					'default'     => '',
					'placeholder' => __( 'Feed password', 'mps-podcasts' ),
					'callback'    => array( $this, 'encode_password' ),
					'class'       => 'regular-text',
				),
				array(
					'id'          => 'protection_no_access_message',
					'label'       => __( 'No access message', 'mps-podcasts' ),
					'description' => __( 'This message will be displayed to people who are not allowed access to your podcast feed. Limited HTML allowed.', 'mps-podcasts' ),
					'type'        => 'textarea',
					'default'     => __( 'You are not permitted to view this podcast feed.', 'mps-podcasts' ),
					'placeholder' => __( 'Message displayed to users who do not have access to the podcast feed', 'mps-podcasts' ),
					'callback'    => array( $this, 'validate_message' ),
					'class'       => 'large-text',
				),
			),
		);

		$settings['redirection'] = array(
			'title'       => __( 'Redirection', 'mps-podcasts' ),
			'description' => __( 'Use these settings to safely move your podcast to a different location. Only do this once your new podcast is setup and active.', 'mps-podcasts' ),
			'fields'      => array(
				array(
					'id'          => 'redirect_feed',
					'label'       => __( 'Redirect podcast feed to new URL', 'mps-podcasts' ),
					'description' => sprintf( __( 'Redirect your feed to a new URL (specified below).%1$sThis will inform all podcasting services that your podcast has moved and 48 hours after you have saved this option it will permanently redirect your feed to the new URL.', 'mps-podcasts' ), '<br/>' ),
					'type'        => 'checkbox',
					'default'     => '',
					'callback'    => 'wp_strip_all_tags',
				),
				array(
					'id'          => 'new_feed_url',
					'label'       => __( 'New podcast feed URL', 'mps-podcasts' ),
					'description' => __( 'Your podcast feed\'s new URL.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'New feed URL', 'mps-podcasts' ),
					'callback'    => 'esc_url_raw',
					'class'       => 'regular-text',
				),
			),
		);

		$settings['publishing'] = array(
			'title'       => __( 'Publishing', 'mps-podcasts' ),
			'description' => __( 'Use these URLs to share and publish your podcast feed. These URLs will work with any podcasting service (including iTunes).', 'mps-podcasts' ),
			'fields'      => array(
				array(
					'id'          => 'feed_url',
					'label'       => __( 'External feed URL', 'mps-podcasts' ),
					'description' => __( 'If you are syndicating your podcast using a third-party service (like Feedburner) you can insert the URL here, otherwise this must be left blank.', 'mps-podcasts' ),
					'type'        => 'text',
					'default'     => '',
					'placeholder' => __( 'External feed URL', 'mps-podcasts' ),
					'callback'    => 'esc_url_raw',
					'class'       => 'regular-text',
				),
				array(
					'id'          => 'feed_link',
					'label'       => __( 'Complete feed', 'mps-podcasts' ),
					'description' => '',
					'type'        => 'feed_link',
					'callback'    => 'esc_url_raw',
				),
				array(
					'id'          => 'feed_link_series',
					'label'       => __( 'Feed for a specific series', 'mps-podcasts' ),
					'description' => '',
					'type'        => 'feed_link_series',
					'callback'    => 'esc_url_raw',
				),
				array(
					'id'          => 'podcast_url',
					'label'       => __( 'Podcast page', 'mps-podcasts' ),
					'description' => '',
					'type'        => 'podcast_url',
					'callback'    => 'esc_url_raw',
				),
			),
		);

		$settings = apply_filters( 'ssp_settings_fields', $settings );

		return $settings;
	}

	/**
	 * Register plugin settings
	 *
	 * @return void
	 */
	public function register_settings() {
		if ( is_array( $this->settings ) ) {
			$tab = ( isset( $_POST['tab'] ) ? filter_var( $_POST['tab'], FILTER_SANITIZE_STRING ) : '' );
			// Check posted/selected tab.
			$current_section = 'general';
			if ( ! empty( $tab ) ) {
				$current_section = $tab;
			} else {
				$tab = ( isset( $_GET['tab'] ) ? filter_var( $_GET['tab'], FILTER_SANITIZE_STRING ) : '' );
				if ( ! empty( $tab ) ) {
					$current_section = $tab;
				}
			}

			foreach ( $this->settings as $section => $data ) {

				if ( $current_section && $current_section !== $section ) {
					continue;
				}

				// Get data for specific feed series.
				$title_tail = '';
				$series_id  = 0;
				if ( 'feed-details' === $section ) {
					$feed_series = ( isset( $_REQUEST['feed-series'] ) ? filter_var( $_REQUEST['feed-series'], FILTER_SANITIZE_STRING ) : '' );
					if ( ! empty( $feed_series ) && 'default' !== $feed_series ) {

						// Get selected series.
						$series = get_term_by( 'slug', esc_attr( $feed_series ), 'series' );

						// Store series ID for later use.
						$series_id = $series->term_id;

						// Append series name to section title.
						if ( $series ) {
							$title_tail = ': ' . $series->name;
						}
					}
				}

				$section_title = $data['title'] . $title_tail;

				// Add section to page.
				add_settings_section( $section, $section_title, array( $this, 'settings_section' ), 'ss_podcasting' );

				if ( ! empty( $data['fields'] ) ) {

					foreach ( $data['fields'] as $field ) {

						// Validation callback for field.
						$validation = '';
						if ( isset( $field['callback'] ) ) {
							$validation = $field['callback'];
						}

						// Get field option name.
						$option_name = $this->settings_base . $field['id'];

						// Append series ID if selected.
						if ( $series_id ) {
							$option_name .= '_' . $series_id;
						}

						// Register setting.
						register_setting( 'ss_podcasting', $option_name, $validation );

						if ( 'hidden' === $field['type'] ) {
							continue;
						}

						$container_class = '';
						if ( isset( $field['container_class'] ) && ! empty( $field['container_class'] ) ) {
							$container_class = $field['container_class'];
						}

						// Add field to page.
						add_settings_field( $field['id'], $field['label'],
							array(
								$this,
								'display_field',
							),
							'ss_podcasting',
							$section,
							array(
								'field'       => $field,
								'prefix'      => $this->settings_base,
								'feed-series' => $series_id,
								'class'       => $container_class
							)
						);
					}
				}
			}
		}
	}

	/**
	 * Settings Section
	 *
	 * @param string $section section.
	 */
	public function settings_section( $section ) {
		$html = '<p>' . $this->settings[ $section['id'] ]['description'] . '</p>' . "\n";

		if ( 'feed-details' === $section['id'] ) {

			$feed_series = 'default';
			if ( isset( $_GET['feed-series'] ) ) {
				$feed_series = esc_attr( $_GET['feed-series'] );
			}

			$permalink_structure = get_option( 'permalink_structure' );

			if ( $permalink_structure ) {
				$feed_slug = apply_filters( 'ssp_feed_slug', $this->token );
				$feed_url  = $this->home_url . 'feed/' . $feed_slug;
			} else {
				$feed_url = $this->home_url . '?feed=' . $this->token;
			}

			if ( $feed_series && 'default' !== $feed_series ) {
				if ( $permalink_structure ) {
					$feed_url .= '/' . $feed_series;
				} else {
					$feed_url .= '&podcast_series=' . $feed_series;
				}
			}

			if ( $feed_url ) {
				$html .= '<p><a class="view-feed-link" href="' . esc_url( $feed_url ) . '" target="_blank"><span class="dashicons dashicons-rss"></span>' . __( 'View feed', 'mps-podcasts' ) . '</a></p>' . "\n";
			}
		}

		if ( 'import' === $section['id'] ) {
			$html = $this->render_import_form();
		}

		if ( 'extensions' === $section['id'] ) {
			$html .= $this->render_seriously_simple_extensions();
		}

		echo $html;
	}

	/**
	 * Generate HTML for displaying fields
	 *
	 * @param  array $args Field data
	 *
	 * @return void
	 */
	public function display_field( $args ) {

		$field = $args['field'];

		$html = '';

		// Get option name
		$option_name         = $this->settings_base . $field['id'];
		$default_option_name = $option_name;

		// Get field default
		$default = '';
		if ( isset( $field['default'] ) ) {
			$default = $field['default'];
		}

		// Get option value
		$data = get_option( $option_name, $default );

		// Get specific series data if applicable
		if ( isset( $args['feed-series'] ) && $args['feed-series'] ) {

			$option_default = '';

			// Set placeholder to default feed option with specified default fallback
			if ( $data ) {
				$field['placeholder'] = $data;

				if ( in_array( $field['type'], array( 'checkbox', 'select', 'image' ), true ) ) {
					$option_default = $data;
				}
			}

			// Append series ID to option name
			$option_name .= '_' . $args['feed-series'];

			// Get series-specific option
			$data = get_option( $option_name, $option_default );

		}

		// Get field class if supplied
		$class = '';
		if ( isset( $field['class'] ) ) {
			$class = $field['class'];
		}

		// Get parent class if supplied
		$parent_class = '';
		if ( isset( $field['parent_class'] ) ) {
			$parent_class = $field['parent_class'];
		}

		switch ( $field['type'] ) {
			case 'text':
			case 'password':
			case 'number':
				$html .= '<input id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $option_name ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" value="' . esc_attr( $data ) . '" class="' . $class . '"/>' . "\n";
				break;
			case 'colour-picker':
				$html .= '<input id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $data ) . '" class="' . $class . '"/>' . "\n";
				break;

			case 'text_secret':
				$placeholder = $field['placeholder'];
				if ( $data ) {
					$placeholder = __( 'Password stored securely', 'mps-podcasts' );
				}
				$html .= '<input id="' . esc_attr( $field['id'] ) . '" type="text" name="' . esc_attr( $option_name ) . '" placeholder="' . esc_attr( $placeholder ) . '" value="" class="' . $class . '"/>' . "\n";
				break;

			case 'textarea':
				$html .= '<textarea id="' . esc_attr( $field['id'] ) . '" rows="5" cols="50" name="' . esc_attr( $option_name ) . '" placeholder="' . esc_attr( $field['placeholder'] ) . '" class="' . $class . '">' . $data . '</textarea><br/>' . "\n";
				break;

			case 'checkbox':
				$checked = '';
				if ( $data && 'on' === $data ) {
					$checked = 'checked="checked"';
				}
				$html .= '<input id="' . esc_attr( $field['id'] ) . '" type="' . $field['type'] . '" name="' . esc_attr( $option_name ) . '" ' . $checked . ' class="' . $class . '"/>' . "\n";
				break;

			case 'checkbox_multi':
				foreach ( $field['options'] as $k => $v ) {
					$checked = false;
					if ( in_array( $k, (array) $data, true ) ) {
						$checked = true;
					}
					$html .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '"><input type="checkbox" ' . checked( $checked, true, false ) . ' name="' . esc_attr( $option_name ) . '[]" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" class="' . $class . '" /> ' . $v . '</label><br/>';
				}
				break;

			case 'radio':
				foreach ( $field['options'] as $k => $v ) {
					$checked = false;
					if ( $k === $data ) {
						$checked = true;
					}
					$html .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '"><input type="radio" ' . checked( $checked, true, false ) . ' name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $k ) . '" id="' . esc_attr( $field['id'] . '_' . $k ) . '" class="' . $class . '" /> ' . $v . '</label><br/>';
				}
				break;

			case 'select':

				$html .= '<select name="' . esc_attr( $option_name ) . '" id="' . esc_attr( $field['id'] ) . '" class="' . $class . '">';
				$prev_group = '';
				foreach ( $field['options'] as $k => $v ) {

					$group = '';
					if ( is_array( $v ) ) {
						if ( isset( $v['group'] ) ) {
							$group = $v['group'];
						}
						$v = $v['label'];
					}

					if ( $prev_group && $group !== $prev_group ) {
						$html .= '</optgroup>';
					}

					$selected = false;
					if ( $k === $data ) {
						$selected = true;
					}

					if ( $group && $group !== $prev_group ) {
						$html .= '<optgroup label="' . esc_attr( $group ) . '">';
					}

					$html .= '<option ' . selected( $selected, true, false ) . ' value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';

					$prev_group = $group;
				}
				$html .= '</select> ';
				break;

			case 'image':
				$html .= '<img id="' . esc_attr( $default_option_name ) . '_preview" src="' . esc_attr( $data ) . '" style="max-width:400px;height:auto;" /><br/>' . "\n";
				$html .= '<input id="' . esc_attr( $default_option_name ) . '_button" type="button" class="button" value="' . __( 'Upload new image', 'mps-podcasts' ) . '" />' . "\n";
				$html .= '<input id="' . esc_attr( $default_option_name ) . '_delete" type="button" class="button" value="' . __( 'Remove image', 'mps-podcasts' ) . '" />' . "\n";
				$html .= '<input id="' . esc_attr( $default_option_name ) . '" type="hidden" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $data ) . '"/><br/>' . "\n";
				break;

			case 'feed_link':

				// Set feed URL based on site's permalink structure
				if ( get_option( 'permalink_structure' ) ) {
					$feed_slug = apply_filters( 'ssp_feed_slug', $this->token );
					$url       = $this->home_url . 'feed/' . $feed_slug;
				} else {
					$url = $this->home_url . '?feed=' . $this->token;
				}

				$html .= '<a href="' . esc_url( $url ) . '" target="_blank">' . $url . '</a>';
				break;

			case 'feed_link_series':

				// Set feed URL based on site's permalink structure
				if ( get_option( 'permalink_structure' ) ) {
					$feed_slug = apply_filters( 'ssp_feed_slug', $this->token );
					$url       = $this->home_url . 'feed/' . $feed_slug . '/series-slug';
				} else {
					$url = $this->home_url . '?feed=' . $this->token . '&podcast_series=series-slug';
				}

				$html .= esc_url( $url ) . "\n";
				break;

			case 'podcast_url';

				$slug        = apply_filters( 'ssp_archive_slug', __( 'podcast', 'mps-podcasts' ) );
				$podcast_url = $this->home_url . $slug;

				$html .= '<a href="' . esc_url( $podcast_url ) . '" target="_blank">' . $podcast_url . '</a>';
				break;

			case 'importing_podcasts';
				$data = ssp_get_importing_podcasts_count();
				$html .= '<input type="input" value="' . esc_attr( $data ) . '" class="' . $class . '" disabled/>' . "\n";
				break;

		}

		if ( ! in_array( $field['type'], array( 'feed_link', 'feed_link_series', 'podcast_url', 'hidden' ), true ) ) {
			switch ( $field['type'] ) {
				case 'checkbox_multi':
				case 'radio':
				case 'select_multi':
					$html .= '<br/><span class="description">' . esc_attr( $field['description'] ) . '</span>';
					break;
				default:
					$html .= '<label for="' . esc_attr( $field['id'] ) . '"><span class="description">' . wp_kses_post( $field['description'] ) . '</span></label>' . "\n";
					break;
			}
		}

		if ( $parent_class ) {
			$html = '<div class="' . $parent_class . '">' . $html . '</div>';
		}

		echo $html;
	}

	/**
	 * Validate URL slug
	 *
	 * @param  string $slug User input
	 *
	 * @return string       Validated string
	 */
	public function validate_slug( $slug ) {
		if ( $slug && strlen( $slug ) > 0 && $slug != '' ) {
			$slug = urlencode( strtolower( str_replace( ' ', '-', $slug ) ) );
		}

		return $slug;
	}

	/**
	 * Encode feed password
	 *
	 * @param  string $password User input
	 *
	 * @return string           Encoded password
	 */
	public function encode_password( $password ) {

		if ( $password && strlen( $password ) > 0 && $password != '' ) {
			$password = md5( $password );
		} else {
			$option   = get_option( 'ss_podcasting_protection_password' );
			$password = $option;
		}

		return $password;
	}

	/**
	 * Validate protectino message
	 *
	 * @param  string $message User input
	 *
	 * @return string          Validated message
	 */
	public function validate_message( $message ) {

		if ( $message ) {

			$allowed = array(
				'a'      => array(
					'href'   => array(),
					'title'  => array(),
					'target' => array(),
				),
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'p'      => array(),
			);

			$message = wp_kses( $message, $allowed );
		}

		return $message;
	}

	/**
	 * Mark redirect date for feed
	 *
	 * @param  string $option Name of option being updated
	 * @param  mixed $old_value Old value of option
	 * @param  mixed $new_value New value of option
	 *
	 * @return void
	 */
	public function mark_feed_redirect_date( $option, $old_value, $new_value ) {

		if ( $option == 'ss_podcasting_redirect_feed' ) {
			if ( ( $new_value != $old_value ) && $new_value == 'on' ) {
				$date = time();
				update_option( 'ss_podcasting_redirect_feed_date', $date );
			}
		}

	}

	/**
	 * Validate the Seriously Simple Hosting api credentials
	 */
	public function validate_podmotor_api_credentials() {
		$podmotor_account_api_token = ( isset( $_GET['api_token'] ) ? filter_var( $_GET['api_token'], FILTER_SANITIZE_STRING ) : '' );
		$podmotor_account_email     = ( isset( $_GET['email'] ) ? filter_var( $_GET['email'], FILTER_SANITIZE_STRING ) : '' );

		$podmotor_handler           = new Podmotor_Handler();
		$response                   = $podmotor_handler->validate_api_credentials( $podmotor_account_api_token, $podmotor_account_email );
		wp_send_json( $response );
	}

	/**
	 * Generate HTML for settings page
	 * @return void
	 */
	public function settings_page() {

		$q_args = wp_parse_args( $_GET, array(
			'post_type' => null,
			'page'      => null,
			'view'      => null,
			'tab'       => null
		) );

		array_walk( $q_args, function ( &$entry ) {
			$entry = sanitize_title( $entry );
		} );

		/* @todo Add Back For Stats Later On */
		/*if( "analytics" === $q_args['view'] ){
			ob_start();
			include MPP_PLUGIN_PATH . 'includes/views/ssp-analytics.php';
			echo ob_get_clean();
			return;
		}*/

		// Build page HTML
		$html = '<div class="wrap" id="podcast_settings">' . "\n";

		$html .= '<h1>' . __( 'Podcast Settings', 'mps-podcasts' ) . '</h1>' . "\n";

		$tab = 'general';
		if ( isset( $_GET['tab'] ) && $_GET['tab'] ) {
			$tab = $_GET['tab'];
		}

		$html .= '<div id="main-settings">' . "\n";

		// Show page tabs
		if ( is_array( $this->settings ) && 1 < count( $this->settings ) ) {

			$html .= '<h2 class="nav-tab-wrapper">' . "\n";

			$c = 0;

			foreach ( $this->settings as $section => $data ) {

				// Set tab class
				$class = 'nav-tab';
				if ( ! isset( $_GET['tab'] ) ) {
					if ( 0 === $c ) {
						$class .= ' nav-tab-active';
					}
				} else {
					if ( isset( $_GET['tab'] ) && $section == $_GET['tab'] ) {
						$class .= ' nav-tab-active';
					}
				}

				// Set tab link
				$tab_link = add_query_arg( array( 'tab' => $section ) );
				if ( isset( $_GET['settings-updated'] ) ) {
					$tab_link = remove_query_arg( 'settings-updated', $tab_link );
				}

				if ( isset( $_GET['feed-series'] ) ) {
					$tab_link = remove_query_arg( 'feed-series', $tab_link );
				}

				// Output tab
				$html .= '<a href="' . esc_url( $tab_link ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $data['title'] ) . '</a>' . "\n";

				++ $c;
			}

			$html .= '</h2>' . "\n";
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			$html .= '<br/><div class="updated notice notice-success is-dismissible">
									<p>' . sprintf( __( '%1$s settings updated.', 'mps-podcasts' ), '<b>' . str_replace( '-', ' ', ucwords( $tab ) ) . '</b>' ) . '</p>
								</div>';
		}

		if ( function_exists( 'php_sapi_name' ) && 'security' == $tab ) {
			$sapi_type = php_sapi_name();
			if ( strpos( $sapi_type, 'fcgi' ) !== false ) {
				$html .= '<br/><div class="update-nag">
									<p>' . sprintf( __( 'It looks like your server has FastCGI enabled, which will prevent the feed password protection feature from working. You can fix this by following %1$sthis quick guide%2$s.', 'mps-podcasts' ), '<a href="http://www.seriouslysimplepodcasting.com/documentation/why-does-the-feed-password-protection-feature-not-work/" target="_blank">', '</a>' ) . '</p>
								</div>';
			}
		}

		$current_series = '';

		// Series submenu for feed details
		if ( 'feed-details' == $tab ) {
			$series = get_terms( 'series', array( 'hide_empty' => false ) );

			if ( ! empty( $series ) ) {

				if ( isset( $_GET['feed-series'] ) && $_GET['feed-series'] && 'default' != $_GET['feed-series'] ) {
					$current_series = esc_attr( $_GET['feed-series'] );
					$series_class   = '';
				} else {
					$current_series = 'default';
					$series_class   = 'current';
				}

				$html .= '<div class="feed-series-list-container">' . "\n";
				$html .= '<span id="feed-series-toggle" class="series-open" title="' . __( 'Toggle series list display', 'mps-podcasts' ) . '"></span>' . "\n";

				$html .= '<ul id="feed-series-list" class="subsubsub series-open">' . "\n";
				$html .= '<li><a href="' . add_query_arg( array(
						'feed-series'      => 'default',
						'settings-updated' => false
					) ) . '" class="' . $series_class . '">' . __( 'Default feed', 'mps-podcasts' ) . '</a></li>';

				foreach ( $series as $s ) {

					if ( $current_series == $s->slug ) {
						$series_class = 'current';
					} else {
						$series_class = '';
					}

					$html .= '<li>' . "\n";
					$html .= ' | <a href="' . esc_url( add_query_arg( array(
							'feed-series'      => $s->slug,
							'settings-updated' => false
						) ) ) . '" class="' . $series_class . '">' . $s->name . '</a>' . "\n";
					$html .= '</li>' . "\n";
				}

				$html .= '</ul>' . "\n";
				$html .= '<br class="clear" />' . "\n";
				$html .= '</div>' . "\n";

			}
		}

		if ( isset( $tab ) && 'import' == $tab ) {
			$current_admin_url = add_query_arg(
				array(
					'post_type' => 'podcast',
					'page'      => 'podcast_settings',
					'tab'       => 'import',
				),
				admin_url( 'edit.php' )
			);
			$html .= '<form method="post" action="' . esc_url_raw( $current_admin_url ) . '" enctype="multipart/form-data">' . "\n";
			$html .= '<input type="hidden" name="action" value="post_import_form" />';
			$html .= wp_nonce_field( 'ss_podcasting-import' );
		} else {
			$html .= '<form method="post" action="options.php" enctype="multipart/form-data">' . "\n";
		}


		// Add current series to posted data
		if ( $current_series ) {
			$html .= '<input type="hidden" name="feed-series" value="' . esc_attr( $current_series ) . '" />' . "\n";
		}

		if ( isset( $tab ) && 'castos-hosting' == $tab ) {
			$podmotor_account_id = get_option( 'ss_podcasting_podmotor_account_id', '' );
			$html .= '<input id="podmotor_account_id" type="hidden" name="ss_podcasting_podmotor_account_id" placeholder="" value="' . $podmotor_account_id . '" class="regular-text disabled" readonly="">' . "\n";
		}

		// Get settings fields
		ob_start();
		if ( isset( $tab ) && 'import' !== $tab ) {
			settings_fields( 'ss_podcasting' );
		}
		do_settings_sections( 'ss_podcasting' );
		$html .= ob_get_clean();

		if ( isset( $tab ) && 'castos-hosting' == $tab ) {
			// Validate button
			$html .= '<p class="submit">' . "\n";
			$html .= '<input id="validate_api_credentials" type="button" class="button-primary" value="' . esc_attr( __( 'Validate Credentials', 'mps-podcasts' ) ) . '" />' . "\n";
			$html .= '<span class="validate-api-credentials-message"></span>' . "\n";
			$html .= '</p>' . "\n";
		}

		$disable_save_button_on_tabs = array( 'extensions', 'import' );

		if ( ! in_array( $tab, $disable_save_button_on_tabs ) ) {
			// Submit button
			$html .= '<p class="submit">' . "\n";
			$html .= '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />' . "\n";
			$html .= '<input id="ssp-settings-submit" name="Submit" type="submit" class="button-primary" value="' . esc_attr( __( 'Save Settings', 'mps-podcasts' ) ) . '" />' . "\n";
			$html .= '</p>' . "\n";
		}

		$html .= '</form>' . "\n";

		$html .= '</div>' . "\n";

		$html .= '</div>' . "\n";

		echo $html;
	}

	public function render_import_form() {
		$site_name    = get_bloginfo( 'name' );
		$current_user = wp_get_current_user();
		ob_start();
		?>
		<p>If you have a podcast hosted on an external service (like Libsyn, Soundcloud or Simplecast) send us a message below and our team will personally import all of your media files and associated posts for you.</p>
		<table class="form-table">
			<tbody>
			<tr>
				<th scope="row">Your name</th>
				<td>
					<input id="name" name="name" type="text" placeholder="Name" value="<?php echo esc_attr( $current_user->user_firstname ) . ' ' . esc_attr( $current_user->user_lastname ) ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Your website name</th>
				<td>
					<input id="website" name="website" type="text" placeholder="Website" value="<?php echo esc_attr( $site_name ) ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Your email address</th>
				<td>
					<input id="email" name="email" type="text" placeholder="email@domain.com" value="<?php echo esc_attr( $current_user->user_email ) ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">Your external podcast url</th>
				<td>
					<input id="podcast_url" name="podcast_url" type="text" placeholder="https://example.com/rss" value="" class="regular-text">
				</td>
			</tr>
			</tbody>
		</table>
		<p class="submit">
			<input id="ssp-settings-submit" name="Submit" type="submit" class="button-primary" value="<?php echo esc_attr( __( 'Submit Form', 'mps-podcasts' ) ) ?>" />
		</p>
		<?php
		$html = ob_get_clean();
		return $html;
	}

	public function submit_import_form() {
		$action = ( isset( $_POST['action'] ) ? filter_var( $_POST['action'], FILTER_SANITIZE_STRING ) : '' );

		if ( ! empty( $action ) && 'post_import_form' === $action ) {
			check_admin_referer( 'ss_podcasting-import' );
			$name        = filter_var( $_POST['name'], FILTER_SANITIZE_STRING );
			$website     = filter_var( $_POST['website'], FILTER_SANITIZE_STRING );
			$email       = filter_var( $_POST['email'], FILTER_SANITIZE_EMAIL );
			$podcast_url = filter_var( $_POST['podcast_url'], FILTER_SANITIZE_URL );

			$new_line    = "\n";
			$site_name   = $name;
			$to          = 'hello@seriouslysimplepodcasting.com';
			$subject     = sprintf( __( 'Podcast import request' ), $site_name );
			$message     = sprintf( __( 'Hi Craig %1$s' ), $new_line );
			$message    .= sprintf( __( '%1$s (owner of %2$s) would like your assistance with manually importing his podcast from %3$s. %4$s' ), $name, $website, $podcast_url, $new_line );
			$message    .= sprintf( __( 'Please contact him at %1$s. %2$s' ), $email, $new_line );
			$from        = sprintf( 'From: "%1$s" <%2$s>', _x( 'Site Admin', 'email "From" field' ), $to );
			wp_mail( $to, $subject, $message, $from );
			?>
			<div class="notice notice-info is-dismissible">
				<p><?php esc_attr_e( 'Thanks, someone from Castos will be in touch. to assist with importing your podcast', 'mps-podcasts' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Disconnects a user from the Castos Hosting service by deleting their API keys
	 * Triggered by the update_option_ss_podcasting_podmotor_disconnect action hook
	 */
	public function maybe_disconnect_from_castos( $old_value, $new_value ) {
		if ( 'on' != $new_value ) {
			return;
		}
		delete_option( $this->settings_base . 'podmotor_account_email' );
		delete_option( $this->settings_base . 'podmotor_account_api_token' );
		delete_option( $this->settings_base . 'podmotor_account_id' );
		delete_option( $this->settings_base . 'podmotor_disconnect' );
	}

	public function render_seriously_simple_extensions() {
		add_thickbox();
		$image_dir  = $this->assets_url . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;
		$extensions = array(
			'connect'     => array(
				'title'       => 'NEW - Castos Podcast Hosting',
				'image'       => $image_dir . 'castos-icon-extension.jpg',
				'url'         => MPP_PODMOTOR_APP_URL,
				'description' => 'Host your podcast media files safely and securely in a CDN-powered cloud platform designed specifically to connect beautifully with Media Portfolios Podcasting.  Faster downloads, better live streaming, and take back security for your web server with Castos.',
				'new_window'  => true,
			),
			'stats'       => array(
				'title'       => 'Media Portfolios Podcasting Stats',
				'image'       => $image_dir . 'ssp-stats.jpg',
				'url'         => add_query_arg( array( 'tab' => 'plugin-information', 'plugin' => 'seriously-simple-stats', 'TB_iframe' => 'true', 'width' => '772', 'height' => '859' ), admin_url( 'plugin-install.php' ) ),
				'description' => 'Seriously Simple Stats offers integrated analytics for your podcast, giving you access to incredibly useful information about who is listening to your podcast and how they are accessing it.',
			),
			'transcripts' => array(
				'title'       => 'Media Portfolios Podcasting Transcripts',
				'image'       => $image_dir . 'ssp-transcripts.jpg',
				'url'         => add_query_arg( array( 'tab' => 'plugin-information', 'plugin' => 'seriously-simple-transcripts', 'TB_iframe' => 'true', 'width' => '772', 'height' => '859' ), admin_url( 'plugin-install.php' ) ),
				'description' => 'Seriously Simple Transcripts gives you a simple and automated way for you to add downloadable transcripts to your podcast episodes. It’s an easy way for you to provide episode transcripts to your listeners without taking up valuable space in your episode content.',
			),
			'speakers'    => array(
				'title'       => 'Media Portfolios Podcasting Speakers',
				'image'       => $image_dir . 'ssp-speakers.jpg',
				'url'         => add_query_arg( array( 'tab' => 'plugin-information', 'plugin' => 'seriously-simple-speakers', 'TB_iframe' => 'true', 'width' => '772', 'height' => '859' ), admin_url( 'plugin-install.php' ) ),
				'description' => 'Does your podcast have a number of different speakers? Or maybe a different guest each week? Perhaps you have unique hosts for each episode? If any of those options describe your podcast then Seriously Simple Speakers is the add-on for you!',
			),
			'genesis'     => array(
				'title'       => 'Media Portfolios Podcasting Genesis Support ',
				'image'       => $image_dir . 'ssp-genesis.jpg',
				'url'         => add_query_arg( array( 'tab' => 'plugin-information', 'plugin' => 'seriously-simple-podcasting-genesis-support', 'TB_iframe' => 'true', 'width' => '772', 'height' => '859' ), admin_url( 'plugin-install.php' ) ),
				'description' => 'The Genesis compatibility add-on for Media Portfolios Podcasting gives you full support for the Genesis theme framework. It adds support to the podcast post type for the features that Genesis requires. If you are using Genesis and Media Portfolios Podcasting together then this plugin will make your website look and work much more smoothly.',
			),
		);

		$html = '<div id="ssp-extensions">';
		foreach ( $extensions as $extension ) {
			$html .= '<div class="ssp-extension"><h3 class="ssp-extension-title">' . $extension['title'] . '</h3>';
			if (isset($extension['new_window']) && $extension['new_window']){
				$html .= '<a href="' . $extension['url'] . '" title="' . $extension['title'] . '" target="_blank"><img width="880" height="440" src="' . $extension['image'] . '" class="attachment-showcase size-showcase wp-post-image" alt="" title="' . $extension['title'] . '"></a>';
			}else {
				$html .= '<a href="' . $extension['url'] . '" title="' . $extension['title'] . '" class="thickbox"><img width="880" height="440" src="' . $extension['image'] . '" class="attachment-showcase size-showcase wp-post-image" alt="" title="' . $extension['title'] . '"></a>';
			}
			$html .= '<p></p>';
			$html .= '<p>' . $extension['description'] . '</p>';
			$html .= '<p></p>';
			if (isset($extension['new_window']) && $extension['new_window']){
				$html .= '<a href="' . $extension['url'] . '" title="' . $extension['title'] . '" target="_blank" class="button-secondary">Get this Extension</a>';
			}else {
				$html .= '<a href="' . $extension['url'] . '" title="' . $extension['title'] . '" class="thickbox button-secondary">Get this Extension</a>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		return $html;
	}
}
