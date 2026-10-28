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
        $mail = $this->configuredMailer();
        try {
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text;
            $mail->send();
        } catch (Exception $exception) {
            error_log('Mailversand fehlgeschlagen: ' . $exception->getMessage());
            throw new RuntimeException('Die E-Mail konnte nicht versendet werden.', 0, $exception);
        }
    }

    public function testConnection(): void
    {
        $mail = $this->configuredMailer();
        $mail->Timeout = 10;
        $mail->getSMTPInstance()->Timelimit = 10;
        try {
            $mail->smtpConnect();
        } catch (Exception $exception) {
            error_log('SMTP connection setup validation failed: ' . $exception->getMessage());
            throw new RuntimeException(
                'Die Verbindung zum Mail-Server ist fehlgeschlagen. Prüfe SMTP-Host, Port, Verschlüsselung, Benutzer und Passwort.',
                0,
                $exception
            );
        } finally {
            $mail->smtpClose();
        }
    }

    private function configuredMailer(): PHPMailer
    {
        $host = $this->config->get('MAIL_HOST');
        $from = $this->config->get('MAIL_FROM');
        if ($host === '' || $from === '') {
            throw new RuntimeException('Der E-Mail-Versand ist nicht konfiguriert.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) $this->config->get('MAIL_PORT');
            $mail->SMTPAuth = $this->config->get('MAIL_USERNAME') !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $this->config->get('MAIL_USERNAME');
                $mail->Password = $this->config->get('MAIL_PASSWORD');
            }

            $encryption = strtolower($this->config->get('MAIL_ENCRYPTION'));
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from, $this->config->get('MAIL_FROM_NAME'));
        } catch (Exception $exception) {
            error_log('Mailversand fehlgeschlagen: ' . $exception->getMessage());
            throw new RuntimeException('Die E-Mail konnte nicht versendet werden.', 0, $exception);
        }

        return $mail;
    }
}
