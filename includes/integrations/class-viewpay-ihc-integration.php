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
 * IHC (slug `indeed-membership-pro`, préfixe CSS `ihc-`) existe en deux éditions
 * au code très différent : la version communautaire (v2.x) et « Indeed Ultimate
 * Membership Pro » (v9.x), cette dernière étant payante et non publiée sur
 * wordpress.org. On couvre les deux via trois mécanismes complémentaires :
 *
 *  - `the_content` priorité 998 : injection du bouton ViewPay dans le wrapper
 *    `.ihc-locker-wrap` (repère HTML stable observé en production).
 *  - `the_content` priorité 999 : restitution du contenu original quand le
 *    cookie ViewPay est valide, en retirant temporairement toute callback IHC
 *    (fonction préfixée `ihc_` ou méthode d'une classe dont le nom contient
 *    `ihc`/`indeed`) pour rester compatible entre versions.
 *  - Hook direct sur les filtres d'autorisation IHC (`ihc_user_has_access` et
 *    variantes) : défense en profondeur, laisse IHC lui-même décider de ne pas
 *    masquer quand ViewPay a débloqué le post. Filtre absent = silencieux.
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

        // Hook direct sur les filtres d'autorisation IHC (plus fiable que de
        // démonter `the_content` a posteriori). Les noms varient selon les
        // versions / forks ("Indeed Membership Pro" vs "Indeed Ultimate
        // Membership Pro"), on s'accroche aux variantes connues. Un filtre
        // inexistant est silencieusement ignoré par WordPress.
        $access_filters = array(
            'ihc_user_has_access',
            'ihc_check_access',
            'ihc_access_check',
            'indeed_user_has_access',
            'indeed_membership_has_access',
        );
        foreach ($access_filters as $filter_name) {
            add_filter($filter_name, array($this, 'grant_access_if_unlocked'), 99, 3);
        }
    }

    /**
     * Force l'accès autorisé quand ViewPay a débloqué le post courant.
     *
     * Branché sur plusieurs filtres IHC potentiels (noms variables selon
     * versions). Signature volontairement tolérante : les filtres IHC passent
     * selon les cas `($has_access)`, `($has_access, $user_id)` ou
     * `($has_access, $user_id, $post_id)`. On lit le post_id quand il est
     * fourni, sinon on retombe sur `get_the_ID()`.
     *
     * @param mixed    $has_access Valeur courante (true/false)
     * @param int|null $user_id    Utilisateur cible (optionnel)
     * @param int|null $post_id    Post cible (optionnel)
     * @return mixed true si ViewPay a débloqué, valeur originale sinon
     */
    public function grant_access_if_unlocked($has_access, $user_id = null, $post_id = null) {
        if (empty($post_id)) {
            $post_id = get_the_ID();
        }

        if (!$post_id) {
            return $has_access;
        }

        if ($this->main->is_post_unlocked($post_id)) {
            return true;
        }

        return $has_access;
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
        // On couvre deux formes :
        //  - fonctions globales préfixées `ihc_` (IHC communautaire)
        //  - méthodes de classe dont le nom de classe contient `ihc`/`indeed`
        //    (IHC « Ultimate » v9.x où les hooks sont passés à des instances).
        $removed_callbacks = array();
        if (isset($wp_filter['the_content']) && !empty($wp_filter['the_content']->callbacks)) {
            foreach ($wp_filter['the_content']->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $key => $callback) {
                    $fn = $callback['function'];

                    $is_ihc_callback = false;
                    if (is_string($fn) && strpos($fn, 'ihc_') === 0) {
                        $is_ihc_callback = true;
                    } elseif (is_array($fn) && isset($fn[0])) {
                        $class_name = is_object($fn[0]) ? get_class($fn[0]) : (is_string($fn[0]) ? $fn[0] : '');
                        $class_lc = strtolower($class_name);
                        if ($class_lc !== '' && (strpos($class_lc, 'ihc') !== false || strpos($class_lc, 'indeed') !== false)) {
                            $is_ihc_callback = true;
                        }
                    }

                    if ($is_ihc_callback) {
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
