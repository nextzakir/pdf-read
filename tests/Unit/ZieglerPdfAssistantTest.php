<?php

namespace Tests\Unit;

use App\Assistants\ZieglerPdfAssistant;
use PHPUnit\Framework\TestCase;

class ZieglerPdfAssistantTest extends TestCase
{
    public function test_ziegler_no_intro_in_locations_and_in_comment()
    {
        $file = realpath(__DIR__ . '/../../storage/pdf_client_test/ZieglerPdfAssistant_1.pdf');
        $this->assertFileExists($file);
        $assistant = new ZieglerPdfAssistant();
        $output = $assistant->processPath($file);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('customer', $output);
        $this->assertArrayHasKey('loading_locations', $output);
        $this->assertArrayHasKey('destination_locations', $output);
    }
}
