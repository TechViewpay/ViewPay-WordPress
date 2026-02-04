<?php
/**
 * ViewPay Integration with Paid Member Subscriptions
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with Paid Member Subscriptions (Cozmoslabs)
 *
 * @see https://www.cozmoslabs.com/docs/paid-member-subscriptions/
 */
class ViewPay_PMS_Integration {

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
     * Check if debug mode is enabled
     *
     * @return bool True if debug logs are enabled
     */
    private function is_debug_enabled() {
        return $this->main->get_option('enable_debug_logs') === 'yes';
    }

    /**
     * Initialize the integration
     */
    public function init() {
        // Very early hook - before anything else
        add_action('init', array($this, 'early_viewpay_check'), 1);

        // Hook into PMS restriction message filter
        add_filter('pms_restriction_message', array($this, 'add_viewpay_button'), 10, 2);

        // Alternative hook for content restriction
        add_filter('pms_restricted_post_content', array($this, 'add_viewpay_button_to_content'), 10, 2);

        // Override restriction check when ViewPay has unlocked content
        add_filter('pms_is_post_restricted', array($this, 'check_viewpay_access'), 10, 2);

        // Hook into member access check
        add_filter('pms_member_is_member', array($this, 'override_member_check'), 10, 3);

        // Hook into PMS block render to bypass restriction
        add_filter('pms_block_content_restriction_start_output', array($this, 'bypass_block_restriction'), 10, 3);

        // Early intervention - runs before PMS filters content
        add_action('wp', array($this, 'force_pms_access'), 1);

        // Multiple content filters at different priorities
        add_filter('the_content', array($this, 'early_content_access'), 1);
        add_filter('the_content', array($this, 'inject_button_in_gutenberg_blocks'), 50);
        add_filter('the_content', array($this, 'unlock_gutenberg_blocks'), 100);
        add_filter('the_content', array($this, 'force_content_display'), 999);
    }

    /**
     * Very early check for ViewPay unlock status
     */
    public function early_viewpay_check() {
        // Log cookie status for debugging
        if ($this->is_debug_enabled()) {
            $has_cookie = isset($_COOKIE['viewpay_unlocked_posts']);
            error_log('ViewPay PMS: early_viewpay_check - Cookie present: ' . ($has_cookie ? 'YES' : 'NO'));
            if ($has_cookie) {
                error_log('ViewPay PMS: Cookie value: ' . $_COOKIE['viewpay_unlocked_posts']);
            }
        }
    }

    /**
     * Bypass PMS block restriction when unlocked via ViewPay
     *
     * @param string $output The block output
     * @param string $content The inner content
     * @param array $attributes Block attributes
     * @return string Modified output
     */
    public function bypass_block_restriction($output, $content, $attributes) {
        global $post;

        if (!$post) {
            return $output;
        }

        if ($this->main->is_post_unlocked($post->ID)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay PMS: Bypassing block restriction for post ' . $post->ID);
            }
            // Return the original content without restriction
            return $content;
        }

