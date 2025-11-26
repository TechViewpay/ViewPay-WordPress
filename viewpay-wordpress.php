<?php
/**
 * Plugin Name: ViewPay WordPress
 * Plugin URI: https://viewpay.tv/
 * Description: Intègre la solution ViewPay dans le paywall WordPress de votre choix.
 * Version: 1.2.4
 * Author: ViewPay
 * Author URI: https://viewpay.tv/
 * Text Domain: viewpay-wordpress
 * Domain Path: /languages
 */

// Si ce fichier est appelé directement, on sort.
if (!defined('WPINC')) {
    die;
}

// Définir les constantes
define('VIEWPAY_WORDPRESS_VERSION', '1.2.4');
define('VIEWPAY_WORDPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIEWPAY_WORDPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Charger les fichiers nécessaires
require_once VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/viewpay-wordpress-class.php';
require_once VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/viewpay-admin.php';

// Options par défaut
function viewpay_wordpress_default_options() {
    return array(
        'site_id' => 'b23d3f0235ae89e4', // ID de démo par défaut
	'button_text' => 'Débloquer en regardant une publicité',
	'cookie_duration' => 15, // Durée en minutes (15 minutes par défaut)
	'button_color' => '',
	'use_custom_color' => 'no', //option pour activer/desactiver la personnalisation
        'enable_debug_logs' => 'no', // option pour activer/désactiver les logs de debug
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
        'swmp' => 'class_exists("SwpmMembershipLevel")',
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
   
    // Check for custom paywall implementations
    if (viewpay_wordpress_has_custom_paywall()) {
        return true;
    }
 
    return false;
}

/**
 * Check if site has a custom paywall implementation
 *
 * @return bool True if custom paywall detected
 */
function viewpay_wordpress_has_custom_paywall() {
    $site_url = get_site_url();
    $site_name = get_bloginfo('name');
    
    // Check for known custom paywall implementations
    $custom_paywall_sites = array(
        'pyreneesmagazine' => array(
	      'url_indicators' => array('testwp.viewpay.tv', 'pyreneesmagazine.com', 'pyrenees.ouzom.fr'),
	      //'url_indicators' => array('pyreneesmagazine'),
 //           'name_indicators' => array('pyrenees', 'pyrénées'),
              'css_indicators' => array('premium-content-cta')
        )
    );
    
    foreach ($custom_paywall_sites as $site => $indicators) {
        // Check URL indicators
        foreach ($indicators['url_indicators'] as $url_indicator) {
            if (strpos(strtolower($site_url), $url_indicator) !== false) {    
		return true;		
	    }
        }
/*        
        // Check site name indicators
        foreach ($indicators['name_indicators'] as $name_indicator) {
            if (strpos(strtolower($site_name), $name_indicator) !== false) {
                return true;
            }
	}
 */
    }  
    return false;
}

function viewpay_wordpress_validate_options($input) {
    $defaults = viewpay_wordpress_default_options();
    $output = array();
    
    // Valider et sanitiser chaque option
    $output['site_id'] = sanitize_text_field($input['site_id']);
    
    // Si le champ est vide, utiliser la valeur par défaut
    if (empty($input['button_text'])) {
        $output['button_text'] = $defaults['button_text'];
    } else {
        $output['button_text'] = sanitize_text_field($input['button_text']);
    }
    
    // Durée du cookie - valider que c'est un nombre entier positif
    if (isset($input['cookie_duration']) && is_numeric($input['cookie_duration'])) {
        $duration = (int) $input['cookie_duration'];
        $allowed_durations = array(5, 10, 15, 30, 60, 120, 240, 480, 720, 1440);
        if (in_array($duration, $allowed_durations)) {
            $output['cookie_duration'] = $duration;
        } else {
            $output['cookie_duration'] = $defaults['cookie_duration'];
            add_settings_error(
                'viewpay_wordpress_options',
                'cookie_duration_invalid',
                __('Durée de cookie invalide. Valeur par défaut utilisée.', 'viewpay-wordpress'),
                'error'
            );
        }
    } else {
        $output['cookie_duration'] = $defaults['cookie_duration'];
    }
    
    // Option d'activation de la personnalisation de couleur
    $output['use_custom_color'] = isset($input['use_custom_color']) && $input['use_custom_color'] === 'yes' ? 'yes' : 'no';
    
    // Couleur du bouton
    $output['button_color'] = isset($input['button_color']) ? sanitize_hex_color($input['button_color']) : '';
    
    // Option d'activation des logs de debug
    $output['enable_debug_logs'] = isset($input['enable_debug_logs']) && $input['enable_debug_logs'] === 'yes' ? 'yes' : 'no';
    
    return $output;
}

// Message d'erreur si aucun plugin compatible n'est actif
function viewpay_wordpress_admin_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('ViewPay WordPress nécessite qu\'un des plugins suivants soit installé et activé : Paid Memberships Pro, Simple Membership, WP-Members, Restrict User Access, Ultimate Member ou Restrict Content Pro.', 'viewpay-wordpress'); ?></p>
    </div>
    <?php
}
