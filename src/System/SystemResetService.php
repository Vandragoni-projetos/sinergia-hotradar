<?php
declare(strict_types=1);

namespace HotRadar\System;

use HotRadar\Db\Connection;
use HotRadar\Repository\AuditRepository;

/**
 * "Zerar tudo" — reset GLOBAL do HOTRADAR, para começar uma nova rodada do zero.
 *
 * Deliberadamente SEPARADO de HotRadar\Radar\RadarLifecycleService: excluir ou
 * limpar UM radar é (e continua sendo) uma operação de escopo LOCAL a esse
 * radar, que preserva histórico de coleta de propósito. "Zerar tudo" é uma
 * intenção GLOBAL e diferente — não deve nunca ficar acoplada ao fluxo de
 * excluir radar (foi exatamente essa confusão, na prática, que deixou
 * hr_collection_runs órfão — ver auditoria de 2026-09-11).
 *
 * Limpa, nesta ordem (dependentes antes do que depende deles):
 *   hr_product_snapshots -> hr_editorial_events -> hr_product_radars
 *   -> hr_products -> hr_collection_runs -> hr_radars
 *
 * PRESERVA sempre (nunca apagados por esta classe):
 *   hr_settings, hr_audit_log, hr_migrations — e, por consequência de nunca
 *   serem tocados, também ficam intactos: autenticação, configurações de
 *   marketplace (Environment), Hot Score V1/V2 (config + override em
 *   hr_settings), a blindagem E1-E5 (não depende de dado de tabela nenhuma).
 *
 * Transacional: tudo ou nada. Se qualquer DELETE falhar, a transação inteira
 * é revertida — nenhuma tabela fica parcialmente limpa.
 */
final class SystemResetService
{
    /** Ordem de exclusão — dependentes primeiro. NÃO reordenar sem revisar as FKs lógicas. */
    private const TABLES_IN_ORDER = [
        'hr_product_snapshots',
        'hr_editorial_events',
        'hr_product_radars',
        'hr_products',
        'hr_collection_runs',
        'hr_radars',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly AuditRepository $audit,
    ) {
    }

    /**
     * Contagem atual de cada tabela afetada — para a tela de impacto, ANTES
     * de qualquer confirmação. Somente leitura.
     * @return array<string,int>
     */
    public function impact(): array
    {
        $out = [];
        foreach (self::TABLES_IN_ORDER as $table) {
            $out[$table] = $this->count($table);
        }
        return $out;
    }

    /**
     * Executa o reset global. Só deve ser chamado depois de confirmação
     * textual explícita do usuário (a checagem do texto "ZERAR TUDO" é
     * responsabilidade da Action, não desta classe — mantém a classe
     * testável sem precisar simular request HTTP).
     *
     * @return array<string,int> quantas linhas foram removidas de cada tabela
     */
    public function factoryReset(string $actor = 'humano:painel'): array
    {
        $before = $this->impact();

        $removed = $this->db->transaction(function () {
            $out = [];
            foreach (self::TABLES_IN_ORDER as $table) {
                $out[$table] = $this->db->run("DELETE FROM $table")->rowCount();
            }
            return $out;
        });

        // hr_settings / hr_audit_log / hr_migrations NUNCA aparecem em TABLES_IN_ORDER —
        // preservados por construção, não por uma checagem extra.
        $this->audit->log('system', 'factory_reset', null, $before, $removed, $actor);

        return $removed;
    }

    private function count(string $table): int
    {
        return (int) ($this->db->first("SELECT COUNT(*) n FROM $table")['n'] ?? 0);
    }
}
