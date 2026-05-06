<?php
defined( 'ABSPATH' ) || exit;

class CCDemo_Form {

    public function __construct() {
        add_shortcode( 'codeconfig_demo_form', [ $this, 'render_form' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_nopriv_ccdemo_request', [ $this, 'handle_submission' ] );
        add_action( 'wp_ajax_ccdemo_request',        [ $this, 'handle_submission' ] );
    }

    public function enqueue_assets(): void {
        if ( ! is_singular() ) {
            return;
        }
        global $post;
        if ( ! has_shortcode( $post->post_content, 'codeconfig_demo_form' ) ) {
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
            true
        );
        wp_localize_script( 'ccdemo-form', 'CCDemoAjax', [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ccdemo_request' ),
        ] );
    }

    /**
     * Shortcode callback — renders the product-select + lead-capture form.
     */
    public function render_form( array $atts = [] ): string {
        $atts = shortcode_atts( [ 'title' => 'Try a Live Demo' ], $atts );

        $products = self::get_products();
        ob_start();
        ?>
        <div class="ccdemo-wrap" id="ccdemo-wrap">

            <!-- Step 1: product selection -->
            <div class="ccdemo-step" id="ccdemo-step-1">
                <h2 class="ccdemo-title"><?php echo esc_html( $atts['title'] ); ?></h2>
                <p class="ccdemo-subtitle">Choose a product and we'll send your private demo link instantly.</p>

                <div class="ccdemo-products">
                    <?php foreach ( $products as $slug => $label ) : ?>
                        <button type="button"
                                class="ccdemo-product-btn"
                                data-product="<?php echo esc_attr( $slug ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: lead form -->
            <div class="ccdemo-step ccdemo-hidden" id="ccdemo-step-2">
                <button type="button" class="ccdemo-back-btn" id="ccdemo-back">&larr; Back</button>
                <h2 class="ccdemo-title">Almost there!</h2>
                <p class="ccdemo-subtitle">
                    Your demo for <strong id="ccdemo-product-label"></strong> is ready.
                    Just fill in your details and we'll email the link.
                </p>

                <form id="ccdemo-form" novalidate>
                    <?php wp_nonce_field( 'ccdemo_request', 'ccdemo_nonce' ); ?>
                    <input type="hidden" name="product" id="ccdemo-product-input" value="">

                    <div class="ccdemo-field-row">
                        <div class="ccdemo-field">
                            <label for="ccdemo-name">Full Name <span>*</span></label>
                            <input type="text" id="ccdemo-name" name="name"
                                   placeholder="Jane Smith" required autocomplete="name">
                        </div>
                        <div class="ccdemo-field">
                            <label for="ccdemo-email">Work Email <span>*</span></label>
                            <input type="email" id="ccdemo-email" name="email"
                                   placeholder="jane@company.com" required autocomplete="email">
                        </div>
                    </div>

                    <div class="ccdemo-field-row">
                        <div class="ccdemo-field">
                            <label for="ccdemo-company">Company</label>
                            <input type="text" id="ccdemo-company" name="company"
                                   placeholder="Acme Inc." autocomplete="organization">
                        </div>
                        <div class="ccdemo-field">
                            <label for="ccdemo-phone">Phone</label>
                            <input type="tel" id="ccdemo-phone" name="phone"
                                   placeholder="+1 555 000 0000" autocomplete="tel">
                        </div>
                    </div>

                    <div id="ccdemo-error" class="ccdemo-error ccdemo-hidden"></div>

                    <button type="submit" class="ccdemo-submit-btn" id="ccdemo-submit">
                        <span class="ccdemo-btn-text">Send My Demo Link</span>
                        <span class="ccdemo-spinner ccdemo-hidden"></span>
                    </button>

                    <p class="ccdemo-privacy">
                        We respect your privacy. Your demo expires in
                        <?php echo esc_html( get_option( 'ccdemo_expiry_days', 2 ) ); ?> day(s) and is only accessible by you.
                    </p>
                </form>
            </div>

            <!-- Step 3: success message -->
            <div class="ccdemo-step ccdemo-hidden" id="ccdemo-step-3">
                <div class="ccdemo-success-icon">&#10003;</div>
                <h2 class="ccdemo-title">Check your inbox!</h2>
                <p class="ccdemo-subtitle" id="ccdemo-success-msg">
                    We've sent a demo link to your email address. Click the link to enter your private demo.
                    It will automatically expire after <?php echo esc_html( get_option( 'ccdemo_expiry_days', 2 ) ); ?> day(s).
                </p>
            </div>

        </div><!-- /.ccdemo-wrap -->
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler for form submission.
     */
    public function handle_submission(): void {
        check_ajax_referer( 'ccdemo_request', 'nonce' );

        $name    = sanitize_text_field( $_POST['name']    ?? '' );
        $email   = sanitize_email(      $_POST['email']   ?? '' );
        $company = sanitize_text_field( $_POST['company'] ?? '' );
        $phone   = sanitize_text_field( $_POST['phone']   ?? '' );
        $product = sanitize_text_field( $_POST['product'] ?? '' );

        // -- Validate required fields --
        if ( empty( $name ) || empty( $email ) || empty( $product ) ) {
            wp_send_json_error( [ 'message' => 'Please fill in all required fields.' ] );
        }

        if ( ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ] );
        }

        $valid_products = self::get_products();
        if ( ! array_key_exists( $product, $valid_products ) ) {
            wp_send_json_error( [ 'message' => 'Invalid product selection.' ] );
        }

        // -- Rate limit: 1 pending/active session per email per product --
        global $wpdb;
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . CCDEMO_TABLE .
            " WHERE email = %s AND product = %s AND status IN ('pending','active') AND expires_at > %s LIMIT 1",
            $email, $product, current_time( 'mysql' )
        ) );

