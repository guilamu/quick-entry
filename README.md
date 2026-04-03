# QuickEntry

Create temporary login URLs with email verification and role assignment for WordPress.

## Temporary Login Management

- Generate unique, secure login URLs that expire after a set time
- Assign any WordPress role (Administrator, Editor, Author, Contributor, Subscriber)
- Configure URLs for single use or multiple uses until expiration
- Revoke temporary logins at any time from the admin dashboard

## Email Verification

- Send 6-digit verification codes via WordPress email system
- Resend codes with a single click
- Codes expire after 10 minutes for security
- Spam folder notice included in verification page

## Admin Dashboard

- View all active and expired temporary logins
- Filter by status (active, expired, used, revoked)
- See usage statistics and audit trail
- Copy login URLs with one click

## Key Features

- **Role Assignment:** Choose from all WordPress roles for each temporary login
- **Expiration Control:** Set custom date and time for URL expiration
- **One-Time Use:** Optional single-use mode for maximum security
- **Multilingual:** Works with content in any language
- **Translation-Ready:** All strings are internationalized
- **Secure:** Cryptographically secure tokens, email verification, rate limiting
- **GitHub Updates:** Automatic updates from GitHub releases

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- WordPress email system must be functional (for sending verification codes)

## Installation

1. Upload the `quick-entry` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **QuickEntry → Temporary Logins** to create your first temporary login
4. Share the generated URL with the intended user

## FAQ

### Is this secure?

Yes. Each temporary login uses cryptographically secure random tokens (256-bit entropy via `random_bytes()`), tokens and verification codes are stored hashed (SHA-256 / `wp_hash_password()`), 6-digit codes are sent via email, and configurable expiration dates apply.

### What happens when a temporary login expires?

The URL becomes invalid and displays an error message. The user will need a new temporary login URL.

### Can I revoke a temporary login before it expires?

Yes. Go to **QuickEntry → Temporary Logins** and click "Revoke" on any active login.

### Does this create actual WordPress user accounts?

No. Temporary logins create a session with the assigned role permissions but do not create permanent user accounts in the database.

### Can I customize the verification email?

Yes, use the `qentry_email_subject` and `qentry_email_body` filters:

```php
add_filter( 'qentry_email_subject', function( $subject ) {
    return 'Your custom subject';
} );

add_filter( 'qentry_email_body', function( $body, $code ) {
    return 'Your custom message with code: ' . $code;
}, 10, 2 );
```

## Project Structure

```
.
├── quick-entry.php              # Main plugin file
├── uninstall.php                # Cleanup on uninstall
├── README.md
├── assets
│   ├── css
│   │   ├── admin.css            # Admin interface styles
│   │   └── frontend.css         # Verification page styles
│   └── js
│       └── admin.js             # Admin AJAX and UI scripts
├── includes
│   ├── class-admin.php          # Admin dashboard and settings
│   ├── class-authenticator.php  # Authentication and session handling
│   ├── class-database.php       # Custom table management
│   ├── class-email.php          # Email sending functionality
│   ├── class-frontend.php       # Verification page rendering
│   └── class-github-updater.php # GitHub auto-updates
└── languages
    ├── quick-entry-fr_FR.mo     # French translation (binary)
    ├── quick-entry-fr_FR.po     # French translation (source)
    └── quick-entry.pot          # Translation template
```

## Changelog

### 1.1.2
- **AUTO DRAFT TEST**

### 1.1.1
- **UI improvements:** Better UI

### 1.1.0
- **Security:** Tokens now stored as SHA-256 hashes — raw token never persists in DB
- **Security:** Switched token generation to `random_bytes(32)` (256-bit CSPRNG)
- **Security:** Verification codes stored hashed with `wp_hash_password()`
- **Security:** Replaced all `rand()` calls with `random_int()` (CSPRNG)
- **Security:** Server-side rate limiting via transients (per-IP + per-token) — PHP sessions removed
- **Security:** Email flood protection on code resend (max 5 per 10 min per address)
- **Security:** `wp_clear_auth_cookie()` called before `wp_set_auth_cookie()` (session fixation fix)
- **Security:** Auth cookie set as session-only (no persistent 14-day "remember me")
- **Security:** `do_action('wp_login', ...)` fired for audit-trail compatibility
- **Security:** `administrator` role blocked from magic link assignment
- **Security:** Generic error message for all invalid/expired/used token states
- **Security:** `Referrer-Policy: no-referrer` + `Cache-Control: no-store` on verification page
- **Security:** `textContent` instead of `innerHTML` in admin JS notifications (XSS fix)
- **Security:** WP-Cron scheduled cleanup of expired tokens (twice daily)
- **Security:** Prepared LIKE queries in uninstall.php

### 1.0.0
- Initial release
- Temporary login URL generation with UUID tokens
- Email verification with 6-digit codes
- Role assignment for all WordPress roles
- Admin dashboard for managing temporary logins
- One-time and multi-use URL support
- Expiration date and time control
- Audit trail for tracking usage
- GitHub auto-update support

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) - see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with love for the WordPress community
</p>