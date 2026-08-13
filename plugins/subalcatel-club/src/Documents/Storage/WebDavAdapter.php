<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents\Storage;

use RuntimeException;

/**
 * Stockage sur un partage WebDAV : Nextcloud, kDrive, ownCloud, Synology.
 *
 * Souvent le choix le plus simple pour une association qui dispose déjà d'un
 * espace collaboratif. À condition d'y créer un **dossier dédié et un compte
 * distinct** : ce n'est pas parce qu'on peut déposer les certificats médicaux
 * dans le Nextcloud du club qu'il faut les mettre à la racine du partage de
 * tout le bureau.
 */
final class WebDavAdapter implements StorageAdapter
{
    private string $baseUrl;
    private string $user;
    private string $password;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings)
    {
        $this->baseUrl  = rtrim(trim((string) ($settings['url'] ?? '')), '/');
        $this->user     = trim((string) ($settings['user'] ?? ''));
        $this->password = (string) ($settings['password'] ?? '');

        if ($this->baseUrl === '' || $this->user === '') {
            throw new RuntimeException('Configuration WebDAV incomplète : adresse et identifiant sont requis.');
        }
    }

    public function put(string $key, string $contents): void
    {
        $this->ensureCollections($key);

        $response = $this->request('PUT', $key, $contents);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new RuntimeException($this->explain('Écriture', $response));
        }
    }

    public function get(string $key): string
    {
        $response = $this->request('GET', $key);

        if ($response['code'] === 404) {
            throw new RuntimeException('Fichier introuvable sur le partage WebDAV.');
        }

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new RuntimeException($this->explain('Lecture', $response));
        }

        return $response['body'];
    }

    public function delete(string $key): void
    {
        $this->request('DELETE', $key);
    }

    public function exists(string $key): bool
    {
        return $this->request('HEAD', $key)['code'] === 200;
    }

    public function test(): array
    {
        $key = '_test/' . bin2hex(random_bytes(8)) . '.txt';

        try {
            $this->put($key, 'test');
            $ok = $this->get($key) === 'test';
            $this->delete($key);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return $ok
            ? ['ok' => true, 'message' => 'Écriture, lecture et suppression confirmées sur le partage.']
            : ['ok' => false, 'message' => 'Le fichier relu ne correspond pas à ce qui a été écrit.'];
    }

    public function describe(): string
    {
        return $this->baseUrl;
    }

    /**
     * WebDAV ne crée pas les dossiers intermédiaires : il faut les demander.
     */
    private function ensureCollections(string $key): void
    {
        $segments = explode('/', trim(dirname($key), '/'));
        $path     = '';

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $path .= ($path === '' ? '' : '/') . $segment;

            // 405 = la collection existe déjà : ce n'est pas une erreur.
            $this->request('MKCOL', $path . '/');
        }
    }

    /**
     * @return array{code: int, body: string, error: string}
     */
    private function request(string $method, string $key, string $body = ''): array
    {
        $url = $this->baseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));

        $response = wp_remote_request($url, [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->user . ':' . $this->password),
            ],
            'body'    => $body === '' ? null : $body,
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            return ['code' => 0, 'body' => '', 'error' => $response->get_error_message()];
        }

        return [
            'code'  => (int) wp_remote_retrieve_response_code($response),
            'body'  => (string) wp_remote_retrieve_body($response),
            'error' => '',
        ];
    }

    /**
     * @param array{code: int, body: string, error: string} $response
     */
    private function explain(string $operation, array $response): string
    {
        if ($response['error'] !== '') {
            return sprintf('%s impossible : %s', $operation, $response['error']);
        }

        return match ($response['code']) {
            401     => 'Identifiants WebDAV refusés.',
            403     => 'Accès refusé : le compte n’a pas le droit d’écrire dans ce dossier.',
            409     => 'Le dossier de destination n’existe pas.',
            507     => 'Espace de stockage insuffisant.',
            default => sprintf('%s refusée (HTTP %d).', $operation, $response['code']),
        };
    }
}
