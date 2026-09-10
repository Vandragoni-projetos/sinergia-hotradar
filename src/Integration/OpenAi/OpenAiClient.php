<?php
declare(strict_types=1);

namespace HotRadar\Integration\OpenAi;

/**
 * Serviço SEPARADO da OpenAI. Nesta fase é usado SOMENTE para produzir
 * resumos interpretativos de relatórios — NUNCA toca no HOT SCORE matemático,
 * que continua 100% determinístico em HotScore/HotScoreConfig.
 *
 * A chave nunca é logada nem retornada. Em erro, devolve mensagem genérica.
 */
final class OpenAiClient
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function __construct(private readonly OpenAiConfig $config)
    {
    }

    public function isReady(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * @param string $system  instruções (regras anti-alucinação)
     * @param string $userJson payload de dados ESTRUTURADOS (string JSON)
     * @return array{ok:bool, text:?string, error:?string}
     */
    public function summarize(string $system, string $userJson): array
    {
        if (!$this->isReady()) {
            return ['ok' => false, 'text' => null, 'error' => 'OpenAI não configurada (defina OPENAI_API_KEY no Environment).'];
        }

        $body = json_encode([
            'model' => $this->config->model(),
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userJson],
            ],
            'temperature' => 0.2,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->apiKey(),
            ],
        ]);
        $res = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'text' => null, 'error' => 'Falha de rede ao chamar a OpenAI.'];
        }
        if ($code < 200 || $code >= 300) {
            // NÃO ecoa o corpo da resposta (pode conter eco de headers). Mensagem genérica.
            return ['ok' => false, 'text' => null, 'error' => 'OpenAI retornou HTTP ' . $code . '.'];
        }
        $json = json_decode((string) $res, true);
        $text = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            return ['ok' => false, 'text' => null, 'error' => 'Resposta da OpenAI sem conteúdo utilizável.'];
        }
        return ['ok' => true, 'text' => trim($text), 'error' => null];
    }
}
