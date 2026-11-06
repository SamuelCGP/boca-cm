# Instalação do BOCA-CM

O BOCA-CM é o BOCA com um editor de código (CodeMirror 5) na página **Runs** do time.
Ele não traz dependências novas nem muda o banco de dados. Instalar o BOCA-CM é instalar o
BOCA normal e depois copiar alguns arquivos por cima.

Há dois caminhos:

- **[A. Instalação limpa](#a-instalação-limpa-do-zero)**: máquina sem BOCA.
- **[B. Por cima de um BOCA já instalado](#b-por-cima-de-um-boca-já-instalado)**: mantém a
  configuração, o banco e as provas existentes.

Os dois terminam na mesma etapa: **[Aplicar os arquivos do BOCA-CM](#aplicar-os-arquivos-do-boca-cm)**.

> Versões testadas: BOCA **1.5.21** (pacote do PPA) e **1.5.24** (código-fonte), no Ubuntu 24.04.
> Os arquivos que o BOCA-CM substitui são idênticos nessas duas versões.

---

## Requisitos

- Ubuntu 24.04 (o mesmo que o BOCA padrão exige)
- Acesso `sudo`
- Internet na hora da instalação. Na prova não precisa: o editor é servido pelo próprio
  servidor do BOCA.
- Navegador dos competidores: Firefox 62+ ou Chromium/Chrome 60+

---

## A. Instalação limpa (do zero)

### A.1. Instale o BOCA padrão pelo PPA

```bash
sudo add-apt-repository ppa:icpc-latam/maratona-linux
sudo apt update
sudo apt install boca
sudo boca-createjail
```

> Se você usa outro PPA para o BOCA, troque a primeira linha por ele. O restante do guia
> não muda.

Durante a instalação, o `apt` pergunta o endereço do banco de dados (use `localhost` se tudo
fica na mesma máquina) e uma senha para o banco. Se deixar a senha vazia, uma senha aleatória
é gerada.

### A.2. Confira se o BOCA padrão está funcionando

Rode `sudo boca-autojudge` e abra `http://IP-DO-SERVIDOR/boca` no navegador. A tela de login deve aparecer. O usuário
`system` entra com a senha inicial `boca`. Troque essa senha antes de qualquer prova.

### A.3. Aplique o BOCA-CM

Siga a seção **[Aplicar os arquivos do BOCA-CM](#aplicar-os-arquivos-do-boca-cm)**.

---

## B. Por cima de um BOCA já instalado

Não é preciso parar o servidor nem mexer no banco. Provas, times e runs existentes continuam
intactos. Se houver uma prova em andamento, faça a atualização fora do horário dela.

Vá direto para **[Aplicar os arquivos do BOCA-CM](#aplicar-os-arquivos-do-boca-cm)**.

---

## Aplicar os arquivos do BOCA-CM

Todos os comandos são executados no servidor do BOCA.

### 1. Verifique a versão instalada

```bash
dpkg -l | grep boca
```

A versão também aparece no rodapé das páginas do BOCA. Esperado: **1.5.21** ou **1.5.24**.
Com outra versão, faça a verificação do passo 3 com atenção redobrada.

### 2. Baixe o BOCA-CM e faça backup

```bash
sudo apt install -y git
cd ~
git clone https://github.com/SamuelCGP/boca-cm.git
sudo cp -a /var/www/boca/src /var/www/boca/src.bkp
```

### 3. Verifique se os arquivos instalados são os originais

O BOCA-CM substitui três arquivos do BOCA. Os comandos abaixo comparam os arquivos instalados
com a versão original em que o BOCA-CM foi baseado:

```bash
cd ~/boca-cm
for f in team/run.php reload.js footnote.php; do
  echo "== $f"
  git show 5a6232c:src/$f | diff -q - /var/www/boca/src/$f && echo "   OK: igual ao original"
done
```

- **Todos "OK"**: siga para o passo 4.
- **Algum arquivo "differ"**: o BOCA instalado é de outra versão ou foi modificado
  localmente. Copiar o arquivo do BOCA-CM descartaria essas diferenças. Veja o que muda com
  `git show 5a6232c:src/team/run.php | diff - /var/www/boca/src/team/run.php` antes de
  continuar.

### 4. Confira a sintaxe dos arquivos PHP

```bash
for f in team/run.php footnote.php; do php -l ~/boca-cm/src/$f; done
```

As duas linhas devem dizer `No syntax errors detected`.

### 5. Copie os arquivos

```bash
cd ~/boca-cm/src
sudo cp -r codemirror /var/www/boca/src/
sudo cp team/run.php team/editor.js team/editor.css /var/www/boca/src/team/
sudo cp reload.js footnote.php /var/www/boca/src/
```

> **Não copie `versionnum.php` nem nada de `private/`.** O `private/` guarda a configuração
> do banco (`conf.php`) e os dados das provas. O `versionnum.php` guarda a versão do BOCA
> instalada, que o painel do admin usa. O próximo passo só acrescenta a versão do BOCA-CM
> nesse arquivo.

### 6. Registre a versão do BOCA-CM

```bash
grep -q BOCACMVERSION /var/www/boca/src/versionnum.php || \
  sudo sed -i "/^\$YEAR=/a \$BOCACMVERSION='1.0.0';" /var/www/boca/src/versionnum.php
cat /var/www/boca/src/versionnum.php
php -l /var/www/boca/src/versionnum.php
```

O arquivo deve ficar parecido com isto (com a sua versão do BOCA):

```php
<?php
$BOCAVERSION='boca-1.5.21';
$YEAR='2024';
$BOCACMVERSION='1.0.0';
?>
```

Essa linha é obrigatória: o rodapé e a página Runs usam `$BOCACMVERSION`.

### 7. Ajuste as permissões

```bash
sudo chown -R www-data:www-data /var/www/boca/src
sudo chmod -R go-rwx /var/www/boca/src/private
```

Não é preciso reiniciar o Apache.

### 8. Impeça que o `apt` desfaça a instalação

Os arquivos do BOCA vêm no pacote `boca-common`. Uma atualização desse pacote
(`apt upgrade`) sobrescreve `run.php`, `reload.js`, `footnote.php` e `versionnum.php`. O
editor some, e a falta da variável de versão gera avisos no rodapé. Para evitar isso,
bloqueie o pacote:

```bash
sudo apt-mark hold boca-common
```

Para atualizar o BOCA no futuro: `sudo apt-mark unhold boca-common`, `sudo apt upgrade`, e
então refaça este guia a partir do passo 1.

---

## Teste rápido

1. Abra `http://IP-DO-SERVIDOR/boca` e pressione **Ctrl+F5** para ignorar o cache. O rodapé
   deve mostrar **"BOCA-CM 1.0.0 (based on BOCA ...)"**.
2. Como admin, garanta que há uma prova ativa com pelo menos um problema, as linguagens e um
   usuário do tipo *team*.
3. Entre como o time e abra **Runs**. O editor aparece abaixo da tabela.
4. Escreva uma solução, escolha problema e linguagem, clique em **Send** e confirme o nome do
   arquivo. A run deve aparecer na tabela e ser corrigida pelo autojudge.
5. Clique em **[edit]** na run enviada. O código abre numa aba nova do editor.
6. Fique uns 2 minutos sem digitar. A página recarrega e as abas continuam lá.

Se algo falhar, o log do Apache costuma mostrar o motivo:

```bash
sudo tail -f /var/log/apache2/error.log
```

---

## Atualizar o BOCA-CM

```bash
cd ~/boca-cm && git pull
```

Depois, refaça os passos 4, 5 e 7. O passo 6 só é necessário se o `versionnum.php` tiver sido
sobrescrito. Quando uma nova versão do BOCA-CM mudar o número em `$BOCACMVERSION`, atualize a
linha em `/var/www/boca/src/versionnum.php`; o número novo faz os navegadores baixarem o
JavaScript atualizado em vez de usar o do cache.

---

## Voltar ao BOCA original

Com o backup do passo 2:

```bash
sudo rm -rf /var/www/boca/src
sudo mv /var/www/boca/src.bkp /var/www/boca/src
sudo apt-mark unhold boca-common
```

Sem backup, reinstale o pacote, que restaura os arquivos originais:

```bash
sudo apt-mark unhold boca-common
sudo apt install --reinstall boca-common
sudo rm -rf /var/www/boca/src/codemirror /var/www/boca/src/team/editor.js /var/www/boca/src/team/editor.css
```

Nos dois casos, o banco de dados e as provas não são afetados.

---

## Modo de submissão por linha de comando

Se o arquivo `/var/www/boca/src/private/run-using-command.config` contiver `1`, os times
submetem apenas pelo `boca-submit-run` e a página Runs não mostra formulário web. Nesse modo
o editor e os links **[edit]** ficam escondidos.
