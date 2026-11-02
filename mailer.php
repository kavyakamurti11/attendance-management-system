<?php
/* mailer.php - minimal SMTP sender (no libraries needed). MAIL_MODE 'log' writes to a file. */
require_once __DIR__ . '/config.php';

function mail_log(string $to, string $subject, string $body): void {
    $line = '[' . date('Y-m-d H:i:s') . "] TO: $to | SUBJECT: $subject\n$body\n" . str_repeat('-', 50) . "\n";
    @file_put_contents(__DIR__ . '/mail_outbox.log', $line, FILE_APPEND | LOCK_EX);
}

function smtp_expect($fp, array $ok): string {
    $resp = '';
    while (($l = fgets($fp, 515)) !== false) {
        $resp .= $l;
        if (strlen($l) < 4 || $l[3] === ' ') break;
    }
    if (!in_array((int)substr($resp, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($resp));
    return $resp;
}
function smtp_cmd($fp, string $cmd, array $ok): string { fwrite($fp, $cmd . "\r\n"); return smtp_expect($fp, $ok); }

function send_mail(string $to, string $subject, string $body): bool {
    if (MAIL_MODE !== 'smtp') { mail_log($to, $subject, $body); return true; }
    try {
        $from = MAIL_FROM !== '' ? MAIL_FROM : SMTP_USER;
        if (SMTP_USER === '' || SMTP_PASS === '' || $from === '') throw new RuntimeException('Fill SMTP_USER / SMTP_PASS in config.php.');
        $fp = @stream_socket_client((SMTP_PORT == 465 ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT, $en, $es, 15);
        if (!$fp) throw new RuntimeException("Connect failed: $es");
        stream_set_timeout($fp, 15);
        smtp_expect($fp, [220]);
        smtp_cmd($fp, 'EHLO localhost', [250]);
        if (SMTP_PORT != 465) {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS failed');
            smtp_cmd($fp, 'EHLO localhost', [250]);
        }
        smtp_cmd($fp, 'AUTH LOGIN', [334]);
        smtp_cmd($fp, base64_encode(SMTP_USER), [334]);
        smtp_cmd($fp, base64_encode(SMTP_PASS), [235]);
        smtp_cmd($fp, "MAIL FROM:<$from>", [250]);
        smtp_cmd($fp, "RCPT TO:<$to>", [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);
        $headers = "From: =?UTF-8?B?" . base64_encode(MAIL_FROM_NAME) . "?= <$from>\r\nTo: <$to>\r\n"
                 . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $msg = $headers . "\r\n" . chunk_split(base64_encode($body));
        fwrite($fp, $msg . "\r\n.\r\n");
        smtp_expect($fp, [250]);
        @smtp_cmd($fp, 'QUIT', [221]);
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('[attendance mail] ' . $e->getMessage());
        return false;
    }
}
