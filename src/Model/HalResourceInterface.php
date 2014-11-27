<?php
namespace CommerceGuys\Platform\Cli\Model;

use Guzzle\Http\Client as HttpClient;

interface HalResourceInterface
{

    /**
     * Create a resource.
     *
     * @param array      $values
     * @param string     $collectionUrl
     * @param HttpClient $client
     *
     * @return HalResourceInterface|false
     */
    public static function create(array $values, $collectionUrl, HttpClient $client);

    /**
     * Get a resource.
     *
     * @param string     $id
     * @param string     $collectionUrl
     * @param HttpClient $client
     *
     * @return HalResourceInterface|false
     */
    public static function get($id, $collectionUrl, HttpClient $client);

    /**
     * Constructor.
     *
     * @param array $data
     *   The complete JSON data fetched for this resource.
     * @param HttpClient $client
     *   A Guzzle client.
     */
    public function __construct(array $data, HttpClient $client = null);

    /**
     * Get the ID for this resource.
     *
     * @return string
     */
    public function id();

    /**
     * @param string $operation
     * @return bool
     */
    public function operationAllowed($operation);

    /**
     * Update this resource.
     *
     * @param array $values
     *
     * @throws \RuntimeException
     *
     * @return bool
     */
    public function update(array $values);

    /**
     * Delete this resource.
     *
     * @throws \RuntimeException
     *
     * @return bool
     */
    public function delete();

    /**
     * Set the Guzzle client for this resource.
     *
     * @param HttpClient $client
     */
    public function setClient(HttpClient $client);

    /**
     * @param string $property
     *
     * @throws \InvalidArgumentException
     *
     * @return mixed
     */
    public function getProperty($property);

    /**
     * @param $rel
     * @return string
     */
    public function getLink($rel = 'self');

    /** @var array */
    public function getData();

    /**
     * @return string[]
     *   An array of the resource's property names.
     */
    public function getPropertyNames();

    /**
     * @return array
     *   An array of the resource's properties, keyed by their names.
     */
    public function getProperties();

}
