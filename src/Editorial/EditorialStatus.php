<?php
declare(strict_types=1);

namespace HotRadar\Editorial;

/**
 * Estados editoriais. E3 implementa só o núcleo; os demais ficam reservados
 * para evolução posterior (sem workflow complexo agora).
 */
final class EditorialStatus
{
    // Núcleo ativo em E3
    public const DESCOBERTO = 'descoberto';
    public const ANALISAR = 'analisar';
    public const APROVADO = 'aprovado';
    public const DESCARTADO = 'descartado';

    // Reservados (previstos, não implementados)
    public const EM_PRODUCAO = 'em_producao';
    public const PRONTO = 'pronto';
    public const PUBLICADO = 'publicado';
    public const MONITORANDO = 'monitorando';
    public const OFERTA_EXPIRADA = 'oferta_expirada';

    /** @return array<int,string> */
    public static function active(): array
    {
        return [self::DESCOBERTO, self::ANALISAR, self::APROVADO, self::DESCARTADO];
    }

    /** @return array<int,string> */
    public static function all(): array
    {
        return [
            self::DESCOBERTO, self::ANALISAR, self::APROVADO, self::DESCARTADO,
            self::EM_PRODUCAO, self::PRONTO, self::PUBLICADO, self::MONITORANDO, self::OFERTA_EXPIRADA,
        ];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::DESCOBERTO => 'Descoberto',
            self::ANALISAR => 'Analisar',
            self::APROVADO => 'Aprovado',
            self::DESCARTADO => 'Descartado',
            self::EM_PRODUCAO => 'Em produção',
            self::PRONTO => 'Pronto',
            self::PUBLICADO => 'Publicado',
            self::MONITORANDO => 'Monitorando',
            self::OFERTA_EXPIRADA => 'Oferta expirada',
            default => ucfirst($status),
        };
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::active(), true);
    }
}
