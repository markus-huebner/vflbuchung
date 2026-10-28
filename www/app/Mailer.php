<?php
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class Mailer
{
    public function __construct(private readonly Config $config)
    {
    }

    public function send(string $recipient, string $subject, string $html, string $text): void
    {
        $from = $this->config->get('MAIL_FROM');
        if ($from === '') {
            throw new RuntimeException('Der E-Mail-Versand ist nicht konfiguriert.');
        }
        if (!function_exists('mail')) {
            error_log('Mailversand fehlgeschlagen: PHP mail() ist nicht verfügbar.');
            throw new RuntimeException('Die PHP-Funktion mail() ist auf dem Webserver nicht verfügbar. Bitte kontaktiere den Hoster.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isMail();
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, $this->config->get('MAIL_FROM_NAME'));
            if ($this->config->has('MAIL_REPLY_TO') && $this->config->get('MAIL_REPLY_TO') !== '') {
                $mail->addReplyTo($this->config->get('MAIL_REPLY_TO'));
            }
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
        } catch (Exception $exception) {
            error_log('Mailversand fehlgeschlagen: ' . $exception->getMessage());
            throw new RuntimeException(
                'Die E-Mail konnte nicht über PHP mail() an den Maildienst übergeben werden. Prüfe die Absenderadresse und die Mail-Konfiguration des Webservers.',
                0,
                $exception
            );
        }
    }
}
