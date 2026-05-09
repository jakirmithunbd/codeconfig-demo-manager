<?php
/**
 * Front-end form — runs on codeconfig.dev
 *
 * Shortcode: [codeconfig_demo_form]
 *
 * Security:
 *   - WP nonce on AJAX action
 *   - IP-based rate limiting (3 requests / hour via transients)
 *   - Honeypot field
 *   - Input validation + sanitisation on both client and server
 *   - No local WP user created — delegates entirely to demo site API
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_Form {

    /** Max form submissions per IP per hour */
    private const RATE_LIMIT = 3;

    public function __construct() {
        add_shortcode( 'codeconfig_demo_form', [ $this, 'render_form' ] );
        add_action( 'wp_enqueue_scripts',             [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_nopriv_ccdemo_request',  [ $this, 'handle_submission' ] );
        add_action( 'wp_ajax_ccdemo_request',         [ $this, 'handle_submission' ] );
    }

    /* ------------------------------------------------------------------
     * Asset loading — only on pages that use the shortcode
     * ------------------------------------------------------------------ */

    public function enqueue_assets(): void {
        if ( ! is_singular() ) {
            return;
        }
        global $post;
        if ( ! has_shortcode( $post->post_content ?? '', 'codeconfig_demo_form' ) ) {
            return;
        }

        wp_enqueue_style(
            'ccdemo-form',
            CCDEMO_URL . 'assets/css/demo-form.css',
            [],
            CCDEMO_VERSION
        );
        wp_enqueue_script(
            'ccdemo-form',
            CCDEMO_URL . 'assets/js/demo-form.js',
            [ 'jquery' ],
            CCDEMO_VERSION,
            true // footer
        );
        wp_localize_script( 'ccdemo-form', 'CCDemoAjax', [
            'ajaxurl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'ccdemo_request' ),
            'primary_color' => esc_js( get_option( 'ccdemo_primary_color', '#4F46E5' ) ),
        ] );
    }

    /* ------------------------------------------------------------------
     * Shortcode
     * ------------------------------------------------------------------ */

    public function render_form( array $atts = [] ): string {
        $atts = shortcode_atts( [
            'title'    => 'Try a Live Demo',
            'subtitle' => 'Choose a product and we\'ll send your private demo link instantly.',
        ], $atts );

        $products     = self::get_products();
        $expiry_days  = (int) get_option( 'ccdemo_expiry_days', 2 );

        ob_start();
        ?>
        <div class="ccdemo-wrap" id="ccdemo-wrap" role="region" aria-label="Demo request form">

            <!-- Step 1: product selection -->
            <div class="ccdemo-step" id="ccdemo-step-1" aria-live="polite">
                <h2 class="ccdemo-title"><?php echo esc_html( $atts['title'] ); ?></h2>
                <p class="ccdemo-subtitle"><?php echo esc_html( $atts['subtitle'] ); ?></p>
                <div class="ccdemo-products" role="list">
                    <?php foreach ( $products as $slug => $label ) : ?>
                        <button type="button"
                                class="ccdemo-product-btn"
                                data-product="<?php echo esc_attr( $slug ); ?>"
                                role="listitem"
                                aria-label="Select <?php echo esc_attr( $label ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: lead form -->
            <div class="ccdemo-step ccdemo-hidden" id="ccdemo-step-2" aria-live="polite">
                <button type="button" class="ccdemo-back-btn" id="ccdemo-back" aria-label="Go back to product selection">
                    &larr; Back
                </button>
                <h2 class="ccdemo-title">Almost there!</h2>
                <p class="ccdemo-subtitle">
                    Your demo for <strong id="ccdemo-product-label"></strong> is ready.
                    Fill in your details and we'll email the access link.
                </p>

                <form id="ccdemo-form" novalidate aria-label="Demo request form">
                    <?php wp_nonce_field( 'ccdemo_request', 'ccdemo_nonce' ); ?>
                    <input type="hidden" name="product" id="ccdemo-product-input" value="">

                    <!-- Honeypot — bots fill this, humans don't -->
                    <div class="ccdemo-hp" aria-hidden="true">
                        <label for="ccdemo-website">Website</label>
                        <input type="text" id="ccdemo-website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="ccdemo-field-row">
                        <div class="ccdemo-field">
                            <label for="ccdemo-name">Full Name <span aria-hidden="true">*</span></label>
                            <input type="text" id="ccdemo-name" name="name"
                                   placeholder="Jane Smith"
                                   required
                                   minlength="2"
                                   maxlength="100"
                                   autocomplete="name"
                                   aria-required="true">
                        </div>
                        <div class="ccdemo-field">
                            <label for="ccdemo-email">Work Email <span aria-hidden="true">*</span></label>
                            <input type="email" id="ccdemo-email" name="email"
                                   placeholder="jane@company.com"
                                   required
                                   maxlength="191"
                                   autocomplete="email"
                                   aria-required="true">
                        </div>
                    </div>

                    <div class="ccdemo-field-row">
                        <div class="ccdemo-field">
                            <label for="ccdemo-company">Company</label>
                            <input type="text" id="ccdemo-company" name="company"
                                   placeholder="Acme Inc."
                                   maxlength="150"
                                   autocomplete="organization">
                        </div>
                        <div class="ccdemo-field">
                            <label for="ccdemo-phone">Phone</label>
                            <input type="tel" id="ccdemo-phone" name="phone"
                                   placeholder="+1 555 000 0000"
                                   maxlength="30"
                                   autocomplete="tel">
                        </div>
                    </div>

                    <div id="ccdemo-error" class="ccdemo-error ccdemo-hidden" role="alert" aria-live="assertive"></div>

                    <button type="submit" class="ccdemo-submit-btn" id="ccdemo-submit">
                        <span class="ccdemo-btn-text">Send My Demo Link</span>
                        <span class="ccdemo-spinner ccdemo-hidden" aria-hidden="true"></span>
                    </button>

                    <p class="ccdemo-privacy">
                        We respect your privacy. Your demo expires in
                        <strong><?php echo esc_html( $expiry_days ); ?> day<?php echo $expiry_days !== 1 ? 's' : ''; ?></strong>
                        and is only accessible by you.
                    </p>
                </form>
            </div>

            <!-- Step 3: success -->
            <div class="ccdemo-step ccdemo-hidden" id="ccdemo-step-3" aria-live="polite">
                <div class="ccdemo-success-icon" aria-hidden="true">&#10003;</div>
                <h2 class="ccdemo-title">Check your inbox!</h2>
                <p class="ccdemo-subtitle" id="ccdemo-success-msg">
                    We've sent a demo link to your email. Click it to enter your private demo.
                    It will automatically expire after
                    <?php echo esc_html( $expiry_days ); ?> day<?php echo $expiry_days !== 1 ? 's' : ''; ?>.
                </p>
            </div>

        </div><!-- /.ccdemo-wrap -->
        <?php
        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * AJAX handler
     * ------------------------------------------------------------------ */

    public function handle_submission(): void {
        // 1. Verify WP nonce
        if ( ! check_ajax_referer( 'ccdemo_request', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh and try again.' ], 403 );
        }

        // 2. Honeypot check
        if ( ! empty( $_POST['website'] ) ) {
            // Silently succeed — don't reveal the honeypot to bots
            wp_send_json_success( [ 'message' => 'Demo link sent! Check your inbox.' ] );
        }

        // 3. Rate limiting by IP
        $ip       = $this->get_client_ip();
        $rl_key   = 'ccdemo_rl_' . hash( 'sha256', $ip );
        $requests = (int) get_transient( $rl_key );

        if ( $requests >= self::RATE_LIMIT ) {
            wp_send_json_error( [
                'message' => 'Too many demo requests. Please wait an hour before trying again.',
            ], 429 );
        }

        // 4. Sanitise + validate inputs
        $name    = sanitize_text_field( wp_unslash( $_POST['name']    ?? '' ) );
        $email   = sanitize_email(      wp_unslash( $_POST['email']   ?? '' ) );
        $company = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
        $phone   = sanitize_text_field( wp_unslash( $_POST['phone']   ?? '' ) );
        $product = sanitize_key(        wp_unslash( $_POST['product'] ?? '' ) );

        $errors = [];
        if ( strlen( $name ) < 2 )        { $errors[] = 'Please enter your full name.'; }
        if ( ! is_email( $email ) )        { $errors[] = 'Please enter a valid email address.'; }
        if ( empty( $product ) )           { $errors[] = 'Please select a product.'; }

        $products = CCDemo_Products::all();
        if ( ! array_key_exists( $product, $products ) ) {
            $errors[] = 'Invalid product selection.';
        }

        if ( $errors ) {
            wp_send_json_error( [ 'message' => implode( ' ', $errors ) ] );
        }

        $product_label   = $products[ $product ]['label'];
        $product_demo_url = CCDemo_Products::demo_url_for( $product );

        if ( empty( $product_demo_url ) ) {
            wp_send_json_error( [ 'message' => 'This product demo is not available yet. Please check back soon.' ] );
        }

        // 5. Bump rate-limit counter (persist for 1 hour)
        set_transient( $rl_key, $requests + 1, HOUR_IN_SECONDS );

        // 6. Call the product's specific demo site API
        $client = CCDemo_API_Client::for_product( $product );
        $result = $client->create_session( [
            'name'    => $name,
            'email'   => $email,
            'company' => $company,
            'phone'   => $phone,
            'product' => $product,
            'ip'      => $ip,
        ] );

        if ( ! $result['success'] ) {
            // Decrement rate limit on API failure (not the user's fault)
            set_transient( $rl_key, max( 0, $requests ), HOUR_IN_SECONDS );
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        // 7. Send the magic-link email from this (main) site
        $sent = CCDemo_Email::send_demo_link( [
            'name'        => $name,
            'email'       => $email,
            'product'     => $product_label,
            'magic_link'  => $result['magic_link'],
            'expires_at'  => $result['expires_at'],
            'expiry_days' => (int) get_option( 'ccdemo_expiry_days', 2 ),
        ] );

        // 8. Admin notification (non-blocking via shutdown hook)
        add_action( 'shutdown', static function () use ( $name, $email, $company, $phone, $product, $ip ) {
            CCDemo_Email::send_admin_notification( compact( 'name', 'email', 'company', 'phone', 'product', 'ip' ) );
        } );

        wp_send_json_success( [
            'message' => sprintf(
                'Demo link sent to %s! Please check your inbox (and spam folder).',
                esc_html( $email )
            ),
        ] );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Returns [ slug => label ] for form rendering and AJAX validation.
     * Delegates to CCDemo_Products which handles caching.
     */
    public static function get_products(): array {
        return CCDemo_Products::labels();
    }

    /**
     * Get the real client IP, trusting only known proxy headers.
     */
    private function get_client_ip(): string {
        // Only trust CF-Connecting-IP / X-Real-IP when behind a known proxy
        $trusted = apply_filters( 'ccdemo_trust_proxy_headers', false );

        if ( $trusted ) {
            foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' ] as $header ) {
                if ( ! empty( $_SERVER[ $header ] ) ) {
                    $ip = filter_var( $_SERVER[ $header ], FILTER_VALIDATE_IP );
                    if ( $ip ) {
                        return $ip;
                    }
                }
            }
        }

        return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    }
}
