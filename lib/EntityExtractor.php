<?php
declare(strict_types=1);

/**
 * EntityExtractor — extrai entidades concretas do conteúdo scrapeado pra injetar no prompt
 * do gerador. Objetivo: prevenir vague_promise NA GERAÇÃO ("O filtro" → "Filtro do Senac-ES").
 *
 * Categorias extraídas:
 *   1. Órgãos/Instituições (Senac, INSS, Polícia Civil, IFRS, etc.)
 *   2. Cidades/Estados (Pinheiros, Araguaína, RS, SP, etc.)
 *   3. Números-chave (15 vagas, 200 candidatos, etc.)
 *   4. Datas/Prazos (30/04, 30 de abril, sexta, 2026)
 *   5. Valores monetários (R$ 2.450, R$ 11 mil)
 *   6. Programas/Eventos (ENEM, Sisu, ProUni, Pé-de-Meia, etc.)
 *   7. Cargos/Profissões (Auxiliar, Técnico, Sargento, etc.)
 */
class EntityExtractor
{
    /** Lista hardcoded de instituições/programas conhecidos (PT-BR/educação/governo) */
    private const ORGAOS_CONHECIDOS = [
        // Educação federal/estadual
        'MEC', 'CAPES', 'INEP', 'FNDE',
        // Sistema S
        'Senac', 'Senai', 'Sesc', 'Sesi', 'Sebrae', 'Senar', 'Senat',
        // Bancos / fundações
        'Caixa', 'Banco do Brasil', 'BB', 'Bradesco', 'Itaú', 'Fundação Bradesco',
        // Universidades / institutos
        'USP', 'Unicamp', 'UFRJ', 'UFRGS', 'UFMG', 'UFBA', 'UFPE', 'UFC',
        'UnB', 'UFSC', 'UFPR', 'UFRRJ', 'UFLA', 'UFMA', 'UEMS', 'UPE', 'UERJ',
        'IFRS', 'IFSP', 'IFMG', 'IFES', 'IFRN', 'IFG', 'IFBA', 'IFCE',
        'IFMA', 'IFC', 'IFSULDEMINAS', 'Etec', 'Fatec',
        // Forças e segurança
        'Polícia Federal', 'PF', 'PRF', 'Polícia Civil', 'Polícia Militar',
        'Exército', 'Marinha', 'Aeronáutica', 'ESA', 'Detran',
        // Tribunais
        'TJ-SC', 'TJSC', 'TJSP', 'TJMG', 'TJRS', 'TJRJ', 'STF', 'STJ', 'TST',
        'Câmara dos Deputados', 'Senado', 'TSE',
        // Empresas estatais
        'Petrobras', 'Eletrobras', 'Correios', 'BNDES', 'Embrapa', 'Embraer',
        'Receita Federal', 'INSS', 'FGTS', 'PIS', 'PASEP',
        // Saúde
        'SUS', 'Anvisa', 'Fiocruz', 'SES',
        // Programas educacionais/sociais
        'ENEM', 'Sisu', 'ProUni', 'Fies', 'Encceja', 'Pronatec',
        'Pé-de-Meia', 'Pé de Meia', 'Bolsa Família', 'CadÚnico',
        'Auxílio Brasil', 'BPC', 'LOAS', 'MEI', 'CNH', 'CPF',
        'MCMV', 'Minha Casa Minha Vida', 'CNU',
        // Mídia
        'Estácio', 'Fapesp', 'CNPq',
    ];

    private const ESTADOS_BR = [
        'AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA',
        'PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO',
    ];

    private const PROFISSOES = [
        'Auxiliar', 'Assistente', 'Técnico', 'Analista', 'Agente', 'Inspetor',
        'Sargento', 'Soldado', 'Cabo', 'Tenente',
        'Professor', 'Pedagogo', 'Biblio',
        'Enfermeiro', 'Médico', 'Farmacêutico', 'Dentista',
        'Cabelereiro', 'Cabeleireiro', 'Trancista', 'Manicure',
        'Recenseador', 'Fiscal', 'Auditor',
        'Programador', 'Desenvolvedor', 'Designer',
        'Administrador', 'Contador', 'Advogado',
    ];

