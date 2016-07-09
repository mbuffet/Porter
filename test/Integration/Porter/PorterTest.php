<?php
namespace ScriptFUSIONTest\Integration\Porter;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use ScriptFUSION\Mapper\CollectionMapper;
use ScriptFUSION\Mapper\Mapping;
use ScriptFUSION\Porter\Cache\CacheAdvice;
use ScriptFUSION\Porter\Cache\CacheToggle;
use ScriptFUSION\Porter\Cache\CacheUnavailableException;
use ScriptFUSION\Porter\Collection\FilteredRecords;
use ScriptFUSION\Porter\Collection\MappedRecords;
use ScriptFUSION\Porter\Collection\PorterRecords;
use ScriptFUSION\Porter\Collection\ProviderRecords;
use ScriptFUSION\Porter\Porter;
use ScriptFUSION\Porter\Provider\DataSource\ProviderDataSource;
use ScriptFUSION\Porter\Provider\Provider;
use ScriptFUSION\Porter\ProviderNotFoundException;
use ScriptFUSION\Porter\Specification\ImportSpecification;
use ScriptFUSIONTest\MockFactory;

final class PorterTest extends \PHPUnit_Framework_TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var Porter */
    private $porter;

    /** @var Provider|MockInterface */
    private $provider;

    /** @var ProviderDataSource */
    private $dataSource;

    protected function setUp()
    {
        $this->porter = (new Porter)->addProvider(
            $this->provider =
                \Mockery::mock(Provider::class)
                    ->shouldReceive('fetch')
                    ->andReturn(new \ArrayIterator(['foo']))
                    ->byDefault()
                    ->getMock()
        );

        $this->dataSource = MockFactory::mockDataSource($this->provider);
    }

    public function testGetProvider()
    {
        self::assertSame($this->provider, $this->porter->getProvider(get_class($this->provider)));
    }

    public function testGetInvalidProvider()
    {
        $this->setExpectedException(ProviderNotFoundException::class);

        (new Porter)->getProvider('foo');
    }

    public function testAddProviders()
    {
        $this->porter->addProviders([
            $this->provider,
            $provider = $this->getMockBuilder(Provider::class)->disableOriginalConstructor()->getMock(),
        ]);

        self::assertSame($this->provider, $this->porter->getProvider(get_class($this->provider)));
        self::assertSame($provider, $this->porter->getProvider(get_class($provider)));
    }

    public function testImport()
    {
        $records = $this->porter->import(new ImportSpecification($this->dataSource));

        self::assertInstanceOf(PorterRecords::class, $records);
        self::assertInstanceOf(ProviderRecords::class, $records->getPreviousCollection());
        self::assertSame('foo', $records->current());
    }

    public function testFilter()
    {
        $this->provider->shouldReceive('fetch')->andReturn(new \ArrayIterator(range(1, 10)));

        $records = $this->porter->import(
            (new ImportSpecification($this->dataSource))
                ->setFilter(function ($record) {
                    return $record % 2;
                })
        );

        self::assertInstanceOf(PorterRecords::class, $records);
        self::assertInstanceOf(FilteredRecords::class, $records->getPreviousCollection());
        self::assertSame([1, 3, 5, 7, 9], iterator_to_array($records));
    }

    public function testMap()
    {
        $records = $this->porter->setMapper(
            \Mockery::mock(CollectionMapper::class)
                ->shouldReceive('mapCollection')
                ->with(\Mockery::type(\Iterator::class), \Mockery::type(Mapping::class), \Mockery::any())
                ->once()
                ->andReturn(new \ArrayIterator($result = ['foo' => 'bar']))
                ->getMock()
        )->import(
            (new ImportSpecification($this->dataSource))->setMapping(\Mockery::mock(Mapping::class))
        );

        self::assertInstanceOf(PorterRecords::class, $records);
        self::assertInstanceOf(MappedRecords::class, $records->getPreviousCollection());
        self::assertSame($result, iterator_to_array($records));
    }

    public function testApplyCacheAdvice()
    {
        $this->porter->addProvider(
            $provider = \Mockery::mock(implode(',', [Provider::class, CacheToggle::class]))
                ->shouldReceive('fetch')->andReturn(new \EmptyIterator)
                ->shouldReceive('disableCache')->once()
                ->shouldReceive('enableCache')->once()
                ->getMock()
        );

        $this->porter->import($specification = new ImportSpecification(MockFactory::mockDataSource($provider)));
        $this->porter->import($specification->setCacheAdvice(CacheAdvice::SHOULD_CACHE()));
    }

    public function testCacheUnavailable()
    {
        $this->setExpectedException(CacheUnavailableException::class);

        $this->porter->import((new ImportSpecification($this->dataSource))->setCacheAdvice(CacheAdvice::MUST_CACHE()));
    }
}
