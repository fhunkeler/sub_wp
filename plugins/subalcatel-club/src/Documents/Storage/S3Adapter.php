<?php

declare(strict_types=1);

namespace Subalcatel\Club\Documents\Storage;

use RuntimeException;

/**
 * Stockage objet compatible S3 : Scaleway, OVH, Infomaniak, MinIO, AWS.
 *
 * Signature AWS Version 4 écrite ici plutôt qu'importée : le plugin n'a pas de
 * gestionnaire de dépendances, et le SDK officiel pèse plusieurs mégaoctets
 * pour trois opérations. L'algorithme est stable depuis 2014 et tient en une
 * centaine de lignes.
 *
 * Le bucket doit être PRIVÉ. Rien ici ne rend un objet public, et le plugin ne
 * produit jamais d'URL directe : les fichiers transitent par PHP, qui vérifie
 * les droits avant de servir.
 */
final class S3Adapter implements StorageAdapter
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;
    private string $prefix;
    private bool $pathStyle;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings)
    {
        $this->endpoint  = rtrim(trim((string) ($settings['endpoint'] ?? '')), '/');
        $this->region    = trim((string) ($settings['region'] ?? 'fr-par'));
        $this->bucket    = trim((string) ($settings['bucket'] ?? ''));
        $this->accessKey = trim((string) ($settings['access_key'] ?? ''));
        $this->secretKey = (string) ($settings['secret_key'] ?? '');
        $this->prefix    = trim((string) ($settings['prefix'] ?? 'documents'), '/');
        $this->pathStyle = (bool) ($settings['path_style'] ?? true);

        if ($this->endpoint === '' || $this->bucket === '' || $this->accessKey === '') {
            throw new RuntimeException('Configuration S3 incomplète : point de terminaison, bucket et clé sont requis.');
        }
    }

    public function put(string $key, string $contents): void
    {
        $response = $this->request('PUT', $key, $contents);

        if ($response['code'] < 200 || $response['code'] >= 300) {
            throw new RuntimeException($this->explain('Écriture', $response));
        }
    }

    public function get(string $key): string
    {
        $response = $this->request('GET', $key);

        if ($response['code'] === 404) {
            throw new RuntimeException('Fichier introuvable sur le stockage objet.');
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
            ? ['ok' => true, 'message' => 'Écriture, lecture et suppression confirmées sur le bucket.']
            : ['ok' => false, 'message' => 'Le fichier relu ne correspond pas à ce qui a été écrit.'];
    }

    public function describe(): string
    {
        return sprintf('%s/%s/%s', $this->endpoint, $this->bucket, $this->prefix);
    }

    // ------------------------------------------------------------- Requêtes

    /**
     * @return array{code: int, body: string, error: string}
     */
    private function request(string $method, string $key, string $body = ''): array
    {
        $path = '/' . $this->prefix . '/' . ltrim($key, '/');

        if ($this->pathStyle) {
            $url  = $this->endpoint . '/' . $this->bucket . $path;
            $host = (string) wp_parse_url($this->endpoint, PHP_URL_HOST);
            $canonicalUri = '/' . $this->bucket . $path;
        } else {
            $scheme = (string) wp_parse_url($this->endpoint, PHP_URL_SCHEME);
            $host   = $this->bucket . '.' . wp_parse_url($this->endpoint, PHP_URL_HOST);
            $url    = $scheme . '://' . $host . $path;
            $canonicalUri = $path;
        }

        $headers  = $this->sign($method, $host, $canonicalUri, $body);
        $response = wp_remote_request($url, [
            'method'  => $method,
            'headers' => $headers,
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
     * Signature AWS Version 4.
     *
     * @return array<string, string>
     */
    private function sign(string $method, string $host, string $canonicalUri, string $body): array
    {
        $now       = gmdate('Ymd\THis\Z');
        $date      = substr($now, 0, 8);
        $payload   = hash('sha256', $body);
        $service   = 's3';

        $headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payload,
            'x-amz-date'           => $now,
        ];

        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }

        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $method,
            $this->encodePath($canonicalUri),
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payload,
        ]);

        $scope       = sprintf('%s/%s/%s/aws4_request', $date, $this->region, $service);
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $now,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac(
            'sha256',
            $service,
            hash_hmac('sha256', $this->region, hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true), true),
            true
        ), true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $headers['Authorization'] = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature
        );

        return $headers;
    }

    /**
     * Encode le chemin segment par segment : les « / » restent des séparateurs.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * @param array{code: int, body: string, error: string} $response
     */
    private function explain(string $operation, array $response): string
    {
        if ($response['error'] !== '') {
            return sprintf('%s impossible : %s', $operation, $response['error']);
        }

        // Les erreurs S3 sont du XML : on en extrait le message plutôt que de
        // renvoyer un code nu.
        if (preg_match('#<Message>(.*?)</Message>#s', $response['body'], $m) === 1) {
            return sprintf('%s refusée (HTTP %d) : %s', $operation, $response['code'], $m[1]);
        }

        return sprintf('%s refusée (HTTP %d).', $operation, $response['code']);
    }
}
