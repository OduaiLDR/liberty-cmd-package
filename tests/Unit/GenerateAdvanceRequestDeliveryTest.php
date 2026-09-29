<?php

namespace Cmd\Reports\Tests\Unit;

use Cmd\Reports\Console\Commands\GenerateAdvanceRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GenerateAdvanceRequestDeliveryTest extends TestCase
{
    public function test_command_exposes_only_the_safe_verification_override(): void
    {
        $command = new GenerateAdvanceRequest();
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('month'));
        self::assertTrue($definition->hasOption('dry-run'));
        self::assertTrue($definition->hasOption('send-to-me'));
        self::assertFalse($definition->hasOption('review'));
        self::assertFalse($definition->hasOption('sample'));
        self::assertFalse($definition->hasOption('company'));
        self::assertFalse($definition->hasOption('send-live'));

        $reflection = new ReflectionClass($command);
        self::assertSame(
            'oduai@libertydebtrelief.com',
            $reflection->getReflectionConstant('VERIFICATION_RECIPIENT')->getValue()
        );
    }

    public function test_email_body_is_clean_and_references_the_invoice(): void
    {
        $command = new GenerateAdvanceRequest();
        $method = (new ReflectionClass($command))->getMethod('buildEmailBody');
        $body = $method->invoke($command, 'LDR', 656000.0, '53', 'September 2026');

        self::assertStringContainsString('$656,000.00', $body);
        self::assertStringContainsString('attached invoice', $body);
        self::assertStringNotContainsString('REVIEW', strtoupper($body));
        self::assertStringNotContainsString('SAMPLE', strtoupper($body));
    }
}
