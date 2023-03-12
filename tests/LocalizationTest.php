<?php

class LocalizationTest extends PHPUnit\Framework\TestCase
{
    /**
     * Test all localization files for possible errors
     */
    function test_localization()
    {
        // Any error/warning will fail the 
        foreach (glob(__DIR__ . '/../localization/*.inc') as $file) {
            $labels = $messages = [];

            include $file;

            $this->assertTrue(!empty($labels));
        }
    }
}
