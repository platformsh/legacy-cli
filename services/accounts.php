<?php

return array(
    'name' => 'Accounts API',
    'operations' => array(
        'getSshKeys' => array(
            'httpMethod' => 'GET',
            'uri' => 'me',
            'summary' => 'Gets a list of ssh_keys',
            'responseClass' => 'SshKeys',
        ),
        'createSshKey' => array(
            'httpMethod' => 'POST',
            'uri' => 'ssh_keys',
            'summary' => 'Creates a new ssh key',
            'parameters' => array(
                'title' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
                'value' => array(
                    'location' => 'json',
                    'type' => 'string',
                ),
            ),
        ),
        'deleteSshKey' => array(
            'httpMethod' => 'DELETE',
            'uri' => 'ssh_keys/{id}',
            'summary' => 'Deletes an ssh key',
            'parameters' => array(
                'id' => array(
                    'location' => 'uri',
                    'description' => 'SSH Key ID',
                    'required' => true,
                ),
            ),
        ),
        'getProjects' => array(
            'httpMethod' => 'GET',
            'uri' => 'me',
            'summary' => 'Gets a list of projects',
            'responseClass' => 'Projects',
        ),
    ),
    'models' => array(
        'SshKeys' => array(
            'type' => 'array',
            'additionalProperties' => false,
            'properties' => array(
                'keys' => array(
                    'location' => 'json',
                    'sentAs' => 'ssh_keys',
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => array(
                            'id' => array(
                                'type' => 'integer',
                                'sentAs' => 'key_id',
                            ),
                            'title' => array(
                                'type' => 'string',
                            ),
                            'fingerprint' => array(
                                'type' => 'string',
                            ),
                        ),
                    ),
                ),
            ),
        ),
        'Project' => array(
            'location' => 'json',
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => array(
                'name' => array(
                    'type' => 'string',
                ),
                'uri' => array(
                    'type' => 'string',
                ),
                'endpoint' => array(
                    'type' => 'string',
                ),
            ),
        ),
        'Projects' => array(
            'type' => 'array',
            'additionalProperties' => false,
            'properties' => array(
                'projects' => array(
                    'location' => 'json',
                    'sentAs' => 'projects',
                    'type' => 'array',
                    'items' => array(
                        '$ref' => 'Project',
                    ),
                ),
            ),
        ),
    ),
);
