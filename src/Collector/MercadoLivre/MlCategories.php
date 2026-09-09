<?php
declare(strict_types=1);

namespace HotRadar\Collector\MercadoLivre;

/**
 * Categorias raiz do Mercado Livre Brasil aceitas em /ofertas?category=MLB<id>.
 * (Confirmado utilizável na auditoria — a página /ofertas responde 200 com estas.)
 */
final class MlCategories
{
    /** @var array<string,string> id => rótulo */
    public const ROOT = [
        'MLB5672' => 'Acessórios para Veículos',
        'MLB1403' => 'Alimentos e Bebidas',
        'MLB1367' => 'Antiguidades e Coleções',
        'MLB1368' => 'Arte, Papelaria e Armarinho',
        'MLB1384' => 'Bebês',
        'MLB1246' => 'Beleza e Cuidado Pessoal',
        'MLB1132' => 'Brinquedos e Hobbies',
        'MLB1430' => 'Calçados, Roupas e Bolsas',
        'MLB1039' => 'Câmeras e Acessórios',
        'MLB1574' => 'Casa, Móveis e Decoração',
        'MLB1051' => 'Celulares e Telefones',
        'MLB1500' => 'Construção',
        'MLB5726' => 'Eletrodomésticos',
        'MLB1000' => 'Eletrônicos, Áudio e Vídeo',
        'MLB1276' => 'Esportes e Fitness',
        'MLB263532' => 'Ferramentas',
        'MLB1144' => 'Games',
        'MLB1499' => 'Indústria e Comércio',
        'MLB1648' => 'Informática',
        'MLB218519' => 'Ingressos',
        'MLB1182' => 'Instrumentos Musicais',
        'MLB3937' => 'Joias e Relógios',
        'MLB1196' => 'Livros, Revistas e Comics',
        'MLB1168' => 'Música, Filmes e Seriados',
        'MLB264586' => 'Saúde',
        'MLB1540' => 'Serviços',
        'MLB1953' => 'Mais Categorias',
        'MLB1071' => 'Animais',
    ];

    /**
     * Preset da V1: Casa + Cozinha + Organização.
     * "Cozinha" e "Organização" são subárvores de Casa/Eletrodomésticos; na página
     * /ofertas o filtro é por raiz, então usamos as duas raízes e deixamos o
     * classificador de nicho refinar a aderência.
     *
     * @return array<int,string>
     */
    public static function presetCasaCozinhaOrganizacao(): array
    {
        return ['MLB1574', 'MLB5726'];
    }

    public static function label(string $id): string
    {
        return self::ROOT[$id] ?? $id;
    }

    public static function isKnownRoot(string $id): bool
    {
        return isset(self::ROOT[$id]);
    }
}