    /**
     * Extrai entidades do conteúdo (pode receber título + corpo).
     *
     * @return array<string,array<int,string>>
     */
    public static function extract(string $content, ?string $title = null): array
    {
        $text = ($title ? $title . "\n" : '') . strip_tags($content);
        $text = preg_replace('/\s+/u', ' ', $text);

        $orgaos = self::extractOrgaos($text);
        $cidades = self::extractCidadesEstados($text, $orgaos);
        $numeros = self::extractNumerosChave($text);
        $datas = self::extractDatasPrazos($text);
        $valores = self::extractValoresMonetarios($text);
        $programas = self::extractProgramasEventos($text, $orgaos);
        $cargos = self::extractCargos($text);
        $horarios = self::extractHorarios($text);
        $enderecos = self::extractEnderecos($text);
        $telefones = self::extractTelefones($text);
        $emails = self::extractEmails($text);

        return [
            'orgaos_instituicoes' => array_values(array_unique($orgaos)),
            'cidades_estados'     => array_values(array_unique($cidades)),
            'numeros_chave'       => array_values(array_unique($numeros)),
            'datas_prazos'        => array_values(array_unique($datas)),
            'valores_dinheiro'    => array_values(array_unique($valores)),
            'programas_eventos'   => array_values(array_unique($programas)),
            'cargos_profissoes'   => array_values(array_unique($cargos)),
            'horarios'            => array_values(array_unique($horarios)),
            'enderecos'           => array_values(array_unique($enderecos)),
            'telefones'           => array_values(array_unique($telefones)),
            'emails'              => array_values(array_unique($emails)),
        ];
    }

