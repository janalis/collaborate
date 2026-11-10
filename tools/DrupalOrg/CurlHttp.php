<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools\DrupalOrg;

/** {@see Http} with curl. */
final class CurlHttp implements Http
{
    public function request(string $method, string $url, array $headers = [], array $form = []): array
    {
        $curl = curl_init($url);
        $lines = ['Accept: application/json', 'User-Agent: janalis-collaborate-drupalorg-watcher'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($curl, [
            \CURLOPT_CUSTOMREQUEST => $method,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_TIMEOUT => 60,
            \CURLOPT_HTTPHEADER => $lines,
        ]);
        if ([] !== $form) {
            curl_setopt($curl, \CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = curl_exec($curl);
        if (!\is_string($body)) {
            throw new \RuntimeException(\sprintf('%s %s: %s', $method, $url, curl_error($curl)));
        }

        return [(int) curl_getinfo($curl, \CURLINFO_RESPONSE_CODE), json_decode($body, true)];
    }
}
