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
        $this->setData($data);
        $this->client = $client;
    }

    /**
     * @inheritdoc
     */
    public static function get($id, $collectionUrl, HttpClient $client)
    {
        try {
            $data = $client->get($collectionUrl . '/' . urlencode($id))
                           ->send()
                           ->json();
        }
        catch (ClientErrorResponseException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }
        return new static($data, $client);
    }

    /**
     * @inheritdoc
     */
    public static function create(array $values, $collectionUrl, HttpClient $client)
    {
        $response = $client
          ->post($collectionUrl, null, json_encode($values))
          ->send();
        $data = $response->json();
        if (isset($data['_embedded']['entity'])) {
            $data = $data['_embedded']['entity'];
        }
        return new static($data, $client);
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
     * @return array
     */
    protected function runOperation($op, $method = 'post', $body = null)
    {
        if (!$this->operationAvailable($op)) {
            throw new \RuntimeException("Operation not available: $op");
        }
        if ($body && !is_scalar($body)) {
            $body = json_encode($body);
        }
        $request = $this->client
          ->createRequest($method, $this->getLink('#' . $op), null, $body);
        $response = $request->send();
        $data = (array) $response->json();
        if (!empty($data['_embedded']['entity'])) {
            $this->setData($data['_embedded']['entity']);
        }
        return $data;
    }

    /**
     * Refresh the current resource.
     *
     * @param array $options Request options.
     */
    public function refresh(array $options = array())
    {
        $response = $this->client->get($this->getLink(), null, $options)->send();
        $this->setData($response->json());
    }

    /**
     * @inheritdoc
     */
    public function getLink($rel = 'self', $absolute = false) {
        if (empty($this->data['_links'][$rel]['href'])) {
            throw new \InvalidArgumentException("Link not available: $rel");
        }
        $url = $this->data['_links'][$rel]['href'];
        if ($absolute && strpos($url, '//') === false) {
            $base = parse_url($this->client->getBaseUrl());
            $url = $base['scheme'] . '://' . $base['host'] . $url;
        }
        return $url;
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
    public function operationAvailable($operation)
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
     * @param array $options
     * @param HttpClient $client
     * @param HalResource $prototype
     *
     * @return HalResource[]
     */
    public static function getCollection($uri, array $options = array(), HttpClient $client, HalResource $prototype = null)
    {
        $collection = $client
          ->get($uri, null, $options)
          ->send()
          ->json();
        if (!is_array($collection)) {
            throw new \UnexpectedValueException("Unexpected response");
        }
        $resources = array();
        foreach ($collection as $data) {
            if ($prototype) {
                $resource = clone $prototype;
                $resource->setData($data);
            }
            else {
                $resource = new HalResource($data, $client);
            }
            $resources[$data['id']] = $resource;
        }
        return $resources;
    }

    /**
     * @param string $property
     * @param bool $required
     * @return mixed
     */
    public function getProperty($property, $required = true)
    {
        if (!array_key_exists($property, $this->data) || strpos($property, '_') === 0) {
            if (!$required) {
                return null;
            }
            throw new \InvalidArgumentException("Undefined property: $property");
        }
        return $this->data[$property];
    }

    /**
     * @param string $property
     * @param bool $required
     * @return string
     */
    public function getPropertyFormatted($property, $required = true)
    {
        $value = $this->getProperty($property, $required);
        if (!is_scalar($value)) {
            return json_encode($value);
        }
        elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        elseif ($property == 'created_at' || $property == 'updated_at') {
            return $this->getDate($value);
        }
        return $value;
    }

    /**
     * @return string[]
     */
    public function getPropertiesFormatted()
    {
        $output = array();
        foreach ($this->getPropertyNames() as $property) {
            $output[$property] = $this->getPropertyFormatted($property);
        }
        return $output;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @param array $data
     */
    public function setData(array $data)
    {
        $this->data = $data;
    }

    /**
     * @inheritdoc
     */
    public function getPropertyNames()
    {
        $keys = array_filter(array_keys($this->data), function($key) {
              return strpos($key, '_') !== 0;
          });
        return $keys;
    }

    /**
     * @inheritdoc
     */
    public function getProperties()
    {
        $keys = $this->getPropertyNames();
        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * @param string $timestamp
     * @param string $format
     *
     * @return string
     */
    public function getDate($timestamp, $format = 'Y-m-d H:i:s')
    {
        return date($format, strtotime($timestamp));
    }

}
