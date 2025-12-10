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

    // Section principale
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

    // Section Paywall
    add_settings_section(
        'viewpay_wordpress_paywall_section',
        __('Configuration du Paywall', 'viewpay-wordpress'),
        'viewpay_wordpress_paywall_section_callback',
        'viewpay-wordpress'
    );

    add_settings_field(
        'paywall_type',
        __('Type de paywall', 'viewpay-wordpress'),
        'viewpay_wordpress_paywall_type_render',
        'viewpay-wordpress',
        'viewpay_wordpress_paywall_section'
    );

    add_settings_field(
        'custom_paywall_selector',
        __('Sélecteur CSS du paywall', 'viewpay-wordpress'),
        'viewpay_wordpress_custom_paywall_selector_render',
        'viewpay-wordpress',
        'viewpay_wordpress_paywall_section'
    );

    add_settings_field(
        'custom_button_location',
        __('Emplacement du bouton', 'viewpay-wordpress'),
        'viewpay_wordpress_custom_button_location_render',
        'viewpay-wordpress',
        'viewpay_wordpress_paywall_section'
    );

    // Section Apparence
    add_settings_section(
        'viewpay_wordpress_appearance_section',
        __('Apparence', 'viewpay-wordpress'),
        'viewpay_wordpress_appearance_section_callback',
        'viewpay-wordpress'
    );

    add_settings_field(
        'button_text',
        __('Texte du bouton', 'viewpay-wordpress'),
        'viewpay_wordpress_button_text_render',
        'viewpay-wordpress',
        'viewpay_wordpress_appearance_section'
    );

    add_settings_field(
        'cookie_duration',
        __('Durée d\'accès après publicité', 'viewpay-wordpress'),
        'viewpay_wordpress_cookie_duration_render',
        'viewpay-wordpress',
        'viewpay_wordpress_appearance_section'
    );

    add_settings_field(
        'use_custom_color',
        __('Personnaliser la couleur', 'viewpay-wordpress'),
        'viewpay_wordpress_use_custom_color_render',
        'viewpay-wordpress',
        'viewpay_wordpress_appearance_section'
    );

    add_settings_field(
        'button_color',
        __('Couleur du bouton', 'viewpay-wordpress'),
        'viewpay_wordpress_button_color_render',
        'viewpay-wordpress',
        'viewpay_wordpress_appearance_section'
    );

    // Section Avancé
    add_settings_section(
        'viewpay_wordpress_advanced_section',
        __('Paramètres avancés', 'viewpay-wordpress'),
        'viewpay_wordpress_advanced_section_callback',
        'viewpay-wordpress'
    );

    add_settings_field(
        'enable_debug_logs',
        __('Logs de débogage', 'viewpay-wordpress'),
        'viewpay_wordpress_enable_debug_logs_render',
        'viewpay-wordpress',
        'viewpay_wordpress_advanced_section'
    );
}
add_action('admin_init', 'viewpay_wordpress_settings_init');

/**
 * Settings section description callback
 */
function viewpay_wordpress_settings_section_callback() {
    echo '<p>' . __('Configurez ici les paramètres principaux de ViewPay.', 'viewpay-wordpress') . '</p>';
}

/**
 * Paywall section description callback
 */
function viewpay_wordpress_paywall_section_callback() {
    echo '<p>' . __('Sélectionnez le plugin de paywall que vous utilisez sur votre site.', 'viewpay-wordpress') . '</p>';
}

/**
 * Appearance section description callback
 */
function viewpay_wordpress_appearance_section_callback() {
    echo '<p>' . __('Personnalisez l\'apparence du bouton ViewPay.', 'viewpay-wordpress') . '</p>';
}

/**
 * Advanced section description callback
 */
function viewpay_wordpress_advanced_section_callback() {
    echo '<p>' . __('Options avancées pour le débogage et le développement.', 'viewpay-wordpress') . '</p>';
}

/**
 * Site ID field render callback
 */
