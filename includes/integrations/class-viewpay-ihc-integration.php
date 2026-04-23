<?php
/**
 * ViewPay Integration with Indeed Membership Pro (IHC)
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe d'intégration de ViewPay avec Indeed Membership Pro
 *
 * Contrairement à PMPro, SWPM ou RCP, IHC (slug `indeed-membership-pro`, préfixe
 * CSS `ihc-`) est un plugin payant qui n'est pas publié sur wordpress.org.
 * L'inspection de la version communautaire v2.6 a confirmé l'absence de filtre
 * public stable équivalent à `pmpro_has_membership_access_filter` ou
 * `swpm_member_can_access_content` : IHC construit son « locker » directement
 * via `the_content` (priorité par défaut, callback dont le nom varie selon les
 * versions) en s'appuyant sur une post meta de remplacement.
 *
 * On reproduit donc la stratégie PMPro/SWPM mais en s'accrochant uniquement à
 * `the_content` :
 *  - priorité 998 : injection du bouton ViewPay dans le wrapper `.ihc-locker-wrap`
 *    (repère HTML stable observé en production chez lecorrespondant.net).
 *  - priorité 999 : restitution du contenu original quand le cookie ViewPay est
 *    valide, en retirant temporairement toute callback dont le nom commence par
 *    `ihc_` de la chaîne `the_content` (on ignore volontairement le nom exact
 *    pour rester compatible entre versions d'IHC).
 */
class ViewPay_IHC_Integration {

    /**
     * Reference to the main plugin class
     */
    private $main;

    /**
     * Constructor
     *
     * @param ViewPay_WordPress $main_instance Main plugin instance
     */
    public function __construct($main_instance) {
        $this->main = $main_instance;
        $this->init();
    }

    /**
     * Initialize the integration
     */
    public function init() {
        // Injection du bouton ViewPay dans le locker HTML rendu par IHC.
        // Priorité 998 : après IHC (priorité 10 par défaut), avant ensure_content_access.
        add_filter('the_content', array($this, 'add_viewpay_button_to_locker'), 998);

        // Restitution du contenu original quand le post est débloqué via ViewPay.
        add_filter('the_content', array($this, 'ensure_content_access'), 999);
    }

    /**
     * Injecte le bouton ViewPay à l'intérieur du locker IHC (`.ihc-locker-wrap`).
     *
     * @param string $content Contenu filtré (potentiellement remplacé par le locker IHC)
     * @return string Contenu éventuellement enrichi du bouton ViewPay
     */
    public function add_viewpay_button_to_locker($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        // Pas de bouton si l'utilisateur a déjà débloqué ce post.
        if ($this->main->is_post_unlocked($post->ID)) {
            return $content;
        }

        // Détection du locker IHC : on cherche la classe `ihc-locker-wrap` (wrapper
        // du message de restriction) ou `ihc_hide_part` (zone cachée).
        if (strpos($content, 'ihc-locker-wrap') === false
            && strpos($content, 'ihc_hide_part') === false) {
            return $content;
        }

        $button_html = $this->build_button_html($post->ID);

        // Tentative d'injection du bouton juste avant la balise fermante
        // du `<div class="ihc-locker-wrap ...">`.
        $pattern = '/(<div[^>]*class=["\'][^"\']*ihc-locker-wrap[^"\']*["\'][^>]*>)(.*?)(<\/div>)/is';
        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, '$1$2' . $button_html . '$3', $content, 1);
        }

        // Fallback : si la structure HTML est différente (versions IHC variables),
        // on ajoute simplement le bouton à la fin du contenu filtré.
        return $content . $button_html;
    }

    /**
     * Restitue le contenu original quand le post est débloqué via ViewPay.
     *
     * On ignore le nom exact de la callback IHC sur `the_content` (il change
     * selon les versions), donc on ne peut pas faire un `remove_filter` nominatif
     * comme le font les intégrations PMPro/SWPM. On scanne dynamiquement
     * `$wp_filter['the_content']` et on retire toute callback dont le nom commence
     * par `ihc_` le temps de réappliquer les filtres sur le contenu original.
     *
     * @param string $content Contenu filtré
     * @return string Contenu original filtré si déverrouillé, contenu d'origine sinon
     */
    public function ensure_content_access($content) {
        global $post, $wp_filter;

        if (!$post) {
            return $content;
        }

        if (!$this->main->is_post_unlocked($post->ID)) {
            return $content;
        }

        // On ne réapplique les filtres que si le locker est effectivement encore
        // présent (évite un double traitement inutile).
        if (strpos($content, 'ihc-locker-wrap') === false
            && strpos($content, 'ihc_hide_part') === false) {
            return $content;
        }

        error_log('ViewPay: Force IHC content display for post ' . $post->ID);

        // Sauvegarde + retrait temporaire des callbacks IHC sur `the_content`.
        $removed_callbacks = array();
        if (isset($wp_filter['the_content']) && !empty($wp_filter['the_content']->callbacks)) {
            foreach ($wp_filter['the_content']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $key => $callback) {
                    $fn = $callback['function'];
                    if (is_string($fn) && strpos($fn, 'ihc_') === 0) {
                        $removed_callbacks[] = array(
                            'function' => $fn,
                            'priority' => $priority,
                            'accepted_args' => $callback['accepted_args'],
                        );
                        remove_filter('the_content', $fn, $priority);
                    }
                }
            }
        }

        // On retire aussi temporairement notre propre hook d'injection du bouton
        // pour éviter que le contenu original (qui ne contient pas le locker) ne
        // soit malencontreusement ciblé par notre regex.
        remove_filter('the_content', array($this, 'add_viewpay_button_to_locker'), 998);
        remove_filter('the_content', array($this, 'ensure_content_access'), 999);

        $original_content = get_post_field('post_content', $post->ID);
        $filtered_content = apply_filters('the_content', $original_content);

        // Restauration de nos propres hooks.
        add_filter('the_content', array($this, 'add_viewpay_button_to_locker'), 998);
        add_filter('the_content', array($this, 'ensure_content_access'), 999);

        // Restauration des callbacks IHC.
        foreach ($removed_callbacks as $cb) {
            add_filter('the_content', $cb['function'], $cb['priority'], $cb['accepted_args']);
        }

        return $filtered_content;
    }

    /**
     * Construit le HTML du bouton ViewPay pour IHC.
     *
     * @param int $post_id Identifiant du post
     * @return string HTML du bouton
     */
    private function build_button_html($post_id) {
        $nonce = wp_create_nonce('viewpay_nonce');
        $button_text = $this->main->get_option('button_text');

        $html  = '<div class="viewpay-container">';
        $html .= '<button id="viewpay-button" class="viewpay-button" ';
        $html .= 'data-post-id="' . esc_attr($post_id) . '" ';
        $html .= 'data-nonce="' . esc_attr($nonce) . '">';
        $html .= esc_html($button_text);
        $html .= '</button>';
        $html .= '</div>';

        return $html;
    }
}
