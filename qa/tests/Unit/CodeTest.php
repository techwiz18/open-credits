<?php

declare(strict_types=1);

namespace OpenCredits\Credits\Tests\Unit;

use OpenCredits\Credits\Entity\Code;
use PHPUnit\Framework\TestCase;

class CodeTest extends TestCase
{
    public function testNormalizeUpperCasesAndStrips(): void
    {
        $this->assertSame('ABCDEF23456789', Code::normalize('abcdef23456789'));
        $this->assertSame('ABCDEF', Code::normalize('ab-cd ef'));
        // 0/1 are outside the code alphabet and stripped everywhere
        $this->assertSame('ABC', Code::normalize('A0B1C'));
        $this->assertSame('', Code::normalize('  -  '));
        $this->assertSame('', Code::normalize(''));
    }

    public function testGenerateLengthAndAlphabet(): void
    {
        $code = Code::generate();
        $this->assertSame(Code::CODE_LENGTH, strlen($code));
        $this->assertMatchesRegularExpression('/^[' . Code::CODE_ALPHABET . ']+$/', $code);
        // Unambiguous alphabet: no 0/O/1/I
        $this->assertStringNotContainsString('0', $code);
        $this->assertStringNotContainsString('1', $code);
        $this->assertStringNotContainsString('O', $code);
        $this->assertStringNotContainsString('I', $code);
    }

    public function testGenerateUniquenessSample(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $codes[] = Code::generate();
        }
        $this->assertCount(200, array_unique($codes));
    }

    public function testGeneratedCodesSurviveNormalize(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $code = Code::generate();
            $this->assertSame($code, Code::normalize(strtolower($code)));
        }
    }
}