        return $output;
    }

    /**
     * Add ViewPay button to PMS restriction message
     *
     * @param string $message The restriction message
     * @param int $post_id The post ID
     * @return string Modified message with ViewPay button
     */
    public function add_viewpay_button($message, $post_id = 0) {
        global $post;

        if (!$post_id && $post) {
            $post_id = $post->ID;
        }

        if (!$post_id) {
            return $message;
        }

        // Generate nonce for security
        $nonce = wp_create_nonce('viewpay_nonce');

        // Look for existing buttons in the message (PMS uses various button styles)
        $button_patterns = array(
            '/<a\s+[^>]*class="[^"]*pms-[^"]*"[^>]*>.*?<\/a>/is',
            '/<a\s+[^>]*href="[^"]*register[^"]*"[^>]*>.*?<\/a>/is',
            '/<a\s+class="([^"]*?)"\s+href="([^"]*?)">(.*?)<\/a>/i',
        );

        $found_button = false;
        foreach ($button_patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                // Create ViewPay button with PMS-compatible styling
                $viewpay_button = '<button id="viewpay-button" class="viewpay-button pms-button" data-post-id="' . esc_attr($post_id) . '" data-nonce="' . esc_attr($nonce) . '">';
                $viewpay_button .= esc_html($this->main->get_option('button_text'));
                $viewpay_button .= '</button>';

                // Get "OR" text in the site's language
                $or_text = $this->main->get_or_text();

                // Insert ViewPay button after the existing button with "OR" separator
                $original_button = $matches[0];
                $replacement = $original_button . ' <span class="viewpay-separator">' . $or_text . '</span> ' . $viewpay_button;

                // Replace the original button with our new structure
                $message = str_replace($original_button, $replacement, $message);
                $found_button = true;
                break;
            }
        }

        if (!$found_button) {
            // No button found, simply add our button
            $button = '<div class="viewpay-container">';
            $button .= '<button id="viewpay-button" class="viewpay-button pms-button" data-post-id="' . esc_attr($post_id) . '" data-nonce="' . esc_attr($nonce) . '">';
            $button .= esc_html($this->main->get_option('button_text'));
            $button .= '</button>';
            $button .= '</div>';

            // Add button after the restriction message
            $message .= $button;
        }

        return $message;
    }

    /**
     * Add ViewPay button to restricted content
     *
     * @param string $content The restricted content/message
     * @param int $post_id The post ID
     * @return string Modified content with ViewPay button
     */
    public function add_viewpay_button_to_content($content, $post_id = 0) {
        return $this->add_viewpay_button($content, $post_id);
    }

    /**
     * Inject ViewPay button in PMS Gutenberg blocks
     *
     * PMS uses Gutenberg blocks (wp-block-pms-content-restriction-start/end)
     * that don't trigger the classic pms_restriction_message filter.
     * This method handles those blocks.
     *
     * @param string $content The post content
     * @return string Modified content with ViewPay button
     */
    public function inject_button_in_gutenberg_blocks($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        // Don't inject if user is admin or already has access
        if (current_user_can('manage_options')) {
            return $content;
        }

        // Don't inject if already unlocked via ViewPay
        if ($this->main->is_post_unlocked($post->ID)) {
            return $content;
        }

        // Check if content has PMS Gutenberg block
        if (strpos($content, 'wp-block-pms-content-restriction-start') === false) {
            return $content;
        }

        // Check if ViewPay button already exists
        if (strpos($content, 'viewpay-button') !== false) {
            return $content;
        }

        // Generate the ViewPay button HTML
        $nonce = wp_create_nonce('viewpay_nonce');
        $button_text = $this->main->get_option('button_text');
        $or_text = $this->main->get_or_text();

        $viewpay_html = '<div class="viewpay-pms-container" style="margin-top: 15px; text-align: center;">';
        $viewpay_html .= '<span class="viewpay-separator" style="display: block; margin-bottom: 10px; font-weight: bold;">' . esc_html($or_text) . '</span>';
        $viewpay_html .= '<button id="viewpay-button" class="viewpay-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
        $viewpay_html .= '<span class="viewpay-icon"></span>';
        $viewpay_html .= esc_html($button_text);
        $viewpay_html .= '</button>';
        $viewpay_html .= '</div>';

        // Inject button after the PMS restriction message div
        // Pattern matches: <div class="wp-block-pms-content-restriction-start">...message...</div>
        $pattern = '/(<div\s+class="wp-block-pms-content-restriction-start"[^>]*>.*?<\/div>)/is';
        $content = preg_replace($pattern, '$1' . $viewpay_html, $content, 1);

        return $content;
    }

    /**
     * Unlock Gutenberg blocks when content is unlocked via ViewPay
     *
     * When user has unlocked content via ViewPay, we need to show the full content
     * that was hidden by PMS blocks.
     *
     * @param string $content The post content
     * @return string Modified content with unlocked blocks
     */
    public function unlock_gutenberg_blocks($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        // Only process if unlocked via ViewPay
        if (!$this->main->is_post_unlocked($post->ID)) {
            return $content;
        }

        // Check if content has PMS Gutenberg block restriction message
        if (strpos($content, 'wp-block-pms-content-restriction-start') === false) {
            return $content;
        }

        if ($this->is_debug_enabled()) {
            error_log('ViewPay PMS: unlock_gutenberg_blocks - Attempting to unlock content for post ' . $post->ID);
        }

        // Get the raw post content and re-render without PMS restrictions
        $raw_content = get_post_field('post_content', $post->ID);

        // Remove PMS block markers from raw content
        // Pattern: <!-- wp:pms/content-restriction-start -->...<!-- /wp:pms/content-restriction-start -->
        // And: <!-- wp:pms/content-restriction-end /-->
        $raw_content = preg_replace('/<!-- wp:pms\/content-restriction-start[^>]*-->.*?<!-- \/wp:pms\/content-restriction-start -->/s', '', $raw_content);
        $raw_content = preg_replace('/<!-- wp:pms\/content-restriction-end[^>]*\/?-->/s', '', $raw_content);

        // Also handle the shortcode version if present
        $raw_content = preg_replace('/\[pms-restrict[^\]]*\]/', '', $raw_content);
        $raw_content = preg_replace('/\[\/pms-restrict\]/', '', $raw_content);

        // Temporarily remove our own filters to avoid infinite loop
        remove_filter('the_content', array($this, 'unlock_gutenberg_blocks'), 100);
        remove_filter('the_content', array($this, 'inject_button_in_gutenberg_blocks'), 50);

        // Apply content filters (except PMS)
        remove_filter('the_content', 'pms_filter_content', 10);
        remove_filter('the_content', 'pms_restrict_content', 10);

        $unlocked_content = apply_filters('the_content', $raw_content);

        // Re-add our filters
        add_filter('the_content', array($this, 'inject_button_in_gutenberg_blocks'), 50);
        add_filter('the_content', array($this, 'unlock_gutenberg_blocks'), 100);

        if ($this->is_debug_enabled()) {
            error_log('ViewPay PMS: Content unlocked successfully for post ' . $post->ID);
        }

        return $unlocked_content;
    }

    /**
     * Check if content should be unrestricted due to ViewPay unlock
     *
     * @param bool $is_restricted Whether the post is restricted
     * @param int $post_id The post ID
     * @return bool Modified restriction status
     */
    public function check_viewpay_access($is_restricted, $post_id) {
        // If already not restricted, no need to check
        if (!$is_restricted) {
            return $is_restricted;
        }

        // Check if unlocked via ViewPay
        if ($this->main->is_post_unlocked($post_id)) {
            error_log('ViewPay: Overriding PMS restriction for post ' . $post_id);
            return false; // Not restricted
        }

        return $is_restricted;
    }

    /**
     * Override member check when ViewPay has unlocked content
     *
     * @param bool $is_member Whether user is a member
     * @param int $user_id The user ID
     * @param array $subscription_plan_ids Array of subscription plan IDs
     * @return bool Modified member status
     */
    public function override_member_check($is_member, $user_id, $subscription_plan_ids) {
        global $post;

        if (!$post) {
            return $is_member;
        }

        // If unlocked via ViewPay, treat as member for this content
        if ($this->main->is_post_unlocked($post->ID)) {
            error_log('ViewPay: Granting PMS member access for post ' . $post->ID);
            return true;
        }

        return $is_member;
    }

    /**
     * Force PMS access early in page load
     */
    public function force_pms_access() {
        global $post;

        if (!$post) {
            return;
        }

        if ($this->main->is_post_unlocked($post->ID)) {
            error_log('ViewPay: Force PMS access for post ' . $post->ID);

            // Try to remove PMS content restriction filters
            // PMS typically uses priority 10 for its filters
            remove_filter('the_content', 'pms_filter_content', 10);
            remove_filter('the_content', 'pms_restrict_content', 10);

            // Force access flags
            add_filter('pms_is_post_restricted', '__return_false', 999);
            add_filter('pms_member_is_member', '__return_true', 999);
        }
    }

    /**
     * Early content access filter
     *
     * @param string $content The post content
     * @return string The content
     */
    public function early_content_access($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        if ($this->main->is_post_unlocked($post->ID)) {
            error_log('ViewPay: Early PMS content filter for post ' . $post->ID);

            // Remove restriction filters
            remove_filter('the_content', 'pms_filter_content', 10);
            remove_filter('the_content', 'pms_restrict_content', 10);
        }

        return $content;
    }

    /**
     * Last-resort content display enforcer
     *
     * @param string $content The post content
     * @return string The potentially modified content
     */
    public function force_content_display($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        if ($this->main->is_post_unlocked($post->ID)) {
            // Check if content appears to be restricted by PMS
            // PMS typically wraps restriction messages in specific classes
            $restriction_indicators = array(
                'pms-restriction-message',
                'pms_restricted',
                'pms-content-restricted',
                'class="pms-'
            );

            $is_restricted_content = false;
            foreach ($restriction_indicators as $indicator) {
                if (strpos($content, $indicator) !== false) {
                    $is_restricted_content = true;
                    break;
                }
            }

            if ($is_restricted_content) {
                error_log('ViewPay: Force PMS content display for post ' . $post->ID);

                // Get the original post content
                $original_content = get_post_field('post_content', $post->ID);

                // Apply standard content filters except PMS's
                remove_filter('the_content', 'pms_filter_content', 10);
                remove_filter('the_content', 'pms_restrict_content', 10);
                $filtered_content = apply_filters('the_content', $original_content);

                return $filtered_content;
            }
        }

        return $content;
    }
}
