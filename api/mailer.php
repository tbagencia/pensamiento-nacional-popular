<?php
/**
 * Mail delivery with two drivers (see MAIL_DRIVER in config.php):
 *  - 'smtp': minimal SMTP client with STARTTLS + AUTH LOGIN. Used in
 *            development with Mailtrap (sandbox.smtp.mailtrap.io), works
 *            with any standard SMTP server. No Composer dependencies.
 *  - 'mail': PHP mail(), the right choice on shared hosting.
 */

require_once __DIR__ . '/config.php';

function send_email(string $to, string $subject, string $html): bool
{
    return MAIL_DRIVER === 'smtp'
        ? smtp_send($to, $subject, $html)
        : mail_send($to, $subject, $html);
}

/**
 * Shared email shell with the site identity: dark masthead with gold
 * eyebrow and flag band, warm body, pill CTA mirroring the site buttons.
 * Styles are inline so email clients keep them. $bodyHtml comes escaped.
 */
function email_layout(string $title, string $bodyHtml, ?string $ctaLabel = null, ?string $ctaUrl = null, string $footnote = ''): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $cta = '';
    if ($ctaLabel !== null && $ctaUrl !== null) {
        $safeLabel = htmlspecialchars($ctaLabel, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
        $cta = <<<HTML
        <p style="text-align: center; margin: 32px 0 8px;">
          <a href="$safeUrl" style="display: inline-block; background: #f6b40e; color: #1d3557; padding: 14px 40px; border-radius: 999px; text-decoration: none; font-weight: bold; font-size: 16px;">$safeLabel</a>
        </p>
        HTML;
    }

    $foot = '';
    if ($footnote !== '') {
        $safeFootnote = htmlspecialchars($footnote, ENT_QUOTES, 'UTF-8');
        $foot = <<<HTML
        <p style="color: #6e675c; font-size: 14px; margin: 24px 0 0;">$safeFootnote</p>
        HTML;
    }

    $siteName = htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8');

    return <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <body style="margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f7f3ea; padding: 32px 16px;">
      <div style="max-width: 520px; margin: 0 auto; background: #fffdf8; border-radius: 14px; overflow: hidden; border: 1px solid rgba(38, 34, 28, 0.08);">
        <div style="background: #1d3557; padding: 26px 32px 22px;">
          <p style="margin: 0 0 8px; color: #f6b40e; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px;">Archivo colaborativo &middot; 1810 &mdash; hoy</p>
          <h1 style="margin: 0; color: #ffffff; font-family: Georgia, 'Times New Roman', serif; font-size: 24px; line-height: 1.3;">$safeTitle</h1>
        </div>
        <div style="font-size: 0; line-height: 0;">
          <div style="height: 2px; background: #74acdf;"></div>
          <div style="height: 2px; background: #ffffff;"></div>
          <div style="height: 2px; background: #74acdf;"></div>
        </div>
        <div style="padding: 28px 32px 30px; color: #26221c; font-size: 16px; line-height: 1.65;">
          $bodyHtml
          $cta
          $foot
        </div>
      </div>
      <p style="max-width: 520px; margin: 16px auto 0; text-align: center; color: #6e675c; font-size: 12px;">$siteName</p>
    </body>
    </html>
    HTML;
}

/** Highlight box for quoted content (a document, a reason, a message). */
function email_box(string $innerHtml): string
{
    return <<<HTML
    <div style="background: #f7f3ea; border-left: 3px solid #f6b40e; border-radius: 0 10px 10px 0; padding: 14px 18px; margin: 16px 0;">$innerHtml</div>
    HTML;
}

/** Tells every configured moderator that a resource awaits review. */
function notify_moderators(array $resource): void
{
    $emails = moderator_emails();
    if (!$emails) {
        return;
    }

    $adminPath = env('ADMIN_PATH', '') ?? '';
    $adminUrl = base_url() . ($adminPath !== '' ? '/panel/' . $adminPath : '/admin/');
    $subject = 'Nuevo documento para moderar - ' . $resource['title'];
    $body = moderation_email_html($resource, $adminUrl);

    foreach ($emails as $email) {
        send_email($email, $subject, $body);
    }
}

