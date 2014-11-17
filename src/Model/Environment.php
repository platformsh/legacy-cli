<?php
namespace CommerceGuys\Platform\Cli\Model;

use Guzzle\Http\Exception\ClientErrorResponseException;

class Environment extends Resource
{

    /** @var Resource[] */
    protected $variables;

    /**
     * Get the SSH URL for the environment.
     *
     * @throws \Exception
     *
     * @return string
     */
    public function getSshUrl()
    {
        if (!isset($this->data['_links']['ssh']['href'])) {
            $id = $this->data['id'];
            throw new \Exception("The environment $id does not have an SSH URL.");
        }

        $sshUrl = parse_url($this->data['_links']['ssh']['href']);
        $host = $sshUrl['host'];
        $user = $sshUrl['user'];

        return $user . '@' . $host;
    }

    /**
     * Get a list of variables.
     *
     * @return Resource[]
     */
    public function getVariables()
    {
        return $this->getCollection('variables');
    }

    /**
     * Get a single variable.
     *
     * @param string $name
     *
     * @return Resource|false
     */
    public function getVariable($name)
    {
        try {
            $variable = $this->client
              ->get('variables/' . urlencode($name))
              ->send()
              ->json();
        }
        catch (ClientErrorResponseException $e) {
            return false;
        }
        $variable = new Resource($variable);
        $variable->setClient($this->client);
        return $variable;
    }

    /**
     * Set a variable
     *
     * @param string $name
     * @param mixed $value
     * @param bool $json
     *
     * @return Resource|false
     */
    public function setVariable($name, $value, $json = false)
    {
        if (!is_scalar($value)) {
            $value = json_encode($value);
            $json = true;
        }
        $existing = $this->getVariable($name);
        if ($existing) {
            $values = array('value' => $value, 'is_json' => $json);
            return $existing->update($values) ? $existing : false;
        }
        return $this->createVariable($name, $value, $json);
    }

    /**
     * Create a variable
     *
     * @param string $name
     * @param string $value
     * @param bool $json
     *
     * @return Resource|false
     */
    protected function createVariable($name, $value, $json = false)
    {
        $body = array('name' => $name, 'value' => $value, 'is_json' => $json);
        $response = $this->client
          ->post('variables', null, json_encode($body))
          ->send();
        if ($response->getStatusCode() == 201) {
            return new Resource($response->json());
        }
        return false;
    }

}
