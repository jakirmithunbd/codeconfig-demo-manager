<?php
/**
 * Email — runs on codeconfig.dev
 *
 * Sends the branded HTML magic-link email and admin notifications.
 * The magic_link is provided by the demo site API — this class only sends.
 */
defined( 'ABSPATH' ) || exit;

class CCDemo_Email {

    /**
     * Send the demo access email to the lead.
     *
     * @param array{
     *   name: string,
     *   email: string,
     *   product: string,
     *   magic_link: string,
     *   expires_at: string,
     *   expiry_days: int
     * } $data
     */
    public static function send_demo_link( array $data ): bool {
        $site_name  = get_bloginfo( 'name' );
        $from_name  = get_option( 'ccdemo_from_name',  $site_name );
        $from_email = get_option( 'ccdemo_from_email', get_option( 'admin_email' ) );

        // Ensure magic link is HTTPS
        $magic_link = set_url_scheme( $data['magic_link'], 'https' );

        $expires_human = date_i18n(
            get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ),
            strtotime( $data['expires_at'] )
        );

        $subject = sprintf( '[%s] Your %s Demo is Ready', $site_name, $data['product'] );
        $body    = self::build_html_body( array_merge( $data, [
            'magic_link'    => $magic_link,
            'site_name'     => $site_name,
            'expires_human' => $expires_human,
        ] ) );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
            "Reply-To: {$from_email}",
            'X-Mailer: CodeConfig Demo Manager ' . CCDEMO_VERSION,
        ];

        return wp_mail( $data['email'], $subject, $body, $headers );
    }

    /**
     * Non-blocking admin notification fired on shutdown.
     */
    public static function send_admin_notification( array $data ): void {
        if ( ! (bool) get_option( 'ccdemo_admin_notify', 1 ) ) {
            return;
        }

        $admin_email = get_option( 'admin_email' );
        $site_name   = get_bloginfo( 'name' );

        $subject = sprintf( '[%s] New Demo Request: %s — %s', $site_name, $data['product'], $data['name'] );
        $body    = implode( "\n", [
            "New demo request received.",
            "",
            "Name:    {$data['name']}",
            "Email:   {$data['email']}",
            "Company: " . ( $data['company'] ?: '—' ),
            "Phone:   " . ( $data['phone'] ?: '—' ),
            "Product: {$data['product']}",
            "IP:      " . ( $data['ip'] ?: '—' ),
            "Time:    " . current_time( 'mysql' ),
            "",
            "View all sessions: " . admin_url( 'admin.php?page=ccdemo-manager' ),
        ] );

        wp_mail( $admin_email, $subject, $body );
    }

    /* ------------------------------------------------------------------
     * HTML email template
     * ------------------------------------------------------------------ */

    private static function build_html_body( array $d ): string {
        $logo_url      = esc_url( get_option( 'ccdemo_email_logo', '' ) );
        $primary_color = sanitize_hex_color( get_option( 'ccdemo_primary_color', '#4F46E5' ) ) ?: '#4F46E5';
        $magic_link    = esc_url( $d['magic_link'] );
        $site_url      = esc_url( home_url() );

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>Your Demo Access Link</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,sans-serif;-webkit-font-smoothing:antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:40px 16px;">
<tr><td align="center">

  <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.08);">

    <!-- Header -->
    <tr>
      <td style="background:<?php echo $primary_color; ?>;padding:28px 40px;text-align:center;">
        <?php if ( $logo_url ) : ?>
          <img src="<?php echo $logo_url; ?>" alt="<?php echo esc_attr( $d['site_name'] ); ?>" height="36" style="display:block;margin:0 auto;border:0;">
        <?php else : ?>
          <p style="margin:0;color:#ffffff;font-size:20px;font-weight:700;letter-spacing:-0.3px;">
            <?php echo esc_html( $d['site_name'] ); ?>
          </p>
        <?php endif; ?>
      </td>
    </tr>

    <!-- Body -->
    <tr>
      <td style="padding:36px 40px 28px;">

        <h1 style="margin:0 0 10px;font-size:22px;color:#111827;font-weight:700;line-height:1.3;">
          Hi <?php echo esc_html( $d['name'] ); ?>, your demo is ready &#127881;
        </h1>
        <p style="margin:0 0 24px;color:#6b7280;font-size:15px;line-height:1.65;">
          You requested a live demo for
          <strong style="color:#111827;"><?php echo esc_html( $d['product'] ); ?></strong>.
          Use the button below to access your private, time-limited demo dashboard.
        </p>

        <!-- CTA -->
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
          <tr>
            <td style="border-radius:8px;background:<?php echo $primary_color; ?>;">
              <a href="<?php echo $magic_link; ?>"
                 style="display:inline-block;padding:13px 30px;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;border-radius:8px;line-height:1;">
                Enter My Demo &rarr;
              </a>
            </td>
          </tr>
        </table>

        <!-- Info tiles -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
               style="background:#f9fafb;border-radius:8px;border:1px solid #e5e7eb;margin-bottom:24px;">
          <tr>
            <td style="padding:18px 22px;">
              <p style="margin:0 0 10px;font-size:14px;color:#374151;line-height:1.55;">
                &#128274;&nbsp;<strong>One-click access</strong> &mdash; this link logs you in automatically. No password needed.
              </p>
              <p style="margin:0 0 10px;font-size:14px;color:#374151;line-height:1.55;">
                &#128197;&nbsp;<strong>Expires on</strong> <?php echo esc_html( $d['expires_human'] ); ?>
                (<?php echo (int) $d['expiry_days']; ?> day<?php echo (int) $d['expiry_days'] !== 1 ? 's' : ''; ?> from now).
              </p>
              <p style="margin:0;font-size:14px;color:#374151;line-height:1.55;">
                &#128465;&nbsp;<strong>Auto-deleted</strong> &mdash; your demo account is removed automatically at expiry.
              </p>
            </td>
          </tr>
        </table>

        <p style="margin:0 0 4px;font-size:12px;color:#9ca3af;">Button not working? Paste this URL into your browser:</p>
        <p style="margin:0;font-size:12px;word-break:break-all;">
          <a href="<?php echo $magic_link; ?>" style="color:<?php echo $primary_color; ?>;">
            <?php echo esc_html( $d['magic_link'] ); ?>
          </a>
        </p>

      </td>
    </tr>

    <!-- Footer -->
    <tr>
      <td style="background:#f9fafb;padding:20px 40px;text-align:center;border-top:1px solid #e5e7eb;">
        <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.6;">
          You received this because you requested a demo at
          <a href="<?php echo $site_url; ?>" style="color:<?php echo $primary_color; ?>;"><?php echo esc_html( $d['site_name'] ); ?></a>.
          If this wasn&rsquo;t you, you can safely ignore this email.
        </p>
      </td>
    </tr>

  </table>

</td></tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
}
