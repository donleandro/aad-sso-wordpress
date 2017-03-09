<?php

/**
 * The class holding all the logic for the 'Azure AD' settings page used to configure the plugin.
 *
 * Partially generated by the WordPress Option Page generator
 * at http://jeremyhixon.com/wp-tools/option-page/
 */
class AADSSO_Settings_Page {

	/**
	 * The stored settings (with defaults, if the setting isn't stored).
	 */
	private $settings;

	/**
	 * The option page's hook_suffix returned from add_options_page
	 */
	private $options_page_id;

	public function __construct() {

		// Ensure jQuery is loaded.
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_include_jquery' ) );

		// Add the 'Azure AD' options page.
		add_action( 'admin_menu', array( $this, 'add_options_page' ) );

		// Register the settings.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Reset settings if requested to.
		add_action( 'admin_init', array( $this, 'maybe_reset_settings' ) );

		// Migrate settings if requested to.
		add_action( 'admin_init', array( $this, 'maybe_migrate_settings' ) );

		// If settings were reset, show confirmation.
		add_action( 'all_admin_notices', array( $this, 'notify_if_reset_successful' ) );

		// If settings were migrated, show confirmation
		add_action( 'all_admin_notices', array( $this, 'notify_json_migrate_status' ) );

		// Remove query arguments from the REQUEST_URI (leaves $_GET untouched).  Resolves issue #58
		$_SERVER['REQUEST_URI'] = remove_query_arg( 'aadsso_reset', $_SERVER['REQUEST_URI'] );
		$_SERVER['REQUEST_URI'] = remove_query_arg( 'aadsso_migrate_from_json_status', $_SERVER['REQUEST_URI'] );

		// Load stored configuration values, with defaults if there's nothing set.
		$default_settings = AADSSO_Settings::get_defaults();
		$this->settings = get_option( 'aadsso_settings', $default_settings );
		foreach ( $default_settings as $key => $default_value ) {
			if ( ! isset( $this->settings[ $key ] ) ) {
				$this->settings[ $key ] = $default_value;
			}
		}
	}

	/**
	 * Clears settings if $_GET['aadsso_nonce'] is set and if the nonce is valid.
	 */
	public function maybe_reset_settings() {
		$should_reset_settings = isset( $_GET['aadsso_nonce'] ) && wp_verify_nonce( $_GET['aadsso_nonce'], 'aadsso_reset_settings' );
		if ( $should_reset_settings ) {
			delete_option( 'aadsso_settings' );
			wp_safe_redirect( admin_url( 'options-general.php?page=aadsso_settings&aadsso_reset=success' ) );
		}
	}

	/**
	 * Migrates old settings (Settings.json) to the database and attempts to delete the old settings file.
	 */
	public function maybe_migrate_settings() {
		/**
		 * Settings should only be migrated if
		 * - The request came from a nonced link
		 * - The nonce action is 'aadsso_migrate_settings'
		 * - There is a legacy settings path defined
		 * - There is a file at that legacy settings path
		 */
		$should_migrate_settings = isset( $_GET['aadsso_nonce'] )
			&& wp_verify_nonce( $_GET['aadsso_nonce'], 'aadsso_migrate_from_json' )
			&& defined( 'AADSSO_SETTINGS_PATH' )
			&& file_exists( AADSSO_SETTINGS_PATH );

		if ( $should_migrate_settings ) {

			$legacy_settings = json_decode( file_get_contents( AADSSO_SETTINGS_PATH ), true );

			if ( null === $legacy_settings ) {
				wp_safe_redirect( admin_url( 'options-general.php?page=aadsso_settings&aadsso_migrate_from_json_status=invalid_json') );
			}

			// If aad_group_to_wp_role_map is set in the legacy settings, build the inverted role_map array,
			// which is what is ultimately saved in the database.
			if ( isset( $legacy_settings['aad_group_to_wp_role_map'] ) ) {
				$legacy_settings['role_map'] = array();
				foreach ($aad_group_to_wp_role_map as $group_id => $role_slug ) {
					if ( ! isset( $legacy_settings['role_map'][$role_slug] ) ) {
						$legacy_settings['role_map'][$role_slug] = $group_id;
					} else {
						$legacy_settings['role_map'][$role_slug] .= ',' . $group_id;
					}
				}
			}

			$sanitized_settings = $this->sanitize_settings( $legacy_settings );

			update_option( 'aadsso_settings', $sanitized_settings );

			if ( is_writable( AADSSO_SETTINGS_PATH ) && is_writable( dirname( AADSSO_SETTINGS_PATH ) ) && unlink( AADSSO_SETTINGS_PATH ) ) {
				wp_safe_redirect( admin_url( 'options-general.php?page=aadsso_settings&aadsso_migrate_from_json_status=success' ) );
			} else {
				wp_safe_redirect( admin_url( 'options-general.php?page=aadsso_settings&aadsso_migrate_from_json_status=manual' ) );
			}
		}
	}