function moderation_email_html(array $resource, string $adminUrl): string
{
    $title = htmlspecialchars($resource['title'], ENT_QUOTES, 'UTF-8');
    $author = htmlspecialchars($resource['author'], ENT_QUOTES, 'UTF-8');
    $year = (int) $resource['year'];

    $box = email_box("<strong>$title</strong><br>$author &middot; $year");
    return email_layout(
        'Nuevo documento pendiente',
        "<p style=\"margin: 0 0 4px;\">Se validó una nueva carga y espera moderación:</p>$box",
        'Ir al panel',
        $adminUrl
    );
}

/** Tells the submitter their document is now published, with its URL. */
function notify_submitter_approved(string $to, string $title, string $docUrl): bool
{
    return send_email($to, 'Tu aporte ya está publicado - ' . SITE_NAME, approved_email_html($title, $docUrl));
}

function approved_email_html(string $title, string $docUrl): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return email_layout(
        '¡Tu aporte fue aprobado!',
        "<p style=\"margin: 0;\">Tu documento <strong>&laquo;{$safeTitle}&raquo;</strong> ya forma parte de la línea de tiempo.</p>",
        'Ver el documento',
        $docUrl,
        'Gracias por aportar al archivo. Compartilo para que llegue a más gente.'
    );
}

/** Tells the submitter their document was not published, with the reason if given. */
function notify_submitter_rejected(string $to, string $title, string $reason): bool
{
    return send_email($to, 'Sobre tu aporte - ' . SITE_NAME, rejected_email_html($title, $reason));
}

function rejected_email_html(string $title, string $reason): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $reasonBlock = '';
    if (trim($reason) !== '') {
        $safeReason = nl2br(htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'));
        $reasonBlock = '<p style="margin: 16px 0 0;">El motivo que indicó el moderador:</p>'
            . email_box($safeReason);
    }

    return email_layout(
        'Tu aporte no fue publicado',
        "<p style=\"margin: 0;\">Revisamos tu documento <strong>&laquo;{$safeTitle}&raquo;</strong> y esta vez no va a formar parte de la línea de tiempo.</p>$reasonBlock",
        null,
        null,
        'Podés corregirlo y volver a enviarlo cuando quieras. Gracias por querer aportar al archivo.'
    );
}

/** Verification email body (the VALIDAR button). Sent from submit.php. */
function verification_email_html(string $title, string $verifyUrl): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    return email_layout(
        'Confirmación de carga',
        "<p style=\"margin: 0 0 12px;\">Recibimos tu aporte <strong>&laquo;{$safeTitle}&raquo;</strong> para la línea de tiempo.</p>"
        . '<p style="margin: 0;">Para terminar de confirmar la carga, hacé clic en el botón:</p>',
        'VALIDAR',
        $verifyUrl,
        'Después de validar, el contenido será revisado por un moderador antes de publicarse. Si no realizaste esta carga, ignorá este mensaje.'
    );
}

/** Visitor feedback email body. Sent from feedback.php to the moderators. */
function feedback_email_html(string $kindLabel, string $message, string $email, string $page): string
{
    $e = fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br($e($message));
    $safeFrom = $email !== '' ? $e($email) : 'No indicado';
    $safePage = $page !== '' ? $e($page) : 'No indicada';

    $box = email_box($safeMessage);
    return email_layout(
        $kindLabel,
        "<p style=\"margin: 0 0 4px;\">Un visitante dejó este mensaje en el sitio:</p>$box"
        . "<p style=\"color: #6e675c; font-size: 14px; margin: 16px 0 0;\">Email de contacto: $safeFrom<br>Página: $safePage</p>"
    );
}

function mail_send(string $to, string $subject, string $html): bool
{
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\r\n";

    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, $headers);
}

