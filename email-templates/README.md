# Email Templates — Clonais Work

Templates HTML pra emails dos 6 sites da rede. **Foco: entrega legítima na caixa de entrada do Gmail.**

> Deliverability não vem de "burlar filtro" — vem de identidade clara, conteúdo útil e infraestrutura limpa. Templates aqui seguem essa premissa.

## Estrutura

```
email-templates/
├── README.md                        ← este arquivo
├── shared/
│   └── (futuro: header.html, footer.html reutilizáveis)
├── cursosenacgratuito/
│   └── cursos-gratuitos-semana.html
├── vagasebeneficios/                ← criar quando precisar
├── comocomprar/
├── guiadoscursos/
├── leaodabarra/
└── ondecompraragora/
```

**1 pasta por site = 1 identidade visual coerente por marca.** Nunca misture.

## Convenções de variáveis

Padrão Mailchimp-like (compatível com MailerLite, Brevo, Sendgrid, Mautic, Lemlist):

| Variável | Significado |
|----------|-------------|
| `%%first_name%%` | Primeiro nome do destinatário (cair pra "amigo(a)" se vazio) |
| `%%email%%` | Email completo (uso raro — footer) |
| `%%unsubscribe_url%%` | Link de opt-out one-click |
| `%%curso_N_titulo%%` | Título do curso N (1-5) |
| `%%curso_N_url%%` | URL do post real no site |
| `%%curso_N_descricao%%` | 1 linha (~80 chars) |
| `%%curso_N_inicio%%` | Data ou "vagas abertas" |

Trocar pelo placeholder do seu provedor se diferente (ex: `*|FNAME|*` no Mailchimp).

## Checklist de deliverability (operacional — não é código)

Sem essas 4 coisas, **nenhum HTML do mundo** entra na caixa de entrada do Gmail:

1. **SPF + DKIM + DMARC alinhados** com o domínio remetente.
   - Validar em https://mxtoolbox.com/SuperTool.aspx
   - DMARC mínimo: `v=DMARC1; p=quarantine; rua=mailto:dmarc@seudominio.com`

2. **Sender identity coerente.**
   - From: `Cursos Senac Gratuito <newsletter@cursosenacgratuito.com.br>` (NÃO `noreply@gmail.com`)
   - Reply-To: respondível por humano
   - Domínio do From == domínio dos links == domínio do unsubscribe

3. **Lista limpa.**
   - Só envie pra quem deu opt-in explícito (LGPD)
   - Remova hard bounces no 1º envio
   - Suprime quem não abre em 90+ dias

4. **Warm-up gradual.**
   - Domínio novo: começar com 50/dia, dobrar a cada 3 dias
   - Nunca disparar 10k de uma vez de IP novo

## O que os templates aqui já garantem

- ✅ Tabelas com `role="presentation"` (acessibilidade + Outlook)
- ✅ Mobile-first, max-width 600px
- ✅ Texto > imagem (Gmail penaliza emails só-imagem)
- ✅ CTA principal único + complementares secundários
- ✅ Footer com motivo de envio + opt-out visível em texto plano
- ✅ Alt text em imagens
- ✅ Sem gatilhos comuns de spam (ALL CAPS, "GRATUITO!!!", "URGENTE!")
- ✅ Razão de recebimento explícita ("você se cadastrou em...")
- ✅ Encoding UTF-8 sem entidades HTML quando possível (mais leve)

## O que NÃO fazer (e por quê)

| ❌ Anti-padrão | 🟥 Por quê |
|--------------|----------|
| Imitar identidade visual de orgão público (azul gov, "Serviço de Informação ao Cidadão") | Phishing visual = SpamAssassin score +5, Gmail filtra |
| Domínio remetente diferente do domínio do CTA | Gmail flagga como redirect suspeito |
| Botões que dizem "URGENTE", "ÚLTIMAS HORAS", "GRÁTIS!!!" sem lastro real | Triggers clássicos de spam filter |
| Imagem dominante (>60% da área) | Sem texto pra parsear, vai pro promo/spam |
| Unsubscribe em letra minúscula escondida no rodapé cinza | CAN-SPAM/LGPD ruim + Gmail penaliza |
| Tracking pixel sem disclosure | LGPD multa + Gmail sinaliza |
| URL encurtada (bit.ly, t.co) no CTA | Sinal de spam — use domínio próprio |
| `<font>` tag, `<center>` aninhado, CSS externo | Quebra em Outlook + sinaliza spam |

## Como testar antes de disparar

1. **mail-tester.com** — envie pra `test-XXXX@mail-tester.com`. Score 9/10+ é alvo. Abaixo de 8 = revisar.
2. **GlockApps** ou **Litmus Spam Testing** — testa em 30+ caixas (Gmail, Outlook, Yahoo, Apple Mail).
3. **Render em clientes reais** — Litmus / Email on Acid pra ver visual em Outlook 2016 (o pior caso).
4. **Validar HTML** — `tidy` ou https://validator.w3.org/

## Como adicionar um template novo

1. Crie pasta `email-templates/{site-slug}/` se não existe
2. Nome do arquivo: `{tema}-{periodicidade}.html` (ex: `ofertas-semana.html`, `concursos-mes.html`)
3. Copie um existente como base, troque identidade visual + variáveis + URL final
4. Rode `mail-tester.com` antes de subir pra produção
5. Documente no README do site se houver convenção específica