	/**
	 * Shows messages about the state of the migration operation
	 */
	public function notify_json_migrate_status() {
		if( isset( $_GET['aadsso_migrate_from_json_status'] ) ) {
			if( 'success' === $_GET['aadsso_migrate_from_json_status'] ) {
				echo '<div id="message" class="notice notice-success"><p>'
					. __( 'Legacy settings have been migrated and the old configuration file has been deleted.', 'aad-sso-wordpress' )
					. __('To finish migration, unset <code>AADSSO_SETTINGS_PATH</code> from <code>wp-config.php</code>. ', 'aad-sso-wordpress')
					.'</p></div>';
			} elseif ( 'manual' === $_GET['aadsso_migrate_from_json_status'] ) {
				echo '<div id="message" class="notice notice-warning"><p>'
					. esc_html__( 'Legacy settings have been migrated successfully. ', 'aad-sso-wordpress' )
					. sprintf( __('To finish migration, delete the file at the path <code>%s</code>. ', 'aad-sso-wordpress'), AADSSO_SETTINGS_PATH )
					. sprintf( __('Then, unset <code>AADSSO_SETTINGS_PATH</code> from <code>wp-config.php</code>. ', 'aad-sso-wordpress') )
					.'</p></div>';
			} elseif( 'invalid_json' === $_GET['aadsso_migrate_from_json_status'] ) {
				echo '<div id="message" class="notice notice-error"><p>'
					. sprintf( __('Legacy settings could not be migrated from <code>%s</code>. ', 'aad-sso-wordpress'), AADSSO_SETTINGS_PATH )
					. esc_html( 'File could not be parsed as JSON. ', 'aad-sso-wordpress' )
					. esc_html( 'Delete the file, or check its syntax.', 'aad-sso-wordpress' )
					.'</p></div>';
			}
		}
	}

	/**
	 * Notifies user if settings reset was successful.
	 */
	public function notify_if_reset_successful()
	{
		if ( isset( $_GET['aadsso_reset'] ) && 'success' === $_GET['aadsso_reset'] ) {
			echo '<div id="message" class="notice notice-warning"><p>'
				. __( 'Single Sign-on with Azure Active Directory settings have been reset to default.',
					'aad-sso-wordpress' )
				.'</p></div>';
		}
	}

	/**
	 * Adds the 'Azure AD' options page.
	 */
	public function add_options_page() {
		$this->options_page_id = add_options_page(
			__( 'Azure Active Directory Settings', 'aad-sso-wordpress' ), // page_title
			'Azure AD', // menu_title
			'manage_options', // capability
			'aadsso_settings', // menu_slug
			array( $this, 'render_admin_page' ) // function
		);
	}

	/**
	 * Renders the 'Azure AD' settings page.
	 */
	public function render_admin_page() {
		require_once( 'view/settings.php' );
	}

