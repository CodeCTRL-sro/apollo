<?php

namespace CodeCTRL\Apollo\Utility\Helper;

use League\OAuth2\Client\Provider\Google;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\OAuth;
use PHPMailer\PHPMailer\PHPMailer;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class MailHelper {

    /**
     * @param Environment $twig
     * @param array $details
     * @param string|null $altBody
     * @param string|null $replyTo
     * @param array $addCC
     * @return void
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public static function send(Environment $twig, array $details, string|null $altBody = "", string|null $replyTo = "", array $addCC = array()): void
    {
        $mail = new PHPMailer(true);
        $htmlBody = null;
        $mail->setFrom($_ENV['MAIL_ADDRESS'], $_ENV['MAIL_NAME']);
        if (!empty($replyTo)) {
            $mail->addReplyTo($replyTo, $replyTo);
        }
        if (!empty($addCC)) {
            foreach ($addCC as $cc) {
                $mail->addCC($cc);
            }
        }

        if(isset($_ENV['MAIL_SMTP_HOST'])) {
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_SMTP_HOST'];
            $mail->SMTPAuth = $_ENV['MAIL_SMTP_AUTH'] ?? true;
            $mail->SMTPSecure = $_ENV['MAIL_SMTP_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $_ENV['MAIL_SMTP_PORT'];
            $mail->Username = $_ENV['MAIL_SMTP_USER'];
            $mail->Password = $_ENV['MAIL_SMTP_PASSWORD'];
            if(isset($_ENV['MAIL_SMTP_AUTH_TYPE'])){
                $mail->AuthType = $_ENV['MAIL_SMTP_AUTH_TYPE'];
            }
        }

        if(isset($_ENV['MAIL_GOOGLE_CLIENT_ID'])){
            $provider = new Google([
                'clientId'     => $_ENV['MAIL_GOOGLE_CLIENT_ID'],
                'clientSecret' => $_ENV['MAIL_GOOGLE_CLIENT_SECRET'],
            ]);

            $mail->setOAuth(new OAuth([
                'provider'     => $provider,
                'clientId'     => $_ENV['MAIL_GOOGLE_CLIENT_ID'],
                'clientSecret' => $_ENV['MAIL_GOOGLE_CLIENT_SECRET'],
                'refreshToken' => $_ENV['MAIL_GOOGLE_REFRESH_TOKEN'],
                'userName'     => $_ENV['MAIL_SMTP_USER'],
            ]));
        }

        $mail->addAddress($details["email"], $details["name"] ?? $details["email"]);

        $mail->isHTML();
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $details["subject"];
        if (isset($details["html"])) {
            $mail->Body = $details["html"];
        } else {
            if (isset($details["details"])) {
                $mail->Body = $twig->render('/emails/' . ($details["twig"] ?? 'simple') . '.html.twig', $details["details"]);
            } else {
                $mail->Body = $twig->render('/emails/' . ($details["twig"] ?? 'simple') . '.html.twig', array('body' => $details["body"]));
            }
        }

        $mail->AltBody = $altBody;
        $mail->send();
    }
}