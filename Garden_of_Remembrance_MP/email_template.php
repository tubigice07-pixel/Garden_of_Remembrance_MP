<?php

if (!function_exists('get_app_base_url')) {
    function get_app_base_url(): string {
        if (defined('APP_BASE_URL') && !empty(APP_BASE_URL)) {
            return rtrim(APP_BASE_URL, '/\\');
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($host)) {
            return 'http://localhost/Garden_of_Remembrance_MP';
        }

        $scheme = 'http';
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'];
        }

        $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));
        $dir = preg_replace('#/(sections|api|setup|includes|config|cron)$#i', '', $dir);
        if ($dir === '/' || $dir === '.') {
            $dir = '';
        }

        return $scheme . '://' . $host . $dir;
    }
}

if (!function_exists('get_email_asset_base_url')) {
    function get_email_asset_base_url(): string {
        if (defined('EMAIL_ASSET_BASE_URL') && !empty(EMAIL_ASSET_BASE_URL)) {
            return rtrim(EMAIL_ASSET_BASE_URL, '/\\');
        }
        return get_app_base_url();
    }
}

function render_branded_email(array $params): string {
    $baseUrl = get_email_asset_base_url();

    $logoUrl = 'https://gardenofremembrance.gamer.gd/cmis_logo.png';
    if (!empty($baseUrl) && strpos($baseUrl, 'localhost') === false && strpos($baseUrl, '127.0.0.1') === false) {
        $logoUrl = rtrim($baseUrl, '/') . '/cmis_logo.png';
    }
    $year = date('Y');

    $title = htmlspecialchars($params['title'] ?? 'Garden of Remembrance Notification', ENT_QUOTES, 'UTF-8');
    $badge = isset($params['badge']) && $params['badge'] !== '' ? htmlspecialchars($params['badge'], ENT_QUOTES, 'UTF-8') : 'Official Notification';
    $greetingName = isset($params['greeting_name']) && $params['greeting_name'] !== '' ? htmlspecialchars(trim($params['greeting_name']), ENT_QUOTES, 'UTF-8') : 'Valued Customer';

    $introHtml = '';
    if (!empty($params['intro_lines']) && is_array($params['intro_lines'])) {
        foreach ($params['intro_lines'] as $line) {
            $safeLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

            $safeLine = str_ireplace(
                ['&lt;strong&gt;', '&lt;/strong&gt;', '&lt;b&gt;', '&lt;/b&gt;', '&lt;em&gt;', '&lt;/em&gt;', '&lt;i&gt;', '&lt;/i&gt;', '&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'],
                ['<strong>', '</strong>', '<b>', '</b>', '<em>', '</em>', '<i>', '</i>', '<br>', '<br>', '<br>'],
                $safeLine
            );
            $introHtml .= '<p style="margin: 0 0 14px 0; font-size: 15px; line-height: 1.65; color: #374151;">' . $safeLine . '</p>';
        }
    }

    $otpHtml = '';
    if (!empty($params['otp_code'])) {
        $otp = htmlspecialchars(trim((string)$params['otp_code']), ENT_QUOTES, 'UTF-8');
        $expiry = isset($params['otp_expiry_minutes']) ? (int)$params['otp_expiry_minutes'] : 10;
        $otpHtml = <<<HTML
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 22px 0; background-color: #fdf8f6; border: 1.5px dashed #7b1113; border-radius: 12px; text-align: center;">
          <tr>
            <td style="padding: 22px 16px;">
              <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.15em; font-weight: 700; color: #7b1113; margin-bottom: 8px;">Your Verification Code</div>
              <div style="font-family: 'Courier New', Courier, monospace, sans-serif; font-size: 34px; font-weight: 800; letter-spacing: 0.35em; color: #7b1113; padding: 6px 0; text-indent: 0.35em;">$otp</div>
              <div style="font-size: 13px; color: #6b7280; margin-top: 8px;">This verification code will expire in <strong>$expiry minutes</strong>.</div>
            </td>
          </tr>
        </table>
HTML;
    }

    $detailsHtml = '';
    if (!empty($params['details_table']) && is_array($params['details_table'])) {
        $secTitle = htmlspecialchars($params['details_section_title'] ?? 'Details', ENT_QUOTES, 'UTF-8');
        $rowsHtml = '';
        $i = 0;
        foreach ($params['details_table'] as $k => $v) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f9fafb';
            $kEsc = htmlspecialchars($k, ENT_QUOTES, 'UTF-8');
            $vEsc = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $rowsHtml .= <<<HTML
            <tr style="background-color: $bg;">
              <td style="padding: 10px 14px; font-size: 13.5px; font-weight: 600; color: #4b5563; width: 42%; border-bottom: 1px solid #e5e7eb;">$kEsc</td>
              <td style="padding: 10px 14px; font-size: 13.5px; font-weight: 700; color: #111827; border-bottom: 1px solid #e5e7eb;">$vEsc</td>
            </tr>
HTML;
            $i++;
        }
        $detailsHtml = <<<HTML
        <div style="margin: 22px 0 16px 0;">
          <h3 style="margin: 0 0 10px 0; font-size: 16px; font-weight: 700; color: #7b1113; text-transform: uppercase; letter-spacing: 0.05em;">$secTitle</h3>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; border-collapse: separate; border-spacing: 0;">
            $rowsHtml
          </table>
        </div>
HTML;
    }

    $paymentHtml = '';
    if (!empty($params['payment_table']) && is_array($params['payment_table'])) {
        $payTitle = htmlspecialchars($params['payment_summary_title'] ?? 'Payment Summary', ENT_QUOTES, 'UTF-8');
        $payRows = '';
        $j = 0;
        foreach ($params['payment_table'] as $k => $v) {
            $bg = ($j % 2 === 0) ? '#ffffff' : '#f9fafb';
            $kEsc = htmlspecialchars($k, ENT_QUOTES, 'UTF-8');
            $vEsc = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $isRemaining = (stripos($k, 'Remaining') !== false || stripos($k, 'Balance') !== false);
            $isPaid = (stripos($k, 'Amount Paid') !== false || stripos($k, 'Total Paid') !== false);
            $valStyle = 'font-weight: 700; color: #111827;';
            if ($isRemaining) {
                $valStyle = 'font-weight: 800; color: #7b1113;';
            } elseif ($isPaid) {
                $valStyle = 'font-weight: 800; color: #15803d;';
            }
            $payRows .= <<<HTML
            <tr style="background-color: $bg;">
              <td style="padding: 10px 14px; font-size: 13.5px; font-weight: 600; color: #4b5563; width: 42%; border-bottom: 1px solid #e5e7eb;">$kEsc</td>
              <td style="padding: 10px 14px; font-size: 13.5px; $valStyle border-bottom: 1px solid #e5e7eb;">$vEsc</td>
            </tr>
HTML;
            $j++;
        }
        $paymentHtml = <<<HTML
        <div style="margin: 20px 0 16px 0;">
          <h3 style="margin: 0 0 10px 0; font-size: 16px; font-weight: 700; color: #7b1113; text-transform: uppercase; letter-spacing: 0.05em;">$payTitle</h3>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; border-collapse: separate; border-spacing: 0;">
            $payRows
          </table>
        </div>
HTML;
    }

    $buttonHtml = '';
    if (!empty($params['action_button']['text']) && !empty($params['action_button']['url'])) {
        $btnText = htmlspecialchars($params['action_button']['text'], ENT_QUOTES, 'UTF-8');
        $btnUrl = htmlspecialchars($params['action_button']['url'], ENT_QUOTES, 'UTF-8');
        $buttonHtml = <<<HTML
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 26px 0 18px 0; text-align: center;">
          <tr>
            <td align="center">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="$btnUrl" style="height:48px;v-text-anchor:middle;width:240px;" arcsize="18%" fillcolor="#7b1113" stroke="f">
                <w:anchorlock/>
                <center style="color:#ffffff;font-family:sans-serif;font-size:15px;font-weight:bold;">$btnText</center>
              </v:roundrect>
              <![endif]-->
              <a href="$btnUrl" target="_blank" style="display: inline-block; background-color: #7b1113; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 700; padding: 13px 32px; border-radius: 8px; box-shadow: 0 4px 12px rgba(123, 17, 19, 0.28); letter-spacing: 0.02em;">$btnText</a>
            </td>
          </tr>
        </table>
HTML;
    }

    $outroHtml = '';
    if (!empty($params['outro_lines']) && is_array($params['outro_lines'])) {
        foreach ($params['outro_lines'] as $line) {
            $safeLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

            $safeLine = str_ireplace(
                ['&lt;strong&gt;', '&lt;/strong&gt;', '&lt;b&gt;', '&lt;/b&gt;', '&lt;em&gt;', '&lt;/em&gt;', '&lt;i&gt;', '&lt;/i&gt;', '&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'],
                ['<strong>', '</strong>', '<b>', '</b>', '<em>', '</em>', '<i>', '</i>', '<br>', '<br>', '<br>'],
                $safeLine
            );
            $safeLine = preg_replace_callback('/&lt;a\s+href=(&quot;|\')(.*?)\1(.*?)&gt;(.*?)&lt;\/a&gt;/i', function($m) {
                return '<a href="' . $m[2] . '" style="color: #7b1113; text-decoration: underline; font-weight: 600;"' . $m[3] . '>' . $m[4] . '</a>';
            }, $safeLine);
            $outroHtml .= '<p style="margin: 10px 0; font-size: 14px; line-height: 1.6; color: #4b5563;">' . $safeLine . '</p>';
        }
    }

    $cancelHtml = '';
    if (!empty($params['cancel_action']['url'])) {
        $canUrl = htmlspecialchars($params['cancel_action']['url'], ENT_QUOTES, 'UTF-8');
        $canText = !empty($params['cancel_action']['text']) ? htmlspecialchars($params['cancel_action']['text'], ENT_QUOTES, 'UTF-8') : 'Cancel Reservation Request';
        $cancelHtml = <<<HTML
        <div style="margin: 26px 0 16px 0; padding: 18px 20px; background-color: #fef2f2; border: 1.5px solid #fca5a5; border-radius: 10px; font-size: 13px; color: #991b1b; text-align: left;">
          <div style="font-weight: 800; font-size: 14.5px; margin-bottom: 6px; color: #991b1b; letter-spacing: -0.01em;">
            Need to Cancel this Reservation?
          </div>
          <div style="margin-bottom: 12px; line-height: 1.5; color: #7f1d1d; font-size: 13px;">
            If your circumstances have changed or you wish to cancel this reservation request, you may submit a formal cancellation request below:
          </div>
          <div style="margin-bottom: 12px;">
            <a href="$canUrl" target="_blank" style="display: inline-block; background-color: #dc2626; color: #ffffff; text-decoration: none; font-size: 13px; font-weight: 700; padding: 9px 20px; border-radius: 6px; letter-spacing: 0.01em; box-shadow: 0 2px 6px rgba(220, 38, 38, 0.25);">$canText &rarr;</a>
          </div>
          <div style="font-size: 11.5px; line-height: 1.45; color: #991b1b; background-color: #fee2e2; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #dc2626;">
            <strong>*Strictly No Refund Policy:</strong> In accordance with park guidelines, all paid downpayments and reservation fees are non-refundable upon cancellation. Submitted cancellation requests will be sent to cemetery administration for review and plot release.
          </div>
        </div>
HTML;
    }

    $footerNote = !empty($params['footer_note']) ? htmlspecialchars($params['footer_note'], ENT_QUOTES, 'UTF-8') : 'This is an automated notification. Please do not reply directly to this email.';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>$title</title>
  <!--[if mso]>
  <style type="text/css">
    body, table, td, h1, h2, h3, p, a, div { font-family: Arial, sans-serif !important; }
  </style>
  <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6fb; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased; color: #2b211f;">

  <!-- Outer Container Table -->
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f6fb; padding: 30px 12px;">
    <tr>
      <td align="center">

        <!-- Main Email Card (Max 600px) -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 35px rgba(0,0,0,0.08); border: 1px solid #e2e8f0;">

          <!-- Top Branded Header -->
          <tr>
            <td style="background: linear-gradient(145deg, #7b1113 0%, #470d0f 100%); background-color: #7b1113; padding: 32px 24px 28px 24px; text-align: center; color: #ffffff;">
              <!-- Cemetery Logo Badge -->
              <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin: 0 auto 14px auto;">
                <tr>
                  <td align="center" style="background-color: #ffffff; width: 88px; height: 88px; border-radius: 50%; padding: 4px; box-shadow: 0 4px 16px rgba(0,0,0,0.35); text-align: center;">
                    <img src="$logoUrl" alt="Garden of Remembrance Logo" width="80" height="80" style="display: block; margin: 0 auto; width: 80px; height: 80px; max-width: 80px; border-radius: 50%; object-fit: contain; border: 0;">
                  </td>
                </tr>
              </table>

              <div style="display: inline-block; background-color: rgba(255,255,255,0.18); border-radius: 999px; padding: 4px 14px; font-size: 11px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; color: #ffffff; margin-bottom: 8px;">
                $badge
              </div>

              <h1 style="margin: 0 0 4px 0; font-size: 22px; font-weight: 800; letter-spacing: -0.01em; color: #ffffff; text-shadow: 0 1px 2px rgba(0,0,0,0.3);">
                Garden of Remembrance
              </h1>
              <div style="font-size: 13px; font-weight: 600; color: #fdf2f2; letter-spacing: 0.06em; text-transform: uppercase;">
                Memorial Park
              </div>
              <div style="font-size: 11.5px; color: rgba(255,255,255,0.78); margin-top: 3px;">
                Cemetery Management and Grave Mapping System
              </div>
            </td>
          </tr>

          <!-- Body Content Area -->
          <tr>
            <td style="padding: 32px 28px 28px 28px; background-color: #ffffff;">

              <!-- Title & Greeting -->
              <h2 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 800; color: #111827; letter-spacing: -0.01em;">$title</h2>
              <div style="font-size: 16px; font-weight: 700; color: #7b1113; margin-bottom: 16px;">Hello, $greetingName,</div>

              $introHtml
              $otpHtml
              $detailsHtml
              $paymentHtml
              $buttonHtml
              $outroHtml
              $cancelHtml

            </td>
          </tr>

          <!-- Footer Area -->
          <tr>
            <td style="background-color: #f9fafb; padding: 24px 28px; text-align: center; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 12px; line-height: 1.6;">
              <div style="font-weight: 700; color: #374151; margin-bottom: 4px;">Garden of Remembrance Memorial Park</div>
              <div style="color: #6b7280; margin-bottom: 12px;">Cemetery Management and Grave Mapping System</div>
              <div style="font-size: 11.5px; color: #9ca3af; padding-top: 8px; border-top: 1px solid #f3f4f6;">
                $footerNote<br>
                &copy; $year Garden of Remembrance. All rights reserved.
              </div>
            </td>
          </tr>

        </table>

      </td>
    </tr>
  </table>

</body>
</html>
HTML;
}
