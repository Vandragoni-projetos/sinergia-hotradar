<?php
declare(strict_types=1);

namespace HotRadar\Support;

/**
 * Lançada por App::bootOrFail() quando a aplicação NÃO PODE subir com
 * segurança — configuração de banco inválida para o ambiente, ou falha real
 * de conexão. A mensagem pública (getMessage()) nunca inclui usuário/senha;
 * a exceção original do driver (pode conter detalhes de conexão) fica só em
 * getPrevious(), acessível apenas a quem loga internamente — nunca é ecoada
 * para o cliente HTTP nem para stdout da CLI.
 *
 * Quem pega esta exceção (public/index.php, bin/hr.php, public/health.php)
 * DEVE responder com falha explícita (HTTP 503 / exit != 0) — nunca engolir
 * e seguir adiante, e nunca, em nenhuma circunstância, tentar um driver
 * alternativo (ver o aviso em src/Db/Connection.php).
 */
final class BootFailedException extends \RuntimeException
{
    /** @param array<int,string> $problems @param array<string,string> $context */
    private function __construct(
        string $message,
        public readonly array $problems,
        public readonly array $context,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @param array<int,string> $problems @param array<string,string> $context */
    public static function configInvalid(array $problems, array $context): self
    {
        return new self(
            'Configuração de banco inválida para o ambiente "' . ($context['environment'] ?? '?') . '": ' . implode('; ', $problems),
            $problems,
            $context,
        );
    }

    /** @param array<string,string> $context */
    public static function connectionFailed(\Throwable $previous, array $context): self
    {
        return new self(
            'Falha ao conectar ao banco (driver=' . ($context['driver'] ?? '?') . ', host=' . ($context['host'] ?? '?') . ')',
            [],
            $context,
            $previous,
        );
    }
}
