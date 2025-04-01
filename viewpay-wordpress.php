<?php
/**
 * Plugin Name: ViewPay WordPress
 * Plugin URI: https://viewpay.tv/
 * Description: Intègre la solution ViewPay dans le paywall WordPress de votre choix.
 * Version: 1.0.0
 * Author: Philippe Rocton
 * Author URI: https://viewpay.tv/
 * Text Domain: viewpay-wordpress
 * Domain Path: /languages
 */

// Si ce fichier est appelé directement, on sort.
if (!defined('WPINC')) {
    die;
}

// Définir les constantes
define('VIEWPAY_WORDPRESS_VERSION', '1.0.0');
define('VIEWPAY_WORDPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIEWPAY_WORDPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Charger les fichiers nécessaires
require_once VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/viewpay-wordpress-class.php';

// Options par défaut
function viewpay_wordpress_default_options() {
    return array(
        'site_id' => 'b23d3f0235ae89e4', // ID de démo par défaut
        'button_text' => 'Débloquer en regardant une publicité',
    );
}

// Initialiser le plugin
function viewpay_wordpress_init() {
    // Vérifier si un plugin compatible est actif
    if (!viewpay_wordpress_is_compatible_plugin_active()) {
        add_action('admin_notices', 'viewpay_wordpress_admin_notice');
        return;
    }
    
    // Initialiser le plugin
    $viewpay = new ViewPay_WordPress();
    $viewpay->init();
}
add_action('plugins_loaded', 'viewpay_wordpress_init');

// Vérifier si un plugin compatible est actif
function viewpay_wordpress_is_compatible_plugin_active() {
    // Liste des plugins compatibles et leurs fonctions/classes de vérification
    $compatible_plugins = array(
        'pmpro' => 'function_exists("pmpro_has_membership_access")',
        'swpm' => 'class_exists("SwpmMembershipLevel")',
        'wp_members' => 'function_exists("wpmem_is_blocked")',
        'rua' => 'class_exists("RUA_App")',
	'um' => 'class_exists("UM")',
	'rcp' => 'class_exists("RCP_Member")',
    );
    
    foreach ($compatible_plugins as $plugin => $check) {
        if (eval("return $check;")) {
            return true;
        }
    }
    
    return false;
}

// Message d'erreur si aucun plugin compatible n'est actif
function viewpay_wordpress_admin_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('ViewPay WordPress nécessite qu\'un des plugins suivants soit installé et activé : Paid Memberships Pro, Simple Membership, WP-Members, Restrict User Access, Ultimate Member ou Restrict Content Pro.', 'viewpay-wordpress'); ?></p>
    </div>
    <?php
}

// Ajouter un menu d'options dans l'admin
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

// Enregistrer les paramètres
function viewpay_wordpress_settings_init() {
    register_setting('viewpay_settings', 'viewpay_wordpress_options');
    
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
    
    add_settings_field(
        'button_color',
        __('Couleur du bouton', 'viewpay-wordpress'),
        'viewpay_wordpress_button_color_render',
        'viewpay-wordpress',
        'viewpay_wordpress_settings_section'
    );
}
add_action('admin_init', 'viewpay_wordpress_settings_init');

// Fonctions de rendu pour les champs de paramètres
function viewpay_wordpress_site_id_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <input type='text' name='viewpay_wordpress_options[site_id]' value='<?php echo esc_attr($options['site_id']); ?>'>
    <p class="description"><?php _e('Entrez l\'ID de site fourni par ViewPay.', 'viewpay-wordpress'); ?></p>
    <?php
}

function viewpay_wordpress_button_text_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <input type='text' name='viewpay_wordpress_options[button_text]' value='<?php echo esc_attr($options['button_text']); ?>'>
    <?php
}

function viewpay_wordpress_button_color_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <input type='color' name='viewpay_wordpress_options[button_color]' value='<?php echo esc_attr($options['button_color']); ?>'>
    <?php
}

function viewpay_wordpress_settings_section_callback() {
    echo __('Configurez ici les paramètres de ViewPay pour votre site WordPress.', 'viewpay-wordpress');
}

// Page d'options
function viewpay_wordpress_options_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('viewpay_settings');
            do_settings_sections('viewpay-wordpress');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
