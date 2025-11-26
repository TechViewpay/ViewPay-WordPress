<?php
/**
 * ViewPay Integration with Simple Membership Pro
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with Simple Membership Pro
 */
class ViewPay_SWPM_Integration {
    
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
        // Button integration - utiliser tous les filtres connus de SWPM
        add_filter('swpm_restricted_content_msg', array($this, 'add_viewpay_button'), 10, 1);
        add_filter('swpm_protected_message', array($this, 'add_viewpay_button'), 10, 1);
        add_filter('swpm_no_access_msg', array($this, 'add_viewpay_button'), 10, 1);
        
        // Access control - intervenir très tôt dans le processus de filtrage
        add_filter('swpm_access_control_before_filtering', array($this, 'check_viewpay_access'), 10, 2);
        add_filter('swpm_member_can_access_content', array($this, 'override_swpm_access'), 10, 3);
        
        // Handle content display for unlocked content - intervention tardive
        add_filter('the_content', array($this, 'ensure_content_access'), 999);
    }
    
    /**
     * Override SWPM's access check directly
     */
    public function override_swpm_access($can_access, $content_id, $user_id) {
        if ($this->main->is_post_unlocked($content_id)) {
            error_log('ViewPay: Overriding SWPM access for post ' . $content_id);
            return true;
        }
        return $can_access;
    }
    
    /**
     * Adds the ViewPay button to Simple Membership Pro restriction messages
     *
     * @param string $message The restriction message text
     * @return string Modified text with ViewPay button
     */
    public function add_viewpay_button($message) {
        global $post;
        
        if (!$post) {
            return $message;
        }
        
        // Log message for debugging
        error_log('ViewPay: Adding button to SWPM message for post ' . $post->ID);
        error_log('ViewPay: Original message: ' . substr($message, 0, 100) . '...');
        
        // Generate a nonce for security
        $nonce = wp_create_nonce('viewpay_nonce');
        
        // Look for existing buttons in the message
        $button_regex = '/<a\s+[^>]*class=["\'](.*?)["\'](.*?)>(.*?)<\/a>/i';
        
        if (preg_match($button_regex, $message, $matches)) {
            // Create ViewPay button with similar style
            $viewpay_button = '<button id="viewpay-button" class="viewpay-button swpm-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $viewpay_button .= esc_html($this->main->get_option('button_text'));
            $viewpay_button .= '</button>';
            
            // Get "OR" text in the site's language
            $or_text = $this->main->get_or_text();
            
            // Insert ViewPay button after the existing button with "OR" separator
            $original_button = $matches[0];
            
            // Create container for inline display if not already in one
            if (strpos($message, 'viewpay-inline-container') === false) {
                $replacement = '<div class="viewpay-inline-container">' . $original_button . ' <span class="viewpay-separator">' . $or_text . '</span> ' . $viewpay_button . '</div>';
            } else {
                $replacement = $original_button . ' <span class="viewpay-separator">' . $or_text . '</span> ' . $viewpay_button;
            }
            
            // Replace the original button with our new structure
            $message = str_replace($original_button, $replacement, $message);
            
            error_log('ViewPay: Modified message with button: ' . substr($message, 0, 100) . '...');
            return $message;
        } else {
            // No button found, simply add our button at the end of the message
            $button = '<div class="viewpay-container">';
            $button .= '<button id="viewpay-button" class="viewpay-button swpm-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $button .= esc_html($this->main->get_option('button_text'));
            $button .= '</button>';
            $button .= '</div>';
            
            // Add button after the restriction message
            $modified_message = $message . $button;
            error_log('ViewPay: Added standalone button: ' . substr($modified_message, 0, 100) . '...');
            return $modified_message;
        }
    }
    
    /**
     * Checks if the user has unlocked content via ViewPay
     *
     * @param mixed $content Current content
     * @param int $post_id Post ID
     * @return mixed Modified content if unlocked
     */
    public function check_viewpay_access($content, $post_id) {
        // If the content is unlocked via ViewPay, return the unfiltered content
        if ($this->main->is_post_unlocked($post_id)) {
            error_log('ViewPay: Content unlocked for SWPM post ' . $post_id);
            return $content;
        }
        
        // Otherwise, let Simple Membership handle access
        return null;
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
            // Add debug log to trace execution
            error_log('ViewPay: Checking content for SWPM post ' . $post->ID);
            
            // Check if the content appears to be restricted by SWPM
            if (strpos($content, 'swpm-protected-content') !== false || 
                strpos($content, 'swpm_protected_message') !== false) {
                
                error_log('ViewPay: Force SWPM content display for post ' . $post->ID);
                
                // Get the original post content
                $original_content = get_post_field('post_content', $post->ID);
                
                // Apply standard content filters except SWPM's
                remove_filter('the_content', array('SwpmProtectContent', 'filter_content'), 20);
                if (class_exists('SwpmPermission')) {
                    remove_filter('the_content', array('SwpmPermission', 'check_permission'), 20);
                }
                
                $filtered_content = apply_filters('the_content', $original_content);
                
                // Restore the filters
                add_filter('the_content', array('SwpmProtectContent', 'filter_content'), 20);
                if (class_exists('SwpmPermission')) {
                    add_filter('the_content', array('SwpmPermission', 'check_permission'), 20);
                }
                
                return $filtered_content;
            }
        }
        
        return $content;
    }
}
