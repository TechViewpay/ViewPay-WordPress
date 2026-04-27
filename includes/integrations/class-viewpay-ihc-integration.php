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
 *  - Hook direct sur deux filtres extensibles d'IHC Ultimate (noms vérifiés
 *    contre le code source v12.x) : `ihc_filter_restriction` (court-circuite
 *    la restriction dans `public/init.php`) et `filter_on_ihc_test_if_must_block`
 *    (force `$block = 0` dans `public/functions.php`). Quand ViewPay a
 *    débloqué un post, IHC s'écarte de lui-même — plus besoin de bricoler
 *    `the_content`.
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
        // démonter `the_content` a posteriori). Noms et signatures vérifiés
        // contre le code source IHC Ultimate (v12.x, public/init.php et
        // public/functions.php) — couvre v9.x à v12.x.
        //
        // `ihc_filter_restriction` : retourner false court-circuite `return;`
        // dans init.php → IHC ne pose aucune restriction, le contenu original
        // s'affiche tel quel.
        add_filter('ihc_filter_restriction', array($this, 'disable_restriction_if_unlocked'), 99, 2);

        // `filter_on_ihc_test_if_must_block` : retourner 0 (SHOW) quand
        // ViewPay a débloqué. Filtre appelé dans plusieurs branches de
        // ihc_test_if_must_block().
        add_filter('filter_on_ihc_test_if_must_block', array($this, 'force_show_if_unlocked'), 99, 6);
    }

    /**
     * Filtre `ihc_filter_restriction` : désactive la restriction IHC sur un
     * post déjà débloqué par ViewPay (signature à 2 arguments validée contre
     * `public/init.php`).
     *
     * @param mixed $restriction_on Valeur courante (truthy = restriction active)
     * @param int   $post_id        Post évalué par IHC
     * @return mixed false si ViewPay a débloqué, valeur originale sinon
     */
    public function disable_restriction_if_unlocked($restriction_on, $post_id) {
        if (!empty($post_id) && $this->main->is_post_unlocked($post_id)) {
            return false;
        }
        return $restriction_on;
    }

    /**
     * Filtre `filter_on_ihc_test_if_must_block` : force `$block = 0` (SHOW)
     * quand ViewPay a débloqué (signature à 6 arguments validée contre
     * `public/functions.php`).
     *
     * @param int   $block          0 = SHOW, 1 = BLOCK (sémantique IHC)
     * @param mixed $block_or_show  Mode d'évaluation IHC (non utilisé)
     * @param mixed $user_levels    Niveaux de l'utilisateur (non utilisé)
     * @param mixed $target_levels  Niveaux requis par le post (non utilisé)
     * @param int   $post_id        Post évalué
     * @param mixed $used_location  Emplacement (non utilisé)
     * @return int 0 si ViewPay a débloqué, valeur originale sinon
     */
    public function force_show_if_unlocked($block, $block_or_show, $user_levels, $target_levels, $post_id, $used_location) {
        if (!empty($post_id) && $this->main->is_post_unlocked($post_id)) {
            return 0;
        }
        return $block;
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
