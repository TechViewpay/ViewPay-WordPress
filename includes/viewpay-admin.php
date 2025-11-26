<?php
/**
 * Admin functionality for ViewPay WordPress plugin
 * 
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds a settings link to the plugin action links
 *
 * @param array $links Existing action links
 * @param string $file Plugin file
 * @return array Modified action links
 */
function viewpay_wordpress_add_settings_link($links, $file) {
    if (plugin_basename(VIEWPAY_WORDPRESS_PLUGIN_DIR . 'viewpay-wordpress.php') === $file) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=viewpay-wordpress') . '">' 
            . __('Paramètres', 'viewpay-wordpress') . '</a>';
        array_unshift($links, $settings_link);
    }
    return $links;
}
add_filter('plugin_action_links', 'viewpay_wordpress_add_settings_link', 10, 2);

/**
 * Adds the ViewPay settings page to the WordPress Settings menu
 */
function viewpay_wordpress_add_admin_menu() {
    add_options_page(
        __('ViewPay WordPress', 'viewpay-wordpress'),
        __('ViewPay WordPress', 'viewpay-wordpress'),
        'manage_options',
        'viewpay-wordpress',
        'viewpay_wordpress_options_page'
    );
}
add_action('admin_menu', 'viewpay_wordpress_add_admin_menu');

/**
 * Registers settings, sections and fields
 */
function viewpay_wordpress_settings_init() {
    register_setting(
 	    'viewpay_settings', 
	    'viewpay_wordpress_options', 
	    array(
    		    'sanitize_callback' => 'viewpay_wordpress_validate_options'
	    )
    );	
//	register_setting('viewpay_settings', 'viewpay_wordpress_options');
    
    add_settings_section(
        'viewpay_wordpress_settings_section',
        __('Paramètres ViewPay', 'viewpay-wordpress'),
        'viewpay_wordpress_settings_section_callback',
        'viewpay-wordpress'
    );
    
    add_settings_field(
        'site_id',
        __('ID de site ViewPay', 'viewpay-wordpress'),
        'viewpay_wordpress_site_id_render',
        'viewpay-wordpress',
        'viewpay_wordpress_settings_section'
    );
    
    add_settings_field(
        'button_text',
        __('Texte du bouton', 'viewpay-wordpress'),
        'viewpay_wordpress_button_text_render',
        'viewpay-wordpress',
        'viewpay_wordpress_settings_section'
    );

    // Ajouter le champ pour la durée de vie du cookie
    add_settings_field(
        'cookie_duration',
        __('Durée d\'accès après publicité', 'viewpay-wordpress'),
        'viewpay_wordpress_cookie_duration_render',
        'viewpay-wordpress',
        'viewpay_wordpress_settings_section'
    );

    // Ajouter un champ pour activer/désactiver la personnalisation de couleur
	add_settings_field(
	    'use_custom_color',
	    __('Personnaliser la couleur', 'viewpay-wordpress'),
	    'viewpay_wordpress_use_custom_color_render',
	    'viewpay-wordpress',
	    'viewpay_wordpress_settings_section'
	);

	// Ajouter le champ de couleur après l'option d'activation
	add_settings_field(
	    'button_color',
	    __('Couleur du bouton', 'viewpay-wordpress'),
	    'viewpay_wordpress_button_color_render',
	    'viewpay-wordpress',
	    'viewpay_wordpress_settings_section'
	);

    // Ajouter le champ pour activer/désactiver les logs de debug
    add_settings_field(
        'enable_debug_logs',
        __('Logs de débogage', 'viewpay-wordpress'),
        'viewpay_wordpress_enable_debug_logs_render',
        'viewpay-wordpress',
        'viewpay_wordpress_settings_section'
    );
}
add_action('admin_init', 'viewpay_wordpress_settings_init');

/**
 * Settings section description callback
 */
