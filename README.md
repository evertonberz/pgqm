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
