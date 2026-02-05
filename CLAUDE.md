# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

ViewPay WordPress is a plugin that integrates ViewPay (attention-based ad monetization) with WordPress paywall plugins. Users can unlock premium content by watching an ad instead of subscribing.

**Language:** French-first codebase (comments, UI, documentation in French)

## Development Setup

This is a pure WordPress plugin with no build system:
- No npm/package.json
- No Composer dependencies
- No compilation required

**To develop:**
1. Symlink or copy the plugin folder to a WordPress installation's `/wp-content/plugins/` directory
2. Activate via WordPress admin → Extensions
3. Configure at Réglages → ViewPay WordPress

**Debug mode:** Enable "Logs de débogage" in admin settings to see console.log output in browser dev tools.

## Architecture

### Entry Point Flow
```
viewpay-wordpress.php (plugin header, constants, init hooks)
    → plugins_loaded hook
    → viewpay_wordpress_init()
        → loads ViewPay_WordPress class
        → setup_paywall_integration() selects integration class
```

### Core Files

| File | Purpose |
|------|---------|
| `viewpay-wordpress.php` | Plugin bootstrap, constants, validation functions |
| `includes/viewpay-wordpress-class.php` | Main class: options, AJAX handling, script enqueueing |
| `includes/viewpay-admin.php` | WordPress Settings API admin page |
| `includes/integrations/` | Paywall-specific integration classes |

### Integration System

Each supported paywall has a dedicated class in `includes/integrations/`:
- `class-viewpay-pms-integration.php` - Paid Member Subscriptions
- `class-viewpay-pmpro-integration.php` - Paid Memberships Pro
- `class-viewpay-rcp-integration.php` - Restrict Content Pro
- `class-viewpay-swpm-integration.php` - Simple Membership Pro
- `class-viewpay-custom-integration.php` - Generic CSS selector-based
- `class-viewpay-pymag-integration.php` - Pyrénées Magazine custom

**Integration pattern:**
```php
class ViewPay_[Paywall]_Integration {
    public function __construct($main_instance) {
        $this->main = $main_instance;
        $this->init();
    }

    public function init() {
        add_filter('paywall_restriction_filter', [$this, 'add_viewpay_button']);
        add_filter('paywall_access_filter', [$this, 'check_viewpay_access']);
    }
}
```

To add a new paywall integration:
1. Create `class-viewpay-[name]-integration.php` following the pattern above
2. Hook into the paywall's restriction message filter to inject the ViewPay button
3. Hook into the access check filter to allow access when `$this->main->is_post_unlocked()` returns true
4. Register the integration in `ViewPay_WordPress::setup_paywall_integration()`

### Frontend Data Flow

```
User clicks ViewPay button
    → JavaScript loads ad via JKFBASQ.loadAds() (external CDN)
    → Ad completion triggers VPcompleteAds()
    → AJAX POST to admin-ajax.php?action=viewpay_content
    → Backend sets HttpOnly cookie with unlocked post ID
    → Page reloads, integration checks cookie via is_post_unlocked()
```

### External Dependencies

- `https://cdn.jokerly.com/scripts/jkFbASQ.js` - ViewPay ad serving library
- jQuery (WordPress bundled)

### Options Storage

All settings stored in `viewpay_wordpress_options` array in wp_options table. Key options:
- `site_id` - ViewPay account identifier
- `paywall_type` - Which integration to use (pms|pmpro|rcp|swpm|custom|pymag)
- `custom_paywall_selector` - CSS selector for custom integration mode
- `cookie_duration` - Unlock duration in minutes (5-1440)

## Key Methods Reference

**ViewPay_WordPress class:**
- `is_post_unlocked($post_id)` - Check if post accessible via cookie
- `get_viewpay_button()` - Generate HTML for the unlock button
- `process_viewpay()` - AJAX handler that sets unlock cookie

**Frontend JS (`assets/js/viewpay-wordpress.js`):**
- `VPinitVideo()` - Initialize ViewPay ad system
- `VPloadAds()` - Display ad modal
- `VPcompleteAds()` - Handle ad completion callback
- `injectCustomPaywallButton()` - JS-based button injection for custom paywalls