function viewpay_wordpress_site_id_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <input type='text' name='viewpay_wordpress_options[site_id]' value='<?php echo esc_attr($options['site_id']); ?>' class="regular-text">
    <p class="description">
        <?php _e('Pour récupérer votre ID, veuillez contacter ViewPay à l\'adresse suivante: <a href="mailto:publishers@viewpay.tv">publishers@viewpay.tv</a>.<br>Par défaut, vous pouvez utiliser l\'ID suivant pour vos tests : <code>b23d3f0235ae89e4</code>', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Paywall type field render callback
 */
function viewpay_wordpress_paywall_type_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $paywall_type = isset($options['paywall_type']) ? $options['paywall_type'] : 'auto';

    // Liste des paywalls supportés avec leur statut de détection
    $paywalls = array(
        'auto' => array(
            'label' => __('Détection automatique (non recommandé)', 'viewpay-wordpress'),
            'detected' => false,
            'description' => __('Le plugin essaiera de détecter automatiquement le paywall utilisé.', 'viewpay-wordpress')
        ),
        'pms' => array(
            'label' => 'Paid Member Subscriptions (Cozmoslabs)',
            'detected' => function_exists('pms_is_member') || class_exists('Paid_Member_Subscriptions'),
            'description' => ''
        ),
        'pmpro' => array(
            'label' => 'Paid Memberships Pro',
            'detected' => function_exists('pmpro_has_membership_access'),
            'description' => ''
        ),
        'rcp' => array(
            'label' => 'Restrict Content Pro',
            'detected' => class_exists('RCP_Member') || class_exists('RCP_Requirements_Check'),
            'description' => ''
        ),
        'swpm' => array(
            'label' => 'Simple Membership',
            'detected' => class_exists('SwpmMembershipLevel') || class_exists('SwpmProtectContent'),
            'description' => ''
        ),
        'wpmem' => array(
            'label' => 'WP-Members',
            'detected' => function_exists('wpmem_is_blocked'),
            'description' => ''
        ),
        'rua' => array(
            'label' => 'Restrict User Access',
            'detected' => class_exists('RUA_App'),
            'description' => ''
        ),
        'um' => array(
            'label' => 'Ultimate Member',
            'detected' => class_exists('UM'),
            'description' => ''
        ),
        'custom' => array(
            'label' => __('Paywall personnalisé / Custom', 'viewpay-wordpress'),
            'detected' => true,
            'description' => __('Configurez manuellement les sélecteurs CSS pour votre paywall.', 'viewpay-wordpress')
        ),
    );
    ?>
    <select name='viewpay_wordpress_options[paywall_type]' id='viewpay-paywall-type' class="regular-text">
        <?php foreach ($paywalls as $key => $paywall): ?>
            <?php
            $detected_text = '';
            if ($key !== 'auto' && $key !== 'custom') {
                $detected_text = $paywall['detected'] ? ' ✓ ' . __('(détecté)', 'viewpay-wordpress') : ' ✗ ' . __('(non détecté)', 'viewpay-wordpress');
            }
            ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($paywall_type, $key); ?>>
                <?php echo esc_html($paywall['label'] . $detected_text); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description">
        <?php _e('<strong>Important :</strong> Sélectionnez le plugin de paywall que vous utilisez. Si votre paywall n\'est pas dans la liste, choisissez "Paywall personnalisé".', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Custom paywall selector field render callback
 */
function viewpay_wordpress_custom_paywall_selector_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $selector = isset($options['custom_paywall_selector']) ? $options['custom_paywall_selector'] : '';
    $paywall_type = isset($options['paywall_type']) ? $options['paywall_type'] : 'auto';
    $disabled = ($paywall_type !== 'custom') ? 'disabled' : '';
    ?>
    <input type='text'
           name='viewpay_wordpress_options[custom_paywall_selector]'
           id='viewpay-custom-paywall-selector'
           value='<?php echo esc_attr($selector); ?>'
           class="regular-text"
           placeholder=".paywall-message, .restricted-content"
           <?php echo $disabled; ?>>
    <p class="description" id="custom-paywall-selector-desc">
        <?php _e('Sélecteur CSS de l\'élément qui contient le message de restriction du paywall.<br>Exemples : <code>.paywall-message</code>, <code>#premium-content-cta</code>, <code>.restricted-notice</code>', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Custom button location field render callback
 */
function viewpay_wordpress_custom_button_location_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $location = isset($options['custom_button_location']) ? $options['custom_button_location'] : 'after';
    $paywall_type = isset($options['paywall_type']) ? $options['paywall_type'] : 'auto';
    $disabled = ($paywall_type !== 'custom') ? 'disabled' : '';
    ?>
    <select name='viewpay_wordpress_options[custom_button_location]'
            id='viewpay-custom-button-location'
            class="regular-text"
            <?php echo $disabled; ?>>
        <option value="before" <?php selected($location, 'before'); ?>><?php _e('Avant le paywall', 'viewpay-wordpress'); ?></option>
        <option value="after" <?php selected($location, 'after'); ?>><?php _e('Après le paywall', 'viewpay-wordpress'); ?></option>
        <option value="inside_start" <?php selected($location, 'inside_start'); ?>><?php _e('Au début du paywall (à l\'intérieur)', 'viewpay-wordpress'); ?></option>
        <option value="inside_end" <?php selected($location, 'inside_end'); ?>><?php _e('À la fin du paywall (à l\'intérieur)', 'viewpay-wordpress'); ?></option>
        <option value="replace" <?php selected($location, 'replace'); ?>><?php _e('Remplacer le contenu du paywall', 'viewpay-wordpress'); ?></option>
    </select>
    <p class="description" id="custom-button-location-desc">
        <?php _e('Où placer le bouton ViewPay par rapport à l\'élément du paywall.', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Button text field render callback
 */
function viewpay_wordpress_button_text_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <input type='text' name='viewpay_wordpress_options[button_text]' value='<?php echo esc_attr($options['button_text']); ?>' class="regular-text">
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
    $duration_value = isset($options['cookie_duration']) ? $options['cookie_duration'] : 15;
    ?>
    <select name='viewpay_wordpress_options[cookie_duration]' id='viewpay-cookie-duration' class="regular-text">
        <option value="5" <?php selected($duration_value, 5); ?>>5 minutes</option>
        <option value="10" <?php selected($duration_value, 10); ?>>10 minutes</option>
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
        <?php _e('Durée pendant laquelle l\'utilisateur aura accès au contenu premium après avoir regardé une publicité.<br><strong>Recommandation :</strong> 15 minutes offrent un bon équilibre entre expérience utilisateur et revenus publicitaires.', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Use custom color checkbox render callback
 */
function viewpay_wordpress_use_custom_color_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $checked = isset($options['use_custom_color']) && $options['use_custom_color'] === 'yes' ? 'checked' : '';
    ?>
    <label>
        <input type="checkbox" name="viewpay_wordpress_options[use_custom_color]" value="yes" <?php echo $checked; ?> id="viewpay-use-custom-color">
        <?php _e('Activer la personnalisation de couleur du bouton', 'viewpay-wordpress'); ?>
    </label>
    <p class="description">
        <?php _e('Par défaut, le bouton ViewPay adopte le style du plugin de paywall utilisé.', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Button color picker render callback
 */
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

/**
 * Enable debug logs checkbox render callback
 */
function viewpay_wordpress_enable_debug_logs_render() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    $checked = isset($options['enable_debug_logs']) && $options['enable_debug_logs'] === 'yes' ? 'checked' : '';
    ?>
    <label>
        <input type="checkbox" name="viewpay_wordpress_options[enable_debug_logs]" value="yes" <?php echo $checked; ?> id="viewpay-enable-debug-logs">
        <?php _e('Activer les logs de débogage dans la console du navigateur', 'viewpay-wordpress'); ?>
    </label>
    <p class="description">
        <?php _e('Active l\'affichage des messages de débogage ViewPay dans la console JavaScript du navigateur.<br><strong>Désactivez cette option en production.</strong>', 'viewpay-wordpress'); ?>
    </p>
    <?php
}

/**
 * Settings page render callback
 */
function viewpay_wordpress_options_page() {
    $options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
    ?>
    <div class="wrap">
        <h1><?php _e('ViewPay WordPress', 'viewpay-wordpress'); ?></h1>

        <div class="viewpay-admin-container" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
            <div class="viewpay-logo-container" style="text-align: center; margin-bottom: 20px;">
                <img src="<?php echo VIEWPAY_WORDPRESS_PLUGIN_URL; ?>assets/img/viewpay-logo.png" alt="ViewPay Logo" style="max-width: 200px;filter:invert(80%);">
            </div>

            <p style="text-align: center; margin-bottom: 30px;">
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
    </div>

    <style>
        .form-table th {
            width: 250px;
        }
        #viewpay-custom-paywall-selector:disabled,
        #viewpay-custom-button-location:disabled {
            background-color: #f0f0f0;
            cursor: not-allowed;
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // Toggle custom fields based on paywall type selection
        function toggleCustomFields() {
            var paywallType = $('#viewpay-paywall-type').val();
            var isCustom = (paywallType === 'custom');

            $('#viewpay-custom-paywall-selector').prop('disabled', !isCustom);
            $('#viewpay-custom-button-location').prop('disabled', !isCustom);

            if (isCustom) {
                $('#viewpay-custom-paywall-selector').closest('tr').show();
                $('#viewpay-custom-button-location').closest('tr').show();
            } else {
                // Optionally hide the rows for non-custom
                // $('#viewpay-custom-paywall-selector').closest('tr').hide();
                // $('#viewpay-custom-button-location').closest('tr').hide();
            }
        }

        // Initial state
        toggleCustomFields();

        // On change
        $('#viewpay-paywall-type').on('change', toggleCustomFields);

        // Toggle color picker based on checkbox
        $('#viewpay-use-custom-color').on('change', function() {
            $('#viewpay-button-color').prop('disabled', !$(this).is(':checked'));
        });
    });
    </script>
    <?php
}
