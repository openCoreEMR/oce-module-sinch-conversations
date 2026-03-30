<?php

/**
 * Unit tests for GlobalsAccessor
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Tests\Unit;

use OpenCoreEMR\Modules\SinchConversations\GlobalsAccessor;
use OpenEMR\Core\Kernel;
use PHPUnit\Framework\TestCase;

class GlobalsAccessorTest extends TestCase
{
    private GlobalsAccessor $accessor;

    protected function setUp(): void
    {
        $this->accessor = new GlobalsAccessor();

        // Set up some test globals
        $GLOBALS['test_string'] = 'hello';
        $GLOBALS['test_int'] = 42;
        $GLOBALS['test_bool_true'] = true;
        $GLOBALS['test_bool_false'] = false;
        $GLOBALS['test_string_bool'] = '1';
        $GLOBALS['test_null'] = null;
    }

    protected function tearDown(): void
    {
        // Clean up test globals
        unset($GLOBALS['test_string']);
        unset($GLOBALS['test_int']);
        unset($GLOBALS['test_bool_true']);
        unset($GLOBALS['test_bool_false']);
        unset($GLOBALS['test_string_bool']);
        unset($GLOBALS['test_null']);
        unset($GLOBALS['test_new_key']);
    }

    public function testGet(): void
    {
        $this->assertEquals('hello', $this->accessor->get('test_string'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', $this->accessor->get('nonexistent', 'default'));
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->accessor->get('nonexistent'));
    }

    public function testSet(): void
    {
        $this->accessor->set('test_new_key', 'new_value');
        $this->assertEquals('new_value', $GLOBALS['test_new_key']);
    }

    public function testHas(): void
    {
        $this->assertTrue($this->accessor->has('test_string'));
        $this->assertFalse($this->accessor->has('nonexistent'));
    }

    public function testHasReturnsFalseForNull(): void
    {
        // isset() returns false for null values
        $this->assertFalse($this->accessor->has('test_null'));
    }

    public function testGetString(): void
    {
        $this->assertEquals('hello', $this->accessor->getString('test_string'));
    }

    public function testGetStringWithDefault(): void
    {
        $this->assertEquals('default', $this->accessor->getString('nonexistent', 'default'));
    }

    public function testGetStringCastsInt(): void
    {
        $this->assertEquals('42', $this->accessor->getString('test_int'));
    }

    public function testGetBoolean(): void
    {
        $this->assertTrue($this->accessor->getBoolean('test_bool_true'));
        $this->assertFalse($this->accessor->getBoolean('test_bool_false'));
    }

    public function testGetBooleanWithDefault(): void
    {
        $this->assertTrue($this->accessor->getBoolean('nonexistent', true));
        $this->assertFalse($this->accessor->getBoolean('nonexistent', false));
    }

    public function testGetBooleanFromString(): void
    {
        $this->assertTrue($this->accessor->getBoolean('test_string_bool'));
    }

    public function testGetInt(): void
    {
        $this->assertEquals(42, $this->accessor->getInt('test_int'));
    }

    public function testGetIntWithDefault(): void
    {
        $this->assertEquals(100, $this->accessor->getInt('nonexistent', 100));
    }

    public function testGetIntCastsString(): void
    {
        $GLOBALS['test_int_string'] = '123';
        $this->assertEquals(123, $this->accessor->getInt('test_int_string'));
        unset($GLOBALS['test_int_string']);
    }

    public function testAll(): void
    {
        $all = $this->accessor->all();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('test_string', $all);
        $this->assertEquals('hello', $all['test_string']);
    }

    public function testGetKernelReturnsKernel(): void
    {
        $kernel = new Kernel();
        $GLOBALS['kernel'] = $kernel;

        $this->assertSame($kernel, $this->accessor->getKernel());

        unset($GLOBALS['kernel']);
    }

    public function testGetKernelThrowsWhenNotAvailable(): void
    {
        unset($GLOBALS['kernel']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenEMR Kernel not available');

        $this->accessor->getKernel();
    }

    public function testGetKernelThrowsWhenWrongType(): void
    {
        $GLOBALS['kernel'] = 'not a kernel';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OpenEMR Kernel not available');

        $this->accessor->getKernel();

        unset($GLOBALS['kernel']);
    }
}
