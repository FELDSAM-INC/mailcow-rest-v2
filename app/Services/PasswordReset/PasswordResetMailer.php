<?php

namespace App\Services\PasswordReset;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Config\MailcowConfig;
use DateTime;
use DateTimeZone;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use IntlDateFormatter;
use Redis;
use Throwable;

/**
 * The reset message (feature 005 FR-022, research R6), legacy reset_password('get_notification',
 * 'issue', 'send_mail'):
 *
 *  - sender `PW_RESET_FROM`, subject `PW_RESET_SUBJ`, bodies `PW_RESET_HTML` and `PW_RESET_TEXT`
 *    from mailcow's shared store, or the default templates (`pw_reset_html.tpl`,
 *    `pw_reset_text.tpl` of the mounted `data/assets/templates`) when both bodies are empty;
 *  - the placeholders `{{hostname}}`, `{{link}}`, `{{username}}`, `{{username2}}` (the recovery
 *    address), `{{date}}` (IntlDateFormatter FULL/FULL in the default locale and `TZ`) and
 *    `{{token_lifetime}}` replaced in subject and bodies with str_replace(), in legacy's order;
 *  - legacy's checks (valid sender and recipient, non-empty subject and text);
 *  - sent through the default mailer, mailcow's mail server without authentication
 *    (`restv2_postfix`), UTF-8, HTML with a text alternative (or text only), `X-Mailer: MooMail`.
 */
class PasswordResetMailer
{
    public function __construct(
        private readonly SharedStore $store,
        private readonly MailcowConfig $config,
    ) {}

    /**
     * Whether the reset is configured: the message has a sender and a subject.
     *
     * @throws SharedStoreUnavailable
     */
    public function available(): bool
    {
        [$from, $subject] = $this->store->run(fn (Redis $redis): array => [$redis->get('PW_RESET_FROM'), $redis->get('PW_RESET_SUBJ')]);

        return is_string($from) && $from !== '' && is_string($subject) && $subject !== '';
    }

    /**
     * Builds and sends the message; false when a legacy check fails or the mail server refuses it.
     */
    public function send(string $username, string $recoveryAddress, string $link): bool
    {
        try {
            $template = $this->template();
            $values = [
                '{{hostname}}' => (string) config('restv2.mailcow_hostname'),
                '{{link}}' => $link,
                '{{username}}' => $username,
                '{{username2}}' => $recoveryAddress,
                '{{date}}' => $this->date(),
                '{{token_lifetime}}' => (string) $this->config->pwResetTokenLifetime(),
            ];
            $fill = fn (string $text): string => str_replace(array_keys($values), array_values($values), $text);

            $from = $template['from'];
            $subject = $fill($template['subject']);
            $text = $fill($template['text']);
            $html = $fill($template['html']);

            if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || filter_var($recoveryAddress, FILTER_VALIDATE_EMAIL) === false || $subject === '' || $text === '') {
                return false;
            }

            Mail::mailer()->send([], [], function (Message $message) use ($from, $recoveryAddress, $subject, $text, $html): void {
                $message->from($from)->to($recoveryAddress)->subject($subject);
                $html !== '' ? $message->html($html)->text($text) : $message->text($text);
                $message->getHeaders()->addTextHeader('X-Mailer', 'MooMail');
            });

            return true;
        } catch (Throwable $e) {
            Log::warning('The password reset message was not sent: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @return array{from: string, subject: string, html: string, text: string}
     */
    private function template(): array
    {
        [$from, $subject, $html, $text] = $this->store->run(fn (Redis $redis): array => [
            $redis->get('PW_RESET_FROM'), $redis->get('PW_RESET_SUBJ'), $redis->get('PW_RESET_HTML'), $redis->get('PW_RESET_TEXT'),
        ]);
        $html = is_string($html) ? $html : '';
        $text = is_string($text) ? $text : '';

        if ($html === '' && $text === '') {
            $dir = (string) config('restv2.mailcow_tpls_dir');
            $dir = str_starts_with($dir, '/') ? rtrim($dir, '/') : base_path(rtrim($dir, '/'));
            $html = (string) @file_get_contents($dir.'/pw_reset_html.tpl');
            $text = (string) @file_get_contents($dir.'/pw_reset_text.tpl');
        }

        return ['from' => is_string($from) ? $from : '', 'subject' => is_string($subject) ? $subject : '', 'html' => $html, 'text' => $text];
    }

    private function date(): string
    {
        $zone = new DateTimeZone((string) config('restv2.timezone', 'UTC'));
        $formatter = new IntlDateFormatter(locale_get_default(), IntlDateFormatter::FULL, IntlDateFormatter::FULL, $zone);

        return (string) $formatter->format(new DateTime('now', $zone));
    }
}
