<?php

declare(strict_types=1);

namespace Janalis\Collaborate\Tests\Application\Shared;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Article;
use Janalis\Collaborate\Tests\Application\Shared\Entity\Tag;

/** The test applications' databases: one per application and environment, next to the one `COLLABORATE_DATABASE_URL` names. */
final class Database
{
    public const string DEFAULT_URL = 'mysql://root@127.0.0.1:3306/collaborate_test?serverVersion=10.11.0-MariaDB';

    /** `COLLABORATE_DATABASE_URL` with its database replaced by `collaborate_<app>_<environment>`. */
    public static function url(string $app, string $environment): string
    {
        $url = Environment::get('COLLABORATE_DATABASE_URL') ?? self::DEFAULT_URL;
        $token = Environment::get('TEST_TOKEN');
        $suffix = (string) preg_replace('/\W/', '_', $environment . (null === $token ? '' : '_' . $token));
        $name = 'collaborate_' . $app . '_' . $suffix;

        return (string) preg_replace('#^([a-z0-9+]+://[^/]+)(/[^?]*)?#i', '$1/' . $name, $url, 1);
    }

    /** Creates the database when missing, then the application's and the bundle's tables from their mapping, empty but for the fixtures. */
    public static function reset(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $params = $connection->getParams();
        $name = $params['dbname'] ?? null;
        \assert(\is_string($name));
        unset($params['dbname']);
        $server = DriverManager::getConnection($params);
        try {
            $server->executeStatement('CREATE DATABASE IF NOT EXISTS ' . $server->quoteSingleIdentifier($name));
        } finally {
            $server->close();
        }
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        self::load($em);
    }

    /** One article with two ordered tags. */
    public static function load(EntityManagerInterface $em): Article
    {
        $article = new Article('Welcome');
        $article->summary = "First line\nSecond line";
        $article->content = '<p>Hello <strong>world</strong></p>';
        $article->addTag(new Tag('alpha', 0));
        $article->addTag(new Tag('beta', 1));
        $em->persist($article);
        $em->flush();
        $em->clear();

        return $article;
    }
}
