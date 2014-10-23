pgqm
====

PostgreSQL Query Monitor


Script de monitoramento de queries (PGQM), com envio de alerta por e-mail caso exceda a tolerância.

Monitora queries ativas lentas de acordo com tolerância configurada no arquivo ini.
Pode ser configurada a tolerância de duração das queries e a quantidade de queries lentas ativas.

Exemplo: quero visualizar quais eram as queries ativas no banco de dados, no momento em que ficaram acumuladas 5 queries com duração de mais de 8 segundos.

O script armazena em um banco sqlite um snapshot do pg_stat_activity dos momentos de crise.


