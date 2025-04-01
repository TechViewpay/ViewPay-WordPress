<?php
/**
 * ViewPay Integration with Restrict Content Pro
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with Restrict Content Pro
 */
class ViewPay_RCP_Integration {
    
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
        // Button integration
        add_filter('rcp_restricted_message', array($this, 'add_viewpay_button'), 10, 1);
        
        // Early intervention - highest priority
        add_action('wp', array($this, 'force_rcp_access'), 1);
        
        // Direct override of RCP's main restriction function
        add_filter('rcp_is_restricted_content', array($this, 'override_restriction'), 1, 3);
        
        // Multiple content filters with different priorities
        add_filter('the_content', array($this, 'early_content_access'), 1);   // Early
        add_filter('the_content', array($this, 'force_content_display'), 999);    // Late
        
        // Override user access capabilities
        add_filter('rcp_user_can_access', array($this, 'check_viewpay_access'), 999, 3);
    }
    
    /**
     * Add ViewPay button to RCP restriction message
     */
    public function add_viewpay_button($message) {
        global $post;
        
        if (!$post) {
            return $message;
        }
        
        // Generate nonce for security
        $nonce = wp_create_nonce('viewpay_nonce');
        
        // Look for existing buttons in the message
        $button_regex = '/<a\s+class="([^"]*?)wp-block-button__link([^"]*?)"\s+href="([^"]*?)">(.*?)<\/a>/i';
        
        if (preg_match($button_regex, $message, $matches)) {
            // Create button with similar classes to match RCP style
            $viewpay_button = '<button id="viewpay-button" class="viewpay-button wp-block-button__link wp-element-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $viewpay_button .= esc_html($this->main->get_option('button_text'));
            $viewpay_button .= '</button>';
            
            // Get "OR" text in the site's language
            $or_text = $this->main->get_or_text();
            
            // Insert ViewPay button after the existing button with "OR" separator
            $original_button = $matches[0];
            $replacement = $original_button . ' <span class="viewpay-separator">' . $or_text . '</span> ' . $viewpay_button;
            
            // Replace the original button with our new structure
            $message = str_replace($original_button, $replacement, $message);
            
            return $message;
        } else {
            // No button found, simply add our button
            $button = '<div class="viewpay-container">';
            $button .= '<button id="viewpay-button" class="viewpay-button wp-block-button__link wp-element-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $button .= esc_html($this->main->get_option('button_text'));
            $button .= '</button>';
            $button .= '</div>';
            
            // Add button after the restriction message
            return $message . $button;
        }
    }
    
    /**
     * Fundamental access override that runs very early in page load
     */
    public function force_rcp_access() {
        global $post;
        
        if (!$post) {
            return;
        }
        
        if ($this->main->is_post_unlocked($post->ID)) {
            error_log('ViewPay: Force RCP access for post ' . $post->ID);
            
            // Remove RCP's content filters completely
            if (has_filter('the_content', 'rcp_filter_restricted_content')) {
                remove_filter('the_content', 'rcp_filter_restricted_content', 100);
            }
            
            if (has_filter('the_content', 'rcp_filter_paid_only_content')) {
                remove_filter('the_content', 'rcp_filter_paid_only_content', 100);
            }
            
            // Disable RCP's main restriction functions through global variables
            if (class_exists('RCP_Member')) {
                global $rcp_is_paid_content;
                $rcp_is_paid_content = false;
            }
            
            // Force global access flag to true
            add_filter('rcp_is_paid_content', '__return_false', 999);
        }
    }
    
    /**
     * Direct override of RCP's main restriction function
     */
    public function override_restriction($is_restricted, $post_id, $user_id=0) {
        if ($this->main->is_post_unlocked($post_id)) {
            error_log('ViewPay: Override RCP restriction for post ' . $post_id);
            return false; // Not restricted
        }
        return $is_restricted;
    }
    
    /**
     * Basic content access check
     */
    public function early_content_access($content) {
        global $post;
        
        if (!$post) {
            return $content;
        }
        
        if ($this->main->is_post_unlocked($post->ID)) {
            error_log('ViewPay: Early content filter for post ' . $post->ID);
            // Remove restriction filter to prevent its execution
            remove_filter('the_content', 'rcp_filter_restricted_content', 100);
        }
        
        return $content;
    }
    
    /**
     * Last-resort content display enforcer
     */
    public function force_content_display($content) {
        global $post;
        
        if (!$post) {
            return $content;
        }
        
        if ($this->main->is_post_unlocked($post->ID)) {
            // Check if content has already been restricted
            if (strpos($content, 'class="restrict-content-message"') !== false || 
                strpos($content, 'rcp-restricted-content') !== false) {
                
                error_log('ViewPay: Force content display for post ' . $post->ID);
                
                // Get the original post content
                $original_content = get_post_field('post_content', $post->ID);
                $original_content = apply_filters('the_content', $original_content);
                
                // Return the original content, bypassing restriction
                return $original_content;
            }
        }
        
        return $content;
    }
    
    /**
     * User access override
     */
    public function check_viewpay_access($can_access, $user_id, $post_id) {
        if ($this->main->is_post_unlocked($post_id)) {
            error_log('ViewPay: Grant RCP access for post ' . $post_id);
            return true;
        }
        return $can_access;
    }
}