    /** Acha horários: "12h às 15h", "8h às 18h", "9h-17h", "12h30 às 14h" */
    private static function extractHorarios(string $text): array
    {
        $found = [];
        if (preg_match_all('/\b(\d{1,2}h(?:\d{2})?)\s*(?:às|as|à|a|–|—|-|até|e)\s*(\d{1,2}h(?:\d{2})?)\b/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) $found[] = $match[1] . ' às ' . $match[2];
        }
        return $found;
    }

    /** Acha endereços físicos: "Rua X", "Av. Y", "Largo Z", "Praça W", "Rodovia BR-XXX" */
    private static function extractEnderecos(string $text): array
    {
        $found = [];
        // Pattern: tipo de via + nome próprio (até 5 palavras) + opcional número
        if (preg_match_all('/\b(?:Rua|Avenida|Av\.|Largo|Pra[çc]a|Alameda|Travessa|Rodovia|BR\-|Estrada|Highway)\s+(?:[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõç\.]+\s*){1,5}(?:,\s*(?:n[º°o]?\.?\s*)?\d+|\bs\/?n\b)?(?:\s*[-,]\s*[A-ZÁÉÍÓÚ][a-záéíóúâêôãõç]+){0,2}/u', $text, $m)) {
            foreach ($m[0] as $hit) {
                $hit = trim($hit, " .,");
                if (mb_strlen($hit) >= 8 && mb_strlen($hit) <= 120) $found[] = $hit;
            }
        }
        return $found;
    }

    /** Acha telefones BR: "(71) 99999-9999", "71 99999-9999", "0800-XXX-XXXX", "11 3333 4444" */
    private static function extractTelefones(string $text): array
    {
        $found = [];
        // Pattern 1: (DDD) NNNN-NNNN ou (DDD) NNNNN-NNNN ou DDD NNNNN-NNNN
        if (preg_match_all('/\(?\b(\d{2})\)?[\s\-\.]?(\d{4,5})[\s\-\.]?(\d{4})\b/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $ddd = $match[1]; $p1 = $match[2]; $p2 = $match[3];
                // DDD válido (11-99) e dígitos coerentes
                $ddd_int = (int)$ddd;
                if ($ddd_int < 11 || $ddd_int > 99) continue;
                // Excluí padrão tipo CEP (5+3) ou CPF
                if (mb_strlen($p1) === 5 && mb_strlen($p2) === 3) continue;
                $found[] = "({$ddd}) {$p1}-{$p2}";
            }
        }
        // Pattern 2: 0800
        if (preg_match_all('/\b0800[\s\-\.]?\d{3}[\s\-\.]?\d{4}\b/', $text, $m)) {
            foreach ($m[0] as $hit) $found[] = trim($hit);
        }
        return $found;
    }

    /** Acha emails simples (institucionais) */
    private static function extractEmails(string $text): array
    {
        $found = [];
        if (preg_match_all('/\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b/i', $text, $m)) {
            foreach ($m[0] as $hit) {
                // Filtra emails de exemplo / placeholder
                if (preg_match('/(example|teste|test|noreply|no-reply|nopaste|fake)/i', $hit)) continue;
                $found[] = $hit;
            }
        }
        return $found;
    }

    /** Acha órgãos/instituições conhecidos no texto */
    private static function extractOrgaos(string $text): array
    {
        $found = [];
        foreach (self::ORGAOS_CONHECIDOS as $org) {
            $pattern = '/\b' . preg_quote($org, '/') . '\b/iu';
            if (preg_match($pattern, $text, $m)) {
                $found[] = $m[0]; /* preserva capitalização original */
            }
        }
        /* Captura órgão-UF/Estado: "Senac-ES", "Polícia-RS", "Sebrae-MG" */
        if (preg_match_all('/\b([A-Z][A-Za-zÀ-ÖØ-öø-ÿ]{2,})[-\s]([A-Z]{2})\b/u', $text, $m)) {
            foreach ($m[0] as $match) $found[] = $match;
        }
        /* Acrônimos genéricos 3-6 letras maiúsculas */
        if (preg_match_all('/\b([A-Z]{3,6})\b/u', $text, $m)) {
            foreach ($m[1] as $acr) {
                if (in_array($acr, self::ESTADOS_BR, true)) continue;
                if (mb_strlen($acr) >= 3 && mb_strlen($acr) <= 6) $found[] = $acr;
            }
        }
        return array_slice($found, 0, 12);
    }

    /** Cidades brasileiras + estados (filtra falsos positivos comuns) */
    private static function extractCidadesEstados(string $text, array $orgaos): array
    {
        $found = [];
        /* Lista de palavras-início que NUNCA são cidades */
        $stopWords = [
            'Editais','Cursos','Vagas','Concursos','Inscrições','Provas','Sistema','Senac','Sebrae','Brasil',
            'Administração','Aprendizagem','Comercial','Polícia','Auxiliar','Assistente','Técnico','Analista',
            'Agente','Inspetor','Cadastro','Documentação','Formulário','Programa','Curso','Vaga','Edital',
            'Concurso','Inscrição','Prova','Salário','Renda','Comprovante','Ensino','Formação',
        ];
        /* "em [Cidade]" — apenas 1 palavra (cidade simples) ou 2 palavras (composta) */
        if (preg_match_all('/\bem\s+([A-Z][a-záéíóúâêôãõçà]{3,}(?:\s+[A-Z][a-záéíóúâêôãõçà]{3,}){0,2})/u', $text, $m)) {
            foreach ($m[1] as $cid) {
                $first = explode(' ', $cid)[0];
                if (in_array($first, $stopWords, true)) continue;
                $found[] = trim($cid);
            }
        }
        /* Estados (UF) — sigla 2 letras com contexto "-XX" ou após cidade */
        if (preg_match_all('/\b(' . implode('|', self::ESTADOS_BR) . ')\b/u', $text, $m)) {
            foreach ($m[1] as $uf) $found[] = $uf;
        }
        /* Estados por extenso */
        $estadosExtenso = ['Acre','Alagoas','Amazonas','Bahia','Ceará','Goiás','Maranhão','Minas Gerais','Pará','Paraíba','Paraná','Pernambuco','Piauí','Rondônia','Roraima','Santa Catarina','São Paulo','Sergipe','Tocantins','Rio Grande do Sul','Rio Grande do Norte','Mato Grosso','Mato Grosso do Sul','Espírito Santo','Distrito Federal','Rio de Janeiro'];
        foreach ($estadosExtenso as $est) {
            if (stripos($text, $est) !== false) $found[] = $est;
        }
        /* Capitais brasileiras + cidades grandes (lista direta, mais confiável que regex) */
        $cidadesGrandes = [
            'Salvador','Recife','Fortaleza','São Luís','Belém','Manaus','Porto Velho','Boa Vista','Macapá','Palmas','Teresina','Natal','João Pessoa','Maceió','Aracaju','Vitória','Belo Horizonte','Curitiba','Florianópolis','Porto Alegre','Cuiabá','Campo Grande','Goiânia','Brasília','São Paulo','Rio de Janeiro','Pinheiros','Araguaína','Garanhuns','Surubim','Ouro Preto','Botucatu','Gramado','Itapevi','Promissão','Santo André','Sonora','Madureira','Alegrete','Angra','Colatina',
        ];
        foreach ($cidadesGrandes as $c) {
            if (preg_match('/\b' . preg_quote($c, '/') . '\b/u', $text)) $found[] = $c;
        }
        return array_slice(array_unique($found), 0, 10);
    }

    /** Números com contexto (quantidades importantes) */
    private static function extractNumerosChave(string $text): array
    {
        $found = [];
        $patterns = [
            '/(\d{1,3}(?:\.\d{3})*|\d+)\s+(vagas?|cursos?|inscri[çc][õo]es?|candidatos?|aprovad[oa]s?|excedentes?|posts?)/iu',
            '/(\d+)\s*%\s+(?:dos?|das?|de)/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match_all($p, $text, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) $found[] = trim($match[0]);
            }
        }
        return array_slice($found, 0, 10);
    }

