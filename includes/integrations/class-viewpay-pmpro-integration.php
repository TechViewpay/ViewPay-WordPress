<?php
/**
 * ViewPay Integration with Paid Memberships Pro
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with Paid Memberships Pro
 */
class ViewPay_PMPro_Integration {
    
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
        // Add ViewPay button to PMPro restriction messages
        add_filter('pmpro_non_member_text_filter', array($this, 'add_viewpay_button'), 10, 2);
        add_filter('pmpro_not_logged_in_text_filter', array($this, 'add_viewpay_button'), 10, 2);
        
        // Check ViewPay access during PMPro access determination
        add_filter('pmpro_has_membership_access_filter', array($this, 'check_viewpay_access'), 10, 4);
        
        // Additional hook to ensure content is properly displayed when unlocked
        add_filter('the_content', array($this, 'ensure_content_access'), 999);
    }
    
    /**
     * Adds the ViewPay button to PMPro restriction messages
     *
     * @param string $text The restriction message text
     * @param int $level_id The membership level ID
     * @return string Modified text with ViewPay button
     */
    public function add_viewpay_button($text, $level_id = 0) {
        global $post;
        
        if (!$post) {
            return $text;
        }
        
        // Generate a nonce for security
        $nonce = wp_create_nonce('viewpay_nonce');
        
        // Look for existing buttons in the message
        $button_regex = '/<a\s+class="(.*?)"\s+href="(.*?)">(.*?)<\/a>/i';
        
        if (preg_match($button_regex, $text, $matches)) {
            // Create button with pmpro_btn class to match PMPro style
            $viewpay_button = '<button id="viewpay-button" class="viewpay-button pmpro_btn" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $viewpay_button .= esc_html($this->main->get_option('button_text'));
            $viewpay_button .= '</button>';
            
            // Get "OR" text in the site's language
            $or_text = $this->main->get_or_text();
            
            // Insert ViewPay button after the existing button with "OR" separator
            $original_button = $matches[0];
            $replacement = $original_button . ' <span class="viewpay-separator">' . $or_text . '</span> ' . $viewpay_button;
            
            // Replace the original button with our new structure
            $text = str_replace($original_button, $replacement, $text);
            
            return $text;
        } else {
            // No button found, simply add our button
            $button = '<div class="viewpay-container">';
            $button .= '<button id="viewpay-button" class="viewpay-button pmpro_btn" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $button .= esc_html($this->main->get_option('button_text'));
            $button .= '</button>';
            $button .= '</div>';
            
            // Add button after the restriction message
            return $text . $button;
        }
    }
    
    /**
     * Checks if the user has unlocked content via ViewPay
     *
     * @param bool $hasaccess Current access status
     * @param object $mypost Post object
     * @param object $myuser User object
     * @param array $post_membership_levels Membership levels that grant access
     * @return bool Modified access status
     */
    public function check_viewpay_access($hasaccess, $mypost, $myuser, $post_membership_levels) {
        // If the user already has access, we don't need to check ViewPay
        if ($hasaccess) {
            return $hasaccess;
        }
        
        // Check if content is unlocked via ViewPay
        if ($this->main->is_post_unlocked($mypost->ID)) {
            error_log('ViewPay: Granting PMPro access for post ' . $mypost->ID);
            return true;
        }
        
        return $hasaccess;
    }
    
    /**
     * Ensures content is displayed when unlocked via ViewPay
     * Acts as a final safeguard against content restriction
     *
     * @param string $content The post content
     * @return string The potentially modified content
     */
    public function ensure_content_access($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        // Only proceed if this post is unlocked via ViewPay
        if ($this->main->is_post_unlocked($post->ID)) {
            // Check if the content appears to be restricted
            if (strpos($content, 'pmpro_content_message') !== false) {
                error_log('ViewPay: Force PMPro content display for post ' . $post->ID);

                // Get the original post content
                $original_content = get_post_field('post_content', $post->ID);

                // Apply standard content filters except PMPro's
                remove_filter('the_content', 'pmpro_membership_content_filter', 5);
                $filtered_content = apply_filters('the_content', $original_content);
                add_filter('the_content', 'pmpro_membership_content_filter', 5);

                // Add unlock notice if enabled
                $notice = $this->get_unlock_notice();

                return $notice . $filtered_content;
            }
        }

        return $content;
    }

    /**
     * Get the unlock notice HTML if enabled
     *
     * @return string The notice HTML or empty string
     */
    private function get_unlock_notice() {
        return '';
    }
}
