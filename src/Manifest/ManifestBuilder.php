<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Manifest;

use Smartlabsys\SlsConnectorBundle\SlsConnectorBundle;

/**
 * Builds `/.well-known/sls-app.json` (doc 05 §1) from the bundle configuration. The six required
 * endpoints and the optional `login` (where the SLS app launcher sends users to start sign-in) are
 * the bundle's own fixed routes.
 */
final class ManifestBuilder
{
    public const ENDPOINTS = [
        'health'             => '/sls/health',
        'provisioning'       => '/sls/provisioning',
        'scim'               => '/scim/v2',
        'webhooks'           => '/sls/webhooks',
        'oidc_callback'      => '/sls/oidc/callback',
        'backchannel_logout' => '/sls/oidc/backchannel-logout',
        'login'              => '/sls/oidc/login',
    ];

    /** @param array<string, mixed> $config the processed `sls_connector` configuration */
    public function __construct(private array $config) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $endpoints = self::ENDPOINTS;
        foreach ($this->config['endpoints'] as $name => $path) {
            if ($path !== null) {
                $endpoints[$name] = $path;
            }
        }

        return [
            'contract_version' => SlsConnectorBundle::CONTRACT_VERSION,
            'app'              => [
                'key'     => $this->config['app']['key'],
                'name'    => $this->config['app']['name'],
                'version' => (string) $this->config['app']['version'],
            ],
            'endpoints'        => $endpoints,
            'roles'            => array_map(static fn (array $role): array => array_filter([
                'key'         => $role['key'],
                'label'       => $role['label'],
                'description' => $role['description'] ?: null,
            ]), $this->config['roles']),
            'seed_templates'   => array_map(static fn (array $template): array => array_filter([
                'key'         => $template['key'],
                'version'     => $template['version'],
                'label'       => $template['label'],
                'description' => $template['description'] ?: null,
                'parameters'  => $template['parameters'] ?: null,
            ]), $this->config['seed_templates']),
            'integration'      => [
                'provides' => array_map(static fn (array $provided): array => array_filter([
                    'scope'       => $provided['scope'],
                    'label'       => $provided['label'],
                    'description' => $provided['description'] ?: null,
                ]), $this->config['integration']['provides']),
                'uses'     => array_map(static fn (array $used): array => [
                    'app'    => $used['app'],
                    'scopes' => array_values($used['scopes']),
                ], $this->config['integration']['uses']),
            ],
            'events'           => [
                'emits'    => array_values($this->config['events']['emits']),
                'consumes' => array_values($this->config['events']['consumes']),
            ],
        ];
    }

    public function appVersion(): string
    {
        return (string) $this->config['app']['version'];
    }
}
