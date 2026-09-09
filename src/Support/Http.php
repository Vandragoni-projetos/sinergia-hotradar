<?php
declare(strict_types=1);

namespace HotRadar\Support;

/**
 * Cliente HTTP GET mínimo (curl). Somente leitura de páginas públicas.
 * Não segue nenhuma automação de login, não envia cookies de terceiros.
 */
final class Http
{
    public function __construct(
        private readonly string $userAgent,
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string, error:?string, bytes:int, final_url:string}
     */
    public function get(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        $hdr = ['Accept: text/html,application/xhtml+xml,application/json'];
        foreach ($headers as $k => $v) {
            $hdr[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $bodyStr = is_string($body) ? $body : '';

        return [
            'status' => $status,
            'body' => $bodyStr,
            'error' => $error,
            'bytes' => strlen($bodyStr),
            'final_url' => $finalUrl,
        ];
    }
}
