<?php

namespace Tests\Unit;

use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\Resolver\MergedRulesetWriter;
use App\Services\Resolver\ResolverFileHelper;
use App\Services\Resolver\ResolverPaths;
use Mockery;
use Tests\TestCase;

class MergedRulesetWriterTest extends TestCase
{
    private MergedRulesetWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new MergedRulesetWriter(
            Mockery::mock(AmneziaWgService::class),
            Mockery::mock(DockerRuntime::class),
            Mockery::mock(ResolverPaths::class),
            Mockery::mock(ResolverFileHelper::class),
        );
    }

    public function test_collect_rule_field_accepts_string_or_array(): void
    {
        $rules = [
            ['domain_suffix' => 'YouTube.com'],
            ['domain_suffix' => ['netflix.com', '']],
            ['domain_suffix' => 123],
            ['domain' => 'Exact.Example'],
            'not-a-rule',
        ];

        $suffix = $this->writer->collectRuleField($rules, 'domain_suffix', true);
        $exact = $this->writer->collectRuleField($rules, 'domain', true);

        $this->assertSame(['youtube.com', 'netflix.com'], $suffix);
        $this->assertSame(['exact.example'], $exact);
    }

    public function test_as_list_normalizes_scalar_and_null(): void
    {
        $this->assertSame([], $this->writer->asList(null));
        $this->assertSame([], $this->writer->asList(''));
        $this->assertSame(['a', 'b'], $this->writer->asList(['a', 'b']));
        $this->assertSame(['youtube'], $this->writer->asList('youtube'));
        $this->assertSame([], $this->writer->asList(0));
    }
}
