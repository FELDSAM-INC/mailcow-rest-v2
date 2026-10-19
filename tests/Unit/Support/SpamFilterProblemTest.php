<?php

namespace Tests\Unit\Support;

use App\Exceptions\ProblemException;
use App\Exceptions\ProblemRenderer;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Contract\OpenApiContract;
use Tests\TestCase;

/**
 * Feature 006 T017 (research R21, FR-065): the four problem types of the feature, their extension
 * members and headers, and the 006 extensions of `temporary-failure`, each validated against its
 * contract schema.
 */
class SpamFilterProblemTest extends TestCase
{
    /**
     * @return array<string, array{ProblemType, string, string}>
     */
    public static function types(): array
    {
        return [
            'quarantine-item-busy' => [ProblemType::QuarantineItemBusy, '/api/v2/problems/quarantine-item-busy', 'Quarantine item is being processed'],
            'quarantine-delivery-refused' => [ProblemType::QuarantineDeliveryRefused, '/api/v2/problems/quarantine-delivery-refused', 'Delivery refused'],
            'quarantine-message-too-large' => [ProblemType::QuarantineMessageTooLarge, '/api/v2/problems/quarantine-message-too-large', 'Quarantine message too large'],
            'spam-policy-entry-exists' => [ProblemType::SpamPolicyEntryExists, '/api/v2/problems/spam-policy-entry-exists', 'List entry already exists'],
        ];
    }

    #[DataProvider('types')]
    public function test_each_type_has_its_uri_status_and_title(ProblemType $type, string $uri, string $title): void
    {
        $this->assertSame($uri, $type->uri());
        $this->assertSame(409, $type->status());
        $this->assertSame($title, $type->title());
        $this->assertNotSame('', $type->detail());
    }

    /**
     * @return array{0: int, 1: array<string, mixed>, 2: \Symfony\Component\HttpFoundation\Response}
     */
    private function render(ProblemException $e): array
    {
        $response = ProblemRenderer::render($e, Request::create('/api/v2/quarantine/1/release', 'POST'));

        return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true), $response];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertValid(array $body, string $schema): void
    {
        $this->assertSame([], OpenApiContract::shared()->validateComponent($body, $schema));
    }

    public function test_quarantine_item_busy_asks_to_retry_after_five_seconds(): void
    {
        [$status, $body, $response] = $this->render(ProblemException::quarantineItemBusy());

        $this->assertSame(409, $status);
        $this->assertSame('/api/v2/problems/quarantine-item-busy', $body['type']);
        $this->assertSame('5', $response->headers->get('Retry-After'));
        $this->assertValid($body, 'Problem');
    }

    public function test_quarantine_delivery_refused_carries_the_smtp_answer(): void
    {
        [$status, $body] = $this->render(ProblemException::quarantineDeliveryRefused(550, '550 5.1.1 <gone@example.org>: Recipient address rejected'));

        $this->assertSame(409, $status);
        $this->assertSame(550, $body['smtpCode']);
        $this->assertSame('550 5.1.1 <gone@example.org>: Recipient address rejected', $body['smtpResponse']);
        $this->assertValid($body, 'QuarantineDeliveryRefusedProblem');
    }

    public function test_quarantine_message_too_large_carries_size_and_limit(): void
    {
        [$status, $body] = $this->render(ProblemException::quarantineMessageTooLarge(10485761, 10485760));

        $this->assertSame(409, $status);
        $this->assertSame(10485761, $body['size']);
        $this->assertSame(10485760, $body['limit']);
        $this->assertValid($body, 'QuarantineMessageTooLargeProblem');
    }

    public function test_spam_policy_entry_exists_names_the_list_and_entry(): void
    {
        [$status, $body] = $this->render(ProblemException::spamPolicyEntryExists('block', 42));

        $this->assertSame(409, $status);
        $this->assertSame('block', $body['list']);
        $this->assertSame(42, $body['entryId']);
        $this->assertValid($body, 'SpamPolicyEntryExistsProblem');
    }

    public function test_temporary_failure_names_the_dependency_and_step(): void
    {
        [$status, $body, $response] = $this->render(ProblemException::temporaryFailure('mailServer', 'delivery'));

        $this->assertSame(503, $status);
        $this->assertSame('/api/v2/problems/temporary-failure', $body['type']);
        $this->assertSame('mailServer', $body['dependency']);
        $this->assertSame('delivery', $body['step']);
        $this->assertSame('30', $response->headers->get('Retry-After'));
        $this->assertValid($body, 'SpamFilterTemporaryFailureProblem');

        [, $body] = $this->render(ProblemException::temporaryFailure('spamFilter'));
        $this->assertArrayNotHasKey('step', $body);
        $this->assertValid($body, 'SpamFilterTemporaryFailureProblem');
    }

    public function test_attribute_not_permitted_names_each_member_and_its_flag(): void
    {
        [$status, $body] = $this->render(ProblemException::attributeNotPermitted(['category'], ['category' => 'quarantineCategory']));

        $this->assertSame(403, $status);
        $this->assertSame(['category'], $body['attributes']);
        $this->assertSame(['category' => 'quarantineCategory'], $body['requiredFlags']);
        $this->assertValid($body, 'QuarantineNotificationAttributeNotPermittedProblem');
    }
}
