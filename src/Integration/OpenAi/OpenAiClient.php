<?php
declare(strict_types=1);

namespace HotRadar\Integration\OpenAi;

/**
 * Serviço SEPARADO da OpenAI. Nesta fase é usado SOMENTE para produzir
 * resumos interpretativos de relatórios — NUNCA toca no HOT SCORE matemático,
 * que continua 100% determinístico em HotScore/HotScoreConfig.
 *
 * A chave nunca é logada nem retornada. Em erro, devolve mensagem genérica.
 *
 * Não é `final`: httpPost() é um seam protegido para que testes possam
 * simular sucesso/erro da API (mock/fake) sem chamada de rede real — ver
 * tests/OpenAiConnectionTest.php.
 */
class OpenAiClient
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

        $r = $this->httpPost($body);

        if ($r['errno']) {
            return ['ok' => false, 'text' => null, 'error' => 'Falha de rede ao chamar a OpenAI.'];
        }
        if ($r['code'] < 200 || $r['code'] >= 300) {
            // NÃO ecoa o corpo da resposta (pode conter eco de headers). Mensagem genérica.
            return ['ok' => false, 'text' => null, 'error' => 'OpenAI retornou HTTP ' . $r['code'] . '.'];
        }
        $json = json_decode((string) $r['body'], true);
        $text = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') {
            return ['ok' => false, 'text' => null, 'error' => 'Resposta da OpenAI sem conteúdo utilizável.'];
        }
        return ['ok' => true, 'text' => trim($text), 'error' => null];
    }

    /**
     * "Testar conexão": só exige a CHAVE (independente do toggle enabled), pois
     * o objetivo é verificar se a chave/modelo funcionam ANTES de decidir
     * ativar o uso. Nunca expõe a chave nem o corpo bruto da resposta.
     * @return array{ok:bool, error:?string}
     */
    public function testConnection(): array
    {
        if (!$this->config->apiKeyPresent()) {
            return ['ok' => false, 'error' => 'OpenAI não configurada (defina OPENAI_API_KEY no Environment).'];
        }

        $body = json_encode([
            'model' => $this->config->model(),
            'messages' => [
                ['role' => 'user', 'content' => 'Responda apenas com a palavra: ok'],
            ],
            'max_tokens' => 5,
            'temperature' => 0,
        ], JSON_UNESCAPED_UNICODE);

        $r = $this->httpPost($body);

        if ($r['errno']) {
            return ['ok' => false, 'error' => 'Falha de rede ao chamar a OpenAI.'];
        }
        if ($r['code'] < 200 || $r['code'] >= 300) {
            return ['ok' => false, 'error' => 'OpenAI retornou HTTP ' . $r['code'] . '.'];
        }
        return ['ok' => true, 'error' => null];
    }

    /**
     * Único ponto que fala com a rede. Protegido para poder ser substituído
     * por uma fake em teste (sem chamada real). Nunca loga/retorna a chave.
     * @return array{code:int, body:?string, errno:int}
     */
    protected function httpPost(string $body): array
    {
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

        return ['code' => $code, 'body' => is_string($res) ? $res : null, 'errno' => $errno];
    }
}
