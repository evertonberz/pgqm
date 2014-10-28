pgqm
====

PostgreSQL Query Mailer


Script de monitoramento de queries (PGQM), com envio de alerta por e-mail caso a duração das queries excedam determinada tolerância.

Monitora queries lentas ativas de acordo com tolerância configurada no arquivo INI.
Pode ser configurada a tolerância de duração das queries e a quantidade de queries lentas ativas.

Exemplo: quero visualizar quais eram as queries ativas no banco de dados, no momento em que ficaram acumuladas 5 queries com duração de mais de 8 segundos.

O script armazena em um banco sqlite um snapshot do pg_stat_activity dos momentos de crise.

Para rodar:
- renomeie o pgqm.ini-dist para pgqm.ini
- configure o pgqm.ini
- configurar o rodar.sh
- execute o rodar.sh

====
Configurações do pgqm.ini

Alguns "conceitos" utilizados:

Query lenta: que demoram mais tempo que a tolerância (especificada na diretiva "tolerancia" do arquivo .ini)

Envio de alerta por e-mail: condição 1 *e* condição 2 devem ser atendidas.

Crise: queries lentas (acima da tolerancia) estão permanecendo ativas no servidor


_numeroDeQueriesAcimaDaTolerancia_

se o número de queries lentas ultrapassar este limite, então o PGQM exibirá *na tela* um aviso e gravará no bd sqlite um snapshot da pg_stat_activity.

_numeroDeQueriesAcimaDaToleranciaParaAlerta_

se o número de queries lentas ultrapassar este limite, então a condição 1 para alerta por e-mail é ativada

_duracaoDaCriseParaAlerta_

se existem queries lentas ativas há mais de <duracaoDaCriseParaAlerta> segundos, então condição 2 do alerta por e-mail é ativada

_periodoDeRepeticaoNormal_

a busca por queries lentas no pg_stat_activity ocorrerá a cada <periodoDeRepeticaoNormal> segundos

_periodoDeRepeticaoEmCrise_

se está em crise, a busca por queries lentas no pg_stat_activity ocorrerá a cada <periodoDeRepeticaoEmCrise> segundos

_tempoDeEsperaPorAcao_

o envio de alerta por e-mail ficara desativado por <tempoDeEsperaPorAcao> após o primeiro alerta (é usado para não lotar a caixa de e-mails)