        if ( $existing ) {
            wp_send_json_error( [ 'message' => 'A demo for this product is already active for your email. Check your inbox.' ] );
        }

        // -- Create temporary WP user --
        $expiry_days = (int) get_option( 'ccdemo_expiry_days', 2 );
        $expires_at  = gmdate( 'Y-m-d H:i:s', time() + $expiry_days * DAY_IN_SECONDS );

        $username = 'demo_' . strtolower( preg_replace( '/[^a-z0-9]/i', '', $name ) ) . '_' . wp_generate_password( 5, false );
        $password = wp_generate_password( 20, true );

        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            // Email already has a WP account — reuse it or use a unique username
            $username = 'demo_' . wp_generate_password( 10, false );
            $user_id  = wp_create_user( $username, $password, 'demo_' . $username . '@noreply.ccdemo' );
        }

        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Could not create demo account. Please try again.' ] );
        }

        // Assign demo role
        $user = new WP_User( $user_id );
        $user->set_role( 'ccdemo_user' );

        // Store expiry in user meta
        update_user_meta( $user_id, '_ccdemo_expires_at', $expires_at );
        update_user_meta( $user_id, '_ccdemo_product',    $product );
        update_user_meta( $user_id, '_ccdemo_real_email', $email );
        update_user_meta( $user_id, '_ccdemo_real_name',  $name );

        // -- Generate magic login token --
        $token = bin2hex( random_bytes( 32 ) );

        $session_id = CCDemo_DB::insert_session( [
            'name'       => $name,
            'email'      => $email,
            'company'    => $company,
            'phone'      => $phone,
            'product'    => $product,
            'token'      => $token,
            'user_id'    => $user_id,
            'expires_at' => $expires_at,
            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        ] );

        if ( ! $session_id ) {
            wp_delete_user( $user_id );
            wp_send_json_error( [ 'message' => 'Session creation failed. Please try again.' ] );
        }

        // -- Send email --
        $sent = CCDemo_Email::send_demo_link( [
            'name'        => $name,
            'email'       => $email,
            'product'     => $valid_products[ $product ],
            'token'       => $token,
            'expires_at'  => $expires_at,
            'expiry_days' => $expiry_days,
        ] );

        if ( ! $sent ) {
            // Don't block the user — session was created, email failed silently
            // Admin can resend from the dashboard
        }

        CCDemo_DB::update_session( $session_id, [ 'status' => 'pending' ] );

        wp_send_json_success( [
            'message' => sprintf(
                'Demo link sent to %s! Please check your inbox (and spam folder).',
                esc_html( $email )
            ),
        ] );
    }

    /**
     * Returns the list of available demo products.
     * Can be filtered via the ccdemo_products hook.
     */
    public static function get_products(): array {
        $default = get_option( 'ccdemo_products', [] );

        if ( empty( $default ) ) {
            $default = [
                'google-drive'    => 'Integration for Google Drive',
                'dropbox'         => 'Integration for Dropbox',
                'onedrive'        => 'Integration for OneDrive',
                'sharepoint'      => 'Integration for SharePoint',
            ];
        }

        return apply_filters( 'ccdemo_products', $default );
    }
}
