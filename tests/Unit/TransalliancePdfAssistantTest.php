<?php

namespace Tests\Unit;

use App\Assistants\TransalliancePdfAssistant;
use PHPUnit\Framework\TestCase;

class TransalliancePdfAssistantTest extends TestCase
{
    public function test_transalliance_parses_and_comments_collected()
    {
        $file = realpath(__DIR__ . '/../../storage/pdf_client_test/TransalliancePdfAssistant_1.pdf');
        $this->assertFileExists($file);
        $assistant = new TransalliancePdfAssistant();
        $output = $assistant->processPath($file);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('loading_locations', $output);
        $this->assertArrayHasKey('destination_locations', $output);
        $this->assertArrayHasKey('comment', $output);
    }
}
