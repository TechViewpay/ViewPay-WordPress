<?php
/**
 * ViewPay Integration with Pyrenees Magazine Custom Paywall
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with Pyrenees Magazine custom paywall
 */
class ViewPay_PyMag_Integration {
    
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
        
        // Add console debug
        $this->debug_log("PyMag Integration class constructed");
        
        $this->init();
    }
    
    /**
     * Helper function to add debug console logs when debug is enabled
     *
     * @param string $message Debug message
     */
    private function debug_log($message) {
        if ($this->is_debug_enabled()) {
            add_action('wp_footer', function() use ($message) {
                echo '<script>console.log("ViewPay Debug: ' . esc_js($message) . '");</script>';
            });
        }
    }
    
    /**
     * Check if debug logs are enabled
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
        // Add console debug
        $this->debug_log("PyMag Integration init() called");
        
        // Only initialize on premium content pages
        add_action('wp', array($this, 'conditional_init'));
    }
    
    /**
     * Conditionally initialize ViewPay only on premium content
     */
    public function conditional_init() {
        global $post;
        
        // Add console debug
        $this->debug_log("conditional_init called");
        
        if (!$post) {
            $this->debug_log("No post object in conditional_init");
            return;
        }
        
        $this->debug_log("Checking post " . $post->ID . " for premium content");
        
        // Check if this is premium content using Carbon Fields
        if (function_exists('carbon_get_post_meta')) {
            $is_premium = carbon_get_post_meta($post->ID, 'is_premium_content');
            $this->debug_log("carbon_get_post_meta result: " . ($is_premium ? 'TRUE' : 'FALSE'));
            if (!$is_premium) {
                $this->debug_log("Not premium content, exiting");
                return; // Exit if not premium content
            }
        } else {
            $this->debug_log("carbon_get_post_meta function not available");
        }
        
        // Also check if paywall HTML is present (fallback method)
	/*
	$content = get_post_field('post_content', $post->ID);
        $this->debug_log("Post content length: " . strlen($content));
        
        if (!$this->has_pymag_paywall($content)) {
            $this->debug_log("No paywall detected in content, exiting");
            return; // Exit if no paywall detected
	}
	*/
        
        $this->debug_log("SUCCESS: Initializing for premium post " . $post->ID);
        
        // Hook into content rendering to inject ViewPay button
        add_filter('the_content', array($this, 'inject_viewpay_button'), 10);
        
        // Add custom CSS for Pyrenees Magazine styling
        add_action('wp_enqueue_scripts', array($this, 'enqueue_pymag_styles'));
        
        // Handle content access for unlocked posts
        add_filter('the_content', array($this, 'bypass_paywall_content'), 5);
    }
    
    /**
     * Custom debug logging that writes to a specific file
     */
    private function debug_log_file($message) {
        if ($this->is_debug_enabled()) {
            $log_file = WP_CONTENT_DIR . '/viewpay-debug.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] ViewPay Debug: {$message}" . PHP_EOL;
            file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Inject ViewPay button into the premium content CTA
     *
     * @param string $content The post content
     * @return string Modified content with ViewPay button
     */
    public function inject_viewpay_button($content) {
        global $post;
        
        // Debug: Log that the function is being called
        if ($this->is_debug_enabled()) {
            error_log('ViewPay Debug: inject_viewpay_button called for post: ' . ($post ? $post->ID : 'NO POST'));
        }
        
        if (!$post) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay Debug: No post object available');
            }
            return $content;
        }
        
        if (!$this->has_pymag_paywall($content)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay Debug: No paywall found in content for post ' . $post->ID);
            }
            return $content;
        }
        
        if ($this->is_debug_enabled()) {
            error_log('ViewPay: Found paywall in post ' . $post->ID . ', injecting button');
        }
        
        // Generate nonce for security
        $nonce = wp_create_nonce('viewpay_nonce');
        
        // Create ViewPay button with PyMag styling
        $viewpay_button = $this->create_viewpay_button($post->ID, $nonce);
        
        // Debug: log the button HTML
        if ($this->is_debug_enabled()) {
            error_log('ViewPay: Button HTML: ' . substr($viewpay_button, 0, 100) . '...');
        }
        
        // Insert ViewPay button into the paywall structure
        $modified_content = $this->insert_button_into_paywall($content, $viewpay_button);
        
        // Debug: check if content was modified
        if ($this->is_debug_enabled()) {
            if ($modified_content !== $content) {
                error_log('ViewPay: Content successfully modified, button should be visible');
            } else {
                error_log('ViewPay: Content was NOT modified - check regex patterns');
            }
        }
        
        return $modified_content;
    }
    
    /**
     * Check if content contains Pyrenees Magazine paywall
     *
     * @param string $content The content to check
     * @return bool True if paywall is present
     */
    private function has_pymag_paywall($content) {
	/*    
	$has_paywall = strpos($content, 'premium-content-cta') !== false;
        
        // Add console debug
        $this->debug_log("Checking for paywall in content (length: " . strlen($content) . ")");
        $this->debug_log("Paywall found: " . ($has_paywall ? 'YES' : 'NO'));
        if ($has_paywall) {
            $snippet = substr($content, strpos($content, 'premium-content-cta') - 50, 200);
            $snippet = addslashes($snippet);
        }
        
	return $has_paywall;
	 */
	$site_url = get_site_url();
	if (strpos(strtolower($site_url), 'testwp.viewpay.tv') !== false) { return true; }
     	else return false;
    }
    
    /**
     * Create ViewPay button with PyMag styling
     *
     * @param int $post_id Post ID
     * @param string $nonce Security nonce
     * @return string ViewPay button HTML
     */
    private function create_viewpay_button($post_id, $nonce) {
        $button_text = $this->main->get_option('button_text');
        $or_text = $this->main->get_or_text();
        
        $button_html = '<div class="option option--viewpay">';
        $button_html .= '<div class="viewpay-separator-pymag">' . esc_html($or_text) . '</div>';
        $button_html .= '<button id="viewpay-button" class="viewpay-button subscribe-button" data-post-id="' . esc_attr($post_id) . '" data-nonce="' . esc_attr($nonce) . '">';
        $button_html .= '<span class="viewpay-icon"></span>';
        $button_html .= esc_html($button_text);
        $button_html .= '</button>';
        $button_html .= '</div>';
        
        return $button_html;
    }
    
    /**
     * Insert ViewPay button into the paywall structure
     *
     * @param string $content Original content
     * @param string $viewpay_button ViewPay button HTML
     * @return string Modified content
     */
    private function insert_button_into_paywall($content, $viewpay_button) {
        if ($this->is_debug_enabled()) {
            error_log('ViewPay: Attempting to insert button into paywall');
        }
        
        // Multiple patterns to try for different HTML structures
        $patterns = array(
            // Pattern 1: Insert between subscription-options__choices and login option
            array(
                'pattern' => '/(<div class="subscription-options__choices">.*?<\/div>)(\s*<div class="option option--login">)/s',
                'replacement' => '$1' . $viewpay_button . '$2'
            ),
            // Pattern 2: Insert before login option directly
            array(
                'pattern' => '/(<div class="option option--login">)/s',
                'replacement' => $viewpay_button . '$1'
            ),
            // Pattern 3: Insert at the end of subscription-options div
            array(
                'pattern' => '/(<div class="subscription-options">.*?)(<\/div>\s*<\/div>)/s',
                'replacement' => '$1' . $viewpay_button . '$2'
            )
        );
        
        $modified_content = $content;
        
        foreach ($patterns as $pattern_info) {
            $test_content = preg_replace($pattern_info['pattern'], $pattern_info['replacement'], $modified_content);
            
            if ($test_content !== $modified_content) {
                if ($this->is_debug_enabled()) {
                    error_log('ViewPay: Successfully inserted button using pattern');
                }
                return $test_content;
            }
        }
        
        // Ultimate fallback: insert before closing premium-content-cta div
        $fallback_pattern = '/(<\/div>\s*<\/div>)$/s';
        if (preg_match($fallback_pattern, $modified_content)) {
            $modified_content = preg_replace('/(<\/div>\s*<\/div>)$/', $viewpay_button . '$1', $modified_content);
            if ($this->is_debug_enabled()) {
                error_log('ViewPay: Used fallback insertion method');
            }
        } else {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay: Could not find suitable insertion point');
            }
        }
        
        return $modified_content;
    }
    
    /**
     * Bypass paywall content for unlocked posts
     *
     * @param string $content The post content
     * @return string Potentially modified content
     */
    public function bypass_paywall_content($content) {
        global $post;
        
        if (!$post) {
            return $content;
        }
        
        // Check if this post is unlocked via ViewPay
        if ($this->main->is_post_unlocked($post->ID) && $this->has_pymag_paywall($content)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay: Bypass PyMag paywall for post ' . $post->ID);
            }
            
            // Remove the premium-content-cta div entirely
            $content = preg_replace('/<div class="premium-content-cta">.*?<\/div>/s', '', $content);
            
            // Add a small notice that content was unlocked via ViewPay
            $unlock_notice = '<div class="viewpay-unlock-notice">';
            $unlock_notice .= '<p><em>' . __('Contenu débloqué grâce à ViewPay', 'viewpay-wordpress') . '</em></p>';
            $unlock_notice .= '</div>';
            
            $content .= $unlock_notice;
        }
        
        return $content;
    }
    
    /**
     * Enqueue custom styles for Pyrenees Magazine integration
     */
    public function enqueue_pymag_styles() {
	   $site_url = get_site_url();
	   if (strpos(strtolower($site_url), 'testwp.viewpay.tv') !== false) {
		    wp_add_inline_style('viewpay-styles', $this->get_pymag_css());
	   }
 	   else {
	        wp_add_inline_style('viewpay-styles', '.option--viewpay{ display: none !important; }');
           }
    }
    
    /**
     * Get custom CSS for Pyrenees Magazine integration
     *
     * @return string CSS styles
     */
    private function get_pymag_css() {
        return '
	
	/* */	
        .option--viewpay {
            margin: 20px 0;
            text-align: center;
            border-top: 1px solid #e0e0e0;
            padding-top: 20px;
            display: none !important;
        }
        
        .viewpay-ads-available .option--viewpay {
            display: block !important;
        }
        
        .viewpay-separator-pymag {
            display: block;
            text-align: center;
            font-weight: bold;
            color: #666;
            margin-bottom: 15px;
            font-size: 14px;
            letter-spacing: 1px;
        }
        
        .option--viewpay .viewpay-button {
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            text-transform: uppercase;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            min-width: 200px;
            justify-content: center;
        }
        
        .option--viewpay .viewpay-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
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
        
        @media (max-width: 768px) {
            .option--viewpay .viewpay-button {
                width: 100%;
                padding: 15px 20px;
                font-size: 14px;
            }
        }
';   

/* */
    }
}
