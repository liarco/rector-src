<?php

namespace spec\Rector\Tests\PhpSpecToPHPUnit\Rector\Variable\PhpSpecToPHPUnitRector\Fixture;

use PhpSpec\ObjectBehavior;
use Rector\Tests\PhpSpecToPHPUnit\Rector\Variable\PhpSpecToPHPUnitRector\Source\OrderFactory;

class MockPropertiesSpec extends ObjectBehavior
{
    public function let(OrderFactory $factory)
    {
        $this->beConstructedWith($factory);
    }

    public function let_it_go(OrderFactory $factory)
    {
        $factory->someMethod()->shouldBeCalled();
        $this->run();
    }
}

?>
-----
<?php

namespace Tests\Rector\Tests\PhpSpecToPHPUnit\Rector\Variable\PhpSpecToPHPUnitRector\Fixture;

use PhpSpec\ObjectBehavior;
use Rector\Tests\PhpSpecToPHPUnit\Rector\Variable\PhpSpecToPHPUnitRector\Source\OrderFactory;

class MockPropertiesTest extends \PHPUnit\Framework\TestCase
{
    private \Rector\Tests\PhpSpecToPHPUnit\Rector\Variable\PhpSpecToPHPUnitRector\Fixture\MockProperties $mockProperties;
    private \PHPUnit\Framework\MockObject\MockObject|\Rector\Tests\PhpSpecToPHPUnit\Rector\Variable\PhpSpecToPHPUnitRector\Source\OrderFactory $factory;
    protected function setUp()
    {
        $this->factory = $this->createMock(OrderFactory::class);
        $this->mockProperties = new \Rector\Tests\PhpSpecToPHPUnit\Rector\Variable\PhpSpecToPHPUnitRector\Fixture\MockProperties($this->factory);
    }

    public function testLetItGo()
    {
        $this->factory->expects($this->atLeastOnce())->method('someMethod');
        $this->mockProperties->run();
    }
}

?>
