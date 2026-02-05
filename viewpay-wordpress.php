<?php
/**
 * Plugin Name: ViewPay WordPress
 * Plugin URI: https://viewpay.tv/
 * Description: Intègre la solution ViewPay dans le paywall WordPress de votre choix.
 * Version: 1.5.7
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
define('VIEWPAY_WORDPRESS_VERSION', '1.5.7');
define('VIEWPAY_WORDPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VIEWPAY_WORDPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Charger les fichiers nécessaires
require_once VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/viewpay-wordpress-class.php';
require_once VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/viewpay-admin.php';

// Options par défaut
function viewpay_wordpress_default_options() {
    return array(
        'site_id' => 'b23d3f0235ae89e4', // ID de démo par défaut
        'paywall_type' => 'pms', // Type de paywall sélectionné (PMS par défaut)
        'custom_paywall_selector' => '', // Sélecteur CSS pour paywall custom
        'custom_button_location' => 'after', // Emplacement du bouton pour paywall custom
        'button_text' => 'Débloquer en regardant une publicité',
        'cookie_duration' => 15, // Durée en minutes (15 minutes par défaut)
        'button_color' => '',
        'use_custom_color' => 'no', // Option pour activer/désactiver la personnalisation
        'enable_debug_logs' => 'no', // Option pour activer/désactiver les logs de debug
        'tsa_desktop_offset' => -86, // Position desktop pour TSA (décalage en px)
        'tsa_mobile_bottom' => 60, // Position mobile pour TSA (bottom en px)
    );
}

// Initialiser le plugin
function viewpay_wordpress_init() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $paywall_type = isset($options['paywall_type']) ? $options['paywall_type'] : 'pms';

    // Si mode custom, toujours initialiser
    if ($paywall_type === 'custom') {
        $viewpay = new ViewPay_WordPress();
        $viewpay->init();
        return;
    }

    // Vérifier que le paywall sélectionné est bien actif
    if (viewpay_wordpress_is_paywall_active($paywall_type)) {
        $viewpay = new ViewPay_WordPress();
        $viewpay->init();
    } else {
        // Le paywall sélectionné n'est pas actif, afficher un avertissement
        add_action('admin_notices', 'viewpay_wordpress_paywall_not_found_notice');
    }
}
add_action('plugins_loaded', 'viewpay_wordpress_init');

/**
 * Vérifie si un type de paywall spécifique est actif
 *
 * @param string $paywall_type Le type de paywall à vérifier
 * @return bool True si le paywall est actif
 */
function viewpay_wordpress_is_paywall_active($paywall_type) {
    $checks = array(
        'pms' => function_exists('pms_is_member') || class_exists('Paid_Member_Subscriptions'),
        'pmpro' => function_exists('pmpro_has_membership_access'),
        'rcp' => class_exists('RCP_Member') || class_exists('RCP_Requirements_Check'),
        'swpm' => class_exists('SwpmMembershipLevel') || class_exists('SwpmProtectContent'),
        'wpmem' => function_exists('wpmem_is_blocked'),
        'rua' => class_exists('RUA_App'),
        'um' => class_exists('UM'),
        'swg' => true, // Subscribe with Google - pas de dépendance plugin
        'rrm' => class_exists('Google\\Site_Kit\\Modules\\Reader_Revenue_Manager') || defined('GOOGLESITEKIT_VERSION'),
        'tsa' => true, // TSA Algérie - intégration custom spécifique
        'pymag' => true, // Pyrénées Magazine - intégration custom spécifique
        'custom' => true,
    );

    return isset($checks[$paywall_type]) ? $checks[$paywall_type] : false;
}

// Vérifier si un plugin compatible est actif (mode auto)
function viewpay_wordpress_is_compatible_plugin_active() {
    $paywall_types = array('pms', 'pmpro', 'rcp', 'swpm', 'wpmem', 'rua', 'um');

    foreach ($paywall_types as $type) {
        if (viewpay_wordpress_is_paywall_active($type)) {
            return true;
        }
    }

    return false;
}

/**
 * Validation et sanitisation des options
 */
