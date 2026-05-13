# Páginas de Conteúdo — Leão da Barra

Este diretório contém o conteúdo HTML para as páginas institucionais do site.

## Como criar as páginas no WordPress

Para cada arquivo `.html` neste diretório, siga os passos:

1. No painel WordPress, vá em **Páginas → Adicionar Nova**
2. Insira o **título** da página (indicado no comentário no topo de cada arquivo)
3. Configure o **slug** (indicado no comentário)
4. Mude o editor para modo **HTML/Código** (clique nos 3 pontinhos → Editor de código)
5. **Cole o conteúdo** do arquivo `.html` (ignore os comentários HTML do topo)
6. Volte para o editor visual para revisar a formatação
7. **Publique** a página

## Páginas disponíveis

| Arquivo | Título | Slug |
|---------|--------|------|
| `sobre.html` | Sobre o Vitória | `sobre` |
| `hino.html` | Hino do Vitória | `hino` |
| `curiosidades.html` | Curiosidades do Vitória | `curiosidades` |
| `historia.html` | História do Vitória | `historia` |
| `barradao.html` | O Barradão | `barradao` |

## Dicas

- As páginas usam o template `page.php` do tema automaticamente
- Os elementos `<details>` já estão estilizados no CSS do tema (accordion com barra vermelha)
- Adicione imagens de destaque (featured image) em cada página para melhor visual
- Você pode editar o conteúdo livremente após importar
- Para adicionar estas páginas ao menu: **Aparência → Menus → Adicionar páginas**

## Menu sugerido

No menu principal, crie um item "Sobre" com subitens:
- Sobre o Vitória
- História
- O Barradão
- Hino
- Curiosidades
