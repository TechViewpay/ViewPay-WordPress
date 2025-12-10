<?php
/**
 * ViewPay Integration with Custom/Generic Paywalls
 *
 * This integration works with any paywall by using CSS selectors
 * configured in the admin settings.
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with custom paywalls
 */
class ViewPay_Custom_Integration {

    /**
     * Reference to the main plugin class
     */
    private $main;

    /**
     * Custom paywall CSS selector
     */
    private $paywall_selector;

    /**
     * Button location relative to paywall
     */
    private $button_location;

    /**
     * Constructor
     *
     * @param ViewPay_WordPress $main_instance Main plugin instance
     */
    public function __construct($main_instance) {
        $this->main = $main_instance;
        $this->paywall_selector = $this->main->get_option('custom_paywall_selector');
        $this->button_location = $this->main->get_option('custom_button_location') ?: 'after';

        $this->init();
    }

    /**
     * Initialize the integration
     */
    public function init() {
        // The custom integration primarily works via JavaScript
        // We add some PHP hooks for server-side support

        // Hook into content rendering
        add_filter('the_content', array($this, 'maybe_inject_button'), 100);

        // Handle content access for unlocked posts
        add_filter('the_content', array($this, 'maybe_unlock_content'), 5);

        // Add custom CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_custom_styles'));
    }

    /**
     * Maybe inject the ViewPay button into content
     *
     * This is a fallback for server-side injection.
     * The main injection happens via JavaScript for better compatibility.
     *
     * @param string $content The post content
     * @return string Modified content
     */
    public function maybe_inject_button($content) {
        global $post;

        if (!$post || !$this->paywall_selector) {
            return $content;
        }

        // Check if content contains the paywall selector (basic check)
        // Note: This is a simple check. JavaScript handles the actual injection.
        $selector_class = ltrim($this->paywall_selector, '.');
        $selector_id = ltrim($this->paywall_selector, '#');

        $has_paywall = (
            strpos($content, 'class="' . $selector_class) !== false ||
            strpos($content, "class='" . $selector_class) !== false ||
            strpos($content, 'id="' . $selector_id) !== false ||
            strpos($content, "id='" . $selector_id) !== false
        );

        if (!$has_paywall) {
            return $content;
        }

        // Generate the button HTML
        $nonce = wp_create_nonce('viewpay_nonce');
        $button_html = $this->create_button_html($post->ID, $nonce);

        // Try to inject the button based on location setting
        $modified_content = $this->inject_button_into_content($content, $button_html);

        return $modified_content;
    }

    /**
     * Create the ViewPay button HTML
     *
     * @param int $post_id Post ID
     * @param string $nonce Security nonce
     * @return string Button HTML
     */
    private function create_button_html($post_id, $nonce) {
        $button_text = $this->main->get_option('button_text');
        $or_text = $this->main->get_or_text();

        $html = '<div class="viewpay-custom-container">';
        $html .= '<div class="viewpay-separator">' . esc_html($or_text) . '</div>';
        $html .= '<button id="viewpay-button" class="viewpay-button" ';
        $html .= 'data-post-id="' . esc_attr($post_id) . '" ';
        $html .= 'data-nonce="' . esc_attr($nonce) . '">';
        $html .= '<span class="viewpay-icon"></span>';
        $html .= esc_html($button_text);
        $html .= '</button>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Inject button into content based on location setting
     *
     * @param string $content Original content
     * @param string $button_html Button HTML to inject
     * @return string Modified content
     */
    private function inject_button_into_content($content, $button_html) {
        if (!$this->paywall_selector) {
            return $content;
        }

        // This is a basic PHP-based injection
        // JavaScript handles more complex cases

        $selector = $this->paywall_selector;

        // Convert selector to regex pattern
        $selector_clean = preg_quote(ltrim($selector, '.#'), '/');
        $is_class = strpos($selector, '.') === 0;
        $is_id = strpos($selector, '#') === 0;

        if ($is_class) {
            $pattern = '/(<[^>]+class=["\'][^"\']*' . $selector_clean . '[^"\']*["\'][^>]*>)/i';
        } elseif ($is_id) {
            $pattern = '/(<[^>]+id=["\']' . $selector_clean . '["\'][^>]*>)/i';
        } else {
            // Generic selector, try both
            $pattern = '/(<[^>]+(?:class|id)=["\'][^"\']*' . $selector_clean . '[^"\']*["\'][^>]*>)/i';
        }

        switch ($this->button_location) {
            case 'before':
                $content = preg_replace($pattern, $button_html . '$1', $content, 1);
                break;

            case 'after':
                // Find the closing tag and insert after
                // This is simplified - JavaScript handles complex cases
                if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                    $position = $matches[0][1] + strlen($matches[0][0]);
                    // Find the closing div
                    $closing_pos = strpos($content, '</div>', $position);
                    if ($closing_pos !== false) {
                        $content = substr_replace($content, $button_html, $closing_pos + 6, 0);
                    }
                }
                break;

            case 'inside_start':
                $content = preg_replace($pattern, '$1' . $button_html, $content, 1);
                break;

            case 'inside_end':
                // Insert before the closing tag of the matched element
                // Simplified - JavaScript handles complex cases
                break;

            case 'replace':
                // Replace content inside the element
                // Simplified - JavaScript handles this better
                break;
        }

        return $content;
    }

    /**
     * Maybe unlock content if ViewPay cookie is set
     *
     * @param string $content The post content
     * @return string Potentially modified content
     */
    public function maybe_unlock_content($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        // Check if this post is unlocked via ViewPay
        if ($this->main->is_post_unlocked($post->ID)) {
            // Add a class to body for CSS targeting
            add_filter('body_class', function($classes) {
                $classes[] = 'viewpay-unlocked';
                return $classes;
            });

            // Add unlock notice
            $unlock_notice = '<div class="viewpay-unlock-notice">';
            $unlock_notice .= '<p><em>' . __('Contenu débloqué grâce à ViewPay', 'viewpay-wordpress') . '</em></p>';
            $unlock_notice .= '</div>';

            // Prepend the notice
            $content = $unlock_notice . $content;
        }

        return $content;
    }

    /**
     * Enqueue custom styles for the integration
     */
    public function enqueue_custom_styles() {
        $css = '
        /* ViewPay Custom Integration Styles */
        .viewpay-custom-container {
            margin: 20px 0;
            text-align: center;
            padding: 15px;
        }

        .viewpay-custom-container .viewpay-separator {
            display: block;
            text-align: center;
            font-weight: bold;
            color: #666;
            margin-bottom: 15px;
            font-size: 14px;
            letter-spacing: 1px;
        }

        .viewpay-custom-container .viewpay-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 200px;
        }

        .viewpay-custom-container .viewpay-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .viewpay-icon {
            width: 16px;
            height: 16px;
            background: url("https://cdn.jokerly.com/images/play_btn_white_small.svg") no-repeat center;
            background-size: contain;
        }

        .viewpay-unlock-notice {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin: 20px 0;
            text-align: center;
        }

        .viewpay-unlock-notice p {
            margin: 0;
        }

        /* Hide paywall when unlocked */
        .viewpay-unlocked ' . esc_attr($this->paywall_selector) . ' {
            display: none !important;
        }

        @media (max-width: 768px) {
            .viewpay-custom-container .viewpay-button {
                width: 100%;
                padding: 15px 20px;
                font-size: 14px;
            }
        }
        ';

        wp_add_inline_style('viewpay-styles', $css);
    }
}
