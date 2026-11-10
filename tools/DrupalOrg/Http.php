<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tools\DrupalOrg;

/** JSON over HTTP: what the watcher asks drupal.org and its GitLab. */
interface Http
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $form    sent as `application/x-www-form-urlencoded` unless empty
     *
     * @return array{int, mixed} the status, and the decoded body (null when it is not JSON)
     */
    public function request(string $method, string $url, array $headers = [], array $form = []): array;
}
