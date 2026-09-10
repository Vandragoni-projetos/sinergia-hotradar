<?php
declare(strict_types=1);

/**
 * CATÁLOGO DE REFERÊNCIA das categorias raiz do Mercado Livre Brasil.
 *
 * Serve APENAS para popular o seletor de categorias na tela de Radar.
 * A configuração REAL de quais categorias são monitoradas fica em hr_radars
 * (banco) — nada aqui é "a configuração ativa", é só uma lista para escolher.
 *
 * Editável livremente: acrescente/renomeie conforme o ML mudar as seções.
 * Confirmadas como utilizáveis em /ofertas?category=<id> pela auditoria.
 *
 * @return array<string,string> id MLB => rótulo
 */
return [
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
