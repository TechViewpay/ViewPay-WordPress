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

// Initialiser le plugin
function viewpay_wordpress_init() {
    // Vérifier si PMPro est actif
    if (!function_exists('pmpro_has_membership_access')) {
        add_action('admin_notices', 'viewpay_wordpress_admin_notice');
        return;
    }
    
    // Initialiser le plugin
    $viewpay = new ViewPay_WordPress();
    $viewpay->init();
}
add_action('plugins_loaded', 'viewpay_wordpress_init');

// Message d'erreur si PMPro n'est pas actif
function viewpay_wordpress_admin_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('ViewPay WordPress nécessite que le plugin Paid Memberships Pro soit installé et activé.', 'viewpay-wordpress'); ?></p>
    </div>
    <?php
}

// Ajouter un script de débogage simple dans le footer
function viewpay_wordpress_footer_debug() {
    ?>
    <script type="text/javascript">
    console.log('ViewPay WordPress Debug: Plugin loaded');
    </script>
    <?php
}
add_action('wp_footer', 'viewpay_wordpress_footer_debug', 99);
