<?php
namespace CommerceGuys\Platform\Cli\Model;

use Cocur\Slugify\Slugify;

class Environment extends HalResource
{

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
     * @return HalResource[]
     */
    public function getVariables()
    {
        return self::getCollection($this->getLink('#manage-variables'), array(), $this->client);
    }

    /**
     * Get a single variable.
     *
     * @param string $id
     *
     * @return HalResource|false
     */
    public function getVariable($id)
    {
        return self::get($id, $this->getLink('#manage-variables'), $this->client);
    }

    /**
     * Set a variable
     *
     * @param string $name
     * @param mixed $value
     * @param bool $json
     *
     * @return HalResource|false
     */
    public function setVariable($name, $value, $json = false)
    {
        if (!is_scalar($value)) {
            $value = json_encode($value);
            $json = true;
        }
        $values = array('value' => $value, 'is_json' => $json);
        $existing = $this->getVariable($name);
        if ($existing) {
            return $existing->update($values) ? $existing : false;
        }
        $values['name'] = $name;
        return self::create($values, $this->getLink('#manage-variables'), $this->client);
    }

    /**
     * @param string $proposed
     * @return string
     */
    public static function sanitizeId($proposed)
    {
        $slugify = new Slugify();
        return substr($slugify->slugify($proposed), 0, 32);
    }

}
