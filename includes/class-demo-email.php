<?php
defined( 'ABSPATH' ) || exit;

class CCDemo_Email {

    /**
     * Send the magic-link demo email to the lead.
     *
     * @param array $data {
     *   string $name, string $email, string $product,
     *   string $token, string $expires_at, int $expiry_days
     * }
     */
    public static function send_demo_link( array $data ): bool {
        $magic_link = add_query_arg(
            [ 'ccdemo_token' => $data['token'] ],
            home_url( '/' )
        );

        $site_name  = get_bloginfo( 'name' );
        $from_name  = get_option( 'ccdemo_from_name',  $site_name );
        $from_email = get_option( 'ccdemo_from_email', get_option( 'admin_email' ) );

        $expires_human = date_i18n(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            strtotime( $data['expires_at'] )
        );

        $subject = sprintf(
            '[%s] Your %s Demo is Ready',
            $site_name,
            $data['product']
        );

        $body = self::build_html_body( array_merge( $data, [
            'magic_link'    => $magic_link,
            'site_name'     => $site_name,
            'expires_human' => $expires_human,
        ] ) );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        ];

        return wp_mail( $data['email'], $subject, $body, $headers );
    }

    /**
     * Build the HTML email body.
     */
    private static function build_html_body( array $d ): string {
        $logo_url    = get_option( 'ccdemo_email_logo', '' );
        $primary_color = get_option( 'ccdemo_primary_color', '#4F46E5' );

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your Demo Link</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 20px;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">

        <!-- Header -->
        <tr>
          <td style="background:<?php echo esc_attr( $primary_color ); ?>;padding:32px 40px;text-align:center;">
            <?php if ( $logo_url ) : ?>
              <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $d['site_name'] ); ?>" height="40" style="display:block;margin:0 auto;">
            <?php else : ?>
              <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;letter-spacing:-0.5px;">
                <?php echo esc_html( $d['site_name'] ); ?>
              </h1>
            <?php endif; ?>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:40px 40px 32px;">
            <h2 style="margin:0 0 12px;font-size:24px;color:#111827;">
              Hi <?php echo esc_html( $d['name'] ); ?>, your demo is ready! &#127881;
            </h2>
            <p style="margin:0 0 24px;color:#6b7280;font-size:16px;line-height:1.6;">
              You requested a live demo for <strong style="color:#111827;"><?php echo esc_html( $d['product'] ); ?></strong>.
              Click the button below to instantly access your private demo dashboard.
            </p>

            <!-- CTA Button -->
            <table cellpadding="0" cellspacing="0" style="margin:0 0 32px;">
              <tr>
                <td style="background:<?php echo esc_attr( $primary_color ); ?>;border-radius:8px;">
                  <a href="<?php echo esc_url( $d['magic_link'] ); ?>"
                     style="display:inline-block;padding:14px 32px;color:#fff;font-size:16px;font-weight:600;text-decoration:none;letter-spacing:0.2px;">
                    Enter My Demo &rarr;
                  </a>
                </td>
              </tr>
            </table>

            <!-- Info box -->
            <table cellpadding="0" cellspacing="0" width="100%" style="background:#f9fafb;border-radius:8px;margin-bottom:28px;">
              <tr>
                <td style="padding:20px 24px;">
                  <p style="margin:0 0 10px;font-size:14px;color:#374151;">
                    <strong>&#128274; One-time magic link</strong> — this link logs you in automatically. No password needed.
                  </p>
                  <p style="margin:0 0 10px;font-size:14px;color:#374151;">
                    <strong>&#128197; Expires on</strong> <?php echo esc_html( $d['expires_human'] ); ?> (<?php echo (int) $d['expiry_days']; ?> days from now).
                  </p>
                  <p style="margin:0;font-size:14px;color:#374151;">
                    <strong>&#128465; Auto-deleted</strong> — your demo account and all data will be automatically removed after expiry.
                  </p>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 8px;font-size:13px;color:#9ca3af;">
              If the button doesn't work, paste this URL into your browser:
            </p>
            <p style="margin:0;font-size:13px;word-break:break-all;">
              <a href="<?php echo esc_url( $d['magic_link'] ); ?>"
                 style="color:<?php echo esc_attr( $primary_color ); ?>;">
                <?php echo esc_html( $d['magic_link'] ); ?>
              </a>
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#f9fafb;padding:24px 40px;text-align:center;border-top:1px solid #e5e7eb;">
            <p style="margin:0;font-size:13px;color:#9ca3af;">
              You received this email because you requested a demo on
              <a href="<?php echo esc_url( home_url() ); ?>" style="color:<?php echo esc_attr( $primary_color ); ?>;">
                <?php echo esc_html( $d['site_name'] ); ?>
              </a>.
              If this wasn't you, simply ignore this email.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Notify the site admin when a new demo is requested.
     */
    public static function send_admin_notification( array $data ): void {
        if ( ! get_option( 'ccdemo_admin_notify', 1 ) ) {
            return;
        }

        $admin_email = get_option( 'admin_email' );
        $subject     = sprintf( '[Demo Request] %s — %s', $data['product'], $data['name'] );
        $body        = sprintf(
            "New demo request:\n\nName: %s\nEmail: %s\nCompany: %s\nPhone: %s\nProduct: %s\nIP: %s\nTime: %s",
            $data['name'], $data['email'], $data['company'] ?? '—',
            $data['phone'] ?? '—', $data['product'],
            $_SERVER['REMOTE_ADDR'] ?? '—', current_time( 'mysql' )
        );

        wp_mail( $admin_email, $subject, $body );
    }
}