    /** Datas, prazos, dias da semana */
    private static function extractDatasPrazos(string $text): array
    {
        $found = [];
        /* DD/MM ou DD/MM/YYYY */
        if (preg_match_all('/\b(\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)\b/u', $text, $m)) {
            foreach ($m[1] as $d) $found[] = $d;
        }
        /* "X de [mês]" */
        $meses = 'janeiro|fevereiro|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro';
        if (preg_match_all('/\b(\d{1,2}\s+de\s+(?:' . $meses . '))\b/iu', $text, $m)) {
            foreach ($m[1] as $d) $found[] = $d;
        }
        /* Dias da semana */
        if (preg_match_all('/\b(segunda|terça|quarta|quinta|sexta|sábado|domingo)(?:-feira)?\b/iu', $text, $m)) {
            foreach ($m[1] as $d) $found[] = mb_strtolower($d);
        }
        /* Anos 2024-2030 */
        if (preg_match_all('/\b(202[0-9])\b/u', $text, $m)) {
            foreach ($m[1] as $a) $found[] = $a;
        }
        return array_slice($found, 0, 10);
    }

    /** Valores monetários (R$ ...) */
    private static function extractValoresMonetarios(string $text): array
    {
        $found = [];
        if (preg_match_all('/R\$\s*[\d\.\,]+(?:\s*mil|\s*mi|\s*milh[õo]es?)?/iu', $text, $m)) {
            foreach ($m[0] as $v) $found[] = trim($v);
        }
        return array_slice($found, 0, 8);
    }

    /** Programas/Eventos (concursos, editais, cursos específicos) */
    private static function extractProgramasEventos(string $text, array $orgaos): array
    {
        $found = [];
        /* "Concurso [da/de/do] [X]" */
        if (preg_match_all('/\b[Cc]oncurso\s+(?:do|da|de|d[ao]s|na|no)?\s*([A-ZÁÉÍÓÚ][\wÀ-ÿ]+(?:\s+[A-ZÁÉÍÓÚ][\wÀ-ÿ-]+){0,3})\s*(?:202\d)?/u', $text, $m)) {
            foreach ($m[0] as $c) $found[] = trim($c);
        }
        /* "Edital [X]" / "Edital N° X" */
        if (preg_match_all('/\bedital\s+(?:n[º°]\s*)?[\w\d\.\/-]+\b/iu', $text, $m)) {
            foreach ($m[0] as $e) $found[] = trim($e);
        }
        /* "Curso [Técnico/Livre/Profissional/...] em [X]" */
        if (preg_match_all('/\bcurso\s+(?:t[ée]cnico|livre|profissional|gratuito|de)\s+(?:em\s+)?([A-ZÁÉÍÓÚ][\wÀ-ÿ]+(?:\s+(?:de|em|do|da|para|e|&|com)\s*)?(?:[A-ZÁÉÍÓÚ][\wÀ-ÿ-]+){0,4})/u', $text, $m)) {
            foreach ($m[0] as $c) $found[] = trim($c);
        }
        return array_slice($found, 0, 8);
    }

    /** Cargos/Profissões mencionadas (limitado a 1-3 palavras pra evitar truncagem feia) */
    private static function extractCargos(string $text): array
    {
        $found = [];
        foreach (self::PROFISSOES as $prof) {
            /* Pega "Auxiliar Administrativo", "Técnico em Enfermagem" — máximo 4 palavras, sem cortar */
            $pattern = '/\b' . preg_quote($prof, '/') . '\b(?:\s+(?:em|de|do|da)?\s*[A-ZÁÉÍÓÚ][a-záéíóúâêôãõç]{3,})?(?:\s+[A-ZÁÉÍÓÚ][a-záéíóúâêôãõç]{3,})?/u';
            if (preg_match_all($pattern, $text, $m)) {
                foreach ($m[0] as $c) {
                    $c = preg_replace('/\s+/u', ' ', trim($c));
                    /* Filtra nomes truncados: precisa terminar em palavra completa */
                    if (preg_match('/[A-Za-zÀ-ÿ]$/u', $c) && mb_strlen($c) >= 4 && mb_strlen($c) <= 50) {
                        $found[] = $c;
                    }
                }
            }
        }
        return array_slice(array_unique($found), 0, 8);
    }

