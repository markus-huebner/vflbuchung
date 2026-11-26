<?php
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

final class Mailer
{
    public static function header(string $heading, string $icon): string
    {
        return '<!doctype html><html lang="de"><body style="margin:0;padding:32px 12px;background:#eef0ec;'
            . 'font-family:Arial,Helvetica,sans-serif;color:#16120f">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
            . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;'
            . 'width:100%;background:#fff;border-radius:16px;overflow:hidden">'
            . '<tr><td style="padding:28px 32px;background:#141210;border-bottom:3px solid #e1141b;color:#fff">'
            . '<img src="cid:vfl-fussball-logo" alt="VfL Kaufering Fußball 1948" width="285" height="44" '
            . 'style="display:block;width:285px;max-width:100%;height:auto;margin:0 0 20px">'
            . '<div style="font-size:13px;letter-spacing:1px;text-transform:uppercase">Platz- und Busbelegung</div>'
            . '<h1 style="font-size:24px;line-height:1.3;margin:10px 0 0">'
            . self::icon($icon) . Security::escape($heading) . '</h1></td></tr>';
    }

    public static function icon(string $type): string
    {
        [$symbol, $label] = match ($type) {
            'pitch' => ['&#9917;', 'Platzbelegung'],
            'vehicle' => ['&#128656;', 'Fahrzeugbuchung'],
            'cabin' => ['&#128682;', 'Kabinenbelegung'],
            'registration' => ['&#128203;', 'Registrierung'],
            'invitation' => ['&#128232;', 'Einladung'],
            'password' => ['&#128272;', 'Passwort'],
            'setup' => ['&#9881;', 'Einrichtung'],
            default => throw new RuntimeException('Das E-Mail-Symbol ist ungültig.'),
        };
        return '<span role="img" aria-label="' . $label . '" style="font-size:28px;line-height:1">'
            . $symbol . '</span> ';
    }

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
            if (str_contains($html, 'cid:vfl-fussball-logo')) {
                $mail->addEmbeddedImage(
                    dirname(__DIR__) . '/assets/images/vfl-fussball-logo-email.png',
                    'vfl-fussball-logo',
                    'vfl-fussball-logo.png',
                    'base64',
                    'image/png'
                );
            }
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
