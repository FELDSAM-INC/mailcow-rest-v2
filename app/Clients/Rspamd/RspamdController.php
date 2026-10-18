<?php

namespace App\Clients\Rspamd;

/**
 * mailcow's Rspamd controller, reached over its Unix socket like legacy (feature 006 research R2).
 * Created by feature 011 with the two reads it needs; feature 006 adds learnHam(), learnSpam(),
 * fuzzyAdd() and actions() with the signatures of its plan. Faked in tests
 * (tests/Support/Rspamd/FakeRspamdController.php).
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
}
