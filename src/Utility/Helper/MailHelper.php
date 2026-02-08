<?php

namespace CodeCTRL\Apollo\Utility\Helper;

use PHPMailer\PHPMailer\PHPMailer;
use Twig\Environment;

class MailHelper {

    /**
     * @param Environment $twig
     * @param array $details
     * @param string|null $altBody
     * @param string|null $replyTo
     * @param array $addCC
     * @return void
     */
    public static function send(Environment $twig, array $details, string|null $altBody = "", string|null $replyTo = "", array $addCC = array()): void
    {
        $mail = new PHPMailer(true);
        $htmlBody = null;
        try {
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
                $mail->SMTPAuth = true;
                $mail->SMTPSecure = $_ENV['MAIL_SMTP_ENCRYPTION'] ?? PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $_ENV['MAIL_SMTP_PORT'];
                $mail->Username = $_ENV['MAIL_SMTP_USER'];
                $mail->Password = $_ENV['MAIL_SMTP_PASSWORD'];
            }

            $mail->addAddress($details["email"], isset($details["name"]) ? $details["name"] : $details["email"]);

            $mail->isHTML();
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $details["subject"];
            if (isset($details["html"])) {
                $mail->Body = $details["html"];
            } else {
                if (isset($details["details"])) {
                    $mail->Body = $twig->render('/emails/' . ($details["twig"] ?? 'simple') . '.html.twig', $details["details"]);
                } else {
                    if (!empty($htmlBody)) {
                        $mail->Body = $twig->render('/emails/' . ($details["twig"] ?? 'simple') . '.html.twig', array('htmlTemplate' => str_replace("__BODY__", $details["body"], $htmlBody)));
                    } else {
                        $mail->Body = $twig->render('/emails/' . ($details["twig"] ?? 'simple') . '.html.twig', array('body' => $details["body"]));
                    }
                }
            }

            $mail->AltBody = $altBody;
            $mail->send();
        } catch (\Exception $e) {

        }
    }
}