function smtp_send(string $to, string $subject, string $html): bool
{
    if (SMTP_USER === '' || SMTP_PASS === '') {
        error_log('[mailer] SMTP credentials not configured');
        return false;
    }

    $encryption = strtolower(env('SMTP_ENCRYPTION', 'tls'));
    $useImplicitTLS = in_array($encryption, ['ssl', 'ssltls', 'tls/ssl']);

    // Port 465 typically requires implicit TLS from the start.
    // Port 587 uses STARTTLS. Port 25 is unencrypted (not recommended).
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    if ($useImplicitTLS) {
        $address = 'tls://' . SMTP_HOST . ':' . SMTP_PORT;
    } else {
        $address = 'tcp://' . SMTP_HOST . ':' . SMTP_PORT;
    }

    $socket = @stream_socket_client($address, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log("[mailer] SMTP connection failed: $errstr ($errno) [address=$address]");
        return false;
    }
    stream_set_timeout($socket, 10);

    try {
        smtp_expect($socket, 220);
        smtp_command($socket, 'EHLO ' . (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost'), 250);

        // Only do STARTTLS if not already using implicit TLS
        if (!$useImplicitTLS && $encryption !== 'none') {
            $starttlsResponse = smtp_command_raw($socket, 'STARTTLS', 220);
            if (preg_match('/^220/', $starttlsResponse)) {
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                smtp_command($socket, 'EHLO ' . (parse_url(base_url(), PHP_URL_HOST) ?: 'localhost'), 250);
            }
        }

        smtp_command($socket, 'AUTH LOGIN', 334);
        smtp_command($socket, base64_encode(SMTP_USER), 334);
        smtp_command($socket, base64_encode(SMTP_PASS), 235);

        smtp_command($socket, 'MAIL FROM:<' . MAIL_FROM . '>', 250);
        smtp_command($socket, 'RCPT TO:<' . $to . '>', 250);
        smtp_command($socket, 'DATA', 354);

        $headers = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\r\n"
            . "To: <$to>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
            . 'Date: ' . date(DATE_RFC2822) . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . SMTP_HOST . ">\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n";

        // Escape lines starting with a dot (SMTP data terminator rule).
        $body = preg_replace('/^\./m', '..', $html);
        fwrite($socket, $headers . "\r\n" . $body . "\r\n.\r\n");
        smtp_expect($socket, 250);

        smtp_command($socket, 'QUIT', 221);
        return true;
    } catch (RuntimeException $e) {
        error_log('[mailer] SMTP error: ' . $e->getMessage());
        return false;
    } finally {
        fclose($socket);
    }
}

/** Sends a raw SMTP command and returns the full response (for STARTTLS check). */
function smtp_command_raw($socket, string $command, int $expectedCode): string
{
    fwrite($socket, $command . "\r\n");
    $response = '';
    do {
        $line = fgets($socket, 1024);
        if ($line === false) {
            throw new RuntimeException("no reply after '$command'");
        }
        $response .= $line;
        $more = ($line[3] ?? ' ') === '-';
    } while ($more);

    $code = (int) substr($response, 0, 3);
    if ($code !== $expectedCode) {
        $safeContext = str_starts_with($command, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $command)
            ? 'AUTH step' : $command;
        throw new RuntimeException("expected $expectedCode after '$safeContext', got $code");
    }
    return $response;
}

/** Sends a command and asserts the expected reply code. */
function smtp_command($socket, string $command, int $expectedCode): void
{
    fwrite($socket, $command . "\r\n");
    smtp_expect($socket, $expectedCode, $command);
}

/** Reads a (possibly multiline) SMTP reply and asserts its code. */
function smtp_expect($socket, int $expectedCode, string $context = 'greeting'): void
{
    $code = 0;
    do {
        $line = fgets($socket, 1024);
        if ($line === false) {
            throw new RuntimeException("no reply after '$context'");
        }
        $code = (int) substr($line, 0, 3);
        $more = ($line[3] ?? ' ') === '-';
    } while ($more);

    if ($code !== $expectedCode) {
        // Never log AUTH payloads (they contain credentials).
        $safeContext = str_starts_with($context, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $context)
            ? 'AUTH step' : $context;
        throw new RuntimeException("expected $expectedCode after '$safeContext', got $code");
    }
}
