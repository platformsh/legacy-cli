<?php
namespace CommerceGuys\Platform\Cli\Model;

use Guzzle\Http\Client as HttpClient;
use Guzzle\Http\Exception\ClientErrorResponseException;

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
     * Get a resource at a URL.
     *
     * @param string $id
     * @param string $collectionUrl
     * @param HttpClient $client
     *
     * @throws ClientErrorResponseException On failure.
     *
     * @return HalResource|false
     *   The resource, or false if it has not been found (if the response code
     *   is 404).
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
     * @param string $rel
     * @param bool $absolute
     *
     * @return string
     */
    public function getLink($rel = 'self', $absolute = false);

    /** @return array */
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
