# Visão Técnica para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv2+** · Compatível com GLPI **11.0.0 a 12.x**

Uma **mesa de trabalho do técnico**: tudo o que cada pessoa precisa atender, numa única tela, com filtros rápidos e ações sem sair da lista. Substitui o antigo plugin "meustickets".

## O que o plugin faz

### Visões
Chamados, problemas e mudanças, juntos ou separados, por visão:

| Visão | O que mostra |
|---|---|
| **Meus** | atribuídos a mim |
| **Do meu grupo** | atribuídos aos meus grupos |
| **Fila do grupo** | do meu grupo e sem técnico |
| **Que acompanho** | onde sou observador |
| **Que abri** | onde sou requerente |
| **Sem atribuição** | sem técnico nem grupo (perfis liberados) |
| **Todos** | tudo o que posso ver (perfis liberados) |

### Filtros e colunas
- **Filtros:** tipo, status (vazio significa "abertos"), prioridade, entidade, categoria, grupos, técnicos, período de abertura, prazo, parados e busca por texto, com seletores com busca.
- **Colunas escolhidas por cada pessoa**, com ordenação e paginação.
- Cada linha mostra o **último acompanhamento**, marcando quando ele veio do requerente.
- **Destaque do que mudou** desde a última vez que você viu.
- **Itens parados** há mais de X dias são destacados.
- **Visões salvas:** filtros e colunas guardados com um nome.

### Ações direto na lista
- **Acompanhamento rápido** com editor de texto rico.
- **Assumir** o item, atribuindo a si mesmo.
- **Exportação CSV** do que está na tela.
- **Atualização automática** no intervalo configurado.

Tudo respeita as entidades e os direitos de cada pessoa. Acompanhamentos privados só aparecem para quem pode vê-los.

## Configuração

Opções da página de configuração:
- perfis que usam a visão técnica e perfis que podem usar as visões **Sem atribuição** e **Todos**;
- tipos de item;
- dias para considerar um item parado;
- intervalo de atualização;
- colunas padrão, itens por página e limite de itens por tipo.

O menu fica em **Assistência → Visão técnica**.

---

## Download e instalação

1. Baixe o arquivo `visaotecnica-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/visaotecnica/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/visaotecnica
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install visaotecnica -u <usuário administrador>
   php bin/console plugin:activate visaotecnica
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/visaotecnica` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install visaotecnica -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v2.0 ou posterior**. Veja o arquivo [LICENSE](LICENSE).