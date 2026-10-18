<?php

namespace App\Services\PasswordReset;

use Illuminate\Http\Request;

/**
 * The link of a reset message (feature 005 FR-022, research R6): the configurable reset page
 * (`RESTV2_PW_RESET_PAGE`, `{token}` replaced; the web app's page by default) on the request's host
 * when it is mailcow's host name or one of its additional server names, otherwise on mailcow's host
 * name, as legacy getBaseURL() decides. Always `https` (plan parity note).
 */
class ResetLinkBuilder
{
    /**
     * The host of the link for a request: its host when allowed, else mailcow's host name.
     */
    public function hostFor(Request $request): string
    {
        $hostname = strtolower((string) config('restv2.mailcow_hostname'));
        $additional = (string) preg_replace('/\s+/', '', strtolower((string) config('restv2.additional_server_names')));
        $allowed = $additional === '' ? [] : explode(',', $additional);
        $allowed[] = $hostname;
        $host = strtolower($request->getHost());

        return in_array($host, $allowed, true) ? $host : $hostname;
    }

    public function link(string $host, string $token): string
    {
        return 'https://'.$host.str_replace('{token}', rawurlencode($token), (string) config('restv2.pw_reset_page'));
    }
}
