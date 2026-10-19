<?php

namespace App\Services\Quarantine;

use App\Exceptions\ProblemException;
use App\Models\Mailcow\QuarantineItem;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The stored message of an item for inspection and downloads (feature 006 FR-014): parsed and
 * downloaded only up to the content size limit (`restv2.quarantine.content_limit`, legacy 10 MiB);
 * a larger message is `tooLarge` (downloads `409 quarantine-message-too-large`), an item without a
 * stored message `missing`. The message is read only when it is used.
 */
class QuarantineContent
{
    public const AVAILABLE = 'available';

    public const TOO_LARGE = 'tooLarge';

    public const MISSING = 'missing';

    public function __construct(private readonly QuarantineMessageParser $parser) {}

    public static function limit(): int
    {
        return max(1, (int) config('restv2.quarantine.content_limit', 10485760));
    }

    public function status(QuarantineItem $item): string
    {
        $size = (int) $item->getRawOriginal('size');

        return match (true) {
            $size === 0 => self::MISSING,
            $size > self::limit() => self::TOO_LARGE,
            default => self::AVAILABLE,
        };
    }

    /**
     * @throws NotFoundHttpException without a stored message
     * @throws ProblemException `quarantine-message-too-large`
     */
    public function requireDownloadable(QuarantineItem $item): void
    {
        match ($this->status($item)) {
            self::MISSING => throw new NotFoundHttpException('The quarantine item has no stored message.'),
            self::TOO_LARGE => throw ProblemException::quarantineMessageTooLarge((int) $item->getRawOriginal('size'), self::limit()),
            default => null,
        };
    }

    public function stored(QuarantineItem $item): string
    {
        return (string) DB::table('quarantine')->where('id', $item->getKey())->value('msg');
    }

    public function parse(QuarantineItem $item): ParsedMessage
    {
        return $this->parser->parse($this->stored($item), (string) $item->getRawOriginal('rcpt'));
    }
}
