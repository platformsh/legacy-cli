<?php
namespace CommerceGuys\Platform\Cli\Model;

use Guzzle\Http\Client as HttpClient;

interface ResourceInterface
{

    public function __construct(array $data);

    /** @return string */
    public function getId();

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

}
