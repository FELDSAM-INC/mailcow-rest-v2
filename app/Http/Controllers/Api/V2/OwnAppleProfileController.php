<?php

namespace App\Http\Controllers\Api\V2;

use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Exceptions\ValidationProblemException;
use App\Http\Controllers\Controller;
use App\Http\RequestContext;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Http\Requests\Api\V2\CreateAppleProfileRequest;
use App\Services\Account\AppleProfileBuilder;
use App\Services\AppPasswords\AppPasswordService;
use App\Services\Config\MailcowConfig;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * getOwnAppleProfile and createOwnAppleProfileWithAppPassword (feature 005 FR-082): legacy
 * `mobileconfig.php` for the signed-in mailbox user, optionally with a new app password embedded
 * (legacy `?app_password`), created through AppPasswordService (flag `appPasswds`, audited with
 * `source: appleProfile`).
 */
class OwnAppleProfileController extends Controller
{
    public const VARIANTS = ['email', 'emailAndDav'];

    public function __construct(
        private readonly AppleProfileBuilder $builder,
        private readonly MailcowConfig $config,
    ) {}

    public function show(Request $request): Response
    {
        $principal = self::mailboxUser($request);
        $validator = Validator::make($request->query(), ['variant' => ['bail', 'sometimes', 'string', 'in:'.implode(',', self::VARIANTS)]]);

        if ($validator->fails()) {
            throw new ValidationProblemException(ApiFormRequest::violations($validator));
        }

        $withDav = self::withDav((string) $request->query('variant', 'emailAndDav'));

        return $this->profile($principal, $request, $withDav, null, 200);
    }

    public function store(CreateAppleProfileRequest $request, AppPasswordService $appPasswords): Response
    {
        $principal = self::mailboxUser($request);
        $withDav = self::withDav($request->variant());
        $created = $appPasswords->create($principal, $principal->username, [
            'name' => $request->appPasswordName() ?? self::deviceName((string) $request->userAgent()),
            'protocols' => $withDav ? ['imap', 'smtp', 'dav'] : ['imap', 'smtp'],
            'active' => true,
        ], 'appleProfile');

        return $this->profile($principal, $request, $withDav, (string) $created['generatedPassword'], 201)
            ->header('Location', '/api/v2/mailboxes/'.$principal->username.'/app-passwords/'.$created['id']);
    }

    /**
     * legacy's device name: `iPad`, `iPhone` or `Mac` by a substring of the user agent, otherwise
     * the user agent, here cut so that its stored (HTML-encoded) form fits 255 characters.
     */
    public static function deviceName(string $userAgent): string
    {
        foreach (['iPad' => 'iPad', 'iPhone' => 'iPhone', 'Macintosh' => 'Mac'] as $needle => $name) {
            if (str_contains($userAgent, $needle)) {
                return $name;
            }
        }

        $name = mb_substr(trim($userAgent), 0, 255);

        while ($name !== '' && mb_strlen(AppPasswordService::encode($name)) > 255) {
            $name = mb_substr($name, 0, -1);
        }

        return $name === '' ? 'Apple device' : $name;
    }

    private function profile(Principal $principal, Request $request, bool $withDav, ?string $password, int $status): Response
    {
        $name = (string) DB::table('mailbox')->where('username', $principal->username)->value('name');
        $plist = $this->builder->build($principal->username, $name, $this->config->autodiscoverConfig($request), $withDav, $password);

        return response($plist, $status, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => $this->builder->contentDisposition(),
            'Cache-Control' => 'no-store',
        ]);
    }

    private static function mailboxUser(Request $request): Principal
    {
        $principal = RequestContext::requirePrincipal($request);

        if ($principal->role !== Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Only mailbox users download a connection profile.');
        }

        return $principal;
    }

    /** The full variant needs the groupware (research R18: `groupware-disabled` otherwise). */
    private static function withDav(string $variant): bool
    {
        if ($variant !== 'emailAndDav') {
            return false;
        }

        if (config('restv2.skip_sogo')) {
            throw new ProblemException(ProblemType::GroupwareDisabled);
        }

        return true;
    }
}
