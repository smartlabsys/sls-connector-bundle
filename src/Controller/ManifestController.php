<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Smartlabsys\SlsConnectorBundle\Manifest\ManifestBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** `GET /.well-known/sls-app.json` — public (doc 05 §1). */
final class ManifestController
{
    public function __construct(private ManifestBuilder $manifest) {}

    #[Route('/.well-known/sls-app.json', name: 'sls_connector.manifest', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse($this->manifest->build());
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->headers->set('Cache-Control', 'public, max-age=300');

        return $response;
    }
}