	/**
	 * Registers settings, sections and fields.
	 */
	public function register_settings() {

		register_setting(
			'aadsso_settings', // option_group
			'aadsso_settings', // option_name
			array( $this, 'sanitize_settings' ) // sanitize_callback
		);

		add_settings_section(
			'aadsso_settings_general', // id
			__( 'General', 'aad-sso-wordpress' ), // title
			array( $this, 'settings_general_info' ), // callback
			'aadsso_settings_page' // page
		);

		add_settings_section(
			'aadsso_settings_advanced', // id
			__( 'Advanced', 'aad-sso-wordpress' ), // title
			array( $this, 'settings_advanced_info' ), // callback
			'aadsso_settings_page' // page
		);

		add_settings_field(
			'org_display_name', // id
			__( 'Display name', 'aad-sso-wordpress' ), // title
			array( $this, 'org_display_name_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'org_domain_hint', // id
			__( 'Domain hint', 'aad-sso-wordpress' ), // title
			array( $this, 'org_domain_hint_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'client_id', // id
			__( 'Client ID', 'aad-sso-wordpress' ), // title
			array( $this, 'client_id_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'client_secret', // id
			__( 'Client secret', 'aad-sso-wordpress' ), // title
			array( $this, 'client_secret_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'redirect_uri', // id
			__( 'Redirect URL', 'aad-sso-wordpress' ), // title
			array( $this, 'redirect_uri_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'logout_redirect_uri', // id
			__( 'Logout redirect URL', 'aad-sso-wordpress' ), // title
			array( $this, 'logout_redirect_uri_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'field_to_match_to_upn', // id
			__( 'Field to match to UPN', 'aad-sso-wordpress' ), // title
			array( $this, 'field_to_match_to_upn_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'match_on_upn_alias', // id
			__( 'Match on alias of the UPN', 'aad-sso-wordpress' ), // title
			array( $this, 'match_on_upn_alias_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'enable_auto_provisioning', // id
			__( 'Enable auto-provisioning', 'aad-sso-wordpress' ), // title
			array( $this, 'enable_auto_provisioning_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'enable_auto_forward_to_aad', // id
			__( 'Enable auto-forward to Azure AD', 'aad-sso-wordpress' ), // title
			array( $this, 'enable_auto_forward_to_aad_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'enable_aad_group_to_wp_role', // id
			__( 'Enable Azure AD group to WP role association', 'aad-sso-wordpress' ), // title
			array( $this, 'enable_aad_group_to_wp_role_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'default_wp_role', // id
			__( 'Default WordPress role if not in Azure AD group', 'aad-sso-wordpress' ), // title
			array( $this, 'default_wp_role_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'role_map', // id
			__( 'WordPress role to Azure AD group map', 'aad-sso-wordpress' ), // title
			array( $this, 'role_map_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_general' // section
		);

		add_settings_field(
			'openid_configuration_endpoint', // id
			__( 'OpenID Connect configuration endpoint', 'aad-sso-wordpress' ), // title
			array( $this, 'openid_configuration_endpoint_callback' ), // callback
			'aadsso_settings_page', // page
			'aadsso_settings_advanced' // section
		);
	}

	/**
	 * Gets the array of roles determined by other plugins to be "editable".
	 */
	function get_editable_roles() {

		global $wp_roles;

		$all_roles = $wp_roles->roles;
		$editable_roles = apply_filters( 'editable_roles', $all_roles );

		return $editable_roles;
	}

	/**
	 * Cleans and validates form information before saving.
	 *
	 * @param array $input key-value information to be cleaned before saving.
	 *
	 * @return array The sanitized and valid data to be stored.
	 */
	public function sanitize_settings( $input ) {

		$sanitary_values = array();

		$text_fields = array(
			'org_display_name',
			'org_domain_hint',
			'client_id',
			'client_secret',
			'redirect_uri',
			'logout_redirect_uri',
			'openid_configuration_endpoint',
		);

		foreach ($text_fields as $text_field) {
			if ( isset( $input[ $text_field ] ) ) {
				$sanitary_values[ $text_field ] = sanitize_text_field( $input[ $text_field ] );
			}
		}

		// Default field_to_match_to_upn is 'email'
		$sanitary_values['field_to_match_to_upn'] = 'email';
		if ( isset( $input['field_to_match_to_upn'] )
		      && in_array( $input['field_to_match_to_upn'], array( 'email', 'login' ) )
		) {
			$sanitary_values['field_to_match_to_upn'] = $input['field_to_match_to_upn'];
		}

		// Default role for user that is not member of any Azure AD group is null, which denies access.
		$sanitary_values['default_wp_role'] = null;
		if ( isset( $input['default_wp_role'] ) ) {
			$sanitary_values['default_wp_role'] = sanitize_text_field( $input['default_wp_role'] );
		}

		// Booleans: when key == value, this is considered true, otherwise false.
		$boolean_settings = array(
			'enable_auto_provisioning',
			'enable_auto_forward_to_aad',
			'enable_aad_group_to_wp_role',
			'match_on_upn_alias',
		);
		foreach ( $boolean_settings as $boolean_setting )
		{
			if( isset( $input[ $boolean_setting ] ) ) {
				$sanitary_values[ $boolean_setting ] = ( $boolean_setting == $input[ $boolean_setting ] );
			} else {
				$sanitary_values[ $boolean_setting ] = false;
			}
		}

		/*
		 * Many of the roles in WordPress will not have Azure AD groups associated with them.
		 * Go over all roles, removing the mapping for empty ones.
		 */
		if ( isset( $input['role_map'] ) ) {
			foreach( $input['role_map'] as $role_slug => $group_object_id ) {
				if( empty( $group_object_id ) ) {
					unset( $input['role_map'][ $role_slug ] );
				}
			}
			$sanitary_values['role_map'] = $input['role_map'];
		}

		// If the OpenID Connect configuration endpoint is changed, clear the cached values.
		$stored_oidc_config_endpoint = isset( $this->settings['openid_configuration_endpoint'] )
			? $this->settings['openid_configuration_endpoint'] : null;
		if ( $stored_oidc_config_endpoint !== $sanitary_values['openid_configuration_endpoint'] ) {
			delete_transient( 'aadsso_openid_configuration' );
			AADSSO::debug_log('Setting \'openid_configuration_endpoint\' changed, cleared cached OpenID Connect values.');
		}

		return $sanitary_values;
	}

	/**
	 * Renders details for the General settings section.
	 */
	public function settings_general_info() { }

	/**
	 * Renders details for the Advanced settings section.
	 */
	public function settings_advanced_info() { }

	/**
	 * Renders the `role_map` picker control.
	 */
	function role_map_callback() {
		printf( '<p>%s</p>',
			__( 'Map WordPress roles to Azure Active Directory groups.', 'aad-sso-wordpress' )
		);
		echo '<table>';
		printf(
			'<thead><tr><th>%s</th><th>%s</th></tr></thead>',
			__( 'WordPress Role', 'aad-sso-wordpress' ),
			__( 'Azure AD Group Object ID', 'aad-sso-wordpress' )
		);
		echo '<tbody>';
		foreach( $this->get_editable_roles( ) as $role_slug => $role ) {
			echo '<tr>';
				echo '<td>' . htmlentities( $role['name'] ) . '</td>';
				echo '<td>';
					printf(
						'<input type="text" class="regular-text" name="aadsso_settings[role_map][%1$s]" '
						 . 'id="role_map_%1$s" value="%2$s" />',
						$role_slug,
						isset( $this->settings['role_map'][ $role_slug ] )
							? esc_attr( $this->settings['role_map'][ $role_slug ] )
							: ''
					);
				echo '</td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Renders the `org_display_name` form control.
	 */
	public function org_display_name_callback()  {
		$this->render_text_field( 'org_display_name' );
		printf(
			'<p class="description">%s</p>',
			__( 'Display Name will be shown on the WordPress login screen.', 'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `org_domain_hint` form control.
	 */
	public function org_domain_hint_callback()  {
		$this->render_text_field( 'org_domain_hint' );
		printf(
			'<p class="description">%s</p>',
			__( 'Provides a hint to Azure AD about the domain or tenant they will be logging in to. If '
			     . 'the domain is federated, the user will be automatically redirected to federation '
			     . 'endpoint.', 'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `client_id` form control
	 */
	public function client_id_callback() {
		$this->render_text_field( 'client_id' );
		printf(
			'<p class="description">%s</p>',
			__( 'The client ID of the Azure AD application representing this blog.', 'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `client_secret` form control
	 **/
	public function client_secret_callback() {
		$this->render_text_field( 'client_secret' );
		printf(
			'<p class="description">%s</p>',
			__( 'A secret key for the Azure AD application representing this blog.', 'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `redirect_uri` form control
	 **/
	public function redirect_uri_callback() {
		$this->render_text_field( 'redirect_uri' );
		printf(
			' <a href="#" onclick="jQuery(\'#redirect_uri\').val(\'%s\'); return false;">%s</a>'
			. '<p class="description">%s</p>',
			wp_login_url(),
			__( 'Set default', 'aad-sso-wordpress' ),
			__( 'The URL where the user is redirected to after authenticating with Azure AD. '
			  . 'This URL must be registered in Azure AD as a valid redirect URL, and it must be a '
			  . 'page that invokes the "authenticate" filter. If you don\'t know what to set, leave '
			  . 'the default value (which is this blog\'s login page).', 'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `logout_redirect_uri` form control
	 **/
	public function logout_redirect_uri_callback() {
		$this->render_text_field( 'logout_redirect_uri' );
		printf(
			' <a href="#" onclick="jQuery(\'#logout_redirect_uri\').val(\'%s\'); return false;">%s</a>'
			. '<p class="description">%s</p>',
			wp_login_url(),
			__( 'Set default', 'aad-sso-wordpress'),
			__( 'The URL where the user is redirected to after signing out of Azure AD. '
			  . 'This URL must be registered in Azure AD as a valid redirect URL. (This does not affect '
			  . ' logging out of the blog, it is only used when logging out of Azure AD.)', 'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `field_to_match_to_upn` form control.
	 */
	public function field_to_match_to_upn_callback() {
		$selected =
		 isset( $this->settings['field_to_match_to_upn'] )
			? $this->settings['field_to_match_to_upn']
			: '';
		?>
		<select name="aadsso_settings[field_to_match_to_upn]" id="field_to_match_to_upn">
			<option value="email"<?php echo $selected == 'email' ? ' selected="selected"' : ''; ?>>
				<?php echo __( 'Email Address', 'aad-sso-wordpress' ); ?>
			</option>
			<option value="login"<?php echo $selected == 'login' ? ' selected="selected"' : ''; ?>>
				<?php echo __( 'Login Name', 'aad-sso-wordpress' ); ?>
			</option>
		</select>
		<?php
		printf(
			'<p class="description">%s</p>',
			__( 'This specifies the WordPress user field which will be used to match to the Azure AD user\'s '
			  . 'UserPrincipalName.', 'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `match_on_upn_alias` checkbox control.
	 */
	public function match_on_upn_alias_callback() {
		$this->render_checkbox_field(
			'match_on_upn_alias',
			__( 'Match WordPress users based on the alias of their Azure AD UserPrincipalName. For example, '
			  . 'Azure AD username <code>bob@example.com</code> will match WordPress user <code>bob</code>.',
			    'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `default_wp_role` control.
	 */
	public function default_wp_role_callback() {

		// Default configuration should be most-benign
		if( ! isset( $this->settings['default_wp_role'] ) ) {
			$this->settings['default_wp_role'] = '';
		}

		echo '<select name="aadsso_settings[default_wp_role]" id="default_wp_role">';
		printf( '<option value="%s">%s</option>', '', '(None, deny access)' );
		foreach( $this->get_editable_roles() as $role_slug => $role ) {
			$selected = $this->settings['default_wp_role'] === $role_slug ? ' selected="selected"' : '';
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $role_slug ), $selected, htmlentities( $role['name'] )
			);
		}
		echo '</select>';
		printf(
			'<p class="description">%s</p>',
			__('This is the default role that users will be assigned to if matching Azure AD group to '
			 . 'WordPress roles is enabled, but the signed in user isn\'t a member of any of the '
			 . 'configured Azure AD groups.', 'aad-sso-wordpress')
		);
	}

	/**
	 * Renders the `enable_auto_provisioning` checkbox control.
	 */
	public function enable_auto_provisioning_callback() {
		$this->render_checkbox_field(
			'enable_auto_provisioning',
			__( 'Automatically create WordPress users, if needed, for authenticated Azure AD users.',
				'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `enable_auto_forward_to_aad` checkbox control.
	 */
	public function enable_auto_forward_to_aad_callback() {
		$this->render_checkbox_field(
			'enable_auto_forward_to_aad',
			__( 'Automatically forward users to the Azure AD to sign in, skipping the WordPress login screen.',
				'aad-sso-wordpress')
		);
	}

	/**
	 * Renders the `enable_aad_group_to_wp_role` checkbox control.
	 */
	public function enable_aad_group_to_wp_role_callback() {
		$this->render_checkbox_field(
			'enable_aad_group_to_wp_role',
			__( 'Automatically assign WordPress user roles based on Azure AD group membership.',
				'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders the `openid_configuration_endpoint` form control
	 **/
	public function openid_configuration_endpoint_callback() {
		$this->render_text_field( 'openid_configuration_endpoint' );
		printf(
			' <a href="#" onclick="jQuery(\'#openid_configuration_endpoint\').val(\'%s\'); return false;">%s</a>'
			. '<p class="description">%s</p>',
			AADSSO_Settings::get_defaults( 'openid_configuration_endpoint' ),
			__( 'Set default', 'aad-sso-wordpress'),
			__( 'The OpenID Connect configuration endpoint to use. To support Microsoft Accounts and external '
			  . 'users (users invited in from other Azure AD directories, known sometimes as "B2B users") you '
			  . 'must use: <code>https://login.microsoftonline.com/{tenant-id}/.well-known/openid-configuration</code>, '
			  . 'where <code>{tenant-id}</code> is the tenant ID or a verified domain name of your directory.',
				'aad-sso-wordpress' )
		);
	}

	/**
	 * Renders a simple text field and populates it with the setting value.
	 *
	 * @param string $name The setting name for the text input field.
	 */
	public function render_text_field( $name ) {
		$value = isset( $this->settings[ $name ] ) ? esc_attr( $this->settings[ $name ] ) : '';
		printf(
			'<input class="regular-text" type="text" '
			 . 'name="aadsso_settings[%1$s]" id="%1$s" value="%2$s" />',
			$name, $value
		);
	}

	/**
	 * Renders a simple checkbox field and populates it with the setting value.
	 *
	 * @param string $name The setting name for the checkbox input field.
	 * @param string $label The label to use for the checkbox.
	 */
	public function render_checkbox_field( $name, $label ) {
		printf(
			'<input type="checkbox" name="aadsso_settings[%1$s]" id="%1$s" value="%1$s"%2$s />'
			 . '<label for="%1$s">%3$s</label>',
			$name,
			isset( $this->settings[ $name ] ) && $this->settings[ $name ] ? 'checked' : '',
			$label
		);
	}

	/**
	 * Indicates if user is currently on this settings page.
	 */
	public function is_on_options_page() {
		$screen = get_current_screen();
		return $screen->id === $this->options_page_id;
	}

	/**
	 * Ensures jQuery is loaded
	 */
	public function maybe_include_jquery() {
		if ( $this->is_on_options_page() ) {
			wp_enqueue_script( 'jquery' );
		}
	}
}