    /**
     * Formata as entidades extraídas em bloco textual pra injetar no prompt do LLM.
     */
    public static function formatForPrompt(array $entities): string
    {
        $labels = [
            'orgaos_instituicoes' => '🏛 Órgãos/Instituições',
            'cidades_estados'     => '📍 Cidades/Estados',
            'numeros_chave'       => '🔢 Números-chave (vagas, %)',
            'datas_prazos'        => '📅 Datas/Prazos',
            'valores_dinheiro'    => '💰 Valores',
            'programas_eventos'   => '📋 Programas/Editais',
            'cargos_profissoes'   => '👤 Cargos/Profissões',
            'horarios'            => '🕐 Horários (atendimento/inscrição)',
            'enderecos'           => '🏠 Endereços físicos (use em <address>)',
            'telefones'           => '📞 Telefones (use <a href="tel:..."> e WhatsApp via wa.me)',
            'emails'              => '✉️ Emails institucionais (use <a href="mailto:...">)',
        ];

        $lines = [];
        $lines[] = '## ENTIDADES REAIS EXTRAÍDAS DO CONTEÚDO (USAR OBRIGATORIAMENTE)';
        $lines[] = '';
        $lines[] = 'Estas são as entidades CONCRETAS encontradas no conteúdo scrapeado. Use-as obrigatoriamente sempre que precisar referenciar "filtro", "erro", "detalhe", "ponto", "critério" no h1/h2/h3 ou início de parágrafo.';
        $lines[] = '';

        $hasAny = false;
        foreach ($labels as $key => $label) {
            $items = $entities[$key] ?? [];
            if (empty($items)) continue;
            $hasAny = true;
            $lines[] = "**{$label}:** " . implode(' | ', array_slice($items, 0, 12));
        }

        if (!$hasAny) {
            $lines[] = '*(Conteúdo sem entidades específicas detectadas — escreva conservativamente, sem usar termos como "O filtro", "O erro", "O detalhe" sem qualificação concreta extraída do próprio texto.)*';
        }

        $lines[] = '';
        $lines[] = '**REGRA DURA:** Em h1/h2/h3 e parágrafos iniciais, JAMAIS use termos genéricos como "O filtro", "O erro", "O detalhe", "O ponto", "O critério" SEM qualificação concreta. Sempre amarre com:';
        $lines[] = '  - "Filtro de [cargo/critério da lista]"';
        $lines[] = '  - "Erro no [campo/cadastro/anexo]"';
        $lines[] = '  - "Detalhe da cláusula X"';
        $lines[] = '  - "Critério do edital N° Y"';
        $lines[] = '';
        $lines[] = '**Exemplos a seguir (extraídos das entidades acima):**';
        $exemplos = self::generateExamplesFromEntities($entities);
        foreach ($exemplos as $ex) $lines[] = "  ✅ {$ex}";

        return implode("\n", $lines);
    }

    /**
     * Gera frases-modelo usando entidades reais como qualificação concreta.
     */
    private static function generateExamplesFromEntities(array $entities): array
    {
        $exs = [];
        $orgao = $entities['orgaos_instituicoes'][0] ?? null;
        $cidade = $entities['cidades_estados'][0] ?? null;
        $programa = $entities['programas_eventos'][0] ?? null;
        $cargo = $entities['cargos_profissoes'][0] ?? null;

        if ($orgao) $exs[] = "\"Critério do edital do {$orgao} barra candidatos sem CNH-D\"";
        if ($cidade) $exs[] = "\"Erro no formulário de inscrição em {$cidade} elimina candidatos\"";
        if ($programa) $exs[] = "\"Cláusula do {$programa} muda quem garante a vaga\"";
        if ($cargo) $exs[] = "\"Filtro pra {$cargo} exige documentação adicional\"";
        if (empty($exs)) {
            $exs[] = "\"Erro no preenchimento do CPF elimina candidatos antes da prova\"";
            $exs[] = "\"Cláusula 4.2 do edital de Araguaína muda quem garante a vaga\"";
        }
        return array_slice($exs, 0, 4);
    }
}
