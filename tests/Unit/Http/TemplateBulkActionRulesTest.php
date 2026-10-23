<?php

namespace Tests\Unit\Http;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Http\TemplateBulkRulesProbeRequest;

class TemplateBulkActionRulesTest extends TestCase
{
    public function test_versions_and_duplicate_ids_are_preserved_for_the_bulk_runner(): void
    {
        $request = new TemplateBulkRulesProbeRequest;
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make([
            'action' => 'delete',
            'targets' => [['id' => '007', 'version' => '"first"'], ['id' => '007']],
        ], $request->rules());
        $this->assertTrue($validator->passes());
        $request->setValidator($validator);

        $this->assertSame([
            ['id' => '007', 'version' => '"first"'],
            ['id' => '007', 'version' => null],
        ], $request->targets());
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_actions_and_target_shapes_are_rejected(array $body, string $field): void
    {
        $request = new TemplateBulkRulesProbeRequest;
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($body, $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has($field), $validator->errors()->toJson());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): array
    {
        return [
            'missing action' => [['targets' => [['id' => '1']]], 'action'],
            'other action' => [['action' => 'activate', 'targets' => [['id' => '1']]], 'action'],
            'empty targets' => [['action' => 'delete', 'targets' => []], 'targets'],
            'configured limit' => [['action' => 'delete', 'targets' => [['id' => '1'], ['id' => '2'], ['id' => '3']]], 'targets'],
            'non-list targets' => [['action' => 'delete', 'targets' => ['named' => ['id' => '1']]], 'targets'],
            'scalar target' => [['action' => 'delete', 'targets' => ['1']], 'targets.0'],
            'missing id' => [['action' => 'delete', 'targets' => [['version' => 'tag']]], 'targets.0.id'],
            'numeric id' => [['action' => 'delete', 'targets' => [['id' => 1]]], 'targets.0.id'],
            'empty id' => [['action' => 'delete', 'targets' => [['id' => '']]], 'targets.0.id'],
            'null version' => [['action' => 'delete', 'targets' => [['id' => '1', 'version' => null]]], 'targets.0.version'],
        ];
    }

    public function test_unknown_body_members_are_reported_as_immutable(): void
    {
        $request = new TemplateBulkRulesProbeRequest;
        $violations = $request->violationsFor([
            'action' => 'delete', 'targets' => [['id' => '1']], 'force' => true, 'name' => 'ignored',
        ]);

        $this->assertSame(['force', 'name'], array_keys($violations));
        $this->assertSame(['immutable', 'immutable'], array_column($violations, 'code'));
    }
}
