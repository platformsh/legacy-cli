<?php

return array(
    'name' => 'Platform API',
    'operations' => array(
        'getEnvironments' => array(
            'httpMethod' => 'GET',
            'uri' => 'environments',
            'summary' => 'Gets a list of environments',
        ),
        'deleteEnvironment' => array(
            'httpMethod' => 'DELETE',
            'uri' => '',
            'summary' => 'Deletes an environment',
        ),
        'activateEnvironment' => array(
            'httpMethod' => 'POST',
            'uri' => 'activate',
            'summary' => 'Activates an environment',
        ),
        'deactivateEnvironment' => array(
            'httpMethod' => 'POST',
            'uri' => 'deactivate',
            'summary' => 'Deactivates an environment',
        ),
        'branchEnvironment' => array(
            'httpMethod' => 'POST',
            'uri' => 'branch',
            'summary' => 'Creates a new environment branched from the existing one',
            'parameters' => array(
                'name' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'title' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
            ),
        ),
        'mergeEnvironment' => array(
            'httpMethod' => 'POST',
            'uri' => 'merge',
            'summary' => 'Merges an environment into its parent',
        ),
        'synchronizeEnvironment' => array(
            'httpMethod' => 'POST',
            'uri' => 'synchronize',
            'summary' => 'Synchronizes an environment with its parent',
            'parameters' => array(
                'synchronize_code' => array(
                    'location' => 'json',
                    'type' => 'boolean',
                ),
                'synchronize_data' => array(
                    'location' => 'json',
                    'type' => 'boolean',
                )
            )
        ),
        'backupEnvironment' => array(
            'httpMethod' => 'POST',
            'uri' => 'backup',
            'summary' => 'Creates a new environment branched from the existing one',
        ),
        'getDomains' => array(
            'httpMethod' => 'GET',
            'uri' => 'domains',
            'summary' => 'Gets a list of domains',
        ),
        'addDomain' => array(
            'httpMethod' => 'POST',
            'uri' => 'domains',
            'summary' => 'Add a domain',
            'parameters' => array(
                'name' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'wildcard' => array(
                    'location' => 'json',
                    'type' => 'boolean',
                ),
            ),
            'additionalParameters' => array(
                'location' => 'json',
            ),
        ),
        'deleteDomain' => array(
            'httpMethod' => 'DELETE',
            'uri' => '',
            'summary' => 'Delete a domain',
            'parameters' => array(
                'name' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
            ),
        ),
    ),
    'models' => array(
        'Environment' => array(
            'location' => 'json',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'id' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'title' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'parent' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                '_links' => array(
                    'location' => 'json',
                    'type' => 'object',
                    'properties' => array(
                        'self' => array(
                            'type' => 'string',
                        ),
                        'public-url' => array(
                            'type' => 'string',
                        ),
                        'ssh' => array(
                            'type' => 'string',
                        ),
                    ),
                ),
            ),
        ),
        'Environments' => array(
            'type' => 'object',
            // This is the only way to make Guzzle support responses with arrays.
            'additionalProperties' => array(
                '$ref' => 'Environment',
            ),
        ),
        'Domain' => array(
            'location' => 'json',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'id' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'name' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'created_at' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'updated_at' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'wildcard' => array(
                    'location' => 'json',
                    'type' => 'boolean',
                ),
                '_links' => array(
                    'location' => 'json',
                    'type' => 'object',
                    'properties' => array(
                        'self' => array(
                            'type' => 'string',
                        ),
                    ),
                ),
                'ssl' => array(
                    'location' => 'json',
                    'type' => 'object',
                    'properties' => array(
                        'has_certificate' => array(
                            'type' => 'boolean',
                        ),
                    ),
                ),
            ),
        ),
        'Domains' => array(
            'type' => 'object',
            // This is the only way to make Guzzle support responses with arrays.
            'additionalProperties' => array(
                '$ref' => 'Domain',
            ),
        ),
    ),
);
