<?php
namespace CommerceGuys\Platform\Cli\Model;

use Guzzle\Http\Client as HttpClient;
use Guzzle\Http\Exception\ClientErrorResponseException;

/**
 * @todo make this work for all hypermedia entities/actions.
 */
class HalResource implements HalResourceInterface
{

    /** @var array */
    protected $data;

    /** @var HttpClient */
    protected $client;

    /**
     * @inheritdoc
     */
    public function __construct(array $data, HttpClient $client = null)
    {
        $this->data = $data;
        $this->client = $client;
    }

    /**
     * Get a resource at a URL.
     *
     * @param string $id
     * @param string $collectionUrl
     * @param HttpClient $client
     *
     * @return HalResource|false
     */
    public static function get($id, $collectionUrl, HttpClient $client)
    {
        try {
            $data = $client->get($collectionUrl . '/' . urlencode($id))
                           ->send()
                           ->json();
        }
        catch (ClientErrorResponseException $e) {
            return false;
        }
        return new HalResource($data, $client);
    }

    /**
     * Create a resource.
     *
     * @param array $values
     * @param string $collectionUrl
     * @param HttpClient $client
     *
     * @return HalResource|false
     */
    public static function create(array $values, $collectionUrl, HttpClient $client)
    {
        $response = $client
          ->post($collectionUrl, null, json_encode($values))
          ->send();
        if ($response->getStatusCode() == 201) {
            return new HalResource($response->json(), $client);
        }
        return false;
    }

    /**
     * @inheritdoc
     */
    public function id()
    {
        return $this->getProperty('id');
    }

    /**
     * Check whether the previous operation returned an activity resource.
     *
     * @return bool
     */
    public function hasActivity()
    {
        return !empty($this->data['_embedded']['activities']);
    }

    /**
     * Execute an operation on the resource.
     *
     * This updates the internal 'data' property with the API response.
     *
     * @param string $op
     * @param string $method
     * @param mixed $body
     *
     * @return bool
     */
    protected function runOperation($op, $method = 'post', $body = null)
    {
        if (!$this->operationAllowed($op)) {
            throw new \RuntimeException("Operation not available: $op");
        }
        if ($body && !is_scalar($body)) {
            $body = json_encode($body);
        }
        $request = $this->client
          ->createRequest($method, $this->data['_links']['#' . $op]['href'], null, $body);
        $response = $request->send();
        $this->data = $response->json();
        return $response->getStatusCode() == 200;
    }

    /**
     * @inheritdoc
     */
    public function update(array $values)
    {
        return $this->runOperation('edit', 'patch', $values);
    }

    /**
     * @inheritdoc
     */
    public function delete()
    {
        return $this->runOperation('delete', 'delete');
    }

    /**
     * @inheritdoc
     */
    public function operationAllowed($operation)
    {
        return !empty($this->data['_links']['#' . $operation]);
    }

    /**
     * @inheritdoc
     */
    public function setClient(HttpClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param string $uri
     * @param HttpClient $client
     *
     * @return HalResource[]
     */
    public static function getCollection($uri, HttpClient $client)
    {
        $collection = $client
          ->get($uri)
          ->send()
          ->json();
        if (!is_array($collection)) {
            throw new \UnexpectedValueException("Unexpected response");
        }
        foreach ($collection as &$resource) {
            $resource = new HalResource($resource, $client);
        }
        return $collection;
    }

    /**
     * @param string $property
     * @return mixed
     */
    public function getProperty($property)
    {
        if (!isset($this->data[$property]) || strpos($property, '_') === 0) {
            throw new \InvalidArgumentException("Undefined property: $property");
        }
        return $this->data[$property];
    }

}