function viewpay_wordpress_validate_options($input) {
    $defaults = viewpay_wordpress_default_options();
    $output = array();

    // Site ID
    $output['site_id'] = sanitize_text_field($input['site_id']);

    // Type de paywall
    $allowed_paywalls = array('pms', 'pmpro', 'rcp', 'swpm', 'wpmem', 'rua', 'um', 'swg', 'rrm', 'tsa', 'pymag', 'custom');
    if (isset($input['paywall_type']) && in_array($input['paywall_type'], $allowed_paywalls)) {
        $output['paywall_type'] = $input['paywall_type'];
    } else {
        $output['paywall_type'] = $defaults['paywall_type'];
    }

    // Sélecteur CSS custom
    $output['custom_paywall_selector'] = isset($input['custom_paywall_selector'])
        ? sanitize_text_field($input['custom_paywall_selector'])
        : '';

    // Emplacement du bouton custom
    $allowed_locations = array('before', 'after', 'inside_start', 'inside_end', 'replace');
    if (isset($input['custom_button_location']) && in_array($input['custom_button_location'], $allowed_locations)) {
        $output['custom_button_location'] = $input['custom_button_location'];
    } else {
        $output['custom_button_location'] = $defaults['custom_button_location'];
    }

    // Texte du bouton
    if (empty($input['button_text'])) {
        $output['button_text'] = $defaults['button_text'];
    } else {
        $output['button_text'] = sanitize_text_field($input['button_text']);
    }

    // Durée du cookie
    if (isset($input['cookie_duration']) && is_numeric($input['cookie_duration'])) {
        $duration = (int) $input['cookie_duration'];
        $allowed_durations = array(5, 10, 15, 30, 60, 120, 240, 480, 720, 1440);
        if (in_array($duration, $allowed_durations)) {
            $output['cookie_duration'] = $duration;
        } else {
            $output['cookie_duration'] = $defaults['cookie_duration'];
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

    // Position TSA desktop (décalage en px, valeur négative attendue)
    if (isset($input['tsa_desktop_offset']) && is_numeric($input['tsa_desktop_offset'])) {
        $output['tsa_desktop_offset'] = (int) $input['tsa_desktop_offset'];
    } else {
        $output['tsa_desktop_offset'] = $defaults['tsa_desktop_offset'];
    }

    // Position TSA mobile (bottom en px, valeur positive attendue)
    if (isset($input['tsa_mobile_bottom']) && is_numeric($input['tsa_mobile_bottom'])) {
        $output['tsa_mobile_bottom'] = (int) $input['tsa_mobile_bottom'];
    } else {
        $output['tsa_mobile_bottom'] = $defaults['tsa_mobile_bottom'];
    }

    return $output;
}

// Message d'erreur si aucun plugin compatible n'est actif (mode auto)
function viewpay_wordpress_admin_notice() {
    ?>
    <div class="notice notice-error">
        <p>
            <strong><?php _e('ViewPay WordPress', 'viewpay-wordpress'); ?></strong>:
            <?php _e('Aucun plugin de paywall compatible n\'a été détecté. Veuillez configurer le type de paywall dans les', 'viewpay-wordpress'); ?>
            <a href="<?php echo admin_url('options-general.php?page=viewpay-wordpress'); ?>"><?php _e('paramètres ViewPay', 'viewpay-wordpress'); ?></a>.
        </p>
    </div>
    <?php
}

// Message d'erreur si le paywall sélectionné n'est pas actif
function viewpay_wordpress_paywall_not_found_notice() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $paywall_type = isset($options['paywall_type']) ? $options['paywall_type'] : 'pms';
    ?>
    <div class="notice notice-warning">
        <p>
            <strong><?php _e('ViewPay WordPress', 'viewpay-wordpress'); ?></strong>:
            <?php printf(__('Le plugin de paywall sélectionné (%s) n\'est pas actif. Veuillez vérifier vos', 'viewpay-wordpress'), '<code>' . esc_html($paywall_type) . '</code>'); ?>
            <a href="<?php echo admin_url('options-general.php?page=viewpay-wordpress'); ?>"><?php _e('paramètres ViewPay', 'viewpay-wordpress'); ?></a>.
        </p>
    </div>
    <?php
}
