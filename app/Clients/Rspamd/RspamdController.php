<?php

namespace App\Clients\Rspamd;

/**
 * mailcow's Rspamd controller, reached over its Unix socket like legacy (feature 006 research R2).
 * Created by feature 011 with the two reads it needs; feature 006 adds the learning calls and the
 * action thresholds. Faked in tests (tests/Support/Rspamd/FakeRspamdController.php).
 */
interface RspamdController
{
    /**
     * The controller's statistics (`GET /stat`), decoded.
     *
     * @return array<string, mixed>
     *
     * @throws RspamdUnavailable when the controller cannot be reached, times out, answers with an
     *                           error status or with a document that is not JSON
     */
    public function stat(): array;

    /**
     * The scan history (`GET /history`, `{"version": 2, "rows": [...]}`), decoded (feature 011
     * research R16).
     *
     * @return array<string, mixed>
     *
     * @throws RspamdUnavailable
     */
    public function history(): array;

    /**
     * The configured action thresholds (`GET /actions`, a list of `{action, value}`), decoded
     * (feature 006: the default spam score).
     *
     * @return array<int|string, mixed>
     *
     * @throws RspamdUnavailable
     */
    public function actions(): array;

    /** Learns the message as ham (`POST /learnham`, the message as `text/plain`). Never throws. */
    public function learnHam(string $message): LearnOutcome;

    /** Learns the message as spam (`POST /learnspam`). Never throws. */
    public function learnSpam(string $message): LearnOutcome;

    /**
     * Stores the message's fuzzy hashes in the local fuzzy storage (`POST /fuzzyadd` with the header
     * `Flag: <flag>`: 13 wanted, 11 unwanted). Never throws.
     */
    public function fuzzyAdd(string $message, int $flag): LearnOutcome;
}
