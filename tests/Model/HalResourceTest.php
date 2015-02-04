<?php

namespace CommerceGuys\Platform\Cli\Tests;

use CommerceGuys\Platform\Cli\Model\HalResource;

class HalResourceTest extends \PHPUnit_Framework_TestCase
{

    /** @var array */
    protected $properties;

    /** @var HalResource */
    protected $resource;

    public function setUp()
    {
        $this->properties = array(
          'id' => 'test-id',
          'name' => 'test name',
          'array' => array(),
          'integer' => 123,
        );
        $data = $this->properties + array(
            '_embedded' => array(),
            '_links' => array(
                'self' => array(
                  'href' => 'https://example.com/',
                ),
                '#operate' => array(
                  'href' => 'https://example.com/operate',
                ),
            ),
          );
        $this->resource = new HalResource($data);
    }

    /**
     * Test HalResource::getProperties().
     */
    public function testGetProperties()
    {
        $this->assertEquals(array_keys($this->properties), array_values($this->resource->getPropertyNames()));
        $this->assertEquals($this->properties, $this->resource->getProperties());
    }

    /**
     * Test HalResource::getProperty().
     */
    public function testGetProperty()
    {
        $this->assertEquals('test-id', $this->resource->id());
        $this->assertEquals('test name', $this->resource->getProperty('name'));
        $this->setExpectedException('\InvalidArgumentException');
        $this->resource->getProperty('nonexistent');
    }

    /**
     * Test HalResource::operationAvailable().
     */
    public function testOperationAvailable()
    {
        $this->assertTrue($this->resource->operationAvailable('operate'));
        $this->assertFalse($this->resource->operationAvailable('nonexistent'));
    }

    /**
     * Test HalResource::operationAllowed().
     */
    public function testGetLink()
    {
        $this->assertNotEmpty($this->resource->getLink());
        $this->assertNotEmpty($this->resource->getLink('#operate'));
        $this->setExpectedException('\InvalidArgumentException');
        $this->resource->getLink('nonexistent');
    }

}