function viewpay_wordpress_settings_section_callback() {
    echo __('Configurez ici les paramètres de ViewPay pour votre site WordPress.', 'viewpay-wordpress');
}

/**
 * Site ID field render callback
 */
function viewpay_wordpress_site_id_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <input type='text' name='viewpay_wordpress_options[site_id]' value='<?php echo esc_attr($options['site_id']); ?>'>
    <p class="description">
        <?php _e('Pour récupérer votre ID, veuillez contacter ViewPay à l\'adresse suivante: <a href="mailto:publishers@viewpay.tv">publishers@viewpay.tv</a>. Par défaut, vous pouvez utiliser l\'ID suivant pour vos tests : b23d3f0235ae89e4', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Button text field render callback
 */
function viewpay_wordpress_button_text_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <input type='text' name='viewpay_wordpress_options[button_text]' value='<?php echo esc_attr($options['button_text']); ?>'>
    <p class="description">
        <?php _e('Texte à afficher sur le bouton ViewPay.', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Cookie duration field render callback
 */
function viewpay_wordpress_cookie_duration_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $duration_value = isset($options['cookie_duration']) ? $options['cookie_duration'] : 30;
    ?>
    <select name='viewpay_wordpress_options[cookie_duration]' id='viewpay-cookie-duration' style="min-width: 200px;">
        <option value="5" <?php selected($duration_value, 15); ?>>5 minutes</option>
        <option value="10" <?php selected($duration_value, 15); ?>>10 minutes</option>
        <option value="15" <?php selected($duration_value, 15); ?>>15 minutes (recommandé)</option>
        <option value="30" <?php selected($duration_value, 30); ?>>30 minutes</option>
        <option value="60" <?php selected($duration_value, 60); ?>>1 heure</option>
        <option value="120" <?php selected($duration_value, 120); ?>>2 heures</option>
        <option value="240" <?php selected($duration_value, 240); ?>>4 heures</option>
        <option value="480" <?php selected($duration_value, 480); ?>>8 heures</option>
        <option value="720" <?php selected($duration_value, 720); ?>>12 heures</option>
        <option value="1440" <?php selected($duration_value, 1440); ?>>24 heures</option>
    </select>
    <p class="description">
        <?php _e('Durée pendant laquelle l\'utilisateur aura accès au contenu premium après avoir regardé une publicité en cas de changement de page. Passé ce délai, l\'utilisateur devra regarder une nouvelle publicité pour accéder à nouveau au contenu. <br><strong>Recommandation :</strong> 15 minutes offrent un bon équilibre entre expérience utilisateur et revenus publicitaires. (NB: tant qu\'il ne quitte pas la page, il a encore accès à l\'article.)', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

// Fonction de rendu pour l'option d'activation de la personnalisation
function viewpay_wordpress_use_custom_color_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $checked = isset($options['use_custom_color']) && $options['use_custom_color'] === 'yes' ? 'checked' : '';
    ?>
    <label>
        <input type="checkbox" name="viewpay_wordpress_options[use_custom_color]" value="yes" <?php echo $checked; ?> id="viewpay-use-custom-color">
        <?php _e('Activer la personnalisation de couleur du bouton', 'viewpay-wordpress'); ?>
    </label>
    <p class="description">
        <?php _e('Par défaut, le bouton ViewPay adopte le style du plugin de paywall utilisé. Activez cette option uniquement si vous souhaitez définir une couleur personnalisée.', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

// Fonction de rendu pour le color picker
function viewpay_wordpress_button_color_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $color_value = isset($options['button_color']) && !empty($options['button_color']) ? $options['button_color'] : '#3498db';
    $is_enabled = isset($options['use_custom_color']) && $options['use_custom_color'] === 'yes';
    $disabled = $is_enabled ? '' : 'disabled="disabled"';
    ?>
    <div id="viewpay-color-picker-container">
        <input type="text" name="viewpay_wordpress_options[button_color]" id="viewpay-button-color" value="<?php echo esc_attr($color_value); ?>" class="viewpay-color-field" data-default-color="#3498db" <?php echo $disabled; ?> />
        <p class="description">
            <?php _e('Sélectionnez la couleur personnalisée pour le bouton ViewPay.', 'viewpay-wordpress'); ?>
        </p>
    </div>
    <?php
}

// Fonction de rendu pour l'option d'activation des logs de debug
function viewpay_wordpress_enable_debug_logs_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $checked = isset($options['enable_debug_logs']) && $options['enable_debug_logs'] === 'yes' ? 'checked' : '';
    ?>
    <label>
        <input type="checkbox" name="viewpay_wordpress_options[enable_debug_logs]" value="yes" <?php echo $checked; ?> id="viewpay-enable-debug-logs">
        <?php _e('Activer les logs de débogage dans la console du navigateur', 'viewpay-wordpress'); ?>
    </label>
    <p class="description">
        <?php _e('Active l\'affichage des messages de débogage ViewPay dans la console JavaScript du navigateur. Utile pour diagnostiquer les problèmes d\'intégration. <strong>Désactivez cette option en production.</strong>', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Settings page render callback with improved layout
 */
function viewpay_wordpress_options_page() {
    // Obtain current options
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <div class="wrap">
        <h1><?php _e('ViewPay WordPress', 'viewpay-wordpress'); ?></h1>
        
        <div style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
        <div class="viewpay-logo-container" style="text-align: center; margin-bottom: 20px;">
            <img src="<?php echo VIEWPAY_WORDPRESS_PLUGIN_URL; ?>assets/img/viewpay-logo.png" alt="ViewPay Logo" style="max-width: 200px;filter:invert(80%);">
        </div>
	<h2><?php _e('Configuration du plugin ViewPay WordPress', 'viewpay-wordpress'); ?></h2>
            <p>
                <?php _e('ViewPay est une solution de micro-paiement par l\'attention publicitaire, qui permet à l\'utilisateur de débloquer un contenu premium en regardant une publicité.', 'viewpay-wordpress'); ?>
            </p>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('viewpay_settings');
                do_settings_sections('viewpay-wordpress');
                submit_button(__('Enregistrer les paramètres', 'viewpay-wordpress'));
                ?>
            </form>
        </div>
        
        <?php if (viewpay_wordpress_is_compatible_plugin_active()): ?>
        <div style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h2><?php _e('Plugins de paywall détectés', 'viewpay-wordpress'); ?></h2>
            <p>
                <?php _e('Les plugins suivants sont compatibles avec ViewPay et ont été détectés sur votre site:', 'viewpay-wordpress'); ?>
            </p>
            
            <ul style="margin-left: 20px; list-style-type: disc;">
                <?php if (function_exists('pmpro_has_membership_access')): ?>
                <li><?php _e('Paid Memberships Pro', 'viewpay-wordpress'); ?></li>
                <?php endif; ?>
                
                <?php if (class_exists('SwpmMembershipLevel')): ?>
                <li><?php _e('Simple Membership', 'viewpay-wordpress'); ?></li>
                <?php endif; ?>
                
                <?php if (function_exists('wpmem_is_blocked')): ?>
                <li><?php _e('WP-Members', 'viewpay-wordpress'); ?></li>
                <?php endif; ?>
                
                <?php if (class_exists('RUA_App')): ?>
                <li><?php _e('Restrict User Access', 'viewpay-wordpress'); ?></li>
                <?php endif; ?>
                
                <?php if (class_exists('UM')): ?>
                <li><?php _e('Ultimate Member', 'viewpay-wordpress'); ?></li>
                <?php endif; ?>
                
                <?php if (class_exists('RCP_Member') || class_exists('RCP_Requirements_Check')): ?>
                <li><?php _e('Restrict Content Pro', 'viewpay-wordpress'); ?></li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
