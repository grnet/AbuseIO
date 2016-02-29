<?php
namespace tests\Helpers;

use tests\TestCase;

class GeneratePasswordTest extends TestCase
{
    public function testMd5()
    {
        $this->assertRegExp('/^[a-z0-9]{8}$/', generatePassword());
    }
}
