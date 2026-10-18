<?php

namespace App\Services\DnsDiagnostics\Spf;

use SPFLib\Check\Environment;
use SPFLib\Check\Result;
use SPFLib\Check\State;
use SPFLib\Checker;
use SPFLib\Exception\DNSResolutionException;
use SPFLib\Exception\IncludeMechanismException;
use SPFLib\Exception\InvalidDomainException;
use SPFLib\Exception\TooManyDNSLookupsException;
use SPFLib\Exception\TooManyDNSVoidLookupsException;

/**
 * spf-lib's checker, remembering which lookup limit ended an evaluation as `permerror` (the
 * library reports both limits as plain `permerror`), so that the SPF check can name it (feature 010
 * research R18: `spfTooManyLookups`, `spfTooManyVoidLookups`).
 */
final class RecordingChecker extends Checker
{
    public ?string $limitHit = null;

    /**
     * The library's own startValidation(), recording the lookup limit that ended it.
     */
    protected function startValidation(Environment $environment, State $state): Result
    {
        $this->limitHit = null;

        try {
            $domain = $this->getDomainNameValidator()->check($state->getSenderDomain());
        } catch (InvalidDomainException $e) {
            return Result::create(Result::CODE_NONE)->addMessage($e->getMessage());
        }

        try {
            return $this->validate($state, $domain);
        } catch (TooManyDNSLookupsException $e) {
            $this->limitHit = 'lookups';

            return Result::create(Result::CODE_ERROR_PERMANENT)->addMessage($e->getMessage());
        } catch (TooManyDNSVoidLookupsException $e) {
            $this->limitHit = 'voidLookups';

            return Result::create(Result::CODE_ERROR_PERMANENT)->addMessage($e->getMessage());
        } catch (DNSResolutionException $e) {
            return Result::create(Result::CODE_ERROR_TEMPORARY)->addMessage($e->getMessage());
        } catch (IncludeMechanismException $e) {
            return Result::create($e->getFinalResultCode())->addMessages($e->getIncludeResult()->getMessages());
        } catch (InvalidDomainException $e) {
            return $this->buildInvalidDomainResult($state, $e);
        }
    }
}
