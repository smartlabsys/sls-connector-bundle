<?php

declare(strict_types=1);

namespace Smartlabsys\SlsConnectorBundle\Controller;

use Smartlabsys\SlsConnectorBundle\Exception\ContractException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** JSON answers of the contract endpoints: `{"error": code, "message": …}` on failure. */
trait ContractResponses
{
    /** @param callable(): JsonResponse $action */
    private function handle(callable $action): JsonResponse
    {
        try {
            $response = $action();
        } catch (ContractException $e) {
            $response = new JsonResponse(['error' => $e->error, 'message' => $e->getMessage()], $e->status);
            if ($e->status === 401) {
                $response->headers->set('WWW-Authenticate', 'Bearer error="invalid_token"');
            }
        }
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ContractException::badRequest('The body must be a JSON object.', 'invalidSyntax');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ContractException::badRequest('The body must be a JSON object.', 'invalidSyntax');
        }

        return $data;
    }
}
