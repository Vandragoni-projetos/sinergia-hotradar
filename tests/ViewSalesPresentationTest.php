<?php
declare(strict_types=1);

use HotRadar\Web\View;

/**
 * Apresentação de "Procura / vendas" na ficha do produto — preserva o sinal
 * qualitativo (salesSignal) E o número exato (salesExact) quando ambos
 * existirem, sem reconverter um no outro nem estimar nada. Não altera
 * coleta, mapper, Radar, Hot Score, salesSignal ou nicheConfidence — só a
 * apresentação (View::vendasTexto()), usada em views/products/show.php.
 */

T::group('View::vendasTexto() — sinal + número exato, nunca fabricando nem reconvertendo');

T::eq('Muito alto · 65.656 vendas', View::vendasTexto('muito_alto', 65656), 'sinal + número exato: combina os dois, número exato formatado com separador de milhar');
T::eq('Alto · 5.000 vendas', View::vendasTexto('alto', 5000), 'outra faixa: sinal + exato');
T::eq('Baixo · 1 vendas', View::vendasTexto('baixo', 1), 'número exato pequeno (1) ainda aparece, sem arredondar/ocultar');

T::eq('Muito alto', View::vendasTexto('muito_alto', null), 'só sinal (ex.: Mercado Livre, que nunca tem salesExact): mostra só o sinal, sem inventar número');

T::eq('65.656 vendas', View::vendasTexto(null, 65656), 'só número exato (salesSignal ainda não derivado/persistido nesse produto): mostra só a quantidade, sem inventar sinal');
T::eq('0 vendas', View::vendasTexto(null, 0), 'número exato = 0 é um dado real (não é "ausência") — mostra "0 vendas", não "Não informado"');

T::eq('Não informado', View::vendasTexto(null, null), 'nem sinal nem número: continua "Não informado", comportamento preservado');

// vendasNome() sozinho continua com o mesmo comportamento de sempre (não foi alterado)
T::eq('Muito alto', View::vendasNome('muito_alto'), 'vendasNome() isolado inalterado');
T::eq('Não informado', View::vendasNome(null), 'vendasNome() isolado inalterado (default)');
