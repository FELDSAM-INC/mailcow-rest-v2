<?php

namespace App\Services\Account;

use App\Services\Branding\ProductName;
use Illuminate\Support\Str;
use XMLWriter;

/**
 * The unsigned Apple connection profile of legacy `mobileconfig.php` (feature 005 FR-082, research
 * R18): the same payloads, keys and order, written with XMLWriter so that every value is escaped.
 * Payload UUIDs are random v4 UUIDs in upper case (legacy uses an MD5-based GUID); the CalDAV port
 * stays a `<real>` and the CardDAV port an `<integer>`, as legacy writes them.
 */
class AppleProfileBuilder
{
    public function __construct(private readonly ProductName $productName) {}

    /**
     * @param  array<string, mixed>  $autodiscover  the server's autodiscover settings for the request
     */
    public function build(string $email, string $displayName, array $autodiscover, bool $withDav, ?string $password): string
    {
        $product = $this->productName->get();
        $identifier = implode('.', array_reverse(preg_split('/(@|\.)/', $email) ?: [])).'.appleprofile.'.preg_replace('/[^a-zA-Z0-9]+/', '', $product);
        $description = ($withDav ? 'IMAP, CalDAV, CardDAV' : 'IMAP').($password !== null ? ' with application password' : '');
        $displayName = $displayName === '' ? $email : $displayName;

        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        $xml->startDocument('1.0', 'UTF-8');
        $xml->writeDtd('plist', '-//Apple//DTD PLIST 1.0//EN', 'http://www.apple.com/DTDs/PropertyList-1.0.dtd');
        $xml->startElement('plist');
        $xml->writeAttribute('version', '1.0');
        $xml->startElement('dict');
        $xml->writeElement('key', 'PayloadContent');
        $xml->startElement('array');

        self::dict($xml, [
            ['EmailAccountDescription', 'string', $email],
            ['EmailAccountType', 'string', 'EmailTypeIMAP'],
            ['EmailAccountName', 'string', $displayName],
            ['EmailAddress', 'string', $email],
            ['IncomingMailServerAuthentication', 'string', 'EmailAuthPassword'],
            ['IncomingMailServerHostName', 'string', self::setting($autodiscover, 'imap', 'server')],
            ['IncomingMailServerPortNumber', 'integer', self::setting($autodiscover, 'imap', 'port')],
            ['IncomingMailServerUseSSL', 'true', null],
            ['IncomingMailServerUsername', 'string', $email],
            ...($password !== null ? [['IncomingPassword', 'string', $password]] : []),
            ['OutgoingMailServerAuthentication', 'string', 'EmailAuthPassword'],
            ['OutgoingMailServerHostName', 'string', self::setting($autodiscover, 'smtp', 'server')],
            ['OutgoingMailServerPortNumber', 'integer', self::setting($autodiscover, 'smtp', 'port')],
            ['OutgoingMailServerUseSSL', 'true', null],
            ['OutgoingMailServerUsername', 'string', $email],
            ['OutgoingPasswordSameAsIncomingPassword', 'true', null],
            ['PayloadDescription', 'string', 'Configures email account.'],
            ['PayloadDisplayName', 'string', 'IMAP Account ('.$email.')'],
            ['PayloadIdentifier', 'string', $identifier.'.email'],
            ['PayloadOrganization', 'string', ''],
            ['PayloadType', 'string', 'com.apple.mail.managed'],
            ['PayloadUUID', 'string', self::uuid()],
            ['PayloadVersion', 'integer', '1'],
            ['PreventAppSheet', 'false', null],
            ['PreventMove', 'false', null],
            ['SMIMESigningUserOverrideable', 'true', null],
            ['SMIMESigningCertificateUUIDUserOverrideable', 'true', null],
            ['SMIMEEncryptByDefaultUserOverrideable', 'true', null],
            ['SMIMEEncryptionCertificateUUIDUserOverrideable', 'true', null],
            ['SMIMEEnableEncryptionPerMessageSwitch', 'true', null],
        ]);

        if ($withDav) {
            self::dict($xml, [
                ['CalDAVAccountDescription', 'string', $email],
                ['CalDAVHostName', 'string', self::setting($autodiscover, 'caldav', 'server')],
                ['CalDAVPort', 'real', self::setting($autodiscover, 'caldav', 'port')],
                ['CalDAVPrincipalURL', 'string', '/SOGo/dav/'.$email],
                ['CalDAVUseSSL', 'true', null],
                ['CalDAVUsername', 'string', $email],
                ...($password !== null ? [['CalDAVPassword', 'string', $password]] : []),
                ['PayloadDescription', 'string', 'Configures CalDAV account.'],
                ['PayloadDisplayName', 'string', 'CalDAV ('.$email.')'],
                ['PayloadIdentifier', 'string', $identifier.'.CalDAV'],
                ['PayloadOrganization', 'string', ''],
                ['PayloadType', 'string', 'com.apple.caldav.account'],
                ['PayloadUUID', 'string', self::uuid()],
                ['PayloadVersion', 'integer', '1'],
            ]);
            self::dict($xml, [
                ['CardDAVAccountDescription', 'string', $email],
                ['CardDAVHostName', 'string', self::setting($autodiscover, 'carddav', 'server')],
                ['CardDAVPort', 'integer', self::setting($autodiscover, 'carddav', 'port')],
                ['CardDAVPrincipalURL', 'string', '/SOGo/dav/'.$email],
                ['CardDAVUseSSL', 'true', null],
                ['CardDAVUsername', 'string', $email],
                ...($password !== null ? [['CardDAVPassword', 'string', $password]] : []),
                ['PayloadDescription', 'string', 'Configures CardDAV accounts'],
                ['PayloadDisplayName', 'string', 'CardDAV ('.$email.')'],
                ['PayloadIdentifier', 'string', $identifier.'.carddav'],
                ['PayloadOrganization', 'string', ''],
                ['PayloadType', 'string', 'com.apple.carddav.account'],
                ['PayloadUUID', 'string', self::uuid()],
                ['PayloadVersion', 'integer', '1'],
            ]);
        }

        $xml->endElement(); // array

        foreach ([
            ['PayloadDescription', 'string', $description],
            ['PayloadDisplayName', 'string', $email],
            ['PayloadIdentifier', 'string', $identifier],
            ['PayloadOrganization', 'string', $product],
            ['PayloadRemovalDisallowed', 'false', null],
            ['PayloadType', 'string', 'Configuration'],
            ['PayloadUUID', 'string', self::uuid()],
            ['PayloadVersion', 'integer', '1'],
        ] as $entry) {
            self::entry($xml, ...$entry);
        }

        $xml->endElement(); // dict
        $xml->endElement(); // plist
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /** `attachment; filename="<product name>.mobileconfig"`, the name made safe for the header. */
    public function contentDisposition(): string
    {
        $name = preg_replace('/["\\\\\x00-\x1F\x7F]+/', '', $this->productName->get()) ?: 'mailcow';

        return 'attachment; filename="'.$name.'.mobileconfig"';
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null}>  $entries
     */
    private static function dict(XMLWriter $xml, array $entries): void
    {
        $xml->startElement('dict');

        foreach ($entries as $entry) {
            self::entry($xml, ...$entry);
        }

        $xml->endElement();
    }

    private static function entry(XMLWriter $xml, string $key, string $type, ?string $value): void
    {
        $xml->writeElement('key', $key);
        $value === null ? $xml->writeElement($type) : $xml->writeElement($type, $value);
    }

    /**
     * @param  array<string, mixed>  $autodiscover
     */
    private static function setting(array $autodiscover, string $service, string $name): string
    {
        $value = is_array($autodiscover[$service] ?? null) ? ($autodiscover[$service][$name] ?? '') : '';

        return is_scalar($value) ? (string) $value : '';
    }

    private static function uuid(): string
    {
        return strtoupper((string) Str::uuid());
    }
}
