<?php

namespace App\Services\Quarantine;

use App\Auth\Principal;
use App\Services\Config\MailcowConfig;
use App\Services\Preferences\PreferencesService;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The message a quarantine release delivers (feature 006 research R3, FR-019, FR-020):
 *
 *  - `original` (`Q_RELEASE_FORMAT` `raw`, empty or missing): the stored message with legacy's exact
 *    substitution of `X-Spam-Flag:` lines (`/^X-Spam-Flag: (.*)/m`), byte-identical otherwise;
 *  - `attachment`: a new message from the envelope sender to the recipient with legacy's
 *    `quarantine.release_subject` and `release_body` in the acting user's language (001 FR-030),
 *    the server's default language for an API key, and English when the language has no
 *    translation, with the stored message attached as `<qid>.eml` (`message/rfc822`).
 *
 * The strings of every language are extracted from the tag's language files into
 * resources/legacy/quarantine-release-strings.json.
 */
class ReleaseMessageFactory
{
    public const FALLBACK_SENDER = 'sender-unknown@rspamd';

    /** @var array<string, array{subject: string, body: string}>|null */
    private static ?array $strings = null;

    public function __construct(
        private readonly PreferencesService $preferences,
        private readonly MailcowConfig $config,
    ) {}

    public function original(string $stored): RawMessage
    {
        return new RawMessage((string) preg_replace('/^X-Spam-Flag: (.*)/m', 'X-Pre-Release-Spam-Flag: $1', $stored));
    }

    public function attachment(string $stored, string $qid, string $sender, string $recipient, Principal $actor): Email
    {
        $language = $this->languageOf($actor);

        return (new Email)
            ->from(new Address($sender))
            ->to(new Address($recipient))
            ->subject($this->subjectFor($language, $qid))
            ->text($this->bodyFor($language), 'utf-8')
            ->attach($stored, $qid.'.eml', 'message/rfc822');
    }

    public function subjectFor(string $language, string $qid): string
    {
        return sprintf($this->strings($language)['subject'], $qid);
    }

    public function bodyFor(string $language): string
    {
        return $this->strings($language)['body'];
    }

    /**
     * The stored envelope sender when it is an address, otherwise (empty, or a non-address such as
     * the column default `unknown`) `sender-unknown@rspamd` (legacy uses it for an empty sender only).
     */
    public static function envelopeSender(?string $sender): string
    {
        return is_string($sender) && filter_var($sender, FILTER_VALIDATE_EMAIL) !== false ? $sender : self::FALLBACK_SENDER;
    }

    private function languageOf(Principal $actor): string
    {
        return $actor->isApiKey() ? $this->config->defaultLanguage() : $this->preferences->effective($actor)['language'];
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function strings(string $language): array
    {
        if (self::$strings === null) {
            $decoded = json_decode((string) file_get_contents(base_path('resources/legacy/quarantine-release-strings.json')), true);
            self::$strings = is_array($decoded) ? array_filter($decoded, 'is_array') : [];
        }

        return self::$strings[strtolower($language)] ?? self::$strings['en-gb'] ?? [
            'subject' => 'Potentially damaging quarantine item %s',
            'body' => 'We have attached your message as eml file to this message.',
        ];
    }
}
