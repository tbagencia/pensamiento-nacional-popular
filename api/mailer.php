<?php
/**
 * Mail delivery with two drivers (see MAIL_DRIVER in config.php):
 *  - 'smtp': minimal SMTP client with STARTTLS + AUTH LOGIN. Used in
 *            development with Mailtrap (sandbox.smtp.mailtrap.io), works
 *            with any standard SMTP server. No Composer dependencies.
 *  - 'mail': PHP mail(), the right choice on shared hosting (Hostinger).
 */

require_once __DIR__ . '/config.php';

function send_email(string $to, string $subject, string $html): bool
{
    return MAIL_DRIVER === 'smtp'
        ? smtp_send($to, $subject, $html)
        : mail_send($to, $subject, $html);
}

/** Tells every configured moderator that a resource awaits review. */
function notify_moderators(array $resource): void
{
    $emails = moderator_emails();
    if (!$emails) {
        return;
    }

    $title = htmlspecialchars($resource['title'], ENT_QUOTES, 'UTF-8');
    $author = htmlspecialchars($resource['author'], ENT_QUOTES, 'UTF-8');
    $year = (int) $resource['year'];
    $adminPath = env('ADMIN_PATH', '') ?? '';
    $adminUrl = base_url() . ($adminPath !== '' ? '/panel/' . $adminPath : '/admin/');
    $subject = 'Nuevo documento para moderar - ' . $resource['title'];

    $body = <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <body style="font-family: Arial, sans-serif; background: #f4f1ea; padding: 24px;">
      <div style="max-width: 520px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 32px;">
        <h2 style="color: #1d3557; margin-top: 0;">Nuevo documento pendiente</h2>
        <p>Se validó una nueva carga y espera moderación:</p>
        <p style="background: #f7f3ea; border-radius: 6px; padding: 16px;">
          <strong>$title</strong><br>
          $author · $year
        </p>
        <p style="text-align: center; margin: 32px 0;">
          <a href="$adminUrl" style="background: #1d6fb8; color: #fff; padding: 14px 40px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 16px;">Ir al panel</a>
        </p>
      </div>
    </body>
    </html>
    HTML;

    foreach ($emails as $email) {
        send_email($email, $subject, $body);
    }
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
